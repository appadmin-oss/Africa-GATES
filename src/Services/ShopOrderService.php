<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Everything that happens to a shop order once money is involved.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SERVICE, AND WHY IT HAD TO BECOME ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Confirming a shop order lived as a private method on {@see \AfricaGates\Controllers\ShopCheckoutController},
 * which meant only a browser hitting `/shop/callback` could ever reach it. Two consequences,
 * both of which were live:
 *
 *   1 · THE GATEWAY WEBHOOK COULD NOT CONFIRM AN ORDER. `/pay/webhook` resolved every
 *       reference against `gates_donations`, so an `AFG-SHP-…` reference matched nothing and
 *       the delivery was acknowledged with a 200 and discarded. A buyer paying inside a wallet
 *       app — who very often never returns to the callback — depended entirely on a cron sweep.
 *
 *   2 · SO THE RECONCILER GREW A SECOND COPY, and the two drifted. `PaymentReconciler` had
 *       its own `fulfilOrder()` which decremented `gates_products.stock` by slug and nothing
 *       else: it never touched `gates_product_variants`, so a reconciled order for a shirt in
 *       XL took stock from no column at all; it never counted the sale, never awarded the
 *       buyer's points, never fired `order.paid`, and sent a receipt with no link to the order.
 *       An order confirmed by cron and the same order confirmed by the browser were two
 *       different events with two different outcomes.
 *
 * One implementation, three callers: the callback, the webhook, and the sweep. That is the
 * whole point of the file.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT CONFIRMATION REFUSES, AND WHAT IT TOLERATES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Refuses: a gateway that does not say `success`; a payment SHORT of the order total; a
 * payment in a currency that is not naira.
 *
 * Tolerates: a payment OVER the order total. This used to be a strict `!==` and it was the
 * last copy of a rule the rest of the platform had already moved off — see the long note in
 * {@see \AfricaGates\Controllers\PaymentController::confirmByReference()}. Turning on
 * "customer bears the transaction fee" in the Paystack dashboard adds the fee to every charged
 * amount, so under strict equality *every* order on the platform arrives a few hundred naira
 * over and is refused. One dashboard toggle, no code change, shop offline.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE SIDE EFFECTS ARE QUEUED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This now runs inside a gateway webhook, which has roughly 30 seconds for the whole delivery
 * before it counts as failed and enters a 72-hour retry schedule. The receipt (SMTP, up to
 * 12s) and the outbound integration fan-out (up to 8s per configured endpoint) are exactly
 * what Paystack's own documentation says to put on a queue. The STOCK is not queued: it is a
 * handful of indexed writes and it is the number the next buyer's checkout is priced against.
 */
final class ShopOrderService
{
    /** Queue name for the buyer's receipt + the team's alert. */
    public const JOB_RECEIPT = 'shop.receipt';

