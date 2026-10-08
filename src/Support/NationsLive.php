<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Which nations Africa GATES is actually live in, counted from the awards themselves.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT USED TO BE A SENTENCE SOMEBODY TYPED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Live in Nigeria, building toward 54 nations" was written into the page description, the
 * JSON-LD, the footer and the terms — with `nations_count` (an admin setting, defaulting to
 * 54) standing in for the second half and the word **Nigeria** hardcoded for the first.
 * `GuideService` had the whole sentence typed out again, 54 included, so the one place that
 * did not read the setting was the one that explains the platform to people.
 *
 * The claim it makes is checkable and it goes stale in the direction that matters: the day
 * a Ghanaian nominee stands in a live award, the site is live in two nations and still says
 * one. Nobody edits a meta description because a nomination came in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT MAKES A NATION "LIVE"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Somebody from it is standing in a live award — an APPROVED nominee, in a category of a
 * cycle of an ACTIVE programme. Not a registered profile: anybody can register from
 * anywhere, and a directory entry is not the platform operating in a country. Not a vote
 * either, for the same reason — voting is open worldwide by design, and counting voter
 * countries would claim presence in places GATES has never run anything.
 *
 * The active-programme join is also what keeps the SANDBOX out, and it does it without a
 * filter anybody has to remember. {@see \AfricaGates\Services\DemoSeeder} contains the demo
 * in its own programme with `is_active = 0`, precisely so public readers exclude it by
 * reaching only for live programmes. A `country_code` filter would have been the kind of
 * "wherever it matters" rule that gets missed once.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT RETURNS NAMES AS WELL AS A NUMBER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because "live in 1 nations" is not a sentence, and "live in 1 nation" is worse than the
 * hardcoded copy it replaces — at one nation the honest and warmer thing to write is the
 * country's name, which is what the page said before this was automatic. So the phrase
 * names one, names two, and counts from three.
 */
final class NationsLive
{
    /**
     * The nations this platform has ever run an award in.
     *
     * Keyed to {@see Regions::MAP}, which is the list the nomination and registration forms
     * already offer — so a country that can be chosen has a name here, and one that cannot
     * is never going to appear in the answer. Six templates carry their own inline copy of
     * this map; they should read this one, and until they do, this is the copy that decides
     * what the site SAYS about itself.
     *
     * @var array<string,string>
     */
    public const NAMES = [
        'NG' => 'Nigeria',       'GH' => 'Ghana',        'ZA' => 'South Africa',
        'KE' => 'Kenya',         'ET' => 'Ethiopia',     'EG' => 'Egypt',
        'MA' => 'Morocco',       'TZ' => 'Tanzania',     'UG' => 'Uganda',
        'CI' => "Côte d'Ivoire", 'CM' => 'Cameroon',     'SN' => 'Senegal',
        'ML' => 'Mali',          'RW' => 'Rwanda',       'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',      'BF' => 'Burkina Faso', 'NE' => 'Niger',
        'BW' => 'Botswana',      'AO' => 'Angola',       'CD' => 'DR Congo',
    ];

    /**
     * The country codes with a nominee standing in a live award, alphabetical by NAME.
     *
     * Alphabetical by name rather than by code so "Côte d'Ivoire and Cameroon" cannot come
     * out in an order that looks arbitrary to the person reading it.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        try {
            $rows = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('p.is_active', 1)
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->whereNotNull('n.country_code')
                ->where('n.country_code', '!=', '')
                ->distinct()->pluck('n.country_code')->all();
        } catch (\Throwable) {
            // A deployment mid-migration must not take the footer down with it. Empty here
            // means the phrase falls back to the platform's home nation, which is the
            // sentence the site carried before any of this was computed.
            return [];
        }

        $codes = [];
        foreach ($rows as $r) {
            $cc = strtoupper(trim((string) $r));
            if ($cc !== '' && !in_array($cc, $codes, true)) $codes[] = $cc;
        }

        usort($codes, static fn (string $a, string $b): int => strcmp(self::name($a), self::name($b)));

        return $codes;
    }

    public static function count(): int
    {
        return count(self::codes());
    }

    /** A country's name, or its code where the map does not know it — never an empty label. */
    public static function name(string $code): string
    {
        $cc = strtoupper(trim($code));

        return self::NAMES[$cc] ?? $cc;
    }

    /**
     * The phrase that goes into the copy: a name, two names, or a count.
     *
     * `$fallback` is what to say when nothing is live yet — a brand-new deployment, or a
     * database that could not be read. It defaults to the platform's home nation because
     * that is what every one of these sentences said before it was computed, and a footer
     * reading "live in 0 nations" on a slow morning is worse than one that is a day stale.
     */
    public static function phrase(string $fallback = 'Nigeria'): string
    {
        $codes = self::codes();

        return match (count($codes)) {
            0       => $fallback,
            1       => self::name($codes[0]),
            2       => self::name($codes[0]) . ' and ' . self::name($codes[1]),
            default => count($codes) . ' nations',
        };
    }
}
