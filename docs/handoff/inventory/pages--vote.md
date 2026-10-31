# `templates/pages/vote.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote → VoteController::index`
**Extends:** layout/gates.twig · **Includes/imports:** partials/promo-carousel.twig, partials/vote-countdown.twig

**What the page says it is (its own header comment, abridged):**
```
 The tab's live dot. An open ballot is the one page whose state is worth seeing from
   another tab — see favicon.js. Template scope, so the layout's <body> can read it.
```

**Headings:** Cast your vote across{{ meta.voting_count }} live programme{{ meta.voting_count  · Voting opensagain soon · No programmes yet · Live programmes · {{ p.icon_emoji|default('') }} {{ p.title }} · Your ballot · Voting closes {{ meta.soonest.closes_at|when_zoned('j M Y, H:i') }} · Community votes count for {{ split.community|default(45) }}%

**Data read (top-level variables/functions):** `st`, `meta`, `hub`, `leader`, `seen`, `tag`, `first`, `split`, `voting`, `flag`, `cols`, `cfe6ce`, `soon`, `deadline`, `favicon_state`, `voting_open`

**States / branches (15 distinct conditions):** `meta.voting_count > 0` · `meta.soonest` · `hub|length == 0` · `st not in seen` · `p.total_votes > 0` · `leader and p.total_votes > 0` · `st == 'nominations'` · `st == 'voting'` · `st == 'shortlisting'` · `st == 'results' or st == 'judging'` · `st == 'voting' and leader` · `st == 'results'` · `st == 'judging' or st == 'shortlisting'` · `p.phase and p.phase.detail` · `firstVoting`

**Links out:** `/leaderboard` · `/nominate` · `/philosophy` · `/integrity` · `/vote/{{ p.slug }}` · `/vote/{{ firstVoting.slug }}`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `voteHub()`; data hooks: `data-prog-ids`, `data-status`

**Accessibility affordances:** aria-hidden×3, aria-pressed×2, aria-label×1, aria-valuenow×1, aria-valuemin×1, aria-valuemax×1; roles: group, progressbar; visually-hidden text×2

**Styling carried:** 1 <style> block(s), 7 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `CelebrationTest` (whole file, via a helper/constant/data provider: 17 tests) — test_a_released_result_celebrates_its_winner, test_a_held_result_names_nobody_and_celebrates_nobody, test_the_delayed_holding_page_carries_no_celebration, test_the_nominee_page_celebrates_only_a_promoted_nominee, test_the_nominee_page_does_not_put_two_clocks_on_one_number, test_the_query_behind_the_dashboard_panel_actually_runs, test_a_nominee_nobody_has_been_told_about_is_not_congratulated, test_somebody_elses_vote_is_not_your_celebration, test_two_categories_backed_is_two_lines_and_one_key, test_the_script_reveals_nothing_and_therefore_cannot_withhold_it, test_it_makes_no_sound, test_reduced_motion_gets_the_result_and_no_particles …
- `CheckoutMailerTest::test_an_abandoned_paid_vote_checkout_gets_one_recovery_email` — (no docblock)
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `DemoSeederTest::test_the_sandbox_is_invisible_to_every_public_reader` — The public-invisibility mechanism, checked through the readers that actually serve the site rather than by asserting a column.
- `DisputeFlowTest::test_the_receipt_states_what_was_delivered` — The receipt states what was DELIVERED, read from the vote rows rather than the order's own counter.
- `GatewayHandoffTest::test_a_handoff_is_single_use` — (no docblock)
- `GatewayHandoffTest::test_the_reference_must_match` — (no docblock)
- `GatewayHandoffTest::test_an_expired_handoff_is_refused` — (no docblock)
- `GatewayHandoffTest::test_the_provider_label_is_never_taken_from_the_request` — (no docblock)
- `GatewayHandoffTest::test_every_checkout_path_has_a_same_origin_handoff_route` — (no docblock)
- `GeeSupportsTest::test_a_route_is_never_linked_as_the_prefix_of_a_longer_path` — No route may be linked as the PREFIX of a longer path.
- `GuideServiceTest::test_unconfigured_answer_falls_to_scripted_and_never_dies` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `LoginNextRedirectTest::test_an_ordinary_local_path_still_goes_through` — (no docblock)
- `NewsletterTest::test_an_issue_states_what_the_award_pages_state` — (no docblock)
- `NewsletterTest::test_auto_mode_sends_to_confirmed_subscribers_only_with_a_way_out` — (no docblock)
- `NomineeStoryTest::test_every_named_supporter_is_reachable` — "and 40 more" used to lead nowhere.
- `NomineeStoryTest::test_the_supporters_page_never_prints_what_anyone_gave` — And the rule that governs the whole feature holds on the new page too: what each person contributed decides the order and is never printed.
- `NomineeStoryTest::test_a_voter_who_did_not_consent_is_never_listed` — A voter who did not consent is on neither list, on either page.
- `OgCardTest::test_the_card_route_is_declared_before_the_nominee_catch_all` — (no docblock)
- `PaidVoteReceiptTest` (whole file, via a helper/constant/data provider: 8 tests) — test_a_minted_order_is_celebrated, test_a_confirmed_but_unminted_order_is_never_reported_as_counted, test_the_unminted_state_says_a_refund_is_owed_and_shows_the_reference, test_the_unminted_state_does_not_celebrate, test_a_minted_order_does_celebrate, test_an_unknown_reference_still_renders_the_pending_state, test_a_minted_order_marks_the_per_device_ballot_tracker, test_an_unminted_order_does_not_mark_the_tracker
- `PhaseSurfaceRenderTest::test_the_hub_never_shows_an_open_badge_beside_an_expired_deadline` — (no docblock)
- `PhaseSurfaceRenderTest::test_the_hub_states_one_consistent_deadline_when_voting_is_open` — (no docblock)
- `PhaseSurfaceRenderTest::test_the_hub_labels_the_shortlisting_phase` — (no docblock)
- `PhaseSurfaceRenderTest::test_the_ballot_explains_a_refused_paid_checkout` — (no docblock)
- `PhaseSurfaceRenderTest::test_the_ballot_shows_a_phase_specific_closed_state` — (no docblock)
- `PhaseSurfaceRenderTest::test_the_open_ballot_names_its_close_date` — (no docblock)
- `PhaseSurfaceRenderTest::test_the_ballot_records_the_vote_for_the_hub_tracker` — (no docblock)
- `PhilosophyDocumentTest::test_the_vote_hub_quotes_the_live_split_and_links_the_philosophy` — A voter about to pay is shown the split, and it must be the live one.
- `PublicSurfaceSandboxTest::test_the_sitemap_omits_the_sandbox` — The sitemap does not hand a crawler a URL the site will refuse.
- `PublicSurfaceSandboxTest::test_a_demo_nominee_has_no_ballot_page` — The page a share link actually opens.
- `PublicSurfaceSandboxTest::test_the_legacy_id_link_does_not_resolve_a_sandbox_nominee` — And the legacy /vote/{id} link, which needs no slug at all — so it hands out the canonical URL of whatever row that id names.
- `PublicSurfaceSandboxTest::test_the_messages_and_supporters_pages_are_not_open_on_a_sandbox_nominee` — The two pages that hang off the ballot.
- `RefundDecisionTest::test_a_delivered_order_owes_nothing` — Already delivered — nothing owed, and it points at the checkable page.
- `RouteCompileTest::test_route_table_compiles_without_conflicts` — (no docblock)
- `SchemaTest::test_person_uses_what_it_does_have` — (no docblock)
- `SeoCanonicalTest::test_a_referral_link_canonicalises_to_the_clean_page` — THE BUG WITH TEETH: the referral feature hands out `?ref=AGXXXX` links and people share them — that is the point.
- `SeoCanonicalTest::test_a_bare_path_is_unchanged` — (no docblock)
- `SeoSitemapTest::test_a_nominee_ballot_is_listed_at_its_canonical_url` — (no docblock)
- `SeoSitemapTest::test_a_merged_nominee_is_not_listed` — A merged nominee's ballot 302s to its survivor.
- `ShortlistScreenTest::test_the_public_badge_appears_only_after_publication_and_then_holds` — The badge on the public programme page comes from the PUBLISHED snapshot.
- `SiteUrlTest::test_a_callback_url_built_from_it_is_always_absolute` — (no docblock)
- `SupportResilienceTest::test_delivery_health_points_at_the_proof_page_when_clean` — And when it IS clean it says so, and hands over the checkable link.
- `SupporterHonoursTest::test_a_promoted_nominee_still_has_a_public_page` — WINNING MUST NOT DELETE THE PAGE.
- `SupportersConsentTest` (whole file, via a helper/constant/data provider: 10 tests) — test_an_order_that_never_answered_the_question_stays_private, test_a_named_order_publishes_the_name_and_the_weight, test_consent_does_not_leak_between_orders, test_the_blank_name_placeholder_is_not_published, test_the_free_path_shares_the_flag_and_the_default, test_the_list_is_scoped_to_the_nominee, test_the_count_is_not_the_length_of_the_truncated_list, test_an_invalid_nominee_id_is_empty_not_fatal, test_typing_a_name_at_checkout_is_the_consent, test_leaving_the_name_blank_is_an_anonymous_order
- `VoteCountdownTest::test_the_vote_hub_still_includes_the_countdown` — And it is still on the hub.
- `VoteMessageTest::test_the_permalink_page_puts_the_message_in_its_own_social_card` — (no docblock)
- `VoteMessageTest::test_a_message_permalink_is_noindex_but_the_full_wall_is_not` — A message permalink is share bait, not search bait: one short quote surrounded by boilerplate, one per message.
- `VoteMessageTest::test_a_nominee_has_a_page_of_all_their_messages` — The full wall is a PAGE, not a "load more" button — so it has a URL a nominee can send to their family, it is visible to crawlers and to readers without JavaScript, and the item markup has exactly one renderer.
- `VoteMessageTest::test_the_messages_page_shows_only_what_was_approved` — Held and rejected messages are not on it, not counted, and not hinted at.
- `VoteRecoveryReachableTest` (whole file, via a helper/constant/data provider: 10 tests) — test_a_batch_can_be_taken_from_nothing_to_the_tally_without_a_shell, test_the_preparer_cannot_approve_through_the_screen, test_the_screen_explains_why_the_preparer_has_no_approve_button, test_applying_needs_the_word_typed, test_an_editor_is_refused_every_route, test_a_refusal_reaches_the_operator_in_the_services_own_words, test_applied_votes_are_named_on_the_nominees_own_public_page, test_a_reversed_batch_stops_being_disclosed, test_nothing_is_disclosed_where_nothing_was_recovered, test_every_step_has_a_route_and_a_way_to_find_it

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `HeadingHierarchyTest::test_public_pages_have_one_h1_and_no_level_jumps` — $key should have exactly one <h1>, found {$h1}
- `PhaseSurfaceRenderTest::test_the_hub_never_shows_an_open_badge_beside_an_expired_deadline` — no ballot is open — *and the deadline stat says so*
- `PhaseSurfaceRenderTest::test_the_hub_states_one_consistent_deadline_when_voting_is_open` — the rail must name the real close date
- `PhaseSurfaceRenderTest::test_the_hub_labels_the_shortlisting_phase` — and offer the one useful action
- `PhilosophyDocumentTest::test_the_vote_hub_quotes_the_live_split_and_links_the_philosophy` — A voter about to pay is shown the split, and it must be the live one. — *the ballot must quote the live community share*
