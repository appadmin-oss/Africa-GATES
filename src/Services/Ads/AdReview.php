<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A person checks every ad before it runs, as Meta does, and again after it is changed.
 *
 * The automatic part is AdRules: it refuses what can be refused on the words alone (a link to
 * a ballot, an offer tied to voting) and FLAGS what needs judgement (an ad that mentions an
 * award, an advertiser who is a nominee). The flags are shown to the reviewer beside the ad;
 * they never decide on their own, because "award-winning jollof" is a restaurant.
 *
 * A refusal needs a reason, and the advertiser is shown it, so they can fix the ad and send
 * it again rather than guess.
 */
final class AdReview
{
    /** @return list<array<string,mixed>> ads waiting for a person, oldest first */
    public static function queue(): array
    {
        if (!AdCampaigns::ready()) return [];
        try {
            $rows = DB::table('gates_ads as a')->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')
                ->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('a.review', 'pending')->where('c.status', '!=', AdCampaigns::ST_CANCELLED)
                ->orderBy('a.id')->limit(200)
                ->select('a.*', 'c.advertiser', 'c.trial', 'c.user_id', 's.placements')->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn ($r) => [
            'id' => (int) $r->id, 'advertiser' => (string) $r->advertiser, 'trial' => (int) $r->trial === 1,
            'headline' => (string) $r->headline, 'body' => (string) $r->body, 'url' => (string) $r->url,
            'cta' => AdRules::CTAS[(string) $r->cta] ?? '', 'image' => $r->image_path ? (string) $r->image_path : null,
            'offer' => $r->offer ? (string) $r->offer : null, 'offer_code' => $r->offer_code ? (string) $r->offer_code : null,
            'flags' => array_values(array_filter(explode(' · ', (string) ($r->review_flags ?? '')))),
            'placements' => array_map(static fn ($p) => AdRules::PLACEMENTS[$p] ?? $p, array_filter(explode(',', (string) $r->placements))),
            'created_at' => (string) $r->created_at,
        ], $rows);
    }

    /**
     * A reviewer's decision on one ad. Approving the first ad of a trial starts its seven
     * days: the window begins when it can run, not while it waits.
     *
     * @return array{ok:bool, message?:string}
     */
    public static function decide(int $adId, int $adminId, bool $approve, string $reason = ''): array
    {
        if (!AdCampaigns::ready()) return ['ok' => false, 'message' => 'Ads are not available.'];
        $reason = trim($reason);
        if (!$approve && $reason === '') return ['ok' => false, 'message' => 'Say why, so the advertiser can fix it.'];
        $now = date('Y-m-d H:i:s');

        $done = DB::table('gates_ads')->where('id', $adId)->where('review', 'pending')->update([
            'review' => $approve ? 'approved' : 'rejected',
            'review_reason' => $approve ? null : mb_substr($reason, 0, 300),
            'reviewed_by' => $adminId ?: null, 'reviewed_at' => $now, 'updated_at' => $now,
        ]);
        if ($done < 1) return ['ok' => false, 'message' => 'That ad is not waiting for review.'];

        if ($approve) {
            $set = DB::table('gates_ad_sets as s')->join('gates_ads as a', 'a.ad_set_id', '=', 's.id')
                ->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('a.id', $adId)->select('s.id', 's.starts_at', 'c.trial')->first();
            if ($set && (int) $set->trial === 1 && $set->starts_at === null) {
                DB::table('gates_ad_sets')->where('id', $set->id)->whereNull('starts_at')->update([
                    'starts_at' => $now,
                    'ends_at'   => date('Y-m-d H:i:s', strtotime($now) + AdRules::TRIAL_DAYS * 86400),
                    'updated_at' => $now,
                ]);
            }
        }
        return ['ok' => true];
    }
}
