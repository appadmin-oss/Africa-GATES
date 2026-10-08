# `templates/pages/nominee-confirm.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** none found in `src/` (see DESTROYED.md)
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
  THE ONE PLACE A NOMINEE IS ASKED ANYTHING — /n/confirm/{token}

  Somebody else has entered this person's name into a public award. This page is the
  only point at which they are asked whether that is all right, and whoever opens it is
  by definition not signed in and may never have heard of this platform.

  So three things are load-bearing:

  · BOTH ANSWERS ARE BUTTONS. "Yes" as a button and "No" as grey text is the pattern
    this codebase's cookie notice exists to forbid, and the stakes here are higher than
    analytics — it is whether a named person appears in a public award. The two carry
    an identical class string and differ only in their accent.

  · IT WORKS WITH NO JAVASCRIPT. Two plain POSTs. A privacy or consent control that
…
```

**Headings:** Did {{ nominator }} nominate you?

**Data read (top-level variables/functions):** `nominator`, `award`, `category`, `token`, `nominee`, `ttl_days`

**Forms:**
- `POST /n/confirm/{{ token }}` fields: _token[hidden]; buttons: Yes, that is me
- `POST /n/confirm/{{ token }}` fields: _token[hidden]; buttons: No, take it down

**States / branches (2 distinct conditions):** `award` · `category`

**Styling carried:** 0 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
