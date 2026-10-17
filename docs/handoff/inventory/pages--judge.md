# `templates/pages/judge.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /judges/{slug} → JudgesController::show`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── A JUROR'S CITATION — formal, portrait-led, in the roster's honour
   language (arch portrait, gold keylines, small-caps engraving).
```

**Headings:** {{ judge.name }} · Citation

**Data read (top-level variables/functions):** `judge`, `country`

**States / branches (8 distinct conditions):** `judge.avatar_path` · `not judge.avatar_path` · `country` · `judge.title or judge.organisation` · `judge.title` · `judge.title and judge.organisation` · `judge.bio` · `judge.programmes is not empty`

**Links out:** `/judges` · `/vote/{{ p.slug }}` · `/integrity`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×8, aria-labelledby×3, aria-label×2, aria-current×1; roles: img; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `SeoStructuredDataTest::test_a_judge_page_survives_a_name_full_of_json_hostile_characters` — The escaping test with real teeth. — *a judge page is a name page and must emit Person*
- `SeoStructuredDataTest::test_a_judge_is_not_described_as_a_nominee` — A judge is not a nominee: the award line belongs only on a ballot.
