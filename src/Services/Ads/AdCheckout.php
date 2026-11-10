<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Services\PaymentService;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Paying for a campaign: book, pay, run, and give back what was not spent.
 *
 * Owner, 3 Nov 2026: "please do" — paid checkout, which the plan held until advertising
 * money had its own stream in finance and a VAT decision. Both are made here:
 *
 *   · THE STREAM is `ads` (CfisCatalogue::BUILT_IN, references `AFG-ADS-…`), so advertising
 *     income is never counted as votes or donations, and Finance reports it on its own line.
 *   · VAT is charged ON TOP of the budget at the rate in `ads_vat_bps` — 7.5% by default,
 *     Nigeria's standard rate on services, set in the console so a change in the law or in
 *     the organisation's registration needs no deployment. Advertising is sold VAT-exclusive
 *     the way every ad platform sells it: the price on /advertise is the price of the views,
 *     and the VAT is shown as its own line before paying and on the receipt. Its rate is
 *     frozen on the campaign, and what is refunded carries its share of it.
 *
 * ── THE MONEY RULES, FROM THE POLICY ───────────────────────────────────────
 *
 * Prepaid in full, no wallet, no balance: one campaign is one payment. A campaign starts when
 * it is BOTH paid and approved, and runs the days that were bought from then — days spent in
 * a review queue are not days anybody paid for. What it does not spend goes back to where it
 * came from, with its VAT, when it ends or is cancelled (settle(), from the maintenance run),
 * including when a reviewer refused it and the advertiser gave up. A chargeback ends it.
 */
final class AdCheckout
{
    public const REF_PREFIX       = 'AFG-ADS-';
    public const VAT_KEY          = 'ads_vat_bps';
    public const DEFAULT_VAT_BPS  = 750;
    /** The smallest budget worth a review, a payment and a refund. */
    public const MIN_BUDGET_NAIRA = 5000;
    public const MAX_BUDGET_NAIRA = 10_000_000;

