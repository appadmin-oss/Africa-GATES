# `templates/pages/account/dashboard.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /account[/] → AccountController::dashboard`
**Extends:** layout/gates.twig · **Includes/imports:** partials/ui.twig, partials/icons.twig, partials/viz.twig, partials/promo-carousel.twig, partials/account-payout.twig, partials/celebrate.twig

**What the page says it is (its own header comment, abridged):**
```
 This page renders flash_* itself, beside the thing the message is about, so the
   layout rail is suppressed — otherwise every message would appear twice.
```

**Headings:** Your account · Voting points {{ gi.ui('eye', 18) }} {{ gi.ui('eye-off', 18) }} · Recent activity · Points · Balance · Ways to earn · History · Earnings unlocked · How it works · Links you made · {{ group.t }} · What you sell · Votes you cast · Signing in · Passkeys on this account · {{ g.t }}

**Data read (top-level variables/functions):** `ui`, `label`, `referral`, `you`, `leads`, `vendor`, `tone`, `the`, `user`, `st`, `what`, `key`, `gi`, `redeemable`, `me`, `points`, `of`, `bm`, `buy`, `events`, `buys`, `me_tabs`, `yet`, `votes`, `points_per_vote`, `icon`, `value`, `points_enabled`, `that`, `paid`, `my_challenges`, `account`, `vote`, `tgt`, `sub`, `pill`, `find`, `checklist`, `filter`, `month`, `up`, `stall`, `group`, `passkeys`, `rail`, `my_nominations`, `first_name`, `from`, `book`, `give`, `they`, `backed_winners`, `open_steps`, `labels`, `people`, `your`, `tickets`, `it`, `earn`, `shop`, `meta`, `saved`, `counts`, `bonus`, `my_tickets`, `recent`, `per`, `my_votes`, `flash_ok`, `flash_error`…

**Forms:**
- `POST /account/referral/code` fields: _token[hidden]; buttons: Get my link
- `POST /account/passkey/{{ k.id }}/delete` [data-confirm] fields: _token[hidden]; buttons: Remove

**States / branches (46 distinct conditions):** `user.email_verified` · `r.on is not defined or r.on` · `r.n` · `flash_ok` · `flash_error` · `is_new|default(false)` · `points_enabled` · `redeemable > 0` · `points_enabled and redeemable > 0` · `referral.accrued_naira|default(0) > 0` · `payout_can` · `a.to starts with '#me-'` · `a.n|default(0) > 0` · `loop.first` · `w.category` · `c.checking` · `c.needs` · `recent|length` · `open_steps is not empty` · `s.done` · `t.seats > 1` · `t.where` · `r.id != 'overview' and (r.on is not defined or r.on)` · `points_chart.ok|default(false)` · `e.created_at|date('F') != month` · `referral.code` · `my_links|default([])|length` · `l.expired` · `l.expires_at` · `my_challenges|default([])|length` · `c.pct is not null and c.state == 'open'` · `buys|length` · `b.when` · `b.amount` · `vendor` · `vendor.missing_docs|default([])|length` · `group.rows|length` · `vendor.items|default([])|length` · `my_nominations|length` · `my_votes|length` · `my_nominations is empty and my_votes is empty` · `bookmarks|length` · `passkeys_available` · `passkeys|default([])|length` · `k.last_used_at` · `backed_winners|default([])|length`

**Links out:** `#me-settings` · `#me-overview` · `#me-{{ r.id }}` · `#me-points` · `/vote` · `#me-referral` · `{{ a.to }}` · `/results` · `#me-activity` · `/challenges/{{ c.slug }}` · `{{ s.href }}` · `{{ t.url }}` · `/account/points.csv` · `{{ b.url }}` · `/community/{{ bm.slug }}` · `/account/logout`

**JS behaviours:** script `{{ asset('/assets/js/account.js') }}`; script `{{ asset('/assets/js/passkeys.js') }}`; 1 inline <script> block(s); data hooks: `data-me`, `data-me-title`, `data-me-go`, `data-find`, `data-celebrate`, `data-me-sign`, `data-rf-copy`, `data-me-copy`, `data-me-kind`, `data-me-nomstate`, `data-confirm`

**Accessibility affordances:** aria-labelledby×21, aria-label×10, aria-hidden×10, aria-disabled×2, aria-controls×1, aria-expanded×1, aria-level×1, aria-describedby×1, aria-live×1, aria-pressed×1; roles: heading, status, alert; <label for>×1; autocomplete×1

