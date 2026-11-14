# `templates/pages/community/new-thread.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /community/new → CommunityController::threadNew`
**Extends:** layout/gates.twig · **Includes/imports:** —

**What the page says it is (its own header comment, abridged):**
```
 ── Thread composer (community v2) ───────────────────────────────────
   Members only — guests are redirected by the controller before render.
   Data: programmes (id,title), error (string|null). The regular form POST
   keeps the CSRF token; author identity is stamped server-side from the
   member's account, so there are no name/email fields.
```

**Headings:** Open a conversation. · New thread details

**Data read (top-level variables/functions):** `error`, `programmes`

**Forms:**
- `POST /community/new` [novalidate,x-data] fields: _token[hidden], programme_id[select], title[text required,maxlength], body[textarea required,minlength], poll_question[text maxlength], poll_options[][text maxlength], poll_multi[checkbox]; buttons: + Add option | 2" @click="pollN--">− Remove last | Publish thread

**States / branches (1 distinct conditions):** `error`

**Links out:** `/community`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `cmCompose()`; data hooks: `data-page`

**Accessibility affordances:** aria-invalid×5, aria-hidden×4, aria-describedby×2, aria-labelledby×1; roles: alert; visually-hidden text×2; <label for>×5

**Styling carried:** 1 <style> block(s), 8 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
