# `templates/pages/org/login.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /org/login → OrgDashboardController::loginPage`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  THE ORGANISATION SIGN-IN.

  Deliberately plain: this screen's only job is to be unambiguous about which organisation's
  money is behind it.

  ── WHAT WAS WRONG WITH IT ──────────────────────────────────────────────────

  It said "Partner sign in · for organisations collecting donations through Africa GATES",
  and roughly half the accounts that reach it are STAND VENDORS. A trader signing in to
  upload a hygiene certificate was told, on the way, that this door is for somebody else —
  which is exactly the moment a person decides they are in the wrong place and leaves.

  It also had no way back to the application. Somebody who arrived here without an account
  had a dead end and a password field.
```

**Headings:** Sign in · No account yet

**Data read (top-level variables/functions):** `error`, `old_email`, `support_email`

**Forms:**
- `POST /org/login` [novalidate] fields: _token[hidden], email[email required,autocomplete], password[password required,autocomplete]; buttons: Sign in

**States / branches (2 distinct conditions):** `error` · `not old_email|default('')`

**Links out:** `mailto:{{ support_email }}` · `/events` · `/account/register?as=organisation`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** roles: alert; <label for>×2; autocomplete×2

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_the_member_screens_do_not_link_to_the_admin_judge_or_organisation_doors` — (no docblock)
- `SignInScreensTest::test_the_organisation_failure_does_not_live_in_the_url` — A FAILURE IS AN EVENT, NOT A URL.
- `SignInScreensTest::test_a_query_string_can_no_longer_accuse_anybody` — And the retired query string cannot conjure one.
- `SignInScreensTest::test_the_organisation_form_hands_the_address_back` — A wrong password costs the password, not the address as well.
- `SignInScreensTest::test_the_lost_access_note_is_actionable` — THE ONE REMEDY THIS PAGE OFFERS HAS SOMEWHERE TO GO.
