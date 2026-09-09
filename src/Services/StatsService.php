<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Real, DB-derived site statistics — replaces the hardcoded marketing numbers
 * (the fake "1,247 profiles", "24 categories", "seven editions") with honest
 * counts. Cached (and tag-invalidated on votes/registrations) when a
 * CacheService is supplied.
 */
class StatsService
{
    public function __construct(private readonly ?CacheService $cache = null) {}

    /** @return array{total_profiles:int,total_votes:int,nations_live:int,legacy_events:int,categories:int} */
    public function summary(): array
    {
        $compute = static fn(): array => [
            'total_profiles' => (int) DB::table('gates_profiles')->where('status', 'approved')->count(),
            // SUM(weight), NOT COUNT(*).
            //
            // A row in gates_votes is a vote EVENT, not a vote: a paid or bonus pack
            // writes ONE row carrying its whole quantity in `weight` (PaidVoteService
            // ::mint, BonusVoteService). Counting rows reported a 25-vote pack as 1, so
            // "Votes cast" on the front page was really a count of transactions — and it
            // contradicted every nominee card on the site, which are built from those
            // same weights. The more paid activity, the further apart the two drifted.
            //
            // `?? 0` because SUM over an empty table is NULL and a new site must show 0.
            'total_votes'    => (int) (DB::table('gates_votes')->sum('weight') ?? 0),
            // ── ONE RESOLVER, AND THIS WAS THE SECOND ONE ────────────────────
            //
            // This counted distinct `country_code` over approved PROFILES, while the
            // footer, the meta description and the JSON-LD all print
            // `NationsLive::phrase()` — which counts nations with an approved nominee
            // standing in a LIVE award, and says in as many words why a registered
            // profile is not the platform operating in a country.
            //
            // So the homepage could print "12 nations live" beside a footer reading
            // "live in Nigeria", from the same page load. Anybody may register from
            // anywhere; the directory figure was the larger one and the wrong one.
            'nations_live'   => \AfricaGates\Support\NationsLive::count(),
            'legacy_events'  => (int) DB::table('gates_legacy_events')->where('is_published', 1)->count(),
            'categories'     => (int) DB::table('gates_award_categories')->count(),
        ];

        return $this->cache
            ? $this->cache->remember('site:stats', 600, $compute, ['leaderboard', 'registry'])
            : $compute();
    }
}
