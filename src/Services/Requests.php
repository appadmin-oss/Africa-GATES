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

    /** Hours between two reminders about one request (and between asking and the first). */
    public const REMIND_HOURS = 24;

    /**
     * The requester nudges whoever decides (v7 §5: "Remind Sade"). It is an event of its own in
     * the history, and an email to the decider — by name, or to everyone who holds the role.
     *
     *   · ONLY THE REQUESTER, ONLY WHILE IT WAITS, AND ONCE A DAY: counted from the last time
     *     they asked or reminded, so a reminder is never sent a minute after the request.
     *   · THE ANSWER SAYS WHETHER ANY MAIL LEFT. A reminder logged while email is down is still
     *     on the record and in the decider's Waiting on you; the requester is told it was not
     *     emailed rather than assuming it was.
     *
     * @param ?callable(string $to, string $subject, string $html, string $plain): bool $send for tests
     * @return array{ok:bool, error?:string, emailed?:int, next?:string}
     */
    public static function remind(int $id, string $actorKind, int $actorId, string $actorName, ?callable $send = null): array
    {
        $r = self::find($id);
        if (!$r) return ['ok' => false, 'error' => 'That request is not there.'];
        if ($r->from_kind !== $actorKind || (int) $r->from_id !== $actorId) return ['ok' => false, 'error' => 'Only the person who asked can send a reminder.'];
        if ($r->status !== 'waiting') return ['ok' => false, 'error' => $r->status === 'needs_info' ? 'They asked you for information first. Add it, and the request goes back to them.' : 'This request has already been decided.'];
        $last = DB::table('gates_request_events')->where('request_id', $id)->whereIn('action', ['asked', 'add_info', 'remind'])->max('created_at');
        $next = $last ? (int) strtotime((string) $last) + self::REMIND_HOURS * 3600 : 0;
        if ($next > time()) return ['ok' => false, 'error' => 'You can remind them from ' . date('D j M, H:i', $next) . ', a day after you last asked.', 'next' => date('Y-m-d H:i:s', $next)];
        self::log($id, $actorKind, $actorId, $actorName, 'remind', null);
        DB::table('gates_requests')->where('id', $id)->update(['updated_at' => date('Y-m-d H:i:s')]);

        $send ??= static function (string $to, string $subject, string $html, string $plain): bool {
            try {
                $res = \AfricaGates\Services\OtpService::boot()->sendBranded($to, $subject, $html, $plain, 'request');
                // Written to the log instead of sent (a host with no mail road) is not "emailed".
                return ($res['success'] ?? false) === true && ($res['fallback'] ?? '') !== 'log';
            } catch (\Throwable) {
                return false;
            }
        };
        $link = \AfricaGates\Support\SiteUrl::base() . '/admin/requests';
        $subject = 'Reminder: ' . $r->title;
        $plain = $actorName . ' is waiting for your decision on "' . $r->title . '"'
            . ($r->amount_minor !== null ? ' (₦' . number_format((int) $r->amount_minor / 100) . ')' : '') . ".\n\nWhy: " . $r->why . "\n\nDecide it here: " . $link;
        $html = '<p>' . htmlspecialchars($actorName) . ' is waiting for your decision on <strong>' . htmlspecialchars((string) $r->title) . '</strong>'
            . ($r->amount_minor !== null ? ' (₦' . number_format((int) $r->amount_minor / 100) . ')' : '') . '.</p><p>' . nl2br(htmlspecialchars((string) $r->why))
            . '</p><p><a href="' . htmlspecialchars($link) . '">Decide it in the console</a></p>';
        $emailed = 0;
        foreach (self::deciderEmails($r) as $to) if ($send($to, $subject, $html, $plain)) $emailed++;
        return ['ok' => true, 'emailed' => $emailed];
    }

    /**
     * Record that an approved request was carried out (a big send went out), in its history.
     * Only on an approved request, and only by the person who asked: it changes no status.
     */
    public static function recordSent(int $id, string $actorKind, int $actorId, string $actorName, string $note): bool
    {
        $r = self::find($id);
        if (!$r || $r->status !== 'approved' || $r->from_kind !== $actorKind || (int) $r->from_id !== $actorId) return false;
        self::log($id, $actorKind, $actorId, $actorName, 'sent', mb_substr($note, 0, 2000));
        return true;
    }

    /** Where a decider is reached: the named person, or every active admin holding the role. @return list<string> */
    private static function deciderEmails(object $r): array
    {
        if ($r->decider_kind === 'member' || $r->decider_kind === 'admin') {
            $e = DB::table($r->decider_kind === 'admin' ? 'gates_admins' : 'gates_users')->where('id', $r->decider_id)->value('email');
            return $e ? [(string) $e] : [];
        }
        $roles = $r->decider_role === 'finance' ? (Permissions::MATRIX['finance'] ?? ['superadmin']) : ['superadmin', 'admin'];
        return array_values(array_unique(array_map('strval', DB::table('gates_admins')->where('is_active', 1)->whereIn('role', $roles)->pluck('email')->all())));
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
        if ($r->decider_role === 'finance') return 'finance';
        // A lead's request (access, a big send, a venue change) goes to the platform's admins;
        // only a volunteer's goes to "the volunteer team". Both are member accounts, so the
        // TYPE says which console asked.
        return self::type((string) $r->type, 'lead') !== null ? 'an administrator' : 'the volunteer team';
    }

    /**
     * A person's requests, newest first.
     *
     * `$audience` keeps one console's requests apart from the other's: an event lead and a
     * volunteer are both member accounts, often the same person, and a lead's spend request
     * does not belong in their volunteer workspace any more than a shift swap belongs in the
     * lead console.
     *
     * @param 'open'|'decided' $which
     * @param ?'lead'|'volunteer' $audience
     * @return list<object>
     */
    public static function from(string $kind, int $id, string $which = 'open', ?string $audience = null): array
    {
        if (!self::ready()) return [];
        $q = DB::table('gates_requests')->where('from_kind', $kind)->where('from_id', $id);
        $which === 'open' ? $q->whereIn('status', self::OPEN) : $q->whereNotIn('status', self::OPEN);
        if ($audience !== null) $q->whereIn('type', array_column(self::types($audience), 'key') ?: ['']);
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