    /**
     * Confirm one order against the gateway. Idempotent, and safe to call concurrently.
     *
     * @return array{ok:bool, state:'confirmed'|'already'|'failed'|'mismatch', message:string}
     */
    public static function confirm(
        string $reference,
        string $provider,
        PaymentService $payments,
        ?LoggerInterface $log = null,
    ): array {
        $reference = trim($reference);
        $order = $reference !== ''
            ? DB::table('gates_orders')->where('reference', $reference)->first()
            : null;

        if (!$order) {
            return ['ok' => false, 'state' => 'failed', 'message' => 'That order could not be found.'];
        }
        if ((string) ($order->status ?? '') === 'paid') {
            return ['ok' => true, 'state' => 'already', 'message' => 'This order is already confirmed.'];
        }
        if ((string) ($order->status ?? '') === 'refunded') {
            return ['ok' => false, 'state' => 'failed', 'message' => 'That order was refunded.'];
        }

        // The gateway recorded on the row is asked first. Falling back to every enabled one
        // matters for an order taken before the column existed, and for one whose gateway has
        // since been switched off — otherwise that row is permanently unconfirmable.
        $stored = strtolower(trim($provider !== '' ? $provider : (string) ($order->provider ?? '')));
        $enabled = $payments->enabledProviderIds();
        $ask = $stored !== '' && in_array($stored, $enabled, true)
            ? array_merge([$stored], array_values(array_diff($enabled, [$stored])))
            : $enabled;

        foreach ($ask as $p) {
            $v = $payments->verify($p, $reference);

            if (!($v['ok'] ?? false)) continue;                       // gateway did not answer
            if (($v['status'] ?? '') === 'failed') {
                // Only an EXPLICIT failure demotes the row. A 'pending' gateway state is left
                // alone so a later webhook or sweep can still confirm it.
                $demoted = DB::table('gates_orders')->where('reference', $reference)
                    ->where('status', 'pending')->update(['status' => 'failed']);
                // A declined card should not spend somebody's discount code — but the release
                // rides on the TRANSITION, not on reaching this branch. `releaseUse()`
                // decrements a counter and is not idempotent, so a buyer refreshing the
                // callback on a declined card would otherwise hand the code back a use per
                // refresh, and a promotion limited to fifty would gain uses by being failed at.
                if ($demoted > 0) {
                    ShopDiscount::releaseUse((string) ($order->discount_code ?? ''));
                }
                return ['ok' => false, 'state' => 'failed', 'message' => 'The payment was declined.'];
            }
            if (($v['status'] ?? '') !== 'success') continue;

            $paid = (int) ($v['amount'] ?? 0);
            $owed = (int) $order->subtotal_naira;

            if ($paid < $owed || !self::currencyAgrees($v)) {
                $log?->warning('[shop] amount/currency mismatch — refusing to confirm', [
                    'ref' => $reference, 'expected' => $owed, 'verified' => $paid,
                    'currency' => (string) ($v['currency'] ?? ''),
                ]);
                return ['ok' => false, 'state' => 'mismatch',
                        'message' => 'The gateway shows a different amount for that payment, '
                                   . 'so nothing has been confirmed. Please contact us.'];
            }
            // ── PAID FOR AN ORDER STAFF HAD ALREADY CANCELLED ───────────────────
            //
            // Cancelling a pending order asks the gateway first (ShopRefunds::cancel), but a
            // buyer can still finish paying in a wallet app after that. The money is real and
            // the order is not, so nothing is fulfilled and a person is told — once, by the
            // writer that claims the alert through the order's history.
            if ((string) ($order->status ?? '') === 'cancelled') {
                $log?->warning('[shop] payment arrived for a CANCELLED order', ['ref' => $reference, 'paid' => $paid]);
                $seen = OptionalColumn::on('gates_orders', 'refund_note')
                    && str_contains((string) ($order->refund_note ?? ''), '[paid after cancel]');
                if (!$seen) {
                    try {
                        DB::table('gates_orders')->where('id', (int) $order->id)->update(OptionalColumn::filter('gates_orders',
                            ['refund_note' => mb_substr(trim((string) ($order->refund_note ?? '') . ' [paid after cancel]'), 0, 300)],
                            ['refund_note']));
                    } catch (\Throwable) {}
                    ShopOrderLog::add((int) $order->id, 'payment', '₦' . number_format($paid)
                        . ' arrived AFTER this order was cancelled. Refund it by hand from the gateway, or reopen the sale with the buyer.');
                    Notifier::adminAlert(null, 'Payment arrived for a cancelled shop order',
                        "Order:  {$reference}\nPaid:   ₦" . number_format($paid)
                        . "\n\nThis order had been cancelled before the payment completed. Nothing has been fulfilled. Refund the buyer from the gateway dashboard.");
                }
                return ['ok' => false, 'state' => 'mismatch',
                        'message' => 'This order was cancelled before your payment arrived. Our team has been told and will refund you.'];
            }

            if ($paid > $owed) {
                // Money we did not ask for is a conversation somebody has to have, not a
                // number to absorb silently.
                $log?->warning('[shop] OVERPAID — confirming anyway', [
                    'ref' => $reference, 'expected' => $owed, 'paid' => $paid,
                    'surplus' => $paid - $owed,
                ]);
            }

            // IDEMPOTENT: only the single writer that flips pending→paid fulfils. A concurrent
            // callback + webhook resolves to exactly one UPDATE affecting one row.
            $changed = DB::table('gates_orders')->where('reference', $reference)
                ->where('status', 'pending')
                ->update(OptionalColumn::filter('gates_orders', [
                    'status'       => 'paid',
                    'paid_at'      => Carbon::now()->toDateTimeString(),
                    'provider'     => $p,
                    'provider_ref' => $reference,
                ], ['provider', 'provider_ref']));

            // The gateway's own transaction id and reference — the numbers on the buyer's
            // receipt. Outside the `$changed` branch on purpose: the loser of a race still
            // learned them, and writing them is not a state transition. See PaymentLookup.
            PaymentLookup::remember('gates_orders', (int) $order->id, $v);

            if ($changed > 0) {
                self::fulfil((int) $order->id, $log);

                // ── AND THE REFERRER GETS PAID ───────────────────────────────
                //
                // Inside the `$changed` branch, so the single writer that flipped
                // pending→paid is the only one that credits. A concurrent callback and
                // webhook would otherwise both reach it — and while the unique index on
                // (source_type, source_id) would refuse the second, relying on a
                // constraint to catch what the control flow should is how the ONE case it
                // does not cover eventually pays twice.
                //
                // `subtotal_naira` and not the gateway's amount: commission is a share of
                // what we charged, not of an overpayment we are about to have a
                // conversation about.
                ReferralService::creditSale(
                    'shop_order',
                    (int) $order->id,
                    (string) ($order->referral_code ?? ''),
                    // Goods only: `subtotal_naira` is goods − discount + DELIVERY, and commission on
                    // the courier's fee paid a referrer 10% of a cost the platform passes on.
                    max(0, (int) $order->subtotal_naira - (int) ($order->shipping_naira ?? 0)),
                    null,
                    // A shop order has no account column, so the buyer is the address on
                    // it. Without this the shop paid commission on a member's own link.
                    null,
                    (string) ($order->email ?? ''),
                );

                return ['ok' => true, 'state' => 'confirmed', 'message' => 'Payment received.'];
            }
            return ['ok' => true, 'state' => 'already', 'message' => 'This order was confirmed a moment ago.'];
        }

        return ['ok' => false, 'state' => 'failed',
                'message' => 'The gateway does not show that payment as successful yet.'];
    }

