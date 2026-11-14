<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Support\Env;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * How many people have seen a page, shown on the page itself (owner, 11 Nov 2026, by voice
 * note: "one of the fastest ways to get people to promote their business online is to have
 * the numbers of views shown … 10,000 views … on each of the pages … put an icon there …
 * where you click it you can see promote your business, promote your event"; then
 * "research and make it smart", "UX-optimized").
 *
 * It is the move every feed made: TikTok puts Promote beside a video's views, Instagram its
 * Boost beside a post's insights, Facebook is testing view counts on posts. A number on the
 * page is the proof of an audience that a rate card can only assert, and the icon beside it
 * is where a business that sees the number can act on it (partials/views-badge.twig).
 *
 * ── WHAT COUNTS AS A VIEW: AN ENGAGED ONE, AS THE PLATFORMS DECIDE IT ───────────────
 *
 * YouTube counts a view only after somebody has actually watched for a while, and holds the
 * number while it filters invalid ones; an ad is "viewable" only after a second on screen.
 * The same idea here, sized for a page:
 *
 *   · ENGAGED: the page has been visible in a foreground tab for ENGAGED_SECONDS (views.js
 *     sends one beacon after that). A bounce, a tab opened and never looked at, a hover
 *     prefetch — none of them run that long, so none of them count. With no script nothing
 *     is counted: the number understates, it never overstates.
 *   · A PERSON, ONCE A DAY: a person's browser (VisitTracker::isPerson — no crawler, link
 *     preview or script), who has not said no (CookiePrefs, the one resolver for "may we count this
 *     person"), who is not our own staff, counted once per page per day by the same
 *     daily-salted visitor hash VisitTracker counts visits by. Nothing that identifies
 *     anybody is kept, and the day's hashes are pruned after it.
 *   · A CEILING PER CONNECTION: at most NET_CAP people per page per day from one network.
 *     High enough for a Nigerian mobile network, which puts thousands of phones behind one
 *     address; far too low for a script rotating its browser string to move a number.
 *   · A SIGNED PAGE: the beacon carries a token minted when the page was drawn (kind, id,
 *     day, signature), so nothing can create counts for pages that do not exist.
 *
 * ── WHERE IT IS NEVER SHOWN ─────────────────────────────────────────────────────────
 *
 * Not on a ballot, a nominee's page or a result (D15, the rule ads follow): a view count
 * beside a nominee is a popularity signal during a vote. Not below SHOW_MIN either (a
 * setting): "3 views" on a new page advertises the opposite of an audience.
 *
 * ── STAFF SEE IT ALWAYS ─────────────────────────────────────────────────────────────
 * Owner, 11 Nov 2026: "I do not see the view feature". Every page starts at nought, staff are
 * never counted, and the threshold was 100 — so on a new deployment the feature was present,
 * counting, and invisible to the one person checking it had shipped. Somebody signed in as
 * staff now sees the count at any number, marked as theirs alone ({@see staffOnly()}), and
 * the default threshold is 10.
 */
final class PageViews
{
    /** Below this a page's count is not shown (settable: views_show_min). */
    public const SHOW_MIN = 10;
    /** Seconds a page must be visible before it counts as a view. */
    public const ENGAGED_SECONDS = 5;
    /** People counted per page per day from one network, at most. */
    public const NET_CAP = 40;
    /** "Trending": this week at least this many times last week, and at least TREND_MIN. */
    public const TREND_RATIO = 1.5;
    public const TREND_MIN = 50;

    /** The kinds of page that may carry a count. Never a nominee, a ballot or a result. */
    /**
     * The pages that carry a count. Shop items, challenges and award pages joined events,
     * stories and discussions on 11 Nov 2026 (owner: "not just ads") — the voice note asked for
     * counts "across the site". Still never a nominee's page, a ballot or a result.
     */
    public const KINDS = ['event', 'post', 'thread', 'product', 'challenge', 'award'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_page_views') && SchemaHas::table('gates_page_view_days') && SchemaHas::table('gates_page_view_seen');
    }

    /**
     * What a page passes its template: the beacon's token (always, so a page below the
     * threshold still gathers its views) and the badge (only once there is one to show).
     *
     * @param array{event?:array{title:string,slug:string}} $promote what "promote this" may offer
     * @return array{token:string, after:int, badge:?array<string,mixed>}|null
     */
    public static function forPage(string $kind, int $id, array $promote = []): ?array
    {
        if (!in_array($kind, self::KINDS, true) || $id < 1 || !self::ready()) return null;
        return ['token' => self::token($kind, $id), 'after' => self::ENGAGED_SECONDS, 'badge' => self::badge($kind, $id, $promote)];
    }

    /** kind.id.yyyymmdd.signature — good for the day it was drawn and the next. */
    public static function token(string $kind, int $id, ?string $day = null): string
    {
        $payload = $kind . '.' . $id . '.' . ($day ?? date('Ymd'));
        return $payload . '.' . self::sign($payload);
    }

    /** @return array{kind:string, id:int}|null */
    public static function read(string $token): ?array
    {
        if (preg_match('/^([a-z]{2,12})\.(\d{1,12})\.(\d{8})\.([a-f0-9]{32})$/', $token, $m) !== 1) return null;
        if (!hash_equals(self::sign($m[1] . '.' . $m[2] . '.' . $m[3]), $m[4]) || !in_array($m[1], self::KINDS, true)) return null;
        // Drawn today or yesterday: a page left open over midnight still counts.
        if (!in_array($m[3], [date('Ymd'), date('Ymd', time() - 86400)], true)) return null;
        return ['kind' => $m[1], 'id' => (int) $m[2]];
    }

    private static function sign(string $payload): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') $key = 'ag-pv-fallback|' . (string) Env::get('DB_NAME', 'africa_gates');
        return substr(hash_hmac('sha256', 'pv|' . $payload, $key), 0, 32);
    }

    /**
     * The beacon: an engaged view of the page the token names, counted if it is one by the
     * rules above. True when it moved the number. Never throws.
     */
    public static function engaged(Request $req, string $token): bool
    {
        $t = self::read($token);
        if ($t === null || !self::ready() || !self::counts($req)) return false;
        $who = VisitTracker::visitorHash($req);
        $net = VisitTracker::networkHash($req) ?? '';
        if ($who === null) return false;
        $key = $t['kind'] . ':' . $t['id'];
        $day = date('Y-m-d');
        try {
            if ($net !== '' && DB::table('gates_page_view_seen')->where('page_key', $key)->where('day', $day)->where('net', $net)->count() >= self::NET_CAP) return false;
            $new = DB::table('gates_page_view_seen')->insertOrIgnore(['page_key' => $key, 'day' => $day, 'who' => $who, 'net' => $net]) > 0;
            if (!$new) return false;
            $now = date('Y-m-d H:i:s');
            DB::table('gates_page_views')->insertOrIgnore(['page_key' => $key, 'views' => 0, 'updated_at' => $now]);
            DB::table('gates_page_views')->where('page_key', $key)->update(['views' => DB::raw('views + 1'), 'updated_at' => $now]);
            DB::table('gates_page_view_days')->insertOrIgnore(['page_key' => $key, 'day' => $day, 'views' => 0]);
            DB::table('gates_page_view_days')->where('page_key', $key)->where('day', $day)->update(['views' => DB::raw('views + 1')]);
            // Yesterday's "who" is no use to anybody and must not be kept: a cheap sweep,
            // now and then, rather than a task somebody has to remember to schedule.
            if (random_int(1, 50) === 1) DB::table('gates_page_view_seen')->where('day', '<', date('Y-m-d', time() - 86400))->delete();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Is this request a person looking at the page? */
    public static function counts(Request $req): bool
    {
        if (!empty($_SESSION['admin_id'])) return false;
        // The beacon is a fetch from the page's own script, never a navigation: a person's
        // browser (VisitTracker::isPerson), not isReader(), which would refuse every one.
        if (!VisitTracker::isPerson($req)) return false;
        if (\AfricaGates\Support\Speculative::is($req)) return false;
        return CookiePrefs::allows($req, CookiePrefs::ANALYTICS);
    }

    /** The threshold below which no count is shown. */
    public static function showMin(): int
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'views_show_min')->value('value');
        } catch (\Throwable) {
            $v = null;
        }
        return $v === null || $v === '' ? self::SHOW_MIN : max(1, (int) $v);
    }

    /**
     * A page's figures: all time, the last seven days, the seven before.
     *
     * @return array{total:int, week:int, prev:int}
     */
    public static function stats(string $kind, int $id): array
    {
        $key = $kind . ':' . $id;
        try {
            $total = (int) (DB::table('gates_page_views')->where('page_key', $key)->value('views') ?? 0);
            $week = (int) DB::table('gates_page_view_days')->where('page_key', $key)->where('day', '>', date('Y-m-d', time() - 7 * 86400))->sum('views');
            $prev = (int) DB::table('gates_page_view_days')->where('page_key', $key)->where('day', '>', date('Y-m-d', time() - 14 * 86400))
                ->where('day', '<=', date('Y-m-d', time() - 7 * 86400))->sum('views');
        } catch (\Throwable) {
            return ['total' => 0, 'week' => 0, 'prev' => 0];
        }
        return ['total' => $total, 'week' => $week, 'prev' => $prev];
    }

    /**
     * What the badge draws, or null when there is nothing to show yet.
     *
     * @param array{event?:array{title:string,slug:string}} $promote
     * @return array<string,mixed>|null
     */
    public static function badge(string $kind, int $id, array $promote = []): ?array
    {
        $s = self::stats($kind, $id);
        $staffOnly = self::staffOnly($s['total']);
        if ($s['total'] < self::showMin() && !$staffOnly) return null;
        $trending = $s['week'] >= self::TREND_MIN && $s['week'] >= self::TREND_RATIO * max(1, $s['prev']);
        $event = null;
        if (isset($promote['event']['slug'])) {
            $event = ['title' => (string) ($promote['event']['title'] ?? ''),
                      'href' => '/account/ads/new?' . http_build_query(['for' => 'event', 'slug' => (string) $promote['event']['slug']])];
        }
        // The audience an ad here would reach, in the market's own measured figures: the
        // number on the chip is THIS page; the offer is to the site's readers.
        $reach = AdMarket::reach();
        $prices = array_values(array_filter(array_map(static fn ($c) => $c['price'], AdMarket::card())));
        // This week against last, as a whole percentage — only when last week HAD views: "+∞%"
        // or "+4,700%" off a page that did not exist a week ago is not a trend, it is a birthday.
        $change = $s['prev'] >= 10 ? (int) round(($s['week'] - $s['prev']) * 100 / $s['prev']) : null;
        return ['total' => $s['total'], 'week' => $s['week'], 'trending' => $trending,
                'staff_only' => $staffOnly, 'min' => self::showMin(),
                'change' => $change, 'spark' => self::spark(self::series($kind, $id, 14)),
                'short' => self::short($s['total']), 'full' => number_format($s['total']), 'week_full' => number_format($s['week']),
                'daily' => $reach['daily'] ?? null, 'from' => $prices !== [] ? min($prices) : null, 'event' => $event];
    }

    /**
     * Views per day for the last `$days` days, oldest first, a nought for a day with none —
     * a gap is a day nobody stayed, and the line has to say so rather than join over it.
     *
     * @return list<int>
     */
    public static function series(string $kind, int $id, int $days = 14): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) $out[date('Y-m-d', time() - $i * 86400)] = 0;
        try {
            $rows = DB::table('gates_page_view_days')->where('page_key', $kind . ':' . $id)
                ->where('day', '>=', array_key_first($out))->pluck('views', 'day');
        } catch (\Throwable) {
            return array_values($out);
        }
        foreach ($rows as $day => $n) if (isset($out[(string) $day])) $out[(string) $day] = (int) $n;
        return array_values($out);
    }

    /**
     * A sparkline for the panel: the line and the area under it, in a 140×40 box, drawn by the
     * server so it costs no script. Null when fewer than two days carry a view — a flat line
     * on the floor reads as "nobody", which is the opposite of why the panel is open.
     *
     * @param list<int> $series
     * @return array{line:string, area:string, peak:int}|null
     */
    public static function spark(array $series): ?array
    {
        if (count(array_filter($series)) < 2) return null;
        $max = max($series);
        $n = count($series);
        $pts = [];
        foreach ($series as $i => $v) {
            $x = round($i * 140 / max(1, $n - 1), 1);
            $y = round(38 - ($v / $max) * 34, 1);
            $pts[] = $x . ',' . $y;
        }
        $line = implode(' ', $pts);
        return ['line' => $line, 'area' => 'M0,40 L' . str_replace(' ', ' L', $line) . ' L140,40 Z', 'peak' => $max];
    }

    /**
     * Is this a count only the person looking may see — staff, below the threshold? Staff are
     * never counted, so the number they are shown is everybody else's.
     */
    public static function staffOnly(int $views): bool
    {
        return $views < self::showMin() && (int) ($_SESSION['admin_id'] ?? 0) > 0;
    }

    /** 940 · 1.2k · 10k · 1.5m — the way every feed writes a count. Never rounded up. */
    public static function short(int $n): string
    {
        if ($n < 1000) return (string) $n;
        [$d, $u] = $n < 1_000_000 ? [1000, 'k'] : [1_000_000, 'm'];
        $v = $n / $d;
        return ($v < 10 ? rtrim(rtrim(number_format(floor($v * 10) / 10, 1), '0'), '.') : (string) (int) floor($v)) . $u;
    }
}
