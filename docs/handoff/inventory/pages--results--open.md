# `templates/pages/results/open.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /results/{edition:[a-z][a-z0-9-]*} → ResultsController::edition`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── COLOUR BUDGET: tier 1, one event ──────────────────────────────────────────
   Whether this award is counting or with the panel is the single thing this page
   states, so the live field is its one event and it appears only while voting is
   actually open. A panel's progress is a neutral bar: it is a total, not a state,
   and it is a statement about OUR work rather than about anybody on the list.
```

**Headings:** {{ e.edition ?: e.year }} · The awards in this edition · {{ c.title }}

**Data read (top-level variables/functions):** `colour_tier`

**States / branches (4 distinct conditions):** `e.status.key == 'counting'` · `e.closes` · `e.promised` · `c.progress`

**Links out:** `/` · `/results` · `/vote`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×2, aria-valuemin×1, aria-valuemax×1, aria-valuenow×1, aria-label×1; roles: progressbar; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
