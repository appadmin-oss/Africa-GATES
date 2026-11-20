<?php
declare(strict_types=1);

namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\EventArrivals;
use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * An event's written report, and an owner's sign-off on it.
 *
 * Before this, Reports held only what the platform could COMPUTE — a status paragraph written
 * from the plan, the post-event figures, the audit trail. Nothing a lead knew and the plan did
 * not: why the doors opened late, which sponsor nearly walked, what to book earlier next year.
 * And nothing anybody signed. So the account of a night lived in somebody's inbox, if anywhere.
 *
 *   draft → submitted → approved                (final)
 *               ↘ returned → submitted → …      (edited on the same row)
 *
 * ── WHO ──────────────────────────────────────────────────────────────────────────────────
 * A lead of the event writes and submits it. An OWNER of the event (the `event.own` grant,
 * Teams::owners) approves it or sends it back with a reason — and never their own: an owner
 * who also leads the event and wrote the report waits for another owner, exactly as a proposal
 * does on Team & roles. Where there is no other owner the report waits, and the page says so
 * and who can name one; it never approves itself.
 *
 * ── WHAT IS SIGNED ───────────────────────────────────────────────────────────────────────
 * The computed figures are frozen into the row at submission (`figures_json`). Arrivals keep
 * syncing and a late payment lands after the night; the owner signs the report as written,
 * against the figures it was written about, and that is what the approved report keeps showing.
 */
final class EventReport
{
    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for the owner', 'returned' => 'Sent back', 'approved' => 'Signed off'];
    public const TONE = ['draft' => 'mute', 'submitted' => 'warn', 'returned' => 'bad', 'approved' => 'ok'];

    /** The written parts, in the order they are read: field => [label, the question it answers]. */
    public const PARTS = [
        'summary'    => ['Summary', 'What happened, in a paragraph the committee can read on its own.'],
        'highlights' => ['What went well', 'Worth keeping or repeating.'],
        'problems'   => ['What went wrong', 'And what it cost: time, money, people or reputation.'],
        'lessons'    => ['Lessons for next time', 'What to do differently, and when it has to happen.'],
    ];

