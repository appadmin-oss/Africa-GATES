# `templates/pages/vote-verify.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/verify → closure routes.php:2186`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  THE RECEIPT A SUPPORTER CAN SHOW SOMEBODY ELSE.

  ── WHY THIS PAGE, AND WHY IT LOOKS LIKE THIS ───────────────────────────────

  Supporters were told the unminted-vote incident was resolved and asked for
  proof. An aggregate cannot answer that — "99.8% of orders are fine" is not a
  reply to "where are MY votes". This answers exactly one order.

  Three design rules follow from it being EVIDENCE rather than a status page:

  1. IT SHOWS THE VOTE ROWS. Not a total we assert — the actual entries, each with
     its own timestamp. A number we print is a claim; a list of the entries behind
     the number is something a reader can weigh. Where they disagree, that is
     shown too, in red, rather than smoothed into a total.
…
```

**Headings:** What happened to your votes · Nothing on record for that reference · Delivered — {{ proof.delivered }} vote{{ proof.delivered == 1 ? '' : 's' }} are  · Refunded · Not confirmed yet · This payment did not complete · Paid, and the votes are not there · The vote records

**Data read (top-level variables/functions):** `proof`, `st`, `ref`

**Forms:**
- `GET /vote/verify` fields: ref[text]; buttons: Verify

**States / branches (13 distinct conditions):** `ref and not proof.found` · `proof.found` · `st == 'delivered'` · `st == 'refunded'` · `st == 'pending'` · `st == 'not_paid'` · `proof.nominee` · `proof.paid_at` · `proof.confirmed_at` · `proof.votes` · `v.at` · `proof.mismatch` · `st != 'delivered' and st != 'refunded'`

**Links out:** `/support/assistant?ref={{ proof.reference|url_encode }}&amp;ask=1` · `/vote` · `/help/paid-but-no-votes` · `/support`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×6, aria-label×1

**Legal / consent lines:**
- Our counter and the tally disagree on this order.

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `DisputeFlowTest::test_the_receipt_states_what_was_delivered` — The receipt states what was DELIVERED, read from the vote rows rather than the order's own counter.
- `RefundDecisionTest::test_a_delivered_order_owes_nothing` — Already delivered — nothing owed, and it points at the checkable page.
- `SupportHandoffLinksTest::test_the_verify_page_asks_for_the_repair_to_actually_run` — The proof page's one action must RUN the repair, not just open a chat box.
- `SupportResilienceTest::test_delivery_health_points_at_the_proof_page_when_clean` — And when it IS clean it says so, and hands over the checkable link.
