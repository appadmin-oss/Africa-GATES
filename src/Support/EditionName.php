<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT AN EDITION IS CALLED — the one answer (Phase 5, REFERENCE §11).
 *
 * The redesign names an edition the same way on every award surface: "11th Edition · 2026"
 * in the edition picker, "11th Edition · voting open" under the vote page's title, "3rd
 * Edition" on the results switcher. Before this there were a dozen hand-rolled
 * `edition_label ?: year` expressions, which agree today only because both halves are
 * usually empty; the first operator to type a label would have had one page say
 * "Rehearsal" and the next "2026" about the same cycle.
 *
 * Resolution, in order:
 *   1. an operator's own `edition_label` ("Centenary Edition") — a person's words win;
 *   2. `edition_number` ("11th Edition") — stored, because a programme may have run for
 *      years before it came to this platform (migrations/2027_03_05_edition_number.php);
 *   3. the cycle's place among its programme's cycles by (year, id) — the same order the
 *      migration's backfill used, so a cycle inserted by a path that never heard of the
 *      column still has the name it would have been given;
 *   4. nothing — and `full()` then says the year alone.
 *
 * The rest of the console still reads `edition_label ?: year` in its own lines (GAPS,
 * Phase 5 entry); the public award pages read this.
 */
final class EditionName
{
    /** "11th Edition", or the operator's label, or '' when neither can be said. */
    public static function label(object|array $cycle): string
    {
        $c = (object) $cycle;
        $own = trim((string) ($c->edition_label ?? ''));
        if ($own !== '') return $own;

        $n = self::number($c);
        return $n > 0 ? Translator::t('%ord% Edition', ['%ord%' => self::ordinal($n)]) : '';
    }

    /** "11th Edition · 2026" — the label and the year, never the year twice. */
    public static function full(object|array $cycle): string
    {
        $c = (object) $cycle;
        $label = self::label($c);
        $year  = (string) ($c->year ?? '');
        if ($label === '') return $year;
        if ($year === '' || str_contains($label, $year)) return $label;
        return $label . ' · ' . $year;
    }

    /** The edition's number, stored or derived; 0 when the cycle cannot be placed. */
    public static function number(object|array $cycle): int
    {
        $c = (object) $cycle;
        if (isset($c->edition_number) && (int) $c->edition_number > 0) return (int) $c->edition_number;
        $id = (int) ($c->id ?? 0);
        $pid = (int) ($c->programme_id ?? 0);
        if ($id < 1 || $pid < 1) return 0;
        try {
            $year = (int) ($c->year ?? DB::table('gates_award_cycles')->where('id', $id)->value('year'));
            return (int) DB::table('gates_award_cycles')->where('programme_id', $pid)
                ->where(fn ($q) => $q->where('year', '<', $year)
                    ->orWhere(fn ($q2) => $q2->where('year', $year)->where('id', '<=', $id)))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** English ordinal: 1st 2nd 3rd 4th … 11th 12th 13th … 21st. */
    public static function ordinal(int $n): string
    {
        $mod100 = $n % 100;
        $suffix = ($mod100 >= 11 && $mod100 <= 13) ? 'th'
            : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $suffix;
    }
}
