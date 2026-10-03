# `templates/pages/support-tickets.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /support/tickets → SupportController::tickets`
**Extends:** layout/gates.twig · **Includes/imports:** partials/help-nav.twig

**What the page says it is (its own header comment, abridged):**
```
 ══════════════════════════════════════════════════════════════════════════
   TICKETS

   The half of support the assistant cannot be. A conversation ends when you
   close the tab; a ticket is a thing with a reference, a state and a reply you
   can come back for — which is what somebody whose money has gone missing
   actually needs.

   Members only, and not as gatekeeping: a ticket is a promise to reply, a reply
   needs an address we have verified, and following it needs an account to
   follow it FROM. A visitor still reaches the same team through the assistant's
   "talk to a human"; they just cannot be promised a thread.
   ══════════════════════════════════════════════════════════════════════════
```

**Headings:** {{ t.subject }} · Conversation · Details · Your support tickets · {{ tickets|default([])|length }} support ticket{{ (tickets|default([])|length) = · Raise a support ticket

**Data read (top-level variables/functions):** `tickets`, `av`, `settled`, `me`, `thread`, `_turns`, `st`, `turn`, `staff`, `attach_accept`, `state`, `support_email`, `not_found`, `attachments`, `member_name`, `assistant`, `bot`, `right`, `this`, `reopens`, `anything`, `that`, `would`, `help`, `attach_max`, `attach_limit`, `closed`, `open`, `dot`, `wait`, `on`, `the`, `team`, `waiting`

**Forms:**
- `GET (self)` fields: —; buttons: Open support ticket

**States / branches (14 distinct conditions):** `thread` · `loop.first` · `m.agent` · `attachments[m.id]|default([])|length` · `a.mime starts with 'image/'` · `settled` · `t.severity == 'urgent'` · `t.last_activity and t.last_activity != t.created_at` · `t.severity != 'normal'` · `t.tools_used` · `not_found` · `tickets` · `t.replies` · `t.answered_by_assistant`

**Links out:** `/support/tickets` · `/support/attachment/{{ a.id }}` · `/support/assistant` · `mailto:{{ support_email }}` · `/support/tickets?ref={{ t.reference|url_encode }}`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `ticketDesk()`; data hooks: `data-page`

**Accessibility affordances:** aria-pressed×4, aria-hidden×4, aria-label×3; roles: group; visually-hidden text×3; <label for>×3; alt=×1

**Styling carried:** 1 <style> block(s), 6 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `SupportMemoryTest::test_a_ticket_comes_back_with_somewhere_to_follow_it` — (no docblock)
- `SupportTicketNamingTest::test_the_member_desk_names_them` — And the qualified form is really on the page — a scan for what is ABSENT passes just as happily on a page that says nothing at all.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `SupportTicketNamingTest::test_the_member_desk_names_them` — And the qualified form is really on the page — a scan for what is ABSENT passes just as happily on a page that says nothing at all.
- `SupportTicketNamingTest::test_no_support_surface_says_a_bare_ticket (four public surfaces dropped)` **(guard kept, edited)** — A support surface never says a bare "ticket": it is a "support ticket" (the member also has event tickets).
