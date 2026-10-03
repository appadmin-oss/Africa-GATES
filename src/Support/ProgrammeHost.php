<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHO RUNS AN AWARD, RESOLVED ONCE.
 *
 * The October handoff asks for "Hosted by Okun Alimosho" beside a logo on two different
 * surfaces — the Alimosho Awards pages and the challenge scoped to them. Two surfaces is
 * exactly the number at which this codebase has repeatedly ended up with two answers to
 * one question: the schedule screen and `InterviewService` disagreeing about a status,
 * `StatsService` and `NationsLive` disagreeing about how many nations are live, the
 * release screen and `ResultRelease` disagreeing about a maximum. So it is one function.
 *
 * ── A CHALLENGE INHERITS ITS HOST THROUGH THE SCOPE CHAIN ────────────────────
 *
 * A challenge is scoped to an award cycle or a category, and both reach a programme.
 * Copying the host onto `gates_challenges` would be the second copy, and it is the one
 * nobody updates: by the time a host changes its name the challenge is over and its page
 * is still up.
 *
 * ── AND IT IS GATED ON `is_active`, FOR THE REASON EVERY READER HERE IS ──────
 *
 * The sandbox lives in an inactive programme, and every reader that walks the category
 * chain is safe without knowing the sandbox exists. This walks that chain, so it carries
 * the same gate rather than inventing a second containment.
 */
final class ProgrammeHost
{
    /**
     * @return array{name:string,logo:string,url:string}|null null whenever no host is
     *         named — which is most programmes. A continental award is hosted by Africa
     *         GATES, and a line saying so on an Africa GATES page is noise.
     */
    public static function forProgramme(int $programmeId): ?array
    {
        if ($programmeId <= 0) {
            return null;
        }

        try {
            $row = DB::table('gates_award_programmes')
                ->where('id', $programmeId)->where('is_active', 1)
                ->first(['host_name', 'host_logo_path', 'host_url']);
        } catch (\Throwable $e) {
            // The columns arrive in 2027_02_14_programme_host.php. A database that has
            // not run it yet has no host rather than an error page — this is decoration
            // on somebody else's screen, and `SchemaHas` is not used because the whole
            // query is the thing that fails, not one clause of it.
            return null;
        }

        return self::shape($row);
    }

    /** @return array{name:string,logo:string,url:string}|null */
    public static function forCycle(int $cycleId): ?array
    {
        if ($cycleId <= 0) {
            return null;
        }

        try {
            $row = DB::table('gates_award_cycles as cy')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('cy.id', $cycleId)->where('p.is_active', 1)
                ->first(['p.host_name', 'p.host_logo_path', 'p.host_url']);
        } catch (\Throwable $e) {
            return null;
        }

        return self::shape($row);
    }

    /**
     * The host of whatever a challenge is scoped to.
     *
     * The FIRST one found, and the order is cycle before category because a challenge
     * scoped to both is scoped to the cycle those categories are in — the same answer by
     * a shorter route. A challenge spanning two programmes with two different hosts has
     * no single host to name, and naming the first would be a guess printed as a fact; it
     * takes the first because that case does not occur today, and the day it does the
     * line reads as one host among several rather than wrongly.
     *
     * @param list<int> $cycleIds
     * @param list<int> $categoryIds
     * @return array{name:string,logo:string,url:string}|null
     */
    public static function forScopes(array $cycleIds, array $categoryIds): ?array
    {
        foreach ($cycleIds as $id) {
            if ($h = self::forCycle((int) $id)) {
                return $h;
            }
        }

        foreach ($categoryIds as $id) {
            try {
                $cycleId = (int) DB::table('gates_award_categories')
                    ->where('id', (int) $id)->value('cycle_id');
            } catch (\Throwable $e) {
                continue;
            }

            if ($h = self::forCycle($cycleId)) {
                return $h;
            }
        }

        return null;
    }

    /** @return array{name:string,logo:string,url:string}|null */
    private static function shape(?object $row): ?array
    {
        $name = trim((string) ($row->host_name ?? ''));

        // The NAME decides. A logo with no name is an unexplained picture, and the line
        // this feeds reads "Hosted by <name>" with the logo beside it — so a row carrying
        // only a logo renders nothing rather than a bare image nobody can attribute.
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'logo' => trim((string) ($row->host_logo_path ?? '')),
            'url'  => trim((string) ($row->host_url ?? '')),
        ];
    }
}
