<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A CHANGE WAITING FOR ITS TIER'S APPROVER (design plan, Approval tiers; Tiers holds the table).
 *
 *   · SMALL — rewording your own work. Waits {@see Tiers::LAPSE_HOURS} for a lead to object,
 *     then applies itself (rule "small_lapse"). The only tier that may.
 *   · MEDIUM — moving your own work's due date. A lead decides; a lead's own applies at once.
 *   · LARGE — a goal's target or deadline, a gate's date. An OWNER decides, never the person
 *     who asked; with no owner, the platform's staff from the event's admin page.
 *
 * Every change says what it is in one line, why, and what it rests on (plan: "Every proposal
 * shows its impact in one line and its evidence"), and its decision is kept beside it.
 */
final class Changes
{
    /** kind => [subject table, what it changes, words] */
    public const KINDS = [
        'reword'        => ['work', 'title', 'Reword'],
        'due_shift'     => ['work', 'due_at', 'Move the due date of'],
        'target'        => ['goal', 'target', 'Change the target of'],
        'goal_deadline' => ['goal', 'deadline', 'Move the deadline of'],
        'gate_date'     => ['gate', 'due_at', 'Move the date of'],
    ];
    private const TABLE = ['work' => 'gates_event_work', 'goal' => 'gates_event_goals', 'gate' => 'gates_event_gates'];

    public static function ready(): bool
    {
        return Goals::ready() && \AfricaGates\Support\SchemaHas::table('gates_event_changes');
    }

    /**
     * Ask for a change. Applies at once when the asker is the tier's own approver (a lead's
     * medium change); otherwise waits.
     *
     * @return array{ok:bool, error?:string, id?:int, event?:int, msg?:string, applied?:bool}
     */
    public static function propose(string $kind, int $subjectId, string $value, string $reason, int $memberId, string $evidence = ''): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!isset(self::KINDS[$kind])) return ['ok' => false, 'error' => 'That is not a change this page makes.'];
        [$sk, $col, $verb] = self::KINDS[$kind];
        $row = DB::table(self::TABLE[$sk])->where('id', $subjectId)->first();
        if (!$row) return ['ok' => false, 'error' => 'That is not there.'];
        $eventId = (int) $row->event_id;
        $lead = Plan::may($memberId, $eventId, 'plan.edit');
        if ($sk === 'work' && !$lead && !($row->owner_kind === Plan::ACTOR && (int) $row->owner_id === $memberId)) {
            return ['ok' => false, 'error' => 'Only who owns this work, or a lead, can ask to change it.'];
        }
        if ($sk !== 'work' && !$lead) return ['ok' => false, 'error' => 'A lead asks for changes to goals and gates.'];
        $new = self::normalise($col, $value);
        if ($new === null) return ['ok' => false, 'error' => $col === 'title' ? 'Say what it should be called.' : ($col === 'target' ? 'The new target needs to be a number.' : 'That is not a date.')];
        if ((string) $new === (string) $row->{$col}) return ['ok' => false, 'error' => 'That is what it already is.'];
        $reason = trim($reason);
        $tier = Tiers::of($kind);
        if ($tier === 'large' && $reason === '') return ['ok' => false, 'error' => 'Say why: an owner decides this, and reads the reason first.'];
        // Plan rule 8: inside the last three days before a gate, any change to the plan goes to the top.
        if ($tier !== 'large' && self::nearGate($eventId)) $tier = 'large';
        $title = (string) ($row->title ?? 'it');
        $summary = mb_substr($verb . ' “' . $title . '” to ' . self::words($col, $new) . ' (now ' . self::words($col, $row->{$col}) . ')', 0, 255);
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_event_changes')->insertGetId(['event_id' => $eventId, 'kind' => $kind, 'tier' => $tier, 'subject_kind' => $sk,
            'subject_id' => $subjectId, 'payload' => json_encode(['value' => $new]), 'summary' => $summary, 'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'evidence' => $evidence !== '' ? mb_substr($evidence, 0, 255) : null, 'status' => 'pending', 'proposed_by' => $memberId,
            'lapse_at' => $tier === 'small' ? date('Y-m-d H:i:s', time() + Tiers::LAPSE_HOURS * 3600) : null, 'created_at' => $now]);
        if ($tier === 'medium' && $lead) {
            self::apply($id, $memberId, 'approved', 'A lead’s own medium change');
            return ['ok' => true, 'id' => $id, 'event' => $eventId, 'applied' => true, 'msg' => 'Changed.'];
        }
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => match ($tier) {
            'small' => 'Asked. It applies itself in ' . Tiers::LAPSE_HOURS . ' hours unless a lead objects.',
            'medium' => 'Asked. A lead decides.',
            default => 'Asked. An owner decides' . (Teams::owners($eventId) ? '.' : ' — this event has no owner yet, so the platform’s staff do.'),
        }];
    }

    /**
     * A lead asks for somebody to become the event's FINANCE ADMIN (owner, 13 Nov 2026:
     * "requires event owner approval"). LARGE, so an owner decides — never the lead who asked,
     * and never the event's current finance admin, who would be approving their own colleague's
     * hold on the money. With no owner, staff decide it on the event's leads page.
     * Nothing is granted until it is approved; then {@see apply()} gives it as an invitation.
     */
    public static function proposeFinance(int $eventId, string $email, string $until, string $reason, int $memberId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($memberId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can ask for that.'];
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Type their email address.'];
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Say why them: an owner decides this, and reads the reason first.'];
        $waiting = DB::table('gates_event_changes')->where('event_id', $eventId)->where('kind', 'finance')->where('status', 'pending')->get(['payload']);
        foreach ($waiting as $w) {
            if ((json_decode((string) $w->payload, true)['email'] ?? '') === $email) return ['ok' => false, 'error' => 'That is already waiting for an owner.'];
        }
        $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($until)) ? trim($until) : '';
        $id = (int) DB::table('gates_event_changes')->insertGetId(['event_id' => $eventId, 'kind' => 'finance', 'tier' => 'large', 'subject_kind' => 'event',
            'subject_id' => $eventId, 'payload' => json_encode(['email' => $email, 'until' => $until]),
            'summary' => mb_substr('Make ' . $email . ' the event’s finance admin' . ($until !== '' ? ' until ' . Goals::day($until) : ''), 0, 255),
            'reason' => mb_substr($reason, 0, 255), 'status' => 'pending', 'proposed_by' => $memberId, 'created_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Asked. ' . (Teams::owners($eventId)
            ? 'An owner of this event approves it; nothing changes until they do.'
            : 'This event has no owner yet, so the platform’s staff approve it.')];
    }

    private static function normalise(string $col, string $v): int|string|null
    {
        $v = trim($v);
        if ($col === 'title') return $v !== '' ? mb_substr($v, 0, 255) : null;
        if ($col === 'target') { $n = Goals::number($v); return $n !== null && $n > 0 ? $n : null; }
        $t = strtotime($v);
        return $t === false ? null : date('Y-m-d 23:59:59', $t);
    }

    private static function words(string $col, mixed $v): string
    {
        if ($v === null || $v === '') return 'nothing';
        return match ($col) { 'title' => '“' . $v . '”', 'target' => number_format((int) $v), default => Goals::day((string) $v) };
    }

    private static function nearGate(int $eventId): bool
    {
        return DB::table('gates_event_gates')->where('event_id', $eventId)->where('status', 'open')
            ->whereBetween('due_at', [date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3 * 86400)])->exists();
    }

    /**
     * Decide a waiting change. Small and medium: a lead (objecting to a small one is
     * rejecting it). Large: an owner who did not ask for it, or staff ($memberId 0 with $admin).
     */
    public static function decide(int $changeId, int $memberId, string $verdict, string $note, bool $admin = false): array
    {
        $c = self::ready() ? DB::table('gates_event_changes')->where('id', $changeId)->first() : null;
        if (!$c || $c->status !== 'pending') return ['ok' => false, 'error' => 'That change has already been decided.'];
        $eventId = (int) $c->event_id;
        if (!$admin) {
            if ($c->kind === 'finance') {
                if (!Teams::isOwner($memberId, $eventId)) return ['ok' => false, 'error' => 'An owner of this event decides who approves its money.'];
                if ((int) $c->proposed_by === $memberId) return ['ok' => false, 'error' => 'You asked for it, so another owner or the platform’s staff decides it.'];
            } elseif ($c->tier === 'large') {
                // A change to money — a revenue goal's target, a money gate's date — may also be the finance admin's.
                $money = ($c->subject_kind === 'goal' && DB::table('gates_event_goals')->where('id', (int) $c->subject_id)->value('metric') === 'revenue')
                      || ($c->subject_kind === 'gate' && DB::table('gates_event_gates')->where('id', (int) $c->subject_id)->value('approver') === 'owner');
                $finance = $money && \AfricaGates\Services\Grants::allows(Plan::ACTOR, $memberId, 'event.finance', 'event', $eventId);
                if (!Teams::isOwner($memberId, $eventId) && !$finance) return ['ok' => false, 'error' => 'An owner of this event decides this one' . ($money ? ', or its finance admin.' : '.')];
                if ((int) $c->proposed_by === $memberId) return ['ok' => false, 'error' => 'You asked for it, so another owner or the platform’s staff decides it.'];
            } elseif (!Plan::may($memberId, $eventId, 'plan.edit')) {
                return ['ok' => false, 'error' => 'A lead of this event decides this one.'];
            }
        }
        $note = trim($note);
        if ($verdict === 'reject') {
            if ($note === '') return ['ok' => false, 'error' => 'Say why, so they know what would change your mind.'];
            DB::table('gates_event_changes')->where('id', $changeId)->where('status', 'pending')->update(['status' => 'rejected', 'decided_by' => $memberId ?: null,
                'decided_at' => date('Y-m-d H:i:s'), 'decided_note' => mb_substr($note, 0, 255)]);
            return ['ok' => true, 'event' => $eventId, 'msg' => 'Not changed. They see your reason.'];
        }
        if ($verdict !== 'approve') return ['ok' => false, 'error' => 'Approve or reject it.'];
        if ($c->kind === 'finance') {
            // Grant first, then mark it approved: a grant the role system refused (an inactive
            // account, somebody who now leads this event) must leave the request waiting with
            // the reason, not recorded as done.
            $p = json_decode((string) $c->payload, true) ?: [];
            $u = DB::table('gates_users')->whereRaw('LOWER(email) = ?', [(string) ($p['email'] ?? '')])->first(['id']);
            if ($u && (Plan::isLead((int) $u->id, $eventId) || Teams::isOwner((int) $u->id, $eventId))) {
                return ['ok' => false, 'error' => 'They lead or own this event now, so they cannot approve its money. Reject it with that reason.'];
            }
            $r = \AfricaGates\Services\RoleInvites::offer((string) ($p['email'] ?? ''), 'event.finance', $eventId, (string) ($p['until'] ?? ''),
                ['kind' => $admin ? 'admin' : Plan::ACTOR, 'id' => $memberId, 'name' => Plan::name(Plan::ACTOR, (int) $c->proposed_by) ?: 'the event lead']);
            if (!$r['ok']) return $r;
            DB::table('gates_event_changes')->where('id', $changeId)->where('status', 'pending')->update(['status' => 'approved', 'decided_by' => $memberId ?: null,
                'decided_at' => date('Y-m-d H:i:s'), 'decided_note' => $note !== '' ? mb_substr($note, 0, 255) : null]);
            return ['ok' => true, 'event' => $eventId, 'msg' => !empty($r['granted']) ? 'Approved. They are the event’s finance admin now.'
                : 'Approved. Their invitation is sent and switches on once ' . \AfricaGates\Services\RoleInvites::reason((string) ($p['email'] ?? ''), 'event.finance') . '.'];
        }
        self::apply($changeId, $memberId, 'approved', $note);
        return ['ok' => true, 'event' => $eventId, 'msg' => 'Approved and applied.'];
    }

    /** Apply a change, claimed first so two deciders cannot both apply it. */
    public static function apply(int $changeId, int $by, string $status, string $note = ''): bool
    {
        $c = DB::table('gates_event_changes')->where('id', $changeId)->first();
        if (!$c) return false;
        $n = DB::table('gates_event_changes')->where('id', $changeId)->where('status', 'pending')->update(['status' => $status, 'decided_by' => $by ?: null,
            'decided_at' => date('Y-m-d H:i:s'), 'decided_note' => $note !== '' ? mb_substr($note, 0, 255) : null]);
        if ($n === 0) return false;
        if (!isset(self::KINDS[(string) $c->kind])) return true;   // 'finance' is granted in decide(), never by lapse
        [$sk, $col] = self::KINDS[(string) $c->kind];
        $value = json_decode((string) $c->payload, true)['value'] ?? null;
        $set = [$col => $value];
        if ($sk !== 'gate') $set['updated_at'] = date('Y-m-d H:i:s');
        DB::table(self::TABLE[$sk])->where('id', (int) $c->subject_id)->update($set);
        if ($sk === 'goal') Planner::draftGates((int) $c->subject_id);   // a new target or deadline re-drafts the open gates
        return true;
    }

    /** Small changes nobody objected to apply themselves (rule "small_lapse"). @return int how many */
    public static function lapse(int $eventId, bool $act): array
    {
        if (!self::ready()) return [];
        $due = DB::table('gates_event_changes')->where('event_id', $eventId)->where('status', 'pending')->where('tier', 'small')
            ->whereNotNull('lapse_at')->where('lapse_at', '<=', date('Y-m-d H:i:s'))->get()->all();
        if ($act) foreach ($due as $c) self::apply((int) $c->id, 0, 'lapsed', 'No objection in ' . Tiers::LAPSE_HOURS . ' hours');
        return $due;
    }

    /** @return list<object> what waits at an event, oldest first */
    public static function pending(int $eventId): array
    {
        return self::ready() ? DB::table('gates_event_changes')->where('event_id', $eventId)->where('status', 'pending')->orderBy('id')->get()->all() : [];
    }

    /** @return list<object> the last decided changes, for the record */
    public static function decided(int $eventId, int $limit = 20): array
    {
        return self::ready() ? DB::table('gates_event_changes')->where('event_id', $eventId)->where('status', '!=', 'pending')->orderByDesc('id')->limit($limit)->get()->all() : [];
    }
}
