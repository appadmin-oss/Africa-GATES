<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * EXTENDING VOTING, ON THE RECORD — the one reader and the one writer of
 * `gates_voting_extensions` (policy: docs/VOTING-EXTENSION-POLICY.md).
 *
 * An extension used to be an operator quietly editing `voting_close`. Nothing recorded that
 * the date had moved, nothing told the public why, and nothing stopped it reading as a way to
 * sell more paid votes on a race somebody wanted to change. So an extension is now:
 *
 *   · a ROW, with the previous and new close, a public reason, who and when;
 *   · a SNAPSHOT of the standings at the moment it was recorded (the vote-snapshot chain), so
 *     nobody can later claim the date was moved to suit a leaderboard;
 *   · a PAID CUTOFF by default: paid checkout stops at the previous close, so the extra time
 *     is free voting only and the extension cannot be a cash grab. Orders started before the
 *     cutoff still mint inside the existing late-mint grace — {@see PaidVoteService::mint()}
 *     is untouched; only whether a NEW checkout may start changes
 *     ({@see PaidVoteService::checkoutClosesAt()});
 *   · a PUBLIC NOTICE on every page that states the closing time ({@see notice()}).
 *
 * It applies to the whole edition by construction: `voting_close` is the cycle's, and every
 * category in the cycle reads it.
 *
 * The operator has already extended a live cycle by editing the date, so the form can also
 * RECORD an extension already made: new close == the current close, previous close earlier.
 *
 * Every read is guarded by {@see SchemaHas::table()}, so a deployment whose migration has not
 * run reads "no extensions" and behaves exactly as before. The paid-cutoff read gates a write
 * (a checkout), so like {@see BallotGuard::cycleForCategory()} it is deliberately NOT memoised.
 */
final class VotingExtension
{
    public const TABLE      = 'gates_voting_extensions';
    public const REASON_MIN = 20;
    public const REASON_MAX = 1000;
    /** Guidance, not a rule the code enforces: the policy's longest ordinary extension. */
    public const GUIDE_MAX_HOURS = 72;

    public const MODE_MOVED    = 'moved';
    public const MODE_RECORDED = 'recorded';

    public static function available(): bool
    {
        return SchemaHas::table(self::TABLE);
    }

