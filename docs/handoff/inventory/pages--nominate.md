# `templates/pages/nominate.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /nominate → NominationController::form`; `POST /nominate → NominationController::submit`
**Extends:** layout/shell.twig · **Includes/imports:** partials/site-header.twig, partials/app-bar.twig, partials/promo-carousel.twig, partials/tab-bar.twig

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  NOMINATE — THE HUB
  design/NominateHub.dc.html · phase §8.16
  ══════════════════════════════════════════════════════════════════════════════

  One question: which award? Everything after it belongs to that award's own page,
  `/nominate/{slug}`, where the words, the categories and the accepted kinds of
  nominee are its own.

  ── WHAT THIS REPLACED, AND WHY NONE OF IT SURVIVED ─────────────────────────

  A 640-line single template holding a five-step Alpine wizard, the award chooser,
  forty-three inline `style` attributes and its own copies of the field labels. It
  worked, and it could not be extended: every award shared one set of nouns, so a
…
```

**Headings:** Open for nominations · Not open right now

**Data read (top-level variables/functions):** `programmes`, `closed`, `assets`, `css`, `components`, `nominate`, `share_expired`

**States / branches (5 distinct conditions):** `share_expired` · `programmes is empty` · `p.subtitle` · `p.nominations_close` · `closed is not empty`

**Links out:** `/awards` · `/results` · `/nominate/{{ p.slug }}`

**Accessibility affordances:** aria-hidden×4, aria-label×2; roles: alert

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/nominate.css

**Guard tests that read this page (at the time of the destroy):**
- `AiPrivacyTest::test_the_nominate_form_discloses_at_the_point_of_collection` — (no docblock)
- `AwardPageTest::test_the_timeline_marks_one_step_now` — (no docblock)
- `CelebrateNigeriaSeedTest::test_the_alimosho_form_shows_counts_toward_for_a_joined_member_only` — The README's MUST, on the real nomination form — and only for somebody who joined.
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `CsrfMiddlewareTest::test_non_api_post_requires_csrf_token` — (no docblock)
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee.
- `FormAccessibilityTest` (whole file, via a helper/constant/data provider: 5 tests) — test_the_nomination_form_has_no_unlabelled_field, test_each_reason_textarea_is_named_by_the_question_above_it, test_each_evidence_link_input_is_named_individually, test_the_public_pages_have_no_unlabelled_control, test_a_decorative_duplicate_link_is_hidden_from_assistive_tech
- `GuideServiceTest::test_scripted_tier_is_directly_addressable_for_budget_degrade` — (no docblock)
- `NominationSubmitPathTest` (whole file, via a helper/constant/data provider: 5 tests) — test_a_nomination_carrying_a_file_does_not_500, test_a_nomination_with_no_file_still_works, test_an_empty_file_input_is_not_an_error, test_the_confirmation_names_the_award_and_every_category, test_both_categories_reach_the_table
- `PhaseSurfaceRenderTest::test_nominate_offers_an_open_award_in_one_press` — ── WHAT THESE THREE NOW ASSERT, AND WHY THE WORDS CHANGED ────────────── `/nominate` used to BE the wizard: one page holding the award chooser and all five steps, with one set of nouns for every award.
- `PhaseSurfaceRenderTest::test_an_open_award_s_own_page_carries_the_form` — (no docblock)
- `PhaseSurfaceRenderTest::test_nominate_never_offers_a_closed_programme` — (no docblock)
- `PhaseSurfaceRenderTest::test_a_closed_award_s_own_page_refuses_rather_than_drawing_a_form` — (no docblock)
- `PhaseSurfaceRenderTest::test_nominate_replaces_the_wizard_with_a_real_closed_state` — (no docblock)
- `PhaseSurfaceRenderTest::test_nominate_closed_state_names_the_next_opening_date` — (no docblock)
- `PhaseSurfaceRenderTest::test_a_stale_status_column_does_not_open_the_nomination_wizard` — (no docblock)
- `SiteLinkIntegrityTest::test_the_scan_would_actually_catch_a_dead_link` — (no docblock)
- `SiteSearchTest::test_a_natural_language_question_reaches_a_page` — The question a first-time visitor actually types.
- `TrailingSlashTest::test_a_post_is_never_redirected` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PhaseSurfaceRenderTest::test_nominate_offers_an_open_award_in_one_press` — ── WHAT THESE THREE NOW ASSERT, AND WHY THE WORDS CHANGED ────────────── `/nominate` used to BE the wizard: one page holding the award chooser and all five steps, with one set of nouns for every award. — *the open award must be listed*
- `PhaseSurfaceRenderTest::test_nominate_never_offers_a_closed_programme` — a programme in its voting phase must not appear as open for nominations — *and it must still be findable, with its real phase stated*
- `PhaseSurfaceRenderTest::test_nominate_replaces_the_wizard_with_a_real_closed_state` — no form may be in the DOM when nothing would accept it — *and always offer a next action*
- `PhaseSurfaceRenderTest::test_nominate_closed_state_names_the_next_opening_date` — Nominate closed state names the next opening date.
- `PhaseSurfaceRenderTest::test_a_stale_status_column_does_not_open_the_nomination_wizard` — the published close date must bind the UI, not just the write path
