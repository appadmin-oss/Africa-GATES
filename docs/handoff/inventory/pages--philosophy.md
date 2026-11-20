# `templates/pages/philosophy.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /philosophy → closure routes.php:2939`
**Extends:** layout/gates.twig · **Includes/imports:** partials/article.twig

**What the page says it is (its own header comment, abridged):**
```
 ── COLOUR BUDGET: tier 0 — this document asks for nothing ────────────────────
   A policy, a methodology or a statement of principle is read, not acted on, so
   it spends no colour event at all: links included, which are ink with an
   underline. A legal page that is loud reads as one that is selling something.

   Declared so the rule can BITE. Every screen in this family is built on
   `components/article.css`, which carried `action` green on its eyebrow chip, its
   download button, its contents rail and its inline code — and was invisible to
   every sweep here, because the sweep read templates and this page holds no
   literal and no role token. It is ink now; the declaration is what stops it
   quietly going back. See ColourBudgetTest.
```

**Headings:** {{ '%02d'|format(loop.index) }}{{ s.title|raw }}

**Data read (top-level variables/functions):** `the`, `of`, `art`, `philosophy`, `it`, `nominee`, `url`, `download`, `scheme`, `title`, `published`, `updated`, `doc_updated`, `doc_url`, `version`, `programme`, `an`, `than`, `community`, `vote`, `money`, `people`, `doc_title`, `author`, `doc_author`, `doc_publisher`, `doc_published`, `doc_version`, `txt_url`, `txt`, `toc`, `community_pct`, `judge_pct`, `on`, `you`, `so`, `contribution`, `rather`, `from`, `supporters`, `publisher`, `subtitle`, `doc_subtitle`, `doc_standfirst`, `doc_citations`, `md_url`, `md`, `file_stem`, `doc_file_stem`, `ceiling`, `votes`, `result`, `to`, `may`, `what`, `faq`, `supporter`, `judging`, `work`, `toward`, `award`, `have`, `was`, `bought`, `no`, `cannot`, `count`, `more`, `financial`, `pyramid`…

**States / branches (1 distinct conditions):** `not loop.last`

**Links out:** `mailto:integrity@afrovanguard.org.ng` · `/help`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `agArticleDoc()`

**Legal / consent lines:**
- terms_url: _site_url ~ '/terms'

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `LegalDocumentTest::test_the_seeded_terms_send_the_reader_to_the_live_figures` — And it does point there, so the guard above is not satisfied by silence.
- `PhilosophyDocumentTest::test_the_article_publishes_the_engines_split_not_the_default` — (no docblock)
- `PhilosophyDocumentTest::test_article_markdown_and_text_agree_on_the_share` — THE ANTI-DRIFT PROMISE, STATED AS A TEST.
- `PhilosophyDocumentTest::test_the_article_offers_all_four_citation_formats_and_the_metadata` — (no docblock)
- `PhilosophyDocumentTest::test_every_section_is_rendered_and_linked_from_the_contents` — Every section must be reachable from the contents, or the document has a dead limb.
- `PhilosophyDocumentTest::test_the_anti_pyramid_disclaimer_survives_every_rendering` — The disclaimer is the one part of this document with legal weight, and the one a reader is most likely to have been sent here specifically to find.
- `PhilosophyDocumentTest::test_the_citation_tabs_are_reachable_by_keyboard` — The citation tabs use a roving tabindex — selected 0, the rest -1 — which is correct for a tablist and means Tab enters the group once rather than stopping four times.
- `PhilosophyDocumentTest::test_the_methodology_page_carries_the_precis_and_not_the_essay` — /integrity carries the PRÉCIS, not the essay.
- `PhilosophyDocumentTest::test_the_vote_hub_quotes_the_live_split_and_links_the_philosophy` — A voter about to pay is shown the split, and it must be the live one.
- `PhilosophyDocumentTest::test_the_nominee_ballot_links_the_philosophy_beside_the_contribution` — THE SURFACE THAT MATTERS MOST.
- `PhilosophyDocumentTest::test_download_is_a_plain_anchor_and_needs_no_javascript` — THE REGRESSION THIS SECTION IS FOR.
- `PhilosophyDocumentTest::test_the_canonical_download_paths_have_no_extension` — Extension-shaped URLs are at the mercy of the web server before PHP sees them: MultiViews, mod_negotiation, static-file handlers, and this project's own root .htaccess, which denies whole extension classes by FilesMatch.
- `PhilosophyDocumentTest::test_copy_fetches_the_canonical_path` — The Copy button fetches the canonical extensionless path, not a dotted one.
- `SupportSurfaceRenderTest::test_the_compact_line_offers_the_philosophy_and_publishes_no_address` — The compact line points at the philosophy, NOT at a mailbox.
- `SupportSurfaceRenderTest::test_the_compact_lines_second_clause_is_overridable` — And a caller can point that clause somewhere else when the context differs.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PhilosophyDocumentTest::test_article_markdown_and_text_agree_on_the_share` — THE ANTI-DRIFT PROMISE, STATED AS A TEST. — *the {$what} edition lost the community share*
- `PhilosophyDocumentTest::test_copy_fetches_the_canonical_path` — The Copy button fetches the canonical extensionless path, not a dotted one. — *copyAll() is still pointed at an extension-shaped URL*
- `PhilosophyDocumentTest::test_download_is_a_plain_anchor_and_needs_no_javascript` — THE REGRESSION THIS SECTION IS FOR. — *the .md download is not a plain anchor*
- `PhilosophyDocumentTest::test_every_section_is_rendered_and_linked_from_the_contents` — Every section must be reachable from the contents, or the document has a dead limb.
- `PhilosophyDocumentTest::test_the_anti_pyramid_disclaimer_survives_every_rendering` — The disclaimer is the one part of this document with legal weight, and the one a reader is most likely to have been sent here specifically to find. — *{$what} lost the disclaimer*
- `PhilosophyDocumentTest::test_the_article_offers_all_four_citation_formats_and_the_metadata` — the cite panel is missing {$label}
- `PhilosophyDocumentTest::test_the_article_publishes_the_engines_split_not_the_default` — the standfirst states the live public-vote share — *the philosophy is still quoting the default after it was overridden*
- `PhilosophyDocumentTest::test_the_citation_tabs_are_reachable_by_keyboard` — The citation tabs use a roving tabindex — selected 0, the rest -1 — which is correct for a tablist and means Tab enters the group once rather than stopping four times. — *the tablist has a roving tabindex but no way to move within it*
