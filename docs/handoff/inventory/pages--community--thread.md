# `templates/pages/community/thread.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /community/{slug} → CommunityController::threadShow`
**Extends:** layout/gates.twig · **Includes/imports:** partials/poll.twig

**What the page says it is (its own header comment, abridged):**
```
 ── Single community thread (community v2) ───────────────────────────
   Guests read everything; every write control either belongs to a member
   or is a clear sign-in CTA. Data: thread, replies, poll, member,
   member_logged_in, member_id, member_state, related.
```

**Headings:** {{ t.title }} · Reply to this thread · Join the conversation · {{ t.reply_count|default(tops|length) }} replies · No replies yet · About this thread · Related threads · Moderated space · Report to the moderators

**Data read (top-level variables/functions):** `login_url`, `member_logged_in`, `is_locked`, `diff`, `tops`, `ui`, `member_id`, `member`, `rl`, `dt`, `op_av`, `member_state`, `av`, `cav`, `kids`, `thread`, `ts`, `replies`, `related`, `signed`, `slug`, `locked`, `name`, `fff8df`, `effaf0`, `e9efef`, `f3eef7`, `fdeaf0`, `e6f0f4`, `account`, `login`, `next`, `community`, `poll`

**States / branches (17 distinct conditions):** `diff < 60` · `diff < 3600` · `diff < 86400` · `diff < 604800` · `n == ''` · `t.is_pinned or is_locked` · `t.is_pinned` · `is_locked` · `poll and not member_logged_in` · `member_logged_in` · `member_id > 0 and (t.author_user_id|default(0)) == member_id` · `tops is empty` · `not is_locked` · `member_id > 0 and (r.author_user_id|default(0)) == member_id` · `kids|length` · `member_id > 0 and (c.author_user_id|default(0)) == member_id` · `related is not empty`

**Links out:** `/community` · `{{ login_url }}` · `/community/{{ rl.slug }}` · `/integrity` · `toast.href`

**JS behaviours:** 1 inline <script> block(s); Alpine x-data: `cmThread({{ {
      signedIn: member_logged_in ? true : fals`, `{ on:false, n:{{ t.cheer_count|default(0) }}, busy:false }`, `{ on:{{ member_state.reposted|default(false) ? 'true' : 'fal`, `{ on:{{ member_state.bookmarked|default(false) ? 'true' : 'f`, `{ on:{{ member_state.following|default(false) ? 'true' : 'fa`, `{ confirm: false }`, `{ confirm: false, fading: false, hidden: false }`, `{ on:false, n:0, busy:false }`; data hooks: `data-page`

**Accessibility affordances:** aria-hidden×27, aria-label×22, aria-pressed×11, aria-labelledby×8, aria-current×1, aria-invalid×1, aria-modal×1; roles: status, alert, dialog; visually-hidden text×4; <label for>×2; prefers-reduced-motion×2

**Styling carried:** 1 <style> block(s), 34 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- `ColourBudgetTest::test_no_page_spends_more_colour_than_its_tier_allows` — (no docblock)
