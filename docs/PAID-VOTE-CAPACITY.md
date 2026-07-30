# Bulk paid voting — can the site take an order of more than 1,000 votes?

Short answer: **yes, now.** Before this change it could not, and the way it failed was
worse than a plain refusal. This is the whole picture, including the limits that are not
ours.

---

## 1. What was actually stopping it

Three separate ceilings, in three different layers, none of them the business rule anyone
believed they were configuring.

### `MAX_QTY = 1000`, and it clamped instead of refusing

`PaidVoteService::MAX_QTY` was a hard-coded constant, and `PaidVoteController` applied it
as `min(MAX_QTY, $qty)`. An order for 5,000 votes therefore became an order for 1,000 — the
buyer was charged for 1,000, received 1,000, and was told nothing. They would find out from
the tally, which is the worst possible place to learn it.

It is now `PaidVoteService::maxQty()`, backed by the `vote_max_qty` setting (Admin →
Settings → Paid voting), and an over-large order is **refused with the real maximum** rather
than quietly shrunk.

### `gates_votes.weight` was `SMALLINT UNSIGNED`

This was the real ceiling, and nobody had measured it. A paid order mints **one** row with
`weight = quantity`, and `SMALLINT UNSIGNED` tops out at **65,535**.

It was invisible precisely because `MAX_QTY` was 1,000 — nothing could reach it. The moment
the cap was raised it would have become binding, and the failure mode differs by host:

| sql_mode | what happens |
| --- | --- |
| strict (this app's connection sets it) | the INSERT raises "Out of range value", the mint transaction rolls back, the order sits CONFIRMED with `votes_used = 0`. No votes invented — but the customer paid and got nothing. |
| relaxed (common on shared hosting) | MySQL **clamps** to 65,535 and reports success. A ₦20,000,000 order for 100,000 votes credits 65,535. Money taken, votes quietly missing, no error anywhere. |

`database/migrations/2026_07_30_vote_weight_widen.php` widens it to `INT UNSIGNED`
(4,294,967,295), matching `gates_nominees.vote_count` and `gates_donations.bonus_votes`,
which were already `INT`. **This migration is what actually makes bulk orders possible** —
raising the cap without it would have produced one of the two failures above.

It is an in-place InnoDB column widening: no row is rewritten and existing values are
unchanged. It does take a brief metadata lock, so on a very large `gates_votes` run it in a
quiet window.

### `amount_naira` is `INT UNSIGNED`, and the ceilings multiply

The one that is easy to miss. Price is quantity × rate, so at an admin rate of ₦1,000 a
vote, a 10,000,000-vote cap alone would let `price()` compute ₦10,000,000,000 — over twice
what the column holds. Two ceilings that each look sufficient in isolation, multiplied
together, is exactly how an "impossible" value reaches a column.

`PaidVoteService::maxQtyForOrder()` is therefore the authority: the **lower** of the
admin's quantity cap and the quantity whose price still fits `MAX_ORDER_NAIRA`
(₦100,000,000). One function, consulted by the ballot form (as the input's `max`), by the
checkout (as the rejection threshold) and by the settings page (as the figure shown to the
admin) — three surfaces computing it separately is how a form offers a quantity the
checkout then refuses.

## 2. The limits that remain, and whose they are

| limit | value | whose |
| --- | --- | --- |
| `vote_max_qty` setting | default 1,000 | **yours** — raise it in Admin → Settings |
| `HARD_MAX_QTY` | 10,000,000 | ours, a blast radius (see below) |
| `MAX_ORDER_NAIRA` | ₦100,000,000 | ours, so `amount_naira` cannot overflow |
| per-transaction card/transfer limit | typically ₦1m–₦10m | **the gateway's and the buyer's bank's** |

`HARD_MAX_QTY` is set far below what the column can hold, on purpose. It is not a storage
limit — it is the amount a single mistyped quantity, or a single compromised admin setting,
can add to a public tally in one transaction. Ten million is past any plausible campaign and
still bounded and reversible by the existing clawback path.

**In practice the binding constraint on a genuinely large order is the payment gateway, not
this platform.** Paystack and Flutterwave both cap a single card transaction well below
₦100m, and a buyer's own bank caps it lower again. A client wanting to place, say, a
₦50,000,000 order will hit their bank before they hit anything here — the practical route is
several orders, or a bank transfer reconciled manually against a `payments:reconcile` run.

## 3. Throughput: a big order is not a big load

Worth saying plainly, because "can the site handle 1,000 votes at once?" is often really a
performance question.

A paid order of **any** size mints exactly one `gates_votes` row (`weight = quantity`) and
performs one `increment` on the nominee's `vote_count`. A 50,000-vote order is the same
amount of database work as a 1-vote order. There is no per-vote loop anywhere in the paid
path, so there is nothing that scales with quantity — pinned by
`PaidVoteCapacityTest::test_an_order_of_any_size_is_a_single_vote_row`.

What this does mean: 50,000 votes arrive as **one** timestamped row. Anything reading vote
timing sees a single event, which is correct — it *was* a single purchase — and is why the
24-hour momentum figure on the flier can jump by a bulk order in one step.

The integrity model is unchanged at any scale. Paid votes are `vote_type = 'paid'` weighted
rows that move the **public tally only**; `organic_vote_count`, the CPI community signal, is
never touched by money. A 50,000-vote order moves the CPI signal by zero, and that is
asserted directly.

## 4. Bulk pricing

`price()` charges the cheaper of the two admin rules at every scale: the per-vote price, or
full ₦1,000 bundles plus a per-vote remainder. At the live configuration (₦200/vote,
6 votes per ₦1,000) a 60,000-vote order is 10,000 full bundles = ₦10,000,000, against
₦12,000,000 at the per-vote rate — so the buyer gets the bundle rate automatically. Pinned,
along with monotonicity: more votes can never cost less.

## 5. Enabling a bulk order

1. **Migrate** — `bin/console db:migrate`, or `GET /__setup/migrate` with the setup token.
   Without `2026_07_30_vote_weight_widen.php` applied, do not raise the cap.
2. **Admin → Settings → Paid voting** → set *Maximum votes per order*. The card beneath the
   field shows the **effective** maximum, which is that number or the cash ceiling's,
   whichever is lower.
3. Confirm with `bin/console app:doctor` that the deployment is the one you just migrated
   (the `opcache.validate_timestamps` line matters here — an edit with no effect looks
   exactly like a failed deploy).
4. Place a small real order first. The refusal path is honest now, but a gateway's own
   limit is discovered at the gateway.

## 6. If a large payment confirms but the votes do not appear

That state is deliberate and queryable. A CONFIRMED `paid-vote` row with `votes_used = 0`
means "paid, never minted — refund owed". Three things produce it: voting closed between
payment and confirmation, the nominee stopped being votable, or the quantity exceeded
`HARD_MAX_QTY`.

```sql
SELECT id, payment_ref, donor_email, amount_naira, bonus_votes, created_at
  FROM gates_donations
 WHERE tier = 'paid-vote' AND status = 'confirmed' AND votes_used = 0;
```

`bin/console cycles:audit` reports the same population, and
`bin/console payments:clawback <ref>` reverses a refunded order and rebuilds the counters.
The buyer sees the honest version of this too — `/vote/paid/success` distinguishes "votes
confirmed" from "payment received, votes not added, this is refundable" rather than thanking
them for votes that do not exist.
