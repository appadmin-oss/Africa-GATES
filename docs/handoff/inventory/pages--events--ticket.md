# `templates/pages/events/ticket.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /events/ticket/{ref:[A-Za-z0-9\-]{8,60}} → EventsController::ticket`
**Extends:** layout/gates.twig · **Includes/imports:** partials/celebrate.twig

**What the page says it is (its own header comment, abridged):**
```
 ── THE TICKET ───────────────────────────────────────────────────────────────

   Reachable with the reference alone, which is the same doctrine as the claim link, the
   interview page and the nominee questionnaire: an attendee has no account, and putting a
   login between somebody and the door they are standing at is how a queue stops moving.

   ── WHAT THIS IS BUILT AGAINST ──────────────────────────────────────────────

   Two design documents, and they specify two DIFFERENT artefacts from the same markup:

     • `Mobile Ticket — Spec.dc.html` — the stub a guest opens on a phone. 390 wide, 1087
       tall, dark frame, cream stub. Its numbers are reproduced exactly below and they are
       marked · SPEC · where they are, so nobody "tidies" one of them later.
     • `Event Ticket.dc.html` (artboard 1a) — the printed stub. 148 × 62 mm landscape, art
…
```

**Headings:** This ticket link is not working · 26 ? ' class="is-long"' : '' }}>{{ title }}

**Data read (top-level variables/functions):** `reg`, `event`, `dark`, `paid`, `title`, `qr`, `tier_swatch`, `priced`, `where`, `support_email`, `one`, `ag`, `ground`, `f3b416`, `fbf6e6`, `tk`, `team`, `class`, `long`, `site_url`, `events`, `ticket`, `design`

**States / branches (28 distinct conditions):** `reg and reg.ticket_code|default('')` · `reg is null` · `D.image` · `event.event_date|default('')` · `event.event_date|default('') and event.location|default('')` · `event.tagline|default('')` · `reg.status == 'confirmed'` · `qr` · `event.end_date|default('')` · `reg.tier|default('')` · `tier_swatch` · `'seat' in D.rows and reg.seat_label|default('')` · `'price' in D.rows` · `'seats' in D.rows and (reg.quantity|default(1)) > 1` · `'phone' in D.rows and reg.phone|default('')` · `'bought' in D.rows and reg.created_at|default('')` · `'email' in D.rows and reg.email|default('')` · `where|length` · `D.note` · `reg.status == 'pending'` · `reg.status == 'cancelled'` · `reg.status == 'waitlisted'` · `reg.status != 'confirmed'` · `reg.checked_in_at|default('')` · `event.event_date|default('') or reg.status == 'confirmed'` · `event.slug|default('')` · `reg is not null and reg.status == 'confirmed' and not reg.checked_in_at|default('')` · `reg is not null`

**Links out:** `mailto:{{ support_email|default('') }}` · `/events/ticket/{{ reg.reference|url_encode }}/calendar.ics` · `/events/ticket/{{ reg.reference|url_encode }}/ticket.pdf` · `/events/{{ event.slug }}`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-celebrate`, `data-tk`

**Accessibility affordances:** aria-hidden×6, aria-live×1; roles: status; <label for>×4; alt=×2; autocomplete×1

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `TicketTierColourTest::test_the_ticket_hero_is_not_lazy_loaded` — The ticket's hero is the largest above-the-fold paint.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `TicketPrintTest::test_the_browser_and_the_pdf_print_the_same_card` — The two print paths produce the same object. — *the two print paths disagree about the card width*
- `TicketPrintTest::test_the_dark_theme_is_forced_back_to_light_on_paper` — The dark theme must print light. — *The dark palette must be overridden inside the print block.*
- `TicketPrintTest::test_the_date_and_venue_are_repeated_on_white_for_print` — Date and venue must survive the colour dropping out. — *The address has to appear somewhere that is not a coloured fill.*
- `TicketPrintTest::test_the_manage_panel_does_not_print` — The self-service form was documented as dropped from print, and was not. — *A page of form controls under a ticket somebody is about to hand over.*
- `TicketPrintTest::test_the_page_rule_is_something_a_browser_will_accept` — The `@page` declaration has to be one the browser will actually accept. — *the sheet has to be declared somewhere*
- `TicketPrintTest::test_the_printed_artwork_is_in_flow` — The artwork must not be in the positioned layer on paper. — *the image has to leave the positioned layer for print*
- `TicketPrintTest::test_the_printed_qr_has_a_physical_size` — The QR must be sized in MILLIMETRES for print. — *Print geometry has to be declared.*
- `TicketPrintTest::test_the_ticket_url_is_printed_as_text` — Paper cannot be clicked, so the link that recovers a lost ticket is printed as text.
- `TicketTierColourTest::test_a_tier_without_a_slot_renders_no_dot` — A tier with no colour chosen renders the name and no dot — never a grey one. — *The tier name still shows.*
- `TicketTierColourTest::test_changing_the_events_accent_moves_the_tier_colour` — The reason the column holds a slot rather than a hex. — *The two accents must produce different fills.*
- `TicketTierColourTest::test_the_ticket_hero_is_not_lazy_loaded` — The ticket's hero is the largest above-the-fold paint. — *Expected the ticket hero img.*
- `TicketTierColourTest::test_the_tier_dot_is_rendered_from_the_events_accent` — The tier dot should render. — *The dot should carry the slot fill.*
