# `templates/pages/legal.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /privacy (walk)`; `GET /terms (walk)`; `GET /cookies (walk)`; `GET /refunds (walk)`; `GET /vendor-terms (walk)`
**Extends:** layout/gates.twig · **Includes/imports:** partials/article.twig, partials/cookie-choice.twig

**What the page says it is (its own header comment, abridged):**
```
 ── COLOUR BUDGET: 0 for a document, 1 for the one that carries a control ──────
   A policy that is loud reads as one that is selling something, so terms, privacy
   and refunds spend nothing at all — links included, which are ink with an
   underline and not an accent.
   /cookies is the exception and it is a real one rather than a convenience: it is
   the only document here that ASKS the reader to decide something, and the budget
   is denominated in exactly that ("one event for each thing a page asks the reader
   to do"). The one event is the consent button in partials/cookie-choice.twig.
   The tier is gated on the same variable as the spend, so neither can appear
   without the other, and the sweep holds this template to the higher of the two —
   see ColourBudgetTest::tier(), which explains why it may not read a flat 1.
```

**Data read (top-level variables/functions):** `art`, `doc_effective`, `cur`, `groups`, `updated`, `url`, `doc_url`, `version`, `the`, `title`, `author`, `doc_author`, `published`, `txt_url`, `doc_txt_url`, `you`, `publisher`, `doc_publisher`, `doc_citations`, `md_url`, `doc_md_url`, `file_stem`, `doc_file_stem`, `documents`, `are`, `this`, `document`, `cookie_control`, `doc_outline`, `description`, `meta_description`, `tab_ids`, `eyebrow`, `standfirst`, `doc_standfirst`, `id`, `legal_body`, `citations`, `accessed`, `doc_accessed`, `note`, `cited`, `effective`, `rather`, `than`, `number`, `so`, `every`, `format`, `below`, `quotes`, `took`, `effect`, `cite`, `read`, `mdash`, `these`, `revised`, `copy`, `were`, `shown`, `one`, `that`, `applied`, `to`, `colour_tier`, `legal_doc`, `label`, `items`, `legal_tabs`

**States / branches (3 distinct conditions):** `doc_outline is not empty` · `t.slug != cur.slug` · `cookie_control`

**Links out:** `/{{ t.slug }}` · `/integrity` · `/philosophy` · `mailto:legal@afrovanguard.org.ng` · `https://afrovanguard.org.ng`

**JS behaviours:** Alpine x-data: `agArticleDoc()`

**Accessibility affordances:** aria-label×1

**Legal / consent lines:**
- {% set colour_tier = cookie_control ? '1' : '0' %}
- {% if cookie_control %}{% include 'partials/cookie-choice.twig' %}{% endif %}

**Styling carried:** 0 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CookieConsentRouteTest::test_the_cookies_page_carries_the_control_and_the_real_list` — (no docblock)
- `CookieConsentRouteTest::test_the_notice_appears_on_an_ordinary_page_in_ask_first_mode` — (no docblock)
- `AiPrivacyTest::test_the_notice_reports_whether_the_features_are_currently_on` — (no docblock)
- `CookiePrefsTest::test_the_notice_is_shown_only_when_there_is_something_to_ask` — (no docblock)
- `LegalCoverageTest::test_all_four_are_linked_from_the_footer` — (no docblock)
- `LegalCoverageTest::test_refunds_has_its_own_path_and_not_only_a_nested_one` — (no docblock)
- `LegalDocumentTest::test_the_page_and_the_download_come_from_one_source` — And the page shows the same thing, from the same builder.
- `RefundQueueTest::test_a_failed_refund_reaches_the_screen_with_both_ways_out` — The join between service and screen.
- `RefundQueueTest::test_a_pending_refund_is_shown_without_controls` — A pending refund is shown, and explicitly offers nothing to press.
- `RouteCompileTest::test_route_table_compiles_without_conflicts` — (no docblock)
- `SeoSitemapTest::test_the_public_surfaces_and_policies_are_all_listed` — Each of these returns 200, is indexable, and was in no section.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AiPrivacyTest::test_the_other_legal_documents_carry_no_ai_section` — and the document itself still renders
- `AiPrivacyTest::test_the_page_admits_what_is_not_known_about_provider_retention` — The page admits what is not known about provider retention.
- `AiPrivacyTest::test_the_page_says_names_are_sent_rather_than_implying_otherwise` — a notice that omits the one obvious identifier would be the misleading kind of true
- `AiPrivacyTest::test_the_privacy_page_actually_renders_the_disclosure` — every destination the registry names must appear on the page — *the placeholder is named so a reader knows what to expect*
- `LegalDocumentTest::test_the_page_and_the_download_come_from_one_source` — And the page shows the same thing, from the same builder.
- `CookieConsentRouteTest::test_the_cookies_page_carries_the_control_and_the_real_list` — the switch the page promises is not on the page
- `CookieConsentRouteTest::test_the_notice_appears_on_an_ordinary_page_in_ask_first_mode` — The notice appears on an ordinary page in ask first mode.
