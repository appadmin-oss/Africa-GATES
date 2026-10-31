# `templates/pages/nominate-award.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `POST /nominate → NominationController::submit`; `GET /nominate/{slug:[a-z0-9-]+} → NominationController::award`
**Extends:** layout/shell.twig · **Includes/imports:** partials/site-header.twig, partials/app-bar.twig, partials/challenge-strip.twig, partials/ai-collection-notice.twig

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  ONE AWARD'S NOMINATION PAGE
  design/NominationFlow.dc.html · phase §8.16
  ══════════════════════════════════════════════════════════════════════════════

  The DC's flow has five steps — Nominee, Award, Categories, Evidence, You. This page
  has FOUR, because the award step is the page: somebody who followed a link from the
  award's own page, a poster or a closing-soon email has already answered it, and asking
  again is the thing a per-award door exists to stop. `/nominate` is where that question
  is asked, and "Change award" goes back to it. Recorded in docs/handoff/GAPS.md.

  ── IT IS ONE FORM, AND THE STEPS ARE AN ENHANCEMENT ────────────────────────

  Every field is in the document from the first render and the whole thing posts to
…
```

**Headings:** {{ programme.title }} · {{ _w.who_question }} · Choose {{ rules.min_categories }} or {{ rules.max_categories }} categories · Add evidence Optional · About you

**Data read (top-level variables/functions):** `programme`, `rules`, `old`, `_p`, `_w`, `_v`, `code`, `_closing`, `error`, `assets`, `member`, `css`, `components`, `nominate`, `regions`, `nominee`, `name_label`, `name_hint`, `name`, `category`, `hidden`, `challenge`, `js`, `wording`, `prefill`, `nominations_open`, `share_expired`, `kinds`

**Forms:**
- `POST /nominate` [novalidate,enctype] fields: _token[hidden], programme_id[hidden], nominee_kind[radio], nominee_kind[hidden], nominee_name[text required,autocomplete], country_code[select required], nominee_state[text required], nominee_lga[text required], nominee_photo[file], nominee_email[email autocomplete,inputmode], nominee_phone[tel autocomplete,inputmode], categories[{{ c.id }}][textarea], evidence_links[][url inputmode], evidence[][file], nominator_name[text required,autocomplete], nominator_email[email required,autocomplete,inputmode], nominator_phone[tel required,autocomplete,inputmode], nominator_country[select required], nomi; buttons: Add another link | Back | Continue | Submit nomination

**States / branches (11 distinct conditions):** `not nominations_open` · `programme.phase.phase == 'upcoming'` · `programme.nominations_open` · `_closing and _closing|when('U') < 'now'|date('U')` · `programme.phase.label|default('')` · `error is defined and error` · `share_expired|default(false)` · `_p is not empty` · `loop.first` · `_w.accepts|length > 1` · `c.description`

**Links out:** `/awards/{{ programme.slug }}` · `/nominate` · `/awards/{{ programme.slug }}#terms`

**JS behaviours:** script `{{ asset('/assets/js/nominate.js') }}`; data hooks: `data-min-cats`, `data-max-cats`, `data-short-reason`, `data-nf-dot`, `data-n`, `data-nf-step`, `data-nf-tpl`, `data-nf-cat-title`, `data-min`

**Accessibility affordances:** aria-label×5, aria-hidden×4, aria-labelledby×4, aria-current×1, aria-live×1; roles: alert, radiogroup, status; <label for>×14; autocomplete×6

**Legal / consent lines:**
- <input type="checkbox" name="consent" value="1" required
- {{ old.consent|default('') ? 'checked' : '' }}>
- terms of this award.

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/nominate.css, /assets/css/components/challenge.css

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

- `AiPrivacyTest::test_the_nominate_form_discloses_at_the_point_of_collection` — and it must point at the generated section, whose anchor therefore has to exist — *the anchor the nominate form links to*
- `CelebrateNigeriaSeedTest::test_the_alimosho_form_shows_counts_toward_for_a_joined_member_only` — The README's MUST, on the real nomination form — and only for somebody who joined. — *a strip mid-form for somebody who has not joined is an advertisement*
- `FormAccessibilityTest::test_the_nomination_form_has_no_unlabelled_field` — the form must render, or this proves nothing
- `FormAccessibilityTest::test_each_reason_textarea_is_named_by_the_question_above_it` — Each reason textarea is named by the question above it.
- `FormAccessibilityTest::test_each_evidence_link_input_is_named_individually` — the evidence inputs must render
- `PhaseSurfaceRenderTest::test_an_open_award_s_own_page_carries_the_form` — the house wording, for an award that has written none of its own — *the form must be present*
- `PhaseSurfaceRenderTest::test_a_closed_award_s_own_page_refuses_rather_than_drawing_a_form` — the form must be absent, not merely hidden — *and it must name the phase, so the visitor knows what IS happening*
