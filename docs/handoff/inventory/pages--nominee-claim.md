# `templates/pages/nominee-claim.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /claim/{id:[0-9]+} → ClaimController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  The claim page. docs/CLAIM-FAIRNESS-AND-FRAUD.md §2 and §7.

  THE WORDING IS PART OF THE DESIGN, not decoration on top of it. §7.2 requires that a
  held claim reads as "we need one more thing", never as a refusal — so there is no
  "denied" state in this template at all, and the held panel is styled as information
  rather than as an error. §7.1 requires that nothing here can be paid for, and §7.5
  requires plain, translatable language, which is why every sentence is short and none of
  them contains a term of art.

  A channel that is not independent is still OFFERED, and labelled before it is chosen.
  Hiding it would leave Baba Sule — whose customer filled the form in with her own
  address — with no way through at all, and springing the hold on him afterwards would be
  worse than telling him now.
```

**Headings:** We could not find that page · This page has been claimed · We need to do this one with a person · Is this you, {{ nominee.name }}? · Enter your code · This page is yours ✓ · Nearly there

**Data read (top-level variables/functions):** `support_email`, `nominee`, `control`, `already`, `channels`, `email`, `address`, `phone`, `number`

**States / branches (8 distinct conditions):** `nominee is null` · `already` · `control.since` · `control and control.cooling_off` · `channels is empty` · `c.channel == 'email'` · `c.independent` · `nominee and not already and channels is not empty`

**Links out:** `/vote` · `mailto:{{ support_email }}` · `/vote/{{ nominee.id }}`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `claimFlow()`; data hooks: `data-page`

**Accessibility affordances:** aria-pressed×2, aria-label×1

**Styling carried:** 1 <style> block(s), 3 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `ClaimSecurityTest::test_after_the_window_it_does_not_promise_a_self_service_undo` — Once the window has passed, it must not still claim the claim is reversible for free. — *a person is still offered*
- `ClaimSecurityTest::test_an_already_claimed_page_says_the_claim_is_not_final_yet` — The "already claimed" page names the window, and points at the inbox that can end this without us. — *a claim inside its cooling-off period is reversible, and the page must say so*
- `ClaimSecurityTest::test_the_already_claimed_page_leaks_neither_the_token_nor_the_claimant` — And it leaks NEITHER the freeze token nor who claimed the page. — *the already-claimed block did not render, so the assertions below prove nothing*
