<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Ads posted from the console (owner, 4 Nov 2026: "allow admin be able to post ads").
 *
 * Two kinds, because staff post for two different reasons and the reader is owed the
 * difference:
 *
 *   · SOLD BY US (`direct`) — a business that bought space by talking to us rather than
 *     through the Ads Manager. Labelled "Sponsored" with their name, exactly like a
 *     self-serve ad; its money (budget plus VAT) is recorded when it is posted, under the
 *     advertising stream, and it is served with the paid campaigns. Paid outside the site,
 *     so what it does not spend is returned by a person — the gateway refunds only what the
 *     gateway took (AdCheckout::settle skips these).
 *   · OUR OWN (`house`) — an Africa GATES promotion: an award opening, an event, the shop.
 *     Labelled "From Africa GATES", never "Sponsored", because nobody paid for it; counted
 *     like any ad so staff can see what it did; and served LAST, only into space no paying
 *     campaign and no trial wanted (AdDelivery). Our own notices must never cost an
 *     advertiser a view they paid for.
 *
 * Both are approved by the person posting them — the review exists to put a second pair of
 * eyes on what outsiders write, and the poster is staff — and both carry the same rules as
 * any ad: the creative checks (AdRules::creative: never a ballot, never an offer tied to
 * voting), the label, the library, the reader's hide and report.
 */
final class AdStaff
{
    public const MAX_DAYS = AdRules::MAX_DAYS;

    public static function ready(): bool
    {
        return AdCampaigns::ready() && SchemaHas::column('gates_ad_campaigns', 'source');
    }

