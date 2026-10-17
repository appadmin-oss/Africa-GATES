# `templates/pages/claim-dispute.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /claim/dispute/{token:[a-f0-9]{32}} → ClaimController::disputePage`; `POST /claim/dispute/{token:[a-f0-9]{32}} → ClaimController::disputeFreeze`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── "THIS WAS NOT ME" ────────────────────────────────────────────────────────

   Reached only from the link in a claim notification. Three states:

     unknown token  →  the link is dead or already used; say so kindly and name a person
     confirm        →  one button, because the GET must not be the action (mail scanners
                       fetch links before a human sees them — see ClaimDispute)
     done           →  what happened and what happens next

   The tone matters more than usual here. The commonest person on this page is not a
   victim of an attack: it is a parent who did not know their child had claimed, or a
   nominee whose nominator claimed on their behalf meaning well. So nothing here accuses
   anybody, and freezing is described as pausing, which is what it is.

…
```

**Headings:** {{ done.code == 'FROZEN' ? 'Done — the claim is paused' : (done.ok ? 'Already pa · This link is no longer active · This claim is already paused · Pause the claim{% if claim.nominee %} on {{ claim.nominee }}’s page{% endif %}?

**Data read (top-level variables/functions):** `claim`, `done`, `support_email`, `paused`, `the`, `could`, `do`, `that`, `token`

**Forms:**
- `POST /claim/dispute/{{ token }}` [novalidate] fields: _token[hidden], note[textarea maxlength]; buttons: Pause this claim

**States / branches (8 distinct conditions):** `done` · `done.ok` · `done.reference|default('')` · `claim is null` · `claim.already` · `claim.nominee` · `claim.reference` · `claim.activated_at`

**Links out:** `mailto:{{ support_email }}`

**Accessibility affordances:** aria-hidden×1; <label for>×1

**Styling carried:** 1 <style> block(s), 1 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `ClaimSecurityTest::test_a_dead_link_explains_itself_rather_than_404ing` — An unknown token gets a kind page and the support address, never a 404. — *a 404 would confirm to anybody enumerating which tokens are real*
- `ClaimSecurityTest::test_fetching_the_link_does_not_freeze_anything` — THE MAIL-SCANNER TRAP. — *a GET froze the claim — every mail scanner on earth will now do this for real*
