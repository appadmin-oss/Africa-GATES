# `templates/pages/donate-success.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /giving/success → DonationController::success`
**Extends:** layout/gates.twig · **Includes/imports:** partials/success.twig

**What the page says it is (its own header comment, abridged):**
```
  THE RECEIPT PAGE.

  ── A PROMISE THAT WAS NOT TRUE ─────────────────────────────────────────────
  This page used to close with "Make it recurring any time to compound the impact".
  There is no recurring donation anywhere in the platform — not a route, not a service,
  not a column — and the form a donor had just used says "a one-time donation" on it.
  Telling somebody a feature exists at the moment they are most inclined to use it is
  the kind of copy that costs the trust the rest of this flow is built to earn.

  What replaced it is three things that actually happen, each of which is checkable
  from somewhere else on the site.

  ── AND THE NOUN ────────────────────────────────────────────────────────────
  "Donation", to match the page the donor came from, the navigation and the footer.
```

**Data read (top-level variables/functions):** `confirmed`

**States / branches (1 distinct conditions):** `confirmed`

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
