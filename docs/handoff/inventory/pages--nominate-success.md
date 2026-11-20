# `templates/pages/nominate-success.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /nominate/success → closure routes.php:2152`
**Extends:** layout/shell.twig · **Includes/imports:** partials/site-header.twig, partials/app-bar.twig

**What the page says it is (its own header comment, abridged):**
```
  ══════════════════════════════════════════════════════════════════════════════
  NOMINATION SENT — THE MOMENT, AND THE THREE THINGS IT OWES
  docs/redesign-ref/Nominate Page.dc.html (the DONE state) · app-ux §24
  ══════════════════════════════════════════════════════════════════════════════

  This page was the last of the three nomination screens still on the legacy layout,
  with forty lines of inline `style`, a Lottie trophy, and `sc_confetti: false` — so
  the one screen in the flow that §24 makes a MUST was the one with nothing on it.

  ── WHY A NOMINATION CELEBRATES AT ALL, AND WHY NOT LIKE A WIN ──────────────

  §24's basis is the peak–end rule: what somebody remembers of a flow is its peak and
  its end, and this is both. Somebody has just spent ninety seconds writing about a
  person they admire, for no return to themselves.
…
```

**Headings:** {% if _nom %}{{ _nom }} has been put forward.{% else %}Your nomination is in.{%  · Rally more nominations for {{ _nom ?: 'them' }}

**Data read (top-level variables/functions):** `_nom`, `assets`, `_ref`, `share_payload`, `_cat`, `js`, `nominate`, `review_sla_hours`, `nominee`, `css`, `components`, `vendor`, `canvas`, `confetti`, `celebrate`, `share`, `category`, `ref`

**States / branches (5 distinct conditions):** `_nom` · `_cat` · `review_sla_hours` · `_ref` · `share_payload`

**Links out:** `#ns-share` · `/nominate` · `/leaderboard` · `/integrity` · `/philosophy`

**JS behaviours:** script `{{ asset('/assets/js/vendor/canvas-confetti-1.9.3.js') }}`; script `{{ asset('/assets/js/celebrate.js') }}`; script `{{ asset('/assets/js/nominate-share.js') }}`; data hooks: `data-celebrate`, `data-celebrate-kind`

**Accessibility affordances:** aria-hidden×1; roles: alert; <label for>×1

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: /assets/css/components/nominate.css

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