    /**
     * The one-time side effects of a confirmed order.
     *
     * Re-read from the id rather than taking the caller's row, because the caller's copy was
     * fetched before the UPDATE and the receipt quotes `paid_at`.
     *
     * Never throws: every step past the stock draw-down is a convenience, and a failed receipt
     * must not be able to undo a confirmation.
     */
    public static function fulfil(int $orderId, ?LoggerInterface $log = null): void
    {
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$order) return;

        $lines = json_decode((string) $order->items_json, true) ?: [];

        // What was actually taken is written back onto the lines, so a later refund returns
        // THAT and not the quantity ordered. See restock(). The draw-down and that record
        // are ONE transaction: stock taken with the lines unrecorded meant a later refund put
        // back the full quantity and created units nobody had (AUDIT-AND-PLAN §2 fault 5).
        $short = [];
        try {
            $short = DB::connection()->transaction(static function () use (&$lines, $orderId): array {
                $s = self::drawDownStock($lines, $orderId);
                DB::table('gates_orders')->where('id', $orderId)
                    ->update(['items_json' => json_encode($lines, JSON_UNESCAPED_UNICODE)]);
                return $s;
            });
        } catch (\Throwable $e) {
            $log?->error('[shop] stock was not drawn down for a paid order', ['order' => $orderId, 'err' => $e->getMessage()]);
            Notifier::adminAlert(null, 'Shop stock not drawn down for a paid order',
                'Order #' . $orderId . ' (' . (string) $order->reference . ') is paid, but its stock could not be taken. Adjust stock by hand.');
        }

        // ── A SHORTFALL IS RECORDED, NOT FLOORED ─────────────────────────────
        //
        // Two buyers can pay for the last item inside the same payment window; checkout
        // refuses an oversell but cannot hold stock across a trip to the gateway. Flagged on
        // the row and said out loud in the alert, rather than clamping to zero in silence and
        // letting the seller find out when the buyer emails.
        if ($short !== []) {
            try {
                DB::table('gates_orders')->where('id', $orderId)
                    ->update(OptionalColumn::filter('gates_orders', ['stock_short' => 1], ['stock_short']));
            } catch (\Throwable) {}
            $log?->warning('[shop] paid order exceeds stock on hand', [
                'ref' => (string) $order->reference, 'items' => $short,
            ]);
        }

