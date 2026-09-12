# Shop & Event Payments — Audit Against the Paystack Reference

*Audited 2026-08-15 against the two attached references ("Paystack API Integration for PHP:
The Complete Reference" and "Paystack Deep Dive: Every Webhook Scenario + Known Issues &
Pitfalls"). Scope: the shop checkout (`/shop/*`) and event ticketing (`/events/*`) money
paths, plus the shared plumbing they depend on.*

Section references in square brackets — `[A9]`, `[§5]` — point at the attached documents.

> **STATUS: all findings remediated.** Every finding below has been fixed; each carries a
> **Fixed** line naming what changed. A further defect — the one actually reported, where shop
> and event payments stopped working once subaccounts were configured — was found while fixing
> these and is written up as **finding 0**. See *Remediation* at the end for the full list of
> files and the test coverage added.

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

### 0 · CRITICAL — a subaccount Paystack refuses takes a whole revenue stream offline, silently

*Not in the original audit. Found while fixing the rest, and it is the failure that was
actually reported: "the payments for the events and shop is not working with the subaccounts."*

`PaymentDestination`'s own docblock states the rule — *"A MALFORMED CODE IS REFUSED, NOT SENT
… Refusing to route is recoverable; refusing to sell is not"* — and the code did not implement
it. `code()` validates the **shape** of what an admin typed (`PaymentDestination.php:132-168`).
That catches a pasted bank account number and a bank's name. It cannot catch the failure that
actually takes a stream down: a well-formed `ACCT_…` code belonging to a **different Paystack
integration**, one that has been **deleted**, or one that was **never activated**. Paystack
refuses all three at `POST /transaction/initialize`, and `initializePaystack()` returned that
refusal straight to the buyer.

The shape of the outage matches the report exactly:

- It is **per stream**. Configure subaccounts for `shop` and `events`, leave `votes` on the main
  account, and the two configured streams die while the third — the one most likely to be
  tested — works perfectly.
- It is **silent**. The buyer sees "we could not start the payment". The operator sees nothing:
  the only trace was `$this->log?->warning(...)` on a host with no shell.
- It arrived with commit `c3e5ffc`, three commits before this audit.

**Fixed** — three changes, in order of how much they matter:

1. `PaymentService::initializePaystack()` now **retries once without the routing** when an
   initialise carrying a subaccount fails. The sale completes; the money lands in the main
   account, which is where it landed before anybody configured a subaccount. The attribution
   row is deleted so the platform's records do not claim a settlement the bank will never show.
2. The fallback is **reported, not swallowed**: `PaymentDestination::reportRefusal()` records it
   against the stream and emails the team (at most hourly per stream), and the settings screen
   shows it beside the offending field with Paystack's own words. A silent fallback that
   redirects a revenue stream for a month is its own kind of bad.
3. `PaymentService::subaccount()` asks Paystack whether a code exists on **this** integration —
   and whether it is active — at the moment somebody presses Save. A bad code is now refused on
   the form, quoting Paystack, and a good one is confirmed back with the business name it
   belongs to. An unreachable gateway is treated as "could not check", never as "bad code".

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

**Fixed** — three ways in, where there were none:
* `PaymentReconciler::registrations()` sweeps pending priced registrations, asking the gateway
  before it writes anything off — so a transfer settling on day three is *confirmed*, not
  expired.
* The webhook confirms them directly (finding 2), and queues the ticket email, because a
  confirmed ticket whose owner was never told is only marginally better than one never issued.
* `EventTicketMailer` was extracted from `EventsController::announce()` — private, and needing
  a `Request`, so only the browser callback could ever reach it. Claimed on `notified_at`, so
  the callback, the webhook and the sweep can all race and exactly one email is sent.

`EventTicketService::releaseExpired()` — which had no caller at all — is now wired into cron
**and refuses to touch a priced hold**. Its thirty-minute window is far shorter than a Nigerian
bank transfer takes, and `cancel()` puts a row beyond the reach of `confirm()` forever; a
sweeper expiring priced holds on the clock alone would destroy the only row that could have
issued a paid ticket. Priced rows belong to the reconciler, which asks first.

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

**Fixed** — `PaymentController::webhook()` now hands off to `handleWebhook()`, which routes by
`PaymentDestination::streamForReference()` into `ShopOrderService::confirm()`,
`EventTicketService::confirm()`, or the donations path. The handler also answers 200 on an
internal exception rather than 500: a 5xx puts the delivery into a 72-hour retry schedule
against code that has just proved it throws, and the sweep will pick the payment up anyway.

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

**Fixed** — confirmation moved to `ShopOrderService::confirm()`, which uses `<` plus
`currencyAgrees()`, exactly like the other two paths. A mismatch is no longer worded as a
failure either: the callback redirects to `?checkout=mismatch`, because telling somebody who
has been debited that their payment failed is how a support ticket becomes a chargeback.

The same move fixed an unrelated divergence nobody had noticed: `PaymentReconciler` had grown
its **own** `fulfilOrder()` which decremented `gates_products.stock` by slug and nothing else —
so a reconciled order for any product **with variants** drew stock from no column at all, never
counted the sale, never awarded the buyer's points and never fired `order.paid`. One
implementation now serves the callback, the webhook and the sweep.

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

**Fixed** — reversals route by stream like confirmations do. `ShopOrderService::reverse()`
marks the order refunded, returns the stock (only if it had not shipped — a chargeback on a
posted parcel is a loss to write off, not a stock correction) and takes back the loyalty points
via the new `PointsService::reverseFromPurchase()`. `EventTicketService::reverse()` clears
`ticket_code`, which is the whole point: a charged-back ticket was still rendering a scannable
QR. Both are idempotent by status, so a duplicate `refund.processed` does the work once.

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

**Fixed** — `findLocal()` reads the row's real status and derives `settled` from
`status === 'confirmed'`, and `localConfirmed()` now includes priced confirmed registrations so
the other direction is checked too.

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

**Fixed** — `AFG-EVT-` added to `PREFIXES` and `gates_event_registrations` to `REF_COLUMN`.
`found()` now returns a named `registration` key alongside `donation` and `order` rather than
calling everything-that-is-not-a-donation an "order" — a shortcut that was survivable with two
tables and a bug with three. Migration `2026_09_14` adds `gateway_txn_id` / `gateway_ref` to the
registrations table, and `SupportContext` gained an event-ticket branch so the assistant can
answer a ticket buyer.

### 7 · MEDIUM — inbound webhook deliveries are not logged

The reference asks for this twice: *"log every event ID/reference before processing"* [A9],
*"log everything received"* [B2]. `gates_webhook_deliveries` logs **outbound** deliveries
only (`WebhookService::deliver()`).

There is no record that a Paystack delivery arrived, what event it carried, or how it was
dispatched. Given B1 documents an acknowledged incident of degraded webhook delivery, the
question "did Paystack send it and we dropped it, or was it never sent?" is currently
unanswerable from this side.

**Fixed** — `gates_gateway_events` (migration `2026_09_13`) plus `GatewayEventLog`. Every
delivery records provider, event name, reference, stream, `domain` (test/live), and **what the
handler decided**. Not the payload: it carries the customer's email and card details, and none
of it is needed for the question the table exists to answer. `GatewayEventLog::everReceived()`
answers the most useful diagnostic on the platform — "has any webhook *ever* arrived?" — with no
gateway call at all.

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

## Remediation

### The low findings

| # | What changed |
|---|---|
| 8a | `EventTicketService::releaseExpired()` wired into the hourly cron — and taught to skip priced holds, which is what makes wiring it safe at all (see finding 1). |
| 8b | `verify()` maps Paystack `reversed` to `failed`, not `pending`. It was being re-verified on every sweep until the three-day ceiling wrote it off as abandoned; it is not abandoned, it is finished. |
| 8c | `domain` (`test` / `live`) is read from every payload and stored on the delivery record. Recorded rather than enforced — the signing secret already decides which mode can reach us, and refusing on `domain` would break the moment somebody rehearses against staging. |
| 8d | Opt-in `PAYSTACK_WEBHOOK_IPS`, off by default and documented as such: the signature is the real control, and an allowlist that trusts a spoofable forwarded header behind Cloudflare reads as protection while checking nothing. It compares `REMOTE_ADDR` only, and a rejection is *recorded*, so a control that fails closed cannot fail invisibly. |
| 8e | Done before the webhook was wired, not after: `ShopOrderService` queues its receipt and uses `dispatchLater()`. Guarded by two new assertions in `PaystackWebhookComplianceTest`. |
| 8f | `EventTicketService::freshReference()` — eight bytes, not five, and minted in one place so the waitlist gets it too. It is the sole bearer token for an unauthenticated, un-rate-limited ticket page. Existing references keep working; every lookup is an exact match. |
| 8g | Unhandled event names are no longer discarded — they land in the delivery log with outcome `ignored` and the gateway's own event name, so a gateway *adding* an event is visible rather than silent. DVA and Pay-with-Transfer remain not-applicable until enabled in Preferences. |

### Files

**New** — `ShopOrderService`, `EventTicketMailer`, `GatewayEventLog`; migrations
`2026_09_13_gateway_events`, `2026_09_14_ticket_payment_columns`.

**Changed** — `PaymentController` (webhook routing, IP gate, delivery log), `PaymentService`
(subaccount fallback + `subaccount()` probe, `reversed` mapping), `PaymentDestination`
(refusal recording, live verification on save), `PaymentReconciler` (`registrations()`, shop
fulfilment unified), `EventTicketService` (`reverse()`, `freshReference()`, hold guard),
`EventsController` / `ShopCheckoutController` (delegate to the services), `GatewayLedger`,
`PaymentLookup`, `PointsService`, `SupportContext`, `VoteProof`, `Maintenance`, `EventWaitlist`,
`SettingsController`, `settings.twig`, `.env.example`.

### Tests

`ShopEventPaymentCoverageTest` — 19 tests covering stream routing, confirmation without a
browser, over/under/foreign-currency payment, the ticket sweep, reversal of both an order and a
ticket, the ledger's settled flag, ticket lookup, the subaccount fallback, and the delivery log.
`PaystackWebhookComplianceTest` gained four assertions for the new queue jobs and the never-500
rule. `EventTicketServiceTest` gained the priced-hold guard.

Suite: **2,886 tests, 16,073 assertions, 1 failure** — `ClaimNotifierTest`, which asserts on
claim-notification wording, is unrelated to payments and was already failing on this branch
before any of this work.

### One deployment step

Both migrations must run before the new code is useful — `/__setup/migrate`, or
`php bin/console migrate`. Until they do, the delivery log is skipped (`hasTable` guard) and
the receipt claims fall back to `OptionalColumn`, so nothing breaks; it simply keeps the old
behaviour.

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
