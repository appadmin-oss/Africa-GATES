# `templates/pages/support.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /support → closure routes.php:3039`
**Extends:** layout/gates.twig · **Includes/imports:** partials/help-nav.twig

**What the page says it is (its own header comment, abridged):**
```
  SUPPORT & APPEALS.

  ── THE LAYOUT PROBLEM THIS FIXES ───────────────────────────────────────────
  The page had five cards in two visually identical rows, doing three different
  things. Two navigated away (assistant, tickets), three silently set a hidden
  form field and scrolled. Same size, same border, same hover — so the only way to
  learn which was which was to click one and find out.

  It also buried the thing that actually resolves problems. The assistant can
  re-ask a bank and add missing votes on the spot; the appeal form opens your mail
  client. Those were presented as peers.

  Now the page is one honest triage, in the order that resolves fastest:

…
```

**Headings:** Reach a person · Submit an appeal · Tell us what happened · Worth reading first · Or just email us

**Data read (top-level variables/functions):** `support_email`, `result`, `profile`, `access`, `ticket`, `issue`

**Links out:** `/support/assistant` · `/support/tickets` · `#sp-form` · `mailto:appeals@afrovanguard.org.ng` · `/integrity` · `/help/dispute-a-result` · `/help/how-cpi-works` · `/help/what-paid-votes-do` · `/help/nomination-rejected` · `mailto:{{ support_email }}`

**JS behaviours:** Alpine x-data: `{
  reason:'Dispute a result', name:'', email:'', ref:'', de`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×8, aria-labelledby×1, aria-pressed×1; roles: group; <label for>×4

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `HelpCentreLayoutTest::test_a_search_with_no_answer_hands_over_instead_of_dead_ending` — (no docblock)
- `PaidVoteReceiptTest::test_the_unminted_state_says_a_refund_is_owed_and_shows_the_reference` — (no docblock)
- `SupportMemoryTest::test_a_ticket_comes_back_with_somewhere_to_follow_it` — (no docblock)
- `SupportSurfaceRenderTest::test_the_reference_travels_into_the_assistant` — (no docblock)
- `SupportSurfaceRenderTest::test_without_a_reference_it_does_not_auto_ask` — (no docblock)
- `SupportSurfaceRenderTest::test_every_kind_renders_rather_than_falling_through_to_nothing` — (no docblock)
- `SupportTicketNamingTest` (whole file, via a helper/constant/data provider: 4 tests) — test_the_assistants_own_labels_say_support_ticket, test_no_support_surface_says_a_bare_ticket, test_the_member_desk_names_them, test_event_tickets_keep_their_name

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `SupportTicketNamingTest::test_no_support_surface_says_a_bare_ticket (dropped)` **(guard kept, edited)** — A support surface never says a bare "ticket": it is a "support ticket".
