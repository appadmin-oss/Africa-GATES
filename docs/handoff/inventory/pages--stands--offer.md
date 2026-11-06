# `templates/pages/stands/offer.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /stand/{token:[a-f0-9]{48}} → StandOfferController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  ── ONE VENDOR, ONE OFFER, THREE THINGS TO DO ────────────────────────────────

  Read the terms, accept the pitch, pay the fee. In that order, and the page will
  not let them happen in any other — a Pay button on an unaccepted offer is a
  charge for a place the trader has not agreed to take.

  Reached by a token in an email. The reader is a market trader on a phone with a
  two-day clock running, so everything that matters is above the fold: what the
  stand is, what it costs, when the offer runs out, and the one button.
```

**Headings:** That link is not working · {% if is_accepted %}Your stand at {{ event.title }}{% else %}You have been offer · The pitch · Before you accept · Pay for your stand

**Data read (top-level variables/functions):** `owing`, `event`, `org`, `expires_at`, `type`, `is_accepted`, `is_offer`, `expired`, `error`, `terms_slug`, `app`, `place`, `confirmed`, `agreed_at`, `stand`, `outstanding`, `fee`, `below`, `full`, `your`, `dead`, `can_pay`

**Forms:**
- `POST /stand/{{ app.access_token }}/accept` fields: _token[hidden], agree_terms[checkbox required]; buttons: Accept this stand
- `POST /stand/{{ app.access_token }}/pay` fields: _token[hidden]; buttons: Pay ₦{{ owing.due|number_format }} now

**States / branches (13 distinct conditions):** `dead` · `is_accepted` · `is_offer and not expired` · `is_offer` · `expired` · `event.event_date` · `type` · `type.includes_power` · `owing.fee > 0` · `error` · `owing.settled` · `agreed_at` · `can_pay`

**Links out:** `/org` · `/{{ terms_slug }}`

**JS behaviours:** data hooks: `data-page`

**Accessibility affordances:** roles: alert, status

**Legal / consent lines:**
- .so-terms{ margin-top:18px; padding-top:16px; border-top:1px solid rgba(16,41,44,.1); }
- The place below is held for you. Read the trading terms, then accept it —
- The trading terms cover
- I have read the Vendor
- Trading Terms and the terms published with this call, and I agree to them.
- {% if agreed_at %}Terms agreed {{ agreed_at|slice(0,10) }}.{% endif %}

**Styling carried:** 1 <style> block(s), 5 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `FaviconTest::test_the_requests_the_states_describe_report_to_it` — The requests the states are about all report — the vote, the paid vote, points redeemed for a vote, a nomination, a gift, a ticket, a checkout and a stall fee.
