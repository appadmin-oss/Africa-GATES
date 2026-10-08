# `templates/pages/registry/register.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 `novalidate` + `data-ag-validate`: the browser's own bubble off, and this
   site's message in its place — styled, translatable, and announced by the same
   summary the server renders. The pair is swept for; neither travels alone.
```

**Headings:** Get on the board.

**Data read (top-level variables/functions):** `old`, `val`, `code`, `error`, `lbl`, `name`

**Forms:**
- `POST /register` [novalidate] fields: _token[hidden], display_name[text required], profile_type[select], category[text], country_code[select required], bio[textarea], email[email required], website[url]; buttons: Register profile

**States / branches (3 distinct conditions):** `error` · `old.profile_type|default('individual') == val` · `old.country_code|default('') == code`

**Links out:** `/awards`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×2; roles: alert; <label for>×7

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
