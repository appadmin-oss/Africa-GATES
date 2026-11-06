# `templates/pages/results/show.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /results/{slug:[0-9]+[^/]*} → ResultsController::show`
**Extends:** layout/gates.twig · **Includes/imports:** partials/celebrate.twig

**What the page says it is (its own header comment, abridged):**
```
  ONE AWARD, WITH THE WORKING SHOWN.

  ── THE DESIGN POSITION ──────────────────────────────────────────────────────
  A court record, not a trophy case. Paper ground, hairline rules, mono micro-labels; one
  gold accent, reserved for the index itself; emerald reserved for the community half, which
  is the half a reader came to check.

  The standing is a LEDGER: every nominee who scored, in the order the award was decided,
  each index decomposing in place into the two halves and the denominator behind them. A
  page that prints one name is an announcement.

  And the claim this page exists to support is NOT "a ranking cannot be bought" — it can,
  and every public surface now says so plainly. It is that a ranking can be CHECKED: the
  tally, how much of it was contributed, the denominator it was measured against, the judge
…
```

**Headings:** {{ r.category.title }} · {{ w.name }} · The standing · One kind of vote, and it was contributed · Two kinds of vote, and both of them count · Share this result · Replies

**Data read (top-level variables/functions):** `row`, `cpct`, `jpct`, `held`, `here`, `weights`, `tally_only`, `rs`, `have`, `has`, `thread`, `replies`, `heat`, `out`, `is_member`

**States / branches (29 distinct conditions):** `r.edition` · `held` · `r.dead_heat` · `r.tie_broken_by_votes` · `r.margin is not null and r.margin <= 10` · `row.out_reason` · `not r.paid_only and row.vote_count != row.organic` · `r.community_basis in ['reach', 'ideal'] and r.cohort_max_unique > 0` · `row.reach_unmeasured` · `r.community_basis == 'ideal' and r.cohort_max > 0` · `row.judge_score is not null` · `row.provisional` · `row.cpi > 0` · `r.paid_only` · `r.cohort_max > 0` · `r.sealed_at` · `r.rank_recomputed` · `r.scale_set_by` · `r.scale_category and not r.scale_in_category` · `r.votes.bought > 0` · `not r.sealed_at` · `r.community_basis == 'ideal'` · `r.reach_unmeasured > 0` · `tally_only` · `r.shortlisted is not null` · `r.community_basis == 'reach'` · `thread` · `is_member|default(false)` · `not held`

**Links out:** `/results` · `/integrity` · `{{ r.url }}/card.png` · `/account/login?next={{ r.url|url_encode }}` · `' + window.agSocial.signInUrl() + '`

**JS behaviours:** 1 inline <script> block(s); data hooks: `data-page`, `data-celebrate`, `data-thread`

**Accessibility affordances:** aria-hidden×3; roles: presentation, status; visually-hidden text×1; <label for>×1; alt=×1; prefers-reduced-motion×1

