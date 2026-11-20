# `templates/pages/vote-nominee.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/{program}/{slug} → VoteController::nominee`
**Extends:** layout/gates.twig · **Includes/imports:** partials/vote-message.twig, partials/vote-message-assets.twig, partials/vote-countdown.twig, partials/support-prompt.twig, partials/member-autofill.twig, partials/share.twig, partials/celebrate.twig

**What the page says it is (its own header comment, abridged):**
```
 The tab's live dot. An open ballot is the one page whose state is worth seeing from
   another tab — see favicon.js. Template scope, so the layout's <body> can read it.
```

**Headings:** {{ n.name }} · Why {{ firstName }} is nominated · {{ award_kind == 'winner' ? n.name ~ ' won.' : n.name ~ ' placed.' }} · Roll of honour · Support for {{ firstName }} · Named supporters · Others in {{ n.category }} · Support {{ firstName }} · Your vote is in ✓

**Data read (top-level variables/functions):** `standing`, `first`, `profile`, `nominee`, `ag`, `recovered`, `message_count`, `msg_max`, `rank`, `_case`, `_bio`, `messages`, `max_qty`, `ink`, `award_kind`, `voting_open`, `paid_retry`, `member`, `pay_providers`, `turnstile_site_key`, `supporters`, `portrait`, `backer_count`, `oav`, `phase`, `points_per_vote`, `wash`, `paid_free_disabled`, `cat_count`, `flag`, `ctry`, `flier_url`, `supporters_url`, `supporter_count`, `supporters_more`, `paid_notice`, `member_points`, `vote_price`, `vote_tiers`, `donation_votes_per_1000`, `slug`, `action`, `honour`, `live`, `_story`, `_brief`, `roll_of_honour`, `others`, `person`, `people`, `standing_headline`, `standing_cta`, `up`, `won`, `placed`, `messages_url`, `vn`, `sect`, `split`, `open`, `closed`, `the`, `leaderboard`, `we`, `ll`, `announce`, `when`, `it`, `opens`, `vote`…

**Forms:**
- `POST /vote/paid/start` fields: _token[hidden], nominee_id[hidden], qty[number required], email[email required,autocomplete], name[text autocomplete], message[textarea maxlength], provider[radio], provider[hidden]; buttons: {{ t.qty }} vote{{ t.qty == 1 ? '' : 's' }} | Contribute

**States / branches (45 distinct conditions):** `rank` · `cat_count` · `profile and profile.cpi_tier and profile.cpi_tier != 'unranked'` · `n.programme_title` · `n.organisation|default('')` · `profile and profile.cpi_score` · `paid_free_disabled` · `n.organic_vote_count is defined and n.organic_vote_count is not null and n.vote_count > n.` · `recovered.total > 0` · `standing.field >= 2` · `standing.gap_ahead is not null and standing.gap_ahead > 0 and not standing.is_leader` · `standing.is_leader and standing.gap_behind` · `standing.momentum_available and standing.momentum_24h > 0` · `standing.top_notable` · `portrait` · `(profile and profile.bio) or n.story|default('') or n.tagline` · `_case or _bio` · `_case and _case != _bio` · `_bio` · `profile and profile.slug` · `award_kind|default('')` · `backer_count|default(0) > 0` · `roll_of_honour|default([])|length` · `messages|default([])|length or supporters|default([])|length` · `messages|default([])|length` · `message_count > messages|length` · `supporters|default([])|length` · `supporter_count > supporters|length` · `supporters_more|default('')` · `others|length` · `o.photo_path` · `not o.photo_path` · `voting_open` · `paid_notice` · `not voting_open` · `can_redeem` · `member_logged_in and points_enabled` · `paid_voting and pay_providers is not empty` · `max_qty > 1000` · `pay_providers|length > 1` · `loop.first` · `not paid_free_disabled` · `donation_votes_per_1000 > 0` · `message_count > 0` · `turnstile_site_key`

**Links out:** `/vote` · `/vote/{{ n.programme_slug }}` · `/integrity` · `#vn-ballot` · `{{ flier_url }}` · `/registry/{{ profile.slug }}` · `/leaderboard` · `{{ messages_url }}` · `{{ supporters_url }}` · `/vote/{{ nominee.programme_slug }}/{{ o.id }}-{{ o.name|lower|replace({' ':'-'})` · `/vote/{{ nominee.programme_slug }}` · `/shop` · `/integrity#money` · `/philosophy` · `/privacy#supporters` · `/terms/{{ nominee.programme_slug }}` · `/giving` · `/terms` · `messageUrl` · `'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(messageUrl)` · `'https://wa.me/?text=' + encodeURIComponent(messageUrl)` · `/claim/{{ n.id }}`

