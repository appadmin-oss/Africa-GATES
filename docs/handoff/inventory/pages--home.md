# `templates/pages/home.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET [/] → HomeController::index`
**Extends:** layout/gates.twig · **Includes/imports:** partials/promo-carousel.twig, partials/globe-band.twig, partials/find-band.twig

**What the page says it is (its own header comment, abridged):**
```
 The band is a component with its own file — see docs/GLOBE-BAND.md.
```

**Headings:** African excellence,recognised.recognised, remembered, celebrated, immortalised. · Surface excellence, wherever you find it. · A vote that actually counts. · Ceremonies, webinars &amp; the live stage. · Recognition you live with daily. · Leading this cycle · {{ lw.edition ?: lw.year }} winners · Recognition is a record, not a moment. · Someone you knowdeserves this stage.

**Data read (top-level variables/functions):** `_noms_open`, `lw`, `feat`, `_voting_open`, `_vp`, `evs`, `cpi_recompute_hours`, `leaderboard`, `vmini`, `leaders`, `latest_winners`, `the`, `hm`, `cal__c`, `nominate`, `nomination`, `index`, `calendar`, `announcements`, `mark`, `gold`, `spotlight_profiles`, `site_events`, `site_stats`, `awards_data`, `es`

**States / branches (19 distinct conditions):** `a.cycle_status|default('') == 'nominations'` · `a.cycle_status|default('') == 'voting'` · `_voting_open` · `_noms_open` · `_vp > 0` · `feat` · `vmini|length` · `n.avatar_path` · `not n.avatar_path` · `n.country_code` · `evs|length` · `loop.first` · `e.location` · `leaders|length` · `p.avatar_path` · `not p.avatar_path` · `p.country_code` · `latest_winners and latest_winners.awards|length` · `a.winner.photo`

**Links out:** `/assets/css/globe-band.css` · `/vote` · `{{ _noms_open ? '/nominate' : '/leaderboard' }}` · `/nominate` · `/leaderboard` · `/account/register` · `/events` · `{{ lw.url }}` · `{{ a.url }}` · `/results` · `/legacy`

**JS behaviours:** script `/assets/js/vendor/d3-7.9.0.min.js`; script `/assets/js/vendor/topojson-client-3.1.0.min.js`; script `/assets/js/globe-band.js`; 1 inline <script> block(s); data hooks: `data-page`, `data-countdown`, `data-lottie`

**Accessibility affordances:** aria-hidden×27, aria-label×1; roles: img, presentation; visually-hidden text×1; alt=×1; prefers-reduced-motion×15

**Styling carried:** 1 <style> block(s), 23 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `GlobeBandTest` (whole file, via a helper/constant/data provider: 25 tests) — test_every_mapped_country_exists_in_the_shipped_geometry, test_every_mapped_country_is_in_the_scripts_africa_set, test_the_map_covers_exactly_the_countries_the_form_accepts, test_a_marker_is_a_nation_with_a_nominee_standing_in_a_live_award, test_the_reader_sees_the_sites_name_and_the_script_gets_natural_earths, test_a_nations_nominees_and_votes_are_both_summed, test_only_a_nation_with_a_decided_award_gets_the_ringed_marker, test_a_runner_up_alone_does_not_ring_a_nation, test_markers_are_ordered_by_votes, test_a_nominee_in_an_inactive_programme_gets_no_marker, test_a_pending_nominee_is_not_standing_anywhere, test_a_merge_tombstone_is_not_counted …

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CspTest::test_every_inline_script_on_a_rendered_page_carries_the_nonce` — {$path} rendered no inline scripts — check the fixture, not the CSP — *{$path} has an inline <script> with no nonce, which the browser will refuse to run: {$tag}*
- `CspTest::test_every_style_block_on_a_rendered_page_carries_the_nonce` — {$path} rendered no <style> block — check the fixture — *{$path} has a <style> with no nonce; the page will render unstyled: {$tag}*
- `CspTest::test_no_rendered_page_uses_an_inline_event_handler` — {$path} contains an inline event handler; convert it to data-ag-do
- `CspTest::test_the_rendered_nonce_matches_the_one_the_header_advertises` — The rendered nonce matches the one the header advertises.
- `PageRenderSmokeTest::test_home_renders_seeded_profiles` — Home renders seeded profiles.
