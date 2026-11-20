# `templates/pages/legacy/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /legacy → LegacyController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 Quote/manifesto block — keeps the page rich even on slim content
```

**Headings:** Every cycle, archived. · A continent of recognition, preserved · The archive · {{ L.title }} · The vault opens after the first cycle

**Data read (top-level variables/functions):** `rows`, `carry`, `assets`, `img`, `africa`, `events`, `watermark`, `mark`

**States / branches (3 distinct conditions):** `rows is not empty` · `not L.cover_path` · `L.cover_path`

**Links out:** `/legacy/{{ L.slug }}` · `/events`

**JS behaviours:** data hooks: `data-count`

**Accessibility affordances:** aria-hidden×4; alt=×3

**Styling carried:** 0 <style> block(s), 12 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
