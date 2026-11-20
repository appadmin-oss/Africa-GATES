# `templates/pages/status.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /status → closure routes.php:3053`
**Extends:** layout/gates.twig · **Includes/imports:** partials/support-prompt.twig

**What the page says it is (its own header comment, abridged):**
```
  ── THIS PAGE IS A MEASUREMENT, NOT A REASSURANCE ─────────────────────────────

  The version this replaced hard-coded its OWN fallback component list in Twig
  defaults, so even when the route passed nothing the page still printed six
  green rows. Two independent places were therefore asserting health that
  neither of them had checked.

  There are no defaults here on purpose. If the route stops passing a report the
  page says so instead of inventing one.

  ── THE ANATOMY IS THE ONE PEOPLE ALREADY KNOW ────────────────────────────────

  Overall verdict, then a component list where each row carries its state, its
  live measurement and its recent history, then the incidents, then how to read
…
```

**Headings:** We could not check just now · {{ headline|raw }} · Current status {{ components|length }} services · Recent problems {{ history_days|default(14) }} days · How to read this

**Data read (top-level variables/functions):** `em`, `uptime`, `components`, `working`, `history`, `note`, `history_days`, `report_ok`, `incidents`, `headline`, `checked_at`, `recorded`, `history_note`, `same_day`, `label`, `things`, `are`, `than`, `usual`, `checked`, `of`, `checks`, `came`, `back`, `yet`, `status_labels`, `tz_abbr`, `overall`, `we`, `can`, `check`, `slower`, `component_history`

**States / branches (12 distinct conditions):** `not report_ok` · `note is defined and note` · `uptime is defined and uptime.pct is not null` · `c.detail` · `c.metric` · `h and h.uptime is not null` · `history is defined and history is not empty` · `history_note` · `report_ok` · `incidents is defined and incidents is not empty` · `i.ongoing` · `i.minutes > 0`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×4, aria-label×1; roles: img

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `SeoSitemapTest::test_the_public_surfaces_and_policies_are_all_listed` — Each of these returns 200, is indexable, and was in no section.
- `StatusHistoryTest` (whole file, via a helper/constant/data provider: 27 tests) — test_each_component_gets_its_own_history, test_the_worst_state_of_a_day_is_the_days_state, test_a_renamed_component_is_dropped_rather_than_misattributed, test_an_unrecognised_state_is_dropped_not_defaulted, test_a_window_with_a_failure_can_never_read_as_a_clean_hundred, test_a_flawless_window_reads_as_a_hundred, test_the_figure_is_floored_not_rounded, test_no_checks_means_no_percentage, test_unmeasured_checks_are_in_neither_half_of_the_fraction, test_a_run_of_failing_checks_is_a_single_incident, test_a_recovery_between_two_runs_makes_two_incidents, test_a_wobble_that_became_an_outage_is_reported_as_an_outage …
- `SystemStatusTest::test_the_template_has_no_hard_coded_component_list` — The template must not carry a fallback board of its own.
- `SystemStatusTest::test_the_page_renders_the_measured_report` — The template must actually render the report, not merely be free of the old fiction.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `StatusHistoryTest::test_a_clean_window_says_nothing_went_wrong` — A clean fortnight says so, rather than showing an empty heading.
- `StatusHistoryTest::test_the_page_carries_a_legend` — Four states, four fills, and a key — otherwise the fills mean nothing to a reader.
- `StatusHistoryTest::test_the_page_draws_a_history_bar_per_component` — The bars reach the page, and a screen reader gets the summary rather than the cells. — *the per-component bars*
- `StatusHistoryTest::test_the_page_lists_recent_problems` — The incident list reaches the page with its duration. — *a problem with no duration is a rumour*
- `StatusHistoryTest::test_the_page_names_the_endpoint` — And it points at the machine-readable board, because monitors are readers too.
- `SystemStatusTest::test_the_page_renders_the_measured_report` — The template must actually render the report, not merely be free of the old fiction. — *the state must be printed as a WORD, not carried by colour alone*
- `SystemStatusTest::test_the_template_has_no_hard_coded_component_list` — The template must not carry a fallback board of its own. — *a defaulted component list is a board that renders green with no data*
