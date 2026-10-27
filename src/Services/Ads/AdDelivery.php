<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Support\Env;
use AfricaGates\Support\Media;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Which ad a page shows, and counting what it delivered.
 *
 * ── A VIEW IS COUNTED BY THE BROWSER, NOT BY THE SERVER ─────────────────────
 *
 * Drawing an ad into a page is not a view: the reader may never scroll to it, and a crawler
 * that passes every header check still renders nothing. A view is counted when at least half
 * the ad has been on screen for a second (the industry's viewable-impression rule, which is
 * also what Meta bills), reported by ads.js. An advertiser pays per 1,000 views, so the
 * difference is their money.
 *
 * Every drawn ad carries a TOKEN, signed and single-use: the view and the click each count
 * once per token (UNIQUE in gates_ad_events), so a script replaying a beacon a thousand times
 * buys nobody a thousand views, and nothing about the reader is stored to make that work.
 *
 * ── WHO GETS SHOWN WHAT ─────────────────────────────────────────────────────
 *
 * By the page, the country the CDN reports (never looked up), and nothing else. No profile,
 * no history, no third party: so a reader who refused cookies sees the same ads as one who
 * did not, and the cookie page stays true. Each reader sees one ad at most FREQUENCY_CAP
 * times a day, counted in their session.
 *
 * ── PACING ──────────────────────────────────────────────────────────────────
 *
 * A campaign's views (trial) or budget (paid) is spread evenly over the days it has left, so
 * a week's budget is not spent by Monday lunchtime. Of the campaigns with room today, the one
 * furthest behind its day's share is shown first: on a rate card that is the fair order.
 */
final class AdDelivery
{
    private const SESSION_FREQ = '_ag_ad_freq';
    private const EVENT_DAYS   = 3;

    public static function ready(): bool
    {
        return AdCampaigns::ready() && SchemaHas::table('gates_ad_stats') && SchemaHas::table('gates_ad_events');
    }

    /**
     * The ad for one placement, or null. Counts the slot toward the placement's inventory
     * when the request is a reader (so the builder's estimate is made of readers too).
     *
     * @param array{country?:?string, reader?:bool, signed_in?:bool} $ctx
     * @return array<string,mixed>|null
     */
    public static function pick(string $placement, array $ctx = []): ?array
    {
        if (!isset(AdRules::PLACEMENTS[$placement]) || !self::ready()) return null;
        if (!($ctx['reader'] ?? false)) return null;
        $day = date('Y-m-d');
        self::countSlot($placement, $day);

        try {
            $now  = date('Y-m-d H:i:s');
            $sets = DB::table('gates_ad_sets as s')->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('c.status', AdCampaigns::ST_SUBMITTED)
                ->where(static fn ($q) => $q->where('c.trial', 1)->orWhereNotNull('c.paid_at'))
                ->whereNotNull('s.starts_at')->where('s.starts_at', '<=', $now)
                ->where(static fn ($q) => $q->whereNull('s.ends_at')->orWhere('s.ends_at', '>=', $now))
                ->select('s.*', 'c.user_id', 'c.advertiser', 'c.trial')->limit(200)->get()->all();
        } catch (\Throwable) {
            return null;
        }

        $country = strtoupper((string) ($ctx['country'] ?? ''));
        $best = null;
        foreach ($sets as $s) {
            if (!in_array($placement, explode(',', (string) $s->placements), true)) continue;
            $countries = array_filter(explode(',', (string) $s->countries));
            // Aimed at countries and we do not know where this reader is: not shown, rather
            // than shown to somebody the advertiser did not pay to reach.
            if ($countries !== [] && !in_array($country, $countries, true)) continue;
            if ($placement === 'vote_hub' && AdRules::inOpenAward((int) $s->user_id)) continue;
            $room = self::roomToday($s, $day);
            if ($room === null) continue;
            $ad = self::rotate((int) $s->id, $day);
            if ($ad === null || self::seenToday((int) $ad->id, $day) >= AdRules::FREQUENCY_CAP) continue;
            if ($best === null || $room < $best['room'] || ($room === $best['room'] && random_int(0, 1) === 1)) {
                $best = ['room' => $room, 'set' => $s, 'ad' => $ad];
            }
        }
        if ($best === null) return null;

        $ad = $best['ad'];
        self::noteSeen((int) $ad->id, $day);
        $token = self::mint((int) $ad->id, $placement, $day);
        $countries = array_filter(explode(',', (string) $best['set']->countries));
        return [
            'id' => (int) $ad->id, 'placement' => $placement, 'advertiser' => (string) $best['set']->advertiser,
            'headline' => (string) $ad->headline, 'body' => (string) $ad->body,
            'cta' => AdRules::CTAS[(string) $ad->cta] ?? 'Learn more',
            'image' => Media::url($ad->image_path ? (string) $ad->image_path : null, 'thumb'),
            'offer' => $ad->offer ? (string) $ad->offer : null, 'offer_code' => $ad->offer_code ? (string) $ad->offer_code : null,
            'href' => '/ads/go/' . $token, 'token' => $token, 'external' => !str_starts_with((string) $ad->url, '/'),
            'by_country' => $countries !== [],
        ];
    }

