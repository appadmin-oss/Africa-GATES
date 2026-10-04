<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What the search palette shows before anybody has typed — REFERENCE §7.1, "the empty
 * query shows trending, open-now and coming-soon".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONLY WHAT THIS PLATFORM CAN ACTUALLY MEASURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The design draws three groups — "Trending people", "Open now", "Coming up" — over
 * invented rows. Two of the three have a real signal behind them here, and those are the
 * two this class answers:
 *
 *  · OPEN NOW — award programmes whose current cycle is taking nominations or votes. The
 *    phase is COMPUTED by {@see CyclePolicy::phaseFor()} from the cycle's own windows, never
 *    read off `gates_award_cycles.status`, which is a cache (CyclePhase's docblock): a
 *    cycle whose voting window opened an hour ago and whose sweep has not run yet is open,
 *    and saying otherwise sends somebody away from a ballot that is taking votes.
 *  · COMING UP — published events that have not happened yet, soonest first.
 *
 * TRENDING HAS NO MEASURED SIGNAL ON THIS PLATFORM (GAPS §3.6), so there is no trending
 * group — not an approximation of one. "Most votes" is a ranking, and putting it under a
 * word that means "rising now" would be the globe band's invented-city fault again with a
 * more plausible label. Recorded as a deviation for the owner (docs/handoff/PHASE-2.md);
 * the day a rate-of-change is recorded somewhere, it becomes a third method here. And
 * "coming soon" AWARDS do not exist yet (GAPS §3.3) — the "Coming up" group is events,
 * which is what the design's own row in that group is.
 *
 * ── THE SANDBOX NEVER REACHES IT ────────────────────────────────────────────
 *
 * The rehearsal programme lives under `is_active = 0` (DemoSeeder), and the open-now query
 * reaches only ACTIVE programmes, so containment is by the chain rather than by a filter
 * somebody has to remember.
 *
 * Every read is wrapped: a missing table on an older install empties its group rather than
 * taking down the one control on every page that lets somebody find anything.
 */
final class SearchLanding
{
    /** At most this many rows per group — the palette's ≤6 (REFERENCE §7.1). */
    public const PER_GROUP = 6;

    /**
     * Every active programme's CURRENT cycle, with its computed phase — the one read
     * behind both the palette's "Open now" and Discover's awards section (Phase 4), so the
     * two cannot disagree about which awards are open.
     *
     * @return list<array{programme_id:int, slug:string, title:string, cover:string,
     *               edition:string, year:int, phase:CyclePhase, status:string, nominations_close:string,
     *               voting_close:string, results_date:string, cycle_id:int}>
     */
    public static function currentCycles(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        try {
            $rows = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)
                ->where('c.status', '!=', 'archived')
                // The programmes in the operator's own order; within one, newest first,
                // so the first row met for a programme is its current cycle.
                ->orderBy('p.sort_order')->orderBy('p.id')
                ->orderByDesc('c.year')->orderByDesc('c.id')
                ->limit(240)
                ->get(['c.id', 'c.year', 'c.edition_label', 'c.status', 'c.nominations_open',
                       'c.nominations_close', 'c.voting_open', 'c.voting_close', 'c.results_date',
                       'p.id as programme_id', 'p.slug', 'p.title', 'p.cover_path'])
                ->all();
        } catch (\Throwable) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ($rows as $r) {
            // One row per programme: its newest cycle is its current one, and an older
            // cycle that still reads "open" on stale dates is not news.
            if (isset($seen[(int) $r->programme_id])) continue;
            $seen[(int) $r->programme_id] = true;

            $out[] = [
                'cycle_id'          => (int) $r->id,
                // The STORED status, for the one question a computed phase must not answer:
                // whether a result was announced (PublicResults::RELEASED).
                'status'            => (string) ($r->status ?? ''),
                'programme_id'      => (int) $r->programme_id,
                'slug'              => (string) $r->slug,
                'title'             => (string) $r->title,
                'cover'             => (string) ($r->cover_path ?? ''),
                'edition'           => trim((string) ($r->edition_label ?? '')) !== ''
                    ? (string) $r->edition_label : (string) ($r->year ?? ''),
                'year'              => (int) ($r->year ?? 0),
                'phase'             => CyclePolicy::phaseFor($r, $now),
                'nominations_close' => (string) ($r->nominations_close ?? ''),
                'voting_close'      => (string) ($r->voting_close ?? ''),
                'results_date'      => (string) ($r->results_date ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Programmes whose current cycle is open for nominations or votes.
     *
     * @return list<array{kind:string,title:string,detail:string,url:string}>
     */
    public static function openNow(?Carbon $now = null): array
    {
        $out = [];
        foreach (self::currentCycles($now) as $c) {
            $phase = $c['phase'];
            if (!$phase->isVotingOpen() && !$phase->isNominationsOpen()) continue;

            $out[] = [
                'kind'   => 'award',
                'title'  => $c['title'],
                'detail' => trim(\AfricaGates\Support\Translator::t($phase->label()) . ' · ' . $c['edition'], ' ·'),
                'url'    => '/awards/' . $c['slug'],
            ];
            if (count($out) >= self::PER_GROUP) break;
        }

        return $out;
    }

    /**
     * Published events that have not started yet, soonest first — the one read behind
     * the palette's "Coming up" and Discover's "Upcoming ceremonies" (Phase 4).
     *
     * @return list<array{slug:string,title:string,location:string,at:string}>
     */
    public static function upcomingEvents(?Carbon $now = null, int $limit = self::PER_GROUP): array
    {
        $now = $now ?? Carbon::now();
        try {
            $rows = DB::table('gates_site_events')
                ->where('status', 'published')
                ->where('event_date', '>=', $now->toDateTimeString())
                ->orderBy('event_date')
                ->limit(max(1, $limit))
                ->get(['slug', 'title', 'location', 'event_date'])
                ->all();
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (object $r): array => [
            'slug'     => (string) $r->slug,
            'title'    => (string) $r->title,
            'location' => (string) ($r->location ?? ''),
            'at'       => (string) $r->event_date,
        ], $rows);
    }

    /**
     * Published events that have not started yet, soonest first.
     *
     * @return list<array{kind:string,title:string,detail:string,url:string}>
     */
    public static function comingUp(?Carbon $now = null): array
    {
        return array_map(static function (array $r): array {
            try {
                $when = Carbon::parse($r['at'])->format('j M');
            } catch (\Throwable) {
                $when = '';
            }

            return [
                'kind'   => 'event',
                'title'  => $r['title'],
                'detail' => trim($when . ' · ' . $r['location'], ' ·'),
                'url'    => '/events/' . $r['slug'],
            ];
        }, self::upcomingEvents($now));
    }
}
