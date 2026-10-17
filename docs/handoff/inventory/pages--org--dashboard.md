# `templates/pages/org/dashboard.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /org → OrgDashboardController::dashboard`
**Extends:** layout/gates.twig · **Includes/imports:** partials/icons.twig, partials/viz.twig

**What the page says it is (its own header comment, abridged):**
```
 This page renders flash_* itself, beside the thing the message is about, so the
   layout rail is suppressed — otherwise every message would appear twice.
```

**Headings:** {{ org.name }} · Overview · This organisation is suspended · This application was not accepted · {{ n_missing }} document{{ n_missing == 1 ? '' : 's' }} still to upload · Everything is on file. We are reviewing it. · You have been offered {{ n_offers == 1 ? 'a stand' : n_offers ~ ' stands' }} · {{ is_vendor ? 'Your account is approved and complete' : 'Your organisation is l · Your documents · Your stand applications · Donations · Appeals · Your page · Getting paid · Stand fees

**Data read (top-level variables/functions):** `org`, `secn`, `ngn`, `spec`, `show_money`, `counts`, `is_vendor`, `available`, `label`, `can_payout`, `brand`, `st`, `n_missing`, `totals`, `name`, `applications`, `n_offers`, `min_payout`, `slug`, `cls`, `brand_sections`, `lrows`, `vrows`, `rows`, `account`, `key`, `payouts`, `campaigns`, `required`, `me`, `gi`, `flash_ok`, `flash_error`, `viz`, `money_chart`, `doc_kinds`, `brand_max_tagline`, `brand_max_story`, `brand_video_names`, `missing`, `has_money`, `rail`, `documents`, `uploads_on`, `donations`, `fund_events`, `todo`, `stand`, `stands`, `approved`, `complete`, `organisation`, `live`, `of`, `each`, `gift`, `take`, `nothing`, `decisions`, `brand_max_links`, `brand_max_videos`, `own_flash`, `to`, `do`, `offered`, `page`, `paid`, `fees`, `individual`, `shortfall`…

**Forms:**
- `POST /org/logout` fields: _token[hidden]; buttons: Sign out
- `POST /org/document` [enctype] fields: _token[hidden], kind[select], document[file required], expires_on[date]; buttons: Upload
- `POST /org/stand/{{ a.app.id }}/accept` fields: _token[hidden]; buttons: Accept
- `POST /org/appeal/{{ c.row.id }}/submit` fields: _token[hidden]; buttons: Send for review
- `POST /org/appeal/{{ c.row.id }}/close` fields: _token[hidden]; buttons: Close
- `POST /org/appeal` fields: _token[hidden], title[text required,maxlength], summary[text maxlength], story[textarea], target_naira[text inputmode], closes_on[date], event_id[select], shortfall_policy[select]; buttons: Save as draft
- `POST /org/brand` [enctype] fields: _token[hidden], accent[color], logo[file], tagline[text maxlength], story[textarea maxlength], website[url inputmode], section_{{ key }}[checkbox], link_label[][text maxlength], link_url[][url inputmode], video_title[][text maxlength], video_url[][text inputmode], {{ name }}_{{ spec.a }}[][text maxlength], {{ name }}_{{ spec.b }}[][text maxlength]; buttons: Save your page
- `POST /org/payout` fields: _token[hidden], amount[text required,inputmode]; buttons: Request payout

**States / branches (55 distinct conditions):** `me.role != 'owner'` · `flash_ok` · `flash_error` · `r.on` · `r.n` · `org.status == 'suspended'` · `is_vendor` · `org.suspended_reason` · `org.status == 'rejected'` · `org.vetting_note` · `n_missing > 0` · `org.status != 'approved'` · `n_offers > 0` · `show_money and totals.count > 0` · `available > 0` · `can_payout and org.status == 'approved' and available >= min_payout` · `can_payout and org.status == 'approved'` · `show_money and money_chart.ok|default(false)` · `required is not empty` · `individual` · `missing[slug] is defined` · `documents` · `d.expires_on` · `d.expires_on|slice(0,10) < "now"|date("Y-m-d")` · `can_payout and uploads_on` · `not uploads_on` · `applications or is_vendor` · `not applications` · `a.type` · `not a.app.completed_at` · `a.app.decision_reason` · `a.app.eligibility == 'fail' and a.app.eligibility_note` · `a.live_offer` · `can_payout` · `a.expired` · `show_money` · `donations` · `campaigns` · `c.open` · `c.days is not null and c.open` · `c.progress.target > 0` · `st == 'live' and not c.open` · `st in ['draft', 'closed']` · `st == 'live'` · `fund_events|default([])|length` · `e.event_date` · `not can_payout` · `brand.logo` · `payout_mode == 'settlement'` · `org.settlement_schedule == 'manual'` · `org.account_last4` · `available >= min_payout and org.status == 'approved'` · `payouts` · `p.gateway_message` · `not show_money`

**Links out:** `#{{ r.id }}` · `#documents` · `#applications` · `#payouts` · `/events` · `/giving/{{ org.slug }}/{{ c.row.slug }}` · `/giving/{{ org.slug }}`

**JS behaviours:** 2 inline <script> block(s); data hooks: `data-page`, `data-pd`, `data-pd-go`, `data-find`, `data-confirm`

**Accessibility affordances:** aria-label×8, aria-hidden×7, aria-selected×6, aria-describedby×1, aria-live×1, aria-labelledby×1; roles: status, alert; visually-hidden text×7; <label for>×13; autocomplete×1; prefers-reduced-motion×1

**Legal / consent lines:**
- the fee disclosure or the line naming who receives the money.

**Styling carried:** 1 <style> block(s), 24 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_the_member_screens_do_not_link_to_the_admin_judge_or_organisation_doors` — (no docblock)
- `FlashKeyTest::test_pages_that_render_their_own_flash_suppress_the_layout_rail` — The pages that place their own flash must opt OUT, or every message appears twice.
- `OrgApplyTest::test_an_organisation_can_apply_and_lands_on_its_dashboard` — (no docblock)
- `OrgApplyTest::test_a_signed_in_user_is_sent_to_their_dashboard` — Somebody already signed in has an organisation; a second one is a duplicate.
- `OrgConsoleLedeTest` (whole file, via a helper/constant/data provider: 3 tests) — test_the_money_is_on_the_first_screen_and_not_behind_the_rail, test_the_lede_never_offers_a_payout_the_form_would_refuse, test_an_organisation_with_no_gifts_is_not_shown_a_wall_of_zeroes
- `OrgPageTest::test_the_dashboard_has_a_form_that_reaches_the_service` — THERE IS A FORM, AND IT POSTS WHERE THE SERVICE LISTENS.
- `PartnerDashboardTest` (whole file, via a helper/constant/data provider: 8 tests) — test_the_rail_offers_a_donation_partner_the_money_and_not_the_stands, test_the_rail_offers_a_vendor_the_stands_and_not_the_appeals, test_a_vendor_who_later_takes_donations_gets_both, test_the_section_switch_needs_no_framework_and_no_javascript, test_the_money_chart_appears_only_once_there_is_money, test_the_chart_counts_the_partners_share_and_not_the_gross, test_the_rail_says_what_is_outstanding_in_a_word, test_the_page_draws_its_icons_rather_than_borrowing_them_from_a_font
- `PublicIaTest::test_the_partner_console_is_reachable_from_the_public_site` — (no docblock)
- `SignInScreensTest::test_the_organisation_failure_does_not_live_in_the_url` — A FAILURE IS AN EVENT, NOT A URL.
- `SignInScreensTest::test_a_query_string_can_no_longer_accuse_anybody` — And the retired query string cannot conjure one.
- `SignInScreensTest::test_the_organisation_form_hands_the_address_back` — A wrong password costs the password, not the address as well.
- `SignInScreensTest::test_the_lost_access_note_is_actionable` — THE ONE REMEDY THIS PAGE OFFERS HAS SOMEWHERE TO GO.
- `StandPhotosTest` (whole file, via a helper/constant/data provider: 17 tests) — test_a_photograph_is_stored_and_the_first_one_is_the_cover, test_a_seventh_photograph_is_refused, test_a_tiny_photograph_is_refused, test_the_third_photograph_completes_the_application, test_missing_photographs_are_reported_like_a_missing_document, test_photographs_are_not_an_eligibility_rule, test_removing_a_photograph_does_not_move_the_completeness_clock, test_removing_the_cover_promotes_the_next_photograph, test_reordering_puts_the_named_photograph_first, test_another_vendors_application_is_not_reachable, test_a_signed_out_visitor_is_refused, test_photographs_cannot_be_changed_after_the_call_closes …
- `StandSurfacesTest::test_a_stranger_can_register_and_apply_in_one_go` — One request: an account and an application.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `OrgConsoleLedeTest::test_the_lede_never_offers_a_payout_the_form_would_refuse` — THE ONE THAT MATTERS: the offer and the form agree, in every state. — *an owner with a requestable balance is not offered it*
- `OrgConsoleLedeTest::test_the_money_is_on_the_first_screen_and_not_behind_the_rail` — The figures open the console, and they are no longer inside a later section. — *the console no longer opens with the money*
- `PartnerDashboardTest::test_the_rail_offers_a_donation_partner_the_money_and_not_the_stands` — The rail offers a donation partner the money and not the stands.
- `PartnerDashboardTest::test_the_rail_offers_a_vendor_the_stands_and_not_the_appeals` — The rail offers a vendor the stands and not the appeals.
- `PartnerDashboardTest::test_a_vendor_who_later_takes_donations_gets_both` — A vendor who later takes donations gets both.
- `PartnerDashboardTest::test_the_section_switch_needs_no_framework_and_no_javascript` — .pd-sec{ display:none
- `PartnerDashboardTest::test_the_money_chart_appears_only_once_there_is_money` — a graph of nothing, shown to the partner still waiting on a review
- `PartnerDashboardTest::test_the_chart_counts_the_partners_share_and_not_the_gross` — The chart counts the partners share and not the gross.
- `PartnerDashboardTest::test_the_rail_says_what_is_outstanding_in_a_word` — and it is marked as outstanding, not merely counted
- `PartnerDashboardTest::test_the_page_draws_its_icons_rather_than_borrowing_them_from_a_font` — an emoji is a font lookup, and several useful ones are simply missing — *the shared coloured set*
- `OrgConsoleLedeTest::test_an_organisation_with_no_gifts_is_not_shown_a_wall_of_zeroes` — A console with no money does not draw four zeroes at somebody. — *an organisation with nothing received is shown an empty summary*
