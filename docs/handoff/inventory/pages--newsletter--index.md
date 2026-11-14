# `templates/pages/newsletter/index.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /newsletter → NewsletterController::show`; `POST /newsletter → NewsletterController::join`
**Extends:** layout/gates.twig · **Includes/imports:** partials/ui.twig

**What the page says it is (its own header comment, abridged):**
```
  /newsletter — what the newsletter is, and the form to join it.

  Every structural fact on this page is read from the code that acts on it: the day it
  goes out from NewsletterSchedule::describe(), what is in it from
  NewsletterComposer::SECTIONS. A page that types "every Thursday" goes on saying it the
  week somebody moves the send to Monday.

  The form posts. It does not need JavaScript, and the page it lands on says the one thing
  the person has to do next — open the confirmation email — because until they do,
  nothing arrives.
```

**Headings:** Africa GATES in your inbox · Check your inbox · Join the list · When it arrives · What is in it

**Data read (top-level variables/functions):** `holidays`, `email_error`, `schedule`, `assets`, `css`, `components`, `newsletter`, `old`, `ui`, `sections`, `sent`

**Forms:**
- `POST /newsletter` [novalidate] fields: _token[hidden], email[email required,maxlength,autocomplete,inputmode]; buttons: Send me the newsletter

**States / branches (4 distinct conditions):** `sent` · `email_error` · `schedule` · `holidays`

**Accessibility affordances:** aria-labelledby×4, aria-invalid×1, aria-describedby×1; roles: alert; <label for>×1; autocomplete×1

**Legal / consent lines:**
- One confirmation email first. Every issue has a one-click unsubscribe.

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/newsletter.css

**Guard tests that read this page (at the time of the destroy):**
- `HolidayNewsletterTest::test_the_public_page_names_the_holidays_from_the_calendar` — (no docblock)
- `NewsletterTest::test_the_pages_render_through_the_real_app` — (no docblock)
- `NewsletterTest::test_the_signup_form_posts_and_lands_on_what_to_do_next` — (no docblock)
- `NewsletterTest::test_the_newsletter_is_linked_from_every_page` — (no docblock)

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `HolidayNewsletterTest::test_the_public_page_names_the_holidays_from_the_calendar` — The public page names the holidays from the calendar.
- `NewsletterTest::test_the_pages_render_through_the_real_app` — the day the page promises is read from the setting that sends on it — *never clear what somebody typed*
