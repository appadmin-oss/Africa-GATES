# `templates/pages/results/hall.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /winners → ResultsController::hall`
**Extends:** layout/gates.twig · **Includes/imports:** partials/tile.twig

**What the page says it is (its own header comment, abridged):**
```
 ── COLOUR BUDGET: tier 1, under the band privilege ───────────────────────────
   A hall of fame is one of exactly two page types allowed to replace its single
   colour tile with a full-bleed honour field — the other is a decided edition.
   That is the rarest thing on this platform getting the loudest treatment, and it
   is only safe as a list of two, so the privilege is declared and the
   declarations are counted. See ColourBudgetTest.
   Everything else here is ink, a hairline, or a programme hue on a spine — and a
   spine is structure, which is why the programme palette was cut to three.
```

**Headings:** Everyone Africa GATES has honoured · Overall winners · {{ p.name }} · Category winners · No award has been announced yet · Nobody here matches that

**Data read (top-level variables/functions):** `hall`, `base`, `ed`, `are`, `colour_tier`, `colour_band`, `winners`

**Forms:**
- `GET {{ base }}` fields: q[search autocomplete]; buttons: Search

**States / branches (26 distinct conditions):** `hall.people|length` · `hall.reach.from and hall.reach.to and hall.reach.from != hall.reach.to` · `hall.reach.to` · `hall.repeat` · `not v.programme and not v.edition and not v.repeat and not v.q and not v.letter` · `v.programme == p.name` · `v.edition == ed.year` · `v.repeat` · `hall.overall_people|default([])|length` · `p.photo` · `w.year` · `w.overall_margin is not null and w.overall_margin > 0` · `w.awards_in_edition` · `w.overall_provisional` · `p.count > 1 or w.edition_url` · `p.count > 1` · `w.edition_url` · `hall.category_people|default([])|length` · `hall.letters|default([])|length > 1` · `v.letter == l` · `v.letter` · `not hall.people|length` · `not hall.shown` · `v.q` · `hall.held` · `hall.truncated`

**Links out:** `/` · `/results` · `{{ base }}?repeat=1` · `{{ base }}` · `{{ base }}?programme={{ p.name|url_encode }}` · `{{ base }}?edition={{ ed.year }}` · `{{ w.url }}` · `{{ w.edition_url }}` · `{{ base }}?letter={{ l }}` · `/integrity`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×7, aria-current×5, aria-label×2, aria-labelledby×2; roles: search; visually-hidden text×3; <label for>×1; alt=×2; autocomplete×1

**Legal / consent lines:**
- Stretching is what made the two cards disagree. A stretched item takes the

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_the_band_privilege_is_still_a_list_of_two` — (no docblock)
- `HallOfFameTest::test_a_winner_with_no_photograph_still_gets_a_card` — (no docblock)
- `HallOfFameTest::test_the_page_spends_its_colour_on_one_role` — (no docblock)
- `HallOfFameTest::test_the_two_sections_are_told_apart_without_any_colour` — And the hierarchy survives the colour being covered.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `HallOfFameTest::test_a_section_heading_is_not_crushed_against_its_own_caption` — A section heading is not crushed against its own caption.
- `HallOfFameTest::test_a_winner_with_no_photograph_still_gets_a_card` — A winner with no photograph still gets a card.
- `HallOfFameTest::test_the_cards_go_one_up_before_the_name_column_is_starved` — The cards go one up before the name column is starved.
- `HallOfFameTest::test_the_category_cards_are_level_too` — the grid stretches the <li> and the card inside it stays its own height
- `HallOfFameTest::test_the_empty_hall_does_not_read_as_a_fault` — The empty hall does not read as a fault.
- `HallOfFameTest::test_the_name_keeps_a_usable_column_at_the_narrowest_two_up_width` — The name keeps a usable column at the narrowest two up width.
- `HallOfFameTest::test_the_page_draws_the_people_and_marks_a_repeat_winner` — the fixture did not produce a repeat winner — *the portrait has no accessible name*
- `HallOfFameTest::test_the_page_spends_its_colour_on_one_role` — the hall is painting its own mark instead of using the tile — *the tile is asked for by role or hue rather than by meaning*
- `HallOfFameTest::test_the_portrait_photo_is_taken_out_of_flow` — the photo is absolutely positioned, so its box must be the containing block
- `HallOfFameTest::test_the_two_overall_cards_are_level` — the two overall cards size themselves independently again
- `HallOfFameTest::test_the_two_sections_are_told_apart_without_any_colour` — And the hierarchy survives the colour being covered.
- `ColourBudgetTest::test_the_band_privilege_is_still_a_list_of_two (expected list emptied)` **(guard kept, edited)** — The honour band (`{% set colour_band = true %}`) belongs to exactly two pages: the decided edition and the hall of fame. Never a third.
