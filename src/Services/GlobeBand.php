<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\NationsLive;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The homepage globe band's data — every marker on it, counted from the awards.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS SERVICE EXISTS AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The band arrived as a design handoff whose script carried sixteen cities with
 * invented figures: Lagos on 41,280 ballots verified in 1.1 seconds, Nairobi on 33,940,
 * arcs drawn between them, and an annotation card stating a "median 1.3 seconds" as a
 * measured fact. None of it was measurable here — this platform has no verification
 * nodes, records no per-ballot latency, and has never had a column for either.
 *
 * A homepage that states a number nobody can check is the {@see StatsService} fault
 * again ("1,247 profiles", "24 categories", "seven editions"), and worse, because these
 * numbers came with an air of instrumentation. So the band is driven from the one thing
 * this platform does know about geography: WHERE THE NOMINEES ARE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT A MARKER MEANS, AND WHY IT IS THE SAME RULE AS THE FOOTER'S
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A marker is a nation with an APPROVED nominee standing in a live award —
 * {@see NationsLive}'s definition, reached by the same joins, so the globe and the
 * footer sentence ("live in Nigeria and Ghana") can never disagree about which nations
 * those are. That definition is also what keeps the sandbox off the homepage without a
 * filter anybody has to remember: {@see DemoSeeder} puts its demo in a programme with
 * `is_active = 0`, and the join reaches only active ones.
 *
 * NOT a registered profile, and NOT a voter's country. Anybody may register or vote from
 * anywhere; neither is the platform operating in a country, and plotting voters would
 * claim presence in places GATES has never run anything.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GEOMETRY MAP IS THE PART THAT FAILS SILENTLY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A marker is positioned by the script from the CENTROID of the country's own polygon in
 * `public/assets/geo/countries-110m.json` — no stored coordinates, nothing anybody typed,
 * so a marker cannot drift from the outline it sits inside. The join between a nominee's
 * `country_code` and that polygon is by NAME, because the script already keys its Africa
 * set, its click hit-test and its country card off `properties.name`.
 *
 * Natural Earth's names are its own, not ISO's: `CD` is "Dem. Rep. Congo" there. A name
 * that does not match produces NO ERROR ANYWHERE — the marker is simply absent from a
 * band that still looks finished, which is this repo's most expensive shape of bug. So
 * {@see self::GEOMETRY} is pinned by `GlobeBandTest` against the shipped geometry file
 * AND against the script's own Africa set, and against `NationsLive::NAMES` so the two
 * lists cannot drift apart when a country is added to the nomination form.
 */
final class GlobeBand
{
    /**
     * Alpha-2 → the name Natural Earth 110m gives that country.
     *
     * Keys are exactly `NationsLive::NAMES`' keys — the countries the nomination form
     * accepts — and the test asserts that identity rather than trusting this comment.
     * Twenty of the twenty-one are the plain English name; `CD` is the one that is not.
     *
     * @var array<string,string>
     */
    public const GEOMETRY = [
        'NG' => 'Nigeria',        'GH' => 'Ghana',         'ZA' => 'South Africa',
        'KE' => 'Kenya',          'ET' => 'Ethiopia',      'EG' => 'Egypt',
        'MA' => 'Morocco',        'TZ' => 'Tanzania',      'UG' => 'Uganda',
        'CI' => "Côte d'Ivoire",  'CM' => 'Cameroon',      'SN' => 'Senegal',
        'ML' => 'Mali',           'RW' => 'Rwanda',        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',       'BF' => 'Burkina Faso',  'NE' => 'Niger',
        'BW' => 'Botswana',       'AO' => 'Angola',        'CD' => 'Dem. Rep. Congo',
    ];

    /** @var array<string,list<array<string,mixed>>> */
    private static array $memo = [];