    /**
     * The ad for a placement on this request: a reader by the arrivals report's own test
     * (VisitTracker::isReader(), so a bot is refused by one definition, not two), in the
     * country the CDN reports.
     *
     * @return array<string,mixed>|null
     */
    public static function forRequest(string $placement, \Psr\Http\Message\ServerRequestInterface $req): ?array
    {
        try {
            $cc = strtoupper(trim($req->getHeaderLine('CF-IPCountry')));
            return self::pick($placement, [
                'reader'  => \AfricaGates\Services\VisitTracker::isReader($req),
                'country' => preg_match('/^[A-Z]{2}$/', $cc) === 1 ? $cc : null,
            ]);
        } catch (\Throwable) {
            return null; // an ad must never be the reason a page does not draw
        }
    }

    /**
     * How far behind its share of today a set is, as a fraction (0 = nothing shown yet today,
     * 1 = today's share used), or null when it has no room today at all.
     */
    private static function roomToday(object $s, string $day): ?float
    {
        $t = AdCampaigns::totals((int) $s->id);
        $daysLeft = 1;
        if ($s->ends_at !== null) {
            $daysLeft = max(1, (int) ceil((strtotime((string) $s->ends_at) - strtotime($day)) / 86400));
        }
        $today = self::todayFor((int) $s->id, $day);

        if ($s->view_cap !== null) {
            $left = (int) $s->view_cap - (int) $t['views'];
            if ($left <= 0) return null;
            $share = (int) ceil(($left + $today['views']) / $daysLeft);
            return $today['views'] >= $share ? null : $today['views'] / max(1, $share);
        }
        if ((int) $s->budget_kobo > 0) {
            $left = (int) $s->budget_kobo - (int) $t['spent_kobo'];
            if ($left <= 0) return null;
            $share = (int) ceil(($left + $today['kobo']) / $daysLeft);
            return $today['kobo'] >= $share ? null : $today['kobo'] / max(1, $share);
        }
        return null; // neither a cap nor a budget: nothing was bought
    }

    /** @return array{views:int, kobo:int} what one set delivered today */
    private static function todayFor(int $setId, string $day): array
    {
        $set = DB::table('gates_ad_sets')->where('id', $setId)->first();
        $rates = json_decode((string) ($set->rates ?? '{}'), true) ?: [];
        $views = $kobo = 0;
        foreach (DB::table('gates_ad_stats as st')->join('gates_ads as a', 'a.id', '=', 'st.ad_id')
                     ->where('a.ad_set_id', $setId)->where('st.day', $day)
                     ->groupBy('st.placement')->selectRaw('st.placement as p, SUM(st.views) as v')->get() as $r) {
            $views += (int) $r->v;
            $kobo  += AdRateCard::cost((int) $r->v, (int) ($rates[(string) $r->p] ?? 0));
        }
        return ['views' => $views, 'kobo' => $kobo];
    }

    /** The set's approved ad with the fewest views today: Meta's ads in a set take turns. */
    private static function rotate(int $setId, string $day): ?object
    {
        $ads = DB::table('gates_ads')->where('ad_set_id', $setId)->where('review', 'approved')->get()->all();
        if ($ads === []) return null;
        $views = [];
        foreach (DB::table('gates_ad_stats')->whereIn('ad_id', array_map(static fn ($a) => (int) $a->id, $ads))
                     ->where('day', $day)->groupBy('ad_id')->selectRaw('ad_id, SUM(views) as v')->get() as $r) {
            $views[(int) $r->ad_id] = (int) $r->v;
        }
        usort($ads, static fn ($a, $b) => ($views[(int) $a->id] ?? 0) <=> ($views[(int) $b->id] ?? 0));
        return $ads[0];
    }

    // ── the frequency cap, in the reader's own session ──────────────────────

    private static function seenToday(int $adId, string $day): int
    {
        $f = $_SESSION[self::SESSION_FREQ] ?? null;
        return is_array($f) && ($f['d'] ?? '') === $day ? (int) ($f['a'][$adId] ?? 0) : 0;
    }

