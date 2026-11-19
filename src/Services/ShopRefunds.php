<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Giving a shop buyer their money back, from the console, through the payment gateway.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS MISSING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Staff could not refund a shop order at all — {@see RefundService} covers paid votes that
 * could never be delivered, and nothing else — and "Cancelled" was a delivery state that moved
 * neither money nor stock. So a cancelled shirt was a paid order with a label on it, the money
 * stayed with us, and the shirt stayed counted as sold.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULES, EACH OF WHICH IS A WAY MONEY GOES WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *  1. A REFUND IS SETTLED ONLY BY `refund.processed`. Paystack answers the request with
 *     "pending" and reports the outcome later, by webhook, step by step. Marking a refund done
 *     when we ASKED would say the buyer has money they may never get (`refund.failed` exists).
 *     So the console's request leaves a `pending` row, and only {@see applyWebhook()} with a
 *     processed event — or a person recording a refund paid outside the gateway — settles one.
 *
 *  2. TOTAL REFUNDS NEVER EXCEED THE CHARGE. What may still be refunded is the charge less every
 *     refund that is pending or processed ({@see refundable()}). The row is written FIRST and the
 *     sum re-read after, so two operators pressing at once cannot both fit under the ceiling;
 *     the loser's row is withdrawn before the gateway is ever asked.
 *
 *  3. AN UNKNOWN OUTCOME IS NOT A FAILURE. If the gateway cannot be reached we do not know
 *     whether it took the request, so the row stays pending and counts against the ceiling.
 *     Only a definite refusal releases it. (The same rule as RefundService, for the same reason:
 *     a retried refund that had in fact gone through is money out twice.)
 *
 *  4. A DELIVERY IS HANDLED ONCE. The gateway re-sends webhooks; the row moves pending →
 *     processed in one conditional UPDATE, and only the writer that moved it adds to the order's
 *     refunded total, returns stock, and emails the buyer.
 *
 *  5. STOCK COMES BACK WHEN THE MONEY DOES, and only what the operator said came back. The units
 *     to return are stored on the refund and applied on settlement, because a refund the gateway
 *     refuses must not have put shirts on a shelf they never returned to.
 *
 * ── BANK TRANSFER AND USSD ──────────────────────────────────────────────────
 *
 * Whether Paystack can refund a bank-transfer or USSD payment automatically is UNCONFIRMED
 * (SHOP-MANAGEMENT-PLAN §1). So nothing promises it: the gateway's refusal is shown in its own
 * words, and {@see recordManual()} records money returned by hand ("refunded outside the
 * gateway") — the same row, the same ceiling, the same settlement.
 */
final class ShopRefunds
{
    public const ST_PENDING   = 'pending';
    public const ST_PROCESSED = 'processed';
    public const ST_FAILED    = 'failed';
    public const STATES = [self::ST_PENDING => 'Waiting for the gateway', self::ST_PROCESSED => 'Refunded',
                           self::ST_FAILED => 'Failed'];

    public const METHOD_GATEWAY = 'gateway';
    public const METHOD_MANUAL  = 'manual';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_shop_refunds');
    }

    /** What may still be refunded on this order: the charge less every live refund. */
    public static function refundable(object $order): int
    {
        return max(0, (int) $order->subtotal_naira - self::committed((int) $order->id));
    }

    /** Pending + processed, i.e. everything that is, or may yet be, money out. */
    public static function committed(int $orderId): int
    {
        if (!self::ready()) return 0;
        return (int) DB::table('gates_shop_refunds')->where('order_id', $orderId)
            ->whereIn('status', [self::ST_PENDING, self::ST_PROCESSED])->sum('amount_naira');
    }

    /** @return list<array<string,mixed>> the order's refunds, newest first */
    public static function forOrder(int $orderId): array
    {
        if (!self::ready()) return [];
        return DB::table('gates_shop_refunds as r')->leftJoin('gates_admins as a', 'a.id', '=', 'r.admin_id')
            ->where('r.order_id', $orderId)->orderByDesc('r.id')
            ->get(['r.*', 'a.name as admin_name'])
            ->map(static fn ($r): array => (array) $r + ['label' => self::STATES[(string) $r->status] ?? (string) $r->status])
            ->all();
    }

    /**
     * Ask the gateway to refund some or all of a paid order.
     *
     * @param ?int            $amount  naira; null = everything still refundable
     * @param array<int,int>  $restock line index → units to return when the refund settles
     * @return array{ok:bool, message:string, refund_id?:int, state?:string}
     */
    public static function request(int $orderId, ?int $amount, array $restock, string $reason, int $adminId,
                                   PaymentService $payments): array
    {
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        $check = self::refusal($order, $amount, $reason);
        if ($check !== null) return ['ok' => false, 'message' => $check];

        $provider = strtolower(trim((string) ($order->provider ?? '')));
        if ($provider === '' || !$payments->isEnabled($provider)) {
            return ['ok' => false, 'message' => 'This order was not paid through a gateway that is switched on here'
                . ($provider !== '' ? ' (' . $provider . ')' : '') . '. If you returned the money another way, record it as refunded outside the gateway.'];
        }

        $want = $amount ?? self::refundable($order);
        $rid  = self::claim($order, $want, self::METHOD_GATEWAY, $provider, $restock, $reason, $adminId);
        if ($rid === 0) {
            return ['ok' => false, 'message' => 'That is more than is left to refund on this order — another refund was recorded a moment ago. Reload and try again.'];
        }

        // The whole charge, untouched by any earlier refund, goes as "no amount" — the gateway's
        // own record of what it collected — rather than our arithmetic. See PaymentService::refund().
        $isWhole = $want === (int) $order->subtotal_naira;
        $r = $payments->refund($provider, (string) $order->reference, $isWhole ? null : $want);
        $now = Carbon::now()->toDateTimeString();

        if (($r['status'] ?? '') === 'failed' && !($r['ok'] ?? false)) {
            // A definite refusal: no money moved. The row says why, and stops counting.
            DB::table('gates_shop_refunds')->where('id', $rid)->update([
                'status' => self::ST_FAILED, 'failure' => mb_substr((string) ($r['message'] ?? ''), 0, 300),
                'updated_at' => $now,
            ]);
            ShopOrderLog::add($orderId, 'refund', 'Refund of ₦' . number_format($want) . ' refused by the gateway: '
                . (string) ($r['message'] ?? ''), $adminId);
            return ['ok' => false, 'refund_id' => $rid, 'state' => self::ST_FAILED,
                    'message' => 'The gateway refused the refund: ' . (string) ($r['message'] ?? 'no reason given')
                        . '. Nothing was sent. If you return the money another way, record it as refunded outside the gateway.'];
        }

        // Pending — including "already refunded" and an immediate "processed": the rule is that
        // only `refund.processed` settles a refund, and the gateway sends it for those too.
        DB::table('gates_shop_refunds')->where('id', $rid)->update([
            'provider_refund_id' => ($r['provider_ref'] ?? null) !== null ? mb_substr((string) $r['provider_ref'], 0, 80) : null,
            'gateway_status'     => ($r['ok'] ?? false) ? (string) ($r['status'] ?? 'pending') : 'unknown',
            'failure'            => ($r['ok'] ?? false) ? null : mb_substr((string) ($r['message'] ?? ''), 0, 300),
            'updated_at'         => $now,
        ]);
        ShopOrderLog::add($orderId, 'refund', 'Refund of ₦' . number_format($want) . ' sent to the gateway'
            . ($reason !== '' ? ' — ' . $reason : '') . '.', $adminId);

        return ['ok' => true, 'refund_id' => $rid, 'state' => self::ST_PENDING,
                'message' => ($r['ok'] ?? false)
                    ? 'Refund of ₦' . number_format($want) . ' sent to the gateway. It is marked refunded when the gateway confirms it (usually within a day); the buyer is emailed then.'
                    : 'The gateway did not answer, so it is not known whether it took the refund. It is held as pending — check the gateway dashboard before trying again.'];
    }

    /**
     * Record money returned outside the gateway — cash, a bank transfer, a USSD payment the
     * gateway cannot refund. Settled at once: a person is telling us the money has gone.
     *
     * @param array<int,int> $restock
     * @return array{ok:bool, message:string, refund_id?:int}
     */
    public static function recordManual(int $orderId, ?int $amount, array $restock, string $reason, int $adminId): array
    {
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        $check = self::refusal($order, $amount, $reason);
        if ($check !== null) return ['ok' => false, 'message' => $check];

        $want = $amount ?? self::refundable($order);
        $rid  = self::claim($order, $want, self::METHOD_MANUAL, null, $restock, $reason, $adminId);
        if ($rid === 0) return ['ok' => false, 'message' => 'That is more than is left to refund on this order.'];

        ShopOrderLog::add($orderId, 'refund', 'Recorded ₦' . number_format($want) . ' refunded outside the gateway — '
            . $reason . '.', $adminId);
        self::settle($rid, null, $adminId);
        return ['ok' => true, 'refund_id' => $rid, 'message' => '₦' . number_format($want) . ' recorded as refunded outside the gateway.'];
    }

    /**
     * Call an order off.
     *
     * A PENDING order has taken no money: it becomes `cancelled`, its discount code's use is
     * handed back (on the transition only — releaseUse() is a decrement, not idempotent), and
     * the gateway is asked first, because a "pending" order whose buyer did in fact pay is not
     * one to cancel; it is one to confirm.
     *
     * A PAID order is cancelled THROUGH a refund of whatever is left, with the units returned
     * when it settles (all of them unless the goods have left) — never by a label alone.
     *
     * @return array{ok:bool, message:string}
     */
    public static function cancel(int $orderId, string $reason, int $adminId, PaymentService $payments,
                                  bool $outsideGateway = false, bool $restock = true, ?OtpService $mailer = null): array
    {
        $reason = trim($reason);
        $order  = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$order) return ['ok' => false, 'message' => 'That order could not be found.'];
        if ($reason === '') return ['ok' => false, 'message' => 'Say why it is being cancelled — the buyer is told.'];

        $status = (string) $order->status;
        if ($status === 'pending' || $status === 'failed') {
            if ($status === 'pending' && trim((string) ($order->provider ?? '')) !== '') {
                $c = ShopOrderService::confirm((string) $order->reference, (string) $order->provider, $payments);
                if (($c['state'] ?? '') === 'confirmed' || ($c['state'] ?? '') === 'already') {
                    return ['ok' => false, 'message' => 'The gateway says this order WAS paid, so it has just been confirmed instead of cancelled. To call it off now, cancel it again — that refunds it.'];
                }
            }
            $n = DB::table('gates_orders')->where('id', $orderId)->whereIn('status', ['pending', 'failed'])
                ->update(\AfricaGates\Support\OptionalColumn::filter('gates_orders', [
                    'status' => 'cancelled', 'fulfilment' => 'cancelled',
                    'cancelled_at' => Carbon::now()->toDateTimeString(),
                    'refund_note' => mb_substr($reason, 0, 300),
                ], ['fulfilment', 'cancelled_at', 'refund_note']));
            if ($n === 0) return ['ok' => false, 'message' => 'That order changed a moment ago. Reload and look again.'];
            // A declined order already handed its use back when it failed (ShopOrderService::confirm).
            if ($status === 'pending') ShopDiscount::releaseUse((string) ($order->discount_code ?? ''));
            ShopOrderLog::add($orderId, 'cancel', 'Cancelled before payment — ' . $reason . '.'
                . ($status === 'pending' && trim((string) ($order->discount_code ?? '')) !== '' ? ' Its discount code use was handed back.' : ''), $adminId);
            ShopMail::send($mailer, $orderId, 'cancelled', ['reason' => $reason, 'refund' => '']);
            return ['ok' => true, 'message' => 'Order ' . (string) $order->reference . ' cancelled. No money had been taken.'];
        }

        if ($status !== 'paid') {
            return ['ok' => false, 'message' => 'Only a pending or paid order can be cancelled; this one is ' . $status . '.'];
        }

        $left = self::refundable($order);
        $lines = json_decode((string) $order->items_json, true) ?: [];
        $back = [];
        if ($restock && !ShopOrderService::hasLeft($order)) {
            foreach ($lines as $i => $l) $back[$i] = (int) ($l['drawn'] ?? $l['qty'] ?? 0);
        }

        if ($left > 0) {
            $r = $outsideGateway
                ? self::recordManual($orderId, $left, $back, 'Cancelled: ' . $reason, $adminId)
                : self::request($orderId, $left, $back, 'Cancelled: ' . $reason, $adminId, $payments);
            if (!$r['ok']) return $r;
        }

        DB::table('gates_orders')->where('id', $orderId)
            ->update(\AfricaGates\Support\OptionalColumn::filter('gates_orders', [
                'fulfilment' => 'cancelled', 'cancelled_at' => Carbon::now()->toDateTimeString(),
            ], ['fulfilment', 'cancelled_at']));
        ShopOrderLog::add($orderId, 'cancel', 'Cancelled after payment — ' . $reason . '.', $adminId);
        ShopMail::send($mailer, $orderId, 'cancelled',
            ['reason' => $reason, 'refund' => $outsideGateway ? 'manual' : 'gateway']);

        return ['ok' => true, 'message' => 'Order ' . (string) $order->reference . ' cancelled. '
            . ($left > 0
                ? ($outsideGateway ? '₦' . number_format($left) . ' recorded as refunded outside the gateway.'
                                   : '₦' . number_format($left) . ' is being refunded through the gateway; stock returns when it settles.')
                : 'Everything had already been refunded.')];
    }

    /**
     * A signed `refund.*` delivery for a shop reference.
     *
     * Matched by the gateway's refund id first (what a re-delivery repeats), then by the order
     * and the oldest refund still waiting. A processed refund nobody asked for here — one started
     * on the gateway's own dashboard — is RECORDED, so the order's total is right whichever door
     * the refund came through; a pending/failed one we never asked for is only logged.
     *
     * @return string what was decided, for the gateway event log
     */
    public static function applyWebhook(string $provider, string $event, string $reference, array $data): string
    {
        if (!self::ready()) return 'ignored';
        $order = DB::table('gates_orders')->where('reference', $reference)->first();
        if (!$order) return 'unmatched';

        $step = match (true) {
            str_contains($event, 'processed') => 'processed',
            str_contains($event, 'failed')    => 'failed',
            str_contains($event, 'processing') => 'processing',
            default => 'pending',
        };
        $gid    = trim((string) ($data['id'] ?? ($data['refund_id'] ?? '')));
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (int) round(((float) $data['amount']) / 100) : null;

        $row = null;
        if ($gid !== '') {
            $row = DB::table('gates_shop_refunds')->where('provider', $provider)->where('provider_refund_id', $gid)->first();
        }
        if (!$row) {
            $q = DB::table('gates_shop_refunds')->where('order_id', (int) $order->id)
                ->where('method', self::METHOD_GATEWAY)->where('status', self::ST_PENDING);
            $cands = $q->orderBy('id')->get();
            foreach ($cands as $c) {
                if ($amount !== null && (int) $c->amount_naira === $amount) { $row = $c; break; }
            }
            $row ??= $cands->first();
            if ($row && $gid !== '' && trim((string) ($row->provider_refund_id ?? '')) === '') {
                try {
                    DB::table('gates_shop_refunds')->where('id', (int) $row->id)
                        ->update(['provider_refund_id' => mb_substr($gid, 0, 80)]);
                } catch (\Throwable) {}
            }
        }

        if (!$row) {
            if ($step !== 'processed') return 'ignored';
            // Refunded on the gateway's dashboard. The amount it names, or the whole of what is
            // left when it names none — the old behaviour, which treated every such event as a
            // full reversal and returned the stock of an order that had not left.
            $left = self::refundable($order);
            $want = $amount !== null ? min($amount, $left) : $left;
            if ($want < 1) return 'ignored';
            $whole = $want >= $left;
            $back = [];
            if ($whole && !ShopOrderService::hasLeft($order)) {
                foreach (json_decode((string) $order->items_json, true) ?: [] as $i => $l) $back[$i] = PHP_INT_MAX;
            }
            $rid = self::claim($order, $want, self::METHOD_GATEWAY, $provider, $back,
                               'Refunded on the gateway dashboard', 0, $gid !== '' ? $gid : null);
            if ($rid === 0) return 'ignored';
            ShopOrderLog::add((int) $order->id, 'refund', 'A refund of ₦' . number_format($want)
                . ' was made on the gateway dashboard and has been recorded here.');
            self::settle($rid, 'refund.processed');
            return 'refunded';
        }

        if (in_array((string) $row->status, [self::ST_PROCESSED, self::ST_FAILED], true)) return 'duplicate';

        if ($step === 'processed') {
            return self::settle((int) $row->id, 'refund.processed') ? 'refunded' : 'duplicate';
        }
        if ($step === 'failed') {
            $n = DB::table('gates_shop_refunds')->where('id', (int) $row->id)->where('status', self::ST_PENDING)
                ->update(['status' => self::ST_FAILED, 'gateway_status' => 'failed',
                          'failure' => mb_substr((string) ($data['message'] ?? ($data['reason'] ?? 'The gateway reported the refund as failed.')), 0, 300),
                          'updated_at' => Carbon::now()->toDateTimeString()]);
            if ($n === 0) return 'duplicate';
            ShopOrderLog::add((int) $order->id, 'refund', 'The gateway reported the ₦'
                . number_format((int) $row->amount_naira) . ' refund as FAILED. The money did not go back.');
            Notifier::adminAlert(null, 'A shop refund FAILED at the gateway',
                'Order:  ' . $order->reference . "\nAmount: ₦" . number_format((int) $row->amount_naira)
                . "\n\nThe buyer has not been refunded. Return the money another way and record it on the order.");
            ShopMail::queue((int) $order->id, 'refund_failed', ['amount' => (int) $row->amount_naira], 'r' . $row->id);
            return 'refund-failed';
        }

        DB::table('gates_shop_refunds')->where('id', (int) $row->id)->where('status', self::ST_PENDING)
            ->update(['gateway_status' => $step, 'updated_at' => Carbon::now()->toDateTimeString()]);
        return 'refund-' . $step;
    }

    /**
     * pending → processed, once. Adds to the order's refunded total, returns the stock it was
     * told to, emails the buyer; and when the order is now refunded in full, settles the order.
     */
    private static function settle(int $refundId, ?string $event, ?int $adminId = null): bool
    {
        $now = Carbon::now()->toDateTimeString();
        $n = DB::table('gates_shop_refunds')->where('id', $refundId)->where('status', self::ST_PENDING)
            ->update(['status' => self::ST_PROCESSED, 'processed_at' => $now, 'updated_at' => $now,
                      'gateway_status' => $event !== null ? 'processed' : null]);
        if ($n === 0) return false;

        $r     = DB::table('gates_shop_refunds')->where('id', $refundId)->first();
        $order = DB::table('gates_orders')->where('id', (int) $r->order_id)->first();
        if (!$order) return true;

        $total = (int) DB::table('gates_shop_refunds')->where('order_id', (int) $order->id)
            ->where('status', self::ST_PROCESSED)->sum('amount_naira');
        DB::table('gates_orders')->where('id', (int) $order->id)
            ->update(\AfricaGates\Support\OptionalColumn::filter('gates_orders', ['refunded_naira' => $total], ['refunded_naira']));

        $back = json_decode((string) ($r->restock_json ?? ''), true);
        $back = is_array($back) ? array_map('intval', $back) : [];
        $whole = $total >= (int) $order->subtotal_naira;

        if ($whole && (string) $order->status === 'paid') {
            ShopOrderService::settleInFull((int) $order->id, (string) ($r->reason ?? 'refunded'), $back, null);
        } elseif ($back !== []) {
            $said = ShopOrderService::restock((int) $order->id, $back, 'refund', 'Refund #' . $refundId, $adminId);
            if ($said !== []) {
                ShopOrderLog::add((int) $order->id, 'refund', 'Returned to stock: ' . implode(', ', $said) . '.', $adminId);
            }
        }
        if ($back !== []) {
            DB::table('gates_shop_refunds')->where('id', $refundId)->update(['restocked' => 1]);
        }
        ShopOrderLog::add((int) $order->id, 'refund', '₦' . number_format((int) $r->amount_naira) . ' refunded'
            . ($r->method === self::METHOD_MANUAL ? ' outside the gateway' : ' — confirmed by the gateway') . '.', $adminId);
        ShopMail::queue((int) $order->id, 'refunded', ['amount' => (int) $r->amount_naira, 'method' => (string) $r->method],
                        'r' . $refundId);
        return true;
    }

    /** Why this refund cannot be asked for, or null. */
    private static function refusal(?object $order, ?int $amount, string $reason): ?string
    {
        if (!self::ready()) return 'Refunds need the latest database update — run /__setup/migrate.';
        if (!$order) return 'That order could not be found.';
        if ((string) $order->status !== 'paid') {
            return 'Only a paid order can be refunded; this one is ' . (string) $order->status . '.';
        }
        if (trim($reason) === '') return 'Say why — the reason is kept on the order.';
        $left = self::refundable($order);
        if ($left < 1) return 'Everything paid on this order has already been refunded or is being refunded.';
        if ($amount !== null && $amount < 1) return 'A refund has to be at least ₦1.';
        if ($amount !== null && $amount > $left) {
            return 'At most ₦' . number_format($left) . ' is left to refund on this order (it was charged ₦'
                . number_format((int) $order->subtotal_naira) . ').';
        }
        return null;
    }

    /**
     * Write the refund row, then re-read the ceiling. Returns 0 (and withdraws the row) when a
     * concurrent refund means this one would take the total past the charge.
     *
     * @param array<int,int> $restock
     */
    private static function claim(object $order, int $amount, string $method, ?string $provider, array $restock,
                                  string $reason, int $adminId, ?string $gatewayId = null): int
    {
        $now = Carbon::now()->toDateTimeString();
        $restock = array_filter(array_map('intval', $restock), static fn (int $q): bool => $q > 0);
        $id = (int) DB::table('gates_shop_refunds')->insertGetId([
            'order_id' => (int) $order->id, 'amount_naira' => $amount, 'method' => $method,
            'status' => self::ST_PENDING, 'provider' => $provider,
            'provider_refund_id' => $gatewayId !== null ? mb_substr($gatewayId, 0, 80) : null,
            'reason' => mb_substr(trim($reason), 0, 300),
            'restock_json' => $restock !== [] ? json_encode($restock) : null,
            'admin_id' => $adminId > 0 ? $adminId : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if (self::committed((int) $order->id) > (int) $order->subtotal_naira) {
            DB::table('gates_shop_refunds')->where('id', $id)->delete();
            return 0;
        }
        return $id;
    }
}
