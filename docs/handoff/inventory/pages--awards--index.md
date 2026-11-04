# `templates/pages/awards/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /awards → AwardsController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── Gala hero ──
```

**Headings:** {{ page.hero_title }} · {{ page.tracks_heading }} · {{ a.title }} · Programmes open soon · {{ page.steps_heading }} · {{ page.step1_title }} · {{ page.step2_title }} · {{ page.step3_title }}

**Data read (top-level variables/functions):** `page`, `status`, `awards`, `tc`, `awards_data`, `e3f1e3`, `fbf1cf`, `e2e8e9`, `efe7f6`, `fbe6ec`, `e0eef1`

**States / branches (5 distinct conditions):** `awards is not empty` · `status == 'voting'` · `status == 'nominations'` · `status == 'judging'` · `status == 'results'`

**Links out:** `/nominate` · `/vote` · `{{ page.gala_link_url }}` · `/awards/{{ a.slug }}`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×3

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ArrivalsReportTest::test_the_home_page_is_named_rather_than_left_as_a_slash` — '/' in a list of paths reads as a missing value.
- `AwardPageTest` (whole file, via a helper/constant/data provider: 7 tests) — test_the_overview_renders_the_comps_sections, test_the_cover_is_the_awards_own, test_views_are_urls_and_terms_is_offered_only_when_written, test_the_scoring_fact_moves_with_the_rules, test_since_is_counted_from_the_editions, test_the_timeline_marks_one_step_now, test_the_phase_line_and_the_step_agree_on_the_date
- `CspTest::test_every_inline_script_on_a_rendered_page_carries_the_nonce` — (no docblock)
- `CspTest::test_every_style_block_on_a_rendered_page_carries_the_nonce` — (no docblock)
- `FormAccessibilityTest::test_the_public_pages_have_no_unlabelled_control` — (no docblock)
- `GeeSupportsTest::test_a_route_is_never_linked_as_the_prefix_of_a_longer_path` — No route may be linked as the PREFIX of a longer path.
- `PageRenderSmokeTest::test_awards_renders_active_programmes` — (no docblock)
- `PhaseSurfaceRenderTest::test_programme_page_offers_voting_when_voting_is_open` — (no docblock)
- `PhaseSurfaceRenderTest::test_programme_page_offers_nominating_when_nominations_are_open` — (no docblock)
- `PhaseSurfaceRenderTest::test_programme_page_offers_results_when_published` — (no docblock)
- `PhaseSurfaceRenderTest::test_programme_page_states_the_phase_even_with_no_action_available` — (no docblock)
- `PhaseSurfaceRenderTest::test_programme_page_does_not_hardcode_a_cycle_year` — (no docblock)
- `SecurityHeadersTest` (whole file, via a helper/constant/data provider: 21 tests) — test_html_gets_exactly_one_coherent_caching_directive, test_no_store_is_deliberately_not_used, test_the_http_1_0_relics_are_not_emitted, test_php_is_told_not_to_send_its_own_caching_headers, test_a_route_that_sets_its_own_policy_is_left_alone, test_a_route_that_sets_its_own_csp_is_not_overridden, test_html_still_gets_the_site_policy_with_its_nonce, test_a_non_html_response_gets_no_caching_policy_imposed, test_a_response_with_no_content_type_is_treated_as_html, test_every_shared_header_is_present_on_a_response, test_the_htaccess_and_the_middleware_agree, test_the_static_csp_is_scoped_by_directory_not_by_directive …
- `SiteSearchTest::test_a_category_name_finds_the_programme_page` — (no docblock)
- `TrailingSlashTest::test_a_trailing_slash_redirects_permanently` — (no docblock)
- `TrailingSlashTest::test_repeated_slashes_collapse_in_one_hop` — (no docblock)
- `TrailingSlashTest::test_a_path_without_a_slash_passes_straight_through` — (no docblock)
- `TrailingSlashTest::test_head_is_redirected_like_get` — (no docblock)
- `VisitTrackerTest::test_token_shaped_segments_are_starred_and_real_slugs_are_not` — A list of known routes and a shape rule, because the failure mode is forgetting.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PageRenderSmokeTest::test_awards_renders_active_programmes` — Awards renders active programmes.
