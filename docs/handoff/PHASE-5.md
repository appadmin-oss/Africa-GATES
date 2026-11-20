# Phase 5 — awards and voting

Built on the shell from AwardsPage, VoteHub, VotePage, NomineePage and VoteBallot `.dc.html`
(5 Oct 2026, rebased onto 6342834 on 8 Oct). No scoring, money, OTP, BallotGuard, fraud, receipt
or CPI rule was changed; every rule in CLAUDE.md is untouched. Not committed (coordinator's rule).

**Ownership, as ruled by the owner on 8 Oct:** where the owner's other session built a file, its
version wins. That session rebuilt `components/results.css` + `results.js` (`1aa11db`), the seven
receipts including `vote-paid-success.twig` and `vote-verify.twig` (`d1b39d1`, `receipt.css`),
the voting copy (`a29d73b`) and the default graphics. My parallel results split
(`results-index.css`, `results-honour.css`), my receipt templates, the receipt part of
`vote-pages.css` and the paid-success tracker/message JS in `vote.js` were removed, and the
results index and hall restored to `1aa11db`'s links. Both receipt routes answer 200 on the seeded
server in all three paid states (minted / paid-not-minted / pending) and for an unknown reference.

## Routes (all 200 on the seeded dev server, port 8105)

`/awards` (+ `?ph=`), `/awards/{p}` (Overview · Award details · Terms, `?edition=`), coming-soon with
`POST /awards/{p}/notify` (double opt-in) and `/awards/alerts/{token}/{confirm|stop}`; `/vote`
(+ `?f=`), `/vote/{p}` (+ `?tab=about`, `?cat=`), `/vote/{p}/tallies`, `/vote/{p}/{id-name}` (ballot,
and the won state with the `win` celebration via `Celebration::refusal()`), `/supporters`,
`/messages`, `/flier` (+ `.png`, `.svg`, `card.png`), `/m/{token}`; `/results`, `/results/{edition}`,
`/results/{id-category}` (released, late holding page), `/winners`; `/vote/paid/success`,
`/vote/verify` (other session's templates). Unreleased categories 404 as before.

## Files (mine)

- Migrations: `2027_03_05_edition_number.php`, `2027_03_05_award_terms.php`
  (`gates_award_terms` + `gates_award_terms_acceptance`), `2027_03_05_award_alerts.php`; both
  schemas. VARCHAR not ENUM; `SchemaIndex::ensure()`; backfills only NULL rows.
- Services: `AwardsFront`, `VoteFront`, `AwardTerms` (a new version only when the text changes;
  acceptance never throws), `AwardAlert` (+ Maintenance task `award-alerts`, claim-first),
  `PaidVoteCopy` (Q10 sentence), `Support\EditionName` (one resolver for "11th Edition · 2026").
- Controllers: `AwardsController` (rewritten), `VoteController` (hub, edition, nominee extras),
  `ApiController` (terms required before a code is sent and before a vote is cast; acceptance
  recorded; `vote_cast` session line the page reads once), `PaidVoteController` (terms on start),
  `ResultsController` (switcher, siblings), admin `ProgrammesController` (terms changelog, edition
  number), `CycleEdition` (next edition number).
- `PublicResults::standings()`: **a late cycle is "With the panel", never "Decided"** (was printing
  Decided under the delay notice). Test: `PublicResultsTest::test_the_index_draws_a_late_cycle_as_with_the_panel`
  — watched failing with the clause commented out.
- Templates: `pages/awards/{index,programme,alert}`, `pages/vote`, `vote-program`, `vote-nominee`,
  `vote-supporters`, `vote-messages`, `vote-message`, `vote-flier`, `pages/results/{_head,edition,open,
  show,late,index,hall}` (the other session has since edited edition/show/index/hall — theirs wins),
  `partials/vote-message.twig` (no Alpine), `partials/seo-head.twig`.
- CSS/JS: `components/awards.css`, `vote.css`, `ballot.css`, `vote-pages.css`; `awards.js`
  (server-seconds ticker), `vote.js` (device ballot under `ag-vote:`, OTP flow, tallies poll,
  Turnstile explicit render, cheer/report, flier native share). `tokens.css` +`--ag-fs-42/46/52`,
  `--ag-r-22` (additive).
- Shared, additive: `routes.php` (2 routes), `container.php` (AwardsController wiring),
  `CookieRegistry` (`ag-vote:`, Preferences, local-or-session).
- Tests changed: `ColourBudgetTest` (vote-nominee's `colour_alt = 'gold'` re-added as the one
  exclusive state), `ThirdPartyScriptIntegrityTest` (Turnstile named again, loaded only with a site
  key), `PublicResultsTest` (+1). Each failed before the change.

## Prop → screenshot map (`shots/phase-5/`)

| Area | DC | Build |
|---|---|---|
| Awards index | `awards/dc-390`, `dc-1440` | `awards/index-{390,834,1024,1440}`, `-390-rtl`, `-390-rm` |
| One award / terms / coming soon | (same DC, states) | `awards/detail-*`, `terms-1440`, `soon-*` |
| Vote hub | `vote/dc-hub-*` | `vote/hub-{390,834,1024,1440}`, `hub-390-rtl` |
| Edition vote page | `vote/dc-edition-*` | `vote/edition-*` |
| Nominee + ballot | `nominee/dc-nominee-*`, `dc-ballot-390` | `nominee/ballot-{390,834,1024,1440}`, `-rtl`, `-rm` |
| Won state | — | `nominee/won-*` |
| Supporters, messages, one message, flier | — (no DC) | `nominee/supporters-390`, `messages-390`, `message-390`, `flier-1440` |
| Hall of fame, late notice | — (no DC, GAPS §5.1) | `winners/hall-*`, `index-late-390` |

Keyboard: every control is a link, button or GET form; the ballot's OTP step, the review dialog
and the edition picker are reachable and operable by keyboard; focus rings are 3px green.
Reduced motion: the countdown keeps ticking (text), celebrations obey the partial's own rule.

## Deviations (what · why · approved by)

- DC mono counts/clock digits → DM Sans tabular figures · §18.3 · owner Q5.
- DC 10.5px labels → 11.5 · closed ladder · owner Q4. 40/42px targets → 44 · §6.7 house floor.
- Phase pills on awards are one neutral pill with the phase's coloured DOT beside its word, terms
  and coming-soon badges neutral · colour budget (tier 2 = live + green) · house rule.
- Notify is email only (DC also offers SMS/WhatsApp) · no SMS/WhatsApp opt-in path exists · open.
- "Download PDF" of terms and the prizes block omitted · no data/renderer · open.
- Ballot evidence shown as counts only, never voter names · privacy · open (below).
- Admin `/admin/awards-page` copy is no longer read by the public page · one resolver (AwardsFront).
- Bars that carry colour carry their figure as text too (`ColourIsNeverAloneTest`).
- JS error strings in `vote.js` are English · nothing in the shell passes a catalogue to JS yet.

## Blocked questions (unanswered, today's rules kept)

- **GAPS Q9 — ballot required fields:** kept today's (free: name, phone, email; paid: email, name
  optional = consent to be named).
- **GAPS Q10 — paid-vote copy:** the real sentence (adds to the tally, 30% of the half; not to the
  people count, 70%), from `PaidVoteCopy`; the other session's `a29d73b` copy agrees.
- May the ballot name the verified backers behind "evidence", or only count them?
- `/claim/{id}` and `/n/confirm` (nominee claim pages) are assigned to no phase; `/claim/1` answers 500
  (template destroyed). Not built here.

## Test run

Narrow filters (≈400 tests across the guards above, the vote/results/terms suites): green except
failures in files I did not touch — `leaderboard.twig`, `legacy/*` (ColourBudget, ColourIsNeverAlone),
`status.twig` (TwigBlockScope), `/account/*` (PublicIa), `blog/post`, `nominate-award`, `profile`,
`pulse-post`, `partials/ui` (ColourIsNeverAlone).
