# `templates/pages/events/detail.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /events/{slug} → EventsController::show`
**Extends:** layout/gates.twig · **Includes/imports:** partials/member-autofill.twig

**What the page says it is (its own header comment, abridged):**
```
 ── IS THIS EVENT A FUNDRAISER? ───────────────────────────────────────────────
   Derived from the live appeals already joined to the event rather than from a new
   flag on the row. An event that is raising for something IS one with an open
   appeal attached, and a second boolean beside it is a second thing to keep in
   step — the first time somebody closes the appeal and forgets the flag, the page
   asks for money for a campaign that has ended.

   At TEMPLATE scope on purpose: a `{% set %}` inside a `{% block %}` is invisible
   to every other block and renders as null with no error, which has taken out this
   codebase's navigation once and a share link a second time.
```

**Headings:** {{ event.title }} · About this event · Agenda · Run of show · Venue · Good to know · Sell something at {{ event.title }}? · This event has ended · Bookings have closed · This event is full · Reserve your place · Make an “I will be there” flier · This event has finished

**Data read (top-level variables/functions):** `event`, `stand_call`, `referral`, `organiser_email`, `organiser_phone`, `tracks`, `early_bird`, `is_past`, `tiers`, `gone`, `pct_sold`, `tier_hues`, `is_fundraiser`, `agenda`, `event_at`, `attendee_note`, `refund_policy`, `reg_count`, `waitlist_open`, `appeal_for`, `waitlist_counts`, `spots_left`, `appeals`, `refund_rule`, `sales_closed`, `registration`, `shut`, `tone`, `gcal`, `schedule`, `calendar`, `ed`, `rsvp`, `event_tz`, `stand`, `the`, `terms`, `capacity`, `access_code`, `fast`, `up`, `tier_heats`, `tier_ms`, `flier_styles`, `flier_style_default`, `is_full`, `tier_tones`, `paid_tiers`, `gateway_ready`, `render`, `action`, `text`, `dates`, `location`, `details`

**States / branches (66 distinct conditions):** `early_bird` · `early_bird.deadline` · `event.cover_image` · `event.end_date` · `event.location or event.venue` · `event.description` · `agenda|default([])` · `s.track and s.track not in tracks` · `tracks|length > 1` · `agenda|length > 1 or d.key == ''` · `s.description` · `s.speakers` · `s.track or s.room` · `s.track` · `s.room` · `schedule` · `s.time` · `s.body` · `event.venue or event.location` · `event.venue and event.location` · `event.map_embed` · `refund_policy|default('') or attendee_note|default('') or organiser_email|default('') or o` · `attendee_note|default('')` · `refund_rule|default('')` · `refund_policy|default('')` · `organiser_email|default('') or organiser_phone|default('')` · `organiser_email|default('')` · `organiser_email|default('') and organiser_phone|default('')` · `organiser_phone|default('')` · `stand_call|default(null)` · `stand_call.state == 'closed'` · `stand_call.closes_at` · `stand_call.left > 0` · `stand_call.quota > 0` · `stand_call.from > 0` · `stand_call.kinds > 1` · `stand_call.state == 'open' and stand_call.closes_at` · `stand_call.state == 'soon' and stand_call.opens_at` · `stand_call.state == 'open'` · `is_past` · `reg_count` · `sales_closed|default('')` · `is_full` · `is_fundraiser` · `appeal_for` · `pct_sold is not null` · `tiers` · `gone` · `t.description|default('')` · `(t.state|default('open')) != 'open' and t.why|default('')` · `gone and waitlist_open|default(false)` · `waitlist_counts[t.id]|default(0)` · `t.left is not null and t.left <= 10` · `paid_tiers and not gateway_ready` · `spots_left is not null` · `not is_past and (event.status|default('published')) != 'cancelled'` · `referral and referral.pct` · `not is_past` · `a.campaign.summary` · `a.progress.target > 0` …