        // A collection order gets its code now, so the receipt below can quote it (ShopPickup).
        try { ShopPickup::issueCode($orderId); } catch (\Throwable) {}

        // Counted from PAID orders only — an abandoned checkout is not a sale and must not
        // move a product up the page.
        try { ShopCatalogue::countSales($lines); } catch (\Throwable) {}

        // Voting points for the member who placed the order (matched by email; idempotent per
        // reference). No-op when points are disabled or no account matches.
        try {
            if (PointsService::enabled()) {
                $uid = (int) (DB::table('gates_users')
                    ->where('email', strtolower((string) $order->email))
                    ->where('status', 'active')->value('id') ?? 0);
                if ($uid > 0) {
                    PointsService::earnFromPurchase($uid, (int) $order->subtotal_naira,
                                                    'shop_order', (string) $order->reference);
                }
            }
        } catch (\Throwable) {}

        // ── AND THE SLOW HALF GOES ON THE QUEUE ──────────────────────────────
        //
        // SMTP and the outbound integration fan-out both run inside a ~30-second gateway
        // budget when the webhook is what confirmed this order. Both are claimed once per
        // order, so whichever of {webhook, callback, sweep} lands second queues nothing and a
        // job that runs twice sends one email.
        try {
            (new QueueService())->push(self::JOB_RECEIPT, ['order_id' => $orderId],
                                       0, 'shop-receipt-' . $orderId);
        } catch (\Throwable $e) {
            $log?->error('[shop] could not queue the receipt, sending inline', ['err' => $e->getMessage()]);
            self::receipt($orderId, null);
        }

