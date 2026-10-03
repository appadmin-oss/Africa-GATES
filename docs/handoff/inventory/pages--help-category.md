# `templates/pages/help-category.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /help/c/{cat:[a-z0-9-]+} → HelpController::category`
**Extends:** layout/gates.twig · **Includes/imports:** partials/help-nav.twig

**What the page says it is (its own header comment, abridged):**
```
  ONE TOPIC, EVERY ANSWER IN IT.

  ── WHY THIS PAGE EXISTS ────────────────────────────────────────────────────
  The Help Centre index used to print all 33 answers inline, because a category had
  nowhere to lead. The corpus is deeply uneven — 12 answers under "Results &
  integrity", 2 under "Privacy" — so in a two-column grid each row was as tall as
  its taller card and left a column of empty page beside the shorter one.

  Giving a topic its own page fixed the index (five titles, then a link) and added
  something the index never had: a URL for a whole topic, which support can paste
  and a search engine can index.

  ── SUMMARIES, NOT JUST TITLES ──────────────────────────────────────────────
  A topic page is a decision point, not a directory. Somebody who has already chosen
…
```

**Headings:** {{ category.title }} · Other topics · The assistant can do what an article cannot.

**Data read (top-level variables/functions):** `category`, `key`, `articles`, `counts`, `categories`, `category_key`

**States / branches (1 distinct conditions):** `key != category_key`

**Links out:** `/help` · `/help/{{ a.slug }}` · `/help/c/{{ key }}` · `/support/assistant`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×2, aria-label×1

**Legal / consent lines:**
- shown because "Privacy · 2" sets an honest expectation before the click. */

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
