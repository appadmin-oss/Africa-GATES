# Admin console — stage 1 feature inventory (taken before the destroy, 4 Oct 2026, at `1bb9538`)

Every admin file stage 1 replaces, what it did, and where each rule lives in the rebuild.
**MUST RESTORE** marks a behaviour, guard, permission, CSP pattern or audit write the rebuild owes;
`→` says where it now lives. Anything marked **OPEN** is not in the rebuild and is listed in
`docs/handoff/PHASE-ADMIN.md` for the owner.

Destroyed: `templates/admin/layout.twig`, `templates/admin/dashboard.twig`,
`templates/admin/partials/cmdk.twig`, `templates/admin/partials/copilot.twig`,
`templates/admin/partials/nav-icons.twig`, `public/assets/css/admin.css`,
`public/assets/js/admin.js`, and the old body of `src/Admin/Support/AdminNav.php`
(replaced in place — same class, new tree). Kept and NOT rebuilt (page-body support, not shell):
`partials/ui.twig` (table cell truncation + view modal), `partials/richtext.twig`,
`partials/ai-assist.twig`, `partials/form-steps.twig`, `partials/datefields.twig`, `partials/pager.twig`
— their `--ad-*` reads were renamed (below), nothing else.

---

## `templates/admin/layout.twig` (268 lines)

| Behaviour / rule | Status |
|---|---|
| `<meta name="csrf-token" content="{{ csrf_token }}">` read by `ag-chat.js` and page scripts | MUST RESTORE → kept |
| Skip link to `#adMain`, `<main tabindex="-1">` | MUST RESTORE → `#cnMain` |
| Icon sprite included once at the top | MUST RESTORE → `partials/nav-icons.twig` rebuilt (stroke 1.7, shell glyphs added) |
| ⌘K/Ctrl+K palette, `<dialog>`, generated from `admin_nav` (`AdminNavTest`) | MUST RESTORE → rebuilt palette, generated from `AdminNav::destinations()` via `console_shell()` |
| Rail from `AdminNav`, filtered per role, current page `aria-current="page"`, section with the current page open | MUST RESTORE → rail from `AdminNav::forRole()` (per PAGE, the guard's own `canOpen`); current by path; a folded group is forced open when the current page is past the fourth |
| Footer: name, email, role label, sign-out link | MUST RESTORE → account menu under the avatar (name, role + blurb, Handbook, Sign out) |
| Mobile toggle (`#adMobileToggle`, ≤880px, outside-click closes) | MUST RESTORE → sidebar toggle; ≤768px the sidebar is an overlay with a scrim, Escape and outside click close it |
| `topbar_eyebrow`, `topbar_title`, `topbar_actions` blocks (`AdminOneHeaderTest`) | MUST RESTORE → page header (`cn-head`); title also printed in the top bar per the HTML |
| Per-page `admin_help` tooltip text (24 pages) | Replaced by the handoff's purpose line (`AdminNav` `sub`, the HTML's `static SUBS`) |
| In-page sub-nav of the section's siblings | MUST RESTORE → "also here" strip of the rail item and its children (`AdminNav::related()`) — this is what keeps every off-rail page linked (`AdminIaTest`) |
| Banner: migrations pending, with the no-shell instruction | MUST RESTORE → HIGH alert `migrations` (`ConsoleAlerts`), red alert pill on every page, Home card |
| Banner: `cron_health()` stalled schedule | MUST RESTORE → HIGH alert `cron` |
| Banner: `mail_health()` email down, never sending anyone to `.env`, suppressed on the mail page | MUST RESTORE → HIGH alert `mail` (one reader of `MailHealth::banner()`; `MailHealthTest` re-pointed) |
| `flash_ok` / `flash_error` / `flash_notice` all rendered (`FlashKeyTest`) | MUST RESTORE → ok = toast (5.2 s, stays without JS); error = inline error card with a retry; notice = inline note |
| Copilot on every page except `/admin/assistant` | MUST RESTORE → assistant drawer (same `agChat`, same `/admin/assistant/chat`, same `ag-copilot` session key) |
| Script order: `ag-chat.js` BEFORE Alpine (else "agChat is not defined"); Popper before Tippy; `foot_scripts` block; `admin.js` last; `richtext`, `ui` partials included | MUST RESTORE → same order |
| `a11y.css` linked last (44px coarse targets, forced-colors, prefers-contrast, aria-invalid, 16px inputs) | MUST RESTORE → rebuilt inside `console/console.css`; `a11y.css` stays held for the judge layout |
| Fonts: Cormorant Garamond, Montserrat, Space Mono | Retired → DM Sans + JetBrains Mono (owner, GAPS §8d) |
| Inline `style=` on the role label (uppercase, `#7FC87C`) | Retired — no capitals, no literals |

