# `templates/pages/events.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /events → EventsController::index`
**Extends:** layout/gates.twig · **Includes/imports:** partials/promo-carousel.twig

**What the page says it is (its own header comment, abridged):**
```
 The promo band (§4). `PromoService` decides what appears; zero promos render
     nothing at all, so this include is safe on every one of the five pages.
```

**Headings:** Where recognition happens · Upcoming · No upcoming events just yet · Past events

**Data read (top-level variables/functions):** `upcoming`, `sc`, `past`, `stand_calls`

**States / branches (11 distinct conditions):** `upcoming is not empty` · `not e.cover_image` · `e.cover_image` · `(e.venue or e.location) or sc` · `sc` · `sc.closes_at` · `e.venue or e.location` · `e.tagline or e.description` · `past is not empty` · `e.location` · `e.tagline`

**Links out:** `/events/{{ e.slug }}` · `/community`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** aria-hidden×4

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `dump_event_page` (whole file, via a helper/constant/data provider: 0 tests) — 
- `ArrivalsReportTest::test_each_stored_fact_reaches_the_report` — Behavioural: a column selected and then dropped on the floor still fails.
- `ArrivalsReportTest::test_hundreds_of_profile_pages_collapse_to_one_readable_row` — Every nominee has their own page, and a share-card campaign lands on hundreds.
- `ArrivalsReportTest::test_the_home_page_is_named_rather_than_left_as_a_slash` — '/' in a list of paths reads as a missing value.
- `CheckoutStartsEndToEndTest::test_the_event_page_renders` — (no docblock)
- `CheckoutStartsEndToEndTest::test_registering_for_a_paid_event_does_not_fatal` — (no docblock)
- `CheckoutStartsEndToEndTest::test_registering_for_a_free_event_does_not_fatal` — The FREE path, which is the one that sends an email inline — and therefore the one that touches the extracted mailer on a request a human is waiting for.
- `CheckoutStartsEndToEndTest::test_the_event_callback_does_not_fatal` — (no docblock)
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `CountdownEndToEndTest::test_the_analytics_screen_renders_the_arrivals_report` — The arrivals report renders every table it computes.
- `DoorWelcomeTest::test_the_greetings_can_be_made_without_waiting_for_cron` — §18 · and there is a step an operator can actually take.
- `EventFlierGeneratorTest::test_the_posts_go_to_the_extensionless_path` — (no docblock)
- `EventFlierTest::test_the_open_state_needs_only_a_name` — (no docblock)
- `EventFlierTest::test_a_confirmed_ticket_carries_a_flier_link_in_the_account_area` — (no docblock)
- `EventReferralPromptTest::test_a_signed_in_member_gets_their_own_link_for_this_event` — (no docblock)
- `EventTierSelectionTest` (whole file, via a helper/constant/data provider: 28 tests) — test_the_tier_list_is_a_named_radio_group, test_the_selection_is_announced_and_not_only_coloured, test_the_group_is_one_tab_stop_and_arrow_keys_move_inside_it, test_the_first_tier_is_reachable_before_anything_is_chosen, test_a_tier_row_clears_a_48px_target, test_focus_is_visible_and_survives_the_selected_state, test_the_effect_layer_is_hidden_from_assistive_tech_and_untouchable, test_reduced_motion_removes_the_whole_effect, test_the_selected_state_is_static_and_needs_no_animation, test_nothing_in_the_effect_is_positioned_outside_the_card, test_the_card_is_not_given_overflow_hidden, test_each_row_carries_its_own_tone_and_hue …
- `GatewayHandoffCallSitesTest` (whole file, via a helper/constant/data provider: 3 tests) — test_every_static_call_on_the_handoff_names_a_real_method, test_the_three_methods_a_handoff_needs_are_all_present, test_the_events_flow_bounces_rather_than_erroring_without_a_url
- `GeeSupportsTest::test_a_route_is_never_linked_as_the_prefix_of_a_longer_path` — No route may be linked as the PREFIX of a longer path.
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `IcsTest::test_only_an_absolute_web_url_is_emitted` — (no docblock)
- `InviteInboxCompatTest::test_there_are_exactly_two_actions` — Two actions in the letter, and no more.
- `MemberPurchasesTest::test_a_confirmed_ticket_carries_the_code_that_opens_the_door` — (no docblock)
- `MemberPurchasesTest::test_a_waitlisted_row_links_to_the_event_because_it_has_no_ticket` — (no docblock)
- `PageRenderSmokeTest::test_events_renders_upcoming_not_empty_state` — (no docblock)
- `RefundQueueTest::test_the_refund_actions_are_post_only` — Both controls are POST.
- `SeoSitemapTest::test_a_published_call_for_stands_is_listed_with_its_event` — An open call for stands is a public page with prices and a deadline on it.
- `SeoSitemapTest::test_a_draft_call_for_stands_is_not_listed` — A DRAFT call is not a public fact and is not advertised.
- `StandCallNoticeTest::test_the_message_says_nothing_is_first_come` — And the message says the thing the page's whole promise rests on.
- `StandNudgeTest::test_an_open_call_reports_the_three_numbers_a_vendor_decides_on` — (no docblock)
- `StandNudgeTest::test_the_event_page_offers_the_stand_and_says_applying_is_free` — (no docblock)
- `StandNudgeTest::test_a_closed_call_gets_the_date_rather_than_a_button` — (no docblock)
- `StandSurfacesTest::test_the_public_call_page_publishes_the_terms` — (no docblock)
- `TicketPrintTest::test_the_ticket_url_is_printed_as_text` — Paper cannot be clicked, so the link that recovers a lost ticket is printed as text.
- `TicketPrintTest::test_a_pending_booking_gets_no_pdf` — (no docblock)
- `TicketTierColourTest::test_the_tier_dot_is_rendered_from_the_events_accent` — (no docblock)
- `TicketTierColourTest::test_changing_the_events_accent_moves_the_tier_colour` — The reason the column holds a slot rather than a hex.
- `TicketTierColourTest::test_a_tier_without_a_slot_renders_no_dot` — A tier with no colour chosen renders the name and no dot — never a grey one.
- `VisitTrackerTest::test_token_shaped_segments_are_starred_and_real_slugs_are_not` — A list of known routes and a shape rule, because the failure mode is forgetting.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PageRenderSmokeTest::test_events_renders_upcoming_not_empty_state` — Events renders upcoming not empty state.
- `StandNudgeTest::test_a_card_is_chipped_only_while_its_call_is_actually_accepting` — the status column still says open; the clock is what decides
