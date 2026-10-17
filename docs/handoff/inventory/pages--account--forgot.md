# `templates/pages/account/forgot.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /account/forgot → AccountController::forgotForm`
**Extends:** layout/account-auth.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   FORGOTTEN PASSWORD — /account/forgot

   Every reply here is the same sentence whether or not the address has an
   account. Answering "no account uses that email" is a free membership check
   for anybody who wants one, and this platform's members are named public
   figures — so the difference between the two answers is the disclosure.

   The one-time code is still offered beside it, because for most people it is
   the faster way back in: it signs them in now, and a password can be set
   afterwards from the account page.
   ═══════════════════════════════════════════════════════════════════════════
```

**Headings:** Reset your password

**Data read (top-level variables/functions):** `error`, `notice`, `errors`, `login_email`, `otp_ttl_minutes`

**Forms:**
- `POST /account/forgot` fields: _token[hidden], email[email required,autocomplete]; buttons: Email me a reset link | Send a one-time code instead →formaction /account/

**States / branches (3 distinct conditions):** `error` · `notice` · `errors.email|default('')`

**Links out:** `/account/login`

**Accessibility affordances:** aria-hidden×1, aria-invalid×1, aria-describedby×1; roles: alert, status; <label for>×1; autocomplete×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_recovery_is_offered_and_the_route_behind_it_exists` — (no docblock)
- `PasswordResetTest::test_an_address_with_an_account_and_one_without_read_identically` — (no docblock)
- `PasswordResetTest::test_the_reset_screen_refuses_to_draw_a_password_field_for_a_dead_link` — (no docblock)
