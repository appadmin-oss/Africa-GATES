<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * An advertiser's campaigns: making one, its state, and what it has delivered.
 *
 * ── DELIVERY IS DERIVED, NEVER STORED ───────────────────────────────────────
 *
 * `gates_ad_campaigns.status` is only the advertiser's own switch (submitted, paused,
 * cancelled). Whether a campaign is in review, scheduled, delivering or finished is worked
 * out on every read from its ads' reviews, its window, its cap and its spend (delivery()).
 * A stored "Active" would be true the day it was written and wrong the hour the trial's
 * thousandth view landed, which is the shape this codebase has paid for most often.
 *
 * ── SPEND IS DERIVED TOO ────────────────────────────────────────────────────
 *
 * Views × the rate the set was booked at, per placement, never more than the budget. There
 * is no spend column to drift from the views it is meant to be the price of.
 */
final class AdCampaigns
{
    public const ST_SUBMITTED = 'submitted';
    public const ST_PAUSED    = 'paused';
    public const ST_CANCELLED = 'cancelled';

    /** What the advertiser is told, per derived state. */
    public const DELIVERY = [
        'in_review'    => 'In review',
        'not_approved' => 'Not approved',
        'scheduled'    => 'Scheduled',
        'active'       => 'Active',
        'paused'       => 'Paused',
        'completed'    => 'Completed',
        'cancelled'    => 'Cancelled',
    ];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_ad_campaigns') && SchemaHas::table('gates_ad_sets') && SchemaHas::table('gates_ads');
    }

    /** One trial per account, ever: a cancelled or finished trial has been used. */
    public static function trialUsed(int $userId): bool
    {
        if (!self::ready()) return true;
        try {
            return DB::table('gates_ad_campaigns')->where('user_id', $userId)->where('trial', 1)->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Validate and create a trial campaign: one set, one ad, sent for review.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, id?:int, errors?:array<string,string>}
     */
    public static function createTrial(int $userId, array $in, ?string $imagePath, ?string $logoPath = null): array
    {
        if (!self::ready() || $userId < 1) return ['ok' => false, 'errors' => ['form' => 'Ads are not available right now.']];
        if (self::trialUsed($userId)) return ['ok' => false, 'errors' => ['form' => 'You have used your free trial.']];

        $v = self::validate($in);
        $errors = $v['errors'];
        if ($imagePath === null || $imagePath === '') $errors['image'] ??= 'Add a square image.';
        // Meta and Google both make an advertiser accept the standards before an ad is sent;
        // so does this. The confirmation is stored, not just ticked.
        if (empty($in['standards'])) $errors['standards'] = 'Confirm your ad meets the ad standards.';
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        $now = date('Y-m-d H:i:s');
        try {
            $id = (int) DB::connection()->transaction(static function () use ($userId, $v, $imagePath, $logoPath, $now): int {
                $cid = (int) DB::table('gates_ad_campaigns')->insertGetId($v['campaign'] + [
                    'user_id' => $userId, 'trial' => 1, 'status' => self::ST_SUBMITTED,
                    'logo_path' => $logoPath ?: null, 'standards_at' => $now,
                    'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                // The trial's window starts when a person approves it, not when it is sent:
                // seven days spent waiting in a queue would be seven days of nothing.
                $sid = (int) DB::table('gates_ad_sets')->insertGetId([
                    'campaign_id' => $cid, 'placements' => implode(',', $v['placements']),
                    'budget_kobo' => 0, 'rates' => '{}',
                    'view_cap' => AdRules::TRIAL_VIEWS, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('gates_ads')->insert($v['ad'] + [
                    'ad_set_id' => $sid, 'image_path' => $imagePath, 'review' => 'pending',
                    'review_flags' => implode(' · ', AdRules::flags($v['ad'] + ['_category' => $v['campaign']['category'], 'licence_ref' => $v['campaign']['licence_ref']], $userId)) ?: null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                return $cid;
            });
        } catch (\Throwable) {
            return ['ok' => false, 'errors' => ['form' => 'That did not save. Try again.']];
        }
        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{campaign:array<string,mixed>, placements:list<string>, ad:array<string,mixed>, errors:array<string,string>}
     */
    public static function validate(array $in): array
    {
        $e = [];
        $objective  = (string) ($in['objective'] ?? '');
        $advertiser = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['advertiser'] ?? '')));
        $name       = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        if (!isset(AdRules::OBJECTIVES[$objective])) $e['objective'] = 'Choose what you want.';
        $al = mb_strlen($advertiser);
        if ($al < 2 || $al > AdRules::NAME_MAX) $e['advertiser'] = sprintf('Your business name, 2 to %d characters.', AdRules::NAME_MAX);
        if ($name === '') $name = $advertiser;
        $placements = AdRules::cleanPlacements($in['placements'] ?? []);
        if ($placements === []) $e['placements'] = 'Choose at least one place.';
        $category = (string) ($in['category'] ?? 'general');
        if (!isset(AdRules::CATEGORIES[$category])) $e['category'] = 'Choose what your ad is for.';
        $licence = strtoupper(trim((string) ($in['licence_ref'] ?? '')));
        if ($licence !== '' && preg_match('{^[A-Z0-9/ .-]{3,60}$}', $licence) !== 1) $e['licence_ref'] = 'Letters, numbers and / or - only.';
        // The approvals this kind of ad must carry: enforced once ads leave beta, asked for
        // and flagged until then (AdRules::CATEGORIES, owner 28 Oct 2026).
        if (!AdRules::inBeta() && isset(AdRules::CATEGORIES[$category])) {
            foreach (AdRules::missingApprovals($category, $in) as $k) {
                $e[$k . '_ref'] = sprintf('%s is needed for this kind of ad.', AdRules::APPROVALS[$k][0]);
            }
        }

        $c = AdRules::creative($in);
        return [
            'campaign'   => ['name' => mb_substr($name, 0, 80), 'advertiser' => $advertiser, 'objective' => $objective,
                             'category' => isset(AdRules::CATEGORIES[$category]) ? $category : 'general',
                             'licence_ref' => $licence !== '' ? $licence : null],
            'placements' => $placements,
            'ad'         => $c['row'], 'errors' => $e + $c['errors'],
        ];
    }

    /** The advertiser's own switch: pause, resume, cancel. */
    public static function setStatus(int $id, int $userId, string $to): bool
    {
        $from = match ($to) {
            self::ST_PAUSED    => [self::ST_SUBMITTED],
            self::ST_SUBMITTED => [self::ST_PAUSED],
            self::ST_CANCELLED => [self::ST_SUBMITTED, self::ST_PAUSED],
            default            => [],
        };
        if ($from === [] || !self::ready()) return false;
        try {
            return DB::table('gates_ad_campaigns')->where('id', $id)->where('user_id', $userId)->whereIn('status', $from)
                ->update(['status' => $to, 'updated_at' => date('Y-m-d H:i:s')]
                    + ($to === self::ST_CANCELLED ? ['cancelled_at' => date('Y-m-d H:i:s')] : [])) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fix a refused ad and send it again. Only the advertiser's own, only while it is not
     * running: an approved ad that changes is a different ad nobody has checked.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, errors?:array<string,string>}
     */
    public static function resubmitAd(int $adId, int $userId, array $in, ?string $imagePath): array
    {
        $ad = self::ownAd($adId, $userId);
        if ($ad === null || (string) $ad->review !== 'rejected') return ['ok' => false, 'errors' => ['form' => 'That ad cannot be changed.']];
        $c = AdRules::creative($in);
        $camp = DB::table('gates_ad_campaigns as c')->join('gates_ad_sets as s', 's.campaign_id', '=', 'c.id')
            ->where('s.id', $ad->ad_set_id)->select('c.category', 'c.licence_ref')->first();
        $category = (string) ($camp->category ?? 'general');
        if (!AdRules::inBeta()) {
            foreach (AdRules::missingApprovals($category, $in + ['licence_ref' => $camp->licence_ref ?? '']) as $k) {
                $c['errors'][$k . '_ref'] = sprintf('%s is needed for this kind of ad.', AdRules::APPROVALS[$k][0]);
            }
        }
        if ($c['errors'] !== []) return ['ok' => false, 'errors' => $c['errors']];
        DB::table('gates_ads')->where('id', $adId)->where('review', 'rejected')->update($c['row'] + [
            'image_path' => $imagePath ?: $ad->image_path, 'review' => 'pending', 'review_reason' => null,
            'review_flags' => implode(' · ', AdRules::flags($c['row'] + ['_category' => $category, 'licence_ref' => $camp->licence_ref ?? null], $userId)) ?: null,
            'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true];
    }

    public static function ownAd(int $adId, int $userId): ?object
    {
        try {
            $ad = DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')
                ->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('a.id', $adId)->where('c.user_id', $userId)->select('a.*')->first();
            return $ad ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> the advertiser's campaigns, newest first, each with its results */
    public static function mine(int $userId): array
    {
        if (!self::ready()) return [];
        try {
            $rows = DB::table('gates_ad_campaigns')->where('user_id', $userId)->orderByDesc('id')->limit(100)->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn ($c) => self::summary($c), $rows);
    }

    /** One campaign of the advertiser's, in full, or null. */
    public static function detail(int $id, int $userId): ?array
    {
        if (!self::ready()) return null;
        $c = DB::table('gates_ad_campaigns')->where('id', $id)->where('user_id', $userId)->first();
        if (!$c) return null;
        $out = self::summary($c);
        $set = $out['_set'];
        $ads = $set ? DB::table('gates_ads')->where('ad_set_id', $set->id)->orderBy('id')->get()->all() : [];
        $byAd = self::statsByAd(array_map(static fn ($a) => (int) $a->id, $ads));
        $out['ads'] = array_map(static fn ($a) => [
            'id' => (int) $a->id, 'headline' => (string) $a->headline, 'body' => (string) $a->body,
            'cta' => AdRules::CTAS[(string) $a->cta] ?? 'Learn more', 'cta_key' => (string) $a->cta, 'url' => (string) $a->url,
            'domain' => AdRules::displayDomain((string) $a->url),
            'advertiser' => (string) $c->advertiser, 'logo' => $c->logo_path ? (string) $c->logo_path : null,
            'initials' => mb_strtoupper(implode('', array_slice(\AfricaGates\Support\AvatarMark::letters((string) $c->advertiser), 0, 2))) ?: '?',
            'external' => !str_starts_with((string) $a->url, '/'),
            'image' => $a->image_path ? (string) $a->image_path : null,
            'offer' => $a->offer ? (string) $a->offer : null, 'offer_code' => $a->offer_code ? (string) $a->offer_code : null,
            'review' => (string) $a->review, 'reason' => (string) ($a->review_reason ?? ''),
            'views' => $byAd[(int) $a->id]['views'] ?? 0, 'clicks' => $byAd[(int) $a->id]['clicks'] ?? 0,
        ], $ads);
        $out['days'] = self::daily(array_map(static fn ($a) => (int) $a->id, $ads), 14);
        return $out;
    }

    /**
     * A campaign's state and results, as the manager's table shows them.
     *
     * @return array<string,mixed>
     */
    public static function summary(object $c): array
    {
        $set = DB::table('gates_ad_sets')->where('campaign_id', $c->id)->orderBy('id')->first();
        $reviews = $set ? DB::table('gates_ads')->where('ad_set_id', $set->id)->pluck('review')->all() : [];
        $t = $set ? self::totals((int) $set->id) : ['views' => 0, 'clicks' => 0, 'spent_kobo' => 0, 'by_placement' => []];
        $delivery = self::delivery($c, $set, $reviews, $t);
        $views = (int) $t['views'];
        $clicks = (int) $t['clicks'];
        return [
            'id' => (int) $c->id, 'name' => (string) $c->name, 'advertiser' => (string) $c->advertiser,
            'objective' => (string) $c->objective, 'objective_label' => AdRules::OBJECTIVES[(string) $c->objective] ?? '',
            'trial' => (int) $c->trial === 1, 'status' => (string) $c->status,
            'category' => (string) ($c->category ?? 'general'),
            'category_label' => AdRules::CATEGORIES[(string) ($c->category ?? 'general')][0] ?? '',
            'delivery' => $delivery['key'], 'delivery_label' => self::DELIVERY[$delivery['key']], 'delivery_note' => $delivery['note'],
            'views' => $views, 'clicks' => $clicks,
            'ctr' => $views > 0 ? round(100 * $clicks / $views, 1) : null,
            'spent_kobo' => (int) $t['spent_kobo'], 'budget_kobo' => $set ? (int) $set->budget_kobo : 0,
            'spent_label' => AdRateCard::format((int) $t['spent_kobo']),
            'budget_label' => $set && (int) $set->budget_kobo > 0 ? AdRateCard::format((int) $set->budget_kobo) : null,
            'view_cap' => $set && $set->view_cap !== null ? (int) $set->view_cap : null,
            'cpm_kobo' => $views > 0 && $t['spent_kobo'] > 0 ? intdiv(1000 * (int) $t['spent_kobo'], $views) : null,
            'cpc_kobo' => $clicks > 0 && $t['spent_kobo'] > 0 ? intdiv((int) $t['spent_kobo'], $clicks) : null,
            'starts_at' => $set->starts_at ?? null, 'ends_at' => $set->ends_at ?? null,
            'placements' => $set ? array_values(array_filter(explode(',', (string) $set->placements))) : [],
            'logo' => $c->logo_path ? (string) $c->logo_path : null,
            'by_placement' => $t['by_placement'],
            '_set' => $set,
        ];
    }

    /**
     * The state a campaign is in, worked out from what it is made of.
     *
     * @param list<string> $reviews its ads' review states
     * @param array<string,mixed> $t its totals
     * @return array{key:string, note:string}
     */
    public static function delivery(object $c, ?object $set, array $reviews, array $t, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        if ((string) $c->status === self::ST_CANCELLED) return ['key' => 'cancelled', 'note' => ''];
        if (!$set || $reviews === []) return ['key' => 'in_review', 'note' => ''];
        if (!in_array('approved', $reviews, true)) {
            return in_array('pending', $reviews, true) ? ['key' => 'in_review', 'note' => 'A person checks every ad before it runs.']
                                                      : ['key' => 'not_approved', 'note' => 'Fix the ad and send it again.'];
        }
        $cap = $set->view_cap !== null ? (int) $set->view_cap : null;
        if ($cap !== null && (int) $t['views'] >= $cap) return ['key' => 'completed', 'note' => 'Reached its views.'];
        if ((int) $set->budget_kobo > 0 && (int) $t['spent_kobo'] >= (int) $set->budget_kobo) return ['key' => 'completed', 'note' => 'Budget spent.'];
        if ($set->ends_at !== null && (string) $set->ends_at < $now) return ['key' => 'completed', 'note' => 'End date passed.'];
        if ((string) $c->status === self::ST_PAUSED) return ['key' => 'paused', 'note' => ''];
        if ($set->starts_at === null || (string) $set->starts_at > $now) return ['key' => 'scheduled', 'note' => ''];
        return ['key' => 'active', 'note' => ''];
    }

    /**
     * Views, clicks and spend for one ad set. Spend is views × the rate each placement was
     * booked at, capped at the budget: an advertiser is never charged for a view past it.
     *
     * @return array{views:int, clicks:int, spent_kobo:int, by_placement:array<string,array{views:int,clicks:int}>}
     */
    public static function totals(int $setId): array
    {
        $set = DB::table('gates_ad_sets')->where('id', $setId)->first();
        if (!$set || !SchemaHas::table('gates_ad_stats')) return ['views' => 0, 'clicks' => 0, 'spent_kobo' => 0, 'by_placement' => []];
        $rows = DB::table('gates_ad_stats as st')->join('gates_ads as a', 'a.id', '=', 'st.ad_id')
            ->where('a.ad_set_id', $setId)->groupBy('st.placement')
            ->selectRaw('st.placement as placement, SUM(st.views) as v, SUM(st.clicks) as c')->get()->all();
        $rates = json_decode((string) $set->rates, true) ?: [];
        $views = $clicks = $spent = 0;
        $by = [];
        foreach ($rows as $r) {
            $v = (int) $r->v; $c = (int) $r->c;
            $views += $v; $clicks += $c;
            $spent += AdRateCard::cost($v, (int) ($rates[(string) $r->placement] ?? 0));
            $by[(string) $r->placement] = ['views' => $v, 'clicks' => $c];
        }
        if ((int) $set->budget_kobo > 0) $spent = min($spent, (int) $set->budget_kobo);
        return ['views' => $views, 'clicks' => $clicks, 'spent_kobo' => $spent, 'by_placement' => $by];
    }

    /**
     * The public library: every approved ad on a campaign that is running now, newest first,
     * with who placed it and since when. Only what the ad itself already shows the public:
     * no spend, no figures, nothing about who saw it.
     *
     * @param ?int $fromAdId narrow to that ad's advertiser (by account, so a business name
     *                       spelled two ways is still one advertiser)
     * @return array{advertiser:?string, ads:list<array<string,mixed>>}
     */
    public static function library(?int $fromAdId = null): array
    {
        if (!self::ready()) return ['advertiser' => null, 'ads' => []];
        $now = date('Y-m-d H:i:s');
        try {
            $q = DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')
                ->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('a.review', 'approved')->where('c.status', self::ST_SUBMITTED)
                ->where(static fn ($w) => $w->where('c.trial', 1)->orWhereNotNull('c.paid_at'))
                ->whereNotNull('s.starts_at')->where('s.starts_at', '<=', $now)
                ->where(static fn ($w) => $w->whereNull('s.ends_at')->orWhere('s.ends_at', '>=', $now))
                ->orderByDesc('s.starts_at')->orderByDesc('a.id')->limit(100)
                ->select('a.*', 'c.advertiser', 'c.logo_path', 'c.user_id', 's.starts_at', 's.placements');
            $name = null;
            if ($fromAdId !== null) {
                $owner = DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')
                    ->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                    ->where('a.id', $fromAdId)->select('c.user_id', 'c.advertiser')->first();
                if (!$owner) return ['advertiser' => null, 'ads' => []];
                $q->where('c.user_id', (int) $owner->user_id);
                $name = (string) $owner->advertiser;
            }
            $rows = $q->get()->all();
        } catch (\Throwable) {
            return ['advertiser' => null, 'ads' => []];
        }
        return ['advertiser' => $name, 'ads' => array_map(static fn ($r) => [
            'id' => (int) $r->id, 'advertiser' => (string) $r->advertiser,
            'initials' => mb_strtoupper(implode('', array_slice(\AfricaGates\Support\AvatarMark::letters((string) $r->advertiser), 0, 2))) ?: '?',
            'logo' => $r->logo_path ? (string) $r->logo_path : null,
            'body' => (string) $r->body, 'headline' => (string) $r->headline,
            'image' => $r->image_path ? (string) $r->image_path : null,
            'offer' => $r->offer ? (string) $r->offer : null, 'offer_code' => $r->offer_code ? (string) $r->offer_code : null,
            'cta' => AdRules::CTAS[(string) $r->cta] ?? 'Learn more', 'url' => (string) (AdRules::safeUrl((string) $r->url) ?? '#'),
            'domain' => AdRules::displayDomain((string) $r->url), 'external' => !str_starts_with((string) $r->url, '/'),
            'since' => (string) $r->starts_at,
            'placements' => array_values(array_filter(explode(',', (string) $r->placements))),
        ], $rows)];
    }

    /** @param list<int> $adIds @return array<int,array{views:int,clicks:int}> */
    private static function statsByAd(array $adIds): array
    {
        if ($adIds === [] || !SchemaHas::table('gates_ad_stats')) return [];
        $out = [];
        foreach (DB::table('gates_ad_stats')->whereIn('ad_id', $adIds)->groupBy('ad_id')
                     ->selectRaw('ad_id, SUM(views) as v, SUM(clicks) as c')->get() as $r) {
            $out[(int) $r->ad_id] = ['views' => (int) $r->v, 'clicks' => (int) $r->c];
        }
        return $out;
    }

    /**
     * The last N days, oldest first, every day present (a day with nothing is a zero, not a gap).
     *
     * @param list<int> $adIds
     * @return list<array{day:string, views:int, clicks:int}>
     */
    private static function daily(array $adIds, int $n): array
    {
        $by = [];
        if ($adIds !== [] && SchemaHas::table('gates_ad_stats')) {
            $from = date('Y-m-d', time() - ($n - 1) * 86400);
            foreach (DB::table('gates_ad_stats')->whereIn('ad_id', $adIds)->where('day', '>=', $from)->groupBy('day')
                         ->selectRaw('day, SUM(views) as v, SUM(clicks) as c')->get() as $r) {
                $by[(string) $r->day] = ['views' => (int) $r->v, 'clicks' => (int) $r->c];
            }
        }
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - $i * 86400);
            $out[] = ['day' => $d] + ($by[$d] ?? ['views' => 0, 'clicks' => 0]);
        }
        return $out;
    }
}
