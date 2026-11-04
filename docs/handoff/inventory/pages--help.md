# `templates/pages/help.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /help → HelpController::index`
**Extends:** layout/gates.twig · **Includes/imports:** partials/help-nav.twig

**What the page says it is (its own header comment, abridged):**
```
  THE HELP CENTRE INDEX.

  ── WHAT THIS PAGE IS FOR ───────────────────────────────────────────────────
  Somebody arriving here is stuck, not browsing, and the ticket queue says the top
  arrival by a distance is "I paid and I have no votes". So the order is: search,
  then the four things that go wrong most, then the whole directory. Making a stuck
  person read a category system first is a small unkindness repeated thousands of
  times.

  ── WHAT THE REBUILD CHANGED ────────────────────────────────────────────────
  The behaviour was right and the surface was not. It was a centred hero over a
  centred search over eleven rounded white boxes of identical radius, border and
  elevation — which is the shape a page takes when nobody has decided what is
  important on it. Four "most people are here for" cards each carried the SAME
…
```

**Headings:** {% if q %}Results for “{{ q }}”{% elseif audience|default('') and audiences[audi · {{ results|length }} answer{{ results|length == 1 ? '' : 's' }} · Most people are here for one of these · = 2 ? 'Matching answers' : 'Every topic'">Every topic · An article cannot look up your payment. The assistant can.

**Data read (top-level variables/functions):** `key`, `audience`, `audiences`, `list`, `results`, `preview`, `audience_counts`, `total`, `by_category`, `categories`, `top`, `index`

**States / branches (10 distinct conditions):** `q` · `audience|default('') and audiences[audience] is defined` · `not q` · `not audience|default('')` · `audience_counts[key]|default(0) > 0` · `audience == key` · `results` · `top|length` · `loop.index > preview` · `list|length > preview`

**Links out:** `/help` · `/help?for={{ key }}` · `'/help?q=' + encodeURIComponent(filter)` · `/help/{{ a.slug }}` · `/support/assistant?q={{ q|url_encode }}` · `/help/c/{{ key }}` · `'/support/assistant?q=' + encodeURIComponent(filter)` · `/support/assistant` · `/support`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `{
       filter: '',
       index: [],
       init() { try {`; data hooks: `data-page`

**Accessibility affordances:** aria-current×2, aria-hidden×2, aria-label×1, aria-live×1

**Legal / consent lines:**
- foot of it. "Privacy · 2" sets an honest expectation before the click. */

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `GeeSupportsTest::test_with_no_model_at_all_gee_still_answers_from_the_help_centre` — (no docblock)
- `GeeSupportsTest::test_the_cards_lead_with_what_the_answer_was_built_from` — (no docblock)
- `GeeSupportsTest::test_cited_slugs_are_read_out_of_the_agents_own_tool_results` — (no docblock)
- `GeeSupportsTest::test_the_widget_links_a_whole_help_article_url_and_not_its_prefix` — A Help Centre URL must be linked WHOLE.
- `GeeSupportsTest::test_the_article_pattern_cannot_break_out_of_the_attribute` — The slug pattern must stay narrow enough that it cannot escape an href.
- `HelpCentreLayoutTest::test_every_category_has_a_page_listing_all_of_its_answers` — Every category resolves, and shows every answer it has WITH ITS SUMMARY.
- `HelpCentreLayoutTest::test_an_unknown_category_goes_to_the_index_rather_than_a_dead_end` — A stale or invented category is a person with a question, not a 404.
- `HelpCentreLayoutTest::test_the_category_route_is_not_swallowed_by_the_article_route` — The category route must not be shadowed by the article route.
- `HelpCentreLayoutTest::test_a_long_category_defers_the_rest_to_its_own_page` — A long category shows a few titles and a real link to the rest.
- `HelpCentreLayoutTest::test_the_deferred_titles_are_present_in_the_page_but_marked_out` — Everything past the preview is rendered but marked out, not omitted.
- `HelpCentreLayoutTest::test_the_search_corpus_is_embedded_as_json_not_as_an_attribute` — The corpus is embedded as JSON in a script tag, NOT interpolated into an x-data attribute.
- `HelpCentreLayoutTest::test_class_bindings_use_object_syntax_so_the_static_class_can_be_cleared` — `:class` bindings use OBJECT syntax.
- `HelpCentreLayoutTest::test_the_get_search_still_works_and_is_still_scored` — Enter still performs the real, scored, shareable server search.
- `HelpCentreLayoutTest::test_a_search_with_no_answer_hands_over_instead_of_dead_ending` — (no docblock)
- `HelpCentreTest::test_the_assistant_reads_the_same_articles_as_the_page` — THE STRUCTURAL POINT OF THE WHOLE FILE.
- `PublicIaTest::test_the_sweep_would_notice_an_orphan` — (no docblock)
- `SeoCanonicalTest::test_an_internal_search_result_is_noindex_follow` — Google asks explicitly that site-search results stay out of the index, and an unbounded query space is a crawl trap.
- `SeoCanonicalTest::test_an_empty_search_box_is_still_the_indexable_page` — (no docblock)
- `SeoCanonicalTest::test_an_array_shaped_parameter_does_not_warn` — `?q[]=a&q[]=b` parses to an array, and every reader here casts to string — which on an array is a warning plus the literal "Array".
- `SeoSitemapTest::test_help_answers_are_listed_with_the_corpus_file_date` — The help corpus lives in a PHP file rather than a table, and it is the one section whose `lastmod` can be exact — the mtime of the file that holds it.
- `SeoStructuredDataTest::test_the_help_breadcrumb_points_at_the_category_page_not_a_search` — The visible crumb used to link `/help?q={category title}` — a search URL, which is now noindex.
- `SupportSurfaceRenderTest::test_the_compact_lines_second_clause_is_overridable` — And a caller can point that clause somewhere else when the context differs.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `HelpCentreLayoutTest::test_a_long_category_defers_the_rest_to_its_own_page` — A long category shows a few titles and a real link to the rest. — *the link must name how many are behind it*
- `HelpCentreLayoutTest::test_a_search_with_no_answer_hands_over_instead_of_dead_ending` — and it carries the question across rather than making them retype it
- `HelpCentreLayoutTest::test_class_bindings_use_object_syntax_so_the_static_class_can_be_cleared` — `:class` bindings use OBJECT syntax. — *string-ternary :class has come back*
- `HelpCentreLayoutTest::test_every_category_has_a_page_listing_all_of_its_answers` — Every category resolves, and shows every answer it has WITH ITS SUMMARY.
- `HelpCentreLayoutTest::test_the_category_route_is_not_swallowed_by_the_article_route` — The category route must not be shadowed by the article route. — *this is the category template, not an article*
- `HelpCentreLayoutTest::test_the_deferred_titles_are_present_in_the_page_but_marked_out` — Everything past the preview is rendered but marked out, not omitted. — *no category is long enough for this test to mean anything*
- `HelpCentreLayoutTest::test_the_get_search_still_works_and_is_still_scored` — Enter still performs the real, scored, shareable server search.
- `HelpCentreLayoutTest::test_the_search_corpus_is_embedded_as_json_not_as_an_attribute` — The corpus is embedded as JSON in a script tag, NOT interpolated into an x-data attribute. — *the x-data attribute is unterminated — the corpus has leaked into it*
