<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\NationsLive;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Everything the homepage states, counted — Phase 4 of the redesign (HomePageV3.dc.html).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE READER FOR THE FRONT DOOR, AND NOTHING ON IT TYPED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The design draws the homepage over demo figures: "214 editions open for nominations",
 * "58 votes happening now", "41 countries taking part", "1.2M verified voters", a
 * commendation from a "National Honours Office", three voting awards with days left and a
 * campaign "₦6.8M raised of ₦10M". Every one of them is a placeholder, and the handoff says
 * so (REFERENCE §2: "All data is fake; replace it with real queries"). This class is where
 * each one is replaced, so the page has one place to ask and a test has one place to hold.
 * It is {@see StatsService}'s and {@see GlobeBand}'s rule — a homepage number nobody can
 * check is the costliest kind — applied to the whole page.
 *
 * Where the design assumes a thing this platform does not record, it is NOT approximated:
 *
 *  · "verified voters" — there is no count of verified PEOPLE across an edition that a
 *    distinct count can produce honestly (three of the five vote minters write a
 *    randomised synthetic hash; CLAUDE.md, "Money buys the tally"). The fourth figure is
 *    therefore the one the platform has always published, votes cast: SUM(weight).
 *  · Recognitions from verified issuers (GAPS §3.1) and the wall of testimonials (§3.5) do
 *    not exist. The hero's record card is therefore a real DECIDED AWARD — the latest
 *    published winner — and never an invented commendation; the sections that need the
 *    missing model are not drawn at all (docs/handoff/PHASE-4.md, "Home", blocked).
 *
 * ── THE SANDBOX NEVER REACHES IT ────────────────────────────────────────────
 *
 * Containment is by the chain, as everywhere public: cycles and categories are reached
 * only through ACTIVE programmes (DemoSeeder parks the rehearsal under `is_active = 0`),
 * results through {@see PublicResults} (which excludes the sandbox itself), and the one
 * reader that starts at a ledger row — the votes total — goes through
 * {@see DemoSeeder::liveAwardOnly()}, because a SUM over `gates_votes` never walks to a
 * programme on its own and the rehearsal mints real votes.
 *
 * ── PHASES ARE COMPUTED, NEVER READ OFF THE STATUS COLUMN ───────────────────
 *
 * "Open for nominations" and "voting now" come from {@see CyclePolicy::stateFor()}, the
 * same computation the search palette's "Open now" uses ({@see SearchLanding}), so the
 * palette and the front page cannot disagree about which awards are open. The stored
 * status is a cache a sweep updates; a window that opened an hour ago is open.
 *
 * Every read is wrapped: a table missing on an older install empties its section rather
 * than taking the front page down — and an empty section is not drawn (§9.4: never an
 * empty view, and never a placeholder in its place).
 */
final class HomeFront
{
    /** Rows in "Voting open" and in "Just decided" (HomePageV3.dc.html draws three of each). */
    public const ROWS = 3;

    /** @var array<string,mixed> */
    private static array $memo = [];

    /**
     * The current cycle of every active programme, with its computed state — one pass that
     * the four stats and the voting list both read.
     *
     * @return list<array{programme:object,state:array<string,mixed>,categories:int}>
     */
    public static function currentCycles(?Carbon $now = null): array
    {
        if (isset(self::$memo['cycles'])) return self::$memo['cycles'];
        $now = $now ?? Carbon::now();

        try {
            $rows = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)
                ->where('c.status', '!=', 'archived')
                ->orderBy('p.sort_order')->orderBy('p.id')
                ->orderByDesc('c.year')->orderByDesc('c.id')
                ->get(['c.id', 'c.year', 'c.edition_label', 'c.status', 'c.nominations_open',
                       'c.nominations_close', 'c.voting_open', 'c.voting_close', 'c.results_date',
                       'p.id as programme_id', 'p.slug', 'p.title', 'p.cover_path'])
                ->all();
        } catch (\Throwable) {
            return self::$memo['cycles'] = [];
        }

        $current = [];
        foreach ($rows as $r) {
            // One per programme: its newest cycle is its current one, and an older cycle
            // that still reads "open" on stale dates is not news (SearchLanding's rule).
            if (isset($current[(int) $r->programme_id])) continue;
            $current[(int) $r->programme_id] = $r;
        }

