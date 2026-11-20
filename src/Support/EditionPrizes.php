<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What each place wins in one edition (owner, 31 Oct 2026): 1st, 2nd, 3rd…, each with what it
 * receives and, where there is one, a cash figure in whole naira.
 *
 * Per EDITION, because prizes change year to year and the award spans years; by PLACE, because
 * a nominee asks "what does second get?" and one paragraph could not answer. The places are the
 * edition's OVERALL standing, across every category — never a prize per category — and every
 * reader says so (LEAD), so nobody reads a list of places as one per category.
 *
 * Read on the award page, in the nominee's voting letter (email and PDF) and on the edition's
 * admin page. One reader, so the three cannot disagree.
 */
final class EditionPrizes
{
    public const MAX = 10;
    public const AWARD_MAX = 1000;
    public const LEAD = 'For the overall winners of the edition, across every category.';

    /**
     * Do this edition's prizes depend on its milestone? On unless an operator turned it off;
     * a database without the column answers on, which is what the terms already say.
     */
    public static function onMilestone(?int $cycleId): bool
    {
        if (!$cycleId) return false;
        try {
            $v = DB::table('gates_award_cycles')->where('id', $cycleId)->value('prizes_on_milestone');
        } catch (\Throwable) {
            return true;
        }
        return $v === null || (int) $v === 1;
    }

    public static function setOnMilestone(int $cycleId, bool $on): void
    {
        try {
            DB::table('gates_award_cycles')->where('id', $cycleId)->update(['prizes_on_milestone' => $on ? 1 : 0]);
        } catch (\Throwable) {}
    }

    /**
     * The condition the prizes come with, or null where there is none to state (turned off, or
     * no milestone set). `text` is for a page read today and says where things stand; `letter`
     * is for a letter, which is read later, so it gives no figure that will have moved.
     *
     * @return array{milestone_naira:int, raised_naira:int, reached:bool, text:string, letter:string}|null
     */
    public static function condition(?int $cycleId): ?array
    {
        if (!$cycleId || !self::onMilestone($cycleId)) return null;
        $p = \AfricaGates\Services\CommunityReturnMilestone::progress($cycleId);
        if ($p['target_kobo'] <= 0) return null;
        $m = intdiv($p['target_kobo'], 100);
        $raised = intdiv(max(0, $p['measured_kobo']), 100);
        $ms = '₦' . number_format($m);
        $if = 'If it is not reached, they may be reduced or not awarded, as the award’s terms set out.';
        return [
            'milestone_naira' => $m, 'raised_naira' => $raised, 'reached' => $p['reached'],
            'text' => $p['reached']
                ? 'The edition has reached its ' . $ms . ' milestone, so these prizes will be awarded.'
                : 'These prizes depend on the edition reaching its ' . $ms . ' milestone (₦' . number_format($raised) . ' so far). ' . $if,
            'letter' => 'These prizes depend on the edition reaching its ' . $ms . ' milestone. ' . $if,
        ];
    }

    /**
     * The edition's places, in order.
     *
     * @return list<array{place:int, label:string, award:string, cash_naira:?int, text:string}>
     */
    public static function for(?int $cycleId): array
    {
        if (!$cycleId) return [];
        try {
            $rows = DB::table('gates_award_prizes')->where('cycle_id', $cycleId)->orderBy('place')->get();
        } catch (\Throwable) {
            return [];   // the migration has not run yet: say nothing rather than break a page
        }
        $out = [];
        foreach ($rows as $r) {
            $cash = $r->cash_naira !== null ? (int) $r->cash_naira : null;
            $award = trim((string) $r->award);
            $out[] = ['place' => (int) $r->place, 'label' => self::ordinal((int) $r->place), 'award' => $award,
                      'cash_naira' => $cash, 'text' => self::text($award, $cash)];
        }
        return $out;
    }

    /** "₦500,000 and a trophy" — the cash first where there is some, then the words. */
    public static function text(string $award, ?int $cash): string
    {
        $money = $cash ? '₦' . number_format($cash) : '';
        if ($money === '') return $award;
        if ($award === '') return $money;
        return $money . ' and ' . (mb_strtolower(mb_substr($award, 0, 1)) . mb_substr($award, 1));
    }

    public static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $suffix . ' place';
    }

    /**
     * Replace the edition's places with what the form sent. Blank rows are dropped and the rest
     * are numbered in the order given, so there is never a gap ("1st, 2nd, 4th"). Returns an
     * error to show, or null when saved.
     *
     * @param list<array{award?:mixed, cash?:mixed}> $rows
     */
    public static function save(int $cycleId, array $rows): ?string
    {
        $clean = [];
        foreach (array_values($rows) as $r) {
            $award = trim((string) ($r['award'] ?? ''));
            $cashRaw = preg_replace('/[\s,₦]/u', '', (string) ($r['cash'] ?? ''));
            if ($award === '' && $cashRaw === '') continue;
            if ($cashRaw !== '' && !ctype_digit($cashRaw)) return 'Cash is whole naira, numbers only (' . $cashRaw . ').';
            if (mb_strlen($award) > self::AWARD_MAX) return 'Keep each prize under ' . self::AWARD_MAX . ' characters.';
            $cash = $cashRaw === '' ? null : (int) $cashRaw;
            if ($award === '' && !$cash) continue;
            $clean[] = ['award' => $award, 'cash_naira' => $cash ?: null];
        }
        if (count($clean) > self::MAX) return 'At most ' . self::MAX . ' places.';

        DB::transaction(function () use ($cycleId, $clean) {
            DB::table('gates_award_prizes')->where('cycle_id', $cycleId)->delete();
            $now = Carbon::now()->toDateTimeString();
            foreach ($clean as $i => $r) {
                DB::table('gates_award_prizes')->insert(['cycle_id' => $cycleId, 'place' => $i + 1] + $r + ['updated_at' => $now]);
            }
        });
        return null;
    }
}