**Links out:** `{{ early_bird.url|default('#ed-rsvp') }}` · `/events` · `{{ event.map_embed }}` · `mailto:{{ organiser_email }}` · `tel:{{ organiser_phone|replace({' ': ''}) }}` · `{{ stand_call.url }}` · `/community` · `ticketUrl` · `/events/{{ event.slug|url_encode }}/calendar.ics` · `{{ gcal }}` · `{{ a.url }}` · `/account#me-referral` · `/account/login?next=/events/{{ event.slug|url_encode }}`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `{ track: '' }`, `evReg('{{ event.slug|e('js') }}', '{{ csrf_token|e('js') }}'`, `evShare()`, `evFlier('{{ event.slug|e('js') }}', '{{ csrf_token|e('js') }`, `evFlier(…, {…}, {…})`; data hooks: `data-page`, `data-rf-copy`; fetches: `/events/`

**Accessibility affordances:** aria-hidden×8, aria-checked×7, aria-label×6, aria-labelledby×3, aria-modal×1; roles: progressbar, radiogroup, radio, img, dialog, group, status; visually-hidden text×1; <label for>×10; alt=×3; tabindex×6; autocomplete×5; prefers-reduced-motion×5

**Legal / consent lines:**
- {{ stand_call.state == 'open' ? 'Apply for a stand' : 'See the terms' }}
- The terms are published now, so you can decide before it opens.
- the browser would reimplement the rounding rule and eventually disagree with the
- card and the printed ticket cannot come to disagree about a tier's colour. */
- and duplicating that here is how the two come to disagree. */
- /* Let popstate do the closing, so the address bar and the sheet cannot disagree. */