    private static function noteSeen(int $adId, string $day): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) return;
        $f = $_SESSION[self::SESSION_FREQ] ?? null;
        if (!is_array($f) || ($f['d'] ?? '') !== $day) $f = ['d' => $day, 'a' => []];
        $f['a'][$adId] = (int) ($f['a'][$adId] ?? 0) + 1;
        $_SESSION[self::SESSION_FREQ] = $f;
    }

    // ── counting ────────────────────────────────────────────────────────────

    /** The browser says the ad was viewable. True when this counted a view. */
    public static function seen(string $token): bool
    {
        $t = self::read($token);
        return $t !== null && self::once($token, 'v') && self::bump($t['a'], $t['p'], $t['d'], 'views');
    }

    /**
     * A click: counted once per drawn ad, and the destination, re-checked on the way out.
     * A row can outlive the code that validated it; nothing reaches a Location header
     * because it was safe when it was saved.
     */
    public static function click(string $token, bool $reader): ?string
    {
        $t = self::read($token);
        if ($t === null) return null;
        $ad = DB::table('gates_ads')->where('id', $t['a'])->first();
        if (!$ad) return null;
        $url = AdRules::safeUrl((string) $ad->url);
        if ($url === null || AdRules::pointsAtANominee($url)) return '/';
        if ($reader && self::once($token, 'c')) self::bump($t['a'], $t['p'], $t['d'], 'clicks');
        return $url;
    }

    private static function once(string $token, string $kind): bool
    {
        try {
            return DB::table('gates_ad_events')->insertOrIgnore([
                'token' => substr(hash('sha256', $token), 0, 64), 'kind' => $kind, 'created_at' => date('Y-m-d H:i:s'),
            ]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function bump(int $adId, string $placement, string $day, string $col): bool
    {
        try {
            DB::table('gates_ad_stats')->insertOrIgnore(['ad_id' => $adId, 'placement' => $placement, 'day' => $day, 'views' => 0, 'clicks' => 0]);
            DB::table('gates_ad_stats')->where('ad_id', $adId)->where('placement', $placement)->where('day', $day)->increment($col);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function countSlot(string $placement, string $day): void
    {
        if (!SchemaHas::table('gates_ad_inventory')) return;
        try {
            DB::table('gates_ad_inventory')->insertOrIgnore(['placement' => $placement, 'day' => $day, 'slots' => 0]);
            DB::table('gates_ad_inventory')->where('placement', $placement)->where('day', $day)->increment('slots');
        } catch (\Throwable) {}
    }

    /**
     * What the chosen placements have drawn per day for readers, over the last 28 days, and
     * what that comes to over the campaign's days. An estimate, labelled as one: ads share
     * a placement, so a campaign gets a part of it, never more than all of it.
     *
     * @param list<string> $placements
     * @return array{daily:int, total:int, days:int}|null null when there is no history yet
     */
    public static function estimate(array $placements, int $days): ?array
    {
        if ($placements === [] || !SchemaHas::table('gates_ad_inventory')) return null;
        try {
            $from = date('Y-m-d', time() - 28 * 86400);
            $rows = DB::table('gates_ad_inventory')->whereIn('placement', $placements)->where('day', '>=', $from)
                ->where('day', '<', date('Y-m-d'))->groupBy('day')->selectRaw('day, SUM(slots) as n')->get()->all();
        } catch (\Throwable) {
            return null;
        }
        if ($rows === []) return null;
        $daily = (int) round(array_sum(array_map(static fn ($r) => (int) $r->n, $rows)) / max(1, count($rows)));
        return ['daily' => $daily, 'total' => $daily * max(1, $days), 'days' => count($rows)];
    }

    /** Counted-once records older than a view could still arrive. */
    public static function prune(): int
    {
        if (!SchemaHas::table('gates_ad_events')) return 0;
        try {
            return DB::table('gates_ad_events')->where('created_at', '<', date('Y-m-d H:i:s', time() - self::EVENT_DAYS * 86400))->delete();
        } catch (\Throwable) {
            return -1;
        }
    }

    // ── the token ───────────────────────────────────────────────────────────

    public static function mint(int $adId, string $placement, string $day): string
    {
        $payload = $adId . '.' . $placement . '.' . str_replace('-', '', $day) . '.' . bin2hex(random_bytes(6));
        return $payload . '.' . self::sign($payload);
    }

    /** @return array{a:int, p:string, d:string}|null */
    public static function read(string $token): ?array
    {
        if (preg_match('/^(\d{1,12})\.([a-z_]{2,20})\.(\d{8})\.([a-f0-9]{12})\.([a-f0-9]{32})$/', $token, $m) !== 1) return null;
        $payload = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        if (!hash_equals(self::sign($payload), $m[5]) || !isset(AdRules::PLACEMENTS[$m[2]])) return null;
        $day = substr($m[3], 0, 4) . '-' . substr($m[3], 4, 2) . '-' . substr($m[3], 6, 2);
        // A view reported more than a day after the page was drawn is not a view anybody saw.
        if ($day < date('Y-m-d', time() - 86400) || $day > date('Y-m-d')) return null;
        return ['a' => (int) $m[1], 'p' => $m[2], 'd' => $day];
    }

    private static function sign(string $payload): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') $key = 'ag-ads-fallback|' . (string) Env::get('DB_NAME', 'africa_gates');
        return substr(hash_hmac('sha256', 'ad|' . $payload, $key), 0, 32);
    }
}
