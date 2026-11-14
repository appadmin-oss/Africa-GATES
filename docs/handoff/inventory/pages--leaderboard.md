# `templates/pages/leaderboard.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /leaderboard → LeaderboardController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── Podium (top 3) ──
```

**Headings:** Cultural Power Index · The first cycle hasn’t been ranked yet

**Data read (top-level variables/functions):** `rows`, `catset`, `has_entries`, `pal`, `live`, `assets`, `img`, `africa`, `mark`, `row`, `top`, `fff8df`, `effaf0`, `e9efef`, `f3eef7`, `fdeaf0`, `b03a5b`, `entries`

**States / branches (3 distinct conditions):** `has_entries` · `not has_entries` · `e.category and e.category not in catset`

**Links out:** `/nominate` · `/vote` · `/registry/{{ e.slug }}` · `/integrity`

**JS behaviours:** Alpine x-data: `{ cat:'All' }`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×2, aria-pressed×2, aria-label×2; roles: list, listitem; alt=×1

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `CspTest::test_every_inline_script_on_a_rendered_page_carries_the_nonce` — (no docblock)
- `CspTest::test_no_rendered_page_uses_an_inline_event_handler` — (no docblock)
- `FormAccessibilityTest::test_the_public_pages_have_no_unlabelled_control` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `PageRenderSmokeTest::test_leaderboard_renders_entries_not_empty_state` — (no docblock)
- `PublicResultsTest::test_the_platform_actually_points_at_the_result_page` — A PAGE WITH NO ROUTE IN IS THIS CODEBASE'S SECOND-MOST-EXPENSIVE BUG.
- `SeoCanonicalTest::test_campaign_parameters_are_stripped` — (no docblock)
- `SeoCanonicalTest::test_a_filter_is_canonicalised_away_but_not_deindexed` — A facet is a near-duplicate: canonicalise it away, but leave it indexable.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PageRenderSmokeTest::test_leaderboard_renders_entries_not_empty_state` — top profile must appear in the ranking — *must NOT show the pre-cycle empty state*
