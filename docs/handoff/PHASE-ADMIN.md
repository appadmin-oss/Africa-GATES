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
