# `templates/pages/integrity.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /integrity → closure routes.php:2977`
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

**Headings:** 01Why this exists · 02The score · 03Who can actually win · 04What the judges score · 05What a contribution does, and what it cannot reach · 06The community return · 07Keeping the count honest · 08When a payment goes wrong · 09Sealing the result · 10The cycle, stage by stage · 11Disputes and your data

**Data read (top-level variables/functions):** `the`, `title`, `of`, `what`, `it`, `art`, `id`, `community`, `votes`, `nominee`, `judge_pct`, `that`, `philosophy`, `to`, `so`, `verified`, `half`, `url`, `version`, `counts`, `from`, `vote`, `community_pct`, `are`, `one`, `does`, `this`, `no`, `an`, `total`, `every`, `can`, `supporter`, `published`, `updated`, `doc_updated`, `doc_url`, `read`, `full`, `on`, `standing`, `cannot`, `do`, `because`, `you`, `panel`, `category`, `score`, `win`, `at`, `doc_title`, `author`, `doc_author`, `doc_publisher`, `doc_published`, `doc_version`, `download`, `toc`, `when`, `we`, `something`, `figure`, `live`, `engine`, `behind`, `means`, `mean`, `someone`, `min_judges`, `return_threshold`…

**States / branches (3 distinct conditions):** `not loop.last` · `return_off_reason|default('') == 'no_paid_voting'` · `return_off_reason|default('') == 'rate_zero'`

**Links out:** `/help/how-cpi-works` · `/help/why-a-small-category-is-not-a-disadvantage` · `/help/why-the-leader-may-not-be-eligible-to-win` · `/help/what-happens-if-two-nominees-tie` · `/help/what-the-judges-actually-score` · `#return` · `/help/what-paid-votes-do` · `/help/how-free-voting-works` · `/help/the-community-return` · `/help/how-we-spot-a-vote-that-is-not-real` · `/help/already-voted` · `/support/assistant` · `/help/paid-but-no-votes` · `/help/refund-when-votes-cannot-count` · `/help/votes-we-could-not-deliver` · `/help/how-results-are-sealed` · `/help/dispute-a-result` · `/help/the-stages-of-an-award-cycle` · `/help/when-does-voting-close` · `mailto:integrity@afrovanguard.org.ng` · `/support` · `/privacy` · `/terms` · `/help/report-a-profile` · `/help/what-data-do-you-keep` · `/philosophy` · `/help`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `agArticleDoc()`

**Legal / consent lines:**
- terms_url: _site_url ~ '/terms'
- {{ judge_pct|default(55) }}%, and it buys no privacy — every purchased vote is published
- approved by a different one, capped in size, and disclosed on the nominee’s page.
- Privacy Policy · Terms.

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `IntegrityPageTest` (whole file, via a helper/constant/data provider: 8 tests) — test_every_article_the_page_links_to_actually_exists, test_the_new_deep_dives_are_filed_where_a_reader_will_look, test_the_published_split_is_the_split_the_scorer_uses, test_the_risk_bands_are_drawn_from_the_configured_thresholds, test_the_community_return_share_is_published_from_basis_points, test_a_whole_number_share_reads_as_a_whole_number, test_the_deep_dives_quote_the_same_engine_as_the_page, test_with_no_override_both_surfaces_report_the_code_default
- `LegalDocumentTest::test_the_seeded_terms_send_the_reader_to_the_live_figures` — And it does point there, so the guard above is not satisfied by silence.
- `MoneyClaimSweepTest::test_the_separation_that_does_hold_is_still_stated_publicly` — THE TRUE CLAIM IS STILL BEING MADE.
- `PhilosophyDocumentTest::test_the_data_tables_are_marked_up_for_a_screen_reader` — The data that used to be bar charts is tabular now, which is only an improvement if it is marked up as a table: a caption to say what it is, and a scope on every header so a screen reader can announce the row it is reading.
- `PhilosophyDocumentTest::test_the_methodology_page_carries_the_precis_and_not_the_essay` — /integrity carries the PRÉCIS, not the essay.
- `PhilosophyDocumentTest::test_the_precis_tracks_the_engine` — The précis quotes the engine too.
- `PhilosophyDocumentTest::test_the_methodology_page_offers_cite_but_not_a_misleading_download` — The methodology page offers Cite but NOT Copy or Download.
- `PhilosophyDocumentTest::test_the_methodology_cites_itself_rather_than_the_philosophy` — Its citation is its OWN identity, not the philosophy's.
- `PublicResultsTest::test_the_page_states_both_vote_figures_and_names_the_difference` — BOTH VOTE NUMBERS, AND THE DIFFERENCE BETWEEN THEM, SAID HERE.
- `SiteSearchTest::test_a_natural_language_question_reaches_a_page` — The question a first-time visitor actually types.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `IntegrityPageTest::test_a_whole_number_share_reads_as_a_whole_number` — A whole-number share must not arrive as "30.0%".
- `IntegrityPageTest::test_every_article_the_page_links_to_actually_exists` — A summary that links out is only better than a wall of text if the links work. — *the page is supposed to be a summary with doors — it has almost no links*
- `IntegrityPageTest::test_the_community_return_share_is_published_from_basis_points` — The community return is the newest claim and the one with money attached. — *a rule is not a measurement*
- `IntegrityPageTest::test_the_published_split_is_the_split_the_scorer_uses` — THE REGRESSION THIS FILE IS REALLY FOR. — *the contribution ceiling is published from the engine*
- `IntegrityPageTest::test_the_risk_bands_are_drawn_from_the_configured_thresholds` — The risk bands are configuration too, and the bands must be contiguous.
- `IntegrityPageTest::test_with_no_override_both_surfaces_report_the_code_default` — Prove the guard bites: with no override at all, both surfaces report the code default.
- `MoneyClaimSweepTest::test_the_separation_that_does_hold_is_still_stated_publicly` — THE TRUE CLAIM IS STILL BEING MADE.
- `PhilosophyDocumentTest::test_the_data_tables_are_marked_up_for_a_screen_reader` — The data that used to be bar charts is tabular now, which is only an improvement if it is marked up as a table: a caption to say what it is, and a scope on every header so a screen reader can announce the row it is reading. — *the method part should present its data as tables*
- `PhilosophyDocumentTest::test_the_methodology_cites_itself_rather_than_the_philosophy` — Its citation is its OWN identity, not the philosophy's. — *the methodology page is citing the philosophy — wrong title and version*
- `PhilosophyDocumentTest::test_the_methodology_page_carries_the_precis_and_not_the_essay` — /integrity carries the PRÉCIS, not the essay. — *the précis must be there*
- `PhilosophyDocumentTest::test_the_methodology_page_offers_cite_but_not_a_misleading_download` — The methodology page offers Cite but NOT Copy or Download. — *Cite must be offered*
- `PhilosophyDocumentTest::test_the_precis_tracks_the_engine` — The précis quotes the engine too.
