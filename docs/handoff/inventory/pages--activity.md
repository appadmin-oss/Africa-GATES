# `templates/pages/activity.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /activity → ActivityController::index`
**Extends:** layout/gates.twig · **Includes/imports:** partials/find-band.twig

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   Activity — one searchable timeline of everything happening on the platform.

   BUILT AS A WORKING FORM FIRST. The <form method="get"> below submits to this
   same page and renders results server-side, so the search works with no
   JavaScript, with JavaScript that failed to load, and on a browser too old for
   fetch(). For an audience that is largely low-end Android on an intermittent
   connection, a script that did not arrive is routine — and a search box that is
   only a script is a search box that is sometimes just missing.

   The script at the bottom UPGRADES that form into an ARIA combobox that answers
   as you type. It never replaces the form: if it does not run, everything still
   works, just with a round trip per search.

…
```

**Headings:** Activity

**Data read (top-level variables/functions):** `understood`, `it`, `items`, `sources`, `filtered`, `search`, `min_query`, `literal`

**States / branches (8 distinct conditions):** `q` · `understood` · `understood.kinds` · `understood.country` · `understood.days` · `literal and q` · `sources is defined and sources < 7` · `it.detail`

**Links out:** `/activity?q={{ q|url_encode }}&amp;literal=1` · `/activity?q={{ q|url_encode }}` · `{{ it.url }}` · `' + esc(it.url) + '`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-active`; fetches: `/activity/search?limit=40&q=`

**Accessibility affordances:** aria-labelledby×1, aria-live×1, aria-atomic×1, aria-selected×1; roles: status, combobox, option, presentation; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ActivityPageAccessibilityTest::test_the_search_is_a_real_get_form_that_submits_to_a_real_route` — (no docblock)
- `FindBandTest::test_the_field_posts_to_the_search_that_already_exists` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `ActivityPageAccessibilityTest::test_the_search_is_a_real_get_form_that_submits_to_a_real_route` — the search must submit without JavaScript — *the query must be a real form field*
- `ActivityPageAccessibilityTest::test_results_are_rendered_server_side` — a ?q= request must return results in the HTML, not an empty shell for a script to fill
- `ActivityPageAccessibilityTest::test_the_form_carries_the_search_landmark` — The form carries the search landmark.
- `ActivityPageAccessibilityTest::test_the_input_has_a_real_label_not_only_a_placeholder` — the search field is gone — *the search input has no id, so no <label for> can reach it*
- `ActivityPageAccessibilityTest::test_the_field_is_labelled_by_something_a_sighted_reader_can_see` — nothing visible above the field says what it is for
- `ActivityPageAccessibilityTest::test_the_markup_does_not_claim_to_be_a_combobox` — The markup does not claim to be a combobox.
- `ActivityPageAccessibilityTest::test_the_script_declares_the_full_combobox_contract` — missing: {$needle}
- `ActivityPageAccessibilityTest::test_the_active_option_is_tracked_without_moving_focus` — the active option must not be focused — use aria-activedescendant
- `ActivityPageAccessibilityTest::test_every_key_the_pattern_requires_is_handled` — keyboard support missing for {$key}
- `ActivityPageAccessibilityTest::test_enter_with_nothing_highlighted_still_submits_the_form` — preventDefault on Enter must be conditional on an option being active
- `ActivityPageAccessibilityTest::test_escape_closes_before_it_clears` — the first Escape must close the list and only a second one clear the input
- `ActivityPageAccessibilityTest::test_there_is_a_polite_status_region_that_exists_before_it_has_content` — the whole sentence must be re-read, not the changed word alone
- `ActivityPageAccessibilityTest::test_the_status_line_is_visible_text_and_not_only_announced` — The status line is visible text and not only announced.
- `ActivityPageAccessibilityTest::test_the_result_list_is_not_itself_a_live_region` — The result list is not itself a live region.
- `ActivityPageAccessibilityTest::test_a_count_of_one_is_not_pluralised` — A count of one is not pluralised.
- `ActivityPageAccessibilityTest::test_an_empty_result_explains_what_to_try_instead` — An empty result explains what to try instead.
- `ActivityPageAccessibilityTest::test_focus_is_visible_on_every_interactive_element` — Focus is visible on every interactive element.
- `ActivityPageAccessibilityTest::test_the_keyboard_active_state_is_styled_and_not_only_hover` — The keyboard active state is styled and not only hover.
- `ActivityPageAccessibilityTest::test_reduced_motion_is_respected_without_hiding_the_busy_state` — Reduced motion is respected without hiding the busy state.
- `ActivityPageAccessibilityTest::test_touch_targets_meet_the_minimum_size` — Touch targets meet the minimum size.
- `ActivityPageAccessibilityTest::test_a_timestamp_is_machine_readable_as_well_as_human_readable` — A timestamp is machine readable as well as human readable.
- `ActivityPageAccessibilityTest::test_the_page_has_exactly_one_h1_and_it_names_the_page` — exactly one h1 — *the region must be named by its own heading*
- `ActivityPageAccessibilityTest::test_every_inline_block_carries_the_csp_nonce` — un-nonced inline block: {$tag}
- `ActivityPageAccessibilityTest::test_the_query_is_escaped_where_it_is_echoed_back` — the query must never be reflected unescaped — *no tag may be formed from the query*
- `FindBandTest::test_a_source_with_no_public_noun_is_still_covered_by_the_sentence` — this test is vacuous if every source is named — *sources are searched that the sentence neither names nor admits to*
- `FindBandTest::test_every_named_source_appears_in_the_sentence` — Every named source appears in the sentence.
- `FindBandTest::test_the_live_search_hooks_survive_on_the_search_surface` — the search input lost the hook the live-search script binds to
- `FindBandTest::test_the_promise_is_printed` — The promise is printed.
