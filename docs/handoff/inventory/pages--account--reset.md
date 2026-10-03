# `templates/pages/account/reset.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /account/reset → AccountController::resetForm`
**Extends:** layout/account-auth.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   SET A NEW PASSWORD — /account/reset?token=…

   TWO SCREENS, AND THE DEAD-LINK ONE IS THE POINT. A reset link is the thing
   people find in an old email, on the wrong device, an hour late. When the
   token no longer works, this must not draw a password field: somebody types a
   new password, presses the button, and is told the link expired — the password
   is gone and so is the only screen that could have told them sooner.

   The controller only passes `token` when it is still live, so the field cannot
   be drawn against a dead one.
   ═══════════════════════════════════════════════════════════════════════════
```

**Headings:** Set a new password · That link has expired

**Data read (top-level variables/functions):** `email`, `error`, `token`, `errors`

**Forms:**
- `POST /account/reset` fields: _token[hidden], token[hidden], email[hidden autocomplete], password[password required,minlength,autocomplete]; buttons: Save it and sign me in

**States / branches (4 distinct conditions):** `token` · `email` · `error` · `errors.password|default('')`

**Links out:** `/account/forgot` · `/account/login`

**Accessibility affordances:** aria-invalid×1, aria-describedby×1; roles: alert; <label for>×1; autocomplete×2

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_recovery_is_offered_and_the_route_behind_it_exists` — (no docblock)
- `PasswordResetTest::test_a_completed_reset_tells_the_owner` — THE OWNER IS TOLD, AFTER THE FACT.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `PasswordResetTest::test_the_reset_screen_refuses_to_draw_a_password_field_for_a_dead_link` — the live token is not carried into the form — *a password field was drawn against a link that cannot work*
