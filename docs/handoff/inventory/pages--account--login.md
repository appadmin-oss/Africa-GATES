# `templates/pages/account/login.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /account/login → AccountController::loginForm`
**Extends:** layout/account-auth.twig · **Includes/imports:** partials/lottie.twig

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   MEMBER SIGN-IN — /account/login

   Two states on one route: credentials, and (with `sent`) the one-time code.

   ── WHAT IS DELIBERATELY NOT HERE ──────────────────────────────────────────
   No link to the admin console, the judges' panel or the organisation
   sign-in. Those are separate trust domains on their own routes and a shared
   public entry point is the thing that makes one of them a target.

   Three alternate ways in, and each one exists. The passkey row is HIDDEN until
   the script confirms this browser can run the ceremony, and is not rendered at
   all when the server half is unavailable — a row that opens nothing reads as
   the account being broken rather than as the feature being absent, which is
…
```

**Headings:** Check your email · Welcome back

**Data read (top-level variables/functions):** `error`, `login_email`, `notice`, `sent`, `passkeys_available`, `otp_ttl_minutes`, `assets`, `js`, `passkeys`

**Forms:**
- `POST /account/login/verify` fields: _token[hidden], email[hidden], email[email required,autocomplete], otp[text required,maxlength,pattern,autocomplete,inputmode]; buttons: Sign in
- `POST /account/login/otp` fields: _token[hidden], email[hidden]; buttons: Send a new one
- `POST /account/login` fields: _token[hidden], email[email required,autocomplete], password[password autocomplete]; buttons: Sign in | Email me a one-time code →formaction /account/logi

**States / branches (6 distinct conditions):** `sent` · `login_email` · `error` · `notice` · `passkeys_available|default(false)` · `not sent`

**Links out:** `/account/login` · `/vote` · `/account/forgot` · `/account/register`

**JS behaviours:** script `{{ asset('/assets/js/passkeys.js') }}`; 2 inline <script> block(s); data hooks: `data-ag-error-group`, `data-passkey-note`

**Accessibility affordances:** aria-hidden×11, aria-label×1; roles: alert, status; <label for>×4; autocomplete×4

**Legal / consent lines:**
- // Keep the FIELD and the boxes agreeing. `pattern` and `maxlength` police the

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_it_reads_the_table_that_actually_serves` — (no docblock)
- `AccountAuthScreensTest::test_the_code_screen_posts_the_address_the_code_was_sent_to` — (no docblock)
- `AccountAuthScreensTest::test_a_new_code_is_asked_for_by_post_and_never_by_a_link` — (no docblock)
- `AccountAuthScreensTest::test_the_real_code_field_is_visible_until_the_script_paints_the_boxes` — (no docblock)
- `AccountAuthScreensTest::test_the_code_field_and_the_boxes_cannot_disagree` — (no docblock)
- `AccountAuthScreensTest::test_a_row_marked_hidden_is_actually_hidden` — (no docblock)
- `AccountAuthScreensTest::test_the_passkey_row_is_absent_when_the_server_cannot_verify_one` — (no docblock)
- `AccountAuthScreensTest::test_recovery_is_offered_and_the_route_behind_it_exists` — (no docblock)
- `AccountAuthScreensTest::test_signing_in_with_no_password_names_the_code_path` — (no docblock)
- `AccountAuthScreensTest::test_a_wrong_password_and_an_unknown_address_read_the_same` — (no docblock)
- `AccountAuthScreensTest::test_showing_the_address_on_the_code_screen_does_not_consume_it` — (no docblock)
- `AccountDashboardTest::test_the_points_export_is_the_owners_and_only_the_owners` — (no docblock)
- `AliasRedirectTest` (whole file, via a helper/constant/data provider: 6 tests) — test_the_table_is_present_and_has_not_been_gutted, test_every_alias_target_is_a_real_route, test_no_alias_shadows_a_real_route, test_no_alias_redirects_to_another_alias, test_the_judge_portal_is_never_aliased, test_targets_are_absolute_and_aliases_are_single_segment
- `EventReferralPromptTest::test_the_sign_in_link_returns_to_this_event` — Sign-in must come back to the event, not dump them on an account page.
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `LoginNextRedirectTest::test_an_ordinary_local_path_still_goes_through` — (no docblock)
- `OneTimeCodeScreenTest::test_with_no_remembered_address_the_screen_asks_for_one` — THE FAULT. Every control the screen offers has to be able to succeed.
- `OneTimeCodeScreenTest::test_with_no_remembered_address_no_control_posts_an_empty_one` — And it does not offer a resend it cannot perform.
- `OneTimeCodeScreenTest::test_with_a_remembered_address_the_field_stays_hidden` — With an address in hand the screen keeps its cheaper shape: no second field.
- `OneTimeCodeScreenTest::test_a_code_verifies_when_the_address_travels_with_it` — A code posted with its address verifies, whatever the session remembers.
- `OneTimeCodeScreenTest::test_the_window_on_the_page_is_the_window_in_the_code` — The window is read from the rule, not typed into the page.
- `OneTimeCodeScreenTest::test_the_minted_code_lives_exactly_that_long` — And the mint uses the same one, so the promise and the token agree.
- `OneTimeCodeScreenTest::test_a_wrong_guess_counts_down_out_loud` — A wrong guess says how many are left, rather than killing the code unannounced.
- `OneTimeCodeScreenTest::test_the_cap_still_burns_the_code` — The cap still holds, and the code is spent once it is reached — the counting above must not have turned the cap into advice.
- `PulsePostingTest::test_a_guest_is_sent_to_sign_in_and_writes_nothing` — (no docblock)
- `SignInScreensTest::test_one_member_account_cannot_be_ground_from_many_addresses` — ONE ACCOUNT CANNOT BE GROUND FROM MANY ADDRESSES.
- `SignInScreensTest::test_every_way_of_failing_is_told_the_same_thing` — EVERY WAY OF FAILING IS TOLD THE SAME SENTENCE.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AccountAuthScreensTest::test_a_new_code_is_asked_for_by_post_and_never_by_a_link` — a GET link mints a code, so a mail scanner following links burns the one just sent — *no way to ask for a new code*
- `AccountAuthScreensTest::test_recovery_is_offered_and_the_route_behind_it_exists` — a password field with no way to recover one — */account/forgot*
- `AccountAuthScreensTest::test_showing_the_address_on_the_code_screen_does_not_consume_it` — rendering the code screen threw away the address it had just shown
- `AccountAuthScreensTest::test_the_code_field_and_the_boxes_cannot_disagree` — The code field and the boxes cannot disagree.
- `AccountAuthScreensTest::test_the_code_screen_posts_the_address_the_code_was_sent_to` — the screen never says which inbox to open — *no form posting to /account/login/verify*
- `AccountAuthScreensTest::test_the_passkey_row_is_absent_when_the_server_cannot_verify_one` — The passkey row is absent when the server cannot verify one.
- `OneTimeCodeScreenTest::test_the_window_on_the_page_is_the_window_in_the_code` — The window is read from the rule, not typed into the page.
- `OneTimeCodeScreenTest::test_with_a_remembered_address_the_field_stays_hidden` — With an address in hand the screen keeps its cheaper shape: no second field. — *asking again for an address the page is already printing is a field nobody needs*
- `OneTimeCodeScreenTest::test_with_no_remembered_address_no_control_posts_an_empty_one` — And it does not offer a resend it cannot perform. — *the code screen must still have a form on it*
- `OneTimeCodeScreenTest::test_with_no_remembered_address_the_screen_asks_for_one` — THE FAULT. Every control the screen offers has to be able to succeed. — *the code screen must ask for the address when the session no longer has one*
- `SignInScreensTest::test_the_lost_access_note_is_actionable` — THE ONE REMEDY THIS PAGE OFFERS HAS SOMEWHERE TO GO. — *the only remedy this page offers is plain text with nowhere to go*
- `SignInScreensTest::test_the_organisation_failure_does_not_live_in_the_url` — A FAILURE IS AN EVENT, NOT A URL. — *the failure is still being carried in the query string*
- `SignInScreensTest::test_the_organisation_form_hands_the_address_back` — A wrong password costs the password, not the address as well. — *a failed sign-in empties the address field and makes them type it again*
