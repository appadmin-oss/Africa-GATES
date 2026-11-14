# `templates/pages/blog/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /blog → BlogController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** Notes from the index. · Latest posts · {{ p.title }} · The first story is being written

**Data read (top-level variables/functions):** `posts`

**States / branches (5 distinct conditions):** `posts is not empty` · `not p.cover_image` · `p.cover_image` · `p.tag` · `p.excerpt`

**Links out:** `/blog/{{ p.slug }}` · `/nominate`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×2; visually-hidden text×1

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `PageRenderSmokeTest::test_blog_renders_published_posts` — (no docblock)
- `VisitTrackerTest::test_token_shaped_segments_are_starred_and_real_slugs_are_not` — A list of known routes and a shape rule, because the failure mode is forgetting.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PageRenderSmokeTest::test_blog_renders_published_posts` — Blog renders published posts.
