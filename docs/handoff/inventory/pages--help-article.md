# `templates/pages/help-article.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /help/{slug:[a-z0-9-]+} → HelpController::article`
**Extends:** layout/gates.twig · **Includes/imports:** partials/help-nav.twig

**What the page says it is (its own header comment, abridged):**
```
  ONE ANSWER, ON ITS OWN PAGE.

  ── WHAT THE SECOND PASS ADDED, AND WHY EACH ONE EARNS ITS SPACE ────────────

  READING PROGRESS   A hairline at the very top. On a phone the article is four
                     or five screens and there is no other signal of how much is
                     left — and "how much more of this is there" is exactly the
                     question somebody asks before giving up and opening a ticket.

  READ TIME          "1 min read" beside the category. Same job, before the scroll
                     rather than during it.

  COPY LINK          Because pasting an answer into a reply is a STATED workflow
                     for this team — it is half the reason articles got URLs at
…
```

**Headings:** {{ article.title }} · Related · This is a general answer · All of {{ category.title }}

**Data read (top-level variables/functions):** `article`, `prev`, `next`, `category`, `related`, `category_key`, `read_minutes`, `siblings`

**States / branches (8 distinct conditions):** `block.p is defined` · `block.steps is defined` · `block.note is defined` · `prev or next` · `prev` · `next` · `related` · `s.slug == article.slug`

**Links out:** `/help` · `/help/c/{{ category_key|url_encode }}` · `/support/assistant?q={{ article.title|url_encode }}` · `/help/{{ prev.slug }}` · `/help/{{ next.slug }}` · `/help/{{ r.slug }}` · `/support/assistant` · `/help/{{ s.slug }}`

**JS behaviours:** Alpine x-data: `{
       copied:false, said:'', pct:0,
       sel:'', selX:0`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×6, aria-label×3, aria-current×1

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `HighlightToAskTest` (whole file, via a helper/constant/data provider: 11 tests) — test_both_ends_of_the_selection_are_checked, test_containment_is_limited_to_the_answer_and_its_summary, test_the_pill_is_clamped_into_the_viewport, test_the_flip_has_a_style_to_go_with_it, test_the_pill_is_repositioned_on_scroll_and_resize, test_it_drops_the_pill_once_the_selection_leaves_the_viewport, test_a_trivial_selection_is_not_a_question, test_the_passage_is_capped, test_the_button_hands_the_passage_over_as_a_parameter_the_assistant_reads, test_the_x_data_attribute_contains_no_double_quote, test_selectors_inside_x_data_use_single_quotes

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `HighlightToAskTest::test_both_ends_of_the_selection_are_checked` — The start of the selection must be inside the answer.
- `HighlightToAskTest::test_containment_is_limited_to_the_answer_and_its_summary` — The rail, the nav and the feedback block are not the answer.
- `HighlightToAskTest::test_the_pill_is_clamped_into_the_viewport` — The horizontal clamp needs the viewport width. — *The vertical checks need the viewport height.*
- `HighlightToAskTest::test_the_flip_has_a_style_to_go_with_it` — The flag has to reach the element.
- `HighlightToAskTest::test_the_pill_is_repositioned_on_scroll_and_resize` — A rotation changes every coordinate it was placed with.
- `HighlightToAskTest::test_it_drops_the_pill_once_the_selection_leaves_the_viewport` — It drops the pill once the selection leaves the viewport.
- `HighlightToAskTest::test_a_trivial_selection_is_not_a_question` — A stray tap selects one word, and one word is not a question.
- `HighlightToAskTest::test_the_passage_is_capped` — The assistant should get a question, not a pasted essay.
- `HighlightToAskTest::test_the_button_hands_the_passage_over_as_a_parameter_the_assistant_reads` — The selected passage has to be URL-encoded into the handover. — *The assistant must read ?q= or this button goes nowhere useful.*
- `HighlightToAskTest::test_the_x_data_attribute_contains_no_double_quote` — No double quote anywhere inside the `x-data` attribute. — *Exactly one root x-data is expected on this page.*
- `HighlightToAskTest::test_selectors_inside_x_data_use_single_quotes` — Single quotes are the only string delimiter available in there.
- `SeoStructuredDataTest::test_a_help_answer_emits_an_faqpage_built_from_its_own_words` — A help answer whose title IS the question is an FAQPage in the literal sense. — *the answer page emitted no FAQPage*
- `SeoStructuredDataTest::test_the_help_breadcrumb_points_at_the_category_page_not_a_search` — The visible crumb used to link `/help?q={category title}` — a search URL, which is now noindex. — */help/c/payments*
- `SeoStructuredDataTest::test_breadcrumb_positions_start_at_one_and_do_not_skip` — Breadcrumb positions start at one and do not skip.
