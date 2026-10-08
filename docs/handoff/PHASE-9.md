# Redesign Phase 9 — Documents, help, blog, status, support

`design_handoff_africa_gates/phases/PHASE-9-docs-help.md`; DCs `DocPage`, `HelpCentre`, `BlogPage`,
`StatusPageV2` (REFERENCE §8.14–§8.16). Plus, by the coordinator's scope additions: the error pages,
`/email/confirm`, `/email/unsubscribe`, `/newsletter`, `/support`, `/support/tickets`, and (owner, 8 Oct 2026)
the Help Centre article **"Why is voting paid?"**.

Evidence: `docs/handoff/shots/phase-9/<area>/` — `legal/`, `help/`, `blog/`, `status/`, `support/`,
`newsletter/`, `error/`. For every page: `<name>-390|834|1024|1440.png`; where a DC exists,
`dc-<name>-<w>.png` (the DC rendered at the same width) and `overlay-<name>-<w>.png` (build under the DC at
50%); `<name>-390-rtl.png` (Arabic, `dir="rtl"`) and `<name>-390-reduced.png` (prefers-reduced-motion).
Dev data: a scratch SQLite database seeded with a programme carrying two versions of its terms, a programme
with none, four posts and a draft, two registry profiles (one pending), a poll, 263 hourly status snapshots
with a two-day gap, a past outage and a problem still open, a planned-work window, and a status subscription.

## 9.1 Routes (all crawled on the seeded dev server)

