# `templates/pages/email-unsubscribe.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /email/unsubscribe → EmailPrefsController::show`; `POST /email/unsubscribe → EmailPrefsController::stop`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 The stop page for bulk email. Three states, and each one has to be unambiguous —
   somebody arrives here wanting exactly one thing to be true afterwards.
```

**Headings:** You’ve been unsubscribed · Stop these emails? · This link didn’t work

**Data read (top-level variables/functions):** `site_url`, `email`, `valid`, `done`

**Forms:**
- `POST {{ site_url }}/email/unsubscribe` fields: e[hidden], t[hidden]; buttons: Yes, unsubscribe me

**States / branches (2 distinct conditions):** `done and valid` · `valid`

**Links out:** `{{ site_url }}/support` · `{{ site_url }}/`

**Accessibility affordances:** aria-hidden×1

**Legal / consent lines:**
- You’ve been unsubscribed
- Yes, unsubscribe me
- Open the most recent email from us and use the Unsubscribe link at the bottom —

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `StandCallNoticeTest::test_the_message_says_nothing_is_first_come` — And the message says the thing the page's whole promise rests on.
- `SupporterHonoursTest::test_nobody_is_congratulated_twice_however_often_promotion_reruns` — THE ONE THAT MATTERS.
- `SupporterHonoursTest::test_the_congratulation_carries_an_unsubscribe_link` — Every congratulation carries a way out — the header one and the visible one.
- `SupporterHonoursTest::test_a_thank_you_still_reaches_someone_who_unsubscribed` — A thank-you still reaches somebody who unsubscribed, and that is the opposite call on purpose.
- `VisitTrackerTest::test_admin_cron_and_asset_paths_are_not_arrivals` — The console, the cron and the assets are not people arriving.
