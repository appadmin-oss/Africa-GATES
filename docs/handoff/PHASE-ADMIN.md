# Admin console — stage 1: tokens, shell, navigation and permissions, Home, overlays (4 Oct 2026)

Built from `design_handoff_admin_console` (README, `Admin Console v9.dc.html`, screenshots) under the
owner's decisions of 4 Oct 2026 (GAPS §8d). Destroy-then-rebuild: every replaced file was inventoried
first in `docs/handoff/inventory/_admin.md` (every rule marked MUST RESTORE, with where it lives now),
then deleted and written again from the DC. Stages 2–3 (other agents) build the purpose-built screens,
the standard list component's 21 pages and the org console on top of this shell.

## 1. Files

| File | What |
|---|---|
| `src/Support/Accent.php` | **Console set added** (`console()`, `consoleShadows()`, `consoleWords()`, `consoleReported()`, `consoleAccepted()`, `consoleHex()`); `css('console')` emits `--cn-*` only. Public palette untouched. |
| `public/assets/css/console/tokens.css` | New. Sizes (the console ladder, rem named by px), radii, shell measures, motion (0 under reduced motion), stacking. No colour. |
| `public/assets/css/console/console.css` | New. Base, a11y layer, shell, sidebar, top bar, page header, states, components, Home, list+detail, overlays, read-only, phone (≤768) and forced-colours/contrast rules, and a delimited block drawing unrebuilt bodies (D-12). |
| `public/assets/css/admin.css` | **Destroyed** (the `--ad-*` palette and old components). |
| `templates/admin/layout.twig` | **Destroyed and rebuilt** (README §2, §7). |
| `templates/admin/dashboard.twig` | **Destroyed and rebuilt** as Home (README §3). |
| `templates/admin/alerts.twig` | New — Alerts, stage-1 scope (README §4.3 list + detail; see D-9). |
| `templates/admin/partials/nav-icons.twig` | **Destroyed and rebuilt** (stroke 1.7, shell glyphs, every old symbol kept). |
| `templates/admin/partials/cmdk.twig`, `copilot.twig` | **Destroyed** → palette and assistant drawer in the layout + `admin.js`. |
| `public/assets/js/admin.js` | **Destroyed and rebuilt**: shell (sidebar toggle/overlay/fold, account menu, pins with Undo, palette, drawer, confirm-with-reason, toast, read-only refusal) + every page behaviour carried over (inventory). |
| `src/Admin/Support/AdminNav.php` | **Rebuilt**: groups by task, per-page gate read from the guard, children ("also here"), off-rail pages, `current()`, `related()`, `destinations()`. |
| `src/Admin/Support/ConsoleShell.php` | New — `console_shell()`: everything the shell draws for the request. |
| `src/Admin/Support/Permissions.php` | `health` key; `SUBPATH_SECTIONS` (settings/providers, settings/mail → health); `alerts`, `me` mapped; `WRITERS`, `canWrite()`, `canOpen()`. |
| `src/Admin/Middleware/AdminAuthMiddleware.php` | Writer allowlist from `Permissions::WRITERS`; read-only roles may POST `/admin/me/…` and `/admin/assistant/chat` only. |
| `src/Admin/Services/ConsoleAlerts.php` | New — alerts derived on read (mail, cron, migrations, chargebacks, schema, payment callbacks, failed messages, failed webhooks); the pill. |
| `src/Admin/Services/HomeBoard.php` | New — tiles, board (six handoff sources, then the old board's jobs), quiet numbers, rail counts. |
| `src/Admin/Services/ConsolePins.php`, `src/Admin/Controllers/ConsoleMeController.php` | New — pins and sidebar state per admin, audited (`console.pin`, `console.unpin`). |
| `src/Admin/Controllers/AlertsController.php` | New. |
| `src/Admin/Controllers/DashboardController.php` | `index()` renders Home from `HomeBoard`; the daily stalled-schedule mail kept. |
| `src/Admin/Controllers/HandbookController.php`, `templates/admin/handbook.twig` | Areas looped from `AdminNav::groups()` with each page's gate (still from code). |
| `src/Admin/Services/AuditService.php` | `record()` attaches the confirm dialog's `_reason`. |
| `database/migrations/2027_03_01_admin_console_pins.php` | `gates_admin_pins` (UNIQUE admin_id+href via `SchemaIndex::ensure`), `gates_admin_prefs`. BIGINT UNSIGNED admin ids, VARCHAR(255) href (utf8mb4 index width), no raw `CREATE INDEX IF NOT EXISTS`, no FK. |
| `src/routes.php` | Health group (reads only) split out of the superadmin `/settings` group; `/alerts`; `/me/pins`, `/me/pins/{id}/unpin`, `/me/sidebar`. |
| `config/container.php` | `admin_nav` global → `AdminNav::forRole()`; `console_shell` Twig function. |
| 67 templates under `templates/admin/` | `--ad-*` reads renamed to `--cn-*` (825 reads, table in the inventory; fallbacks dropped). 14 `topbar_title`s renamed so the page agrees with its new rail label (`AdminIaTest`). No other edit. |
| Tests | New: `ConsolePaletteTest`, `ConsoleTypeTest` (+ `Tests\Support\ConsoleSurface`), `ConsoleShellTest`. Rebuilt: `AdminNavTest`, `AdminNavRenderTest`. Edited to the new rule: `AdminIaTest`, `HandbookTest`, `AdminContrastTest`, `AdminClassCoverageTest`, `MailHealthTest`, `DeadTokenTest`, `ColourLiteralTest`, `Tests\Support\PublicSurface`. |

## 2. Decisions recorded

- **Tokens (§1a)** — (A) monochrome, DM Sans and JetBrains Mono (owner). One colour file: `Accent`'s console set.
- **Console type (owner asked me to decide):** the console follows **its own** ladder (README §10: 11 · 11.5 · 12 · 12.5 · 13 · 13.5 · 14 · 15 · 17 · 18 · 22 · 24 · 26 · 30, plus 10.5 and 16 that the HTML draws in the shell), written in rem named by px; never vw/clamp; **no capitals**; **mono only for figures, references, times and keycaps** (class-segment naming rule). Held by `ConsoleTypeTest` over the rebuilt files only (a page joins `ConsoleSurface::REBUILT` when its stage rebuilds it). The public guards keep their scope; the two surfaces never overlap (tested). **Flag for GAPS §8d.**
- **Word contrast (reported, not edited):** `grey-450` on the sidebar 3.10:1 (group labels); `grey-500` on `fill-selected` 4.35:1 (focused row second line); `grey-500` on `fill-current` 4.04:1 (count beside the current nav item); `warning-dot` 2.95:1 (a waiting count on a Home card, 22px/500 — not large text). Held exactly by `Accent::consoleReported()`.
- **`health` gate** — superadmin, admin, viewer: Integrations, Email health, Alerts. Only reads and read-only diagnostics moved (page GETs, `providers/run`, `mail/diagnose`); changing the transport, send rules, events token, suppressions and **sending a real test message** stay superadmin (their RoleMiddleware). Owner-approved widening.
- **Per-page gates read from the guard.** Discovered: the old rail typed gates per section while the guard read paths, and they disagreed on **15 pages** (unmapped → superadmin only): Handbook, Support tickets, Campaigns, Challenges, Payouts, Partner organisations, Vendor rules, Vote recovery, Integrity, Judging audit, Result release, Audit log, AI & interview bot. Those were bounced for every non-superadmin who clicked them. The new nav shows each only to whom the guard admits (superadmin). **Mapping any of them is an access change — open question 1.**
- **Viewer read-only** — writes at 45% + toast, never submitted (client), refused by `AdminAuthMiddleware` (server). A viewer may pin, toggle the sidebar and ask the assistant (these change nothing on the platform; the assistant was documented "every role" and the writer allowlist had silently refused viewers).
- **Confirm-with-reason** — a POSTing `data-confirm` form requires a reason (button disabled at 45% until non-space), sent as `_reason` and attached to the audit row by `AuditService::record()`; a link confirm has nowhere to carry a reason and asks without one. Per-action server-side *requirement* of a reason belongs to each stage-2/3 screen.
- **Alerts are derived on read** (no table yet) — open while their check fails; the three old banners (migrations, cron, mail) became HIGH alerts, so the red pill on every page carries their promise.

## 3. DC → screenshot map (`docs/handoff/shots/admin/stage-1/`)

All at 924×540 (the DC canvas), 1280×800, 1440×900 and 390×844, for superadmin, admin, moderator, editor, viewer (`{state}-{role}-{width}.png`): `home`, `palette` (empty: Recent first), `palette-typed` (2+ words → "Ask the assistant"), `drawer`, `confirm` (empty reason, button disabled), `confirm-reason`, `toast` (with Undo), `account` (real account menu), `sidebar-overlay` (390 only), `readonly-toast` (viewer). 50% overlays against the DC at 924×540: `overlay-home-924-50pct.png` (01-admin-1), `overlay-palette-…` (01-admin-5), `overlay-drawer-…` (02-admin-5), `overlay-confirm-…` (03-admin-5; the DC draws it over Payouts — mine over Home, same card). Measured on every capture: the top bar never overlaps itself, the title ellipsizes, no horizontal page scroll, no console errors, and at 390 no control in the bar or main under 44×44 (Playwright, all 20 combinations pass).

## 4. Deviations — what, why, approved by

| # | What | Why | Approved |
|---|---|---|---|
| D-1 | No scope switcher, Hosts page, host chips, "View as host" banner, "Scoped to a host" state | Hosts not built | **Awaiting owner** |
| D-2 | No console switcher ("Platform admin · Switch") | Consoles remain separate logins | **Awaiting owner** |
| D-3 | Account menu shows name, email, role + blurb, Handbook, Assistant, View the site, Sign out — no role preview | README: preview is prototype-only | README |
| D-4 | "Editions" omitted from Programmes | No route yet; the Editions screen (§4.6) is stage 2 | Stage 2 builds it |
| D-5 | "Review queue" → `/admin/nominations/review`, not `/admin/moderation` | In this codebase `/admin/moderation` is COMMUNITY moderation; the nomination review the DC draws is the review desk. Codebase wins on routing; community moderation is linked under Review queue | **Question for owner** |
| D-6 | Integrations stays at `/admin/settings/providers` (README says `/providers`) | Published URL; codebase wins on routing | README §0 |
| D-7 | Purpose lines that describe hosts trimmed: Support, Awards, Revenue, Payouts, Audit log | Hosts are not built; the sentence would describe nothing | **Awaiting owner** |
| D-8 | Off-rail pages are "children" in an "also here" pill row under the page header and in the palette | CLAUDE.md: a sub-page is linked from its parent; the DC has no sub-nav drawing | **Awaiting owner** |
| D-9 | Alerts: Open · High tabs, list, detail, recommended action. No Closed tab, Assign, Close-with-reason or timeline | Needs alert STATE (what "closed" means for a still-true fact) — stage 2 | Stage 2 |
| D-10 | Home board: the six DC sources, then the old board's other jobs (interviews overdue/today/no link, unpublished transcripts, questionnaires not sent/silent, community items held, support conversations, profiles) | Losing existing work-finding would drop features | **Awaiting owner** |
| D-11 | Quiet numbers: "of N ever" (DC "of N this edition") | Votes span editions; several can run at once | **Awaiting owner** |
| D-12 | Unrebuilt page bodies drawn with the new components under their old `ad-*` class names (delimited block in `console.css`) | README keeps eight pages' templates "inside the new shell"; raw HTML would be unusable. No template edited for it; delete page by page | **Awaiting owner** |
| D-13 | Assistant drawer has a typed-question box | The DC's drawer has none, so a follow-up would be impossible | **Awaiting owner** |
| D-14 | Palette: no "people", no "Preview as" roles, no host rows; filter rows only where a real filter exists (waiting longest, paid with no votes, high alerts) | No people search in code; preview is prototype-only; no "flagged" filter exists | **Awaiting owner** |
| D-15 | Assistant drawer subtitle "Reads this console as {Role}" (no "· {scope}") | No scopes | D-1 |
| D-16 | Home "N things need a person" title is singular-aware ("1 thing needs a person") | Grammar | **Awaiting owner** |
| D-17 | Toast, palette and drawer at 390 take the full width / 44px targets | Rule 12 | README |
| D-18 | Removed from Home (inventory): integrity briefing + AI button, distributions, 14-day chart, top nominees, last 12 audit rows, "View the site" button (now in the account menu) | Not on the handoff's Home | **Awaiting owner — where do they go?** |

## 5. Guards proven failing first

`AdminNavTest`: an access change (`support` mapped to `moderation`) → fails the boundary diff; the rail un-filtered → fails "no surface offers…"; `canOpen` letting an unmapped path through → fails the guard comparison. `ConsoleShellTest`: the read-only exemption removed → fails; the `_reason` attachment removed → fails; pins not re-checked on read → fails; Home not filtered → fails (and `AdminNavTest`). `ConsoleTypeTest`: a px size, a mono label and uppercase planted in `console.css` → fail; its own planted detector test runs every time. `ColourLiteralTest`/`ConsolePaletteTest`: a hex planted in `console.css` → fail. `ConsolePaletteTest` fails while the reported list and the measured failures differ in either direction.

## 6. Open questions — for the owner, not guessed

1. **Access gaps the old rail hid** (15 pages above): map any of them — Handbook to `overview` (CLAUDE.md says every role should read it), Support tickets / Campaigns to `moderation`, Payouts / Partner organisations / Vendor rules / Vote recovery to `finance`, Integrity / Judging audit / Result release / Audit log to `data`, Challenges to `programmes`, AI & interview bot to `configuration`? Each is an access widening.
2. D-5: should "Review queue" mean nominations (as built) — and where should community moderation live?
3. `health` writes: should an **admin** be able to send a real test SMS, switch the mail transport, or lift a suppression? (Kept superadmin.)
4. Legal's purpose line says "acceptances are counted" — GAPS §3.2 found acceptance is never recorded. Keep the sentence?
5. The four reported contrast failures (§2).
6. D-1, D-2, D-7–D-16, D-18 as listed.
7. The review desk 302s to Nominations when the queue is empty, so "Review queue" then lights Nominations. Acceptable, or an empty state on the desk (stage 2)?

8. Seen while capturing (not changed — the mail page is stage 2's): on a box with no mail login, the Email
   health page reads "Email is working" (it counts sends) while `MailHealth::banner()` — and so the HIGH alert —
   says email is not set up (`readonly-toast-viewer-924.png`). Two answers to one question on one screen pair.

## 7. Stage 2/3 work this leaves (pages render inside the new shell, bodies not rebuilt)

Every page except Home and Alerts: their bodies are the destroyed design's markup drawn by D-12. Purpose-built (§4): Review queue, Payment issues, Alerts (state), Integrations, Email health, Editions, Audit log, People & roles. List component (§5): the 21 pages. Undesigned, kept in the shell: Media, Shop, Legacy vault, Awards page, Vendor rules, Site & keys, AI & interview bot, Test data, and every form/detail page. Each joins `ConsoleSurface::REBUILT` when rebuilt, and deletes its `ad-*` rules from the D-12 block.

## 8. Tests

Narrow filter: 1,744 tests, 1 failure (`PasskeyTest`, PHP 8.3). **Full suite (once): 6,731 tests, 10 failures** — 8 `PasskeyTest` (PHP 8.4 needed; environmental); `DeployedEndpointTest` (the deploy-check page's Home marker, mine — fixed and re-run green); `DevUiTest::test_components_css_holds_the_base_components_and_nothing_else` (`.ag-photo--cover` in `components.css`, a concurrent public-site agent's change, not this stage).

---

# AdminConsole v2 (`design_handoff_letter_email_admin`, README §10b) — 20 Oct 2026

Built in steps; each step is one commit.

## Step 1 — palette and faces
`Accent::CONSOLE` is the v2 set, faces are Geist and Geist Mono. Eight text pairs the design
draws under 4.5:1 are reported in `Accent::CONSOLE_REPORTED`, not re-valued.

## Step 2 — the shell and the rail
- **Rail groups** are the v2 job groups in `AdminNav::GROUPS`: Awards · Engage · Audience ·
  Money · Content · Organisation. Every page keeps its own gate (`sectionForPath`), so the
  regrouping changes no access; `AdminNavTest` still diffs every gate against the guard.
- **Groups** (`ConsoleShell::rail()`): the rail opens in full — every group starts open
  (owner, 20 Oct 2026, over README §10b's collapse-when-idle). A person may close any
  group; the choice is kept in `ac2.col` (declared in `CookieRegistry`) and never closes
  the group holding the current page. A closed group shows its summed count, red when one
  of them is urgent.
- **Counts** come from `HomeBoard::counts()` only: review, payments (urgent), alerts (urgent
  while one is high) and, new, moderation (held comments plus held threads — the moderation
  page's own two queues).
- **Page titles** were renamed to the rail's labels (`AdminIaTest`): Programmes, Nominations
  (the review screen), All nominations, Judges, Events, Broadcasts, Refunds, Finance,
  Products, Posts, Moderation, Team, Settings.
- **Handbook** moved from the account menu into Organisation. Its gate is unchanged (still
  unmapped, so superadmin only — open question 1 above still stands).
- **New menu** lists only real create flows the role can open. Invite judge is superadmin
  only because the judges routes carry a `RoleMiddleware` on top of the guard.
- **Phone** (<600): app bar, floating tab bar (Home · Review · Money · Alerts · More), and the
  rail itself as the More sheet. **Tablet** (600–1023): the 60px icon rail.

### Deviations
| What | Why |
|---|---|
| Workspace group (Inbox, Calendar), Check-in and Contacts are not in the rail | The pages do not exist yet (step 8). A rail item with no page is a dead link. |
| Refunds shows no count | Its queue is the same money as Payments' "refund owed" bucket; counting it twice would show one fact twice. |
| The alert pill says "N high alerts", not "N affecting production" | `ConsoleAlerts` has severity but no production-impact axis yet (step 5). Severity is not impact, so the impact wording waits for the data. |
| The bell lists alerts only, with no "Mark all read" | There is no notification store; the bell's only source is `ConsoleAlerts`. |
| New menu: "Release results", not "Schedule result release"; no "New giving campaign" | Results are released by an act on `/admin/result-release`, not scheduled; giving pages are created by the organisations themselves. |
| New broadcast and Upload media open their list pages | Both create forms live on those pages (the broadcast's is a POST from the list). |
| The desktop sidebar toggle is kept, before the crumb | An existing, persisted control (§10 says keep everything `console_shell()` provides). |
| Send feedback moved into the account menu; the top bar's Docs and Feedback buttons are gone | The v2 bar carries only crumb, signal, bell and New. |

## Step 3 — Home and Calendar
Each v2 screen is a NEW template (owner, 20 Oct 2026); the old one is left untouched.
- **Home** is `admin/home.twig` + `console/home.css`, fed by `Admin\Services\HomeBlocks`.
  `admin/dashboard.twig` is no longer rendered and is kept as it was.
  - The brief is up to three recorded facts, most serious first: a high alert, money owed,
    the review queue's oldest wait, a quorum shortfall, then votes against the day before.
    It is not an AI summary. "Ask a follow-up" hands those sentences to Ask.
  - Needs attention is `HomeBoard::board()` with an area per job (All · Awards · Money ·
    Content). Alerts and partner enquiries show under All only.
  - Editions are placed on the tracker by `CyclePolicy::stateFor()`, the computed phase
    and not the stored status. An edition leaves the list a month after its results.
  - Performance shows Votes, Nominations, Raised and Tickets over 7d, 30d or 90d, each
    compared with the window before. Raised is confirmed money not refunded.
  - System reads the status page's last snapshot (`SystemStatus::snapshot()`). If no
    check ran in the last 45 minutes it says there is no current reading.
  - Recent is changes only: sign-ins, sign-outs and views are left out.
- **Calendar** is `/admin/calendar`, under Home in the rail. It is gated `overview`, and
  every entry is filtered by the page it links to. It is fed by `Admin\Services\ConsoleCalendar`,
  which is also where Home's This week comes from. Dates are only the stored ones: cycle
  boundaries, event days and early-bird deadlines, and challenge open, close and draw.
- **List pattern**: `console/list.css`, the v2 list page. Calendar is its first user;
  step 4 extends it.

| Deviation | Why |
|---|---|
| No "Add date" or "Subscribe" on Calendar | Dates belong to the programme, event or challenge they are set on, and there is no calendar feed yet. |
| The side column sits beside the work from 1040px of content width | The design's 1200px is the whole canvas; its work column is about 1040px at that size. |
| No Payouts in This week | Payouts are not scheduled on this platform; they are paid when requested. |

## Step 4 — Alerts, and the console's own faces
- **Two axes.** Every alert carries `impact` (`affects` / `may` / none) beside its
  severity, plus `who` (the people it reaches) and `steps` (the fix in order). The top
  bar's pill now speaks impact: "N affecting production" (red, pulsing), "N may affect
  production" (amber), "N alerts" (grey), "All systems normal" (green). The bell opens
  with a Production block above the other alerts.
- **Alert state** (`2027_03_26_console_alert_state.php`, `Admin\Services\ConsoleAlertLog`):
  first seen, resolved, reopened count, owner, timeline. "Resolved" is written when a
  check passes, never by a button. A check that throws is unknown, never resolved. The log
  syncs on every Alerts view and every maintenance tick.
- **Page**: `admin/alerts/index.twig` + `console/alerts.css` (new; `admin/alerts.twig`
  is left as it was). Production banner, service strip (status-page components, 7 days
  in 12-hour blocks, a dashed gap where nothing was recorded), Open / Resolved · 7 days,
  severity and impact chips, source select, list and sticky detail with Copy diagnosis,
  Run all checks, Assign to me and the timeline.
- **Geist is self-hosted** (`public/assets/fonts/geist/`, variable files, SIL OFL), not
  loaded from Google Fonts. That also lets a render without network access be compared
  against the design's screenshots face for face.

| Deviation | Why |
|---|---|
| No "Post a status update" on the banner | The status page is computed from checks; there is no hand-written update to post. |
| The service strip's last line is "7 days", not a per-service note | Only the state is recorded per component, not a cause. |

### The rail is one level (owner, 20 Oct 2026)
Only a group (Awards, Engage, …) opens and closes. Pages are never nested in the rail: a
sub-page is on its parent page's "also here" strip and in ⌘K, and its rail item stays lit
while you are on it. `NavCoverageTest` still requires every rendered page to be in the
tree, so a page cannot drop out of both.

When a role may open a sub-page but not the item it sits under (an admin and Analytics,
All data, Legal or Operations scripts; a moderator and Event registrations), that page
stands in the rail as an item of its own, at the same single level. Without this it was
reachable from nowhere for that role. `NavCoverageTest` checks, role by role, that every
page the guard lets a role open is in its rail or on a strip, and in its ⌘K.

## Step 5 — Nomination review (screenshot 02)
- **Where**: `/admin/nominations/review` — the rail's "Nominations" — is the list now,
  `admin/nominations/queue.twig` (+ `_queue_peek.twig`, `_queue_filter.twig`,
  `console/review.css`, `js/review-queue.js`). The one-at-a-time desk moved to
  `/admin/nominations/desk` (a sub-page; `desk.twig` unchanged but for its title), and an
  old desk link carrying `?after=` is sent there. `/admin/nominations` is still the full
  record.
- **The data**: `Admin\Services\ReviewQueue`. To review · Asked (`needs_details`) ·
  Approved (`approved` and `verified`, the two spellings of a pass) · Rejected, counted
  inside the programme scope. The queue tabs are read whole (up to 2,000, said on screen
  past that) so quick views and facet counts are true of the queue, not of one page; the
  record tabs page in SQL. Counts and GROUP BYs carry no ORDER BY (MySQL error 1140 under
  ONLY_FULL_GROUP_BY; a test reads the query log for it).
- **The checks, each from the code that acts on it**: Duplicate? (the desk's own name key,
  `NominationTriageService::nameKey()`), No evidence (evidence rows plus the old
  `reference_url*` columns, one per address), Needs N more places (the Approve gate,
  batched as `AwardService::eligibilityMany()`), and the category line from
  `NominationCategoryFit::suggestions()` — the first reader that method family ever had.
- **The peek**: inline at ≥1360px (docked to the work surface, the page gives up the
  room), a floating drawer below, a bottom sheet on a phone. Every field, the per-category
  case, links and files, the checks spelled out, History (the audit log plus team notes),
  a note box (`gates_nomination_notes`), Reject with a reason, Full record, Approve. J/K or
  ↓/↑ step, Esc closes, A approves, R opens the rejection, D opens the desk.
- **Bulk**: Approve N goes through the single-approve action once per row (same gate, same
  emails, same audit rows); whatever is refused is named. Export (filtered list or the
  selection) carries no email or phone.
- **Presence** (`gates_admin_presence`): "N others here" and "… is viewing", from a
  45-second heartbeat. It only informs.
- **Saved views, density, columns**: `ac2.views`, `ac2.dens`, `ac2.hide`, declared in
  `CookieRegistry`.

| Deviation | Why |
|---|---|
| "Phone unverified" is not a check; "Needs N more places" takes its slot, only while the eligibility rule is on | Nothing verifies a nominator's phone, so the pill would be on every row. The eligibility rule is what actually stops an approval. |
| No "Ask for more" yet, in the peek or the bulk bar | It needs the message to the nominator and the way back in for them; it ships with the desk (step 6). |
| No bulk Reject | A rejection emails each nominator its reason at once and cannot be recalled; one reason typed for many is not a reason. It waits for the undo window. |
| Toasts are the console's own (`agToast`) | One toast system, not a second. |

## Duplicates and net counts — `/admin/nominees/duplicates` (21 Oct 2026)

A sub-page of Nominees (linked from it, not on the rail; `admin_page: nominees`, gate
unchanged — `moderation` via the `nominees` path segment). It answers one question the merge
scan and the totals had answered separately: **how many people** are in this edition, and how
many people voted.

**Files.** `Admin\Controllers\DuplicatesController`, `Admin\Services\{NomineePeople,
VoterPeople, IdentityVerdicts}`, `Support\EmailCanon`, `templates/admin/nominees/duplicates.twig`,
`public/assets/css/console/dupes.css`, migration `2027_03_28_identity_resolution.php`
(`gates_votes.voter_canon_hash`, `gates_identity_verdicts`), AI capability
`nominee.identity_verify`. Test: `tests/Unit/DuplicatesPeopleTest.php` (four mutations each
proven to fail it: no redirect, a committing preview, `take` ignored, the AI overruling a person).

**One matcher, not two.** `NomineePeople` calls `MergeSuggestionService::{nameKeys, pairScore,
explain, survivor}` — thin public faces on the scan's own private rule — and `VoterPeople`
calls `VoterReach::{personKey, buyerHashes}`, so the console's people and the CPI's people are
one definition.

**The merge, improved.**
- *Preview is the merge, rolled back* (`MergeService::preview()` = `mergeNominees(…, dryRun:
  true)`), so its totals cannot disagree with what Merge then does.
- *The survivor keeps what the admin picks*: `MergeService::TAKEABLE` (name, tagline,
  organisation, country, story, photo); default is the survivor's own value, else the first
  merged entry that has one. Every change is journalled; Undo restores it.
- *A merged entry's public link now goes to the survivor* (302, because a merge can be
  undone). It 404'd before — on posters and WhatsApp the day the votes were joined.
  `MergeService::survivorOf()` follows the chain; the redirect goes through the survivor's own
  lookup, so sandbox and status rules still hold (tested).
- *Imported tallies are named before they are lost.* The merge rebuilds totals from ballot
  rows (pre-existing behaviour); a tally imported without rows shrinks. The preview now says
  how many votes would not carry over. Whether a merge should instead ADD imported totals is a
  scoring question for the owner — not changed here.

**Open for the owner.** (1) Imported tallies on merge — keep the ledger rule, or carry the
imported difference? (2) Net voters (one inbox = one person) is shown, never scored; scoring it
would be a new, named basis.

## The console moves without reloading — `console-nav.js` (21 Oct 2026)

Owner: "make the admin a smart SPA, to improve the speed and the UX". Built as page swaps over
the server-rendered console rather than a client-rendered app: every page still renders on
the server and works with JavaScript off, and the guards on templates (CSP, Twig scope,
colour, type) keep applying because the HTML is the same HTML.

- Links and forms (GET and POST) inside `/admin` fetch the next page and swap only what
  changes; the shell, the open assistant and loaded scripts stay. Measured on the dev server:
  a swap is ~35% faster than a full load, and ~70–80% faster when the link was hovered first
  (prefetched, kept 20 s, cleared by any save).
- Back/Forward keep their scroll; a save that lands on its own page keeps your place;
  focus moves to the page and its title is announced; a 120 ms-delayed hairline shows a wait.
- Unsaved edits ask before leaving; a dropped connection on a save says so rather than
  guessing; a file answer to a POST is handed over as a download; a signed-out answer loads
  the sign-in page normally.
- Pages with their own scripts take part only when marked `data-nav="rerun"`. Since the
  follow-up the same day, EVERY console page does: the review desk, the single nomination
  review, Providers, multi-step forms, the event editor and its tickets were converted to
  `cnPage` (layout head) — document/window listeners, timers and the ticket scanner's camera
  are undone when the page goes. Verified: 26 of 26 rail pages and all six converted pages
  swap; the desk's A/R/S work on the desk and send nothing after leaving; the tickets page
  keeps its own focus in the code box. Two stale-token copies were fixed on the way (review,
  desk, Providers now read the CSRF meta when they send), and the ticket page's duplicate
  `submit-form` listener was removed — admin.js's now uses `requestSubmit()`, so the
  confirm, the one-press guard and the swap apply to a select that submits its form.
- Server: `ConsoleShell::prefetch()` — a prefetch is not a visit (Recent, Presence). A
  prefetched page that is then shown sends a `HEAD` so the visit is counted after all.
- Test: `ConsoleNavTest` (rerun sweep and shell marking each proven to fail). Verified in
  Chromium at 1280 and on an iPhone 13 profile: 24 of 26 rail pages swap, no console errors,
  no CSP violations.

## Step 8 — Contacts (26 Oct 2026)

`/admin/contacts`, in Audience above Broadcasts. Everybody who has voted, given, nominated,
held a ticket, bought from the shop, joined or subscribed, one row per person, joined on
`EmailCanon::hash()` — the key a vote now records as `voter_canon_hash` — so one inbox is
one row however it was typed. Built on `console/list.css` plus `console/contacts.css`.
Service `Admin\Services\Contacts`, test `ContactsTest` (each rule mutation-proven).

- **Gate: `finance`** (superadmin and admin). It totals each person's giving beside their
  address, which no `data` page does in one place. **Widening it to `data` is an access
  change — open question for the owner.**
- **Consent is the newsletter's own answer** (`NewsletterAudience::stateOf()`), so the page
  cannot call somebody reachable whom the newsletter's sender would refuse.
- **Given** is confirmed and not refunded. A phone number more than three people typed is a
  placeholder and shown for nobody (`VoterPeople::PHONE_CAP`).
- Voters from before addresses were matched are **counted, not listed**: their vote kept
  only a fingerprint of the address as typed, so nothing can join them to anything.
- Not cached: the cache table is plain text, and a cached list would be a second store of
  every address and phone outside the tables erasure knows about.

### Deviations

| Deviation | Why |
|---|---|
| No Import, no Export | An imported list carries no record of consent; an export is a copy of every address outside every table the erasure path knows. Both need the owner's decision. |
| "Took part in" names a voter's programme, never the nominee they voted for or nominated | A ballot is private, and nominees are not contacts (the CRM push's rule). |
| "Reachable by email" is "On the newsletter"; "Reachable on WhatsApp" is "With a phone number" | Only a confirmed subscriber receives a broadcast, and nothing records WhatsApp consent. A stat must say what it counts. |
| Consent words are the newsletter's six states, not "Opted in / Email only / Opted out" | "Email only" has no meaning the data can support; "Bounced" and "Complained" are evidence an operator needs. |
| Search and tabs are GET parameters, paged 50 at a time | A filter over drawn rows would search one page of tens of thousands. |

## The assistant, after the operations assistant the owner pointed at (26 Oct 2026)

`/admin/assistant` is an expanded three-pane view — conversations, the conversation, the
evidence — and the ⌘J drawer is the same component, compact, on the same conversation
(`public/assets/js/console-assistant.js`, `console/assistant.css`). Alpine and `ag-chat.js`
no longer drive it.

- **Conversations are kept on the server**, per administrator (`Admin\Services\AssistantThreads`,
  `2027_04_04_assistant_threads.php`): grouped Today / Yesterday / Past week / Past month,
  searchable by anything said in them, deletable, and pruned 90 days after they last move by
  the daily maintenance run. The model's history is the server's record, never what the
  browser sends back. The browser keeps only which conversation the tab is in
  (`ag-asst-thread`, declared in CookieRegistry).
- **Every answer shows its evidence**: the checks it ran as expandable steps with timings, a
  card per result, and the result itself as a table (or figures, or the report text) in the
  panel beside it. The agent's own output is unchanged; it was being thrown away.
- **Answers are formatted** by `Support\ChatMarkdown` — escaped first, then bold, code, lists
  and links to `/admin` pages or `https://` only.
- **The composer**: `@` tags a console page the role can open, `?` lists the shortcuts; the
  drawer sends the page under it as context. Both are checked server-side against
  `Permissions::canOpen()`, so a tag is a hint, never a way round the rail.
- **Rated**: helpful / not helpful per answer, by the person who asked; the 30-day totals are on
  Settings → AI. Copy on every answer.

Deviation: the reference's "Ask / mode" control and sliders button are not drawn — there is one
mode, and a control with nothing behind it is worse than none.
