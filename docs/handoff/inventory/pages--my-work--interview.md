# `templates/pages/my-work/interview.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /my-work/{token:[a-f0-9]{32}} → MyWorkController::page`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── THE LIVE INTERVIEW ───────────────────────────────────────────────────────

   A conversation instead of a form, for the same person the form was built for: a teacher with
   an hour after school, on a phone, who has never used this site. Built to the mobile design at
   390px and allowed to grow from there — every value here traces to that handoff or to the
   site's own tokens.

   ── WHAT THE SCREEN HAS TO KEEP SAYING ──────────────────────────────────────

     • NOTHING IS SENT until they press the button with their own name typed. Said on the
       welcome screen, in the header, and again on review.
     • THE AI NEVER WRITES THEIR ANSWERS. Every machine-derived value on this page is labelled
       as such and carries the quote it came from.
     • PROGRESS IS APPROXIMATE AND SAYS SO. The rail comes from the outcome ledger, so it can
…
```

**Headings:** Show them the work. · Read it before it goes. · This has been sent. · Nothing you said is lost. · What's left · Your words

**Data read (top-level variables/functions):** `form`, `token`, `say_max`, `iv`, `notice`, `error`, `deadline`, `voice`

**Forms:**
- `POST /my-work/{{ token }}/interview/switch` fields: _token[hidden]; buttons: Fill in the form instead
- `POST /my-work/{{ token }}/interview/switch` fields: _token[hidden]; buttons: Use the form instead
- `POST /my-work/{{ token }}` fields: _token[hidden], action[hidden], declared_name[text required,maxlength,autocomplete]; buttons: Send this to the judges
- `POST /my-work/{{ token }}/interview/switch` fields: _token[hidden]; buttons: Carry on in the form

**States / branches (5 distinct conditions):** `notice` · `error` · `deadline` · `voice` · `iv.closing`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `ivApp()`; fetches: `/my-work/`

**Accessibility affordances:** aria-hidden×14, aria-label×7, aria-labelledby×3, aria-pressed×2, aria-modal×2, aria-live×1, aria-atomic×1; roles: group, dialog; visually-hidden text×4; <label for>×8; tabindex×3; autocomplete×1; prefers-reduced-motion×1

**Legal / consent lines:**
- * a retried turn and a refresh can never disagree about what has been recorded.

**Styling carried:** 1 <style> block(s), 54 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
