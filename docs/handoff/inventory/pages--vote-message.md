# `templates/pages/vote-message.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /m/{token:[A-Za-z0-9_-]{16,32}} → VoteMessageController::permalink`
**Extends:** layout/gates.twig · **Includes/imports:** partials/share.twig, partials/vote-message.twig, partials/vote-message-assets.twig

**What the page says it is (its own header comment, abridged):**
```
 ── ONE MESSAGE, ON ITS OWN PAGE ─────────────────────────────────────────────

   This page exists because of what a link preview does. A supporter who shares
   their message as a link to the ballot gets the ballot's card — "Vote for X" —
   the same card fifty other supporters get, with their own words nowhere in it.
   Here the words ARE the page, so the card carries them.

   And because the words are the page, the page has to earn the tap that follows:
   the CTA below the quote is the ballot, and the other messages under it are the
   argument for adding one.

   Rendered from data prepared by VoteMessageService::byToken(), which resolves the
   display name against the voter's consent BEFORE it reaches here — a template
   with the real name in scope is one edit away from printing it.
```

**Headings:** Back {{ msg.nominee_name }} too · What others are saying

**Data read (top-level variables/functions):** `msg`, `ballot_url`, `wall_total`, `wall`

**States / branches (5 distinct conditions):** `msg.nominee_photo` · `msg.when` · `ballot_url` · `wall` · `wall_total > (wall|length + 1) and ballot_url`

**Links out:** `/philosophy` · `{{ ballot_url }}` · `{{ ballot_url }}/messages` · `/support` · `/terms` · `/privacy`

**JS behaviours:** Alpine x-data: `vmItem('{{ msg.token|e('js') }}', {{ msg.cheers|default(0) }`; data hooks: `data-on`

**Accessibility affordances:** aria-hidden×2; alt=×1

**Legal / consent lines:**
- real people quickly. Read the terms and the privacy notice.

**Styling carried:** 1 <style> block(s), 5 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `VoteMessageTest::test_a_message_permalink_is_noindex_but_the_full_wall_is_not` — A message permalink is share bait, not search bait: one short quote surrounded by boilerplate, one per message.
- `VoteMessageTest::test_a_nominee_has_a_page_of_all_their_messages` — The full wall is a PAGE, not a "load more" button — so it has a URL a nominee can send to their family, it is visible to crawlers and to readers without JavaScript, and the item markup has exactly one renderer.
- `VoteMessageTest::test_the_messages_page_shows_only_what_was_approved` — Held and rejected messages are not on it, not counted, and not hinted at.
- `VoteMessageTest::test_the_permalink_advertises_the_messages_own_card_not_the_ballots` — THE CARD IS THE SHARE. — *a shared message previews with the ballot card, so the words never appear*
- `VoteMessageTest::test_the_permalink_page_puts_the_message_in_its_own_social_card` — the message is on the page but not in the preview card, which is the only reason the page exists
