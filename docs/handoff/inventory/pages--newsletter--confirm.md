# `templates/pages/newsletter/confirm.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /email/confirm → NewsletterController::confirmShow`; `POST /email/confirm → NewsletterController::confirm`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  /email/confirm — the yes. Three states, and each says exactly one thing.

  The GET renders a button rather than confirming, because mail scanners fetch every
  link in a message and would otherwise confirm on the reader's behalf — see
  NewsletterController.
```

**Headings:** You are on the list · Confirm your subscription · This link did not work

**Data read (top-level variables/functions):** `email`, `valid`, `assets`, `css`, `components`, `newsletter`, `done`

**Forms:**
- `POST /email/confirm` fields: _token[hidden], e[hidden], t[hidden]; buttons: Yes, send it to me

**States / branches (2 distinct conditions):** `done and valid` · `valid`

**Links out:** `/` · `/newsletter`

**Legal / consent lines:**
- issue ends with a one-click unsubscribe.

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/newsletter.css

**Guard tests that read this page (at the time of the destroy):**
- `NewsletterTest::test_a_signup_gets_one_confirmation_and_nothing_else` — (no docblock)
- `NewsletterTest::test_the_pages_render_through_the_real_app` — (no docblock)
