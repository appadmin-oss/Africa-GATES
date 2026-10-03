# `templates/pages/results/edition.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /results/{edition:[a-z][a-z0-9-]*} → ResultsController::edition`
**Extends:** layout/gates.twig · **Includes/imports:** partials/tile.twig, partials/celebrate.twig

**What the page says it is (its own header comment, abridged):**
```
 ── COLOUR BUDGET: tier 1, and the one event is the honour band ───────────────
   A decided edition is one of exactly two page types allowed to cash their single
   tile in for a full-bleed field — the other is the hall of fame. That is the
   rarest thing on this platform getting the loudest treatment, and it is
   enforceable because it is a list of two.
   Every other status on the page is unpainted: a withheld award is an OUTLINE and
   never a fill, because on a page that is mostly good news the absence of one
   result must not be the loudest thing on it. See ColourBudgetTest.
```

**Headings:** {{ e.edition ?: e.year }} · {{ top.winner.name }} · The awards in this edition · {{ c.title }}

**Data read (top-level variables/functions):** `top`, `tag`, `also`, `winners`, `_site_url`, `ed`, `ph`, `lands`, `land`, `colour_tier`, `colour_band`

**States / branches (12 distinct conditions):** `not loop.last` · `top` · `top.winner.photo` · `e.overall and e.overall.margin is not null and e.overall.margin > 0` · `e.awards|length > 1` · `also|length` · `e.overall and e.overall.provisional` · `c.url` · `c.status.key == 'counting'` · `c.progress` · `c.status.publishes_standing and c.award` · `c.status.key == 'withheld'`

**Links out:** `/` · `/results` · `{{ top.url }}` · `{{ c.url }}` · `/integrity`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-celebrate`

**Accessibility affordances:** aria-hidden×6, aria-valuemin×1, aria-valuemax×1, aria-valuenow×1, aria-label×1; roles: progressbar; alt=×1; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_the_band_privilege_is_still_a_list_of_two` — (no docblock)
- `EditionPageTest::test_a_winner_with_no_photograph_still_gets_a_card` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `EditionPageTest::test_a_winner_with_no_photograph_still_gets_a_card` — A winner with no photograph still gets a card.
- `EditionPageTest::test_a_withheld_award_makes_the_edition_top_provisional_and_says_why` — AND THE CAVEAT IS ABOUT OUR PROCESS, NEVER ABOUT THE PERSON UNDER IT.
- `EditionPageTest::test_an_award_being_checked_is_named_on_the_page` — A WITHHELD AWARD IS A ROW, NOT A NUMBER IN A SENTENCE. — *the fixture no longer produces a withheld award*
- `EditionPageTest::test_the_arithmetic_is_collapsed_and_not_cut` — a printed result folds its working away
- `EditionPageTest::test_the_edition_names_who_came_second_and_third_in_it` — SECOND AND THIRD IN THE EDITION, WHICH EXIST NOWHERE ELSE ON THIS SITE. — *the edition has no standing of its own*
- `EditionPageTest::test_the_face_reaches_the_page` — the portrait never reached the markup — *Oluwagbemiga Dorcas*
- `ColourBudgetTest::test_the_band_privilege_is_still_a_list_of_two (expected list emptied)` **(guard kept, edited)** — The honour band (`{% set colour_band = true %}`, one extra colour event replacing the tile with a field) belongs to exactly two pages: the decided edition and the hall of fame. Never a third.
