# `templates/pages/interview.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /interview/{token:[a-f0-9]{32}} → InterviewController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── THE NOMINEE'S INTERVIEW PAGE ─────────────────────────────────────────────

   Reached only from the link in an invitation. The person reading it is, most likely,
   somebody who has never used this site, on a phone, who has just learned that a panel
   of strangers wants to interview them. Everything here is written for that person.

   THE CONSENT BOX IS THE POINT OF THE PAGE. It is the only place a nominee can give
   permission for their words to be recorded, transcribed by a machine, and read by the
   people deciding their award — and InterviewService::publish() refuses to show the panel
   anything without it. So it is not a checkbox at the bottom of a form: it says what is
   recorded, who reads it, that a machine writes it down, and that saying no is allowed and
   costs them nothing.

   Two decisions worth stating:
…
```

**Headings:** This link is not working · {{ iv.nominee }}, the panel would like to speak with you · What we will talk about · Confirm you are coming · Permission to record and write it down

**Data read (top-level variables/functions):** `iv`, `support_email`, `africagates`, `notice`, `error`, `to`, `link`, `follow`, `move`, `it`, `replied`, `yet`, `permission`, `given`, `token`, `my`, `answer`, `will`, `be`, `there`

**Forms:**
- `POST /interview/{{ token }}` fields: _token[hidden], consent[checkbox], name[text maxlength,autocomplete], note[text maxlength]; buttons: {{ iv.confirmed ? 'Update my answer' : 'Yes, I wil | I cannot make this time — please move it

**States / branches (7 distinct conditions):** `iv is null` · `notice` · `error` · `iv.meet_url and iv.open` · `not iv.open` · `iv.themes|length` · `iv.open and not iv.past`

**Links out:** `mailto:{{ support_email|default('support@africagates.org') }}` · `{{ iv.meet_url }}`

**Accessibility affordances:** <label for>×2; autocomplete×1

**Legal / consent lines:**
- /* Consent. Deliberately the most prominent thing on the page after the time. */
- .ivp__consent{ margin-top:22px; background:#fffdf5; border:1px solid #ecdca0; border-radius:16px;
- .ivp__consent h2{ margin:0 }
- .ivp__consent ul{ margin:12px 0 0; padding-left:20px; font-size:14px; line-height:1.8; color:#4a5256 }
- {{ iv.confirmed ? 'confirmed' : (iv.declined ? 'asked to move it' : 'not replied yet') }}{{ iv.consented ? ' · permission given' : '' }}
- transcript. Please read this before you agree:
- I agree to the conversation being recorded and transcribed, and to the judging
- value="{{ iv.consent_name }}">

**Styling carried:** 1 <style> block(s), 2 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `InterviewFlowTest::test_an_unknown_token_says_nothing_about_whether_it_exists` — An unknown token says nothing about whether it exists.
- `InterviewFlowTest::test_the_nominee_page_offers_consent_and_never_the_questions` — the themes are shown — *the panel’s exact wording must not leak — that interviews the rehearsal*
- `InterviewFlowTest::test_the_page_is_not_indexable` — The page is not indexable.
- `InterviewPageTest::test_the_interview_screen_is_served_and_not_the_form` — The interview screen is served and not the form.
- `InterviewPageTest::test_the_four_promises_are_on_the_page` — The four promises are on the page.
- `InterviewPageTest::test_the_form_escape_hatch_needs_no_javascript` — The form escape hatch needs no javascript.
- `InterviewPageTest::test_the_model_cannot_reach_the_submit` — The model cannot reach the submit.
- `InterviewPageTest::test_a_deployment_without_the_key_gets_the_form_instead` — A deployment without the key gets the form instead.
- `InterviewPageTest::test_an_interview_already_started_shows_the_degraded_screen_not_a_dead_end` — An interview already started shows the degraded screen not a dead end.
- `InterviewPageTest::test_a_submitted_interview_says_it_has_been_sent` — A submitted interview says it has been sent.
- `InterviewPageTest::test_the_composer_stops_a_paste_the_server_would_truncate` — The composer stops a paste the server would truncate.
- `InterviewPageTest::test_every_machine_derived_value_is_labelled_as_such` — Every machine derived value is labelled as such.
- `InterviewPageTest::test_the_progress_rail_carries_no_percentage` — The progress rail carries no percentage.
