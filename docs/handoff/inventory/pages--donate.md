# `templates/pages/donate.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /giving → DonationController::page`; `GET /giving/{slug:(?!apply$|manage$|redirect$|callback$|success$|giving$|gift$|donate$|stop$)[a-z0-9][a-z0-9-]{1,118}} → DonationController::page`; `GET /giving/{slug:(?!apply$|manage$|redirect$|callback$|success$|giving$|gift$|donate$|stop$)[a-z0-9][a-z0-9-]{1,118}}/{campaign:[a-z0-9][a-z0-9-]{1,118}} → DonationController::page`
**Extends:** layout/gates.twig · **Includes/imports:** partials/org-page.twig, partials/share.twig

**What the page says it is (its own header comment, abridged):**
```
  ── DONATIONS ────────────────────────────────────────────────────────────────

  One template, three recipients: the Africa GATES fund, a partner organisation's own
  page, and a specific appeal inside one. Which of the three it is decides the heading,
  the disclosure and whether the "raise donations yourself" block appears at all.

  ── WHAT THE REBUILD CHANGED, AND WHY ───────────────────────────────────────

  THE POSITIONING. The form used to live inside a dark full-width band beside the ask,
  which put the two loudest things on the page in competition and left the case for
  giving squeezed into a 33rem column beside a card three times its height. Now:

    · The masthead is an editorial masthead — kicker, headline, deck, hairline, and the
      real figures set as a ledger row. It runs the full measure of the text column,
…
```

**Headings:** {{ campaign.title }} · Donate to {{ org.name }} · Fund the next generation of leaders · {% if org_closed is defined and org_closed %}This appeal is closed{% else %}Make · {% if campaign %}Donate to this appeal{% else %}Make a donation{% endif %} · Your details · Where donations go · {{ a.title }} · Recent donations · {% if campaign %}Share this appeal{% elseif org %}Share {{ org.name }}{% else %} · Raise donations on a page of your own

**Data read (top-level variables/functions):** `org`, `progress`, `campaign`, `fund_goal`, `org_totals`, `days_left`, `org_fee_bps`, `pct`, `stats`, `error`, `appeals`, `min_naira`, `org_closed`, `providers`, `platform_tip`, `shortfall_text`, `processing_fee_pct`, `recurring`, `allocation`, `givers`, `cause`

**Forms:**
- `POST /giving` fields: _token[hidden], amount[hidden], org[hidden], campaign[hidden], monthly[hidden], name[text autocomplete], email[email required,autocomplete], provider[radio], cover_fees[checkbox], platform_tip[radio]; buttons: Once | Every month | ={{ min_naira }} ? ('Continue with '+ngn(value)) : | ← Change the amount | 0 ? Math.floor(value*tipPct/100) : 0)))">

**States / branches (31 distinct conditions):** `campaign` · `campaign.summary` · `org` · `fund_goal` · `fund_goal.met` · `stats.gifts > 0` · `not fund_goal` · `providers is empty` · `org_closed is defined and org_closed` · `error` · `not (org_closed is defined and org_closed)` · `progress and progress.target > 0` · `days_left is not null` · `shortfall_text` · `org_fee_bps > 0` · `appeals` · `a.summary` · `org.scuml_number` · `recurring` · `loop.first` · `org and org_fee_bps > 0` · `platform_tip.offered` · `pct == platform_tip.default` · `pct == 0` · `allocation` · `a.body` · `not org` · `givers|length >= 3` · `g.ago` · `providers is not empty` · `org_totals.orgs > 0`

**Links out:** `/support` · `/giving/{{ org.slug }}/{{ a.slug }}` · `/integrity` · `#give` · `/account/register?as=organisation`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `donate()`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×8, aria-label×4, aria-labelledby×3, aria-pressed×3; roles: img, alert, group; <label for>×2; tabindex×4; autocomplete×2; prefers-reduced-motion×1

**Legal / consent lines:**
- gift agreed to by accident is a dispute, and a checkbox under a row of amounts is
- the browser chose — so this exists only so the recap and the button can agree with
- if (!/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(src)) return;