    public static function vatBps(): int
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', self::VAT_KEY)->value('value');
        } catch (\Throwable) {
            $v = null;
        }
        $v = trim((string) ($v ?? ''));
        return $v === '' || !is_numeric($v) ? self::DEFAULT_VAT_BPS : max(0, min(2500, (int) $v));
    }

    /** @return array{budget:int, vat:int, total:int, vat_bps:int} naira */
    public static function quote(int $budgetNaira, ?int $vatBps = null): array
    {
        $bps = $vatBps ?? self::vatBps();
        $vat = (int) round($budgetNaira * $bps / 10000);
        return ['budget' => $budgetNaira, 'vat' => $vat, 'total' => $budgetNaira + $vat, 'vat_bps' => $bps];
    }

    /** Are paid campaigns possible here at all: a gateway, and at least one place on sale? */
    public static function open(?PaymentService $p = null): bool
    {
        if (!AdRateCard::anyOnSale() || !SchemaHas::column('gates_ad_sets', 'days')) return false;
        try {
            return ($p ?? new PaymentService())->enabledProviderIds() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Validate and create a paid campaign, waiting for its payment and its review.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, id?:int, reference?:string, errors?:array<string,string>}
     */
    public static function create(int $userId, array $in, ?string $imagePath, ?string $logoPath = null): array
    {
        if (!AdCampaigns::ready() || $userId < 1) return ['ok' => false, 'errors' => ['form' => 'Ads are not available right now.']];
        $v = AdCampaigns::validate($in);
        $errors = $v['errors'];
        if ($imagePath === null || $imagePath === '') $errors['image'] ??= 'Add a square image.';
        if (empty($in['standards'])) $errors['standards'] = 'Confirm your ad meets the ad standards.';

        // Only places that are on sale can be bought; the trial runs everywhere, money does not.
        $rates = AdRateCard::snapshot($v['placements']);
        $places = array_values(array_filter($v['placements'], static fn ($p) => isset($rates[$p])));
        if ($v['placements'] !== [] && $places === []) $errors['placements'] = 'None of those places is on sale yet.';

        $budget = (int) preg_replace('/[^0-9]/', '', (string) ($in['budget'] ?? ''));
        if ($budget < self::MIN_BUDGET_NAIRA || $budget > self::MAX_BUDGET_NAIRA) {
            $errors['budget'] = sprintf('A budget of ₦%s to ₦%s.', number_format(self::MIN_BUDGET_NAIRA), number_format(self::MAX_BUDGET_NAIRA));
        }
        $days = (int) ($in['days'] ?? 0);
        if ($days < 1 || $days > AdRules::MAX_DAYS) $errors['days'] = sprintf('From 1 to %d days.', AdRules::MAX_DAYS);
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        $q = self::quote($budget);
        $ref = self::REF_PREFIX . strtoupper(bin2hex(random_bytes(6)));
        $now = date('Y-m-d H:i:s');
        try {
            $id = (int) DB::connection()->transaction(static function () use ($userId, $v, $places, $rates, $imagePath, $logoPath, $now, $q, $ref, $days): int {
                $cid = (int) DB::table('gates_ad_campaigns')->insertGetId($v['campaign'] + [
                    'user_id' => $userId, 'trial' => 0, 'status' => AdCampaigns::ST_SUBMITTED,
                    'logo_path' => $logoPath ?: null, 'standards_at' => $now, 'payment_ref' => $ref,
                    'vat_bps' => $q['vat_bps'], 'vat_kobo' => $q['vat'] * 100,
                    'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $sid = (int) DB::table('gates_ad_sets')->insertGetId([
                    'campaign_id' => $cid, 'placements' => implode(',', $places),
                    'budget_kobo' => $q['budget'] * 100,
                    'rates' => json_encode(array_intersect_key($rates, array_flip($places))),
                    'view_cap' => null, 'days' => $days, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('gates_ads')->insert($v['ad'] + [
                    'ad_set_id' => $sid, 'image_path' => $imagePath, 'review' => 'pending',
                    'review_flags' => implode(' · ', AdRules::flags($v['ad'] + ['_category' => $v['campaign']['category'],
                        'licence_ref' => $v['campaign']['licence_ref']], $userId)) ?: null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                return $cid;
            });
        } catch (\Throwable) {
            return ['ok' => false, 'errors' => ['form' => 'That did not save. Try again.']];
        }
        return ['ok' => true, 'id' => $id, 'reference' => $ref];
    }

    /**
     * Open the gateway's checkout for an unpaid campaign of this member's.
     *
     * @return array{ok:bool, url?:string, message:string}
     */
    public static function start(PaymentService $p, int $campaignId, int $userId, string $email, string $callbackUrl): array
    {
        $c = DB::table('gates_ad_campaigns')->where('id', $campaignId)->where('user_id', $userId)->first();
        if (!$c || (int) $c->trial === 1 || (string) $c->status === AdCampaigns::ST_CANCELLED) return ['ok' => false, 'message' => 'That campaign cannot be paid for.'];
        if ($c->paid_at !== null) return ['ok' => false, 'message' => 'That campaign is already paid for.'];
        $set = DB::table('gates_ad_sets')->where('campaign_id', $campaignId)->orderBy('id')->first();
        if (!$set) return ['ok' => false, 'message' => 'That campaign cannot be paid for.'];
        $total = intdiv((int) $set->budget_kobo + (int) $c->vat_kobo, 100);
        $ids = $p->enabledProviderIds();
        $provider = in_array('paystack', $ids, true) ? 'paystack' : ($ids[0] ?? '');
        if ($provider === '') return ['ok' => false, 'message' => 'Payments are not available right now. Your campaign is saved; pay for it from your Ads Manager.'];
        $r = $p->initialize($provider, $total, $email, (string) $c->payment_ref, $callbackUrl,
            ['reference' => (string) $c->payment_ref, 'purpose' => 'ads', 'campaign' => $campaignId]);
        if (!($r['ok'] ?? false) || empty($r['checkout_url'])) {
            return ['ok' => false, 'message' => 'The payment page would not open. Your campaign is saved; try again from your Ads Manager.'];
        }
        return ['ok' => true, 'url' => (string) $r['checkout_url'], 'message' => ''];
    }

    /**
     * The gateway says a campaign's payment cleared. Asked of the gateway, never taken from
     * the browser; the amount must cover what was quoted; idempotent, because the return
     * page and the webhook both arrive.
     *
     * @return array{state:string, message:string, campaign?:int}
     */
    public static function confirm(string $reference, PaymentService $p): array
    {
        $c = DB::table('gates_ad_campaigns')->where('payment_ref', $reference)->first();
        if (!$c) return ['state' => 'unmatched', 'message' => ''];
        if ($c->paid_at !== null) return ['state' => 'already', 'message' => '', 'campaign' => (int) $c->id];
        $set = DB::table('gates_ad_sets')->where('campaign_id', $c->id)->orderBy('id')->first();
        $due = $set ? (int) $set->budget_kobo + (int) $c->vat_kobo : 0;
        $v = $p->verify('paystack', $reference);
        if (!($v['ok'] ?? false) || !in_array(strtolower((string) ($v['status'] ?? '')), ['success', 'paid'], true)) {
            return ['state' => 'pending', 'message' => (string) ($v['message'] ?? ''), 'campaign' => (int) $c->id];
        }
        $paid = (int) ($v['amount'] ?? 0) * 100;
        if ($due <= 0 || $paid < $due) {
            return ['state' => 'mismatch', 'message' => 'Paid ' . AdRateCard::format($paid) . ' against ' . AdRateCard::format($due), 'campaign' => (int) $c->id];
        }
        $now = date('Y-m-d H:i:s');
        $n = DB::table('gates_ad_campaigns')->where('id', $c->id)->whereNull('paid_at')
            ->update(['paid_at' => $now, 'paid_kobo' => $paid, 'updated_at' => $now]);
        if ($n === 1 && $set) self::schedule((int) $set->id);
        if ($n === 1) AdNotice::receipt((int) $c->id);
        return ['state' => $n === 1 ? 'confirmed' : 'already', 'message' => '', 'campaign' => (int) $c->id];
    }

    /**
     * Start a paid set's window once it is both paid and approved: from now, for the days
     * bought. Called from both events, whichever comes second.
     */
    public static function schedule(int $setId): void
    {
        try {
            $s = DB::table('gates_ad_sets as s')->join('gates_ad_campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('s.id', $setId)->select('s.*', 'c.paid_at', 'c.trial')->first();
            if (!$s || (int) $s->trial === 1 || $s->paid_at === null || $s->starts_at !== null) return;
            if (!DB::table('gates_ads')->where('ad_set_id', $setId)->where('review', 'approved')->exists()) return;
            $now = date('Y-m-d H:i:s');
            $days = max(1, (int) ($s->days ?? 0));
            DB::table('gates_ad_sets')->where('id', $setId)->whereNull('starts_at')->update([
                'starts_at' => $now, 'ends_at' => date('Y-m-d H:i:s', time() + $days * 86400), 'updated_at' => $now,
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Give back what finished campaigns did not spend, with its share of the VAT.
     *
     * A campaign is finished when it is cancelled or its delivery is complete (budget spent,
     * or its days over). `refunded_at` is claimed BEFORE the gateway is asked, so two runs
     * cannot both refund one campaign; a refusal releases the claim for the next run, and an
     * unknown outcome keeps it, because retrying an unknown is how money goes back twice.
     *
     * @return int campaigns settled this run
     */
    public static function settle(PaymentService $p, int $limit = 20): int
    {
        if (!AdCampaigns::ready() || !SchemaHas::column('gates_ad_campaigns', 'refunded_vat_kobo')) return 0;
        try {
            // Self-serve only: a campaign sold by staff was paid outside the site, so its
            // unspent money goes back by a person, never through the gateway.
            $q = DB::table('gates_ad_campaigns')->where('trial', 0)->whereNotNull('paid_at')->whereNull('refunded_at');
            if (SchemaHas::column('gates_ad_campaigns', 'source')) $q->where('source', 'self');
            $rows = $q->orderBy('id')->limit(200)->get()->all();
        } catch (\Throwable) {
            return 0;
        }
        $done = 0;
        foreach ($rows as $c) {
            if ($done >= $limit) break;
            $sum = AdCampaigns::summary($c);
            if (!in_array($sum['delivery'], ['completed', 'cancelled'], true)) {
                // Refused and abandoned: not finished by delivery, but never going to run.
                // Thirty days after payment with no approved ad, it is given back whole.
                if (!($sum['delivery'] === 'not_approved' && strtotime((string) $c->paid_at) < time() - 30 * 86400)) continue;
            }
            $budget = (int) $sum['budget_kobo'];
            $unspent = max(0, $budget - (int) $sum['spent_kobo']);
            $vatBack = $budget > 0 ? intdiv((int) $c->vat_kobo * $unspent, $budget) : 0;
            $naira = intdiv($unspent + $vatBack, 100);
            $now = date('Y-m-d H:i:s');
            $claimed = DB::table('gates_ad_campaigns')->where('id', $c->id)->whereNull('refunded_at')
                ->update(['refunded_at' => $now, 'updated_at' => $now]);
            if ($claimed !== 1) continue;
            if ($naira < 1) {
                DB::table('gates_ad_campaigns')->where('id', $c->id)->update(['refunded_kobo' => 0, 'refunded_vat_kobo' => 0,
                    'refund_note' => 'Budget fully spent; nothing to return.']);
                $done++;
                continue;
            }
            $r = $p->refund('paystack', (string) $c->payment_ref, $naira);
            if (($r['ok'] ?? false) || ($r['status'] ?? '') === 'pending') {
                DB::table('gates_ad_campaigns')->where('id', $c->id)->update([
                    'refunded_kobo' => $naira * 100, 'refunded_vat_kobo' => min($vatBack, $naira * 100),
                    'refund_note' => mb_substr(($r['ok'] ?? false) ? 'Unspent budget returned.' : (string) ($r['message'] ?? 'Refund outcome not yet known.'), 0, 250),
                ]);
                AdNotice::refunded((int) $c->user_id, (string) $c->name, $naira);
                $done++;
            } else {
                DB::table('gates_ad_campaigns')->where('id', $c->id)->update(['refunded_at' => null,
                    'refund_note' => mb_substr('Refund refused: ' . (string) ($r['message'] ?? ''), 0, 250)]);
            }
        }
        return $done;
    }

    /** A chargeback or a refund made at the gateway: the campaign stops, and the record says why. */
    public static function reverse(string $reference, string $why): bool
    {
        $c = DB::table('gates_ad_campaigns')->where('payment_ref', $reference)->first();
        if (!$c || $c->paid_at === null) return false;
        $now = date('Y-m-d H:i:s');
        DB::table('gates_ad_campaigns')->where('id', $c->id)->update([
            'status' => AdCampaigns::ST_CANCELLED, 'cancelled_at' => $c->cancelled_at ?? $now,
            'refunded_at' => $c->refunded_at ?? $now,
            'refunded_kobo' => max((int) $c->refunded_kobo, (int) $c->paid_kobo),
            'refunded_vat_kobo' => max((int) ($c->refunded_vat_kobo ?? 0), (int) $c->vat_kobo),
            'refund_note' => mb_substr('Payment reversed at the gateway: ' . $why, 0, 250), 'updated_at' => $now,
        ]);
        return true;
    }

    /**
     * Advertising income for Finance: what campaigns paid, less refunds, WITHOUT the VAT
     * (owed to the tax authority, not earned), and the VAT owed separately.
     *
     * @return array{gross:int, count:int, vat:int} naira
     */
    public static function income(?string $since = null, ?string $until = null): array
    {
        if (!SchemaHas::column('gates_ad_campaigns', 'vat_kobo')) return ['gross' => 0, 'count' => 0, 'vat' => 0];
        try {
            $q = DB::table('gates_ad_campaigns')->where('trial', 0)->whereNotNull('paid_at');
            // Dates, inclusive, as FinanceService::window() reads them.
            if ($since !== null && $since !== '') $q->where('paid_at', '>=', substr($since, 0, 10) . ' 00:00:00');
            if ($until !== null && $until !== '') $q->where('paid_at', '<=', substr($until, 0, 10) . ' 23:59:59');
            $r = $q->selectRaw('COUNT(*) n, COALESCE(SUM(paid_kobo),0) p, COALESCE(SUM(vat_kobo),0) v, '
                . 'COALESCE(SUM(refunded_kobo),0) rf, COALESCE(SUM(refunded_vat_kobo),0) rv')->first();
        } catch (\Throwable) {
            return ['gross' => 0, 'count' => 0, 'vat' => 0];
        }
        $vat = max(0, (int) $r->v - (int) $r->rv);
        $net = max(0, (int) $r->p - (int) $r->rf - $vat);
        return ['gross' => intdiv($net, 100), 'count' => (int) $r->n, 'vat' => intdiv($vat, 100)];
    }
}
