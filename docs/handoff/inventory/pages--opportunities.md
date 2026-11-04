# `templates/pages/opportunities.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /opportunities → OpportunityController::index`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 DB-driven: OpportunityController passes `opportunities` (from gates_opportunities
   via OpportunityService). No fabricated fallback — if there are no live listings
   we show an honest empty state, never fake grants with dead apply links.
```

**Headings:** Grants, fellowships &amp; mentor circles · Open now · {{ o.title }} · Bring an opportunity to the registry

**Data read (top-level variables/functions):** `rows`, `opportunities`

**States / branches (2 distinct conditions):** `rows|length` · `o.deadline`

**Links out:** `{{ o.apply_url|default('#') }}` · `/account/register` · `/partner` · `mailto:opportunities@afrovanguard.org.ng`

**JS behaviours:** data hooks: `data-anim-delay`, `data-anim`

**Accessibility affordances:** aria-hidden×5

**Styling carried:** 0 <style> block(s), 6 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CheckInThanksTest::test_a_night_with_nothing_open_does_not_say_zero` — With nothing open, the message does not announce a zero.
- `PageRenderSmokeTest::test_opportunities_renders_listings_not_empty_state` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PageRenderSmokeTest::test_opportunities_renders_listings_not_empty_state` — Opportunities renders listings not empty state.
