# `templates/pages/nominee-confirm-done.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /n/confirm/{token:[a-f0-9]{40}} (walk)`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  The answer, said back plainly — /n/confirm/{token} after a POST.

  A consent screen that answers "Thank you" and nothing else leaves somebody unsure
  whether anything happened. Each outcome says what is now true and what happens next,
  and the declined one says the nomination is gone rather than "noted".
```

**Headings:** {{ heading }}

**Data read (top-level variables/functions):** `outcome`, `heading`, `body`

**States / branches (2 distinct conditions):** `outcome == 'confirmed'` · `outcome == 'declined'`

**Links out:** `/`

**JS behaviours:** data hooks: `data-outcome`

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
