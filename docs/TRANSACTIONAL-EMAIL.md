# Transactional email — what sends, when, and why voters were getting nothing

Reported from production:

> I also need an email to be sent automatically to those who initiate payment without
> completion + status sent to those that vote. I also noticed that there's no emails
> being sent to voters.

Both halves were true, for **different reasons**, and neither was a mail-server problem.

---

## 1. Why voters were receiving nothing

### A paid vote sent nothing, ever

`PaidVoteService::mint()` bumps the tally and dispatches a webhook.
`PaidVoteController::callback()` mints and redirects to a confirmation page. That was the
whole of it: **no receipt, no confirmation, no reference, no record of any kind.**

That matters more than it looks, because of one admin setting. When
`paid_voting_disable_free` is on, the free OTP path is refused at the API boundary with a
403 — so the paid path is the **only** vote path on the site, and it was silent end to
end. Someone who spends money on a nominee and receives nothing has no evidence the
purchase happened. That is a support ticket at best and a chargeback at worst.

### `payments:reconcile` was scheduled nowhere

The documented backstop for a dropped gateway callback ("schedule every few minutes")
was not scheduled in `Maintenance`, not in `cron/maintenance.php`, nowhere. So a
supporter whose browser closed on the return trip stayed `pending` with their money
taken until somebody read `cycles:audit` by hand.

Worse: when it *did* run, `reconcileDonations()` flipped the status and stopped. A
paid-vote order confirmed by the backstop ended up `confirmed` with `votes_used = 0` —
money taken, no votes on the nominee, and **indistinguishable from the deliberate
"voting closed before the payment confirmed" refusal that the same column encodes.** The
two live confirm paths both mint. The backstop that exists *for* dropped callbacks was
the one that did not.

### The free-vote confirmation existed but was invisible

`ApiController::castVote()` does send a confirmation. It sent with **no category**, so
`gates_mail_log` recorded it as `NULL` and the question "are voters receiving anything?"
could not be answered from the delivery audit even on a deployment where mail was
working. It now records `Votes`.

### And SMTP may simply not be configured

Every send path is best-effort **by design** — a mail failure must never break a vote or
a payment. The cost is that a total delivery outage is *indistinguishable from normal
operation from the outside*. Two configuration mistakes produce it:

| Cause | What happens | How it looks |
|---|---|---|
| `SMTP_USER` / `SMTP_PASS` unset | Every send writes to `var/logs/outgoing-mail.log` and returns failure | Site works perfectly, nothing arrives |
| Credentials present but wrong | PHPMailer's reason reaches `gates_mail_log` and nowhere else | Same, plus a reason only a DB query reveals |

**This is now the first thing `app:doctor` reports.** See §4.

---

## 2. What sends now

| Trigger | Email | Category | Claimed by |
|---|---|---|---|
| Paid-vote order confirms **and** votes mint | "Your N votes for X are confirmed" — votes, nominee, category, amount, receipt reference, link to the live standing | `Paid votes` | `receipt_sent_at` |
| Paid-vote order confirms and votes **do not** mint | "Payment received — but your votes could not be added" — explains the closed-window rule, gives the reference, invites the refund | `Paid votes` | `receipt_sent_at` |
| A checkout left `pending` for 45 min – 72 h | "Your N votes for X are one tap away" (or generic copy for a gift) with a link straight back | `Checkout` | `abandoned_mail_at` |
| A free OTP vote is cast | "Your vote for X is confirmed" (pre-existing; now categorised) | `Votes` | n/a |

Everything lands in `gates_mail_log`, recipient masked.

### The receipt has two variants because `confirmed` ≠ `minted`

`votes_used = 0` on a **confirmed** order is the platform's existing, queryable "paid but
never minted — refund owed" signal: `mint()` deliberately refuses to push weighted votes
into a cycle that closed between payment and confirmation, and `cycles:audit` reports the
same population. A receipt congratulating that buyer on votes they do not have would be
the platform lying about a payment. The confirmation page already draws this distinction;
the email now agrees with it.

---

## 3. The parts that are easy to get wrong

### Send exactly once, with three callers racing

A paid-vote order confirms from the browser callback, from the signature-verified gateway
webhook, and from `payments:reconcile` — any two of which can land within the same
second. The recovery sweep re-selects the same rows on **every** maintenance tick.

So each email is claimed by a guarded `UPDATE … WHERE <column> IS NULL`, a single
statement, so concurrent callers resolve to exactly one winner with no transaction and no
lock. It is the same mechanism `votes_used` already uses to mint an order's votes once.

The claim is taken **before** the send, because a duplicate receipt is worse than a late
one. It is **released** when the transport reports failure — but only for the receipt:

- **Receipt** releases on failure. A permanently lost receipt for a payment that *did*
  complete is worse than either alternative.
- **Recovery mail** does *not* release. Retrying a nudge against a broken transport is
  how one abandoned order becomes a nightly email to the same person. The failure is in
  `gates_mail_log`.

