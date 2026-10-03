# `templates/pages/error.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** layout/gates.twig · **Includes/imports:** partials/support-prompt.twig

**What the page says it is (its own header comment, abridged):**
```
    ── WHAT THIS USED TO SAY ──────────────────────────────────────────────────
    "Our team has been notified and is on it. Your votes and data are safe —
    please try again in a moment."

    Nobody is notified. The exception is appended to var/logs/error-detail.log,
    whose only reader is a diagnostics route an operator has to remember to open,
    on a host with no shell and no alerting. The sentence was false — and worse
    than false, because telling somebody their problem is already being handled
    is the most effective way to stop them reporting it. That is how a fault
    survives for months with nobody having said anything.

    What replaces it has to do two things the old copy did not: say plainly that
    this is not the reader's fault and nothing of theirs is lost, and give them
    something to DO. The reference below is that something — it is on the log
…
```

**Headings:** {{ heading }}

**Data read (top-level variables/functions):** `_p`, `dark`, `_g`, `to`, `code`, `message`, `_h`, `_m`, `has`, `_illo`, `you`, `heading`, `error_ref`, `the`, `home`, `on`, `light`, `page`, `your`, `continue`, `went`, `again`, `it`, `us`, `what`, `get`, `back`, `registry`, `plain`, `assets`, `img`, `illustrations`, `darkg`, `area`, `protected`, `need`, `be`, `signed`, `hold`, `right`, `permissions`, `view`, `this`, `email`, `shield`, `account`, `login`, `wrong`, `our`, `end`, `one`, `ours`, `anything`, `did`, `votes`, `entries`, `payments`, `are`, `unaffected`, `moment`, `happens`, `twice`, `tell`, `quote`, `reference`, `below`, `we`, `can`, `see`, `exactly`…

**States / branches (6 distinct conditions):** `code == 403` · `dark` · `code == 404` · `hasIllo` · `_pU == 'reload'` · `dark and error_ref is defined and error_ref`

**Links out:** `{{ _pU }}` · `{{ _gU }}`

**JS behaviours:** data hooks: `data-page`, `data-ag-do`; data-ag-do: reload

**Accessibility affordances:** aria-hidden×3; alt=×1

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
