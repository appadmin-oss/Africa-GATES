<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The questionnaire deadline, and the consequence an organiser attaches to missing it.
 *
 * ── THE DEADLINE IS NOT A GATE ───────────────────────────────────────────────
 *
 * Nothing in here stops anybody answering. That is settled policy on this platform: the
 * questionnaire stays fillable after the deadline and after voting closes, because a
 * nominee who finally reaches a computer on the Tuesday has given the judges something
 * useful, and a form that has locked them out has given the judges nothing. The deadline
 * exists so a nominee knows there is a date, and so an organiser can decide what a missed
 * one means.
 *
 * So the deadline drives two things only: what the invitation says, and whether
 * {@see enforce()} disqualifies.
 *
 * ── AND DISQUALIFICATION IS AN ACT, NOT A CONSEQUENCE ────────────────────────
 *
 * "Auto-disqualify if not filled" is a rule that acts on real people while nobody is
 * watching. A cron that flips a status and moves on leaves an organiser unable to answer
 * the only question they will be asked — "why is this nominee gone?" — so every
 * disqualification records when it fired and what the rule was, and
 * {@see reinstate()} undoes it completely. It also fires only where an organiser turned it
 * on for that cycle: off is the default, and stays the default.
 *
 * `grace_days` is here because a deadline and its enforcement should never be the same
 * instant. Invitations land in spam, phones break, and somebody submitting four hours
 * after midnight has not declined to take part.
 */
final class QuestionnairePolicy
{
    /** Days between the deadline and enforcement, when a cycle has never been configured. */
    public const DEFAULT_GRACE = 3;

    /**
     * The policy for a cycle. Always returns a usable shape, configured or not.
     *
     * @return array{
     *   cycle_id:int, deadline_at:?string, autodisqualify:bool, grace_days:int,
     *   source:string, enforce_at:?string
     * }
     */
    public static function forCycle(int $cycleId): array
    {
        $row = $cycleId > 0
            ? DB::table('gates_questionnaire_policy')->where('cycle_id', $cycleId)->first()
            : null;

        $explicit = trim((string) ($row->deadline_at ?? ''));
        $deadline = $explicit !== '' ? $explicit : self::derived($cycleId);
        $grace    = max(0, (int) ($row->grace_days ?? self::DEFAULT_GRACE));

        return [
            'cycle_id'       => $cycleId,
            'deadline_at'    => $deadline !== '' ? $deadline : null,
            'autodisqualify' => (bool) (int) ($row->autodisqualify ?? 0),
            'grace_days'     => $grace,
            // Which of the two the date came from. The screen says so, because an organiser
            // who has not set one is looking at a date that will move when they reschedule
            // the results — and has no way to know that from the date itself.
            'source'         => $explicit !== '' ? 'set' : ($deadline !== '' ? 'derived' : 'none'),
            'enforce_at'     => $deadline !== ''
                ? Carbon::parse($deadline)->addDays($grace)->toDateTimeString()
                : null,
        ];
    }

