# `templates/pages/results/late.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /results/{slug:[0-9]+[^/]*} → ResultsController::show`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ONE AWARD, PROMISED AND NOT YET ANNOUNCED.

  ── WHY THIS PAGE EXISTS AT ALL ─────────────────────────────────────────────

  `PublicResults` used to serve a result page as soon as the cycle's results date passed,
  announced or not. So a cycle still in `judging` published a full standing with a named
  winner and an index — from a panel that was still open, and with no sealed record behind
  it, which is why the page also printed "Recomputed under current rules". The gate is the
  announcement now: `results` or `archived`, written by `CycleMaterialiser` in the same
  transaction that crowns the winners and — where the announcement actually goes out —
  seals the standing. A cycle corrected long after its boundary promotes and publishes but
  is NOT sealed: on that path every notification is deliberately withheld, and a seal
  claims to be the standing that was announced.

…
```

**Headings:** {% if late.award %}{{ late.award }}{% else %}This award{% endif %} has not been 

**Data read (top-level variables/functions):** `late`

**States / branches (3 distinct conditions):** `late.edition` · `late.award` · `late.note`

**Links out:** `/results` · `/awards`

**JS behaviours:** data hooks: `data-page`

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CelebrationTest::test_the_delayed_holding_page_carries_no_celebration` — (no docblock)
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `PublicResultsTest` (whole file, via a helper/constant/data provider: 39 tests) — test_a_released_cycle_has_a_public_result, test_a_cycle_that_has_not_released_has_no_public_result, test_a_passed_results_date_does_not_publish_an_unannounced_result, test_a_late_cycle_is_stated_rather_than_left_silent, test_the_delay_carries_an_operators_note_when_there_is_one, test_a_late_award_resolves_from_its_own_category_link, test_a_results_date_still_in_the_future_does_not, test_the_sandbox_has_no_public_result_page, test_a_result_with_no_community_half_is_held_rather_than_published, test_a_category_that_crowns_nobody_is_held, test_held_results_are_counted_on_the_index, test_every_public_figure_is_the_release_screens_own …

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CelebrationTest::test_the_delayed_holding_page_carries_no_celebration` — The delayed holding page carries no celebration.
- `PublicResultsTest::test_the_late_page_names_the_award_and_the_date_it_missed` — AND THE AWARD'S OWN LINK ANSWERS, NAMING THE AWARD AND THE DATE. — *the page does not say which award the reader was sent to*
- `PublicResultsTest::test_the_late_page_stands_without_an_operators_note` — And with no note the page still stands, without inventing a cause. — *an empty note is rendering a heading with nothing under it*