        // Category counts in one grouped query, not one per row.
        $counts = [];
        $ids = array_map(static fn (object $r): int => (int) $r->id, array_values($current));
        if ($ids !== []) {
            try {
                foreach (DB::table('gates_award_categories')->whereIn('cycle_id', $ids)
                    ->selectRaw('cycle_id, COUNT(*) AS n')->groupBy('cycle_id')->get() as $c) {
                    $counts[(int) $c->cycle_id] = (int) $c->n;
                }
            } catch (\Throwable) {
                $counts = [];
            }
        }

        $out = [];
        foreach ($current as $r) {
            $out[] = [
                'programme'  => $r,
                'state'      => CyclePolicy::stateFor($r, $now),
                'categories' => $counts[(int) $r->id] ?? 0,
            ];
        }

        return self::$memo['cycles'] = $out;
    }

    /**
     * The four figures under the hero.
     *
     * @return array{nominations_open:int,voting_open:int,nations:int,votes:int}
     */
    public static function stats(): array
    {
        if (isset(self::$memo['stats'])) return self::$memo['stats'];

        $nominations = $voting = 0;
        foreach (self::currentCycles() as $c) {
            if ($c['state']['is_nominations_open']) $nominations++;
            if ($c['state']['is_voting_open'])      $voting++;
        }

        try {
            // SUM(weight), never COUNT(*): one row is a vote EVENT and a pack carries its
            // whole quantity in `weight` (StatsService has the history). Through the one
            // sandbox clause, because this is the only figure here that starts at a ledger.
            $q = DB::table('gates_votes');
            DemoSeeder::liveAwardOnly($q, 'gates_votes.nominee_id');
            $votes = (int) ($q->sum('weight') ?? 0);
        } catch (\Throwable) {
            $votes = 0;
        }

        return self::$memo['stats'] = [
            'nominations_open' => $nominations,
            'voting_open'      => $voting,
            // The footer's "live in …" sentence and the globe count the same thing.
            'nations'          => NationsLive::count(),
            'votes'            => $votes,
        ];
    }

    /**
     * "Voting open": awards whose current cycle is taking votes, soonest to close first.
     *
     * @return array{total:int,rows:list<array{title:string,url:string,meta:string,left:string,cover:string}>}
     */
    public static function voting(?Carbon $now = null): array
    {
        $open = array_values(array_filter(self::currentCycles($now),
            static fn (array $c): bool => (bool) $c['state']['is_voting_open']));

        // Closing soonest first; an open window with no close date sorts last.
        usort($open, static fn (array $a, array $b): int
            => ($a['state']['seconds_left'] ?? PHP_INT_MAX) <=> ($b['state']['seconds_left'] ?? PHP_INT_MAX));

        $rows = [];
        foreach (array_slice($open, 0, self::ROWS) as $c) {
            $p = $c['programme'];
            $edition = trim((string) ($p->edition_label ?? '')) !== ''
                ? (string) $p->edition_label : (string) ($p->year ?? '');
            $parts = array_filter([
                $edition,
                $c['categories'] > 0
                    ? Translator::t($c['categories'] === 1 ? '%n% category' : '%n% categories',
                        ['%n%' => number_format($c['categories'])])
                    : '',
            ], static fn ($s) => $s !== '');

            $rows[] = [
                'title' => (string) $p->title,
                'ini'   => self::initials((string) $p->title),
                'url'   => '/vote/' . $p->slug,
                'meta'  => implode(' · ', $parts),
                'left'  => self::left($c['state']['seconds_left'] ?? null),
                'cover' => (string) ($p->cover_path ?? ''),
            ];
        }

        return ['total' => count($open), 'rows' => $rows];
    }

    /**
     * The deadline in the design's words — "2 days left" — or nothing where the window has
     * no close date. Never "0 days left": under a day it says so.
     */
    public static function left(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) return '';
        $days = intdiv($seconds, 86400);
        if ($days >= 2)  return Translator::t('%n% days left', ['%n%' => (string) $days]);
        if ($days === 1) return Translator::t('1 day left');

        return Translator::t('Closes today');
    }

    /**
     * "Just decided": the newest published winners. Only what {@see PublicResults} would
     * publish — released, sealed where sealed, never a held award, never the sandbox.
     *
     * @return list<array{name:string,photo:string,what:string,url:string,category:string,programme:string,edition:string}>
     */
    public static function decided(): array
    {
        if (isset(self::$memo['decided'])) return self::$memo['decided'];

        try {
            $items = PublicResults::index(self::ROWS)['items'];
        } catch (\Throwable) {
            $items = [];
        }

        $out = [];
        foreach ($items as $c) {
            $w = $c['winner'] ?? null;
            if (!is_array($w) || trim((string) ($w['name'] ?? '')) === '') continue;
            $category = (string) ($c['category']->title ?? '');
            $out[] = [
                'name'      => (string) $w['name'],
                'ini'       => self::initials((string) $w['name']),
                'photo'     => (string) ($w['photo'] ?? ''),
                'category'  => $category,
                'programme' => (string) ($c['programme'] ?? ''),
                'edition'   => (string) ($c['edition'] ?? ''),
                'what'      => trim($category . ' · ' . trim(($c['programme'] ?? '') . ' ' . ($c['edition'] ?? '')), ' ·'),
                'url'       => (string) ($c['url'] ?? '/results'),
            ];
            if (count($out) >= self::ROWS) break;
        }

        return self::$memo['decided'] = $out;
    }

    /**
     * The one campaign the Giving section features (§8.1 "1 featured campaign").
     *
     * There is no "featured" flag in the data model, so the choice is a rule stated here
     * rather than a pick nobody can see: the open appeal, from an organisation that can
     * receive money right now, that CLOSES SOONEST — the one a gift helps most this week.
     * Raised is {@see OrgCampaign::progress()}, the one summer: confirmed rows, refunds out,
     * the donor's tip to the platform out.
     *
     * @return array<string,mixed>|null
     */
    public static function campaign(): ?array
    {
        if (array_key_exists('campaign', self::$memo)) return self::$memo['campaign'];

        $orgs = [];
        foreach (PartnerOrg::listReceivable() as $o) $orgs[(int) $o->id] = $o;
        if ($orgs === []) return self::$memo['campaign'] = null;

        try {
            $rows = DB::table('gates_org_campaigns')
                ->whereIn('org_id', array_keys($orgs))
                ->where('status', OrgCampaign::STATUS_LIVE)
                ->orderByRaw('CASE WHEN closes_on IS NULL THEN 1 ELSE 0 END')
                ->orderBy('closes_on')->orderBy('id')
                ->limit(20)->get()->all();
        } catch (\Throwable) {
            return self::$memo['campaign'] = null;
        }

        foreach ($rows as $c) {
            if (!OrgCampaign::isOpen($c)) continue;
            $org  = $orgs[(int) $c->org_id];
            $p    = OrgCampaign::progress((int) $c->id);
            $logo = (string) (OrgBrand::of($org)['logo'] ?? '');

            return self::$memo['campaign'] = [
                'title'  => (string) $c->title,
                'org'    => (string) ($org->name ?? ''),
                'ini'    => self::initials((string) ($org->name ?? '')),
                'url'    => '/giving/' . $org->slug . '/' . $c->slug,
                'raised' => '₦' . number_format($p['raised']),
                'goal'   => $p['target'] > 0 ? '₦' . number_format($p['target']) : '',
                'pct'    => $p['pct'],
                // Re-validated on the way OUT (OrgBrand's rule): a stored path is trusted
                // only as far as safePath() still accepts it today.
                'logo'   => OrgBrand::safePath($logo) ? '/' . $logo : '',
            ];
        }

        return self::$memo['campaign'] = null;
    }

    /**
     * What an absent photo shows (GAPS Q8, unanswered): up to two initials, as the search
     * palette's rows do (Phase 2). Multibyte-safe — a name may begin with "Ọ" or "É".
     */
    public static function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w === '' || !preg_match('/\p{L}|\p{N}/u', $w, $m)) continue;
            $out .= mb_strtoupper($m[0]);
            if (mb_strlen($out) >= 2) break;
        }

        return $out;
    }

    /** Reset the memo. Called between tests; there is nothing to reset in a request. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