## `templates/admin/dashboard.twig` (311 lines) + `DashboardController::index`

| Behaviour | Status |
|---|---|
| "What needs a person" board from `AttentionBoard::forRole()`, zero is never a card, no door the role cannot open | MUST RESTORE → `HomeBoard::board()` — the handoff's six sources first, then every AttentionBoard job except chargebacks (now a HIGH alert) |
| "Nothing is waiting" empty state | MUST RESTORE → "Nothing is waiting on you" (HTML copy) |
| Quiet numbers from `AttentionBoard::pulse()` | MUST RESTORE → `HomeBoard::quiet()`, six cells under the HTML's labels |
| Daily stalled-schedule e-mail from a page load (`CronHealth::claimAlert()`) | MUST RESTORE → kept in the controller, unchanged |
| "View the site" link to `/` | OPEN — not on the handoff's Home |
| Integrity briefing: deterministic signals, templated text, "AI briefing" button (`/admin/integrity-brief`) and chips linking `/admin/data/collusion`, `/admin/judges`, `/admin/nominations/review` | OPEN — not on the handoff's Home; the JSON route stays; owner to place it (Integrity screen, stage 2, is the obvious home). `/admin/data/collusion` stays linked from the integrity screen |
| Region and tier distributions, 14-day vote chart (Chart.js), top six nominees | OPEN — not on the handoff's Home; Analytics is their natural home (stage 3). The Chart.js include goes with the page; the handoff rule is charts through `Viz` with a table twin |
| Last twelve audit rows with named actor/target | OPEN — Audit log already holds them; not on Home |
| Greeting "Welcome back, {name}" | Retired — the HTML's heading is "What needs doing today?" |

## `templates/admin/partials/cmdk.twig` (palette JS, 83 lines)

| Behaviour | Status |
|---|---|
| Plain JS, works when the rest of the page broke | MUST RESTORE → in rebuilt `admin.js`, no framework |
| Substring match over page + section; ↑↓ wrap, Enter follows, Esc closes | MUST RESTORE |
| ⌘K on Mac, "Ctrl K" elsewhere | MUST RESTORE |
| "Nothing matches that." | Replaced by the HTML's "Nothing matches. Pages your role can't open aren't listed." |

## `templates/admin/partials/copilot.twig`

| Behaviour | Status |
|---|---|
| `agChat({storageKey:'ag-copilot', …, csrf})`, `role="log" aria-live="polite"`, error `role="alert"`, 2000-char cap, Enter sends | MUST RESTORE → assistant drawer |
| "Full view" link to `/admin/assistant` | MUST RESTORE → in the drawer header |
| `[x-cloak]{display:none}` style (old Alpine pages relied on it being on every page) | MUST RESTORE → `console.css` |

## `templates/admin/partials/nav-icons.twig`

| Rule | Status |
|---|---|
| One `<symbol id="ic-{page}">` per nav page; `AdminNavTest` requires every icon the nav names | MUST RESTORE → every `icon` named by `AdminNav` (and every legacy `#ic-*` a page body still references) |
| Stroke 2 | Retired → 1.7 (`console-icons.js`); shell glyphs `home`, `sidebar`, `search`, `bell`, `plus`, `external` added. `console-icons.js` is not shipped |

## `public/assets/css/admin.css` (700 lines) — the `--ad-*` palette and the components

