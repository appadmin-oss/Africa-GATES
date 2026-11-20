# `templates/pages/vote-paid-success.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/paid/success → PaidVoteController::success`
**Extends:** layout/gates.twig · **Includes/imports:** partials/success.twig, partials/support-prompt.twig, partials/share.twig

**What the page says it is (its own header comment, abridged):**
```
 THREE states, because two was a lie.

   `confirmed` says the money arrived; `minted` says the votes landed. Those come
   apart whenever a payment initiated inside the voting window confirms after it
   closes — PaidVoteService::mint() refuses that on purpose rather than pushing
   weighted votes into a closed tally, leaving votes_used = 0. This page used to
   branch on `confirmed` alone and told that buyer their votes were "already in
   the public tally" with a receipt on the way.

   paid + minted   → celebrate, and mark the /vote hub's per-device tracker.
   paid + NOT minted → say plainly that nothing was counted and a refund is owed.
   not confirmed   → the gateway has not landed yet.
```

**Headings:** Say something about {{ nominee_name ? nominee_name : 'them' }}

**Data read (top-level variables/functions):** `nominee_name`, `minted`, `reference`, `programme_id`, `msg_max`, `msg_posted`, `confirmed`

**States / branches (4 distinct conditions):** `confirmed and not minted` · `not minted` · `minted and reference` · `minted and programme_id`

**Links out:** `url` · `'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url)` · `'https://wa.me/?text=' + encodeURIComponent(url)`

**JS behaviours:** 2 inline <script> block(s); Alpine x-data: `paidMessage('{{ reference|e('js') }}', {{ msg_max|default(40`; fetches: `/api/vote-message`

**Accessibility affordances:** visually-hidden text×1; <label for>×1

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `PaidVoteReceiptTest` (whole file, via a helper/constant/data provider: 8 tests) — test_a_minted_order_is_celebrated, test_a_confirmed_but_unminted_order_is_never_reported_as_counted, test_the_unminted_state_says_a_refund_is_owed_and_shows_the_reference, test_the_unminted_state_does_not_celebrate, test_a_minted_order_does_celebrate, test_an_unknown_reference_still_renders_the_pending_state, test_a_minted_order_marks_the_per_device_ballot_tracker, test_an_unminted_order_does_not_mark_the_tracker

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PaidVoteReceiptTest::test_a_minted_order_is_celebrated` — A minted order is celebrated.
- `PaidVoteReceiptTest::test_a_confirmed_but_unminted_order_is_never_reported_as_counted` — promising a receipt for votes that do not exist compounds the error
- `PaidVoteReceiptTest::test_the_unminted_state_says_a_refund_is_owed_and_shows_the_reference` — and a route to act on it
- `PaidVoteReceiptTest::test_the_unminted_state_does_not_celebrate` — a payment that minted no votes was celebrated
- `PaidVoteReceiptTest::test_a_minted_order_does_celebrate` — a minted order got no celebration
- `PaidVoteReceiptTest::test_an_unknown_reference_still_renders_the_pending_state` — an unconfirmed payment is not a refund case — nothing was charged that we know of
- `PaidVoteReceiptTest::test_a_minted_order_marks_the_per_device_ballot_tracker` — A minted order marks the per device ballot tracker.
- `PaidVoteReceiptTest::test_an_unminted_order_does_not_mark_the_tracker` — recording a vote that was refused is the same lie in a different place
