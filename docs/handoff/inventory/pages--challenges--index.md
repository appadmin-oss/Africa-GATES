# `templates/pages/challenges/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /challenges (walk)`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  /challenges — the list.

  Not in the design pack: §3 specifies the single challenge page and the placements,
  and the handoff's own rule is that "Challenges" appears under Participate in the mega
  menu while at least one is open. A menu entry needs somewhere to land, and a nav item
  pointing at nothing is this codebase's oldest fault — a mechanism with no route in.

  So the list is built in the house card idiom rather than matched to a comp, and every
  line on a card comes from `ChallengeCopy` exactly as the full page does, so a card and
  the page behind it can never promise different prizes.
```

**Headings:** Challenges

**Data read (top-level variables/functions):** `cards`

**States / branches (1 distinct conditions):** `cards|length`

**Links out:** `/challenges/{{ c.slug }}` · `/awards`

**JS behaviours:** data hooks: `data-theme`, `data-state`

**Accessibility affordances:** aria-hidden×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ChallengeCopyTest::test_the_compact_strip_agrees_with_the_page` — The strip beside an award cannot promise a different prize from the page.
- `ChallengePageTest` (whole file, via a helper/constant/data provider: 3 tests) — test_the_comps_sections_render_in_order_from_the_library, test_one_main_landmark, test_each_themes_solid_holds_white_text