**Styling carried:** 1 <style> block(s), 5 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `DonationReceiptTest::test_a_callback_confirmed_one_off_gift_is_receipted_without_a_stop_link` — A one-off gift confirmed by the callback alone is receipted, without a stop link.
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee.
- `FundAllocationTest::test_the_template_gates_both_blocks` — The public template gates it, and gates the mission quote with it.
- `GivingFormFlowTest` (whole file, via a helper/constant/data provider: 8 tests) — test_the_recap_states_the_frequency_that_was_chosen, test_the_recap_carries_the_gift_to_the_platform_and_the_total, test_advancing_and_returning_both_move_focus, test_the_focus_move_waits_for_the_step_to_render, test_the_focus_target_only_shows_a_ring_to_the_keyboard, test_the_interactive_controls_declare_a_reachable_height, test_a_standing_order_is_never_the_default, test_the_form_states_that_a_gift_buys_no_votes
- `GivingUrlTest::test_every_path_is_built_from_one_base` — (no docblock)
- `GivingUrlTest::test_a_slug_or_token_cannot_escape_its_own_segment` — A SLUG IS SOMEBODY'S TYPING AND REACHES A URL.
- `GivingUrlTest::test_the_canonical_paths_are_the_ones_registered` — (no docblock)
- `GivingUrlTest::test_the_fixed_words_are_the_ones_that_are_reserved` — (no docblock)
- `OrgApplyTest::test_the_chooser_opens_the_application_in_place` — The chooser sends a non-profit to the branch, not to a page of its own.
- `OrgPageTest::test_the_donation_page_includes_the_renderer` — AND THE DONATION PAGE ACTUALLY INCLUDES THE RENDERER.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `DonationGoalTest::test_with_no_target_set_the_page_is_unchanged` — With no target set the page is exactly what it was — no bar, and the raised tile. — *a bar was drawn against a target nobody chose*
- `DonationGoalTest::test_a_target_draws_a_bar_and_the_distance_left` — 250,000 of 1,000,000 is a quarter, and the bar said otherwise — *the distance left is the half of this that moves somebody*
- `DonationGoalTest::test_the_fund_counts_only_gifts_to_the_fund` — THE FUND'S RAISED FIGURE IS GIFTS TO THE FUND, AND NOTHING ELSE IN THE TABLE. — *the recent-gifts ledger listed a payment that was not a gift to the fund*
- `DonationGoalTest::test_the_raised_figure_is_stated_once` — THE MASTHEAD MUST NOT SAY THE SAME NUMBER TWICE. — *the masthead prints the raised figure twice*
- `DonationGoalTest::test_passing_the_target_is_reported_and_the_bar_does_not_overrun` — Passing the target is good news and the page says so — but the BAR is capped. — *the bar ran past its own track*
- `DonationGoalTest::test_a_target_with_nothing_raised_does_not_break` — A target with nothing raised is still an ask, and must not divide by anything odd.
- `DonationGoalTest::test_the_ledger_says_when_each_gift_arrived` — WHEN each gift arrived, which was collected and rendered nowhere.
- `DonationGoalTest::test_the_ledger_is_ordered_by_when_the_gift_arrived` — ORDERED BY TIME, NOT BY INSERTION. — *the newest gift is not at the top*
- `DonationGoalTest::test_the_page_asks_to_be_shared` — The one thing a fundraising page cannot do for itself. — *the share row is not the shared partial, so it will drift*
- `FundAllocationTest::test_the_template_gates_both_blocks` — The public template gates it, and gates the mission quote with it.
- `GivingFormFlowTest::test_the_recap_states_the_frequency_that_was_chosen` — A DONOR CONFIRMING A MONTHLY GIFT IS NOT TOLD IT IS A ONE-OFF. — *the unconditional label is still rendered somewhere*
- `GivingFormFlowTest::test_the_recap_carries_the_gift_to_the_platform_and_the_total` — The tip is in the recap, not only in the button.
- `GivingFormFlowTest::test_advancing_and_returning_both_move_focus` — CHANGING STEP MOVES FOCUS. — *an inline step change bypasses the focus move*
- `GivingFormFlowTest::test_the_focus_move_waits_for_the_step_to_render` — AND THE MOVE WAITS FOR THE ELEMENT TO EXIST. — *focus() on a display:none element does nothing, so the move has to wait a tick*
- `GivingFormFlowTest::test_the_focus_target_only_shows_a_ring_to_the_keyboard` — A FOCUS RING ON A HEADING NOBODY CLICKED IS A RING NOBODY WANTED.
- `GivingFormFlowTest::test_the_interactive_controls_declare_a_reachable_height` — EVERY TOUCH TARGET CLEARS 44px.
- `GivingFormFlowTest::test_a_standing_order_is_never_the_default` — AND THE MONTHLY OPTION IS NEVER PRE-SELECTED. — *the form arrives with a monthly gift already chosen*
- `GivingFormFlowTest::test_the_form_states_that_a_gift_buys_no_votes` — THE PAGE SAYS A DONATION BUYS NO VOTES, AND THE CODE MAKES THAT TRUE. — *the giving form no longer tells a donor that a donation grants no votes*
- `OrgCampaignTest::test_a_closed_appeal_404s_rather_than_falling_through_to_the_general_fund` — The one that matters.
- `OrgCampaignTest::test_a_closed_appeal_prints_the_reason_and_not_just_a_heading` — The 404 page must actually PRINT its reason.
- `OrgCampaignTest::test_an_open_appeal_renders_its_real_progress` — An open appeal renders its real progress.
- `OrgCampaignTest::test_the_organisation_page_offers_its_open_appeals` — An organisation's own page lists its open appeals so a donor can choose a cause.
- `OrgPageTest::test_the_dashboard_has_a_form_that_reaches_the_service` — THERE IS A FORM, AND IT POSTS WHERE THE SERVICE LISTENS. — *the route has always existed; the form is what did not*
- `OrgPageTest::test_the_donation_page_includes_the_renderer` — AND THE DONATION PAGE ACTUALLY INCLUDES THE RENDERER. — *the controller has to read the brand for the template to draw it*
