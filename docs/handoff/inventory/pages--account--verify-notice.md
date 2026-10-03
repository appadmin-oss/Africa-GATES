# `templates/pages/account/verify-notice.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /account/verify → AccountController::verifyEmail`
**Extends:** layout/account-auth.twig · **Includes/imports:** partials/lottie.twig

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   CONFIRM YOUR EMAIL — /account/verify

   A different screen from the sign-in code, and the difference matters: this
   one is a verification LINK sent after registration, not a 6-digit code. The
   two used to look alike enough that people typed a code into a page with no
   code field.

   The one-time sign-in code also verifies an address (see
   AccountController::otpVerify), so the fastest way out of this screen is
   often the sign-in page — said plainly at the foot rather than left for
   somebody to work out.
   ═══════════════════════════════════════════════════════════════════════════
```

**Headings:** Confirm your email

**Data read (top-level variables/functions):** `email`, `error`, `notice`, `ttl_hours`

**Forms:**
- `POST /account/verify/resend` fields: _token[hidden], email[hidden], email[email required,autocomplete]; buttons: Resend the link

**States / branches (3 distinct conditions):** `email` · `error` · `notice`

**Links out:** `/account/login`

**Accessibility affordances:** aria-hidden×1; roles: alert, status; <label for>×1; autocomplete×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest` (whole file, via a helper/constant/data provider: 19 tests) — test_it_reads_the_table_that_actually_serves, test_every_link_and_form_on_the_member_auth_screens_reaches_a_real_route, test_the_member_screens_do_not_link_to_the_admin_judge_or_organisation_doors, test_the_code_screen_posts_the_address_the_code_was_sent_to, test_a_new_code_is_asked_for_by_post_and_never_by_a_link, test_the_real_code_field_is_visible_until_the_script_paints_the_boxes, test_the_code_field_and_the_boxes_cannot_disagree, test_a_row_marked_hidden_is_actually_hidden, test_the_passkey_row_is_absent_when_the_server_cannot_verify_one, test_recovery_is_offered_and_the_route_behind_it_exists, test_the_chooser_offers_every_kind_of_account_this_platform_has, test_the_individual_form_is_one_click_in_and_keeps_what_was_typed …
- `EmailVerificationTest::test_one_connection_cannot_resend_to_an_endless_list_of_addresses` — RESEND WAS THE ONE MAIL-SENDING ENDPOINT COUNTING ONE OF THE TWO.
- `EmailVerificationTest::test_the_notice_states_the_window_and_gets_it_from_the_constant` — THE PAGE STATES THE WINDOW, AND READS IT FROM THE CODE.
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `EmailVerificationTest::test_the_notice_states_the_window_and_gets_it_from_the_constant` — THE PAGE STATES THE WINDOW, AND READS IT FROM THE CODE. — *the page does not state how long the link lives, or has its own copy of the number*