### Order of operations in the cron is load-bearing

```
queue → cycles → cache → payments:reconcile → checkout recovery mail
                         ^^^^^^^^^^^^^^^^^^^   ^^^^^^^^^^^^^^^^^^^^^
                         confirm the genuinely  then chase whoever is
                         paid ones FIRST        still pending
```

Run it the other way round and the first thing a paying supporter whose callback was
dropped receives is an email telling them they did not pay. A test pins the ordering.

### What the recovery email must not say

**It must never say "you were not charged."** A `pending` row means no *successful
verification* was ever seen — not that no money moved. A dropped callback on a real
payment produces exactly the same row. So the copy asserts nothing about the buyer's
bank; it says:

> If your bank shows a charge for this already, do not pay again — reply to this email
> quoting reference `AFG-PVOTE-…` and we will match it up and add your votes. This is the
> only reminder we will send about it.

A test asserts the forbidden phrasings are absent.

### Every filter on the sweep is load-bearing

This is the one send on the platform that goes to people who did **not** complete an
action, which makes it the one that can turn into spam or into an accusation.

| Filter | Why |
|---|---|
| older than `GRACE_MINUTES` (45) | Not still at the gateway — a card needing a bank OTP, a transfer needing an app switch, a slow 3-D Secure step. Mailing "you didn't finish" to somebody typing their PIN is worse than not mailing. |
| newer than `WINDOW_HOURS` (72) | Keeps the nudge relevant, **and bounds the first run**: this ships to a database already holding every pending row ever written. |
| `abandoned_mail_at IS NULL` | Claimed, so the next tick does not repeat it. |
| status re-checked **at claim time** | Closes the read→send gap in which the webhook may have confirmed the order. |
| no confirmed order from the same address in the window | Every press of "pay" writes its own pending row, so a buyer who bounced once and succeeded on the second attempt leaves **both**. Telling a paying customer they did not pay is worse than staying silent. |
| `BATCH` (40) per run | Each row is one blocking SMTP conversation. |

---

## 4. Operating it

### Is email working at all?

```bash
php bin/console app:doctor            # the `mail` section
php bin/console mail:checkout --status
```

```
smtp_configured          NO
last_successful_send     NEVER — no email has ever been delivered
mail_sent_24h            0
mail_failed_24h          37
last_failure_reason      SMTP not configured (SMTP_USER / SMTP_PASS)
receipts_owed            12
abandoned_awaiting_mail  4
```

`last_successful_send: NEVER` is the check that settles it. No amount of reading
application code explains that — it means no email has ever left the installation, and
every email-dependent flow (voting OTP, magic-link sign-in, receipts, judge invitations)
should be treated as non-functional until one test email lands.

`app:doctor` raises a problem for each of: SMTP unconfigured, never-delivered, every
attempt in 24 h failed, and receipts owed.

### Look before it sends

```bash
php bin/console mail:checkout --dry-run
```

Reports exactly who **would** be emailed and why the rest were skipped, and writes
nothing — no send, no claim. Worth running once before trusting it.

### Backfill the receipts already owed

Every paid vote taken before this shipped has a confirmed row and no receipt, and those
buyers have never been told anything:

```bash
php bin/console mail:checkout --receipts --limit 50
```

Do this **after** proving SMTP works (admin Settings → "Send test email"), otherwise
each attempt fails, releases its claim, and you learn nothing.

### Scheduling

Nothing to add — both run inside the existing maintenance orchestrator, so they are
already driven by whichever front door this deployment uses:

- system cron → `cron/maintenance.php`
- webcron → `GET /__cron/run?token=…`
- opportunistic → `Maintenance::tick()` off web traffic (admin setting `webcron_auto`)

Named tasks exist for running one thing:

```bash
php bin/console app:doctor                                  # diagnose
# via the maintenance hub:
#   ?task=payments        payments:reconcile only
#   ?task=checkout-mail   the recovery sweep only
```

### Tuning

Constants on `CheckoutMailer` — `GRACE_MINUTES`, `WINDOW_HOURS`, `BATCH`. Read the table
in §3 before changing `GRACE_MINUTES` downward; it is the one that decides whether a
supporter mid-payment gets accused of abandoning it.

---

## 5. Still open

- **`/pay` vote-pack and ticket purchases send no receipt.** `PaymentController::confirm()`
  sends nothing for non-`paid-vote` tiers. `DonationController` has its own receipt and
  `payments:reconcile` sends one for shop orders, so the gap is specifically the partner-page
  price book (`vote:*`, `ticket:*`, sponsorship tiers). Left alone deliberately rather than
  widened into silently: those grant `bonus_votes` redeemed later, which is a different
  message from "your votes are in".
- **No unsubscribe link on the recovery mail.** It is transactional (one message, about a
  transaction the recipient started) and capped at exactly one per order, so it is not a
  campaign — but if the volume ever justifies a preference centre, `gates_mail_log`
  already has the category to filter on.
