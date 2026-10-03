# `templates/pages/support-ticket-link.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /support/t/{token:[a-f0-9]{64}} → SupportController::linkedThread`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  ONE TICKET, OPENED BY A LINK, WITH NO ACCOUNT

  Deliberately NOT support-tickets.twig with a flag. That page is a desk: a list
  down one side, filters, "your other tickets". Every one of those is a capability
  a link must not have — it is permission for one conversation, not for an
  address's history — and building this as a variant would mean the containment
  rule lived in a chain of {% if %} that any later edit could quietly break.

  So this template renders exactly one thread and offers no navigation into
  anything else. There is nothing here to accidentally reveal.

  The audience is somebody on a phone, on mobile data, who tapped a link in an
  email and may never have visited the site. No sign-in, no jargon, and the reply
…
```

**Headings:** This link is no longer active · {{ t.subject }}

**Data read (top-level variables/functions):** `av`, `settled`, `me`, `thread`, `state`, `tl`, `turn`, `staff`, `closed`, `open`, `assistant`, `bot`, `right`, `this`, `reopens`, `us`, `anything`, `that`, `would`, `help`

**States / branches (4 distinct conditions):** `thread is null` · `loop.first` · `m.agent` · `thread is not null`

**Links out:** `/support`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `ticketLink()`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×2; visually-hidden text×1; <label for>×1

**Legal / consent lines:**
- * It is already in the address bar, so this discloses nothing new — but it keeps

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `CsrfFieldNameTest::test_the_account_free_ticket_reply_can_actually_post` — The account-free ticket reply specifically, by name.
- `SupportTicketNamingTest` (whole file, via a helper/constant/data provider: 4 tests) — test_the_assistants_own_labels_say_support_ticket, test_no_support_surface_says_a_bare_ticket, test_the_member_desk_names_them, test_event_tickets_keep_their_name

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CsrfFieldNameTest::test_the_account_free_ticket_reply_can_actually_post` — The account-free ticket reply specifically, by name. — *The token must come from the tag the layout already emits, not a copy.*
- `SupportTicketNamingTest::test_no_support_surface_says_a_bare_ticket (dropped)` **(guard kept, edited)** — A support surface never says a bare "ticket": it is a "support ticket".