    /** Per part. TEXT on SQLite, MEDIUMTEXT on MySQL; a report is read, not a book. */
    public const MAX_CHARS = 8000;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_event_writeups');
    }

    public static function of(int $eventId): ?object
    {
        if (!self::ready() || $eventId < 1) return null;
        return DB::table('gates_event_writeups')->where('event_id', $eventId)->first() ?: null;
    }

    /** Has the night happened? A report of an event that has not taken place is a plan. */
    public static function after(object $e): bool
    {
        return strtotime((string) $e->event_date) <= time();
    }

    /**
     * The post-event figures, computed now: who came, what was spent, ticket income, work done,
     * cues run. One definition, read by the Post-event page and frozen into a submitted report.
     *
     * @return list<array{l:string, v:string, s:string}>
     */
    public static function figures(object $e): array
    {
        $id = (int) $e->id;
        $s = EventArrivals::summary($id);
        $r = Plan::readiness($id);
        $paid = array_sum(array_map(static fn ($l) => (int) $l->paid_minor, Plan::lines([$id])));
        $cues = Scope::show($id);
        $out = [['l' => 'Came', 'v' => number_format($s['in']), 's' => $s['expected'] ? 'of ' . number_format($s['expected']) . ' expected' : '']];
        $out[] = ['l' => 'Spent', 'v' => Plan::naira($paid), 's' => isset($e->budget_minor) && $e->budget_minor ? 'of ' . Plan::naira((int) $e->budget_minor) . ' budget' : ''];
        $out[] = ['l' => 'Ticket income', 'v' => Plan::naira(Scope::ticketIncomeMinor($id)), 's' => ''];
        if ($r) $out[] = ['l' => 'Work done', 'v' => $r['done'] . ' of ' . $r['all'], 's' => ''];
        if ($cues) $out[] = ['l' => 'Cues run', 'v' => count(array_filter($cues, static fn ($c) => $c->done_at)) . ' of ' . count($cues), 's' => ''];
        return $out;
    }

    /** The figures a report was signed against: frozen at submission, live before it. */
    public static function figuresFor(object $e, ?object $rep): array
    {
        if ($rep && $rep->figures_json && in_array($rep->status, ['submitted', 'approved', 'returned'], true)) {
            $f = json_decode((string) $rep->figures_json, true);
            if (is_array($f)) return $f;
        }
        return self::figures($e);
    }

    /**
     * Save the written parts. A lead only, and only while it is theirs to change: a draft, or a
     * report sent back. Submitting is a separate act (`submit`), so a half-written report can be
     * kept without anybody being asked to sign it.
     *
     * @param array{id:int,name:string} $me
     * @return array{ok:bool, error?:string, msg?:string, event?:int}
     */
    public static function save(int $eventId, array $in, array $me, bool $andSubmit = false): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $e = DB::table('gates_site_events')->where('id', $eventId)->first();
        if (!$e) return ['ok' => false, 'error' => 'That event is not there.'];
        if (!Plan::isLead((int) $me['id'], $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event writes its report.'];
        $rep = self::of($eventId);
        if ($rep && !in_array($rep->status, ['draft', 'returned'], true)) {
            return ['ok' => false, 'error' => $rep->status === 'approved' ? 'The report is signed off. It cannot change now.'
                                                                           : 'The report is with the owner. It can change only if they send it back.'];
        }
        $set = [];
        foreach (array_keys(self::PARTS) as $k) {
            $txt = trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? '')));
            if (mb_strlen($txt) > self::MAX_CHARS) return ['ok' => false, 'error' => self::PARTS[$k][0] . ' is longer than ' . number_format(self::MAX_CHARS) . ' characters.'];
            $set[$k] = $txt !== '' ? $txt : null;
        }
        $now = date('Y-m-d H:i:s');
        $set += ['author_id' => (int) $me['id'], 'author_name' => mb_substr((string) $me['name'], 0, 190) ?: null, 'updated_at' => $now];
        if ($andSubmit) {
            if (!self::after($e)) return ['ok' => false, 'error' => 'The report is submitted after the night, ' . date('D j M', (int) strtotime((string) $e->event_date)) . '. Save it as a draft until then.'];
            if ($set['summary'] === null) return ['ok' => false, 'error' => 'Write the summary before you submit. It is the part the committee reads first.'];
            $set += ['status' => 'submitted', 'submitted_at' => $now, 'figures_json' => json_encode(self::figures($e), JSON_UNESCAPED_UNICODE),
                     'decided_id' => null, 'decided_name' => null, 'decided_at' => null];
        }
        if ($rep) {
            // Claimed on the status it was read with, so two leads saving at once cannot submit
            // over an owner's decision that landed between the read and the write.
            $n = DB::table('gates_event_writeups')->where('id', (int) $rep->id)->where('status', (string) $rep->status)->update($set);
            if ($n < 1) return ['ok' => false, 'error' => 'The report changed while you were writing. Open it again.'];
        } else {
            DB::table('gates_event_writeups')->insertOrIgnore($set + ['event_id' => $eventId, 'status' => $andSubmit ? 'submitted' : 'draft', 'created_at' => $now]);
        }
        if ($andSubmit) {
            $sent = 0;
            foreach (self::signers($eventId, self::of($eventId)) as $o) {
                if ($o->email !== '' && self::mail($o->email, $o->name, $eventId, ['state' => 'submitted', 'by' => (string) $me['name'], 'note' => ''])) $sent++;
            }
            return ['ok' => true, 'event' => $eventId, 'msg' => 'Submitted. ' . ($sent > 0 ? 'The event’s owners have been emailed to sign it off.'
                                                                                     : 'An owner of the event signs it off; none could be emailed, so tell them it is waiting.')];
        }
        return ['ok' => true, 'event' => $eventId, 'msg' => 'Saved as a draft.'];
    }

    /**
     * An owner's decision. `approve` makes it final; `return` sends it back to the leads with a
     * reason, which is required — "sent back" with no reason is a report nobody can fix.
     *
     * @param array{id:int,name:string} $by
     * @return array{ok:bool, error?:string, msg?:string, event?:int, verdict?:string}
     */
    public static function decide(int $eventId, string $verdict, string $note, array $by): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $rep = self::of($eventId);
        if (!$rep || $rep->status !== 'submitted') return ['ok' => false, 'error' => 'There is no report waiting for a decision.'];
        if (!Teams::isOwner((int) $by['id'], $eventId)) return ['ok' => false, 'error' => 'Only an owner of this event signs off its report.'];
        if ((int) $rep->author_id === (int) $by['id']) {
            return ['ok' => false, 'error' => 'You wrote this report, so another owner signs it off.'];
        }
        if (!in_array($verdict, ['approve', 'return'], true)) return ['ok' => false, 'error' => 'Sign it off or send it back.'];
        $note = trim($note);
        if ($verdict === 'return' && $note === '') return ['ok' => false, 'error' => 'Say what needs changing. The leads see it.'];
        $n = DB::table('gates_event_writeups')->where('id', (int) $rep->id)->where('status', 'submitted')->update([
            'status' => $verdict === 'approve' ? 'approved' : 'returned',
            'decided_id' => (int) $by['id'], 'decided_name' => mb_substr((string) $by['name'], 0, 190) ?: null,
            'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($n < 1) return ['ok' => false, 'error' => 'Somebody decided it a moment ago.'];
        $u = $rep->author_id ? DB::table('gates_users')->where('id', (int) $rep->author_id)->first(['name', 'email']) : null;
        if ($u && $u->email) self::mail((string) $u->email, (string) $u->name, $eventId, ['state' => $verdict === 'approve' ? 'approved' : 'returned', 'by' => (string) $by['name'], 'note' => $note]);
        return ['ok' => true, 'event' => $eventId, 'verdict' => $verdict,
                'msg' => $verdict === 'approve' ? 'Signed off. The report is final.' : 'Sent back to the leads with your note.'];
    }

    /** Owners who could sign this report: every live owner but its author. @return list<object> */
    public static function signers(int $eventId, ?object $rep): array
    {
        return array_values(array_filter(Teams::owners($eventId), static fn ($o) => !$rep || (int) $o->actor_id !== (int) $rep->author_id));
    }

    /** Best-effort, like every notice here: a mail outage never blocks a decision, and the page shows where it stands. */
    private static function mail(string $to, string $name, int $eventId, array $vars): bool
    {
        try {
            $e = Plan::event($eventId);
            $first = trim((string) strtok(trim($name), ' ')) ?: 'there';
            return !empty(Emails::send(OtpService::boot(), $to, 'event-report', ['first_name' => $first, 'place' => (string) ($e->title ?? 'your event'),
                'action_url' => rtrim(SiteUrl::base(), '/') . EventPages::url($eventId, 'reports', 'post'), 'action_label' => 'Open the report'] + $vars)['success']);
        } catch (\Throwable) {
            return false;
        }
    }
}