    /** @return list<object> every extension of the cycle, oldest first */
    public static function forCycle(int $cycleId): array
    {
        if ($cycleId < 1 || !self::available()) return [];
        try {
            return DB::table(self::TABLE)->where('cycle_id', $cycleId)->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function latest(int $cycleId): ?object
    {
        if ($cycleId < 1 || !self::available()) return null;
        try {
            return DB::table(self::TABLE)->where('cycle_id', $cycleId)->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** When paid checkout stops for this cycle because voting was extended, or null. */
    public static function paidCutoffFor(int $cycleId): ?Carbon
    {
        return self::at(self::latest($cycleId)->paid_cutoff_at ?? null);
    }

    public static function paidCutoffForCategory(int $categoryId): ?Carbon
    {
        if ($categoryId < 1 || !self::available()) return null;
        try {
            $cycleId = (int) (DB::table('gates_award_categories')->where('id', $categoryId)->value('cycle_id') ?? 0);
        } catch (\Throwable) {
            return null;
        }
        return self::paidCutoffFor($cycleId);
    }

    /** True when an extension's paid cutoff has been reached for this category's edition. */
    public static function paidClosedFor(int $categoryId, ?Carbon $at = null): bool
    {
        $cut = self::paidCutoffForCategory($categoryId);
        return $cut !== null && ($at ?? Carbon::now())->gte($cut);
    }

    /**
     * "Paid voting for this edition closed on X when voting was extended; free voting stays
     * open until Y." — the sentence every paid surface gives once the cutoff has passed.
     */
    public static function paidClosedSentence(int $cycleId, ?Carbon $at = null): ?string
    {
        $cut = self::paidCutoffFor($cycleId);
        if ($cut === null || ($at ?? Carbon::now())->lt($cut)) return null;
        $close = '';
        try {
            $close = (string) (DB::table('gates_award_cycles')->where('id', $cycleId)->value('voting_close') ?? '');
        } catch (\Throwable) {}

        return Translator::t('Paid voting for this edition closed on %x% when voting was extended; free voting stays open until %y%.', [
            '%x%' => DisplayTime::showZoned($cut->toDateTimeString()),
            '%y%' => DisplayTime::showZoned($close),
        ]);
    }

    public static function paidClosedSentenceForCategory(int $categoryId, ?Carbon $at = null): ?string
    {
        try {
            $cycleId = (int) (DB::table('gates_award_categories')->where('id', $categoryId)->value('cycle_id') ?? 0);
        } catch (\Throwable) {
            return null;
        }
        return self::paidClosedSentence($cycleId, $at);
    }

    /**
     * The public notice, for any cycle with an extension; null otherwise. Dates are already
     * formatted ({@see DisplayTime::showZoned()}); the reasons are raw text for Twig to escape.
     *
     * With more than one extension it states the whole move — from the ORIGINAL close to the
     * latest — and every reason, because a notice that only explains the last step hides the
     * first one.
     *
     * @return array{from:string,to:string,reasons:list<string>,count:int,paid_cutoff:?string,paid_cutoff_passed:bool,paid_on:bool}|null
     */
    public static function notice(int $cycleId, ?Carbon $now = null): ?array
    {
        $all = self::forCycle($cycleId);
        if ($all === []) return null;
        $first = $all[0];
        $last  = $all[count($all) - 1];
        $cut   = self::at($last->paid_cutoff_at ?? null);

        $reasons = array_values(array_map(static fn (object $r): string => (string) $r->reason, $all));
        return [
            // Closing times, so a midnight close reads as the end of the day before rather than
            // as a day of voting nobody has (DisplayTime::showClose).
            'from'               => DisplayTime::showClose((string) $first->previous_close, false),
            'to'                 => DisplayTime::showClose((string) $last->new_close),
            'reasons'            => $reasons,
            'ticks'              => self::ticks(implode(' ', $reasons)),
            'count'              => count($all),
            'paid_cutoff'        => $cut ? DisplayTime::showClose($cut->toDateTimeString()) : null,
            'paid_cutoff_passed' => $cut !== null && ($now ?? Carbon::now())->gte($cut),
            'paid_on'            => PaidVoteService::enabled(),
        ];
    }

    /**
     * The extension as ROWS FOR THE ANNOUNCEMENT STRIP (SiteNotice) — owner, 20 Nov 2026: "the
     * extension should use the announce feature, dismissable, and not crowd the whole
     * interface". It was a card inside every voting page, the results page and the award page.
     *
     * One row per announcement: editions extended to the same close for the same reason (one
     * extension applied to several awards) share a row naming the awards, so three awards do
     * not stack three banners. Only while the extended voting is still running, and only for
     * active programmes (the sandbox's is inactive). The id is the content, so a further
     * extension shows again to somebody who hid the first.
     *
     * @return list<array{kind:string,id:string,dismiss:bool,to:string,short:string,which:string,awards:list<string>,reasons:list<string>,ticks:list<string>,paid:list<string>}>
     */
    public static function rows(?Carbon $now = null): array
    {
        if (!self::available()) return [];
        $now = $now ?? Carbon::now();
        try {
            $cycles = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)->whereNotNull('c.voting_close')
                ->whereIn('c.id', DB::table(self::TABLE)->select('cycle_id'))
                ->orderBy('p.title')->get(['c.id', 'c.voting_close', 'p.title']);
        } catch (\Throwable) {
            return [];
        }
        $groups = [];
        foreach ($cycles as $c) {
            $close = self::at($c->voting_close);
            if ($close === null || $now->gte($close)) continue;           // over: nothing left to announce
            $n = self::notice((int) $c->id, $now);
            if ($n === null) continue;
            // Grouped on what the public reads first, the new close and the reason. A paid cutoff
            // a few minutes apart (one award extended before the old close, another recorded
            // after it) must not split one announcement into two banners; it is stated per
            // award inside the row instead.
            $key = $n['to'] . '|' . implode('|', $n['reasons']);
            $groups[$key] ??= ['n' => $n, 'awards' => [], 'paid' => [], 'close' => (string) $c->voting_close];
            $groups[$key]['awards'][] = (string) $c->title;
            if ($n['paid_cutoff'] && $n['paid_on']) {
                $groups[$key]['paid'][(string) $c->title] = $n['paid_cutoff_passed']
                    ? Translator::t('Paid voting closed at %c%; free voting stays open.', ['%c%' => $n['paid_cutoff']])
                    : Translator::t('Paid voting closes at %c%; free voting stays open until %t%.', ['%c%' => $n['paid_cutoff'], '%t%' => $n['to']]);
            }
        }
        $out = [];
        foreach ($groups as $key => $g) {
            $n = $g['n'];
            // One sentence when every award says the same; otherwise each award its own.
            $distinct = array_values(array_unique($g['paid']));
            $paid = count($distinct) <= 1 ? $distinct
                : array_values(array_map(static fn (string $award, string $line): string => $award . ': ' . $line,
                                         array_keys($g['paid']), $g['paid']));
            // The closed row has to fit a phone in two lines, so it carries the short form (no
            // year, no zone) and a count of awards; the exact time and the names are inside.
            $close = self::at($g['close']);
            $short = $close === null ? $n['to'] : (DisplayTime::show($g['close'], 'H:i:s') === '00:00:00'
                ? Translator::t('until the end of %d%', ['%d%' => DisplayTime::show($close->copy()->subDay()->toDateTimeString(), 'D j M')])
                : Translator::t('until %d%', ['%d%' => DisplayTime::show($g['close'], 'D j M, H:i')]));
            $count = count($g['awards']);
            $out[] = ['kind' => 'extension', 'dismiss' => true, 'id' => 'x' . substr(sha1($key . '|' . implode(',', $g['awards'])), 0, 12),
                      'to' => $n['to'], 'short' => $short,
                      'which' => $count === 1 ? $g['awards'][0] : Translator::t('%n% awards', ['%n%' => $count]),
                      'awards' => $g['awards'], 'reasons' => $n['reasons'], 'ticks' => $n['ticks'], 'paid' => $paid];
        }
        return $out;
    }

    /**
     * The card's "what did not change" ticks, LESS what the reason already says.
     *
     * The ticks were drawn under every reason, and an operator's reason naturally says the
     * same things ("every vote already cast stands, and the rules are unchanged … for every
     * nominee equally"), so the card stated them twice, one under the other. A tick is shown
     * only when the reason does not already make its point; together they always say all
     * three. Phrase checks, not understanding: a reason that says it some other way gets the
     * tick as well, which is repetition, never an omission.
     *
     * @return list<string> the tick sentences to draw, untranslated (the template passes |trans)
     */
    public static function ticks(string $reason): array
    {
        $r = mb_strtolower($reason);
        $out = [];
        if (!preg_match('/already cast|cast so far|votes? (?:cast )?(?:still )?stands?|stand(?:s)? as cast/u', $r)) {
            $out[] = 'Votes already cast stand';
        }
        $rules  = (bool) preg_match('/rules?\b[^.]{0,40}\b(?:unchanged|the same|stay)|(?:unchanged|same)\b[^.]{0,20}\brules?\b/u', $r);
        $prices = (bool) preg_match('/prices?\b[^.]{0,40}\b(?:unchanged|the same|stay)|(?:unchanged|same)\b[^.]{0,20}\bprices?\b/u', $r);
        if (!$rules && !$prices) $out[] = 'Rules and prices unchanged';
        elseif ($rules && !$prices) $out[] = 'Prices unchanged';
        elseif (!$rules && $prices) $out[] = 'Rules unchanged';
        if (!preg_match('/every nominee|all nominees|each nominee|equally|every category|all categories/u', $r)) {
            $out[] = 'Same date for every nominee';
        }
        return $out;
    }

    /**
     * The other editions one extension can be applied to at the same time, from any award:
     * those whose voting has opened, whose results are not released, and which have a close
     * date. An extension is per edition by construction ({@see extend()}), so an announcement
     * meant for "every nominee in every category" across several awards used to need the form
     * once per award — and the editions an operator moved by editing the date instead were
     * left with a later close and no notice at all (ABA and ACA, live, 20 Nov 2026).
     *
     * @return list<object{id:int,programme:string,label:string,voting_close:string}>
     */
    public static function companions(int $cycleId, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        try {
            $rows = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', '!=', $cycleId)->whereNotNull('c.voting_close')->whereNotNull('c.voting_open')
                ->where('p.is_active', 1)
                ->orderBy('p.title')->get(['c.*', 'p.title as programme_title']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $c) {
            $open = self::at($c->voting_open);
            if ($open === null || $now->lt($open)) continue;
            $phase = CyclePolicy::phaseFor($c, $now);
            if (in_array($phase, [CyclePhase::Results, CyclePhase::Archived], true)) continue;
            $out[] = (object) ['id' => (int) $c->id, 'programme' => (string) $c->programme_title,
                               'label' => trim((string) ($c->edition_label ?? '')) ?: (string) $c->year,
                               'voting_close' => (string) $c->voting_close, 'extended' => self::latest((int) $c->id) !== null];
        }
        return $out;
    }

    /**
     * Record an extension of one cycle. Validates, snapshots, writes the row and (unless it
     * only records a change already made) moves `voting_close`. The caller audits and busts
     * the award caches.
     *
     * @param array{previous_close?:mixed,new_close?:mixed,reason?:mixed,stop_paid?:mixed,further?:mixed} $in
     *        dates as TYPED in the display zone (datetime-local), converted here
     * @return array{ok:bool,error?:string,mode?:string,id?:int,before?:array,after?:array,snapshot_ref?:?string,warnings?:list<string>}
     */
    public static function extend(object $cycle, array $in, ?int $adminId, ?Carbon $now = null, ?SnapshotService $snapshots = null): array
    {
        $now = $now ?? Carbon::now();
        $fail = static fn (string $why): array => ['ok' => false, 'error' => $why];

        if (!self::available()) {
            return $fail('Extensions cannot be recorded until the 2027_06_03_voting_extensions migration has run. Nothing was changed.');
        }

        $reason = trim(preg_replace('/\s+/u', ' ', (string) ($in['reason'] ?? '')) ?? '');
        $len = mb_strlen($reason);
        if ($len < self::REASON_MIN || $len > self::REASON_MAX) {
            return $fail('The public reason must be between ' . self::REASON_MIN . ' and ' . self::REASON_MAX
                . ' characters (it is ' . $len . '). It is printed on every voting page, so say what happened.');
        }

        $prevS = DisplayTime::toStored(is_string($in['previous_close'] ?? null) ? $in['previous_close'] : null);
        $newS  = DisplayTime::toStored(is_string($in['new_close'] ?? null) ? $in['new_close'] : null);
        $prev  = self::at($prevS);
        $new   = self::at($newS);
        $cur   = self::at($cycle->voting_close ?? null);
        if ($cur === null)  return $fail('This edition has no voting close date, so there is nothing to extend.');
        if ($prev === null) return $fail('Enter the previous close.');
        if ($new === null)  return $fail('Enter the new close.');
        if (!$new->gt($prev)) return $fail('The new close must be later than the previous close.');
        if ($prev->gt($cur)) {
            return $fail('The previous close cannot be later than the voting close currently set ('
                . DisplayTime::showZoned($cur->toDateTimeString()) . ').');
        }
        if ($new->lt($cur)) {
            return $fail('The new close is earlier than the voting close currently set ('
                . DisplayTime::showZoned($cur->toDateTimeString()) . '). Shortening voting is not an extension.');
        }

        $phase = CyclePolicy::phaseFor($cycle, $now);
        if (in_array($phase, [CyclePhase::Results, CyclePhase::Archived], true)) {
            return $fail('This edition\'s results are already released, so voting cannot be extended.');
        }
        $results = self::at($cycle->results_date ?? null);
        if ($results !== null && $new->gte($results)) {
            return $fail('The new close must be before the results date ('
                . DisplayTime::showZoned($results->toDateTimeString()) . '). Move the results date first if the policy allows it.');
        }

        $prior = self::forCycle((int) $cycle->id);
        if ($prior !== [] && empty($in['further'])) {
            return $fail('This edition has already been extended ' . count($prior) . ' time' . (count($prior) === 1 ? '' : 's')
                . '. The policy allows one extension; tick "This is a further extension" to record another.');
        }

        $stopPaid = !empty($in['stop_paid']);
        if ($stopPaid && PaidVoteService::freeVotingDisabled()) {
            return $fail('Free voting is switched off for this award, so stopping paid votes would end voting altogether. '
                . 'Untick "Stop paid votes at the previous close", or switch free voting on first.');
        }

        $mode = ($new->eq($cur) && $prev->lt($cur)) ? self::MODE_RECORDED : self::MODE_MOVED;

        // ── THE PAID CUTOFF HAS TO BE TRUE ───────────────────────────────────
        // Normally the previous close. But where the ballot actually stayed open past it —
        // an extension already made by editing the date, and recorded only now — paid
        // checkout DID take orders after the previous close, and the public sentence "paid
        // voting closed at {previous}" would be false. Then it stops at the moment the ballot
        // demonstrably stopped selling: now, or the current close if that has passed.
        $cutoff = null;
        if ($stopPaid) {
            $cutoff = $prev->copy();
            if ($cur->gt($prev) && $now->gt($prev)) {
                $cutoff = ($now->lt($cur) ? $now : $cur)->copy();
            }
        }

        $warnings = [];
        if ($cutoff !== null && !$cutoff->eq($prev)) {
            $warnings[] = 'Paid votes were taken after the previous close while the date was already moved, so paid checkout stops at '
                . DisplayTime::showZoned($cutoff->toDateTimeString()) . ' rather than the previous close. Those votes stand.';
        }
        if ($new->diffInHours($prev, true) > self::GUIDE_MAX_HOURS) {
            $warnings[] = 'This extension is longer than the policy\'s ' . self::GUIDE_MAX_HOURS . ' hours.';
        }

        // The standings as they were when the extension was recorded. A failure here must not
        // block the extension (an outage is often the very reason for it): record null, say so.
        $ref = null;
        try {
            $ref = ($snapshots ?? new SnapshotService())->captureCycle((int) $cycle->id);
        } catch (\Throwable $e) {
            error_log('[voting-extension] snapshot for cycle ' . (int) $cycle->id . ': ' . $e->getMessage());
        }
        if ($ref === null) {
            $warnings[] = 'The standings could not be captured, so this extension has no snapshot reference.';
        }

        $row = [
            'cycle_id'       => (int) $cycle->id,
            'previous_close' => $prev->toDateTimeString(),
            'new_close'      => $new->toDateTimeString(),
            'reason'         => $reason,
            'paid_cutoff_at' => $cutoff?->toDateTimeString(),
            'snapshot_ref'   => $ref !== null ? mb_substr($ref, 0, 80) : null,
            'mode'           => $mode,
            // NULL, never 0: there is no admin 0 (CLAUDE.md, the audit FK).
            'admin_id'       => ($adminId !== null && $adminId > 0) ? $adminId : null,
            'created_at'     => $now->toDateTimeString(),
        ];

        try {
            $id = (int) DB::connection()->transaction(function () use ($row, $mode, $cycle, $newS): int {
                $id = (int) DB::table(self::TABLE)->insertGetId($row);
                if ($mode === self::MODE_MOVED) {
                    DB::table('gates_award_cycles')->where('id', (int) $cycle->id)->update(['voting_close' => $newS]);
                }
                return $id;
            });
        } catch (\Throwable $e) {
            error_log('[voting-extension] write for cycle ' . (int) $cycle->id . ': ' . $e->getMessage());
            return $fail('The extension could not be saved. Nothing was changed.');
        }

        // Keep the indexed boundary in step, as cycleSave() does. Best-effort: the column may
        // predate its migration.
        if ($mode === self::MODE_MOVED) {
            try {
                $fresh = DB::table('gates_award_cycles')->where('id', (int) $cycle->id)->first();
                if ($fresh) {
                    DB::table('gates_award_cycles')->where('id', (int) $cycle->id)
                        ->update(['next_boundary_at' => CyclePolicy::nextBoundaryFor($fresh)]);
                }
            } catch (\Throwable) {}
        }

        return [
            'ok'           => true,
            'id'           => $id,
            'mode'         => $mode,
            'before'       => ['voting_close' => $cur->toDateTimeString()],
            'after'        => ['voting_close' => $mode === self::MODE_MOVED ? $newS : $cur->toDateTimeString()],
            'row'          => $row,
            'snapshot_ref' => $row['snapshot_ref'],
            'warnings'     => $warnings,
        ];
    }

    private static function at(mixed $v): ?Carbon
    {
        if ($v === null || $v === '' || $v === '0000-00-00 00:00:00') return null;
        if ($v instanceof Carbon) return $v->copy();
        try { return Carbon::parse((string) $v); } catch (\Throwable) { return null; }
    }
}
