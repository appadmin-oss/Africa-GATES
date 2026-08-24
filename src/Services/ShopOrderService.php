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
                    (int) $order->subtotal_naira,
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
        $short = self::drawDownStock($lines);

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

        $changed = DB::table('gates_orders')->where('id', (int) $order->id)->where('status', 'paid')
            ->update(OptionalColumn::filter('gates_orders', [
                'status'      => 'refunded',
                'refunded_at' => Carbon::now()->toDateTimeString(),
                'refund_note' => mb_substr($why, 0, 300),
            ], ['refunded_at', 'refund_note']));
        if ($changed === 0) return false;

        $lines     = json_decode((string) $order->items_json, true) ?: [];
        $unshipped = (string) ($order->fulfilment ?? 'unfulfilled') !== 'fulfilled';
        if ($unshipped) {
            self::returnStock($lines);
        }

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
            'ref' => (string) $order->reference, 'why' => $why, 'restocked' => $unshipped,
        ]);

        Notifier::adminAlert(null, 'Shop order reversed — ' . $why,
            "Order:  {$order->reference}\nBy:     {$order->name} <{$order->email}>\n"
            . 'Amount: ₦' . number_format((int) $order->subtotal_naira) . "\nReason: {$why}\n\n"
            . ($unshipped
                ? "Stock has been returned — this order had not been marked fulfilled.\n"
                : "STOCK WAS NOT RETURNED — this order was already marked fulfilled, so the "
                  . "goods have left. This is a loss to write off, not a stock correction.\n")
            . 'Points awarded for this order have been taken back where the balance allowed.');

        return true;
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
     * @param list<array<string,mixed>> $lines
     * @return list<string> human sentences naming what fell short
     */
    private static function drawDownStock(array $lines): array
    {
        $short = [];

        foreach ($lines as $l) {
            $slug = (string) ($l['slug'] ?? '');
            $qty  = (int) ($l['qty'] ?? 0);
            $vid  = (int) ($l['variant_id'] ?? 0);
            if ($slug === '' || $qty < 1) continue;

            $what = (string) ($l['name'] ?? $slug)
                  . (($l['variant'] ?? '') !== '' ? ' (' . $l['variant'] . ')' : '');

            if ($vid > 0) {
                $have = DB::table('gates_product_variants')->where('id', $vid)->value('stock');
                if ($have === null) continue;                      // untracked: nothing to draw
                if ((int) $have < $qty) {
                    $short[] = $what . ' — ordered ' . $qty . ', ' . (int) $have . ' on hand';
                }
                DB::table('gates_product_variants')->where('id', $vid)->whereNotNull('stock')
                    ->where('stock', '>=', $qty)->decrement('stock', $qty);
                DB::table('gates_product_variants')->where('id', $vid)->whereNotNull('stock')
                    ->where('stock', '<', $qty)->update(['stock' => 0]);
                continue;
            }

            $have = DB::table('gates_products')->where('slug', $slug)->value('stock');
            if ($have === null) continue;
            if ((int) $have < $qty) {
                $short[] = $what . ' — ordered ' . $qty . ', ' . (int) $have . ' on hand';
            }
            DB::table('gates_products')->where('slug', $slug)->whereNotNull('stock')
                ->where('stock', '>=', $qty)->decrement('stock', $qty);
            DB::table('gates_products')->where('slug', $slug)->whereNotNull('stock')
                ->where('stock', '<', $qty)->update(['stock' => 0]);
        }

        return $short;
    }

    /** Put the units back. Only ever called for an order that had not shipped. */
    private static function returnStock(array $lines): void
    {
        foreach ($lines as $l) {
            $slug = (string) ($l['slug'] ?? '');
            $qty  = (int) ($l['qty'] ?? 0);
            $vid  = (int) ($l['variant_id'] ?? 0);
            if ($slug === '' || $qty < 1) continue;

            try {
                if ($vid > 0) {
                    DB::table('gates_product_variants')->where('id', $vid)
                        ->whereNotNull('stock')->increment('stock', $qty);
                    continue;
                }
                DB::table('gates_products')->where('slug', $slug)
                    ->whereNotNull('stock')->increment('stock', $qty);
            } catch (\Throwable) { /* a stock correction must not throw out of a webhook */ }
        }
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