    /**
     * Every nation with a marker, with the two figures its card shows.
     *
     * `geo` is what the script matches against the polygon; `name` is what a reader is
     * shown, and they differ for `CD` — the card says "DR Congo" because that is what the
     * rest of the site calls it, while the outline it highlights is Natural Earth's.
     *
     * `voted` decides the marker's shape, and it is a fact rather than a flourish: a
     * nation whose nominees have recorded ballots gets the verified marker, one still
     * waiting for its first vote gets the plain dot. A reader hovering either is told
     * which in the label.
     *
     * ORDERED by votes, descending, then by name — so the busiest markers are appended
     * first and sit under nothing when two centroids land close together.
     *
     * @return list<array{code:string,name:string,geo:string,nominees:int,votes:int,voted:bool}>
     */
    public static function countries(): array
    {
        $key = 'countries';
        if (isset(self::$memo[$key])) return self::$memo[$key];

        try {
            $q = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('p.is_active', 1)
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->whereNotNull('n.country_code')
                ->where('n.country_code', '!=', '');

            // A merge tombstone is not a nominee standing anywhere, and its votes have
            // already been reassigned to the survivor — counting both double-counts them.
            MergeService::notMerged($q, 'n.merged_into');

            $rows = $q->selectRaw('n.country_code AS cc, COUNT(*) AS nominees, '
                    . 'COALESCE(SUM(n.vote_count), 0) AS votes')
                ->groupBy('n.country_code')
                ->get();
        } catch (\Throwable) {
            // A deployment mid-migration must not take the homepage down. Empty here draws
            // the globe with no markers, which is also what a brand-new site looks like —
            // and the band says so rather than inventing a marker to fill the space.
            return self::$memo[$key] = [];
        }

        $out = [];
        foreach ($rows as $r) {
            $cc = strtoupper(trim((string) $r->cc));
            // A code with no polygon has no honest position on a globe. It is dropped
            // from the markers and NOT silently absorbed into a neighbour; the count of
            // what was dropped travels in `unplaced()` so a screen can say so.
            if ($cc === '' || !isset(self::GEOMETRY[$cc])) continue;

            $votes = (int) $r->votes;
            $out[] = [
                'code'     => $cc,
                'name'     => NationsLive::name($cc),
                'geo'      => self::GEOMETRY[$cc],
                'nominees' => (int) $r->nominees,
                'votes'    => $votes,
                'voted'    => $votes > 0,
            ];
        }

        usort($out, static fn (array $a, array $b): int
            => $b['votes'] <=> $a['votes'] ?: strcmp($a['name'], $b['name']));

        return self::$memo[$key] = $out;
    }

    /**
     * Nations standing in a live award that this band cannot place on the globe.
     *
     * Zero on every deployment today, because `GEOMETRY` covers every country the
     * nomination form offers, and `GlobeBandTest` holds that identity. It is counted
     * anyway because an operator can set `country_code` directly, and the failure that
     * would follow — a nation live on the platform, absent from the map OF where the
     * platform is live — is invisible from the band itself.
     *
     * READ BY {@see self::note()}, deliberately and on the public page. §20's rule is that
     * a method whose docblock describes the running system and has no caller is that
     * description being false, and a count of a silent omission that nothing states is the
     * purest form of it: it would make the omission twice as quiet.
     */
    public static function unplaced(): int
    {
        $placed = array_column(self::countries(), 'code');
        $n = 0;
        foreach (NationsLive::codes() as $cc) {
            if (!in_array($cc, $placed, true)) $n++;
        }

        return $n;
    }

    /**
     * The annotation card's text.
     *
     * It states what the markers ARE and nothing else. The handoff's line — "every ballot
     * is confirmed at the verification node nearest the voter — median 1.3 seconds" —
     * described infrastructure this platform does not have, in the voice of a measurement.
     */
    public static function note(): string
    {
        $n    = count(self::countries());
        $gone = self::unplaced();

        $note = $n === 0
            ? 'Each marker is a nation with a nominee standing in a live award. No award is '
              . 'open for entries yet, so the map is empty — it fills as nominations are '
              . 'approved.'
            : 'Each marker is a nation with a nominee standing in a live award. Open one for '
              . 'that nation\'s nominees and the votes cast for them — the same figures the '
              . 'award pages carry.';

        // Said out loud rather than left to be noticed. A nation live on the platform and
        // missing from this map is a fault in the map, and the map is the only place it
        // shows; understate and flag it, the same way an unfinished panel is scored.
        if ($gone > 0) {
            $note .= $gone === 1
                ? ' One more nation is standing in a live award and is not drawn here: the '
                  . 'map has no outline for its country.'
                : ' ' . $gone . ' more nations are standing in live awards and are not drawn '
                  . 'here: the map has no outline for their countries.';
        }

        return $note;
    }

    /** Reset the memo. Called between tests; there is nothing to reset in a request. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
