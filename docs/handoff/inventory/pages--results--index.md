# `templates/pages/results/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /results → ResultsController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── COLOUR BUDGET: tier 1, and the one event is `live` ─────────────────────────
   This page states one thing — where each award stands — so it buys one colour
   event, and it spends it on the only status that is true right now: an award
   whose voting is open. Decided is plain ink and a date; a panel's progress is a
   neutral bar; withheld is an OUTLINE and never a fill, because on a page that is
   mostly good news the absence of one result must not be the loudest thing on it.
   Programme hues on the row spines are identity, not events. See
   ColourBudgetTest.
```

**Headings:** Where every award stands · {% if d.awards == 1 %}This award has not been decided yet{% else %}These results · {{ e.edition ?: e.year }} · Nothing matches that · No award has been announced yet

**Data read (top-level variables/functions):** `view`, `stats`, `held`, `key`, `order`, `programme`, `word`, `programmes`, `editions`, `shown`, `are`, `colour_tier`, `status`, `delayed`

**Forms:**
- `GET /results` fields: q[search autocomplete], order[hidden]; buttons: Search

**States / branches (22 distinct conditions):** `stats.counting|default(0)` · `view.order|default('status') != 'status'` · `programmes|default([])` · `not view.programme` · `view.programme == p.name` · `view.order|default('status') == key` · `d.edition` · `d.awards == 1` · `d.awards > 1` · `d.note` · `editions|default([])` · `e.status.key == 'counting'` · `e.status.key == 'counting' and e.closes` · `e.status.key == 'decided' and e.announced|default('')` · `e.status.publishes_standing and e.overall and e.overall.winner` · `e.overall.provisional` · `e.status.key == 'judging'` · `e.status.key == 'withheld'` · `e.decided|default(0) and e.decided < e.categories` · `view.q|default('') or view.programme|default('')` · `view.q` · `held|default(0) > 0`

**Links out:** `/` · `/results{{ view.order|default('status') != 'status' ? '?order=' ~ view.order : '` · `/results?programme={{ p.name|url_encode }}{{ view.order|default('status') != 'st` · `/results?order={{ key }}{{ view.programme ? '&programme=' ~ view.programme|url_e` · `{{ e.url }}` · `/results` · `/awards`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×5, aria-current×3, aria-label×1; roles: search; visually-hidden text×3; <label for>×1; autocomplete×1; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CelebrationTest` (whole file, via a helper/constant/data provider: 17 tests) — test_a_released_result_celebrates_its_winner, test_a_held_result_names_nobody_and_celebrates_nobody, test_the_delayed_holding_page_carries_no_celebration, test_the_nominee_page_celebrates_only_a_promoted_nominee, test_the_nominee_page_does_not_put_two_clocks_on_one_number, test_the_query_behind_the_dashboard_panel_actually_runs, test_a_nominee_nobody_has_been_told_about_is_not_congratulated, test_somebody_elses_vote_is_not_your_celebration, test_two_categories_backed_is_two_lines_and_one_key, test_the_script_reveals_nothing_and_therefore_cannot_withhold_it, test_it_makes_no_sound, test_reduced_motion_gets_the_result_and_no_particles …
- `EditionPageTest::test_the_url_a_page_links_and_the_one_the_route_serves_are_minted_once` — (no docblock)
- `EditionPageTest::test_the_sitemap_lists_the_edition_and_every_award_in_it` — (no docblock)
- `EditionPageTest::test_an_unannounced_cycle_is_not_submitted_to_a_crawler` — (no docblock)
- `FeedLinkifyTest::test_a_bare_path_is_not_linkified` — A BARE PATH IS NEVER A LINK.
- `PhaseSurfaceRenderTest::test_nominate_replaces_the_wizard_with_a_real_closed_state` — (no docblock)
- `PublicResultsTest::test_the_sandbox_has_no_public_result_page` — THE REHEARSAL CANNOT REACH THE PUBLIC RECORD.
- `PublicResultsTest::test_the_feed_card_links_to_the_award_rather_than_to_its_own_thread` — NO URL IN THE BODY — THE CARD CARRIES THE LINK.
- `PublicResultsTest::test_the_platform_actually_points_at_the_result_page` — A PAGE WITH NO ROUTE IN IS THIS CODEBASE'S SECOND-MOST-EXPENSIVE BUG.
- `RouteTableIntegrityTest::test_the_matcher_is_neither_greedy_nor_blind` — AND THE DETECTOR HAS TO FIND THE SHAPE IT LOOKS FOR.
- `SiteHeaderTest::test_results_is_reachable_by_browsing` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PublicResultsTest::test_the_index_page_draws_in_each_of_its_states` — The index page draws in all three of its states, and the middle one is the new one. — *an award being verified is not on the page at all*
- `PublicResultsTest::test_the_results_page_states_a_late_cycle_above_the_list` — THE RESULTS PAGE SAYS A CYCLE IS LATE, ABOVE THE LIST AND NOT UNDER IT. — *the results page is silent about a cycle whose promised date has passed*
