# `templates/pages/account/register.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /account/register → AccountController::registerForm`
**Extends:** layout/account-auth.twig · **Includes/imports:** partials/field.twig

**What the page says it is (its own header comment, abridged):**
```
 ═══════════════════════════════════════════════════════════════════════════
   CREATE AN ACCOUNT — /account/register

   Two steps on one route: a chooser, then the individual form
   (`?as=individual`).

   ── WHY A CHOOSER ──────────────────────────────────────────────────────────
   Four kinds of account exist on this platform and until now exactly one of
   them was reachable from the public site. Somebody nominated by a friend, a
   non-profit that wants a Giving page, a trader after a stand: each had a
   route, a form and a controller, and no door anybody could find. The
   organisation application was linked from the ORGANISATION SIGN-IN — a page
   you reach by already having an account. That is this codebase's oldest
   shape of fault: a mechanism complete on every side with no way in.
…
```

**Headings:** Create your account · Register your organisation · Join Africa GATES

**Data read (top-level variables/functions):** `errors`, `old`, `error`, `fld`, `to`, `fix`, `field`, `text`, `one`, `thing`, `are`, `things`, `org_signed_in`

**Forms:**
- `POST /account/register` [novalidate] fields: _token[hidden], as[hidden], name[text required,pattern,autocomplete], email[email required,autocomplete], phone[tel required,autocomplete,inputmode], password[password minlength,autocomplete]; buttons: Create account
- `POST /account/register` fields: _token[hidden], as[hidden], name[text required,maxlength], legal_name[text required,maxlength], cac_number[text required,maxlength,pattern], scuml_number[text maxlength], contact_email[email required,maxlength,autocomplete], password[password required,minlength,autocomplete], contact_name[text maxlength,autocomplete], contact_phone[tel maxlength,autocomplete,inputmode], description[textarea]; buttons: Send application

**States / branches (10 distinct conditions):** `as|default('') == 'individual'` · `error` · `errors|default({})|length` · `errors.name|default('')` · `errors.email|default('')` · `errors.phone|default('')` · `errors.password|default('')` · `as|default('') == 'organisation'` · `org_signed_in|default(false)` · `errors.contact_email|default('')`

**Links out:** `/account/register` · `#{{ field }}` · `/terms` · `/privacy` · `/org` · `/events` · `/account/register?as=individual` · `/vote` · `/account/register?as=organisation` · `/partner` · `/account/login`

**Accessibility affordances:** aria-hidden×6, aria-invalid×5, aria-describedby×5; roles: alert, status; <label for>×13; tabindex×1; autocomplete×8

**Legal / consent lines:**
- usable. By continuing you accept the Terms and
- Privacy notice.

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `AccountAuthScreensTest::test_the_chooser_offers_every_kind_of_account_this_platform_has` — (no docblock)
- `AccountAuthScreensTest::test_the_individual_form_is_one_click_in_and_keeps_what_was_typed` — (no docblock)
- `AccountAuthScreensTest::test_a_rejected_registration_returns_to_the_form_and_not_to_the_chooser` — (no docblock)
- `AccountAuthScreensTest::test_the_name_rule_in_the_browser_matches_the_one_on_the_server` — THE BROWSER'S RULE AND THE SERVER'S RULE ARE THE SAME RULE.
- `AccountAuthScreensTest::test_the_form_states_what_it_will_refuse` — A requirement met for the first time in a refusal reads as arbitrary.
- `AccountAuthScreensTest::test_each_branch_says_which_one_it_is` — Both branches name themselves in the BODY.
- `CheckoutStartsEndToEndTest::test_the_registration_form_survives_a_failed_attempt` — ── THE REGISTRATION FORM AFTER A FAILED ATTEMPT ───────────────────────── `AccountController::flash()` was declared `?string`.
- `GivingUrlTest::test_every_path_is_built_from_one_base` — (no docblock)
- `HeadingHierarchyTest` (whole file, via a helper/constant/data provider: 1 tests) — test_public_pages_have_one_h1_and_no_level_jumps
- `OrgApplyTest::test_a_rejected_form_comes_back_filled_in` — A bad detail must not cost them the other nine fields.
- `OrgApplyTest::test_the_chooser_opens_the_application_in_place` — The chooser sends a non-profit to the branch, not to a page of its own.
- `OrgApplyTest::test_an_application_without_the_branch_field_is_not_an_application` — The branch travels in the BODY, and the handler reads it there.
- `OrgApplyTest::test_a_failed_application_does_not_leak_into_the_member_form` — A FAILED APPLICATION MUST NOT PREFILL THE OTHER FORM.
- `OrgApplyTest::test_member_registration_is_rate_limited_per_connection` — IT SENDS AN EMAIL PER CALL, AND HAD NO LIMIT OF ANY KIND.
- `SeoSitemapTest::test_the_core_section_points_at_destinations_not_redirects` — `/register` 301s to `/account/register`, and the hand-written sitemap advertised the redirect for as long as it existed.

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `AccountAuthScreensTest::test_each_branch_says_which_one_it_is` — Both branches name themselves in the BODY. — *the $branch form does not name its own branch*
- `AccountAuthScreensTest::test_the_chooser_offers_every_kind_of_account_this_platform_has` — register a non-profit — *partner or exhibit*
- `AccountAuthScreensTest::test_the_form_states_what_it_will_refuse` — A requirement met for the first time in a refusal reads as arbitrary. — *the page refuses disposable inboxes and never says so*
- `AccountAuthScreensTest::test_the_individual_form_is_one_click_in_and_keeps_what_was_typed` — The individual form is one click in and keeps what was typed.
- `AccountAuthScreensTest::test_the_name_rule_in_the_browser_matches_the_one_on_the_server` — THE BROWSER'S RULE AND THE SERVER'S RULE ARE THE SAME RULE. — *the name field no longer states its rule where it is typed*
- `CheckoutStartsEndToEndTest::test_the_registration_form_survives_a_failed_attempt` — ── THE REGISTRATION FORM AFTER A FAILED ATTEMPT ───────────────────────── `AccountController::flash()` was declared `?string`. — *GET /account/register after a failed submission*
- `OrgApplyTest::test_a_failed_application_does_not_leak_into_the_member_form` — A FAILED APPLICATION MUST NOT PREFILL THE OTHER FORM.
- `OrgApplyTest::test_a_rejected_form_comes_back_filled_in` — A bad detail must not cost them the other nine fields.
- `OrgApplyTest::test_the_application_still_gets_its_own_values_back` — And the application's own values still come back to the application. — *keying the bag per branch emptied the branch it belongs to*
- `OrgApplyTest::test_the_chooser_opens_the_application_in_place` — The chooser sends a non-profit to the branch, not to a page of its own. — *the chooser no longer offers the organisation branch*
- `OrgApplyTest::test_the_page_states_what_it_will_ask_for` — The requirements are on the page, above the form, not behind a link.
