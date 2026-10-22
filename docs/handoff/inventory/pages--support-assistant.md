# `templates/pages/support-assistant.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /support/assistant → SupportController::page`
**Extends:** layout/gates.twig · **Includes/imports:** partials/help-nav.twig

**What the page says it is (its own header comment, abridged):**
```
 ══════════════════════════════════════════════════════════════════════════
   THE SUPPORT DESK

   Gee's engine, a support console's face. The difference is not cosmetic: Gee
   is a guide that volunteers suggestions as you browse; this is a desk you come
   to with a problem. So it opens with an input rather than a greeting, states
   plainly what it can and cannot see, shows which records it consulted, and puts
   "talk to a human" on screen from the first frame instead of behind three
   failed attempts.

   OPEN TO EVERYONE. A guest cannot be shown a LIST of their payments — there is
   no identity to scope one to — but a guest with a reference gets the same
   repair a member does, because most people who buy votes here never make an
   account and they were the ones locked out of the fix built for them.
…
```

**Headings:** {% if member_first %}Hello {{ member_first }} — what has gone wrong?{% else %}Wh

**Data read (top-level variables/functions):** `can_see_payments`, `member_first`, `support_email`, `ai_on`

**Forms:**
- `GET (self)` fields: —; buttons: Send

**States / branches (5 distinct conditions):** `member_first` · `not can_see_payments` · `can_see_payments` · `not ai_on` · `ai_on`

**Links out:** `/account/login?next=%2Fsupport%2Fassistant` · `/support/tickets` · `a.url` · `'/support/tickets?ref=' + encodeURIComponent(m.ticket || '')` · `/help` · `mailto:{{ support_email }}` · `$2`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `supportDesk()`; data hooks: `data-page`; fetches: `/api/v1/support/chat`, `/api/v1/support/escalate`

**Accessibility affordances:** aria-hidden×11, aria-label×2, aria-live×1; roles: log; visually-hidden text×1; <label for>×1; prefers-reduced-motion×2

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `HelpCentreLayoutTest::test_a_search_with_no_answer_hands_over_instead_of_dead_ending` — (no docblock)
- `HighlightToAskTest::test_the_button_hands_the_passage_over_as_a_parameter_the_assistant_reads` — (no docblock)
- `SupportConversationFaultsTest::test_the_escalate_button_asks_before_it_files` — (no docblock)
- `SupportHandoffLinksTest` (whole file, via a helper/constant/data provider: 4 tests) — test_every_parameter_a_page_sends_is_one_the_assistant_reads, test_the_verify_page_asks_for_the_repair_to_actually_run, test_a_question_from_a_url_is_bounded, test_only_the_url_params_object_is_asked_for_parameters
- `SupportSurfaceRenderTest::test_the_reference_travels_into_the_assistant` — (no docblock)
- `SupportSurfaceRenderTest::test_without_a_reference_it_does_not_auto_ask` — (no docblock)
- `SupportSurfaceRenderTest::test_every_kind_renders_rather_than_falling_through_to_nothing` — (no docblock)
- `SupportTicketNamingTest` (whole file, via a helper/constant/data provider: 4 tests) — test_the_assistants_own_labels_say_support_ticket, test_no_support_surface_says_a_bare_ticket, test_the_member_desk_names_them, test_event_tickets_keep_their_name

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `SupportConversationFaultsTest::test_the_escalate_button_asks_before_it_files` — with nothing said yet it must ask, not open a ticket
- `SupportHandoffLinksTest::test_every_parameter_a_page_sends_is_one_the_assistant_reads` — fromLink() reads no parameters at all — that cannot be right.
- `SupportHandoffLinksTest::test_the_verify_page_asks_for_the_repair_to_actually_run` — The proof page's one action must RUN the repair, not just open a chat box. — *q= only prefills a draft; this button is meant to act.*
- `SupportHandoffLinksTest::test_a_question_from_a_url_is_bounded` — `?q=` is free text from a URL, so it must be capped before it becomes a message. — *A pasted essay is not a question — cap ?q= before it reaches the composer.*
- `SupportHandoffLinksTest::test_only_the_url_params_object_is_asked_for_parameters` — `.get(…)` in fromLink() may only be called on the URLSearchParams object. — *Could not isolate fromLink().*
- `SupportTicketNamingTest::test_the_assistants_own_labels_say_support_ticket` — The assistants own labels say support ticket.
- `SupportTicketNamingTest::test_no_support_surface_says_a_bare_ticket (dropped)` **(guard kept, edited)** — A support surface never says a bare "ticket": it is a "support ticket".
