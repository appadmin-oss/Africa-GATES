<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayThreshold;
use AfricaGates\Support\GivingUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE SHARE VIDEO'S STORY — one record, four beats (handoff 16 Oct 2026, "Share video").
 *
 * A 9:16, ~18.5s clip a nominee, an organiser or a supporter posts to a status or a reel:
 * Hook (the photo and one line) → Story (the photo becomes a card, the title, the verified
 * organiser) → Proof (the giving ring, the votes, the ticket price) → Action (the end card
 * and its button). It is drawn in the visitor's own browser (`share-video.js`): this host
 * has no headless browser, and a canvas recorded by MediaRecorder is a real video file.
 *
 * Everything here is the record's own data. The DC's figures ("1,412 gifts", "Voting
 * closes 15 Oct") are design content. LOW-DATA-RULES apply to the proof beat exactly as
 * they do on the page the video points at: a figure below its threshold is not drawn,
 * and with no figure left the proof beat becomes more story instead of a "0".
 *
 * Copy: the DC's award button said "Vote free"; voting here is paid by default, so it
 * says "Vote".
 */
final class ShareVideo
{
    /** @return array<string,mixed>|null */
    public static function forNominee(?array $f): ?array
    {
        if ($f === null) return null;
        $votes = (int) ($f['votes'] ?? 0);
        return [
            'kind'   => 'award',
            'kicker' => 'Vote for them',
            'hook'   => trim((string) $f['name']) . ' for ' . trim((string) $f['category']) . '.',
            'chip'   => 'Award · ' . trim((string) $f['programme']),
            'title'  => (string) $f['category'],
            'name'   => (string) $f['name'],
            'org'    => (string) $f['programme'],
            'orgType'=> 'Verified issuer',
            'cta'    => 'Vote',
            'sub'    => trim((string) ($f['tagline'] ?? '')),
            'url'    => (string) $f['url'],
            'photo'  => (string) ($f['photo'] ?? ''),
            'proof'  => DisplayThreshold::met('votes_public_total', $votes)
                ? ['type' => 'votes', 'votes' => $votes] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function forCampaign(string $orgSlug, string $campaignSlug, string $base): ?array
    {
        $org = PartnerOrg::bySlug($orgSlug);
        if (!$org || !PartnerOrg::canReceive($org)) return null;
        $c = OrgCampaign::bySlug((int) $org->id, $campaignSlug);
        if (!$c || !OrgCampaign::isOpen($c)) return null;

        $p    = OrgCampaign::progress((int) $c->id);
        $days = OrgCampaign::daysLeft($c);
        $proof = null;
        if ($p['target'] > 0 && DisplayThreshold::met('campaign_pct_ring', $p['pct'])) {
            $proof = ['type' => 'giving', 'pct' => min(100, (int) $p['pct']),
                      'raised' => '₦' . number_format($p['raised']), 'goal' => '₦' . number_format($p['target']),
                      'gifts' => DisplayThreshold::met('gifts_count', $p['count']) ? (int) $p['count'] : null,
                      'days' => $days !== null && $days <= DisplayThreshold::min('days_left_show') ? $days : null];
        }
        $summary = trim((string) ($c->summary ?? ''));
        return [
            'kind'   => 'giving',
            'kicker' => 'Help fund it',
            'hook'   => $summary !== '' ? self::sentence($summary) : (string) $c->title,
            'chip'   => 'Giving · ' . (string) $org->name,
            'title'  => (string) $c->title,
            'name'   => '',
            'org'    => (string) $org->name,
            'orgType'=> 'Verified organiser',
            'cta'    => 'Give now',
            'sub'    => 'Every gift is receipted',
            'url'    => rtrim($base, '/') . GivingUrl::org($orgSlug, $campaignSlug),
            'photo'  => '',
            'proof'  => $proof,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function forEvent(string $slug, string $base): ?array
    {
        $e = DB::table('gates_site_events')->where('slug', $slug)->where('status', 'published')->first();
        if (!$e) return null;
        $from = null;
        try {
            $prices = array_column(EventTicketService::tiers((int) $e->id), 'price_naira');
            if ($prices !== []) $from = min($prices);
        } catch (\Throwable) {}
        $when  = $e->event_date ? date('D j M', strtotime((string) $e->event_date)) : '';
        $where = trim((string) ($e->venue ?: $e->location ?: ''));
        $cover = (string) ($e->cover_image ?? '');
        return [
            'kind'   => 'event',
            'kicker' => 'You’re invited',
            'hook'   => trim((string) ($e->tagline ?? '')) !== '' ? self::sentence((string) $e->tagline) : (string) $e->title,
            'chip'   => 'Event' . (trim((string) $e->location) !== '' ? ' · ' . trim((string) $e->location) : ''),
            'title'  => (string) $e->title,
            'name'   => '',
            'org'    => 'Africa GATES',
            'orgType'=> 'Verified organiser',
            'cta'    => 'Get tickets',
            'sub'    => trim($when . ($when !== '' && $where !== '' ? ' · ' : '') . $where),
            'url'    => rtrim($base, '/') . '/events/' . $e->slug,
            'photo'  => preg_match('#^(/|https://)#', $cover) ? $cover : '',
            'proof'  => $from !== null ? ['type' => 'event', 'from' => $from === 0 ? 'Free' : '₦' . number_format($from)] : null,
        ];
    }

    /** The first sentence, at most 90 characters: a hook is one line on a phone. */
    private static function sentence(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (preg_match('/^(.{12,90}?[.!?])(\s|$)/u', $t, $m)) return $m[1];
        return mb_strlen($t) > 90 ? rtrim(mb_substr($t, 0, 88)) . '…' : $t;
    }
}
