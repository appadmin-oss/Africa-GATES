# `templates/pages/gated-form.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /form/{token} → GatedFormController::show`; `POST /form/{token} → GatedFormController::submit`
**Extends:** layout/gates.twig · **Includes/imports:** —

**Headings:** {% if subject_name %}Congratulations, {{ subject_name }}!{% else %}Complete your · Thank you! · {% if status == 'used' %}This form is already complete{% elseif status == 'expir

**Data read (top-level variables/functions):** `status`, `purpose`, `subject_name`, `error`, `onboarding`, `acceptance`, `token`, `terms_url`, `terms`

**Forms:**
- `POST /form/{{ token }}` fields: _token[hidden], bio[textarea required,maxlength], expertise[text maxlength], phone[tel maxlength], links[text maxlength], accept_terms[checkbox required]; buttons: Submit my details

**States / branches (7 distinct conditions):** `status == 'ok'` · `subject_name` · `purpose == 'judge'` · `error` · `status == 'done'` · `status == 'used'` · `status == 'expired'`

**Links out:** `{{ terms_url|default('/terms') }}` · `/`

**Accessibility affordances:** aria-hidden×1; roles: alert; <label for>×4

**Legal / consent lines:**
- I confirm these details are accurate and I accept the programme terms.

**Styling carried:** 0 <style> block(s), 32 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
