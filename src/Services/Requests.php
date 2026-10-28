<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * People who cannot do something themselves ASK (v7 handoff §2.6; review K2).
 *
 * A swap, time off, an hours correction, a role, a travel refund, spend over a limit: each is a
 * request with ONE decider, and the answer travels back to the person who asked.
 *
 *   · The types are DATA, read from resources/consoles/request-types.json (seeded from the
 *     handoff). The handoff named a person as each type's decider; that file carries a ROUTE
 *     instead, resolved here to whoever holds the role or grant at the request's scope, because
 *     a typed name is wrong the day that person changes jobs.
 *   · The transitions are the JSON's, and nothing else: a decider moves Waiting to Approved,
 *     Needs info or Declined; the requester withdraws, or answers Needs info back to Waiting.
 *     Decided requests are read-only.
 *   · Asking for info, declining and adding info each NEED A NOTE, and the requester sees it.
 *     A refusal with no reason is a refusal nobody can fix.
 *   · The history is append-only (`gates_request_events`). Undo is an event of its own, offered
 *     for a few seconds after a decision, never an edit of the past.
 */
final class Requests
{
    public const STATUSES = ['waiting' => 'Waiting', 'needs_info' => 'Needs info', 'approved' => 'Approved',
                             'declined' => 'Declined', 'withdrawn' => 'Withdrawn'];
    public const OPEN = ['waiting', 'needs_info'];
    /** The seconds after a decision in which its decider may take it back. */
    public const UNDO_SECONDS = 10;

    /** action => [who may take it, from statuses, to status, note required] */
    private const ACTIONS = [
        'approve'  => ['decider', ['waiting'], 'approved', false],
        'ask_info' => ['decider', ['waiting'], 'needs_info', true],
        'decline'  => ['decider', ['waiting'], 'declined', true],
        'add_info' => ['requester', ['needs_info'], 'waiting', true],
        'withdraw' => ['requester', ['waiting', 'needs_info'], 'withdrawn', false],
    ];