| Rule | Status |
|---|---|
| `--ad-*` tokens (teal/green palette, 21 names, 953 reads in 69 files) | Destroyed. No aliases: every read renamed to a `--cn-*` console token (table below) |
| Border-box reset (`AdminClassCoverageTest`) | MUST RESTORE → `console.css` |
| `.sr-only`, `.skip-link`, reduced-motion safety net | MUST RESTORE |
| `ad-btn` min 34/28/24px (24×24 floor, WCAG 2.5.8) | MUST RESTORE → `cn-btn`, and the unrebuilt bodies' `ad-btn` |
| `.ad-table-wrap` scrolls (keyboard reachable via admin.js) | MUST RESTORE |
| Lining + tabular figures on numbers | MUST RESTORE |
| `tr.ad-cut` / `tr.ad-out` (shortlist cut line, dimmed below) | MUST RESTORE → unrebuilt-body block |
| `.nr-cat` nomination review category picker | MUST RESTORE → unrebuilt-body block |
| `.ad-auth*` login styles | Dead — no template used them (login uses `components/auth.css`) |
| Every other `ad-*` component (card, chip, tab, form, filters, flash, pager, stat, callout, grid, avatar, info) | Drawn by the rebuilt components under their old class names in a delimited "pages not yet rebuilt" block of `console.css` (deviation D-12) — deleted page by page as stages 2–3 rebuild them |

`--ad-*` → `--cn-*` rename (applied to every reader; fallbacks dropped, the token always exists now):

| Old | New |
|---|---|
| `--ad-text`, `--ad-ink` | `--cn-ink` |
| `--ad-text-soft` | `--cn-grey-600` |
| `--ad-text-mute` | `--cn-grey-500` |
| `--ad-line`, `--ad-border` | `--cn-line-card` |
| `--ad-line-soft` | `--cn-line-row` |
| `--ad-surface` | `--cn-surface` |
| `--ad-surface-2`, `--ad-bg`, `--ad-bg-soft` | `--cn-fill-panel` |
| `--ad-primary`, `--ad-accent` | `--cn-ink` (the primary is black in the handoff) |
| `--ad-primary-hover` | `--cn-grey-800` |
| `--ad-gold`, `--ad-warn` | `--cn-warning-dot` |
| `--ad-danger` | `--cn-danger` |
| `--ad-info` | `--cn-info` |
| `--ad-font-mono` | `--cn-mono` |
| `--ad-radius` | `--cn-r-12` |
| `--ad-side-w` | `--cn-side-w` |

## `public/assets/js/admin.js` (448 lines)

| Behaviour | Status |
|---|---|
| Mobile sidebar toggle + outside click | MUST RESTORE → rebuilt shell |
| `[data-file-zone]` click-to-pick, drag-drop, image previews | MUST RESTORE |
| `agConfirm(message, onYes, opts)` exported on `window` | MUST RESTORE → the confirm-with-reason dialog; `window.agConfirm` kept |
| `form[data-confirm]` submit → confirm → `requestSubmit(submitter)` (keeps `formaction`; `NestedFormTest`) | MUST RESTORE |
| `a[data-confirm]` / `button[data-confirm]` outside a confirming form → confirm → follow / `requestSubmit(el)` | MUST RESTORE |
| NProgress on submit when present | MUST RESTORE |
| `.ad-table-wrap` gets `tabindex=0`, `role=region`, a name, only while it overflows (ResizeObserver) — WCAG 2.1.1 | MUST RESTORE |
| `[data-ag-do="tier-colour"]` swatch from the option's `data-fill`/`data-edge`, repainted on Alpine re-render (MutationObserver) | MUST RESTORE (`EventTierColourFieldTest`) |
| `[data-ag-do="submit-form"]` select submits its form | MUST RESTORE |
| `[data-ag-do="stand-size"]` disables `[data-ag-size-custom]` unless "custom" | MUST RESTORE (`StandSurfacesTest`) |
| Door voice preview: `voice-try`, `voice-fill`, `voice-kind`, Enter-in-box previews not saves, same-origin audio URL, `/admin/settings/voice-preview` with `X-CSRF-Token` | MUST RESTORE verbatim (`DoorVoiceTierTest`, `DoorWelcomeTest`) |
| Tippy on `[data-tip]` | MUST RESTORE |

## Access and permissions (not files, but the same rebuild)

| Rule | Status |
|---|---|
| Every page keeps its gate (`AdminNavTest` against the recorded mapping) | MUST RESTORE → the recorded mapping is now the GUARD's (`Permissions::sectionForPath`), diffed page by page; only Integrations, Email health and Alerts change (`health`, owner-approved) |
| Writer allowlist `superadmin, admin, editor, moderator` | MUST RESTORE → `Permissions::WRITERS`, used by `AdminAuthMiddleware` and the read-only rendering |
| A role never offered a page it cannot open | MUST RESTORE → `Permissions::canOpen` everywhere |
| Audit: every write recorded with sentinel admin id | MUST RESTORE → plus `_reason` from the confirm dialog attached in `AuditService::record()` |
