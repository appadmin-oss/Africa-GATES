# `templates/pages/pay-success.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /pay/success → PaymentController::success`
**Extends:** layout/gates.twig · **Includes/imports:** partials/success.twig, partials/support-prompt.twig

**What the page says it is (its own header comment, abridged):**
```
 THE page for the incident this was built after. Somebody who paid inside a
   wallet app lands here, reads "awaiting confirmation", and has no way of
   knowing that a single re-check against the gateway would settle it. The prompt
   carries the reference, so the repair costs them one tap and no typing.
```

**Data read (top-level variables/functions):** `confirmed`, `_bonus`, `bonus_votes`

**States / branches (1 distinct conditions):** `confirmed`

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `PaymentAuditTest` (whole file, via a helper/constant/data provider: 16 tests) — test_a_backlog_of_abandoned_carts_cannot_hide_a_real_payment, test_a_checkout_nobody_completed_is_expired_after_the_ceiling, test_a_late_settlement_on_the_last_day_is_confirmed_not_expired, test_a_recent_unpaid_checkout_is_left_alone, test_a_failed_refund_does_not_take_anybodys_votes, test_a_settled_refund_or_a_chargeback_still_claws_back, test_an_unrecognised_reversal_shaped_event_does_nothing, test_an_overpayment_is_confirmed_and_the_surplus_recorded, test_an_underpayment_is_still_never_confirmed, test_a_payment_in_another_currency_is_not_this_order, test_a_recorded_provider_is_asked_first_and_alone, test_an_order_with_no_recorded_provider_still_asks_everybody …
- `PaymentControllerTest::test_callback_confirms_on_success_and_matching_amount` — (no docblock)
- `SupportSurfaceRenderTest::test_the_unconfirmed_payment_page_hands_over_its_reference` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `SupportSurfaceRenderTest::test_the_pressure_points_all_include_it` — The pressure points all include it.
- `SupportSurfaceRenderTest::test_the_unconfirmed_payment_page_hands_over_its_reference` — the whole point is that the reader never retypes the reference
