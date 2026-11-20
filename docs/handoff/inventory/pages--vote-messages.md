# `templates/pages/vote-messages.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/{program}/{slug:[0-9]+[^/]*}/messages → VoteMessageController::forNominee`
**Extends:** layout/gates.twig · **Includes/imports:** partials/vote-message.twig, partials/share.twig, partials/vote-message-assets.twig

**What the page says it is (its own header comment, abridged):**
```
 ── EVERY MESSAGE FOR ONE NOMINEE ────────────────────────────────────────────

   The ballot shows the newest few and links here. This page exists rather than a
   "load more" button because everything past the first page would otherwise be
   invisible to crawlers and to any reader without JavaScript, the wall would need a
   second renderer that will drift from the first, and "all the messages about me" —
   which is a thing a nominee sends to their family — would have no URL.

   Server-paginated, one renderer (partials/vote-message.twig, the same one the ballot
   uses), and shareable.
```

**Headings:** Messages for {{ nom.name }}

**Data read (top-level variables/functions):** `nom`, `page`, `total`, `pages`, `ballot_url`, `messages`

**States / branches (6 distinct conditions):** `nom.photo_path` · `total` · `messages|length` · `pages > 1` · `page > 1` · `page < pages`

**Links out:** `{{ ballot_url }}` · `?page={{ page - 1 }}` · `?page={{ page + 1 }}` · `/support`

**Accessibility affordances:** aria-hidden×1, aria-label×1; alt=×1

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route
