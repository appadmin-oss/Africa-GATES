# `templates/pages/stands/call.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /events/{slug}/stands → StandApplyController::call`
**Extends:** layout/gates.twig · **Includes/imports:** partials/viz.twig

**What the page says it is (its own header comment, abridged):**
```
 ─────────────────────────────────────────────────────────────────────────────
   THE PUBLIC CALL FOR STANDS.

   Everything on this page is a published term, and it is published BEFORE anybody knows
   who applied. That is the whole fairness mechanism — the prices, the quotas, the closing
   date and what a strong application looks like are all readable by every applicant before
   they decide whether to bother.

   It stays up after the deadline on purpose. A vendor who arrives a week late is owed the
   sentence "this closed on the 14th", not a 404 that reads as though the whole thing was
   imaginary.

   ── THE ORDER OF THIS PAGE IS THE DESIGN ────────────────────────────────────

…
```

**Headings:** Trade at {{ event.title }} · The terms for this event are not published yet · What you will be asked to upload · What is on offer, and how much of it is left · Where the stands sit · How your application is decided · Africa GATES is a record-keeper and a gatekeeper, not an auditor or an underwrit

**Data read (top-level variables/functions):** `call`, `event`, `days_left`, `accepting`, `closing_soon`, `vz`, `notify_source`, `closes_iso`, `hours_left`, `zone_label`, `categories`, `sc`, `pip`, `open`, `hall`, `offer_hours`, `published`, `capacity`

**Forms:**
- `POST /api/v1/newsletter/subscribe` fields: _token[hidden], source[hidden], email[email required,autocomplete]; buttons: Email me when it opens

**States / branches (15 distinct conditions):** `event.location` · `event.event_date` · `call.intro` · `not published` · `accepting` · `closing_soon` · `call.closes_at and call.closes_at|slice(0,10) < "now"|date("Y-m-d")` · `call.opens_at` · `c.type.includes_power` · `c.type.step_free` · `c.type.description` · `c.type.deposit_naira > 0` · `c.quota > 0 and c.quota <= 10` · `c.quota > 10` · `c.left > 0`

**Links out:** `/events/{{ event.slug }}/stands/apply`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-ag-notify`, `data-ag-countdown`

**Accessibility affordances:** aria-hidden×13, aria-live×1; visually-hidden text×1; <label for>×1; autocomplete×1; prefers-reduced-motion×2

**Legal / consent lines:**
- /* Nothing published yet. Dashed, because the card is a placeholder for terms that do
- The terms for this event are not published yet
- // terms are not published.

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `NewsletterTest::test_the_stand_call_form_reads_the_field_the_api_actually_sends` — (no docblock)
- `StandCallNoticeTest::test_the_form_and_the_lookup_agree_on_the_source` — The source string has one owner, and the form uses it.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `NewsletterTest::test_the_stand_call_form_reads_the_field_the_api_actually_sends` — the API answers `success`; reading `ok` told every person who asked that it had failed
- `StandCallNoticeTest::test_the_form_and_the_lookup_agree_on_the_source` — The source string has one owner, and the form uses it. — *the template writes its own copy of the source string*
- `StandSurfacesTest::test_a_closed_call_still_says_when_it_closed` — And a closed one still is, so a late applicant learns when it closed.
- `StandSurfacesTest::test_a_draft_call_publishes_none_of_its_terms` — A draft call is not a public fact — its terms are still being written. — *a draft quota was published*
- `StandSurfacesTest::test_the_public_call_page_publishes_the_terms` — The public call page publishes the terms.