    /**
     * Post a campaign: one set, one approved ad, live from the start date for the days given.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, id?:int, errors?:array<string,string>}
     */
    public static function post(int $adminId, array $in, ?string $imagePath, ?string $logoPath = null): array
    {
        if (!self::ready()) return ['ok' => false, 'errors' => ['form' => 'Ads are not available until the latest migrations have run.']];
        $source = (string) ($in['source'] ?? '');
        $e = [];
        if (!in_array($source, ['direct', 'house'], true)) $e['source'] = 'Choose sold by us or our own.';

        $advertiser = $source === 'house' ? 'Africa GATES'
            : trim((string) preg_replace('/\s+/u', ' ', (string) ($in['advertiser'] ?? '')));
        $al = mb_strlen($advertiser);
        if ($al < 2 || $al > AdRules::NAME_MAX) $e['advertiser'] = sprintf('The business name, 2 to %d characters.', AdRules::NAME_MAX);

        $places = AdRules::cleanPlacements($in['placements'] ?? []);
        if ($places === []) $e['placements'] = 'Choose at least one place.';

        $c = AdRules::creative($in);
        $e += $c['errors'];
        if ($imagePath === null || $imagePath === '') $e['image'] = 'Add an image.';

        $days = (int) ($in['days'] ?? 0);
        if ($days < 1 || $days > self::MAX_DAYS) $e['days'] = sprintf('From 1 to %d days.', self::MAX_DAYS);
        $start = trim((string) ($in['starts_on'] ?? ''));
        $startAt = $start === '' ? time() : strtotime($start . ' 00:00:00');
        if ($startAt === false) $e['starts_on'] = 'A date, or blank for today.';
        elseif ($start !== '' && $startAt < strtotime('today')) $e['starts_on'] = 'Not in the past.';

        $budget = 0;
        if ($source === 'direct') {
            $budget = (int) preg_replace('/[^0-9]/', '', (string) ($in['budget'] ?? ''));
            if ($budget < 1 || $budget > AdCheckout::MAX_BUDGET_NAIRA) $e['budget'] = 'The budget the advertiser paid for, in naira.';
        }
        $cap = (int) preg_replace('/[^0-9]/', '', (string) ($in['view_cap'] ?? ''));
        if ($e !== []) return ['ok' => false, 'errors' => $e];

        $q = AdCheckout::quote($budget);
        $rates = $source === 'direct' ? AdRateCard::snapshot($places) : [];
        if ($source === 'direct' && $rates === []) {
            return ['ok' => false, 'errors' => ['placements' => 'None of those places has a price, so a budget cannot be spent there. Set one in Settings, Ad prices.']];
        }
        $now = date('Y-m-d H:i:s');
        $from = date('Y-m-d H:i:s', max(time(), (int) $startAt));
        try {
            $id = (int) DB::connection()->transaction(static function () use ($adminId, $source, $advertiser, $in, $places, $rates, $c, $imagePath, $logoPath, $now, $from, $days, $q, $cap, $budget): int {
                $cid = (int) DB::table('gates_ad_campaigns')->insertGetId([
                    'user_id' => 0, 'name' => mb_substr(trim((string) ($in['name'] ?? '')) ?: $advertiser, 0, 80),
                    'advertiser' => $advertiser, 'objective' => isset(AdRules::OBJECTIVES[(string) ($in['objective'] ?? '')]) ? (string) $in['objective'] : 'traffic',
                    'category' => 'general', 'trial' => 0, 'status' => AdCampaigns::ST_SUBMITTED,
                    'source' => $source, 'posted_by' => $adminId > 0 ? $adminId : null,
                    'logo_path' => $logoPath ?: null, 'standards_at' => $now, 'submitted_at' => $now,
                    // Money in the door for a sale made by staff; nothing for our own.
                    'payment_ref' => $source === 'direct' ? 'DIRECT-' . strtoupper(bin2hex(random_bytes(6))) : null,
                    'paid_at' => $now, 'paid_kobo' => $source === 'direct' ? $q['total'] * 100 : 0,
                    'vat_bps' => $source === 'direct' ? $q['vat_bps'] : 0, 'vat_kobo' => $source === 'direct' ? $q['vat'] * 100 : 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $sid = (int) DB::table('gates_ad_sets')->insertGetId([
                    'campaign_id' => $cid, 'placements' => implode(',', $places),
                    'budget_kobo' => $budget * 100, 'rates' => json_encode($rates ?: new \stdClass()),
                    'view_cap' => $source === 'house' && $cap > 0 ? $cap : null, 'days' => $days,
                    'starts_at' => $from, 'ends_at' => date('Y-m-d H:i:s', strtotime($from) + $days * 86400),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('gates_ads')->insert($c['row'] + [
                    'ad_set_id' => $sid, 'image_path' => $imagePath, 'review' => 'approved',
                    'reviewed_by' => $adminId > 0 ? $adminId : null, 'reviewed_at' => $now,
                    'review_flags' => implode(' · ', AdRules::flags($c['row'], 0)) ?: null,
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
     * Every campaign, newest first, as the console lists it: who, which kind, its state and
     * its results. Self-serve ones are listed too, so staff see the whole of what is running.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(int $limit = 200): array
    {
        if (!AdCampaigns::ready()) return [];
        try {
            $rows = DB::table('gates_ad_campaigns')->orderByDesc('id')->limit($limit)->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static function ($c): array {
            $s = AdCampaigns::summary($c);
            unset($s['_set']);
            $src = (string) ($c->source ?? 'self');
            return $s + ['source' => $src, 'source_label' => AdRules::SOURCES[$src] ?? $src,
                         'staff' => in_array($src, ['direct', 'house'], true)];
        }, $rows);
    }

    /** Pause, resume or end a campaign staff posted. A self-serve one is the advertiser's, and moderation's. */
    public static function setStatus(int $id, string $to): bool
    {
        $from = match ($to) {
            AdCampaigns::ST_PAUSED    => [AdCampaigns::ST_SUBMITTED],
            AdCampaigns::ST_SUBMITTED => [AdCampaigns::ST_PAUSED],
            AdCampaigns::ST_CANCELLED => [AdCampaigns::ST_SUBMITTED, AdCampaigns::ST_PAUSED],
            default => [],
        };
        if ($from === [] || !self::ready()) return false;
        $now = date('Y-m-d H:i:s');
        return DB::table('gates_ad_campaigns')->where('id', $id)->whereIn('source', ['direct', 'house'])->whereIn('status', $from)
            ->update(['status' => $to, 'updated_at' => $now] + ($to === AdCampaigns::ST_CANCELLED ? ['cancelled_at' => $now] : [])) > 0;
    }
}
