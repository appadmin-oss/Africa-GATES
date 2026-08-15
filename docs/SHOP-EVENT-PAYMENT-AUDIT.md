# Shop & Event Payments — Audit Against the Paystack Reference

*Audited 2026-08-15 against the two attached references ("Paystack API Integration for PHP:
The Complete Reference" and "Paystack Deep Dive: Every Webhook Scenario + Known Issues &
Pitfalls"). Scope: the shop checkout (`/shop/*`) and event ticketing (`/events/*`) money
paths, plus the shared plumbing they depend on.*

Section references in square brackets — `[A9]`, `[§5]` — point at the attached documents.

---

## The one-paragraph version

The shared payment plumbing is in good shape and, in several places, ahead of the
reference: signature verification, kobo conversion, idempotent state transitions, the
16-hour dispute clock, the dispute evidence flow and the reconciliation backstop are all
implemented correctly. The problem is **coverage, not correctness**. `/pay/webhook` is the
only inbound webhook endpoint, and it resolves references against `gates_donations` alone.
The shop survives that because the cron reconciler sweeps `gates_orders`. **Event tickets
are covered by nothing at all** — no webhook, no reconciler, no admin repair action, no
support lookup — and the one screen that could have surfaced the resulting orphaned
payments is hardcoded to report event registrations as settled.

---

## What is already right

Stated first because a list of findings alone misrepresents the state of this code.

| Reference guidance | Where it is met |
|---|---|
| HMAC-SHA512 over the **raw** body, `hash_equals` [§5, A10] | `PaymentController::verifyWebhookSignature()` — `$body->rewind(); $raw = $body->getContents();` then `hash_equals()`. `PaymentController.php:332-345`, `:597-615` |
| Webhook route exempt from CSRF [A10, B2] | `CsrfMiddleware::OTP_EXEMPT` includes `/pay/webhook`. `CsrfMiddleware.php:42` |
| Never trust the callback; always verify server-side [§Key Findings 3] | Both shop and event callbacks re-verify. `ShopCheckoutController.php:405`, `EventsController.php:460` |
| Idempotent "mark as paid", one reference confirms once [A9] | Conditional `where('status','pending')->update(...)` in all three paths. `ShopCheckoutController.php:419`, `EventTicketService.php:571`, `PaymentReconciler.php:288` |
| Amounts in kobo on the wire, naira at the boundary [§2] | Converted in exactly two places, per provider. `PaymentService.php:472`, `:579` |
| Transaction IDs stored wide enough for unsigned 64-bit [B6] | `gateway_txn_id VARCHAR(64)`, not an int column. `2026_08_26_gateway_reference.php` |
| TLS verified, never `VERIFY_PEER = false` [§1] | `PaymentService::request()` sets `CURLOPT_SSL_VERIFYPEER => true`, `VERIFYHOST => 2`. `PaymentService.php:812` |
| Reconciliation job as a backstop, not webhook-only state [A9, B1] | `PaymentReconciler`, newest-first with an age ceiling and a written-off recovery pass. Scheduled via `Maintenance::run()` → `payments`. |
| Dispute 16-hour SLA is a notification, not a log line [A6] | `DisputeAlert::queue()`, fired even for a dispute with no matching row. `PaymentController.php:382-387` |
| Dispute evidence: 30-minute signed URL, empty body on success, `.jpg/.jpeg/.pdf` only [A6] | `PaymentService::disputeUploadUrl()`, `putSignedFile()` (judges HTTP status, sends no `Authorization`). `PaymentService.php:924-998` |
| Refund step-events are not outcomes [A8] | `webhookReversalKind()` explicitly excludes `refund.failed`, `refund.pending`, `refund.processing`, `dispute.remind`. `PaymentController.php:663-665` |
| Heavy work off the webhook thread [A10] | `WebhookService::dispatchLater()`, `CheckoutMailer::queueReceipt()`, guarded by `PaystackWebhookComplianceTest`. |

---

## Findings

### 1 · CRITICAL — an event ticket paid for outside the browser is never issued, and nothing anywhere will find it

`EventTicketService::confirm()` has exactly one call site in the codebase:
`EventsController::callback()` (`EventsController.php:460`), which runs only when the
buyer's browser returns from the gateway.

Every other recovery route that exists for the other money paths is absent for events:

- **No webhook path.** `/pay/webhook` looks the reference up in `gates_donations` only
  (`PaymentController.php:398`). An `AFG-EVT-…` reference matches nothing, so the handler
  falls through to `return $res->withStatus(200)` and the event is discarded as "a
  non-charge event". Paystack is told everything is fine.
- **No reconciler path.** `PaymentReconciler::run()` merges `orders()`, `donations()` and
  `recoverWrittenOff()` (`PaymentReconciler.php:177-183`). There is no `registrations()`.
- **No admin repair.** `Admin\Controllers\EventsController` offers `checkIn`, `releaseSeat`,
  `promote` and `exportAttendees` — nothing that confirms a paid registration.
- **No support lookup.** See finding 6.

The reference is explicit that this is the common case, not the edge case: *"bank transfers
happen from external sources and Paystack only gets notified after completion, so webhooks
are the only way to know a payment happened"* [A3], and the callback-only failure mode is
named as the likely root of the "debited but not credited" merchant complaints [B4].
This codebase already knows it — `PaymentReconciler::reclaim()`'s own docblock describes
paying inside a wallet app as the reported incident.

The outcome for a ticket buyer: money taken by Paystack, registration stuck at `pending`,
the hold silently ages out of the seat arithmetic (`EventTicketService::sold()` treats an
expired hold as not-sold), and the seat is resold. No ticket, no refund, no record anybody
looks at.

### 2 · HIGH — one webhook endpoint, one ledger; the other two streams are silently acknowledged

Paystack permits exactly one webhook URL per account, so *"your handler must dispatch
internally on the `event` field"* [B2] — and, here, on the reference stream too. The
handler does not: `webhookReference()` extracts the reference and then queries one table.

This is the root cause of finding 1, and it is also why the shop's webhook coverage is
accidental rather than designed — the shop is saved only by the cron reconciler, which
means shop confirmation latency is the cron interval plus `run()`'s 15-minute grace,
where a webhook would be seconds.

The routing primitive already exists and is already used at initialise time:
`PaymentDestination::streamForReference()` maps `AFG-EVT…` → `events`, `AFG-SHP…` → `shop`,
`AFG-…` → `votes` (`PaymentDestination.php:258-260`). The webhook handler should dispatch
through it.

### 3 · HIGH — the shop refuses overpayment on strict equality; the rest of the platform does not

```php
// ShopCheckoutController.php:414
if ((int)$v['amount'] !== (int)$order->subtotal_naira) {
```

`PaymentController::confirmByReference()` was deliberately changed away from this and
carries a long comment explaining why (`PaymentController.php:449-471`): turning on
"customer bears the transaction fee" in the Paystack dashboard adds the fee to every
charged amount, so *every* payment arrives a few hundred naira over. One dashboard toggle,
total outage, no code change. `PaymentReconciler::orders()` uses `<` too
(`PaymentReconciler.php:270`).

So the shop is the only path still holding the version that was fixed elsewhere. The
practical effect today is not a permanent loss — the row stays `pending` and the cron
reconciler confirms it later on the correct rule — but the buyer is redirected to
`/shop?checkout=failed`, sees a failure for a payment that succeeded, and the order is only
fulfilled at the next sweep.

**Also on that same line: the shop path never checks the currency.** `PaymentController`
(`:465`) and `PaymentReconciler` (`:270`) both call `currencyAgrees()`; the shop does not.

### 4 · HIGH — refunds and chargebacks never reach a shop order or an event ticket

`webhookReversalKind()` correctly identifies a conclusive reversal, then
`PaymentController.php:360` does `DB::table('gates_donations')->where('payment_ref', $ref)`
and `BonusVoteService::clawbackDonation()`. `RefundService` operates on `gates_donations`
exclusively; `DisputeAlert::alertFor()` and `DisputeEvidence` likewise.

Consequences within scope:

- A refunded **shop order** stays `status = 'paid'`, stock stays decremented, `countSales()`
  keeps counting it, and the buyer keeps the loyalty points awarded by
  `PointsService::earnFromPurchase()` (`ShopCheckoutController.php:514-519`).
- A charged-back **event ticket** stays `confirmed` and its `ticket_code` still renders a
  scannable QR (`EventsController.php:531`). The QR opens a door for a ticket the bank has
  already reversed.

The dispute *alert* does fire for both (it is deliberately outside the `$d` guard) — so a
human is told. Nothing in the data changes.

### 5 · MEDIUM — the gateway ledger reports every event registration as settled, whatever its status

```php
// GatewayLedger.php:287-296  (findLocal)
if ($schema->hasTable('gates_event_registrations')) {
    $r = DB::table('gates_event_registrations')->where('reference', $ref)->first();
    if ($r) {
        return [
            ...
            'status'  => 'registered',   // ← not $r->status
            'settled' => true,           // ← unconditional
```

`disagreements()` opens with `if (!$ours['settled'])` → *"Paystack took ₦X but our row is
still …"* (`GatewayLedger.php:321`). Because `settled` is hardcoded `true`, that sentence
can never be produced for an event registration.

This is the screen whose entire purpose is finding money at Paystack that this side has not
honoured — and it is the screen that would have caught finding 1. As written, an orphaned
`pending` ticket payment is filed under **agreed**.

`localConfirmed()` (`GatewayLedger.php:350-392`) also omits `gates_event_registrations`
entirely, so the opposite direction — a ticket we call confirmed that Paystack's list does
not contain — is not checked either.

### 6 · MEDIUM — support cannot find a ticket by any number the buyer holds

`PaymentLookup::REF_COLUMN` covers `gates_donations` and `gates_orders` only
(`PaymentLookup.php:183`), and `PREFIXES` lists `AFG-PVOTE-`, `AFG-GIVE-`, `AFG-SHP-`,
`AFG-` — no `AFG-EVT-` (`PaymentLookup.php:66`). Its own docblock says a missing prefix
means *"a real supporter's half-pasted reference goes unfound"*.

Compounding it: `2026_08_26_gateway_reference.php` adds `gateway_txn_id` / `gateway_ref` to
`gates_donations` and `gates_orders` but not to `gates_event_registrations`, so the
gateway's own identifiers — *the numbers on the buyer's Paystack receipt* [§7] — are never
captured for a ticket even when confirmation does succeed.

Net: a ticket buyer with a problem can paste their `AFG-EVT-…` reference, their Paystack
transaction id, or their bank's number, and all three find nothing.

### 7 · MEDIUM — inbound webhook deliveries are not logged

The reference asks for this twice: *"log every event ID/reference before processing"* [A9],
*"log everything received"* [B2]. `gates_webhook_deliveries` logs **outbound** deliveries
only (`WebhookService::deliver()`).

There is no record that a Paystack delivery arrived, what event it carried, or how it was
dispatched. Given B1 documents an acknowledged incident of degraded webhook delivery, the
question "did Paystack send it and we dropped it, or was it never sent?" is currently
unanswerable from this side.

### 8 · LOW — assorted

| # | Finding | Location |
|---|---|---|
| 8a | `EventTicketService::releaseExpired()` has no caller anywhere. Seat arithmetic already discounts expired holds, so nothing is broken — but the tidy-up the docblock promises never runs, and `pending` rows accumulate. | `EventTicketService.php:749` |
| 8b | `verify()` maps Paystack `reversed` → `pending` alongside `abandoned`. A reversed transaction therefore sits in the reconciler queue being re-verified until the 3-day expiry writes it off, rather than being recognised as conclusive [A5, A8]. | `PaymentService.php:577` |
| 8c | The `domain` field (`"test"` / `"live"`) on webhook bodies is never inspected [A1]. Low risk while the environment holds one key, but it is the documented discriminator for a shared endpoint. | `PaymentController::webhook()` |
| 8d | Paystack's three webhook source IPs (52.31.139.75, 52.49.173.169, 52.214.14.220) are not allowlisted. Optional — signature verification is the primary control [§5, B3]. | — |
| 8e | If a webhook path is added for the shop (finding 2), `ShopCheckoutController::fulfil()` currently calls `sendBranded()` and `WebhookService::dispatch()` **inline** — precisely the ~30-second-budget violation `PaystackWebhookComplianceTest` guards against on the donations path. Fix before wiring, not after. | `ShopCheckoutController.php:464`, `:505` |
| 8f | Event references carry 40 bits of entropy (`bin2hex(random_bytes(5))`) and are the sole bearer token for the ticket page and its calendar file; the shop uses 48. Both pages are `noindex` and neither is rate-limited. | `EventTicketService.php:440` |
| 8g | `bank.transfer.rejected` [A1] and DVA `charge.success` with `channel: "dedicated_nuban"` [A3] are unhandled. Not applicable unless Pay-with-Transfer or Dedicated Virtual Accounts are enabled on the account — worth confirming in Preferences. | — |

---

## Recommended order of work

1. **Route the webhook by reference stream** (finding 2). One dispatch in
   `PaymentController::webhook()` through `PaymentDestination::streamForReference()`, into
   `EventTicketService::confirm()` and the shop's confirm path. Closes finding 1 for new
   payments. Do 8e first.
2. **Add `registrations()` to `PaymentReconciler`** (finding 1). The webhook is the fast
   path; the sweep is the backstop the reference insists on [A9] — and it is the only thing
   that recovers the payments already stranded.
3. **Fix `GatewayLedger::findLocal()`** (finding 5) — read the real status, and derive
   `settled` from `status === 'confirmed'`. Two lines, and it turns the ledger back into the
   instrument that finds the stranded ones.
4. **Align the shop's amount check** with `PaymentController` — `<` plus `currencyAgrees()`
   (finding 3).
5. **Extend `PaymentLookup`** to `gates_event_registrations` + `AFG-EVT-`, and add the
   gateway-id columns to that table (finding 6).
6. **Log inbound deliveries** (finding 7), then extend reversal handling to orders and
   registrations (finding 4).

---

## Operational items from Part B, for whoever owns the Paystack account

Not code — but they belong in the same review.

- **Confirm the webhook URL and the signing key** in Settings → API Keys & Webhooks match
  this deployment, and remember the trailing slash if `.htaccess` rewrites are in play
  [A10]. A silently misconfigured URL is indistinguishable from finding 1 from the outside.
- **Subscribe to `status.paystack.com`** [B1]. The reference documents a webhook-delivery
  incident; a prolonged `pending` is a cue to check the status page before debugging here.
- **Re-check the API changelog periodically** [B6] — the `reference`-required change on
  Initiate Transfer is coming, and this platform's transfer surface (refunds are not
  transfers, but payouts may be added) would be affected.
- **Rate limits** [§2] are not a current risk: the reconciler caps at 200 rows per table
  per run against a 3,000/60s verify allowance.
