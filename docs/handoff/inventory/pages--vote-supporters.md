# `templates/pages/vote-supporters.twig` — feature inventory (pre-patch, `a9d963a^`)

**Routes / renderers:** `GET /vote/{program}/{slug:[0-9]+[^/]*}/supporters → VoteMessageController::supporters`
**Extends:** layout/gates.twig · **Includes/imports:** partials/share.twig

**What the page says it is (its own header comment, abridged):**
```
 ── EVERYONE WHO ASKED TO BE NAMED ───────────────────────────────────────────

   The ballot names the ten biggest backers and then said "and 40 more", which led
   nowhere. Every one of these people ticked a box asking to be named in public;
   naming ten and counting the rest is the one outcome that consent did not promise.

   Two rules this page keeps, both inherited from SupportersService:

   · No numbers. What each person contributed decides the ORDER and is never printed.
     Publishing it turns a thank-you into a table of who spent most, discloses one
     person's spending to every reader, and invites exactly the escalation this
     platform should not encourage.

   · Nobody appears here who did not ask to. The private majority is acknowledged in
…
```

**Headings:** Supporters of {{ nom.name }}

**Data read (top-level variables/functions):** `nom`, `total`, `page`, `pages`, `backer_count`, `ballot_url`, `supporters`, `has`, `have`, `capped`

**States / branches (8 distinct conditions):** `nom.photo_path` · `total` · `supporters|length` · `pages > 1` · `page > 1` · `page < pages` · `backer_count > total` · `capped`

**Links out:** `{{ ballot_url }}` · `?page={{ page - 1 }}` · `?page={{ page + 1 }}` · `/privacy#supporters`

**JS behaviours:** data hooks: `data-ag-cascade`

**Accessibility affordances:** aria-hidden×2, aria-label×1; alt=×1

**Legal / consent lines:**
- How we handle voter names{% if capped %} · This page lists the most recent contributions; on a very large rally the oldest may not be shown{% endif %}.

**Styling carried:** 1 <style> block(s), 0 inline style attributes; stylesheet links: —

**Guard tests that read this page (at the time of the destroy):**
- none found by path or route

## Rules held by guard tests destroyed with this page (3 Oct 2026)

Each line is a test method that was deleted because it rendered or read a destroyed file (or passed vacuously once the file was gone), or a sweep entry that was edited out. **The rebuild re-asserts every one of these** — rewritten against the new markup, and watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS").

- `NomineeStoryTest::test_a_voter_who_did_not_consent_is_never_listed` — A voter who did not consent is on neither list, on either page.
- `NomineeStoryTest::test_every_named_supporter_is_reachable` — "and 40 more" used to lead nowhere.
- `NomineeStoryTest::test_the_supporters_page_never_prints_what_anyone_gave` — And the rule that governs the whole feature holds on the new page too: what each person contributed decides the order and is never printed. — *the supporters list did not render*