    private static ?array $types = null;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_requests');
    }

    /** @return list<array<string,mixed>> the request types one audience can make */
    public static function types(string $audience = 'volunteer'): array
    {
        if (self::$types === null) {
            $path = dirname(__DIR__, 2) . '/resources/consoles/request-types.json';
            self::$types = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }
        return array_values(self::$types[$audience] ?? []);
    }

    public static function type(string $key, string $audience = 'volunteer'): ?array
    {
        foreach (self::types($audience) as $t) if ($t['key'] === $key) return $t;
        return null;
    }

    /**
     * Send a request. Title and why are required; everything else is what the type asks for.
     *
     * @param array{title?:string, amount?:string, by?:string, why?:string} $f
     * @return array{ok:bool, id?:int, error?:string, decider?:string}
     */
    public static function create(string $audience, string $type, string $fromKind, int $fromId, string $fromName, array $f,
                                  ?string $scopeKind = null, ?int $scopeId = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $t = self::type($type, $audience);
        if ($t === null) return ['ok' => false, 'error' => 'Pick what kind of request this is.'];
        $title = trim((string) ($f['title'] ?? ''));
        $why = trim((string) ($f['why'] ?? ''));
        if ($title === '' || $why === '') return ['ok' => false, 'error' => 'Add what you need and why.'];
        $amount = trim((string) ($f['amount'] ?? ''));
        $minor = null;
        $fieldLabel = '';
        foreach ($t['fields'] as $fd) if ($fd['key'] === 'amount') $fieldLabel = (string) $fd['label'];
        if ($amount !== '' && str_contains($fieldLabel, '₦')) {
            $n = (float) preg_replace('/[^0-9.]/', '', $amount);
            $minor = (int) round($n * 100);
        }
        $d = self::resolveDecider((string) $t['route'], $scopeKind, $scopeId, $fromKind, $fromId);
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_requests')->insertGetId([
            'type' => $type, 'title' => mb_substr($title, 0, 255), 'from_kind' => $fromKind, 'from_id' => $fromId,
            'scope_kind' => $scopeKind, 'scope_id' => $scopeId, 'amount_minor' => $minor,
            'amount_text' => $minor === null && $amount !== '' ? mb_substr($amount, 0, 64) : null,
            'needed_by' => mb_substr(trim((string) ($f['by'] ?? '')), 0, 64) ?: null, 'why' => mb_substr($why, 0, 4000),
            'decider_kind' => $d['kind'], 'decider_id' => $d['id'], 'decider_role' => $d['role'],
            'status' => 'waiting', 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::log($id, $fromKind, $fromId, $fromName, 'asked', null);
        return ['ok' => true, 'id' => $id, 'decider' => $d['label']];
    }

    /**
     * Take an action on a request: approve, ask_info, decline (the decider); add_info, withdraw
     * (the requester).
     *
     * @return array{ok:bool, error?:string, status?:string}
     */
    public static function act(int $id, string $action, string $actorKind, int $actorId, string $actorName, string $note = '',
                               ?string $adminRole = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $a = self::ACTIONS[$action] ?? null;
        $r = DB::table('gates_requests')->where('id', $id)->first();
        if ($a === null || !$r) return ['ok' => false, 'error' => 'That request is not there.'];
        [$who, $from, $to, $needsNote] = $a;
        $allowed = $who === 'decider' ? self::canDecide($r, $actorKind, $actorId, $adminRole)
                                      : ($r->from_kind === $actorKind && (int) $r->from_id === $actorId);
        if (!$allowed) return ['ok' => false, 'error' => 'This request is not yours to ' . ($who === 'decider' ? 'decide.' : 'change.')];
        if (!in_array($r->status, $from, true)) return ['ok' => false, 'error' => 'This request has already been decided.'];
        $note = trim($note);
        if ($needsNote && $note === '') {
            return ['ok' => false, 'error' => match ($action) {
                'decline' => 'Say why, so they can fix it and ask again.',
                'ask_info' => 'Say what you need from them.',
                default => 'Write the information first.',
            }];
        }
        // Guarded on the status it was read in: two deciders pressing at once cannot both win.
        $now = date('Y-m-d H:i:s');
        $n = DB::table('gates_requests')->where('id', $id)->where('status', $r->status)->update([
            'status' => $to, 'updated_at' => $now, 'decided_at' => in_array($to, ['approved', 'declined'], true) ? $now : null,
        ]);
        if ($n === 0) return ['ok' => false, 'error' => 'Somebody else answered this a moment ago.'];
        self::log($id, $actorKind, $actorId, $actorName, $action, $note !== '' ? mb_substr($note, 0, 2000) : null);
        return ['ok' => true, 'status' => $to];
    }

    /** Take back the last decision, within UNDO_SECONDS, by the person who made it. */
    public static function undo(int $id, string $actorKind, int $actorId, string $actorName): bool
    {
        if (!self::ready()) return false;
        $last = DB::table('gates_request_events')->where('request_id', $id)->orderByDesc('id')->first();
        if (!$last || $last->actor_kind !== $actorKind || (int) $last->actor_id !== $actorId) return false;
        $was = match ($last->action) { 'approve', 'ask_info', 'decline', 'withdraw' => 'waiting', 'add_info' => 'needs_info', default => null };
        if ($last->action === 'withdraw') {
            $prev = DB::table('gates_request_events')->where('request_id', $id)->where('id', '<', $last->id)->orderByDesc('id')->value('action');
            $was = $prev === 'ask_info' ? 'needs_info' : 'waiting';
        }
        if ($was === null || time() - (int) strtotime((string) $last->created_at) > self::UNDO_SECONDS) return false;
        DB::table('gates_requests')->where('id', $id)->update(['status' => $was, 'decided_at' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        self::log($id, $actorKind, $actorId, $actorName, 'undo', null);
        return true;
    }

    /** May this person decide this request? */
    public static function canDecide(object $r, string $actorKind, int $actorId, ?string $adminRole = null): bool
    {
        if ($r->decider_kind === 'member' || $r->decider_kind === 'admin') {
            return $r->decider_kind === $actorKind && (int) $r->decider_id === $actorId;
        }
        if ($r->decider_kind === 'role' && $actorKind === 'admin' && $adminRole !== null) {
            // Finance is whoever the guard lets into Money; "an administrator" is an admin.
            $roles = $r->decider_role === 'finance' ? (Permissions::MATRIX['finance'] ?? ['superadmin']) : ['superadmin', 'admin'];
            return in_array($adminRole, $roles, true);
        }
        return false;
    }

    /**
     * Who decides, from the type's route and the request's scope.
     *
     * @return array{kind:string, id:?int, role:?string, label:string}
     */
    public static function resolveDecider(string $route, ?string $scopeKind, ?int $scopeId, string $fromKind, int $fromId): array
    {
        if ($route === 'lead') {
            // The volunteer's team lead at this scope, else the event's lead, else the team.
            foreach (['vol.team_lead', 'event.lead'] as $cap) {
                if ($scopeKind === null) break;
                foreach (Grants::holders($cap, $scopeKind, $scopeId) as $g) {
                    if ($g->actor_kind === $fromKind && (int) $g->actor_id === $fromId) continue;
                    $name = self::memberName((string) $g->actor_kind, (int) $g->actor_id);
                    return ['kind' => (string) $g->actor_kind, 'id' => (int) $g->actor_id, 'role' => null,
                            'label' => 'your lead' . ($name !== '' ? ', ' . $name : '')];
                }
            }
            return ['kind' => 'role', 'id' => null, 'role' => 'admin', 'label' => 'the volunteer team'];
        }
        if ($route === 'finance') return ['kind' => 'role', 'id' => null, 'role' => 'finance', 'label' => 'finance'];
        return ['kind' => 'role', 'id' => null, 'role' => 'admin', 'label' => $route === 'staff' ? 'the volunteer team' : 'an administrator'];
    }

    /** The words the requester sees for who decides. */
    public static function deciderLabel(object $r): string
    {
        if ($r->decider_kind === 'member' || $r->decider_kind === 'admin') {
            return self::memberName((string) $r->decider_kind, (int) $r->decider_id) ?: 'your lead';
        }
        return $r->decider_role === 'finance' ? 'finance' : 'the volunteer team';
    }

    /**
     * A person's requests, newest first.
     *
     * @param 'open'|'decided' $which
     * @return list<object>
     */
    public static function from(string $kind, int $id, string $which = 'open'): array
    {
        if (!self::ready()) return [];
        $q = DB::table('gates_requests')->where('from_kind', $kind)->where('from_id', $id);
        $which === 'open' ? $q->whereIn('status', self::OPEN) : $q->whereNotIn('status', self::OPEN);
        return $q->orderByDesc('id')->limit(100)->get()->all();
    }

    /** What is waiting on one decider. @return list<object> */
    public static function waitingOn(string $kind, int $id, ?string $adminRole = null): array
    {
        if (!self::ready()) return [];
        $rows = DB::table('gates_requests')->where('status', 'waiting')
            ->where(static function ($w) use ($kind, $id, $adminRole) {
                $w->where(static fn ($o) => $o->where('decider_kind', $kind)->where('decider_id', $id));
                if ($kind === 'admin' && $adminRole !== null) $w->orWhere('decider_kind', 'role');
            })->orderBy('id')->limit(200)->get()->all();
        return array_values(array_filter($rows, static fn ($r) => self::canDecide($r, $kind, $id, $adminRole)));
    }

    /** @return list<object> the request's history, oldest first */
    public static function history(int $id): array
    {
        if (!self::ready()) return [];
        return DB::table('gates_request_events')->where('request_id', $id)->orderBy('id')->get()->all();
    }

    public static function find(int $id): ?object
    {
        return self::ready() ? (DB::table('gates_requests')->where('id', $id)->first() ?: null) : null;
    }

    private static function log(int $id, string $kind, ?int $actorId, string $name, string $action, ?string $note): void
    {
        DB::table('gates_request_events')->insert([
            'request_id' => $id, 'actor_kind' => $kind, 'actor_id' => $actorId, 'actor_name' => mb_substr($name, 0, 190) ?: null,
            'action' => $action, 'note' => $note, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function memberName(string $kind, int $id): string
    {
        $t = $kind === 'admin' ? 'gates_admins' : 'gates_users';
        return (string) (DB::table($t)->where('id', $id)->value('name') ?? '');
    }
}