**Styling carried:** 1 <style> block(s), 37 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
- `ColourIsNeverAloneTest::test_nothing_on_this_platform_carries_colour_without_a_word` — (no docblock)
- `EventFlierGeneratorTest` (whole file, via a helper/constant/data provider: 40 tests) — test_every_state_from_the_handoff_is_present, test_generating_and_ready_are_separate_screens, test_progress_is_announced_and_not_only_animated, test_the_share_path_is_chosen_by_asking_the_device, test_there_is_no_silent_fallback_to_a_wa_me_link, test_the_two_step_path_says_why_there_are_two_steps, test_the_caption_goes_in_the_message_and_not_on_the_image, test_the_caption_is_prefilled_with_a_blank_first_line, test_the_reason_to_trust_the_upload_is_at_the_point_of_upload, test_the_reframe_shows_what_the_type_will_cover, test_the_reframe_comes_after_the_first_render_not_before_it, test_the_move_the_photo_control_is_hidden_when_there_is_no_photo …
- `EventFundraisingTest::test_the_event_page_renders_the_appeal_and_never_a_payment_form` — (no docblock)
- `EventFundraisingTest::test_a_fundraising_event_reads_as_one_on_its_registration_panel` — "Tickets" is right for a summit and wrong for a fundraising dinner.
- `EventFundraisingTest::test_the_fundraiser_reading_is_derived_from_the_appeal` — It is DERIVED from the live appeal, not stored a second time.
- `EventFundraisingTest::test_the_fundraiser_flag_is_hoisted_out_of_the_blocks` — And the set is at TEMPLATE scope, not inside a block.
- `EventFundraisingTest::test_the_fundraiser_wording_does_not_turn_a_ticket_into_a_gift` — The ticket is still not called a donation.
- `EventFundraisingTest::test_the_bar_carries_a_text_alternative` — (no docblock)
- `EventTierSelectionTest::test_a_tier_row_clears_a_48px_target` — (no docblock)
- `EventTierSelectionTest::test_focus_is_visible_and_survives_the_selected_state` — (no docblock)
- `EventTierSelectionTest::test_the_effect_layer_is_hidden_from_assistive_tech_and_untouchable` — (no docblock)
- `EventTierSelectionTest::test_reduced_motion_removes_the_whole_effect` — (no docblock)
- `EventTierSelectionTest::test_the_selected_state_is_static_and_needs_no_animation` — (no docblock)
- `EventTierSelectionTest::test_nothing_in_the_effect_is_positioned_outside_the_card` — (no docblock)
- `EventTierSelectionTest::test_the_card_is_not_given_overflow_hidden` — (no docblock)
- `EventTierSelectionTest::test_the_register_burst_fires_on_a_successful_response` — (no docblock)
- `EventTierSelectionTest::test_a_repeat_press_replays_on_both_mechanisms` — A repeat press must replay, and the two effects reach that guarantee differently.
- `EventTierSelectionTest::test_choosing_a_tier_does_not_fire_the_card_wide_light` — The card-wide light does not run on a comparison any more.
- `EventTierSelectionTest::test_the_unchosen_rows_are_not_dimmed` — And the rows being compared are not dimmed while somebody compares them.
- `EventTierSelectionTest::test_the_ripple_starts_at_the_pointer_and_covers_the_row` — The ripple is born where the pointer landed, which is the whole point of it.
- `EventTierSelectionTest::test_the_ripple_is_released_on_every_exit_from_a_press` — Every way a press can end has to end the ripple, or it stays on the row.
- `EventTierSelectionTest::test_a_keyboard_selection_still_gets_a_ripple` — A keyboard press has no pointer, and it still gets a response.
- `EventTierSelectionTest::test_the_row_has_a_state_layer_for_hover_focus_and_press` — The row responds to hover and focus, not only to a completed click.
- `EventTierSelectionTest::test_the_clip_is_on_the_ink_layer_and_not_on_the_row` — The ripple is clipped to the row, and the row's outline is not clipped with it.
- `EventTierSelectionTest::test_no_state_layer_takes_the_rows_small_text_below_aa` — The wash must never take the row's small text below AA — for ANY accent.
- `EventTierSelectionTest::test_no_persistent_layer_escapes_the_ripple_gate` — The layers are alternatives, not a pile.
- `EventTierSelectionTest::test_the_pressed_wash_lives_only_where_there_is_no_ripple` — And the pressed wash exists exactly where the ripple does not.
- `EventTierSelectionTest::test_reduced_motion_keeps_the_wash_and_drops_the_ripple` — Reduced motion refuses the ripple at the source rather than collapsing it.
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `CheckoutStartsEndToEndTest::test_the_event_page_renders` — GET /events/{slug}
- `EventFlierGeneratorTest::test_a_bookmarked_fragment_still_opens_it` — A bookmarked fragment still opens it.
- `EventFlierGeneratorTest::test_a_failure_preserves_the_draft` — A failure preserves the draft.
- `EventFlierGeneratorTest::test_a_new_photo_discards_the_old_framing` — A new photo discards the old framing.
- `EventFlierGeneratorTest::test_both_doors_open_it_and_neither_reaches_into_the_component` — the rail card and the post-registration nudge
- `EventFlierGeneratorTest::test_cancel_actually_cancels_the_request` — Cancel actually cancels the request.
- `EventFlierGeneratorTest::test_changing_the_shape_rechecks_the_style` — Changing the shape rechecks the style.
- `EventFlierGeneratorTest::test_every_field_has_a_real_label` — Every field has a real label.
- `EventFlierGeneratorTest::test_every_state_from_the_handoff_is_present` — no photo picker — *no caption field*
- `EventFlierGeneratorTest::test_focus_is_moved_in_trapped_and_given_back` — Focus is moved in trapped and given back.
- `EventFlierGeneratorTest::test_generating_and_ready_are_separate_screens` — the generating screen could not be located — *the generating screen has more than one button*
- `EventFlierGeneratorTest::test_nothing_still_requires_a_name_unconditionally` — All three places that decide "is a name required" must say the same thing.
- `EventFlierGeneratorTest::test_only_statuses_this_application_never_returns_count_as_filtered` — a rejected CSRF token must not be retried as if it were a filter
- `EventFlierGeneratorTest::test_progress_is_announced_and_not_only_animated` — Progress is announced and not only animated.
- `EventFlierGeneratorTest::test_reduced_motion_stops_the_progress_animation` — Reduced motion stops the progress animation.
- `EventFlierGeneratorTest::test_the_back_gesture_closes_the_sheet_instead_of_leaving_the_page` — The back gesture closes the sheet instead of leaving the page.
- `EventFlierGeneratorTest::test_the_caption_goes_in_the_message_and_not_on_the_image` — The caption goes in the message and not on the image.
- `EventFlierGeneratorTest::test_the_caption_is_prefilled_with_a_blank_first_line` — The caption is prefilled with a blank first line.
- `EventFlierGeneratorTest::test_the_confirmed_path_gets_its_token_from_the_server` — ag:flier-token
- `EventFlierGeneratorTest::test_the_dialog_carries_the_semantics_that_make_it_one` — The dialog carries the semantics that make it one.
- `EventFlierGeneratorTest::test_the_face_claim_is_only_made_when_a_face_was_found` — The face claim is only made when a face was found.
- `EventFlierGeneratorTest::test_the_generator_is_not_inside_the_rail` — the generator must be a dialog — *the generator must live outside the rail, not merely be positioned out of it*
- `EventFlierGeneratorTest::test_the_make_button_is_live_on_the_token_path` — A name is required only when there is no token to take it from. — *the button gates on a name the token path does not need — it stays dead for a ticket-holder*
- `EventFlierGeneratorTest::test_the_move_the_photo_control_is_hidden_when_there_is_no_photo` — The move the photo control is hidden when there is no photo.
- `EventFlierGeneratorTest::test_the_no_photo_design_is_offered_first` — the no-photo shape must be the first option — *and the default*
- `EventFlierGeneratorTest::test_the_open_entry_states_the_incentive_without_nagging` — The open entry states the incentive without nagging.
- `EventFlierGeneratorTest::test_the_page_behind_does_not_scroll_and_gets_its_scrolling_back` — The page behind does not scroll and gets its scrolling back.
- `EventFlierGeneratorTest::test_the_photo_has_a_second_transport_and_a_last_resort` — The photo has a second transport and a last resort.
- `EventFlierGeneratorTest::test_the_posts_go_to_the_extensionless_path` — The posts go to the extensionless path.
- `EventFlierGeneratorTest::test_the_reason_to_trust_the_upload_is_at_the_point_of_upload` — The reason to trust the upload is at the point of upload.
- `EventFlierGeneratorTest::test_the_reframe_comes_after_the_first_render_not_before_it` — start() must not route to the reframe screen — the render comes first now — *the ready screen is the only way into the reframe*
- `EventFlierGeneratorTest::test_the_reframe_opens_on_the_frame_the_render_used` — The reframe opens on the frame the render used.
- `EventFlierGeneratorTest::test_the_reframe_shows_what_the_type_will_cover` — The reframe shows what the type will cover.
- `EventFlierGeneratorTest::test_the_share_path_is_chosen_by_asking_the_device` — canShare must be asked with a real File
- `EventFlierGeneratorTest::test_the_start_guard_agrees_with_the_button` — And the guard behind it agrees, or the button enables onto a refusal. — *start() still demands a name on the token path*
- `EventFlierGeneratorTest::test_the_style_data_is_not_passed_through_an_attribute` — JSON must not be passed through an HTML attribute
- `EventFlierGeneratorTest::test_the_style_picker_is_a_radiogroup_with_real_swatches` — the style chips must carry no colours of their own
- `EventFlierGeneratorTest::test_the_style_rides_on_the_request_and_the_answer_is_adopted` — The style rides on the request and the answer is adopted.
- `EventFlierGeneratorTest::test_the_targets_and_focus_are_in_the_stylesheet` — The targets and focus are in the stylesheet.
- `EventFlierGeneratorTest::test_the_two_step_path_says_why_there_are_two_steps` — The two step path says why there are two steps.
- `EventFundraisingTest::test_a_fundraising_event_reads_as_one_on_its_registration_panel` — "Tickets" is right for a summit and wrong for a fundraising dinner. — *the panel has to name the kind of event before it lists prices*
- `EventFundraisingTest::test_the_bar_carries_a_text_alternative` — The bar carries a text alternative.
- `EventFundraisingTest::test_the_event_page_renders_the_appeal_and_never_a_payment_form` — The event page renders the appeal and never a payment form.
- `EventFundraisingTest::test_the_fundraiser_flag_is_hoisted_out_of_the_blocks` — And the set is at TEMPLATE scope, not inside a block. — *hoist anything used by more than one block to template scope*
- `EventFundraisingTest::test_the_fundraiser_reading_is_derived_from_the_appeal` — It is DERIVED from the live appeal, not stored a second time. — *is_fundraiser*
- `EventFundraisingTest::test_the_fundraiser_wording_does_not_turn_a_ticket_into_a_gift` — The ticket is still not called a donation.
- `EventReferralPromptTest::test_a_visitor_who_is_not_signed_in_sees_the_offer_and_a_way_to_get_a_link` — The offer is shown to everybody; the LINK needs an owner, so that is the next step. — *there is no link to copy until somebody owns one*
- `EventReferralPromptTest::test_the_sign_in_link_returns_to_this_event` — Sign-in must come back to the event, not dump them on an account page.
- `EventReferralPromptTest::test_a_signed_in_member_gets_their_own_link_for_this_event` — the link must carry their code — *and land on THIS event, not the events index*
- `EventReferralPromptTest::test_the_link_is_selectable_even_with_no_javascript` — The field is a real input before it is a copy button, so a browser that refuses the clipboard API still leaves the link there to select. — *the link has to be in the markup, not written in by a script*
- `EventReferralPromptTest::test_the_rate_is_read_live_rather_than_written_into_the_copy` — THE HONESTY ONE. Change the rate in admin and the page changes with it. — *a page promising a rate the ledger will not pay is worse than no page*
- `EventReferralPromptTest::test_the_threshold_is_read_live_and_explains_the_backdating` — The threshold is read live and explains the backdating.
- `EventReferralPromptTest::test_nothing_is_offered_when_referrals_are_switched_off` — Nothing is offered when referrals are switched off.
- `EventReferralPromptTest::test_nothing_is_offered_on_an_event_with_referrals_disabled` — Per-event disabling exists so an event can opt out; the page has to honour it.
- `EventReferralPromptTest::test_nothing_is_offered_on_a_past_event` — Nobody can buy a ticket to a past event, so there is nothing to earn from.
- `EventReferralPromptTest::test_the_prompt_sits_after_the_share_block_and_not_above_the_tickets` — A supporter who came to buy a ticket and left having read about commission instead is a worse outcome than one who never saw this. — *the referral offer must not lead the sidebar*
- `EventTierSelectionTest::test_a_keyboard_selection_still_gets_a_ripple` — A keyboard press has no pointer, and it still gets a response. — *the fallback origin is the row centre*
- `EventTierSelectionTest::test_a_repeat_press_replays_on_both_mechanisms` — A repeat press must replay, and the two effects reach that guarantee differently. — *a ripple that is never removed stacks one node per press for the life of the page*
- `EventTierSelectionTest::test_a_sold_out_tier_is_held_rather_than_celebrated` — a sold-out top tier must not sweep white — never celebrate joining a queue
- `EventTierSelectionTest::test_a_tier_row_clears_a_48px_target` — A tier row clears a 48px target.
- `EventTierSelectionTest::test_choosing_a_tier_does_not_fire_the_card_wide_light` — The card-wide light does not run on a comparison any more. — *pick() could not be located*
- `EventTierSelectionTest::test_each_row_carries_its_own_tone_and_hue` — Each row carries its own tone and hue.
- `EventTierSelectionTest::test_focus_is_visible_and_survives_the_selected_state` — Focus is visible and survives the selected state.
- `EventTierSelectionTest::test_no_persistent_layer_escapes_the_ripple_gate` — The layers are alternatives, not a pile. — *the state layer opacities are not in the stylesheet*
- `EventTierSelectionTest::test_no_state_layer_takes_the_rows_small_text_below_aa` — The wash must never take the row's small text below AA — for ANY accent. — *the state layer opacities are not in the stylesheet*
- `EventTierSelectionTest::test_nothing_animates_before_the_first_press` — Nothing animates before the first press.
- `EventTierSelectionTest::test_nothing_in_the_effect_is_positioned_outside_the_card` — the effect layers are not in the stylesheet
- `EventTierSelectionTest::test_reduced_motion_keeps_the_wash_and_drops_the_ripple` — Reduced motion refuses the ripple at the source rather than collapsing it.
- `EventTierSelectionTest::test_reduced_motion_removes_the_whole_effect` — Reduced motion removes the whole effect.
- `EventTierSelectionTest::test_the_card_is_not_given_overflow_hidden` — The card is not given overflow hidden.
- `EventTierSelectionTest::test_the_clip_is_on_the_ink_layer_and_not_on_the_row` — The ripple is clipped to the row, and the row's outline is not clipped with it.
- `EventTierSelectionTest::test_the_effect_layer_is_hidden_from_assistive_tech_and_untouchable` — The effect layer is hidden from assistive tech and untouchable.
- `EventTierSelectionTest::test_the_first_tier_is_reachable_before_anything_is_chosen` — The first tier is reachable before anything is chosen.
- `EventTierSelectionTest::test_the_group_is_one_tab_stop_and_arrow_keys_move_inside_it` — The group is one tab stop and arrow keys move inside it.
- `EventTierSelectionTest::test_the_premium_row_at_the_top_of_the_list_still_gets_the_peak` — the Patron row should render — *exactly one row may be the peak*
- `EventTierSelectionTest::test_the_pressed_wash_lives_only_where_there_is_no_ripple` — And the pressed wash exists exactly where the ripple does not. — *the ripple already draws the press — a wash under it doubles the ink*
- `EventTierSelectionTest::test_the_register_burst_fires_on_a_successful_response` — the success branch could not be located — *the success branch has no else*
- `EventTierSelectionTest::test_the_ripple_is_released_on_every_exit_from_a_press` — Every way a press can end has to end the ripple, or it stays on the row.
- `EventTierSelectionTest::test_the_ripple_starts_at_the_pointer_and_covers_the_row` — The ripple is born where the pointer landed, which is the whole point of it.
- `EventTierSelectionTest::test_the_row_has_a_state_layer_for_hover_focus_and_press` — The row responds to hover and focus, not only to a completed click.
- `EventTierSelectionTest::test_the_selected_state_is_static_and_needs_no_animation` — The selected state is static and needs no animation.
- `EventTierSelectionTest::test_the_selection_is_announced_and_not_only_coloured` — The selection is announced and not only coloured.
- `EventTierSelectionTest::test_the_tier_list_is_a_named_radio_group` — one radio per tier
- `StandNudgeTest::test_a_closed_call_gets_the_date_rather_than_a_button` — A closed call gets the date rather than a button.
- `StandNudgeTest::test_a_draft_call_is_not_a_public_fact` — A draft call is not a public fact.
- `StandNudgeTest::test_the_event_page_offers_the_stand_and_says_applying_is_free` — the call page has to be reachable from the event it belongs to — *how many places are left is the first question*
- `StandNudgeTest::test_the_nudge_does_not_appear_on_an_event_that_has_no_call` — not even a stray link — this event has nothing to apply for
- `EventFlierGeneratorTest::test_there_is_no_silent_fallback_to_a_wa_me_link` — There is no silent fallback to a wa me link.
- `EventTierSelectionTest::test_the_unchosen_rows_are_not_dimmed` — And the rows being compared are not dimmed while somebody compares them.