    /**
     * The date the old implementation inferred, kept as the fallback.
     *
     * It is the wrong field to hang a deadline on — moving the results date silently
     * changes what every invitation already sent meant — but it is what deployments have
     * been telling nominees, so it stays until an organiser sets a real one.
     */
    public static function derived(int $cycleId): string
    {
        if ($cycleId <= 0) return '';
        try {
            $c = DB::table('gates_award_cycles')->where('id', $cycleId)
                ->first(['results_date', 'voting_close']);

            return $c ? trim((string) ($c->results_date ?? $c->voting_close ?? '')) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The deadline as a nominee should read it: "15 October 2026", or '' when there is none.
     *
     * No seconds. A nominee reading a date with seconds on it is reading a database column,
     * and the seconds are noise on a deadline that is really about which day.
     */
    public static function humanFor(int $cycleId): string
    {
        $at = self::forCycle($cycleId)['deadline_at'];
        if ($at === null) return '';

        try { return Carbon::parse($at)->format('j F Y'); }
        catch (\Throwable) { return $at; }
    }

    /** @param array<string,mixed> $in a form POST */
    public static function save(int $cycleId, array $in, int $adminId): array
    {
        if ($cycleId <= 0) return ['ok' => false, 'message' => 'Pick a cycle first.'];

        $raw  = trim((string) ($in['deadline_at'] ?? ''));
        $when = null;
        if ($raw !== '') {
            try {
                $when = Carbon::parse($raw)->toDateTimeString();
            } catch (\Throwable) {
                return ['ok' => false, 'message' => 'That date could not be read. Use the date picker.'];
            }
        }

        $auto  = (bool) (int) ($in['autodisqualify'] ?? 0);
        $grace = max(0, min(365, (int) ($in['grace_days'] ?? self::DEFAULT_GRACE)));

        // Turning enforcement on with no deadline to enforce is a rule that can never fire,
        // and it would sit on the screen looking armed. Refused rather than clamped: the
        // organiser has to decide which of the two they actually meant.
        if ($auto && $when === null && self::derived($cycleId) === '') {
            return ['ok' => false, 'message' => 'Set a deadline before switching on auto-disqualification — '
                                              . 'there is nothing for the rule to measure against.'];
        }

        $row = [
            'deadline_at'    => $when,
            'autodisqualify' => (int) $auto,
            'grace_days'     => $grace,
            'updated_at'     => Carbon::now()->toDateTimeString(),
            'updated_by'     => $adminId ?: null,
        ];

        $id = DB::table('gates_questionnaire_policy')->where('cycle_id', $cycleId)->value('id');
        if ($id) DB::table('gates_questionnaire_policy')->where('id', $id)->update($row);
        else     DB::table('gates_questionnaire_policy')->insert($row + ['cycle_id' => $cycleId]);

        $p = self::forCycle($cycleId);

        return ['ok' => true, 'message' => 'Saved. ' . self::describe($p)];
    }

    /** @param array<string,mixed> $p the shape returned by {@see forCycle()} */
    public static function describe(array $p): string
    {
        if ($p['deadline_at'] === null) {
            return 'No deadline is set, so nominees are not told of one and nothing is enforced.';
        }

        $when = Carbon::parse((string) $p['deadline_at'])->format('j F Y');
        $s = ($p['source'] === 'derived'
                ? "Nominees are told {$when}, taken from the cycle's results date — set one here to fix it."
                : "Nominees are told {$when}.");

        if (!$p['autodisqualify']) {
            return $s . ' A nominee who misses it keeps their nomination.';
        }

        $on = Carbon::parse((string) $p['enforce_at'])->format('j F Y');

        return $s . " Anyone who has not submitted is disqualified on {$on}"
             . ($p['grace_days'] > 0 ? " ({$p['grace_days']} days' grace)." : '.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ENFORCEMENT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Disqualify everyone in a cycle who has not submitted by deadline + grace.
     *
     * `$dryRun` is not a courtesy. This is a destructive-by-design operation that runs
     * unattended, so the screen shows an organiser exactly who it would take BEFORE the
     * rule ever fires, and the command has the same switch.
     *
     * @return array{ok:bool, cycle_id:int, would:int, done:int, names:list<string>, message:string}
     */
    public static function enforce(int $cycleId, bool $dryRun = true, int $adminId = 0): array
    {
        $p = self::forCycle($cycleId);
        $none = ['ok' => false, 'cycle_id' => $cycleId, 'would' => 0, 'done' => 0, 'names' => []];

        if (!$p['autodisqualify']) {
            return $none + ['message' => 'Auto-disqualification is off for this cycle.'];
        }
        if ($p['enforce_at'] === null) {
            return $none + ['message' => 'This cycle has no deadline to enforce.'];
        }
        if (Carbon::parse((string) $p['enforce_at'])->isFuture()) {
            return $none + ['message' => 'The deadline has not passed yet (enforced from '
                                       . Carbon::parse((string) $p['enforce_at'])->format('j F Y') . ').'];
        }

        // Draft and withdrawn both mean "did not submit". A test row never counts — it has
        // no nominee behind it, so disqualifying one would be disqualifying nobody while
        // showing an organiser a number that looked like somebody.
        $rows = DB::table('gates_nominee_submissions AS s')
            ->leftJoin('gates_nominees AS n', 'n.id', '=', 's.nominee_id')
            ->where('s.cycle_id', $cycleId)
            ->whereIn('s.status', ['draft', 'withdrawn'])
            ->where(fn ($q) => $q->whereNull('s.is_test')->orWhere('s.is_test', 0))
            ->get(['s.id', 's.nominee_id', 'n.name']);

        $names = [];
        foreach ($rows as $r) $names[] = (string) ($r->name ?? ('#' . $r->nominee_id));

        if ($dryRun) {
            return ['ok' => true, 'cycle_id' => $cycleId, 'would' => count($names), 'done' => 0,
                    'names' => $names,
                    'message' => count($names) . ' nominee' . (count($names) === 1 ? '' : 's')
                               . ' would be disqualified. Nothing has changed.'];
        }

        $note = 'Missed the questionnaire deadline of '
              . Carbon::parse((string) $p['deadline_at'])->format('j F Y')
              . ($p['grace_days'] > 0 ? " plus {$p['grace_days']} days' grace" : '') . '.';

        $done = 0;
        foreach ($rows as $r) {
            $done += DB::table('gates_nominee_submissions')->where('id', $r->id)->update([
                'status'            => 'disqualified',
                'autodisqualify_at' => Carbon::now()->toDateTimeString(),
                'disqualify_note'   => mb_substr($note, 0, 300),
                'updated_at'        => Carbon::now()->toDateTimeString(),
            ]);
        }

        return ['ok' => true, 'cycle_id' => $cycleId, 'would' => count($names), 'done' => $done,
                'names' => $names,
                'message' => $done . ' nominee' . ($done === 1 ? '' : 's') . ' disqualified.'];
    }

    /**
     * Undo one disqualification completely.
     *
     * Back to 'draft', not 'submitted': they still have not answered, and the whole point of
     * reinstating somebody is that they now can. Both stamps are cleared, so the row does
     * not carry a disqualification that is no longer true.
     */
    public static function reinstate(int $submissionId, int $adminId = 0): array
    {
        $s = DB::table('gates_nominee_submissions')->where('id', $submissionId)->first(['status']);
        if (!$s) return ['ok' => false, 'message' => 'That questionnaire could not be found.'];

        if ((string) $s->status !== 'disqualified') {
            return ['ok' => false, 'message' => 'That nominee is not disqualified.'];
        }

        DB::table('gates_nominee_submissions')->where('id', $submissionId)->update([
            'status'            => 'draft',
            'autodisqualify_at' => null,
            'disqualify_note'   => null,
            'updated_at'        => Carbon::now()->toDateTimeString(),
        ]);

        return ['ok' => true, 'message' => 'Reinstated — their questionnaire is open again.'];
    }

    /** Every cycle with auto-disqualification switched on, for the cron to walk. */
    public static function armedCycles(): array
    {
        return array_map('intval', DB::table('gates_questionnaire_policy')
            ->where('autodisqualify', 1)->pluck('cycle_id')->all());
    }
}