**JS behaviours:** script `https://challenges.cloudflare.com/turnstile/v0/api.js`; 1 inline <script> block(s); Alpine x-data: `voteNominee()`, `{ open:false, long:false }`, `{ busy:false, done:false, msg:'', bal:{{ member_points }} }`, `{
                qty: {{ paid_retry.qty|default(0) > 0 ? pa`, `{ pvName: {{ (paid_retry.name|default('') ?: (member ? membe`, `{ open: false }`; data hooks: `data-page`, `data-ag-reveal`, `data-celebrate`, `data-ag-cascade`; fetches: `/account/redeem`, `/api/otp/request`, `/api/vote`

**Accessibility affordances:** aria-hidden×21, aria-label×4, aria-labelledby×3, aria-expanded×2, aria-pressed×1, aria-controls×1; roles: note, group, img, alert; visually-hidden text×1; <label for>×9; alt=×1; autocomplete×6; prefers-reduced-motion×2

**Legal / consent lines:**
- /* ── The recovery disclosure ──────────────────────────────────────────────
- The platform admitting it put votes on a public tally itself, and a disclosure
- styled to be skimmed past is not a disclosure. So it is the one block here
- Shown on {{ firstName }}’s public supporters list and on your receipt. Leave it blank to give anonymously — your vote counts exactly the same. Only the name is 
- One verified vote per category. We email a 6-digit code to confirm it’s you — your email is hashed for the one-vote check. By voting you agree to the programme 
- I’m a real person voting once, and I accept the voting rules.
- Vote for {{ firstName }} &rarr;
- step: 'email', email: '', fullName: '', phone: '', otp: '', agreed: false, busy: false,
- // The optional message of support and its own consent flag. Carried in this
- if (!this.agreed) { this.otpError = 'Please confirm you’re a real person voting once.'; return; }

**Styling carried:** 1 <style> block(s), 51 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CelebrationTest` (whole file, via a helper/constant/data provider: 17 tests) — test_a_released_result_celebrates_its_winner, test_a_held_result_names_nobody_and_celebrates_nobody, test_the_delayed_holding_page_carries_no_celebration, test_the_nominee_page_celebrates_only_a_promoted_nominee, test_the_nominee_page_does_not_put_two_clocks_on_one_number, test_the_query_behind_the_dashboard_panel_actually_runs, test_a_nominee_nobody_has_been_told_about_is_not_congratulated, test_somebody_elses_vote_is_not_your_celebration, test_two_categories_backed_is_two_lines_and_one_key, test_the_script_reveals_nothing_and_therefore_cannot_withhold_it, test_it_makes_no_sound, test_reduced_motion_gets_the_result_and_no_particles …
- `ColourBudgetTest::test_an_exclusive_state_is_declared_and_the_declarations_are_counted` — (no docblock)
- `ColourBudgetTest::test_a_page_that_asks_for_money_declares_where` — And the marker is required, or the rule above is opt-in: a template handed `pay_providers` is about to ask somebody for money.
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee.
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `PaidOnlyBallotCopyTest::test_each_surface_says_instead_that_there_was_no_free_vote` — AND THE REPLACEMENT SENTENCE EXISTS.
- `PublicResultsTest::test_the_ballot_page_says_which_part_of_its_tally_counts` — AND THE BALLOT PAGE SAYS IT TOO, WHERE THE LARGER NUMBER IS PRINTED.
- `SupportSurfaceRenderTest::test_the_nominee_brief_appears_once_and_is_never_truncated` — (no docblock)
- `SupportSurfaceRenderTest::test_a_registry_bio_no_longer_swallows_the_nomination_brief` — (no docblock)
- `VoteCountdownTest::test_the_nominee_ballot_includes_the_countdown` — The ballot page is the point of the feature.
- `VoteCountdownTest::test_the_ballot_page_renders_with_a_ticking_deadline` — The whole ballot page renders with a live deadline in it.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CelebrationTest::test_the_nominee_page_celebrates_only_a_promoted_nominee` — a promoted nominee gets no moment on their own page — *confetti on the page of somebody who has not been promoted*
- `CelebrationTest::test_the_nominee_page_does_not_put_two_clocks_on_one_number` — The nominee page does not put two clocks on one number.
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee. — *{$file}: the {$action} form does not start the busy ring*
- `NomineeStoryTest::test_a_nominee_with_no_story_falls_back_to_its_tagline` — A nominee approved before the column existed has a tagline and no story.
- `NomineeStoryTest::test_a_nominee_with_nothing_written_gets_no_card` — No story, no tagline, no bio: the card is absent rather than empty.
- `NomineeStoryTest::test_the_ballot_prints_the_whole_story` — THE WHOLE POINT. The full story reaches the page — all of it, in the HTML, not behind a request — so a reader without JavaScript and a crawler both get it. — *the ballot is still showing a truncation of the story*
- `PaidOnlyBallotCopyTest::test_each_surface_says_instead_that_there_was_no_free_vote` — AND THE REPLACEMENT SENTENCE EXISTS. — *the public result page branches on paid_only and then says nothing*
- `PhaseSurfaceRenderTest::test_the_ballot_explains_a_refused_paid_checkout` — the outcome of a just-taken action
- `PhaseSurfaceRenderTest::test_the_ballot_shows_a_phase_specific_closed_state` — and offer a way back — *the ballot form must be absent*
- `PhaseSurfaceRenderTest::test_the_open_ballot_names_its_close_date` — machine-readable instant — *and the words a reader needs*
- `PhaseSurfaceRenderTest::test_the_ballot_records_the_vote_for_the_hub_tracker` — the key the hub reads must be written — *and called on a successful vote*
- `PhilosophyDocumentTest::test_the_nominee_ballot_links_the_philosophy_beside_the_contribution` — THE SURFACE THAT MATTERS MOST. — *the ballot must let a supporter read why voting carries a contribution*
- `PublicResultsTest::test_the_ballot_page_says_which_part_of_its_tally_counts` — AND THE BALLOT PAGE SAYS IT TOO, WHERE THE LARGER NUMBER IS PRINTED. — *the ballot page prints a tally and never says how much of it counts*
- `SupportSurfaceRenderTest::test_the_nominee_brief_appears_once_and_is_never_truncated` — {{ _case|nl2br }} — *the hero copy is gone, and so is the CSS that positioned it*
- `SupporterHonoursTest::test_a_promoted_nominee_still_has_a_public_page` — WINNING MUST NOT DELETE THE PAGE. — *an approved nominee has a page*
- `VoteCountdownTest::test_the_ballot_page_renders_with_a_ticking_deadline` — The whole ballot page renders with a live deadline in it.
- `VoteCountdownTest::test_the_bare_variant_removes_chrome_and_sets_no_colour` — AND IT SETS NO COLOURS, WHICH IS THE WHOLE REASON IT WAS RENAMED. — *the variant that assumed a dark ground is still in the sheet*
- `VoteCountdownTest::test_the_nominee_ballot_includes_the_countdown` — The ballot page is the point of the feature.
- `VoteCountdownTest::test_the_vote_hub_still_includes_the_countdown` — And it is still on the hub.
- `VoteRecoveryReachableTest::test_a_reversed_batch_stops_being_disclosed` — And a reversal takes them off the disclosure with them. — *votes that are no longer on the tally are still being claimed as support*
- `VoteRecoveryReachableTest::test_applied_votes_are_named_on_the_nominees_own_public_page` — THE CONTROL THE DOCTRINE CALLS THE STRONGEST ONE. — *three votes were put on a public tally and the page said nothing*
- `VoteRecoveryReachableTest::test_nothing_is_disclosed_where_nothing_was_recovered` — And nothing is claimed on a platform that has never recovered anything — which is every page on almost every deployment.
- `ColourBudgetTest::test_an_exclusive_state_is_declared_and_the_declarations_are_counted (expected list emptied)` **(guard kept, edited)** — The ballot declares `{% set colour_alt = 'gold' %}`: its gold laurel is drawn only once voting has closed, a state excluding its other colour events; the only page allowed an exclusive state.
- `ColourBudgetTest::test_a_page_that_asks_for_money_declares_where (second half removed)` **(guard kept, edited)** — The ballot marks its paid region with `data-ag-paid`, and that region contains the pack controls (`vn-qty`); nothing inside it wears a colour field (money is never the loudest thing on a page).
