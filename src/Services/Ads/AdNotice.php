<?php
declare(strict_types=1);
namespace AfricaGates\Services\Ads;

use AfricaGates\Services\DoorWelcome;
use AfricaGates\Services\Mail\Emails;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Telling the advertiser what a reviewer decided (owner, 28 Oct 2026: "improve the campaign
 * features").
 *
 * A decision used to reach nobody: the ad's state changed in the Ads Manager and the
 * advertiser found out only by opening it. Every large platform emails both answers, and the
 * refusal is the one that matters: until they read the reason, nothing is showing and they
 * do not know why. The member's notifications carry the same decision (AlertService::ads()),
 * derived from the review stamp, so the in-app half needs no row of its own.
 *
 * Never throws and never undoes a decision: the ad's state is the record, and a mail that
 * fails to send leaves the Ads Manager saying exactly the same thing.
 */
final class AdNotice
{
    /** @param 'approved'|'refused'|'taken_down' $decision */
    public static function decided(int $adId, string $decision, ?OtpService $mailer): bool
    {
        if ($mailer === null || !in_array($decision, ['approved', 'refused', 'taken_down'], true)) return false;
        try {
            $r = DB::table('gates_ads as a')
                ->join('gates_ad_sets as s', 's.id', '=', 'a.ad_set_id')
                ->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->join('gates_users as u', 'u.id', '=', 'c.user_id')
                ->where('a.id', $adId)
                ->select('a.headline', 'a.review_reason', 'c.id as cid', 'c.name as campaign', 'c.trial',
                         's.ends_at', 'u.email', 'u.name')
                ->first();
            if (!$r || !filter_var((string) $r->email, FILTER_VALIDATE_EMAIL)) return false;
            $base = SiteUrl::base();
            $out = Emails::send($mailer, (string) $r->email, 'ad-review', [
                'first_name' => DoorWelcome::firstName((string) $r->name) ?: (string) $r->name,
                'decision'   => $decision,
                'campaign'   => (string) $r->campaign,
                'headline'   => (string) $r->headline,
                'reason'     => (string) ($r->review_reason ?? ''),
                'ends_on'    => $decision === 'approved' && (int) $r->trial === 1 && $r->ends_at
                                ? date('j F Y', strtotime((string) $r->ends_at)) : '',
                'manage_url' => $base . '/account/ads/' . (int) $r->cid . ($decision === 'approved' ? '' : '#ad-' . $adId),
                'policy_url' => $base . '/legal/advertising',
                'site_url'   => $base,
            ]);
            return (bool) ($out['success'] ?? $out['ok'] ?? false);
        } catch (\Throwable $e) {
            error_log('[ads] review notice: ' . $e->getMessage());
            return false;
        }
    }
}