| Route | Answer | Notes |
|---|---|---|
| `/privacy` `/terms` `/cookies` `/refunds` `/vendor-terms` `/legal/{slug}` | 200 | One template (`pages/legal.twig`) over `LegalDocument`; `…/download/txt|md` 200 for every one (refunds and vendor-terms had no download route — the toolbar 404'd; added) |
| `/cookies#choices`, `/cookies.txt` | 200 | Control card from `CookiePrefs` via `consent()`; cookie list from `LegalDocument::cookiesHtml()` |
| `/terms/{slug}` | 200 / 404 | Programme terms, every version listed (`AwardTerms`) |
| `/philosophy`, `/integrity` | 200 | `/about` → 301 `/philosophy` |
| `/help`, `/help/{slug}`, `/help/c/{cat}` | 200 | Unknown category → 302 `/help`; `/help?gee=support` opens Gee in place; `/support/assistant` → 301 there |
| `/blog`, `/blog/{slug}` | 200 / 404 | Draft → 404 |
| `/status`, `/status.json` | 200 | `POST /status/subscribe` 303; `/status/alerts/{token}/{confirm|stop}` 200, unknown token 404 |
| `/support`, `/support/tickets`, `/support/ticket/{token}` | 200 | Tickets page needs a session to list; the link page works without one |
| `/newsletter`, `/email/confirm`, `/email/unsubscribe` | 200 | Work with no script and no sign-in; unsubscribe is CSRF-exempt by design |
| any unknown path | 404 | `pages/error.twig`; 403/500/503 share it |

## 9.2 Files

**Rebuilt (destroyed 3 Oct; inventories `pages--legal*.md`, `--integrity`, `--philosophy`, `--help*`, `--blog*`,
`--status`, `--support*`, `--newsletter*`, `--error`)**: `templates/pages/legal.twig`, `integrity.twig`,
`philosophy.twig`, `programme-terms.twig`, `help.twig`, `help-article.twig`, `help-category.twig`,
`blog/index.twig`, `blog/post.twig`, `status.twig`, `support.twig`, `support-tickets.twig`,
`support-ticket-link.twig`, `newsletter/index.twig`, `newsletter/confirm.twig`, `email-unsubscribe.twig`,
`error.twig`; partials `article.twig` (the DocPage macros), `cookie-choices.twig`, `help.twig`, `poll.twig`,
`support-thread.twig`; CSS `components/doc.css`, `help.css`, `blog.css`, `poll.css`, `status.css`, `support.css`,
`newsletter.css`, `error.css`; JS `doc.js`, `help.js`, `blog.js`, `poll.js`, `status.js`, `support.js`, `tickets.js`.

**Created**: `src/Services/PlannedWork.php` (the one reader of the four `status_planned_*` settings, shown
from 7 days before until it ends), `src/Services/StatusAlert.php` (status updates: double opt-in, sweep claims
through `BroadcastLog` before it sends, stop link), `templates/pages/status-alert.twig`,
`database/migrations/2027_03_07_status_alerts.php` (+ both schema files; indexes via `SchemaIndex::ensure()`),
tests `StatusPageTest.php` (11), `Phase9PagesTest.php` (9); `HelpCentreLayoutTest` gained 2 and its PREVIEW
constant moved 5 → 3 (the DC draws three links per card).

**Changed (minimal, additive)**: `LegalDocument` (`sections()`, `contact()`, `readMinutes()`),
`SystemStatus` (component `group`; ongoing incidents carry `steps`; `payload()` carries `planned`),
`HelpController` (`PREVIEW` 3, `updated`), `BlogController`, `HelpCentre` (the owner's article, block kinds
`h`/`list`/`quote` + `kicker`), `ErrorHandler` (every Slim 4xx/5xx keeps its own code), `routes.php`
(legal/terms/integrity/status vars, status subscribe + alert routes, refunds/vendor-terms downloads),
`Maintenance` (`status-alerts` task), admin `SettingsController` + `settings.twig` (a "Planned work" card —
**reported for the admin-console agent**), `partials/gee.twig` + `gee.js` (`data-gee-default`, so a help page
opens Gee in support mode), `layout/shell.twig` (`csrf_meta`), `vote-nominee.twig` (one link: "Where the
money goes" → the new article), `MethodologyDocument`/`CommunityVotingPhilosophy` titles to sentence case.

## 9.3 Prop → screenshot map

| DC prop / state | Screenshot |
|---|---|
| DocPage `doc=integrity|terms|privacy|cookies|programme-terms|philosophy` | `legal/<doc>-*.png`, `dc-…`, `overlay-…` |
| DocPage contents sheet (<1024) | `legal/cookies-390-sheet.png`, `-jump.png` |
| DocPage table → cards on a phone | `legal/cookies-390-table.png`, `-834-table.png` |
| HelpCentre `view=index|search|article` | `help/index-*`, `help/search-*`, `help/article-*` |
| Help category (no DC) | `help/category-*` |
| Owner's article | `help/why-is-voting-paid-*` |
| BlogPage `view=index|post` | `blog/index-*`, `blog/post-*` |
| StatusPageV2 `scenario=down` (open problem, planned work, gap days) | `status/status-*`, `status-now-*`, `status-inc-*` |
| StatusPageV2 subscribe sheet / dialog | `status/status-sub-390.png`, `status-sub-1440.png` |
| Day square picked | `status/status-cells-390.png` |
| Alert confirm page | `status/alert-*` |
| Support, tickets, newsletter, confirm, unsubscribe, 404 (no DC) | `support/`, `newsletter/`, `error/` |

**Keyboard**: every control is a link, a button or a form field in DOM order; the contents sheet, the status
subscribe sheet and the help sheet open through `AGChrome.openSheet` (focus moves in, Tab is trapped, Esc and
Back close, focus returns to the trigger — verified on `/status`: focus lands on Close, `aria-expanded` flips,
Esc returns focus to the bell). The status day strip is one `role="img"` with the whole summary in its label;
its squares are pointer-only detail and are deliberately not tab stops. **Reduced motion**: the sheet slide,
reading-progress transition and smooth scrolling are off.

## 9.4 Deviations (what · why · approved by)

1. **Display sizes**: DocPage H1 46/48/52 → 32/40/44, StatusPageV2 30/38/44 kept (tokens exist) · §6.2 fixed
   sizes · REFERENCE.
2. **No mono / no capitals** where the DCs draw them (DocPage kickers, Status "LIVE CHECK", stamp line, group
   titles, "STILL") · §18.3–4, `MonoAndCaseTest` · owner 3 Oct.
3. **DocPage** "Previous version", "Applies to", "Results chain" rows not drawn — no data behind them; computed
   rows (read time, effective date, contact) instead · no invented facts · CLAUDE.md legal rule.
4. **Citation formats tablist kept** beyond the DC · inventory MUST RESTORE.
5. **Cookie card has an explicit Save** rather than instant toggles · WCAG 3.2.2 (change of context) and a
   form that posts without script · house rule.
6. **Help top answers** are tinted tiles, not four hues · `ColourBudgetTest` tier 1; the controller's top four
   kept over the DC's · data, not a typed list.
7. **Status**: history range toggle (14 / 90 days) not drawn — the log keeps 14 days; "Happening now" updates
   are the **measured** state changes, not typed narratives; the DC's pink live dot is the error red (tier 3:
   gold, green, error); step dots ink/grey as the DC, state carried in words; subscribe is **email only** (SMS
   and WhatsApp need their own number opt-in, none exists — same as AwardAlert) and only offered when mail can
   leave.
8. **Support**: the DC-less "Reach a person" page drops the response-time promise (nothing measures it).
9. **Blog**: the CTA buttons in the DC's footer band not drawn (no destination owned); reading progress and the
   audio bar are painted on the bar itself (`--p`), so colour never sits on an unnamed element.

## 9.5 Owner's article "Why is voting paid?" (8 Oct 2026) — owner-confirmable changes

Live at `/help/why-is-voting-paid` (category Voting; linked from `/integrity` §money, the ballot's "Add more
votes" box, and the related lists of "How do I vote, and is it free?" and "What do paid votes actually do?";
Gee finds it through `HelpCentre::search()`, which it reads — "why is voting paid" ranks it first).
Verbatim except:
1. **Price**: "₦100 or ₦200" → `₦{price}`, read from `PaidVoteService::pricePerVote()` (the one price the
   code charges; bundles are percentage discounts on that price, not separate prices). Confirm the wording if
   two prices were meant.
2. **Capitals**: the kicker "AFRICAN G8 COMMUNITY VOTING", the cycle "NOMINATE → VOTE → …" and the slogan
   "WE DON'T WAIT FOR SPONSORS…" are sentence case; the cycle and slogan render as Playfair pull quotes.
3. **"African G8"** appears nowhere in this codebase or its data (no programme, setting or document by that
   name). Kept verbatim; confirm whether it should be the platform's name (Africa GATES) or a programme's.
4. **Scoring rule**: nothing in the text says a paid vote counts as a supporter, so nothing was added. Two
   sentences for the owner to look at: "Vote for who you believe deserves to be recognized" sits in an article
   about paying, beside a platform where a free vote also exists — and the title "Why is voting paid?" reads as
   if all voting were paid, while `how-free-voting-works` says voting is free (true unless
   `paid_voting_disable_free` is set).
5. The six fund headings are Title Case as written (labels, not headings) — left as the owner wrote them.

## 9.6 Blocked questions

- **B-1 Status "By country"** — StatusPageV2 draws per-country SMS / mobile money health. Nothing on this
  platform checks either per country (the only country fact is a nominee's); drawing rows would be invented
  health. Needs a product decision and a real probe.
- **B-2 Status SMS/WhatsApp subscriptions** — need a number double opt-in that does not exist.
- **B-3** `vote-paid-success.twig` (rebuilt by the owner's other session) still links "Why voting works this
  way" to `/philosophy`; not edited under the owner's ruling. Pointing it at the new article is the owner's call.

## 9.7 Tests

New: `StatusPageTest` (11), `Phase9PagesTest` (9), `HelpCentreLayoutTest` +2. Each was seen failing against a
planted break: report_ok defaulting rows on (no-default rule), the sweep mailing unconfirmed rows, the
planned-work lead window removed, one-check incidents treated as news, the card preview widened by one, the
error reference's class dropped; the legal-download check found the refunds/vendor-terms 404 on its first run.
