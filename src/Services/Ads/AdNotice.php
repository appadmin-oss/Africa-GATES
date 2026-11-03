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

    /**
     * The receipt for a paid campaign, with its VAT as its own line: what an advertiser's
     * accountant needs, sent when the gateway confirms rather than when they press pay.
     */
    public static function receipt(int $campaignId): void
    {
        try {
            $c = DB::table('gates_ad_campaigns as c')->join('gates_users as u', 'u.id', '=', 'c.user_id')
                ->join('gates_ad_sets as s', 's.campaign_id', '=', 'c.id')->where('c.id', $campaignId)
                ->select('c.*', 'u.email', 's.budget_kobo', 's.days')->first();
            if (!$c || !filter_var((string) $c->email, FILTER_VALIDATE_EMAIL)) return;
            $pct = rtrim(rtrim(number_format((int) $c->vat_bps / 100, 2), '0'), '.');
            $body = 'Receipt for your campaign "' . $c->name . '" (' . $c->advertiser . ").\n\n"
                . 'Ad budget: ' . AdRateCard::format((int) $c->budget_kobo) . "\n"
                . 'VAT at ' . $pct . '%: ' . AdRateCard::format((int) $c->vat_kobo) . "\n"
                . 'Paid: ' . AdRateCard::format((int) $c->paid_kobo) . "\n"
                . 'Reference: ' . $c->payment_ref . "\n\n"
                . 'It runs for ' . (int) $c->days . ' days from when a person approves it. You pay only for views '
                . 'delivered; what it does not spend comes back to you, with its VAT, when it ends.'
                . "\n\n" . SiteUrl::base() . '/account/ads/' . $campaignId;
            \AfricaGates\Services\Notifier::confirm(OtpService::boot(), (string) $c->email, 'Receipt: ' . $c->name, $body);
        } catch (\Throwable $e) {
            error_log('[ads] receipt: ' . $e->getMessage());
        }
    }

    /** Money going back: said in naira, once, when the refund is made. */
    public static function refunded(int $userId, string $campaign, int $naira): void
    {
        try {
            $to = (string) (DB::table('gates_users')->where('id', $userId)->value('email') ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
            \AfricaGates\Services\Notifier::confirm(OtpService::boot(), $to, 'Unspent budget returned: ' . $campaign,
                'Your campaign "' . $campaign . '" has ended. ₦' . number_format($naira) . ' it did not spend, with its VAT, '
                . 'is on its way back to the card or account you paid with. Banks usually show it within 5 to 10 working days.');
        } catch (\Throwable $e) {
            error_log('[ads] refund notice: ' . $e->getMessage());
        }
    }
}