**Legal / consent lines:**
- {'t':'Privacy','rows':[
- {'l':'Cookie settings','v':'','to':'/cookies'},
- {'l':'How we use your data','v':'','to':'/privacy'}

**Styling carried:** 1 <style> block(s), 7 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountDashboardTest::test_no_section_can_exist_without_being_reachable` — Every section in the document is reachable, and every rail link goes somewhere.
- `AccountDashboardTest::test_every_tab_gets_its_reveal_rule` — And the reveal rule is generated for every one of them.
- `AccountTabsTest::test_the_controllers_redirect_hash_is_a_real_section` — The controller redirects to `/account#me-referral` after minting a code, after a payout request and after saving bank details.
- `AccountTabsTest::test_a_section_is_revealed_by_the_hash_alone` — THE ONE THAT MATTERS.
- `FlashKeyTest::test_pages_that_render_their_own_flash_suppress_the_layout_rail` — The pages that place their own flash must opt OUT, or every message appears twice.
- `SupportTicketNamingTest::test_event_tickets_keep_their_name` — Event tickets are NOT renamed.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AccountDashboardTest::test_a_brand_new_account_gets_a_welcome_and_not_six_empty_panels` — there is nothing to chart, and a graph of nothing is the worst first screen there is
- `AccountDashboardTest::test_a_member_with_history_gets_the_chart_and_the_table_behind_it` — A member with history gets the chart and the table behind it.
- `AccountDashboardTest::test_both_scripts_receive_the_tab_list` — The foot script must receive a REAL array, not `null`. — *the head script no longer renders the tab list*
- `AccountDashboardTest::test_every_section_is_in_the_document` — Every section is in the document.
- `AccountDashboardTest::test_every_tab_gets_its_reveal_rule` — And the reveal rule is generated for every one of them.
- `AccountDashboardTest::test_no_section_can_exist_without_being_reachable` — Every section in the document is reachable, and every rail link goes somewhere. — *the me_tabs list has moved or gone*
- `AccountDashboardTest::test_the_chart_is_drawn_on_the_server` — The chart is drawn on the server.
- `AccountDashboardTest::test_the_page_carries_no_emoji_as_interface_iconography` — the account page draws its icons as SVG
- `AccountDashboardTest::test_the_section_switch_needs_no_framework_and_no_javascript` — .me-sec{ display:none
- `AccountTabsTest::test_the_tab_list_is_defined_once_and_reused` — The list is defined ONCE and rendered into both scripts. — *more than one tab list*
- `AccountTabsTest::test_the_reveal_rules_are_generated_from_the_one_list` — The reveal rules are GENERATED from the one list.
- `AccountTabsTest::test_every_tab_has_a_section_with_the_matching_id` — Every tab needs a section whose id matches it exactly.
- `AccountTabsTest::test_every_tab_has_a_rail_item` — And a rail item, or there is nothing to click. — *the rail list is gone*
- `AccountTabsTest::test_referrals_is_reachable` — The regression itself, named. — *referrals is not a tab*
- `AccountTabsTest::test_the_controllers_redirect_hash_is_a_real_section` — The controller redirects to `/account#me-referral` after minting a code, after a payout request and after saving bank details. — *no anchored redirects found — did they change?*
- `AccountTabsTest::test_a_section_is_revealed_by_the_hash_alone` — THE ONE THAT MATTERS. — *without a :target rule the rail cannot work when the script does not run*
- `AccountTabsTest::test_every_section_has_a_title_in_the_phone_child_bar` — ══ THE PHONE HUB IS A FIFTH PLACE A SECTION ID IS SPELLED ═════════════════ The docblock above counts four things that have to agree for a section to be visible. — *the child bar titles are no longer looped from `rail` — a typed list is the drift*
- `AccountTabsTest::test_the_phone_list_is_the_rail_and_not_a_copy_of_it` — The rail is `display:none` below 600px, so this list is the whole of phone navigation. — *the phone list is hand-written — that is the fourth copy of the section list*
- `AccountTabsTest::test_the_balance_answer_is_applied_before_first_paint` — Hiding a balance has to happen BEFORE the first paint, which means the head script and not the foot one. — *the stored answer is read after first paint, so the balance flashes on every load*
- `SupportTicketNamingTest::test_event_tickets_keep_their_name` — Event tickets are NOT renamed.
