# `templates/pages/judges.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /judges → JudgesController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** Meet the judges · Our panel is being assembled

**Data read (top-level variables/functions):** `judges`, `slug`, `title`, `filters`

**States / branches (8 distinct conditions):** `judges is not empty` · `filters is not empty` · `j.avatar_path` · `not j.avatar_path` · `j.country_code` · `j.title` · `j.organisation` · `j.bio`

**Links out:** `/judges/{{ j.slug }}` · `/integrity`

**JS behaviours:** Alpine x-data: `{ filter: 'all' }`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×5, aria-pressed×2, aria-label×1; roles: tablist

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
