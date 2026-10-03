# `templates/pages/vote-program.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/{program} → VoteController::program`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 The tab's live dot. An open ballot is the one page whose state is worth seeing from
   another tab — see favicon.js. Template scope, so the layout's <body> can read it.
```

**Headings:** {{ programme.title }} · {{ c.category.title }} · Nominees coming soon

**Data read (top-level variables/functions):** `programme`, `voting_open`, `cy`, `phase`, `categories`, `total_nominees`, `open`, `soon`, `vp`, `fill`, `lead`, `vote`, `tallies`, `favicon_state`

**States / branches (18 distinct conditions):** `programme.subtitle or programme.description` · `voting_open` · `cy.year` · `phase and phase.detail` · `categories is not empty` · `c.category.description` · `not c.headline` · `c.headline` · `c.headline.lead == 0` · `n.field > 1` · `n.photo_path` · `not n.photo_path` · `n.shortlisted|default(false)` · `n.organisation` · `n.tagline` · `voting_open and n.field > 1` · `n.is_leader` · `voting_open and categories is not empty`

**Links out:** `/vote` · `{{ n.url }}` · `/nominate`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-headline`, `data-cat`, `data-nominee`

**Accessibility affordances:** aria-hidden×6, aria-label×3; roles: img; prefers-reduced-motion×1

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `VoteProgrammeLayoutTest` (whole file, via a helper/constant/data provider: 4 tests) — test_the_headline_banner_honours_hidden, test_the_headline_banner_is_not_a_capsule, test_the_rank_is_centred_rather_than_nudged_by_a_magic_number, test_the_vote_link_wraps_for_every_card_or_for_none

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `ShortlistScreenTest::test_the_public_badge_appears_only_after_publication_and_then_holds` — The badge on the public programme page comes from the PUBLISHED snapshot. — *nothing may be badged before a shortlist is published*
- `VoteProgrammeLayoutTest::test_the_headline_banner_honours_hidden` — restore this and the ghost banner comes back
- `VoteProgrammeLayoutTest::test_the_headline_banner_is_not_a_capsule` — the trend icon belongs on the first line, not the middle of a two-line block
- `VoteProgrammeLayoutTest::test_the_rank_is_centred_rather_than_nudged_by_a_magic_number` — the card is already align-items:center, so this needs nothing tuned
- `VoteProgrammeLayoutTest::test_the_vote_link_wraps_for_every_card_or_for_none` — The vote link wraps for every card or for none.