        WebhookService::dispatchLater('order.paid', [
            'reference'      => (string) $order->reference,
            'subtotal_naira' => (int) $order->subtotal_naira,
            'email'          => (string) $order->email,
            'items'          => $lines,
        ]);
    }

    /**
     * The buyer's receipt and the team's alert. Runs off the queue, so it may take as long as
     * SMTP takes.
     *
     * Claimed via `receipt_sent_at`, so a job delivered twice sends one email. The claim is a
     * conditional UPDATE for the same reason every other transition here is one.
     */
    public static function receipt(int $orderId, ?OtpService $mailer): void
    {
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$order || (string) ($order->status ?? '') !== 'paid') return;

        if (OptionalColumn::on('gates_orders', 'receipt_sent_at')) {
            $claimed = DB::table('gates_orders')->where('id', $orderId)
                ->whereNull('receipt_sent_at')
                ->update(['receipt_sent_at' => Carbon::now()->toDateTimeString()]);
            if ($claimed === 0) return;          // somebody else already sent it
        }

        $lines = json_decode((string) $order->items_json, true) ?: [];
        $summary = implode("\n", array_map(
            static fn ($l) => '  ' . ($l['name'] ?? '?')
                    . (($l['variant'] ?? '') !== '' ? ' (' . $l['variant'] . ')' : '')
                    . ' ×' . ($l['qty'] ?? 0) . ' — ₦' . number_format((int) ($l['line_total'] ?? 0)),
            $lines
        ));
        $total = '₦' . number_format((int) $order->subtotal_naira);
        // A collection order: where, and the code the buyer will be asked for.
        $collect = '';
        if ((int) ($order->pickup_point_id ?? 0) > 0) {
            $pp = ShopPickup::point((int) $order->pickup_point_id);
            $collect = '<p>You chose to collect this order' . ($pp ? ' from <strong>' . htmlspecialchars((string) $pp->name) . '</strong>' : '')
                . '. We will email you when it is ready. Your collection code is <strong style="font-family:monospace">'
                . htmlspecialchars((string) ($order->pickup_code ?? '')) . '</strong> — keep it; you will be asked for it.</p>';
        }
        $base  = rtrim(\AfricaGates\Support\SiteUrl::base(), '/');
        $short = (int) ($order->stock_short ?? 0) === 1;

        if ($mailer) {
            try {
                $mailer->sendBranded(
                    (string) $order->email,
                    'Your Africa GATES order is confirmed',
                    '<p>Thank you, ' . htmlspecialchars((string) $order->name)
                    . ' — your order is confirmed and being prepared.</p>'
                    . '<p style="font-family:monospace">Order '
                    . htmlspecialchars((string) $order->reference) . '</p>'
                    . $collect
                    . "<p>Total paid: <strong>{$total}</strong>. Every purchase funds child "
                    . 'leadership programmes — thank you.</p>'
                    // The order's own page, reachable with the reference alone. Without it a
                    // buyer who closed the tab had nowhere to check whether it shipped.
                    . '<p style="text-align:center;margin:22px 0"><a href="'
                    . $base . '/shop/order/' . rawurlencode((string) $order->reference) . '"'
                    . ' style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;'
                    . 'border-radius:999px;font-weight:600;text-decoration:none">Track this order →</a></p>',
                    'Shop'
                );
            } catch (\Throwable) { /* a receipt failure must not undo a confirmation */ }
        }

        $breakdown = '';
        if (($order->shipping_naira ?? null) !== null || ($order->discount_naira ?? null) !== null) {
            $breakdown = "\nGoods:    ₦" . number_format((int) ($order->goods_naira ?? 0))
                . ((int) ($order->discount_naira ?? 0) > 0
                    ? "\nDiscount: −₦" . number_format((int) $order->discount_naira)
                      . ' (' . (string) ($order->discount_code ?? '') . ')' : '')
                . "\nDelivery: ₦" . number_format((int) ($order->shipping_naira ?? 0));
        }

        Notifier::adminAlert($mailer,
            ($short ? 'Shop order paid — NOT ENOUGH STOCK' : 'New shop order (paid)'),
            "Order:   {$order->reference}\nBy:      {$order->name} <{$order->email}> · "
            . (string) ($order->phone ?? '')
            . "\nShip to: " . (string) $order->address . $breakdown . "\nTotal:   {$total}"
            . "\n\nItems:\n{$summary}"
            . ($short
                ? "\n\nSTOCK SHORTFALL — this order is paid but cannot be filled from stock on hand."
                  . "\nContact the buyer before it becomes a complaint."
                : ''));
    }

    /**
     * The money went back: a refund settled, or a bank pulled it in a chargeback.
     *
     * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────
     *
     * Reversal webhooks only ever reached `gates_donations`, so a refunded shop order stayed
     * `paid` forever: stock stayed decremented, the sale kept counting towards "most bought",
     * and the buyer kept the loyalty points the purchase awarded. The seller's own records
     * said they had sold something they had been paid nothing for.
     *
     * Stock IS returned. That is the arguable half — a chargeback on a shipped parcel does not
     * bring the parcel back — so the restock is reported in the alert rather than done
     * quietly, and it only happens for an order that has not been marked fulfilled.
     *
     * Idempotent by status: only a `paid` row can be reversed, so a duplicate
     * `refund.processed` delivery does the work once.
     *
     * @return bool whether this call was the one that reversed it
     */
    public static function reverse(string $reference, string $why, ?LoggerInterface $log = null): bool
    {
        $order = DB::table('gates_orders')->where('reference', trim($reference))->first();
        if (!$order || (string) ($order->status ?? '') !== 'paid') return false;

        // Restocked only when the goods have not left. `fulfilled` is the word the first
        // version of this check used; the console has only ever written shipped, delivered and
        // (since phase 4) collected, so a chargeback on a DELIVERED parcel used to put the
        // units back on a shelf they were not on.
        $unshipped = !self::hasLeft($order);
        return self::settleInFull((int) $order->id, $why, $unshipped ? null : [], $log);
    }

    /** Has this order physically left us — shipped, delivered or collected? */
    public static function hasLeft(object $order): bool
    {
        return in_array((string) ($order->fulfilment ?? ''), ['fulfilled', 'shipped', 'delivered', 'collected'], true);
    }

    /**
     * The order's money has all gone back. One implementation for a refund settled from the
     * console ({@see ShopRefunds}), a refund started on the gateway's dashboard, and a
     * chargeback ({@see reverse()}).
     *
     * `$restock`: null puts back everything still out (what was drawn, less what an earlier
     * partial refund already returned); a map of line index → units puts back exactly that;
     * [] puts back nothing.
     *
     * Idempotent by status: only a `paid` row moves, so a duplicate delivery does the work once.
     *
     * @param array<int,int>|null $restock
     * @return bool whether this call was the one that settled it
     */
    public static function settleInFull(int $orderId, string $why, ?array $restock, ?LoggerInterface $log = null): bool
    {
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$order || (string) ($order->status ?? '') !== 'paid') return false;

        // The status change and the stock coming back are ONE transaction: refunded with the
        // stock never returned, or returned on an order a crash left `paid`, are both a
        // count that no longer matches the shelf (AUDIT-AND-PLAN §2 fault 5).
        $returned = [];
        $changed = DB::connection()->transaction(static function () use ($order, $why, $restock, &$returned): int {
            $n = DB::table('gates_orders')->where('id', (int) $order->id)->where('status', 'paid')
                ->update(OptionalColumn::filter('gates_orders', [
                    'status'      => 'refunded',
                    'refunded_at' => Carbon::now()->toDateTimeString(),
                    'refund_note' => mb_substr($why, 0, 300),
                ], ['refunded_at', 'refund_note']));
            if ($n > 0 && $restock !== []) {
                $returned = self::restock((int) $order->id, $restock, 'refund', 'Refunded: ' . $why);
            }
            return $n;
        });
        if ($changed === 0) return false;

        // And the referrer's commission on it. Same reasoning as the points: the purchase
        // that earned it no longer exists. Never throws — see ReferralService::reverseSale().
        ReferralService::reverseSale('shop_order', (int) $order->id, 'order refunded: ' . $why);

        // Take back the points the purchase awarded. Best effort and never negative — see
        // PointsService::reverseFromPurchase().
        try {
            $uid = (int) (DB::table('gates_users')
                ->where('email', strtolower((string) $order->email))
                ->where('status', 'active')->value('id') ?? 0);
            if ($uid > 0) {
                PointsService::reverseFromPurchase($uid, 'shop_order', (string) $order->reference);
            }
        } catch (\Throwable) {}

        $log?->warning('[shop] order reversed', [
            'ref' => (string) $order->reference, 'why' => $why, 'restocked' => $returned !== [],
        ]);
        ShopOrderLog::add((int) $order->id, 'refund', 'All of the money is back with the buyer (' . $why . ').'
            . ($returned !== [] ? ' Returned to stock: ' . implode(', ', $returned) . '.' : ' Nothing was returned to stock.'));

        Notifier::adminAlert(null, 'Shop order reversed — ' . $why,
            "Order:  {$order->reference}\nBy:     {$order->name} <{$order->email}>\n"
            . 'Amount: ₦' . number_format((int) $order->subtotal_naira) . "\nReason: {$why}\n\n"
            . ($returned !== []
                ? 'Returned to stock: ' . implode(', ', $returned) . "\n"
                : "STOCK WAS NOT RETURNED — either the goods had already left, or whoever refunded "
                  . "it chose not to. If they are not on the shelf, this is a loss to write off.\n")
            . 'Points awarded for this order have been taken back where the balance allowed.');

        return true;
    }

    /**
     * Put units of a paid order back on the shelf, never more than it actually took.
     *
     * `$qty` maps a line's index to the units to return; null means "everything still out".
     * Each line remembers what has already come back (`restocked`), so a partial refund that
     * returned one shirt and a later chargeback cannot return it twice — the fault that once
     * minted units nobody had (see drawDownStock()).
     *
     * @param array<int,int>|null $qty
     * @return list<string> what went back, in words
     */
    public static function restock(int $orderId, ?array $qty, string $reason = 'refund', string $note = '', ?int $adminId = null): array
    {
        $order = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$order) return [];
        $lines = json_decode((string) $order->items_json, true) ?: [];
        $said  = [];

        foreach ($lines as $i => $l) {
            $drawn = array_key_exists('drawn', $l) ? (int) $l['drawn'] : (int) ($l['qty'] ?? 0);
            $out   = max(0, $drawn - (int) ($l['restocked'] ?? 0));
            $want  = $qty === null ? $out : min($out, max(0, (int) ($qty[$i] ?? 0)));
            if ($want < 1 || (string) ($l['slug'] ?? '') === '') continue;

            if (ShopStock::move((string) $l['slug'], (int) ($l['variant_id'] ?? 0), $want, $reason,
                                $note, $orderId, $adminId)) {
                $lines[$i]['restocked'] = (int) ($l['restocked'] ?? 0) + $want;
                $said[] = $want . ' × ' . (string) ($l['name'] ?? $l['slug'])
                        . (($l['variant'] ?? '') !== '' ? ' (' . $l['variant'] . ')' : '');
            }
        }
        if ($said !== []) {
            DB::table('gates_orders')->where('id', $orderId)
                ->update(['items_json' => json_encode($lines, JSON_UNESCAPED_UNICODE)]);
        }
        return $said;
    }

    // ── stock ────────────────────────────────────────────────────────────────

    /**
     * Take the sold units out of stock, and report anything that could not be taken.
     *
     * Two bound queries rather than one raw CASE, so no request data and no integer is ever
     * concatenated into SQL. The count is read BEFORE the decrement, so the shortfall is the
     * actual gap rather than one derived from a number the decrement has already changed.
     *
     * A variant carries its own stock, and where one exists the product's column is not the
     * truth — a shirt is four in medium and none in large, not twelve. The reconciler's old
     * copy of this loop had no variant branch at all, so a reconciled order for any variant
     * product drew its stock from nowhere.
     *
     * ── AND WHAT WAS ACTUALLY TAKEN IS RECORDED ON THE LINE ─────────────────
     *
     * An oversold line is clamped to zero, so it draws only what was on hand — three of a
     * five-unit order, say. `drawn` is written onto each tracked line (by reference, then
     * persisted by fulfil()) because the old returnStock() gave back the full `qty`: a
     * refunded oversold order then CREATED two units nobody had, and the next buyer paid
     * for a shirt that was never on the shelf. The count is the gap the conditional
     * statements actually closed, not the read before them.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<string> human sentences naming what fell short
     */
    private static function drawDownStock(array &$lines, int $orderId = 0): array
    {
        $short = [];

        foreach ($lines as $i => $l) {
            $slug = (string) ($l['slug'] ?? '');
            $qty  = (int) ($l['qty'] ?? 0);
            $vid  = (int) ($l['variant_id'] ?? 0);
            if ($slug === '' || $qty < 1) continue;

            $what = (string) ($l['name'] ?? $slug)
                  . (($l['variant'] ?? '') !== '' ? ' (' . $l['variant'] . ')' : '');

            $q = static fn () => $vid > 0
                ? DB::table('gates_product_variants')->where('id', $vid)
                : DB::table('gates_products')->where('slug', $slug);

            $have = $q()->value('stock');
            // Untracked: nothing to draw, and recorded as nothing drawn, so switching stock
            // tracking on later cannot make a refund of this order mint units.
            if ($have === null) { $lines[$i]['drawn'] = 0; continue; }
            if ((int) $have < $qty) {
                $short[] = $what . ' — ordered ' . $qty . ', ' . (int) $have . ' on hand';
            }

            // The whole quantity when it is there. Otherwise whatever is left, taken by a
            // compare-and-set on the value just read, so a concurrent order cannot be
            // counted as drawn by both — retried a few times, then recorded as nothing drawn
            // rather than guessed.
            $drawn = 0;
            if ($q()->whereNotNull('stock')->where('stock', '>=', $qty)->decrement('stock', $qty) > 0) {
                $drawn = $qty;
            } else {
                for ($try = 0; $try < 3; $try++) {
                    $left = $q()->value('stock');
                    if ($left === null || (int) $left <= 0) break;
                    if ((int) $left >= $qty) {
                        if ($q()->where('stock', '>=', $qty)->decrement('stock', $qty) > 0) { $drawn = $qty; break; }
                        continue;
                    }
                    if ($q()->where('stock', (int) $left)->update(['stock' => 0]) > 0) {
                        $drawn = (int) $left;
                        break;
                    }
                }
            }
            $lines[$i]['drawn'] = $drawn;
            // And the ledger's half of it, inside the same transaction as the draw-down, so the
            // history and the count cannot disagree about a sale (ShopStock).
            if ($drawn > 0) ShopStock::recordLine($slug, $vid, -$drawn, 'sale', $orderId);
        }

        return $short;
    }

    /**
     * Everything is priced and charged in naira. A gateway reporting success in another
     * currency has not paid THIS order, whatever the number says — ₦5,000 and $5,000 are the
     * same integer and three orders of magnitude apart.
     */
    private static function currencyAgrees(array $v): bool
    {
        $c = strtoupper(trim((string) ($v['currency'] ?? '')));
        return $c === '' || $c === 'NGN';       // '' = a gateway that does not report one
    }
}
