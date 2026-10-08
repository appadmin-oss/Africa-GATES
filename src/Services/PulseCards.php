<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT KIND OF POST IS THIS? — the five shapes Pulse draws (Phase 8 §8.9, PulsePage.dc.html).
 *
 * The DC's feed has five kinds — post, photo, recognition, vote, give — and the record has
 * one: a thread, with an optional attachment and an optional result announcement
 * (PulseFeedService). So the kind is DERIVED from facts the row already carries, never
 * stored and never picked by whoever posted:
 *
 *   recognition  the post announces a published result (PulseFeedService's `result`
 *                payload — released, sealed where sealed). `gates_recognitions` does not
 *                exist (GAPS §3.1), so a recognition here is an award decided on this
 *                platform, and the card says so.
 *   photo        it carries an image or a video.
 *   vote         it is posted in an award's channel while that award's PUBLIC VOTING IS
 *                OPEN — computed from the cycle (CyclePolicy, through the programme list),
 *                never a status column. The card's clock is the award's own.
 *   give         its text links a giving campaign on this site that is open now; the
 *                progress is OrgCampaign::progress(), the one summer (refunds out).
 *   post         anything else.
 *
 * Derived on read, so a card can never outlive its reason: an award whose voting closed
 * stops drawing "Vote", and a campaign that closed stops asking for money.
 */
final class PulseCards
{
    /**
     * @param list<array<string,mixed>> $items PulseFeedService::page() items
     * @param list<array<string,mixed>> $programmes AwardService::getActiveProgrammesWithStatus()
     * @return list<array<string,mixed>> the same items, each with `card` and its payload
     */
    public static function decorate(array $items, array $programmes): array
    {
        $byId = [];
        foreach ($programmes as $p) $byId[(int) $p['id']] = $p;

        foreach ($items as &$it) {
            $it['card'] = 'post';
            if (!empty($it['result'])) { $it['card'] = 'recognition'; continue; }
            if (!empty($it['media']))  { $it['card'] = 'photo'; continue; }

            $p = $byId[(int) ($it['programme_id'] ?? 0)] ?? null;
            if ($p && !empty($p['phase']['is_voting_open'])) {
                $secs = (int) ($p['phase']['seconds_left'] ?? 0);
                $it['card'] = 'vote';
                $it['vote'] = [
                    'award' => (string) $p['title'],
                    'url'   => '/vote/' . $p['slug'],
                    'days'  => $secs > 0 ? intdiv($secs, 86400) : null,
                ];
                continue;
            }

            $give = self::campaignIn((string) ($it['body'] ?? ''));
            if ($give !== null) { $it['card'] = 'give'; $it['give'] = $give; }
        }
        unset($it);

        return $items;
    }

    /** The first open campaign a post's text links to, with its progress — or null. */
    public static function campaignIn(string $text): ?array
    {
        if (!preg_match('~/giving/([a-z0-9-]{1,120})/([a-z0-9-]{1,160})~i', $text, $m)) return null;
        try {
            $org = DB::table('gates_partner_orgs')->where('slug', strtolower($m[1]))->first();
            if (!$org) return null;
            $c = OrgCampaign::bySlug((int) $org->id, strtolower($m[2]));
            if (!$c || !OrgCampaign::isOpen($c)) return null;
            $p = OrgCampaign::progress((int) $c->id);
        } catch (\Throwable) {
            return null;
        }
        return [
            'title'  => (string) $c->title,
            'url'    => '/giving/' . $org->slug . '/' . $c->slug,
            'raised' => (int) ($p['raised'] ?? 0),
            'target' => (int) ($p['target'] ?? 0),
            'pct'    => (int) ($p['pct'] ?? 0),
        ];
    }
}