**Legal / consent lines:**
- community half is measured from, so the tiebreak cannot disagree with the half it

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CelebrationTest` (whole file, via a helper/constant/data provider: 17 tests) — test_a_released_result_celebrates_its_winner, test_a_held_result_names_nobody_and_celebrates_nobody, test_the_delayed_holding_page_carries_no_celebration, test_the_nominee_page_celebrates_only_a_promoted_nominee, test_the_nominee_page_does_not_put_two_clocks_on_one_number, test_the_query_behind_the_dashboard_panel_actually_runs, test_a_nominee_nobody_has_been_told_about_is_not_congratulated, test_somebody_elses_vote_is_not_your_celebration, test_two_categories_backed_is_two_lines_and_one_key, test_the_script_reveals_nothing_and_therefore_cannot_withhold_it, test_it_makes_no_sound, test_reduced_motion_gets_the_result_and_no_particles …
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `EditionPageTest::test_the_arithmetic_is_collapsed_and_not_cut` — (no docblock)
- `MoneyClaimSweepTest::test_the_separation_that_does_hold_is_still_stated_publicly` — THE TRUE CLAIM IS STILL BEING MADE.
- `PaidOnlyBallotCopyTest::test_each_surface_says_instead_that_there_was_no_free_vote` — AND THE REPLACEMENT SENTENCE EXISTS.
- `PublicResultsTest` (whole file, via a helper/constant/data provider: 39 tests) — test_a_released_cycle_has_a_public_result, test_a_cycle_that_has_not_released_has_no_public_result, test_a_passed_results_date_does_not_publish_an_unannounced_result, test_a_late_cycle_is_stated_rather_than_left_silent, test_the_delay_carries_an_operators_note_when_there_is_one, test_a_late_award_resolves_from_its_own_category_link, test_a_results_date_still_in_the_future_does_not, test_the_sandbox_has_no_public_result_page, test_a_result_with_no_community_half_is_held_rather_than_published, test_a_category_that_crowns_nobody_is_held, test_held_results_are_counted_on_the_index, test_every_public_figure_is_the_release_screens_own …
- `ReleasedStandingTest` (whole file, via a helper/constant/data provider: 15 tests) — test_a_released_page_publishes_the_sealed_index_not_a_recomputed_one, test_a_rules_change_cannot_rename_the_winner_of_a_released_award, test_a_nominee_added_after_the_release_is_not_ranked_into_it, test_a_nominee_sealed_below_quorum_is_not_swept_in_when_judging_finishes, test_a_release_with_no_seal_is_not_dressed_up_as_an_announcement, test_the_page_states_whether_the_figures_were_announced_or_recomputed, test_a_cycle_is_sealed_once_however_often_the_sweep_runs, test_a_released_page_publishes_the_sealed_placings, test_a_seal_missing_a_placing_is_reconstructed_and_labelled, test_the_sealed_reach_denominator_survives_the_rows_being_purged, test_a_nominee_the_live_draw_would_rank_in_is_reported_to_the_operator, test_a_seal_the_rules_still_agree_with_reports_no_movement …
- `TallyOnlyCommunityHalfTest` (whole file, via a helper/constant/data provider: 14 tests) — test_an_unmeasured_edition_pays_the_tally_term_only, test_measured_reach_cannot_pay_a_full_half_to_a_narrow_nominee, test_the_drawn_cycle_reports_that_reach_was_unmeasurable, test_the_release_screen_states_the_half_was_paid_on_tally_alone, test_the_public_page_states_the_half_was_paid_on_tally_alone, test_neither_screen_says_it_when_the_rows_are_there, test_the_screen_names_the_layer_that_chose_the_basis, test_it_distinguishes_a_programme_override_from_a_cycle_one, test_the_narrowest_layer_decides_and_matches_what_was_scored, test_an_unoverridden_cycle_gets_no_notice, test_a_shortlist_no_longer_hides_a_bigger_tally_from_the_yardstick, test_a_full_community_half_now_requires_matching_the_biggest_tally …

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CelebrationTest::test_a_held_result_names_nobody_and_celebrates_nobody` — a held result named its winner — *confetti over a result the platform is deliberately withholding*
- `CelebrationTest::test_a_released_result_celebrates_its_winner` — A released result celebrates its winner.
- `PaidOnlyBallotCopyTest::test_no_surface_prints_the_organic_split_where_there_was_no_choice` — No surface prints the organic split where there was no choice.
- `PublicResultsTest::test_a_category_with_no_purchased_votes_says_so` — AND IT DOES NOT INVENT A DIFFERENCE WHERE THERE IS NONE.
- `PublicResultsTest::test_a_nominee_with_no_ballot_rows_is_not_shown_as_having_no_backers` — A NOMINEE WHOSE BALLOT ROWS ARE MISSING IS NOT PUBLISHED AS HAVING NO SUPPORTERS. — *the fixture no longer produces the state this test is about*
- `PublicResultsTest::test_a_result_with_no_community_half_is_held_rather_than_published` — THE FAULT THIS WHOLE PAGE WAS BUILT AFTER. — *a held result named its winner anyway*
- `PublicResultsTest::test_a_signed_in_member_gets_a_working_reply_box` — THE COMPOSER RENDERS FOR A MEMBER, AND ITS SCRIPT PARSES. — *no reply box for a member*
- `PublicResultsTest::test_the_page_states_both_vote_figures_and_names_the_difference` — BOTH VOTE NUMBERS, AND THE DIFFERENCE BETWEEN THEM, SAID HERE. — *a nominee with purchased votes shows only one of their two vote figures*
- `PublicResultsTest::test_the_public_page_shows_the_panel_mark_precisely` — THE PUBLIC PAGE SHOWS THE MARK AT THE PRECISION THAT DECIDES IT. — *a scorecard was dropped, so this is no longer a two-judge panel*
- `PublicResultsTest::test_the_reply_box_honours_a_quarantine_verdict` — A QUARANTINED REPLY IS NOT DRAWN INTO THE THREAD. — *the reply box shows every reply as live, including quarantined ones*
- `PublicResultsTest::test_the_reply_script_waits_for_the_deferred_helper_it_needs` — AND THE BUTTON IS BOUND TO SOMETHING.
- `PublicResultsTest::test_the_result_page_draws_the_whole_standing_and_the_working` — RENDERED, UNDER strict_variables. — *a nominee was left off the standing with no reason given*
- `ReleasedStandingTest::test_a_seal_missing_a_placing_is_reconstructed_and_labelled` — AND WHERE THE SEAL DID NOT RANK EVERYBODY, THE PAGE SAYS SO. — *a reconstructed order is being published as the announced one*
- `ReleasedStandingTest::test_the_page_states_whether_the_figures_were_announced_or_recomputed` — AND THE PAGE SAYS WHICH OF THE TWO IT IS SHOWING. — *a live computation is being presented as the announced result*
- `TallyOnlyCommunityHalfTest::test_a_basis_with_no_reach_term_gets_no_such_caveat` — THE LEGACY BASES ARE NOT COVERED, AND THAT IS CORRECT.
- `TallyOnlyCommunityHalfTest::test_neither_screen_says_it_when_the_rows_are_there` — AND NEITHER SCREEN SAYS IT WHERE IT IS UNTRUE. — *the fixture has no rows*
- `TallyOnlyCommunityHalfTest::test_the_public_page_states_the_half_was_paid_on_tally_alone` — the method note described a seventy per cent that was never computed
