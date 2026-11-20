# Redesign Phase 0 — the map before anything is destroyed

**Phase 0 of `design_handoff_africa_gates`, rebuilt from scratch on 2 Oct 2026 at `615ce92`.**
The previous `GAPS.md` (960 lines, commit `9c7e07b`) was deleted unread and every fact below was
re-derived from the code, because three later phases and a dozen unlabelled rebuilds have landed
on top of it since. No template, CSS or JS was changed by this phase. The only other file this
phase touches is `.claude/skills/app-ux-standards/SKILL.md` (item 1), which was deleted and
reinstalled from the bundle and is byte-identical to it, so git records no change.

Paths are relative to the repo root. `H/` is the handoff bundle. "MISSING" always names the
searches that came back empty — "I could not find it" and "it is not there" are different claims
and only the second is worth building on.

---

## 0. The standing principle, and what it can and cannot mean

> **"Do NOT EVER patch. Only DESTROY, then rebuild."** — the product owner, for this redesign.

This overrides every softer rule in the bundle about *method*: README rule 4 and REFERENCE §4.4
("reuse first … extend it; never fork it"), DESIGN-NOTES "small request = small edit, don't
redesign untouched parts", and HANDOFF §5's "light polish of the existing template". A page in
this redesign is never edited into the new design; its template, its page CSS and its page JS
are deleted, and the page is written again from its DC.

It does **not** override the rules about *what must survive*, because those are not about method:

| Destroyed and rebuilt | Never destroyed by a UI phase — and why |
|---|---|
| The page template, its `<style>` block, its page CSS file, its page JS | **Behaviour.** REFERENCE §0.5 "keep every feature they already have" becomes a **feature inventory** taken from the template *before* it is deleted, and ticked off against the rebuild. It is a checklist, not a constraint on method. |
| Shared chrome built by earlier redesign sessions (§7) | **Migrations and both schema files.** A migration that ran on production cannot be un-run; `MigrateCommand` records applied files and runs in filename order (CLAUDE.md, the stack section). Corrections are new repair migrations. |
| The guard tests tied to destroyed files — **rebuilt with their rule**, and each rebuilt guard is watched failing before it is trusted (CLAUDE.md, "Prove a new sweep FAILS") | **Retired-never-deleted records** — criteria, ballots, receipts, sealed standings (CLAUDE.md "Things that must stay true"). REFERENCE §4.9 agrees for evidence. |
| `docs/handoff/{ref,mine,shots}` screenshots of destroyed pages | **Domain services** — `NomineeScoringService`, `ResultRelease`, `ReleasedStanding`, `VoterReach`, `CycleMaterialiser`, mail, payments. A screen rebuild reads them; it does not rewrite them. |
| | **Published URLs.** A path that was a page and stops being one is a 301; a slug is kept when its content is retired (CLAUDE.md, the 301-vs-302 and help-centre sections). |
| | **The shared files other work also lives in** — `config/container.php`, `src/routes.php`, `src/Support/AssetBundle.php`, `src/Support/CookieRegistry.php`, `templates/layout/gates.twig`. Redesign commits interleave with unrelated fixes in them, so "destroy" there means rewriting the redesign's hunks deliberately, never reverting whole commits. |

Every later phase's PR states, per page: what was destroyed (paths), the feature inventory taken
from it, and where each feature lives in the rebuild.

---

## 0b. Progress log — what has been done (kept current; last updated 4 Oct 2026, Phase 4 Home and Discover)

Everything is on branch `claude/ai-assistance-judges-features-1ka4oz`. Detailed evidence per phase lives in
`PHASE-1.md`, `PHASE-2.md`, `PHASE-3.md`, `DESTROYED.md`, `inventory/` and `shots/`; this section is the index.

### Before the redesign (audit and fixes)
- Codebase audit, then fixes shipped in `895c22a` … `615ce92`: mail TLS advice, voting (sandbox is not a
  ballot; mint vs refund race; ballot gate before a vote code is mailed; refusal through `PublicFault`), money
  (receipts to every donor; refunds reverse what they earned), security and maintenance (redirects, reset
  links, single-use tokens, failures that report), judges (removal retires, recusal never erased).
- **Email on production — not a code fault.** The host intercepts outbound SMTP on 587 (certificate
  mismatch). Operator action: set Send by to Automatic and save a Brevo API key in `/admin/settings/mail`.

### Phase 0 — recon (`997964a`)
- This file, the route → DC map, the destroy list. Nothing deleted in Phase 0.

### Phase 1 — foundations (`a9d963a`)
- `Support\Translator` and the `|trans` filter (Q12). `Support\Accent` destroyed and rebuilt as the handoff's
  32 tokens + shadows, no aliases (Q1–Q3); colour guards rebuilt (`ColourLiteralTest`, `ColourFields`, …).
- `layout/shell.twig` and the base components.

### The destroy passes (`882d768`, `5b06988`, `c2cf053`)
- Owner: "Do NOT EVER patch. Only DESTROY, then rebuild." Every public page on `layout/gates.twig` (and that
  layout), their partials, page stylesheets and scripts were inventoried (`inventory/`) and deleted, then two
  orphan passes. Rules each page owes its rebuild are marked MUST RESTORE in the inventories.
- Held, not destroyed: the admin and judge consoles and their CSS (`admin.css`, `judge.css`, `main.css`,
  `aurora.css`, `components/auth.css`, `a11y.css`), `partials/viz.twig` (admin-only).

### Phase 2 — chrome (`5e915ac`, `60fba99`, `763f871`)
- Chrome rebuilt: shell, site header, app bar, tab bar, menu sheet, quick settings, Display & reading
  (`POST /account/display`, member persistence), search (`GET /search` JSON, palette), shortcuts, language
  prompt, flash. `SystemStatus::light()`.
- Type: `TypeScaleTest` (closed ladder, no vw/clamp) and `MonoAndCaseTest` (mono only for ref/code/stub, no
  capitals) over `Tests\Support\PublicSurface` (Q4, Q5). Screen type in **rem** via `tokens.css`
  `--ag-fs-*`, so 125/150% text size scales; pixel-identical at 100%.
- Door scanner **held** like admin. Seven email templates destroyed and rebuilt to the ladder with
  `Accent::mail()` colours. `share`/`poll` partials destroyed.
- Header touch targets as specified (40/34px), owner exception in `TargetSizeTest`; 44 elsewhere, 24 floor.
- Cookie consent (Q11): `ag_consent`, Essential + Preferences/Analytics/Marketing ("Not used"), "any no wins",
  GPC/DNT beats a stored yes, plain POST forms, `ag_privacy` carried over and retired, `CookieRegistry`
  rebuilt both directions, generated `/cookies` section, `2027_02_27_cookie_consent_policy_repair.php`.

### Phase 3 — Gee and celebrations (this commit)
- **Gee**: `gee.js` destroyed and rebuilt; `partials/gee.twig`, `components/gee.css`; mounted on every shell
  page except Pulse/support/no-chrome (Q19 applied from the spec, awaiting confirmation);
  `window.AGGee.open({mode,q})`; help-desk mode on the real `supportDesk()` store; `GET /api/support/desk`
  ("From your account"); work-card steps reported by `Services\SupportWork` from what
  `PaymentReconciler::reclaim()` actually did (no timers); screenshot attach validated on bytes (images, 5MB);
  `--ag-bottom-ui` clearance measured at 16px above every bottom bar; `/support/assistant` 301 →
  `/help?gee=support`. Two live bugs fixed: a confirmed payment whose votes could not be minted was told
  "confirmed" (now `MINT_REFUSED`); an upper-case reference missed its order on SQLite. The help-desk flow was
  recorded on the dev server with a stubbed gateway — **not staging**.
- **Celebrations**: `celebration.js`/`.css` byte-identical to the bundle; `partials/celebration.twig`,
  `Services\Celebration` (`refusal()` — no delayed, unreleased, held, sandbox or unconfirmed moment, and never
  the five §7.8 non-moments; `seenKey()`), nonced boot, dev showcase `/_dev/celebration`. **No page celebrates
  yet** — Phases 5, 7 and 8 wire the moments.
- Tests: `GeeTest` (27), `CelebrationTest` rebuilt (26); 59 mutations across both, all caught.

### Phase 4 — Home and the site footer (4 Oct 2026; Discover is the other agent's entry)
- `/` rebuilt on the shell from HomePageV3.dc.html + WeAreAfrica.dc.html (`PHASE-4.md`, "Home"): hero with a
  real decided award as its record card, four counted stats, the globe on GlobeBand's markers (own canvas
  projection, no vendored library; mouse and keyboard reach every marker), Happening now, one featured campaign,
  the Recognise band. Every figure from `Services\HomeFront` (memoised, computed phases, sandbox contained) or
  `GlobeBand`; no DC figure ships. `HomeController` and `docs/GLOBE-BAND.md` inventoried, destroyed, rewritten.
- **Not drawn, blocked:** "Who recognises" (§3.1) and the Wall (§3.5) — §8c item 14.
- **The site footer** (`partials/site-footer.twig`, on every shell page): the DC's columns plus every MUST
  RESTORE destination, `nations_live()`, Activity removed, Cookies reopens the choices — §8c item 10 closed.
- Tests: `HomePageTest` (13), `SiteFooterTest` (9), `GlobeBandTest` rebuilt (+9 band rules); 28 mutations caught.

### Phase 4 — Discover, and Activity retired into it (4 Oct 2026)
- `/discover` built on the shell from DiscoverPage.dc.html (`PHASE-4.md`, "Discover"): sticky search + filters,
  tabs All · **Live** · People · Organisations · Awards · Results · Events docking beside Filters on scroll (skill
  §6b), facets as a phone sheet / end panel, every row from an existing reader (`Services\Discover` composes
  `ActivityFeedService::timeline()`, `SearchLanding`, `PublicResults`, `ProgrammeHost`, `NationsLive`); the one
  new query is "most nominated this month". Works with no script (links and GET forms, upgraded in place).
- **`/activity` → 301 `/discover?tab=live`**, keeping `q` and `literal` only; `ActivityController` destroyed;
  `/discover` and `/discover?tab=live` in the sitemap; no nav links Activity (swept). activity.twig's rules kept:
  `aria-activedescendant` combobox (script-declared), the status line the only live region, real links with
  `<time datetime>`, "Understood as" / "Literal.", the partial-sources line (denominator now counted, not typed).
- Not drawn, blocked: three facets and Recognitions' source — §8c item 19.
- Tests: `DiscoverPageTest` (22, new), `FindBandTest` rewritten (1 → 8); 29 mutations caught.

### Menu sheet — rebuilt for the owner's request of 4 Oct 2026 (§8e)
- `menu-sheet.twig` destroyed and rebuilt; its code moved out of `chrome.js`/`chrome.css` into
  `js/menu-sheet.js` and `css/components/menu-sheet.css` (inventory `inventory/partials--menu-sheet.md`).
  Detents (opens content-aware — head, card and the four squares whole, 45–70% of the screen — drag up to full, drag/flick down to close, expand-first, same-gesture list
  hand-over, rubber band, scrim follows), grabber as a button; most-used tiles (`Services\MenuShortcuts`,
  frecency, member: `gates_users.menu_use_json` + `POST /account/menu-use` beacon; guest: `ag-menu-use`
  only with Preferences). 17 scenarios measured, research and open questions in `MENU-SHEET.md`.

### Admin console stage 1 — tokens, shell, navigation, Home, overlays (4 Oct 2026; `PHASE-ADMIN.md`)
- Console palette in `Support\Accent` (`--cn-*`, `css('console')`), console tokens/sheet; `admin.css`, the old layout,
  dashboard, palette, copilot and `admin.js` inventoried (`inventory/_admin.md`), destroyed and rebuilt from the DC.
  Per-page gates read from the guard; `health` = superadmin/admin/viewer for Integrations, Email health, Alerts;
  pins and sidebar per admin (migration `2027_03_01_admin_console_pins.php`); viewer read-only client- and
  server-side; confirm-with-reason → `_reason` on the audit row; alerts derived on read. **Found:** the old rail
  offered 15 pages the guard refused (PHASE-ADMIN.md §6 Q1). Hosts and the console switcher awaiting the owner.

### Phase 5 — awards and voting (5–8 Oct 2026; `PHASE-5.md`)
- `/awards` (index, one award with Overview · Award details · versioned Terms, coming soon with a double-opt-in
  alert), `/vote` hub → edition page → nominee + ballot → won state (`win` via `Celebration::refusal()`), the
  nominee's supporters / messages / message / flier pages, and `/winners`. Migrations: `edition_number`,
  `gates_award_terms` + acceptance, `gates_award_alerts`. No scoring or money rule changed. The results
  stylesheet/script and the paid-vote receipts are the owner's other session's (`1aa11db`, `d1b39d1`) and win.
- Fixed: `/results` printed "Decided" over a late, unannounced cycle. Open: Q9, Q10 (today's rules kept),
  ballot evidence naming, and `/claim/{id}` (500, owned by no phase).

### Phase 7 — Events index, detail and ticket (4–5 Oct 2026; `PHASE-7.md`, "Events")
- `/events` (upcoming soonest first, featured "Next up", coming soon among them, past in its own section, sandbox
  excluded), `/events/{slug}` in every state incl. **coming soon** (derived from the tiers' `sale_starts_at` by
  `Services\EventSales` — no second date column) with a double-opt-in "email me when tickets go on sale"
  (`gates_event_sale_alerts`, `EventSaleAlert`, maintenance sweep, SendPolicy), and the §8.11 ticket. Tier colours
  derived at read time (`EventTierTone::card()`, Q6). `partials/photo.twig` (Q8) used by events and home. New columns
  `livestream_url`, `recording_url`, `access_notes` + three admin fields. 38 tests, 24 mutations caught. Blocked:
  wallet passes (B1), Q17 (48 h kept), hosts' own ownership of events.

### Phase 9 — documents, help, blog, status, support, error pages (5–8 Oct 2026; `PHASE-9.md`)
- Legal pages, `/integrity`, `/philosophy`, programme terms on DocPage; Help Centre index/category/article with
  every "Ask Gee" opening the desk in place; blog index + post; `/status` on StatusPageV2 with the measured
  "Happening now" timeline, planned work (`PlannedWork`, set in `/admin/settings`) and double-opt-in status
  updates (`StatusAlert`, `gates_status_alerts`, maintenance sweep); `/support`, tickets, newsletter, the
  bulk-mail stop button and the 404/403/500/503 page with its reference. The owner's "Why is voting paid?"
  article (price from the setting; capitals and "African G8" flagged). Blocked: status by country (§3.14).

### Test status
- Full suite after Phase 3: **6,633 tests, 8 failures — all `PasskeyTest`**, which needs PHP 8.4 (this
  container runs 8.3; dependencies installed with `--ignore-platform-req=php`). Not a code fault.
- No MySQL parity run has been possible in these sessions.

### Known gaps in the meantime
- `/help` returns 500 until Phase 9 rebuilds it (the Gee 301 lands there).
- ~~No footer anywhere until Phase 4~~ — the footer is on every shell page since Phase 4 (Home).
- No translations written: every new string is `|trans`-ready with no catalogue entries.
- Public pages destroyed and not yet rebuilt are simply absent until their phase (4–9).

### Next
- Phase 4 (home and discover), then 5–10 per `phases/`. The admin/judge update is awaited from the owner.
- Open decisions for the owner: §8c.

---

## 1. Method

- **Routes were read from Slim, not parsed.** `CLAUDE.md` records a regex parser finding 563
  routes where the router held 755 (the API group is a closure mounted twice, and `$a` names three
  unrelated groups). A scratch script booted the container and `src/routes.php` the way
  `public/index.php` and `RouteTableIntegrityTest` do, and read `getRouteCollector()->getRoutes()`;
  closures were resolved with `ReflectionFunction`.
- **Every public GET was dispatched** through the real test harness (in-memory SQLite, all three
  schema files, every migration) with Twig's profiler recording the templates rendered. That is
  what separates a 301 from a 302 — a closure is never assumed to be a redirect.
- **Templates for parameterised routes** that 404 on an empty database (30 of 94) were found by
  following each handler's source by reflection to depth 3, and by hand for closures that delegate.
- **DC mapping** is from each `phases/PHASE-*.md` "→ template" arrow and each DC's props, checked
  against DC references already in template comments.
- Code facts were each opened and read; the load-bearing ones (the `lang_ask` fault, the `--ag-line`
  collision, the `/search` alias, the missing celebration files, `base/tokens.css` still linked by
  admin login, no Alpine in `shell.twig`) were re-checked by a second reader before this was written.

---

## 2. Stack facts (confirmed)

| Fact | Answer | Where |
|---|---|---|
| **CSP nonce** | `Csp::nonce()` (memoised, 16 random bytes) exposed as the Twig **global** `csp_nonce`; the header reads the same value | `src/Support/Csp.php:54-57`; `config/container.php:237`; `src/Middleware/SecurityHeadersMiddleware.php:140-144` |
| …inside macros | **Visible.** Twig 3.27.1 merges globals into every macro call (`vendor/twig/twig/src/Node/MacroNode.php:112`), verified by rendering one. The comment at `templates/partials/article.twig:17` saying macros cannot see it is true of template variables and false of globals; the explicit `nonce` argument is a convention | — |
| **CSP actually served** | **Two policies, and production gets the weaker one.** PHP: `script-src 'self' 'nonce-…' 'unsafe-eval'`, no `'unsafe-inline'`; `style-src-attr 'unsafe-inline'`. `public/.htaccess:141-142` replaces it with a static nonce-less policy carrying `'unsafe-inline' 'unsafe-eval'` because the host injects its own CSP; its own comment says the nonce policy "has never reached a browser on this host". Kept equal to `Csp::staticPolicy()` by `CspStaticFallbackTest` | `Csp.php:182-242, 291+`; `public/.htaccess:110-142` |
| **`asset()`** | Twig function → `Assets::url()`: appends `?v=<xxh3 of the file's bytes>`, memoised per request, fallback token for a missing file. Separately `css_bundle` (global) = `AssetBundle::url()`, one built bundle with a manifest; `null` → individual links in the same order | `container.php:470-473`; `src/Support/Assets.php:109-130`; `src/Support/AssetBundle.php:59-114`; `templates/layout/gates.twig:258-323` |
| **Alpine** | **3.13.5**, vendored, `defer`. It is why `'unsafe-eval'` stays. **`layout/shell.twig` does not load it**, so a page moved onto the shell loses every `x-data` component unless it brings Alpine | `public/assets/js/vendor/alpine-3.13.5.min.js`; `gates.twig:597`; `Csp.php:30-35` |
| **`article.twig` macros** | `styles()` :30 · `cite_meta(d)` :42 · `article_ld(d, nonce)` :64 · `masthead(d)` :88 · `tools(d)` :112 · `contents(groups, wide)` :177 · `blocks(list)` :202 · `next(d)` :230 · `cite(d)` :247 · `script(d, nonce)` :323. Imported by `legal.twig`, `integrity.twig`, `philosophy.twig` | `templates/partials/article.twig` |
| Other macro libraries | `partials/ui.twig` (pill, tile, avatar, row, cell, facts, meter, bar, empty, chips, head, steps, ticks, notice, faq, subnav), `partials/icons.twig`, `partials/field.twig`, `partials/viz.twig` | — |
| **`gee.js` entry points** | A classic IIFE binding fixed ids (`#gee`, `#geeFab`, `#geePanel`, …) in markup that is **inline in `layout/gates.twig:462-560`** — there is no `partials/gee.twig`. Globals: `window.openGee`, `closeGee`, `toggleGee` (`open()` takes no arguments). Talks to `POST /api/guide` and `/api/support/escalate`. Mobile breakpoint hard-coded at 560px, not 600. **No `window.AGGee` exists** | `public/assets/js/gee.js:13-26, 36, 332, 458, 544-546` |
| **`supportDesk()`** | **Not Gee's.** An Alpine component defined in a nonced inline script in the support page, posting to `/api/v1/support/chat` and `/escalate` | defined `templates/pages/support-assistant.twig:394-395`, used `:215` |
| Fonts | Google Fonts, `display=swap`, preconnect; allowed by both policies; nothing self-hosted. `gates.twig:174` loads Playfair 400–900, Source Serif 4, DM Sans 300–700, JetBrains Mono 400/500/700; `shell.twig:61-63` loads exactly the handoff set. **Atkinson Hyperlegible (the "easy read" option) is loaded nowhere** and silently falls back | `shell.css:112`; `partials/display-reading.twig:58` |
| Icons | `partials/icons.twig`: `sec(name, size, title)` (two-tone section icons) and `ui(name, size)` (24×24, stroke 1.9, round caps; `back`/`next` mirror in RTL). Admin has its own sprite. **Lucide 1.28.0 is also vendored** — a second icon set already in the tree, against REFERENCE §14 | `icons.twig:71, 167`; `public/assets/js/vendor/lucide-1.28.0.min.js` |
| Translation | **Built (Q12, answered by the owner: build it).** `Support\Translator` is the one resolver: the Twig filter `trans` (`{{ 'Back'\|trans }}`, `'Hello %name%'\|trans({'%name%': n})`) reads `Languages::current()` — the locale `LanguageMiddleware` settled from `?lang=` / `ag_lang` — and catalogues `resources/lang/{code}.php` keyed by the English source string. English has no catalogue (identity); a missing or empty entry renders the source; output is plain text, so autoescape still applies. Registered on the app environment and on every bare mail `Environment` through `Translator::register()`. The catalogues hold only the first-visit prompt's words today (moved out of `Support\Languages`); every rebuilt string is translatable from its first commit, and `TranslatorTest` fails on a catalogue entry nothing reads | `src/Support/Translator.php`; `resources/lang/`; `config/container.php` (after `lang_ask`); `tests/Unit/TranslatorTest.php` |
| Layouts | `layout/gates.twig` (85 public templates), `layout/shell.twig` (4: nominate ×3, dev-ui), `layout/account-auth.twig` (5), `admin/layout.twig` (97), `judge/layout.twig` (2) | — |
| CSS | Legacy top level (`main.css` 4,949 lines, `ui-overhaul.css`, `professional.css`, `redesign-2026.css`, `aurora.css`, `motion.css`, …); redesign layer `tokens.css` / `shell.css` / `components.css`; 24 sheets in `components/`. **`base/tokens.css` is orphaned but still linked by `templates/admin/login.twig:11`** and still declares the failing gold `#c9a24b` | `public/assets/css/` |
| Inline styling today | 924 `style="…"` attributes in 79 public templates (42 interpolate Twig); 89 `<style>` blocks in 81 templates; 3,137 literal hexes in 82 templates (baseline `tests/baselines/template-hex.json`) | — |

---

## 3. The gaps table

Expected status is quoted from `phases/PHASE-0-recon.md` §15. **Where "Code says" disagrees, the
code wins and the phase brief needs correcting.**

| # | Feature | Expected | **Code says** | Built by |
|---|---|---|---|---|
| 1 | Recognitions + verified issuers | NEW | **MISSING** | — |
| 2 | Award terms versioning + acceptance | NEW | **PARTIAL** — one unversioned column; acceptance never recorded | `2026_06_30_programme_terms.php` |
| 3 | Coming-soon awards + notify (double opt-in) | NEW | **MISSING** (newsletter double opt-in exists to reuse) | — |
| 4 | Overall edition winner + top 3 | NEW (compute) | **EXISTS** — compute and render. *Disagrees* | pre-redesign |
| 5 | Wall of recognition | NEW | **MISSING** | — |
| 6 | Search API + palette | NEW | **PARTIAL** — palette built; JSON lives at `/activity/search`; `GET /search` is a 301 to HTML. *Disagrees* | Phase 2 `4c5f489` |
| 7 | DisplayReading persistence + first-paint apply | NEW | **PARTIAL** — localStorage and first paint EXIST; the profile half is MISSING | Phase 2 `b18620b` |
| 8 | Quick settings sheet + first-visit language prompt | NEW | **EXISTS, with a live fault** in the prompt. *Disagrees* | Phase 2 `b18620b`, `ee7dce0` |
| 9 | Celebrations (partial + boot + seen keys) | NEW; JS/CSS supplied | **PARTIAL** — a different engine runs; the verbatim files are not in the repo | pre-redesign |
| 10 | Gee restyle + privacy note + `--ag-bottom-ui` | EXTEND `gee.js` | **PARTIAL** — none of the three asks done; still mounted on Pulse; absent from shell pages | Phase 3 (destroyed and rebuilt; §3.10) |
| 11 | Pulse post kinds + guest banner | EXTEND | **PARTIAL** — `post`/`result` only; 4 reactions exist; banner copy differs | pre-redesign |
| 12 | Event tier colours, glow, states, waitlist | EXTEND | **Mostly EXISTS** — the spec's stored colour columns conflict with CLAUDE.md | pre-redesign |
| 13 | Shop multi-select, load more, restock alerts, order page | EXTEND | **PARTIAL** — restock and order page exist; multi-select, load more, phone markup missing | pre-redesign |
| 14 | Status by country, maintenance, subscribe | NEW | **PARTIAL** — incident timeline exists; the three named features missing | pre-redesign |
| 15 | Two-link mega nav | EXTEND `nav.twig` | **EXISTS** in `partials/site-header.twig`; Explore has 7 items, not 6 | Phase 2 `ee7dce0` |
| 16 | Nominee race, ballot, winner state | EXISTS; restyle | **EXISTS** — ballot field rules differ from REFERENCE §12 | pre-redesign |

### 3.1 Recognitions + verified issuers — MISSING
- **Phase 4 (4 Oct 2026):** the homepage's "Who recognises" section is not drawn and its hero record card shows a
  published award winner instead of an issuer's recognition (`PHASE-4.md` B1, D3); §8c item 14.
- Searched `src/`, `database/`, `templates/`, `public/assets/js`, `config/` for `gates_recognitions`,
  `issuer_type`, `withdrawn_reason`, `commendation`, `issuer`, `recogni` as a table/service/kind:
  nothing but payout and judge-COI wording. No `CREATE TABLE` for recognitions or issuers in any
  schema file or migration. `SupporterHonours` / `pages/honour.twig` thank supporters — a different thing.
- Reusable: recipient `gates_profiles`; organisation/business issuers `gates_partner_orgs` (CAC-vetted
  via `vetted_by`/`vetted_at`, no `issuer_type`; `2026_09_18_partner_orgs.php:55`) — community and
  government issuers have no table; evidence `gates_nominee_evidence.verified_at`; the withdrawn-never-
  deleted, audited pattern of `gates_judge_coi.withdrawn_at` (`JudgeService.php:965-1012`).

### 3.2 Award terms versioning + acceptance — PARTIAL
- **Exists:** `gates_award_programmes.terms`, one MEDIUMTEXT per programme, no version
  (`2026_06_30_programme_terms.php:9-10`, `database/schema.sql:41`); written in admin
  (`ProgrammesController.php:75`); read by the award page's Terms tab (`AwardsController.php:57-58`,
  `awards/programme.twig:91-96`, `|nl2br`) and by `/terms/{slug}` (`routes.php:2664-2672`,
  `programme-terms.twig`, `|sanitize_html`) — **the same text through two different filters**.
- **Missing:** `gates_award_terms` (version, effective_at, changelog), `gates_award_terms_acceptance`,
  and any acceptance write (`terms_accept`, `accept_terms`, `agree_terms` hit only gated forms and stand offers).
- **Live faults the rebuild must not inherit:** the nominate form's required `consent` checkbox
  (`nominate-award.twig:445-449`) is **never read by the server** — `consent` appears nowhere in
  `NominationController`, `AwardService` or `NominationRules`. Its link `/awards/{slug}#terms` lands on
  the Overview tab (the view is `?tab=terms`, the heading id `h-terms`). Voting says "By voting you agree
  to the programme terms" (`vote-nominee.twig:1190`) and records nothing.

### 3.3 Coming-soon awards + notify — MISSING
- Searched `coming soon`, `notify-me`, `notify_me`, `view=soon`, `'soon'`, `email me`: the only notify
  route is the shop's (`routes.php:2279`). `awards/programme.twig:100-128` draws the edition card only
  `{% if a.cycle %}`; `AwardOverview::action()` returns `null` when no phase is open, so a dormant award
  offers nothing. `awards/index.twig:108` prints an "Opens soon" badge with no form.
- **Reuse, don't fork:** `/newsletter` + `/email/confirm` (`routes.php:3276-3279`),
  `NewsletterController.php:80-95`, `Newsletter\NewsletterAudience` over `gates_newsletter.confirmed_at`
  — the same table already holds stand-call "email me when it opens" rows, which `NewsletterAudience`
  correctly treats as not-subscriptions.

### 3.4 Overall edition winner + top 3 — EXISTS (table says NEW; the code wins)
- Compute: `ResultRelease::overall()` (`ResultRelease.php:687`); `PublicResults::overallFor()`
  (`PublicResults.php:395-425`) returns winner, runner_up, margin, dead_heat, provisional/held, `top4`,
  from the sealed published categories without re-scoring.
- Render: `results/edition.twig:255-318` (winner, then 2nd/3rd from `top4|slice(1,3)`, then the caveat);
  also `results/index.twig:391-394`, `results/hall.twig:521-575`. A running edition is
  `results/open.twig:181`; a late one `results/late.twig`.
- **Before rebuilding:** (1) **two resolvers for one headline** — the name is `e.top` (the category winner
  with the highest CPI, `PublicResults.php:514-519`) while the runners-up and margin come from `e.overall`;
  the rebuild draws the whole block from `e.overall`. (2) The overall order is **not sealed**
  (`'reconstructed' => true`, `PublicResults.php:424`). (3) The phase names `pages/results.twig`, which does
  not exist; the real set is `pages/results/{index,edition,show,hall,open,late}.twig`.

### 3.5 Wall of recognition — MISSING
- **Phase 4 (4 Oct 2026):** the homepage does not draw the Wall; blocked for the owner (§8c item 14, `PHASE-4.md` B2).
- Searched `wall`, `masonry`, `testimon`, `Who recognis`, `gates_testimonials` (excluding firewall/
  swallow/paywall) over `home.twig`, `HomeController`, `src/Services`: nothing; `gates_testimonials` is in
  no schema file or migration. The DC's cards need quote, name, role, photo or initials, tag (Winner,
  Finalist, Honoured, Issuer, Nominator) and tone.
- **Not the source:** `gates_vote_messages` (supporter messages, consented, used by the nominee page's
  roll of honour) and the OrgBrand `quotes` block are different consents for different uses.

### 3.6 Search API + palette — BUILT (Phase 2 `/search`; Phase 4 retired `/activity` into Discover's Live tab)
- **Phase 4 (4 Oct 2026):** `/activity` is a 301 to `/discover?tab=live`; the timeline is
  `ActivityFeedService::timeline()` — the palette's index — drawn by Discover. The text below is the Phase 0 record.
- Palette: `partials/site-search.twig` + `public/assets/js/ag-search.js` — scope chips, ≤6 per group,
  combobox ARIA, focus trap, `/` and Cmd-K; mounted once at `gates.twig:540`.
- JSON: `GET /activity/search?q=&scope=&literal=&limit=` (`routes.php:2149`,
  `ActivityController.php:68-110`), scope map `ActivityFeedService::SCOPES` (`:166-171`), 120 req/60 s.
- Gaps: **`GET /search` is not JSON** — `/search` and `/find` are 301 aliases to the HTML `/activity`
  (`routes.php:1916-1917`). The empty query shows the latest feed, not trending/open-now/coming-soon — a
  documented deviation (`ag-search.js:223-231`): there is no trending signal, and coming-soon awards (3.3)
  do not exist.

### 3.7 DisplayReading persistence + first-paint apply — PARTIAL
- Exists: store `public/assets/js/a11y.js` (`localStorage["ag-a11y"]`, fires `ag:a11y`); first paint
  `partials/a11y-head.twig` (inline, nonced; `ag-t125`, `ag-t150`, `ag-hc`, `ag-easy`, `ag-ls`, `ag-ul`,
  `ag-rm`, `ag-saver`), included by both layouts (`gates.twig:243`, `shell.twig:59`); UI
  `partials/display-reading.twig`; declared at `CookieRegistry.php:134`.
- **Missing — "and the profile when signed in":** `a11y.js` never fetches; `gates_users` has no
  preferences column; no route reads or writes the settings.

### 3.8 Quick settings + first-visit language prompt — EXISTS, with a live fault (table says NEW)
- Quick settings: `partials/quick-settings.twig`, mounted only by `shell.twig:103` because its one trigger
  is the shell app bar's avatar (`partials/app-bar.twig:91`) — so it works on four pages.
- Language prompt: `partials/lang-prompt.twig` (**destroyed 3 Oct 2026** with the second orphan wave —
  its only includer, `layout/nav.twig`, had gone; inventory in `inventory/_partials.md`), `chrome.js:298-317`
  (survives, now finds no prompt and returns), strings from `Support\Languages`, one cookie writer
  `LanguageMiddleware`.
- **FAULT:** `layout/nav.twig:27` includes the prompt **unconditionally**. The server gate `lang_ask()`
  is registered (`container.php:384`, `Languages::shouldAsk()`) and **no template calls it**
  (`grep -rn lang_ask templates`: nothing), while `chrome.js` says the block is present only when it was
  true. So a visitor who answered "Keep English" is asked again on every page; and on the ~180 `gates.twig`
  pages `bindLangAsk` returns early (no `.ag-main`), so it never hides on scroll. `LanguageTest` checks
  `shouldAsk()` in isolation and passes over it. This is a §17 "declared, no reader" fault and is listed
  for the Phase 2 rebuild, not patched here.
- **Since 3 Oct 2026** the prompt and `nav.twig` are both destroyed, so nothing asks at all; `lang_ask()`
  and `lang_prompts()` are registered with no template caller. The fault is the rebuild's not to carry: the
  rebuilt prompt is included only inside `{% if lang_ask() %}`.

### 3.9 Celebrations — BUILT in Phase 3 (3 Oct 2026); no page wires it yet
- **Shipped byte-identical** (`cmp` clean against `H/design/assets/celebration/*`): `public/assets/js/celebration.js`
  (sha256 `903d690b…5c7a145f3c8`) and `public/assets/css/components/celebration.css` (sha256 `1e00d2fb…b016eada06a`).
  `Tests\Support\VerbatimAssets` pins both by path and hash; `ColourLiteralTest` skips the sheet only while the hash
  holds. Q7 is **not answered**: every hex, px size, capital and word in them that disagrees with the house rules is
  listed in `PHASE-3.md`, "Celebrations" §5.
- Built around it: `partials/celebration.twig` (the DC's card; draws nothing unless `celebration_allowed()`),
  `components/celebration-card.css`, `celebration-boot.js`, `Services\Celebration` (the decision, the one seen-key
  resolver, the kind's palette family), `/_dev/celebration` (dev only). `ag-cel-` declared under Preferences in
  `CookieRegistry`; the boot hands the engine a key only with Preferences, so with it refused nothing is stored and
  the burst replays (Q-C1 in `PHASE-3.md`).
- **The refusal rules are restored as a decision, not by placement:** no celebration on a delayed, unreleased, held
  (no quorum or community dark), sandbox or someone-else's result, nor an unconfirmed payment, nor any of §7.8's
  never-celebrate moments — asked through `PublicResults` (the result pages' own gate). `CelebrationTest` rebuilt (26:
  22 new, the 4 `backedWinners()` kept); 21 mutations, 21 caught.
- **Who wires it:** `award_won`/`edition_won`/`vote_cast` Phase 5, `gift_confirmed`/`ticket_confirmed` Phase 7,
  `nomination_sent` Phase 8 (`PHASE-3.md`, "Celebrations" §2).
- Open for the owner: Q7, Q-C1 (play once vs consent), Q-C2 (four DC tints off §6.1), Q-C3 (≤12 visible exceeded by
  the verbatim win and desktop nominate — measured 26 and 16; DOM counts 39 and 15–17 as §9.3 states).

### 3.10 Gee — BUILT in Phase 3 (3 Oct 2026); was PARTIAL, none of the three asks done
**Built (Phase 3, `PHASE-3.md` "Gee"):** `gee.js` destroyed (inventory `_scripts.md`) and rebuilt with
`partials/gee.twig` and `components/gee.css` from `Gee.dc.html`; mounted by `layout/shell.twig` on every shell
page except Pulse (`gates_page` or `tab` = `pulse`), the support pages and a chromeless page. Clearance is
`bottom: calc(var(--ag-bottom-ui, 0px) + 16px + env(safe-area-inset-bottom))` and nothing else — measured
16px above the tab bar, the cookie notice and a sticky bar at 390/834/1024/1440; `shell.js` now measures how far
up the screen the bottom UI reaches (bars stack) and sees a bar mounted or removed later; the cookie notice
carries `data-bottom-ui`. The privacy note is dismissible, `ag-gee-privacy` is in `CookieRegistry`
(Preferences, kept only with Preferences). `window.AGGee.open({mode, q})`; `?gee=support` opens the desk on any
page; `/support/assistant` 301s to `/help?gee=support` keeping `q` (and `ref`/`topic`/`ask`). The help desk
(§8.22) is wired to the real support actions; the work card's steps are what the repair recorded
(`Services\SupportWork`, `PaymentReconciler::reclaim()` now reports `found`/`asked`); z-index 60 restored.
`/help` itself still 500s — `pages/help.twig` was destroyed and is Phase 9's.

*As it stood in Phase 0:*
- Exists: `gee.js` (649 lines), `components/gee.css` (303); mounted only in `gates.twig:448-470`;
  `shell.twig:94` has an empty `{% block gee %}`, so **shell pages have no Gee**. Two modes, label only.
- Missing: the privacy note (`privacy`, `ag-gee` not in `gee.js`; `ag-gee-privacy` not in
  `CookieRegistry`); `--ag-bottom-ui` clearance (`gee.css:19` `bottom:1.5rem`, `:237` a fixed phone offset —
  while `shell.js:94-123` already sets the variable); `window.AGGee.open({mode,q})`; the §8.22 help-desk UI
  (work card, quick fixes, "From your account", reference card, handoff card); z-index is 70 not 60.
- **Gee is on Pulse**, which the spec forbids: `gee_suppressed` (`gates.twig:462-464`) covers `community`
  for members, `support`, and `hide_chrome`; Pulse renders as `gates_page='pulse'` (`PulseController.php:198`).
- The live work card's three steps have nothing to bind to: `supportDesk()`'s `chat` is one request/response.

### 3.11 Pulse — PARTIAL
- Kinds: `'kind' => isset($results[$id]) ? 'result' : 'post'` (`PulseFeedService.php:181`) plus photo/video
  media. Missing: `recognition` (needs 3.1), `vote`, `give`.
- Reactions: the four exist (`CommunityService::REACTIONS`, `:142`).
- Guest banner exists with different copy (`pulse.twig:773-775`).

### 3.12 Event tiers — mostly EXISTS; the spec conflicts with CLAUDE.md
- Colour: one slot column `gates_event_tiers.colour` (`2026_09_17_tier_colour.php:42`), slots in
  `EventTierPalette.php:66-73`, resolved from `ticket_accent` by `EventTierTone::hues()` (`:230`, `hue`/fill
  + `edge`), passed as `tier_hues` (`EventsController.php:271`).
- Glow `.ed-fx__*` conic gradients (`events/detail.twig:405-505`) — not `@property --ev-a` over 3.2 s as §8.10 says.
- States: tier `open/sold_out/early/closed` (`detail.twig:1052-1086`); event ended (`:955`), `sales_closed` (`:958-965`).
- Waitlist: `EventWaitlist` (join, placeOf, length, promote, expireOffers), `POST /events/{slug}/waitlist`
  (`routes.php:2099`), rows in `gates_event_registrations` (`waitlisted`, `offer_expires_at`); "N waiting"
  (`detail.twig:1128`). **Hold is 48 h** (`EventWaitlist::OFFER_HOURS`, `:45`), the spec says 24 h. Off by default.

### 3.13 Shop — PARTIAL
- Exists: restock alerts (`gates_stock_alerts`, `2026_09_09_stock_alerts.php:36-67`; `StockAlert`;
  `POST /shop/{slug}/notify-me`, `GET /shop/back-in-stock/stop/{token}`, `routes.php:2277-2279`;
  `shop/alert-stopped.twig` — the spec's `sku`/`stopped_at` are `variant_id`/`cancelled_at`, so no new table);
  order page `GET /shop/order/{ref}` (`ShopCheckoutController::order()`, `:501-520`; noindex; states
  pending→delivered/cancelled, `shop/order.twig:57-112`); region/currency cookies declared.
- Missing: **multi-select filters** — `ShopController.php:96` reads `c` as one string, so the spec's
  `?c[]=` becomes the string `"Array"`, a warning, and zero products (same cast on `q`, `sort`, `min`/`max`);
  **load more** (numbered pager, `ShopCatalogue::PER_PAGE = 12`); phone markup; the order page's 4-step
  progress. `order.twig:90` prints a raw `paid_at` rather than `DisplayTime`.

### 3.14 Status — PARTIAL
- Exists: `/status` (`routes.php:3053-3076`) on `SystemStatus::report()`/`timeline()`, incidents from
  `gates_status_log`, `/status.json`.
- Missing: country dimension (no `country`/`geo` in `SystemStatus`, no probe table); maintenance windows
  (`maintenance_window`, `maintenance_mode`, `planned maintenance`, `gates_maintenance` — nothing; "Maintenance"
  here is the cron orchestrator); subscribe. `status.twig:26` forbids a subscribe with nothing behind it, so it
  ships only with `NewsletterAudience` / `SendPolicy` / `BroadcastLog` behind it and gated on a working sender.

### 3.15 Mega nav — EXISTS (Phase 2, `ee7dce0`)
- In `partials/site-header.twig` (not `nav.twig`), included by `layout/nav.twig:21`. Participate: Nominate,
  Vote, Awards, Giving, Register a profile, Integrity Center (`:85-91`). Explore: Discover, Pulse, Events,
  Legacy Vault, Blog, **Results**, Status (`:93-100`) — 7 against 6; Results was added so decided awards stay
  reachable (`PublicResultsTest`). Discover currently 302s to `/registry`.

### 3.16 Nominee page — EXISTS; ballot rules differ
- `pages/vote-nominee.twig` (1,652 lines): race `.vn-race*`; won state "Winner"/"Runner-up" + "{name} won."
  (`:777-805`) then the consented roll of honour; points redeem for members (`:973-985`; the signed-out
  "Have points?" line is missing).
- **Ballot fields vs REFERENCE §12 ("name, phone, email and message required"):**

| Ballot | Name | Phone | Email | Message | Lines |
|---|---|---|---|---|---|
| Free | collected | collected | collected | optional, behind a toggle | `:1201-1240` |
| Paid | "(optional)" | none | required | "(optional)" | `:1073, :1111, :1132` |

  The paid name field **is** the display-name consent ("THE FIELD IS THE CONSENT", `:1076-1088`).
  Making it required changes what consent means — blocked question Q9.

---

## 4. REFERENCE §11 data model, and the two redirects

| Item | Status | Evidence |
|---|---|---|
| `gates_award_cycles.edition_number` | **MISSING** | Cycles have `year` + free-text `edition_label` (`schema.sql:48`); `AwardService.php:70` counts editions with `COUNT(*)`. Only a seed docblock mentions the name |
| `gates_award_terms` + acceptance | **MISSING** | §3.2 |
| `gates_recognitions` + issuers | **MISSING** | §3.1 |
| `gates_testimonials` | **MISSING** | §3.5 |
| Blog `read_minutes`, `people` | **MISSING** | `gates_posts` has slug, title, excerpt, body, cover_image, audio_path, author, tag, status, timestamps. `read_minutes` exists only for help articles (computed, `HelpController.php:219`) |
| Event tier colour columns (`accent`, `accent_light`, `wash`, `deep`, `glow`) | **Deliberately not columns** | §3.12 and conflict C9 |
| Event waitlist | **EXISTS, different shape** | rows in `gates_event_registrations`, `offer_expires_at`, 48 h |
| Maintenance windows · status probes by country | **MISSING** | §3.14 |
| Shop restock alerts | **EXISTS** | §3.13 |

**`/activity` → 301 `/discover?tab=live` (keep `q`, `literal`) — BUILT in Phase 4 (4 Oct 2026)**, one route,
no twin; `curl` proof in `shots/phase-4/discover/curl-activity-301.txt`. Phase 0 record follows. `/activity`
is a live page (`routes.php:2148`, `pages/activity.twig`) and `/discover` is a deliberate 302 to `/registry`
(`routes.php:1851-1853`) with no Discover template. Moving with the redirect: the `/search` and `/find` aliases
(they would chain), the footer's "Activity search" (`layout/footer.twig:31`), the GET forms in
`site-search.twig:46,115` and `find-band.twig:64`, the links in `activity.twig:85,89`; `/activity/search` (the
palette's JSON) stays or moves deliberately; `/discover?tab=live` joins `SitemapService` (`/activity` was
never in it).

**`/support/assistant` → 301 `/help?gee=support` (keep `q`) — MISSING, and order matters.** The route still
renders `SupportController::page` (`routes.php:3120`); nothing reads `gee=support` (`HelpController`,
`help.twig`, `help-article.twig`); `window.AGGee` does not exist. Build `AGGee.open({mode:'support', q})` and
the desk first, or the 301 lands people on a Help Centre that cannot open it and the ticket link, the
no-provider fallback and "forget reference" are lost with the page. The bundle's `snippets/php/redirects.php`
registers both paths a second time beside the live routes — `RouteTableIntegrityTest` fails that (the second
handler is dead and its middleware never runs); the destroy replaces the route, it never adds a twin.

---

## 5. Route → DC map

**Counts (from the live router).** 846 verb-path pairs (GET 422, POST 422, OPTIONS 1, DELETE 1 —
`RouteTableIntegrityTest`'s docblock still says 755). Excluded GETs: `/admin` 137, `/api` 32, `/judge` 7,
`/__setup` 11, `/__cron` 1. **Public GETs 234**: 86 × 301 (63 alias table at `routes.php:1883-1979`, 15 legacy
`/donate*`·`/gift*`, 8 others), 1 × 302 placeholder (`/discover`), 40 files/data, 13 payment hand-offs/logout,
**94 render a page** — 77 public site + 17 member/partner/token/staff.

### 5.1 Public site

| Path | Handler | Template | DC (props) | Phase |
|---|---|---|---|---|
| `/` | `HomeController:index` | `pages/home.twig` | HomePageV3 + WeAreAfrica | 4 |
| `/awards` | `AwardsController:index` | `pages/awards/index.twig` | AwardsPage `view=index` | 5 |
| `/awards/{p}` | `AwardsController:programme` | `pages/awards/programme.twig` | AwardsPage `view=detail` (`view=soon`: no route or state) | 5 |
| `/vote` | `VoteController:index` | `pages/vote.twig` | VoteHub | 5 |
| `/vote/{program}` | `VoteController:program` | `pages/vote-program.twig` | VotePage `view=vote` | 5 |
| `/vote/{program}/{slug}` | `VoteController:nominee` | `pages/vote-nominee.twig` | NomineePage + VoteBallot | 5 |
| `/vote/paid/success` | `PaidVoteController:success` | `pages/vote-paid-success.twig` | *inferred:* Celebration `kind=vote` | 5/3 |
| `/results/{edition}` | `ResultsController:edition` | `pages/results/edition.twig` · `open.twig` | ResultsPage | 5 |
| `/results` | `ResultsController:index` | `pages/results/index.twig` | **ambiguous** (Q14) | 5? |
| `/results/{id-slug}` | `ResultsController:show` | `pages/results/show.twig` · `late.twig` | **ambiguous** (Q14) | 5? |
| `/leaderboard` | `LeaderboardController:index` | `pages/leaderboard.twig` | Leaderboard `state=live/empty` | 6 |
| `/legacy` | `LegacyController:index` | `pages/legacy/index.twig` | LegacyVault `view=index` | 6 |
| `/legacy/{slug}` | `LegacyController:event` | `pages/legacy/event.twig` | LegacyVault `view=edition` | 6 |
| `/registry/{slug}` | `RegistryController:profile` | `pages/registry/profile.twig` | ProfilePage (`owner=true` has no view; the phase's `pages/profile.twig` does not exist) | 6 |
| `/registry` | `RegistryController:index` | `pages/registry/index.twig` | *inferred:* DiscoverPage (directory) | 4? |
| `/activity` | 301 → `/discover?tab=live` (Phase 4) | — | **retired** → DiscoverPage Live | 4 ✓ |
| `/discover` | `DiscoverController:index` (Phase 4) | `pages/discover.twig` | DiscoverPage (All, Live, sections, facets) | 4 ✓ |
| `/events` | `EventsController:index` | `pages/events.twig` | EventsPage `view=index` | 7 |
| `/events/{slug}` | `EventsController:show` | `pages/events/detail.twig` | EventsPage `view=detail` × open/waitlist/soldout/closed/ended | 7 |
| `/events/ticket/{ref}` | `EventsController:ticket` | `pages/events/ticket.twig` | TicketPage `status=valid/checkedin` | 7 |
| `/shop` | `ShopController:index` | `pages/shop/index.twig` | ShopPage `view=index` | 7 |
| `/shop/{slug}` | `ShopController:item` | `pages/shop/item.twig` | ShopPage `view=item` | 7 |
| `/shop/success` | `ShopCheckoutController:success` | `pages/shop/success.twig` | ShopPage `view=done` | 7 |
| `/shop/order/{ref}` | `ShopCheckoutController:order` | `pages/shop/order.twig` | ShopPage `view=order` | 7 |
| `/shop/back-in-stock/stop/{token}` | `ShopController:stopAlert` | `pages/shop/alert-stopped.twig` | ShopPage `view=stopped` | 7 |
| `/giving`, `/giving/{org}`, `/giving/{org}/{campaign}` | `DonationController:page` | `pages/donate.twig` | GivingPage `view=campaign` (`checkout` is an in-page step) | 7 |
| `/giving/success` | `DonationController:success` | `pages/donate-success.twig` | GivingPage `view=done` + Celebration `kind=give` | 7 |
| `/pulse`, `/pulse/reels` | `PulseController:index` | `pages/pulse.twig` | PulsePage `signedIn` | 8 |
| `/nominate` | `NominationController:form` | `pages/nominate.twig` | NominateHub | 8 |
| `/nominate/{slug}` | `NominationController:award` | `pages/nominate-award.twig` | NominationFlow | 8 |
| `/nominate/success` | closure `routes.php:2152` | `pages/nominate-success.twig` | Celebration `kind=nominate` | 8/3 |
| `/account/login` | `AccountController:loginForm` | `pages/account/login.twig` | SignIn `view=phone`/`code` (no phone path exists) | 8 |
| `/account/register` | `AccountController:registerForm` | `pages/account/register.twig` | SignIn join (`profile`, `interests`: no equivalent) | 8 |
| `/account/verify`, `/account/forgot`, `/account/reset` | `AccountController` | `pages/account/{verify-notice,forgot,reset}.twig` | SignIn family, no matching view (the DC is passwordless) | 8? |
| `/integrity` | closure `routes.php:2977-3038` | `pages/integrity.twig` | DocPage `doc=integrity` | 9 |
| `/philosophy` | closure `routes.php:2939-2975` | `pages/philosophy.twig` | DocPage `doc=philosophy` | 9 |
| `/terms`, `/privacy`, `/cookies` | `$legalRender` (`routes.php:2566`) | `pages/legal.twig` | DocPage `doc=terms/privacy/cookies` (+ CookieConsent) | 9/2 |
| `/refunds`, `/vendor-terms`, `/legal/{slug}` | `$legalRender` | `pages/legal.twig` | DocPage legal shape (not in the `doc` enum) | 9 |
| `/terms/{slug}` | closure `routes.php:2664-2672` | `pages/programme-terms.twig` | DocPage `doc=programme-terms` | 9 |
| `/help` | `HelpController:index` | `pages/help.twig` | HelpCentre `view=index` (+ Gee support) | 9/3 |
| `/help/c/{cat}` | `HelpController:category` | `pages/help-category.twig` | HelpCentre (no category view in the DC) | 9 |
| `/help/{slug}` | `HelpController:article` | `pages/help-article.twig` | HelpCentre `view=article` | 9 |
| `/blog`, `/blog/{slug}` | `BlogController` | `pages/blog/{index,post}.twig` | BlogPage `view=index/post` | 9 |
| `/status` | closure `routes.php:3053-3076` | `pages/status.twig` | StatusPageV2 | 9 |
| `/support/assistant` | `SupportController:page` | `pages/support-assistant.twig` | **retire** → Gee `mode=support` | 3 |
| `/_dev/ui` | closure `routes.php:2929-2937` | `pages/dev-ui.twig` | Phase 1 style page | 1 |

**No DC (22):** `/winners` (`results/hall.twig`), `/support`, `/judges`, `/judges/{slug}`, `/opportunities`,
`/challenges`, `/challenges/{slug}` (cites `ChallengePage.dc.html`, not in this bundle), `/community`,
`/community/{slug}`, `/community/new`, `/vote/verify` (payment proof — not VotePage `view=verify`),
`/vote/{p}/{s}/flier`, `/vote/{p}/{s}/messages`, `/vote/{p}/{s}/supporters`, `/m/{token}`, `/honour/{ref}`,
`/partner`, `/partner/success`, `/pay/success`, `/newsletter`, `/email/confirm`, `/email/unsubscribe`.

### 5.2 Member, partner, token and staff pages (not designed)
`/account` (cites `AccountPage.dc.html`, not in this bundle) · `/support/tickets` · `/org/login` · `/org` ·
`/giving/manage/{token}` · `/support/t/{token}` · `/claim/{id}` · `/claim/dispute/{token}` · `/n/confirm/{token}` ·
`/my-work/{token}` · `/interview/{token}` · `/stand/{token}` · `/events/{slug}/stands` · `/events/{slug}/stands/apply` ·
`/door/{token}` · `/form/{token}` · `/f/{key}`. REFERENCE §18.7 leaves these undesigned. **`/door/{token}` is HELD
(owner, 3 Oct 2026): a staff tool outside the redesign, like the consoles** — `pages/events/door.twig` and
`components/door.css` are in `Tests\Support\PublicSurface`'s held lists, and `TypeScaleTest` fails if any other
template links the door's sheet (PHASE-2.md §10).

### 5.3 DCs and views with no current route
~~DiscoverPage (no page; `/discover` is a 302)~~ built in Phase 4 · AwardsPage `view=soon` · SignIn `phone`, `profile`, `interests` ·
GivingPage `checkout` (an in-page step) · VotePage `verify`/`done` (in-flow states — not confirmed) ·
ProfilePage `owner=true` · ResultsPage's named `pages/results.twig` · `GET /search` JSON · `/help?gee=support`.
Components, not pages: SiteHeader, AppBar, MobileMenu, DisplayReading, CookieConsent (`view=admin` out of scope),
Gee, Celebration, WeAreAfrica, VoteBallot. `Nominate.dc.html` is the review canvas.

### 5.4 Page templates no route renders
`pages/registry/register.twig` (its controller methods are unrouted; `/register` 301s), `pages/registry/register-success.twig`
(referenced by nothing), `pages/terms.twig` (referenced by nothing; "Last updated · 1 May 2025") — orphans for the
destroy list. `pages/error.twig` is the error handler's.

### 5.5 What could not be determined
Populated rendering of the 30 parameterised routes (mapped by handler source, not watched); per-route middleware
(Slim 4 exposes none, so gating is inferred from the 302s); which DC owns `/results` and `/results/{id}`; whether
VotePage `verify`/`done` are meant as routes.

---

## 6. Conflicts — the bundle against the repo's own rules

The bundle's §0 order of authority puts its README first and the repo's templates last. It says nothing about
the repo's **guards** — the tests that encode faults which already shipped. Each row below is a place where
building the bundle as written fails a test or reintroduces a documented fault. None is resolved here.

| # | Bundle says | Repo rule / guard | Decision needed |
|---|---|---|---|
| C1 | **Colour:** 26 named tokens, "only the tokens in §6", `snippets/css/tokens.css` "the only file with hex values" (REFERENCE §6.1, §4.6) | CLAUDE.md: `Support\Accent` is the ramp, "colour comes from Accent and nowhere else", "never invent a fifth name". Guards: `AccentTest` (10), `SlotFloorTest` (15), `ColourBudgetTest` (9), `NoLiteralHexTest` (4). **Live collision today:** `--ag-line` is `#d6d4cc` from Accent (`Accent.php:121`, emitted at `gates.twig:245`) and `#e8e5dd` in `tokens.css:64`; `--ag-surface-2` likewise. Near-duplicates under different names: action wash `#e4f6e4` / `--ag-green-wash #effaf0`; live ink `#cc1950` / `#b0224f`; caution `#b3261e` / `--ag-error #b42318` | **Q1 — answered: Accent rebuilt as the bundle palette (§8).** Was: either Accent is destroyed and rebuilt *as* the bundle palette (still PHP-emitted, so the floor tests measure real values), or `tokens.css` is the source and the four guards are rebuilt to read it |
| C2 | Guards are **blind** to the bundle's names: `AccentTest`/`ColourBudgetTest` look for `--ag-<role>-(fill\|wash)`; a page painted with `--ag-green` or `--ag-gold-wash` passes them **without being checked** | CLAUDE.md, "the right rule pinned to the wrong token" | **Done in Phase 1** — fields read by property, `Tests\Support\ColourFields` |
| C3 | Gold `#f3b416` for "honour, winners"; `--ag-mute` for disabled text; inputs and outlined chips bordered only by `--ag-line-2 #d6d4cc` | Gold is 1.61:1 on the ground — CLAUDE.md records gold used as a line as the reason the site read monochrome; `SlotFloorTest::test_mute_is_never_a_word`; the chip/input border is **1.48:1** on white, under the 3:1 a border owes (WCAG 1.4.11, CLAUDE.md tier section) | **Q2 — answered: accepted as shipped (§8)** |
| C4 | Seven exact shadows (REFERENCE §6.5) | `Accent.php:212-217` "there are no shadows anywhere on this site" (the lip) | **Q3 — answered: adopted (§8)** |
| C5 | Type scale: body 16, 14.5, 13.5, micro 11.5–12.5, display fixed per breakpoint, "never `vw` type" (§6.2, §4.5) | `TypeScaleTest` **already enforces the bundle's ladder** (11.5…17, `MIGRATING = [10, 11]`, Phase 1 `c73971e`) — but CLAUDE.md still states the old closed scale `10·11·12·13·14·16·17` and "display set with `clamp()` against the viewport", and `tokens.css:195-201` carries `vw` compat sizes | **Resolved (Q4, owner, 3 Oct 2026):** §6.2 as written — closed ladder 11.5…17, display fixed per breakpoint, no `vw`/container-unit/`clamp()` type; `MIGRATING` deleted. `TypeScaleTest` destroyed and rebuilt over the public surface (`Tests\Support\PublicSurface`; admin/judge held); CLAUDE.md's section rewritten. The `tokens.css` `vw` sizes were already gone |
| C6 | "Never mono for labels, stats or kickers; no uppercase labels" (§6.2, §18.3-4) — but the bundle contradicts itself: HANDOFF §2 "Mono … numbers, codes, times", §5e "a mono count"; SKILL §24.3 "mono 17px value" vs §24.7 "no monospace"; HANDOFF §5c "removable dark chips" vs §18.2 outlined | CLAUDE.md house style: "hairline rules, **mono micro-labels**" (214 such declarations). 124 files use `text-transform:uppercase`. No test either way | **Resolved (Q5, owner, 3 Oct 2026):** README §0/§18 wins over HANDOFF §2/§5e and SKILL §24.3 — mono only for references and codes, no uppercase/capitalize/small-caps or typed all-caps labels except the CONFIRMED stamp, sentence-case headings. Guard: `MonoAndCaseTest` (selector must carry a `ref`/`code`/`stub` segment for mono, `stamp` for capitals); house-style line rewritten. Chips (§5c vs §18.2) follow §18.2: outlined |
| C7 | Snippet `tokens.css` declares the full ladders (`--ag-sp-*`, `--ag-r-*`, `--ag-sh-*`, `--ag-z-*`, stock, `--ag-ease-pop`, `--ag-dur-3`) | `DeadTokenTest`: no property declared without a reader. It already forced five bundle tokens out; Phase 1 made `/_dev/ui` read ladders to keep them alive — the §17 fault dressed as a fix | A token lands with its first reader, in the phase that reads it |
| C8 | "No inline styles in Twig" (§4.6, README rule 6) | 924 `style=` and 89 `<style>` blocks today; `NoLiteralHexTest` and `ColourBudgetTest` **read templates only**, so moving colour into component CSS moves it out of every guard's sight | Rebuilt guards sweep `public/assets/css/components/*.css` too |
| C9 | Event tiers carry stored `accent`, `accent_light`, `wash`, `deep`, `glow` columns (§11, HANDOFF §5) | CLAUDE.md: "a tier's colour is a slot, never a hex … `EventTierTone::hues()` is the one resolver". Guards `EventTierToneTest` (21), `EventTierColourFieldTest` (13), `EventTierSelectionTest` (28), `EventFlierThemeTest` | **Q6** — recommend deriving the five properties at read time from the slot |
| C10 | `celebration.js`/`.css` ship **verbatim**; SKILL §24.7: the engine "sets only inline style properties via JS" | It writes `style="…"` **attributes through `innerHTML`** (lines 122, 147-152), so `style-src-attr 'unsafe-inline'` becomes load-bearing. 32 lines of literal hex, 7 colours outside §6.1 (`#fbd46a #f4789c #7fb6d9 #e8a800 #fff3c4 #d9c7a3 #c9dbe8`), demo defaults `'KCEA Ceremony'` / `'Sat 6 Dec · 18:00'`. Its `ag-cel-` key is undeclared → `CookieRegistryTest` fails by name. It replaces `celebrate.js`, guarded by `CelebrationTest` (17) | **Q7** — accept the file's hexes and defaults as verbatim (exempt by kind) or the owner amends it |
| C11 | Unsplash photography in 21 of 32 DCs — "placeholders only" (§14) **and** "never ship a placeholder" (§4.2) | `AwardPageTest:88` refuses a stock photo on the award page; Unsplash still live in `opportunities.twig:9,73` and a `dns-prefetch` at `gates.twig:162` | **Q8** — what fills a photo slot with no real image |
| C12 | Paid contributions "count the same as free votes" (§12, HANDOFF §6, DESIGN-NOTES) | CLAUDE.md: paid votes count at full weight in the **30% tally only**; the 70% people term counts verified people and a purchase buys no reach (`VoterReach`; `PaidVoteCpiSeparationTest`, `EditionScaleTest` sweeps help articles for the retired wording) | **Q10** — copy built from that sentence would publish a false rule; confirm it is overridden |
| C13 | "No AI wording anywhere public" (§4.8, README rule 8) | Legally required disclosures: `partials/ai-collection-notice.twig` (NDPA point-of-collection), `/privacy#automated-processing` from `AiPrivacy::disclosure()`, the `/cookies` AI section; `AiPrivacyTest` (30). The notice's own comment draws the line: features may go unnamed, data processing may not | Keep the disclosures; the rule applies to feature copy |
| C14 | Consent cookie `ag_consent` (JSON, versioned), four categories (§10, CookieConsent DC) | `CookiePrefs::COOKIE = 'ag_privacy'`, one resolver ("if anything said no, the answer is no"), `visits_consent_mode`, plain-POST controls, identical class strings on accept/decline; `CookieRegistryTest` fails by name on `ag_consent`, `ag-cel-*`, `ag-gee-privacy` | **Q11 — answered (owner, 3 Oct 2026): build the four categories in `ag_consent`, keeping the one-sentence rule, plain-POST controls and identical answer classes. Built in Phase 2 item 6 (`docs/handoff/PHASE-2.md`, "Cookie consent").** |
| C15 | Snippets use `|trans`; ES-module JS (`export function`); `page-shell.twig` includes `partials/nav.twig` and `partials/gee.twig`; `shell.css` locks `html,body{overflow:hidden}` globally; `bottom-ui.js` skips `position:fixed` bars via `offsetParent` | No `trans` filter (built since — §2); every repo script is a classic `defer` script; neither partial exists; a global lock makes every `gates.twig` page unscrollable; the `offsetParent` bug was found and fixed in `4c5f489` | **Q12 — answered: build it.** `|trans` now compiles (§2 "Translation"). The other three are known snippet faults; the DC wins over the snippet (snippets README) |
| C16 | `GET /search?q=&scope=` returns JSON (HANDOFF §3) | `/search` is a 301 alias to `/activity`; the palette's JSON is `/activity/search` (`SearchScopeTest`, 7) | **Q13** |
| C17 | `docs/redesign-ref/` | A different, earlier set of 24 DCs; the nomination flow (`4e4090c`, `4dadb12`) was built against it, not against `H/design/NominationFlow.dc.html`. REFERENCE §0: only `design/` is spec | **Q15** — two references disagreeing is this repo's costliest shape |
| C20 | NominationFlow DC: the error copy says "at least one" category | Its own heading, and PHASE-8 §8.16, say two to three; `NominationRules` enforces two (`src/Services/NominationRules.php:52-62`). REFERENCE §0 puts the phase file above the DC | **Resolved by the bundle's own order:** two. Recorded here because the comment in `NominationRules` points at this file |
| C18 | The method rules (§4.4, DESIGN-NOTES "small request = small edit") | The owner's principle (§0 above) | **Resolved by the owner:** destroy and rebuild; the feature inventory replaces "keep every feature" as the guarantee |
| C19 | §17 acceptance: Lighthouse, 2 GB Android, overlay diffs | No headless browser on the production host; the dev container has Chromium (Playwright) and the earlier sessions' screenshots in `docs/handoff/{ref,mine,shots}` | Screenshots are taken in the dev container |

**CLAUDE.md is already stale in three places** because Phase 1 rewrote the guards and left the prose: the type
ladder (C5), the paper colour (`Accent::PAPER` is already `#f1efe9`; CLAUDE.md and the `Accent.php:13` /
`AccentTest.php:17` docblocks still say `#f0f2f2`), and the role count (Accent has five roles — `fault` was
added — not four). Whichever phase decides C1 and C5 rewrites those sections in the same commit.

---

## 7. What earlier sessions built from this bundle — and the destroy list

The clone is shallow (58 commits visible). Redesign work found, oldest first:

| Commit | What | Built against |
|---|---|---|
| `9c7e07b` | Phase 0 (the GAPS.md this file replaces; the skill) | this bundle |
| `c73971e` | Phase 1: `tokens.css`, `shell.css`, `components.css`, `shell.js`, `layout/shell.twig`, `pages/dev-ui.twig` + `/_dev/ui`; rewrote `TypeScaleTest`; moved `ag_accents()` before the links | this bundle |
| `b18620b` | Phase 2: app bar, tab bar, menu sheet, quick settings, display reading, a11y head, lang prompt; `a11y.js`, `chrome.js`; `Languages`, `LanguageMiddleware` | this bundle |
| `ee7dce0` | Phase 2: site header (`layout/nav.twig`, `partials/site-header.twig`, `header.js`; deleted `components/nav.css`; `components/vote-countdown.css`) | this bundle |
| `4c5f489` | Phase 2: search palette (`ag-search.js`, `site-search.twig`, `/activity/search?scope=`) | this bundle |
| `e2b2343`, `4e4090c`, `4dadb12` | Nominations: per-award pages, 2–3 categories, evidence; flow; 801 touch targets | **`docs/redesign-ref/`** |
| `22b31d1`, `777bb00`, `980609c` | Account; `partials/ui.twig` | `AccountPage.dc.html` (**not in this bundle**) |
| `e107714`, `63b68d8` | Challenges; `challenge-strip.twig` | `ChallengePage.dc.html` (**not in this bundle**) |
| `e9f7d2f` | Awards page; `AwardOverview`; `components/awards.css` | this bundle's AwardsPage |

### 7.1 Existence against the snippets
| Path | State |
|---|---|
| `public/assets/css/tokens.css` | EXISTS (255 lines). Missing from the snippet set: `--ag-stock-low`, `--ag-stock-gone`, `--ag-sh-gee`, `--ag-ease-pop`, `--ag-dur-3` (C7). Added beyond §6.1: `--ag-surface-2`, `--ag-error-wash`, `--ag-r-7`, and a 22-name compatibility block (`--ag-fs-*` in `vw`, `--ag-r-sm/md/lg`, `--ag-shadow-nav`, `--ag-nav-h`, …) |
| `public/assets/css/shell.css` / `components.css` | EXIST (169 / 1,369). Every snippet selector present; the lock is scoped to `body.ag-shelled` |
| `public/assets/js/shell.js` | EXISTS — scroll state, collapsing search, bottom UI, sheet, as one classic script (`window.AGShell`). The four snippet JS files do not exist separately |
| `templates/layout/shell.twig`, `partials/app-bar.twig`, `partials/tab-bar.twig` | EXIST, rebuilt without `|trans` |
| `templates/partials/celebration.twig`, `public/assets/js/celebration.js`, `css/components/celebration.css` | **MISSING** |
| `templates/partials/gee.twig`, `templates/partials/nav.twig` | never existed |
| Redirects snippet | not applied |

### 7.2 Destroy list (for the phases that own them — nothing is deleted in Phase 0)
Each is destroyed **together with** its guard, which is rebuilt with its rule in the same commit.

- **Phase 1 (foundations):** `public/assets/css/tokens.css`, `shell.css`, `components.css`; the orphan
  `public/assets/css/base/tokens.css` and its link at `templates/admin/login.twig:11`; `public/assets/js/shell.js`;
  `templates/layout/shell.twig`; `templates/pages/dev-ui.twig` and its route (`routes.php:2920-2937`).
  Guards: `TypeScaleTest`, `DeadTokenTest`, `AssetBundleTest`, `ShorthandOverridesTest`, and — with Q1 — `AccentTest`,
  `SlotFloorTest`, `ColourBudgetTest`, `NoLiteralHexTest` + `tests/baselines/template-hex.json`.
  **Done (3 Oct 2026) — `docs/handoff/PHASE-1.md`.** `shell.css`, `components.css`, `shell.js`, `layout/shell.twig`
  and `pages/dev-ui.twig` were deleted and written again; new guards `ShellLayoutTest`, `DevUiTest`.
  **Moved, not rebuilt — to be destroyed by their owners.** `components.css` had grown to 1,376 lines because later
  work appended to it. Before the rebuild those rules were moved out **byte for byte** (screenshots of `/`, `/nominate`
  and its Menu at 390 and 1440 are byte-identical before and after the move, `shots/phase-1/carve/`), each into a file
  owned by the phase that destroys it, loaded at the position it held inside `components.css` (both layouts and
  `AssetBundle::STYLESHEETS`):
  - `public/assets/css/components/chrome.css` — **moved, to be destroyed in Phase 2**: app bar, tab bar, bottom action
    bar, `.ag-tint--*`, `.ag-btn--sm` / `--quiet`, `body.ag-sheet-open`, Menu, Quick settings, Display & reading, the
    language prompt, site header, mega panel, popovers, announcement strip (b18620b, ee7dce0, 4c5f489, e2b2343,
    4dadb12); and `[dir="rtl"] .ag-ico-dir` from `shell.css`. Two lines were written rather than moved, both marked in
    the file: the tab bar's `body:not(.ag-shelled)` became `html:not(.ag-shelled)` (the lock class moved to `<html>`),
    and the Display & reading rows keep DisplayReading.dc.html's 44 × 26 switch (Phase 2 had resized the base switch
    in place; the rebuilt base is the snippet's 46 × 28).
  - `public/assets/css/components/library.css` — **moved, to be destroyed with its readers**: pill, table, cell, facts,
    meter, avatar, page head, lead, group, steps, ticks, notice, FAQ, date, link card, form stack, disclosure, sub-nav,
    `input.ag-field` (980609c Account, e107714 Challenges, e9f7d2f Awards). The award page's share goes in **Phase 5**;
    Account and Challenges are **Q16** — no phase owns them yet.
  - `.ag-ai-note` → end of `public/assets/css/components/nominate.css` — **moved, to be destroyed in Phase 8**: its
    only includer is `pages/nominate-award.twig`.
  `DevUiTest::test_components_css_holds_the_base_components_and_nothing_else` now fails any block appended to the base.
  **Found by the rebuild, for Phase 2:** `partials/app-bar.twig` draws its back chevron inline without `.ag-ico-dir`,
  so in Arabic it points the wrong way (`shots/phase-1/devui-390-rtl-collapsed.png`).
- **Phase 2 (chrome):** `layout/nav.twig`, `partials/{site-header,app-bar,tab-bar,menu-sheet,quick-settings,display-reading,a11y-head,lang-prompt,site-search}.twig`,
  `public/assets/js/{a11y,chrome,header,ag-search}.js`, `components/{site-search,vote-countdown}.css`, the cookie notice partial.
  Guards: `SiteHeaderTest`, `ChromeReachabilityTest`, `DisplayReadingTest`, `LanguageTest` (rebuilt to catch §3.8's fault),
  `SearchScopeTest`, `VoteCountdownTest`.
- **Phase 3 (Gee + celebrations):** `public/assets/js/celebrate.js`, `vendor/canvas-confetti-1.9.3.js` + its PROVENANCE row,
  `partials/celebrate.twig`, the `ag-celebrated:` row in `CookieRegistry`; the inline Gee markup in `gates.twig:462-560`,
  `gee.js`, `components/gee.css`; `pages/support-assistant.twig` (after the desk exists under `/help`).
  Guards: `CelebrationTest` (keeping its four refusal rules).
- **Phases 4–9 (pages):** each page template in §5.1 with its `<style>` block and page CSS/JS, including the ones already rebuilt
  against other references — `pages/nominate*.twig` + `components/nominate.css` + `nominate*.js`, `pages/awards/*.twig` +
  `components/awards.css`; the orphans in §5.4; `pages/activity.twig` (Phase 4).
- **Undesigned surfaces** rebuilt against DCs outside this bundle — `account/*`, `challenges/*` with `components/{account,challenge}.css`,
  `account.js`, `partials/{ui,challenge-strip,account-payout}.twig` — are listed, not scheduled: no phase in this bundle owns them (Q16).
- **Docs:** `docs/handoff/{ref,mine,shots}/` as each page they picture is destroyed; `docs/redesign-ref/` on Q15.

**Never destroyed** (see §0): `database/migrations/2027_02_02_nomination_categories_evidence.php` and every other migration;
both schema files; `Support\Languages`, `LanguageMiddleware` and their cookie rows (the only i18n there is, and published policy);
`NominationRules`, `NominationCategoryFit`, `AwardWording`, `NomineeKind`, `AwardOverview`, `ActivityFeedService::SCOPES`; every
scoring, sealing, mail and payment service.


### 7.3 The old pages, destroyed (3 Oct 2026) — `DESTROYED.md`
The owner judged Phase 1's in-place renames (~95 files) and its moved rules (`chrome.css`, `library.css`) a patch, and decided:
destroy the old pages now; each phase rebuilds its own from its DC. **125 files were deleted** — 92 page templates,
`layout/gates.twig` and `layout/account-auth.twig`, 9 partials, 22 stylesheets — by the rules and with the per-file reason,
rebuilding phase and route behaviour in [`DESTROYED.md`](DESTROYED.md). A feature inventory of every destroyed file, taken
from `a9d963a^` (§0's requirement), is in [`inventory/`](inventory/), each ending with the rules of the **548 guard-test methods**
destroyed with it; cross-page rules are in `inventory/_cross-page-rules.md`.

- **What renders now:** `/_dev/ui` (header, app bar, tab bar and sheets with base styles only — `chrome.css` is gone) and `/door/{token}`;
  every non-page endpoint and every POST. Every route whose template is gone answers **500** through `ErrorHandler`'s last-resort
  body (its own `pages/error.twig` is destroyed too), and records a `PublicFault` per request.
- **Held, owner to decide:** the 16 admin/judge templates `a9d963a` patched, `a11y.css`, `components/auth.css`, `partials/viz.twig`
  (shared with admin), the patched `src/` services, and the email templates — no phase rebuilds them.
  **Decided (owner, 3 Oct 2026):** the seven email templates — and the house shell `OtpService::brandWrap()` they
  arrive in — were destroyed and rebuilt to the §6.2 ladder with colours from `Accent::mail()` (inventory
  `inventory/_emails.md`, PHASE-2.md §10); `a11y.css` stays held and is now listed in `PublicSurface::HELD_CSS` (only
  the consoles link it); the door scanner is held like admin.
- **This changes §7.2:** the Phase 2–9 lists above name files that no longer exist; a phase now *writes* its pages rather than
  destroying them first. The orphans that did not qualify (`layout/nav.twig`, `layout/footer.twig`, most Phase 2 chrome partials,
  the legacy sheets) are still the owning phase's to destroy — listed in `DESTROYED.md`.
- **Tokens:** seven `tokens.css` steps with no remaining reader were removed; seven palette tokens and three `--ob-*` are held in
  `DeadTokenTest::AWAITING_REBUILD` (deleting a handoff palette entry is the owner's call, Q1).

---

## 8. Blocked questions — for the owner, not guessed

1. **One colour source** — rebuild `Support\Accent` as the bundle palette, or make `tokens.css` the source and rebuild the four colour guards to read it? (C1, C2) — **Answered (owner, 2 Oct 2026): Accent is destroyed and rebuilt as the handoff palette**, exact names and values, still PHP-emitted into a nonced `<style>` by every layout; `tokens.css` holds no colour; no compatibility aliases — every reader of a retired name rewritten. Guards rebuilt to see the handoff's names (`AccentTest`, `SlotFloorTest`, `ColourBudgetTest`, `ColourIsNeverAloneTest` via `Tests\Support\ColourFields`) and to sweep CSS as well as templates (`ColourLiteralTest` replaces `NoLiteralHexTest`; C8). Built in Phase 1.
2. Gold as fill only; `--ag-mute` as disabled text; the 1.48:1 outlined chip/input border — accepted, or corrected? (C3) — **Answered (owner, 2 Oct 2026): ship the handoff exactly, ±0.** Fills, lines/borders, gold as a line, `mute` and the 1.48:1 border are accepted (`Accent::ACCEPTED`); the 4.5:1 floor is kept for every word token on every ground it is drawn on (`Accent::words()`); a failing word is reported, never re-valued.
3. Shadows — the bundle's seven, or none? (C4) — **Answered (owner, 2 Oct 2026): adopted.** §6.5 plus `--ag-sh-gee`, emitted by Accent (they are rgba of ink); the "no shadows anywhere" doctrine is deleted from Accent, AssetBundle, `lip.css`, `article.css` and CLAUDE.md. The two per-element §6.5 shadows (celebration badge, celebration ticket) belong to Phase 3.
4. Display type — fixed rungs per breakpoint (bundle) confirmed, `MIGRATING [10, 11]` deleted outright? (C5) — **Answered (owner, 3 Oct 2026): apply §6.2.** Fixed sizes per breakpoint, never viewport-scaled (`vw`, `clamp()` on `vw`) type; the 10/11px `MIGRATING` allowance is deleted outright. `TypeScaleTest` rebuilt and scoped explicitly to the public surface (admin/judge templates and their CSS are held). Surviving public files still on 10/11px are listed by the test's own failure, owned by the rebuilds of the door, `partials/viz`/`poll` and the mail templates. **Since (owner, 3 Oct 2026):** the mail templates are rebuilt onto the ladder, the door is held, `poll` was destroyed and `viz` is held — `TypeScaleTest` is green; and **screen type is written in rem** (`tokens.css` `--ag-fs-*` steps) so the Display & reading setting reaches it, which `TypeScaleTest` now also holds (PHASE-2.md §10).
5. Mono and uppercase — which side of the bundle's own contradictions wins? (C6) — **Answered (owner, 3 Oct 2026): the README's §6.2/§18.3–4 wins.** JetBrains Mono only for references and codes (order refs, ticket codes, receipt codes, times inside ticket stubs), never labels, stats or kickers; no uppercase labels except the ticket stub's CONFIRMED stamp; headings sentence case. Guard: `MonoAndCaseTest`; CLAUDE.md's house-style line rewritten.
6. Event tier colour — five stored columns, or derived from the slot at read time (recommended)? (C9)
7. `celebration.js` — accept its off-palette hexes and demo defaults as verbatim, or amend the file? (C10) — **Still open.** Shipped verbatim in Phase 3 and exempted by name and hash; the full list of what disagrees is in `PHASE-3.md`, "Celebrations" §5, with Q-C1–Q-C3 beside it.
8. A photo slot with no real image — what renders? (C11) — **Answered (owner delegated, 4 Oct 2026: "think of something"). Decision:** never a stock photo, never an
   invented face, never a broken-image box. **People** (nominees, judges, members): a monogram — initials in Playfair 700,
   ink on the neutral `tint` ground with a hairline ring, sized to the slot; the person (or their claimer) is offered
   "Add a photo" where they can act. **Things** (events, campaigns, awards, products): a generated cover — the event's
   own accent through `EventFlierTheme` (already one accent → a whole contrast-checked palette), the title set in the
   cover, drawn in CSS so it is never a request that can fail; awards use the programme's identity family. One shared
   partial (`partials/photo.twig`) decides real image vs fallback, so every page answers the question the same way.
9. Ballot fields — make name, phone and message required (REFERENCE §12)? The paid name field is the display-name consent today. (§3.16)
10. Paid-vote copy states the real rule (tally yes, reach no) — confirm the bundle's "count the same" sentence is overridden. (C12)
11. Consent — keep the `ag_privacy` single-switch model, or build the four-category `ag_consent` (CookiePrefs, legal copy, a repair migration of the stored policy)? (C14) — **Answered (owner, 3 Oct 2026): build the handoff's four categories in `ag_consent`** — Essential (always on) plus Preferences, Analytics, Marketing — keeping "if anything said no, the answer is no" (GPC/DNT beats a stored yes; GPC shown as "Respected your browser's privacy signal"), both controls plain forms that post, the notice's answers one identical class string, no dismiss-without-answering. Built in Phase 2 item 6: `CookiePrefs` and `CookieRegistry` rebuilt, `ag_privacy` carried over (a no stays a no) and retired, the generated `/cookies` section rebuilt, `2027_02_27_cookie_consent_policy_repair.php`, `partials/cookie-consent.twig`. Marketing is drawn as "Not used" with no switch (nothing here does marketing) — see PHASE-2.md, "Cookie consent", for that judgement.
12. ~~Translation — build a `trans` layer before any phase uses the snippets, or drop `|trans`? (C15)~~ **Answered by the product owner: build it first.** Built — §2 "Translation".
13. Search JSON — move it to `GET /search` (retiring that alias), or keep `/activity/search`? Trending in the empty palette has no measured signal — drop it? (C16, §3.6)
14. Which DC owns `/results` (index) and `/results/{id}` (one award)? (§5.1)
15. `docs/redesign-ref/` — delete as superseded? (C17) **Answered 5 Oct (AUDIT Q15): delete. Deleted 8 Oct 2026; it remains in git history.**
16. Account and Challenges were rebuilt from DCs not in this bundle — destroyed and rebuilt in a phase of this redesign, or left as they are?
17. Waitlist hold — 24 h (bundle) or 48 h (`EventWaitlist::OFFER_HOURS`)? (§3.12)
18. Explore — keep Results (7 items) or the bundle's 6? (§3.15)
19. Gee on `layout/shell.twig` pages — yes? Today they have none. (§3.10) — **Applied in Phase 3 from the spec, not answered by the owner:** §7.7 says "on every page except Pulse", and the shell is the only public layout, so Gee is on every shell page but Pulse (plus the old layout's two suppressions that still hold: the support pages and a page with no chrome). **Awaiting the owner's confirmation — §8c item 12.**

---

## 8b. Answered after Phase 2 (owner, 3 Oct 2026)

Raised by Phase 2 (`PHASE-2.md` §9 and deviation 15), not in the list above:

- **Text size does not reach px type** — *Answered: type in rem.* `tokens.css` `--ag-fs-*` (px name, rem value),
  every public screen size reads one; pixel-identical at 100%, scales at 125/150% (`shots/phase-2/rem/`).
- **44px everywhere (§6.7) vs §7.1's 40px header** — *Answered: the header as specified.* Recorded by selector in
  `TargetSizeTest::OWNER_HEADER`; 44 holds everywhere else, 24 under everything. Six older sub-44 controls outside the
  header surfaced and are listed as OPEN, awaiting a ruling (PHASE-2.md §10).
- **The door scanner** — *Answered: held like admin.* **The email templates** — *Answered: destroy and rebuild to the
  ladder, colours from Accent.* Both done (PHASE-2.md §10).

---

## 8c. Open after Phase 2 — awaiting the owner (recorded 3 Oct 2026)

Raised by the Phase 2 builds and put to the owner; nothing below has been decided, and no phase may guess
an answer. Each is detailed in `PHASE-2.md` (deviations list, §9, §10 and "Cookie consent").

1. **Marketing category** — nothing on this site does marketing, so it is drawn as the fourth row marked
   "Not used" with no switch, and can never be stored as a yes. Keep that, or drop the row?
2. **Consent records** — the handoff's admin consent view promises answers kept for 12 months; nothing
   server-side records an answer today. Build a consent log, or keep none?
3. **Notice position** — the cookie notice comes after `<main>` in reading order (51 Tab presses at 390).
   Move it to the top of the page?
4. **Language under a privacy signal** — with GPC/DNT (or Preferences refused) the chosen language is a
   session cookie and is forgotten when the browser closes. Acceptable?
5. **Six sub-44px controls outside the header** — `.ag-pop__lang` rows 40, palette Esc 28, shortcuts
   close 36, `.ag-chip` 40 and `.ag-cs__go` 40 at ≥1024, `.ag-switch` 28 inside a 44 span. Each: raise
   to 44, or an approved exception? (`TargetSizeTest` lists them as OPEN.)
6. **Tab labels at 150%** — on a 390 phone "Discover" and "Nominate" touch (no overlap). Cap the tab
   label size?
7. **Email colours** — mapped by role onto the palette, so some look different (the old 2.8:1 footer
   grey is now `soft`; the old dark-mode palette is retired). Confirm.
8. **Design deviations** — 28 in the chrome and 14 in cookie consent, each with what/why, listed in
   `PHASE-2.md`. Approve or reject each.
9. **Still open from earlier**:
   - who writes the translations (every new string goes through `|trans`, but no catalogue entries exist yet);
   - trending in the empty search palette has no measured signal (Q13, second half);
   - the change to how `/cookies` describes the `ag-a11y` text-size setting.
10. **~~Re-opening consent at ≥600px~~ — closed in Phase 4:** the footer's Cookies link reopens the choices
    (`data-ag-do="consent-open"`). Was: **Re-opening consent at ≥600px** — there is no in-page way back to the choices until Phase 4 rebuilds
    the footer. Accept the gap until then?
11. **Admin and judge consoles** — the owner is sending an update; both stay held until it arrives.
12. **Gee (Phase 3) — confirm the spec's reading, and seven questions it raised** (`PHASE-3.md`, "Gee", §7):
    Q19 applied from §7.7 (Gee on every shell page but Pulse, and still off the support pages) — confirm; the
    DC's privacy sentence "kept for 30 days" has no mechanism behind it (the note states what the code does
    instead); the reply promise reads the one SLA the platform has (`review_sla_hours`, 48h by default) — or a
    separate support SLA setting?; "A person sees every unresolved message" is the spec's footer, but a chat
    turn reaches a person only when it is escalated; the spec's "no bar 40px" against its own formula (16px +
    the safe area); `/help` does not exist until Phase 9, so the 301 lands on a 500 until then; and the
    deviations listed there.

13. **Celebrations (Phase 3) — four questions** (`PHASE-3.md`, "Celebrations"):
    **Q7** still open — every hex, px size, capital and English word inside the byte-identical
    `celebration.js`/`celebration.css` is listed in `PHASE-3.md` §5; the engine takes no colour option, so Accent
    values cannot be fed in from outside (only `ColourLiteralTest` exempts the stylesheet, and only while its
    sha256 still matches); **Q-C1** the engine always writes `ag-cel-` to localStorage, so the boot hands it a
    seen key only when Preferences is allowed — with Preferences refused the burst replays on every view;
    **Q-C2** four DC card tints are not in §6.1 and are drawn with each family's wash token; **Q-C3** the
    verbatim file exceeds the §24.6 visible-at-once budget (≤12): win shows 26, desktop nominate 16 (DOM counts
    39 / 15–17 / ≤8 are within budget). Plus six deviations (no replay under reduced motion, "1st" not counted
    up, the share button as the default primary, one full stop in the live region, no haptics before a tap).

---

## 8c (cont.) — raised by Phase 4 Home (4 Oct 2026)

14. **Homepage sections that need a data model** (`PHASE-4.md`, "Home", H8): "Who recognises" needs
    recognitions + verified issuers and a decision on what Businesses/Governments are offered (B1); the Wall needs
    approved testimonials with consent for that use (B2). Build them (migration → service → admin) or drop them?
15. **Recognise band** — what may it promise, and where does "Start recognising" go (B3)?
16. **Home phone bar** — the DC's logo bar (built) or §7.2's large-title root (B4)?
17. **Featured campaign rule** — the open one closing soonest (built), or a "featured" flag (B5)?
18. **Home deviations** D2 (hero lead), D3 (record card), D4 ("votes cast"), D6, D10, D12, D13, D17 need approval;
    Q8 (absent photos) is still open.

## 8c (cont.) — raised by Phase 4 Discover (4 Oct 2026)

19. **Discover facets and kinds with no record behind them** (`PHASE-4.md`, "Discover", D7): what do
    "Identity verified (government ID)", "Vouched for (3+ verified people)" and "Field" mean here (B-1)? Not drawn
    until answered. The Recognitions chip says none are recorded (D-2) — keep it, or hide it until §3.1 is built?
20. **"Most nominated"** — count category entries (built), or record `nominee_id` on a nomination at approval so
    the DC's "N nominations" is honest (B-2)?
21. **Event accessibility flags** (captions, sign language, audio description) — columns for Phase 7 (B-3)?
22. **`/registry`** still renders a destroyed template (500): retire it into `/discover?tab=people` (301) or rebuild
    it in Phase 6 (B-4)?
23. **Discover deviations** D-1 … D-8 and D-10 need approval — D-10 (kind dots as structure, not colour events)
    decides whether the page can be drawn as designed at tier 2.

## 8d. Admin and org consoles — owner decisions (4 Oct 2026)

The admin handoff arrived (`design_handoff_admin_console`: `Admin Console v9.dc.html`, `Org Console v2.dc.html`,
README). The consoles leave the "held" list and are destroyed and rebuilt like every other surface.

- **§1a tokens — (A), with DM Sans instead of Geist.** The monochrome palette of README §10 replaces the teal
  `--ad-*` set; the face is DM Sans (the house face), and the mono role is the house JetBrains Mono, not Geist
  Mono. The console colours live in `Support\Accent` as a separate console set, so there is still exactly one
  file a colour may be typed in.
- **`health` gate — widened as designed.** New MATRIX key `health` = superadmin, admin, viewer; Integrations,
  Email health and the new Alerts page move to it. `workspaces` (superadmin) only if Hosts is built.
  **And integrations are extensible from the UI**: an admin can add and configure more providers from the
  console, not only the hard-coded set (design to be proposed).
- **Hosts — not decided.** The owner asked for an assessment of feasibility first (given in chat, 4 Oct 2026):
  nothing in the schema owns a programme, event or challenge today — every one is platform-owned — while
  partner organisations (`gates_partner_orgs`, `org_id` on campaigns, stands, catalogue, payouts, documents)
  are already a tenant with their own login. Hosts are not built until the owner chooses.
- **Org capabilities — to be discussed** with the owner before the org console is rebuilt beyond what exists.
- **Console type — decided in stage 1, flagged for the owner to confirm:** the console follows its OWN ladder
  (README §10 + 10.5 and 16, in rem), never vw/clamp, NO capitals, mono only for figures, references, times and
  keycaps; held by `ConsoleTypeTest` over the rebuilt console files. The public ladder and guards are unchanged.
- **Stage 1 raised** (`PHASE-ADMIN.md` §4, §6): 18 deviations and 7 questions — chiefly the 15 pages the old rail
  offered to roles the guard refused (each mapping is an access change), Review queue's route, and four console
  words the handoff draws under 4.5:1 (reported in `Accent::consoleReported()`, not re-valued).

## 8e. Public menu sheet — owner request (4 Oct 2026)

"The menu for mobile should be smarter. You cannot currently drag down to close."
- **Most used**: a frequency + recency ("frecency") ranked set of the member's most-used destinations, shown
  where the four Participate tiles are today (Facebook's "Your shortcuts" pattern).
- **Drag**: the same structure as now, but dragging behaves like Meta's sheets — opens at a medium height,
  drags up to full, drags down (or flicks) to close, with the inner list scrolling first and handing the pull
  to the sheet at its top. The open height follows Meta. Research and the chosen numbers: `MENU-SHEET.md`.

## 8f. Hosts and events — owner (4 Oct 2026)

- **Hosts: yes.** "Businesses, governments and so on should be able to use it for events, awards and so on." One
  award has already run on production, so the existing programme (and every event, challenge and campaign) must
  become owned by the platform's own host in a migration that changes no published figure. Design first
  (`HOSTS.md`), then build after admin stage 1.
- **Events: "there's nothing like upcoming events or coming soon events on the site."** Build the Phase 7 events
  index and detail now (§8.10), including an upcoming list and an announced-but-not-on-sale ("coming soon") state,
  end to end, plus the §8.11 ticket polish.

## 8a. Code that cites this file

Four comments cite section numbers of the GAPS.md this file replaces. Phase 0 edits no other file, so the
phase that next touches each one re-points it:

| Comment | Cited | The fact now lives in |
|---|---|---|
| `tests/Unit/SiteHeaderTest.php:126` | §9.8 (Results in the header) | §3.15, Q18 |
| `src/Support/Languages.php` | §5.3 (no translation layer) | Re-pointed: the docblock now describes `Support\Translator` and cites Q12 |
| `src/Services/NominationRules.php:61` | (no section) the DC's "at least one" | C20 |
| ~~`src/routes.php:1842`~~ | §5.5 (no Discover page) | Comment removed with the 302 in Phase 4 |

---

## 9. Done when

- [x] **`docs/handoff/GAPS.md` exists, covering every gaps row with a status and paths** — §3, all 16 rows, plus §4.
- [x] **A route → DC map is included** — §5, derived from the live router and dispatch, not a parse.
- [x] **No template, CSS or JS was changed** — `git status` after this phase: `docs/handoff/GAPS.md` only. The
  skill was reinstalled byte-identical, so it records no change.
- [x] **REFERENCE §17 for each screen in this phase** — Phase 0 has no screens: no screenshots, overlay diffs, RTL,
  keyboard or reduced-motion evidence apply. **Deviations: 0.**

## Report back

- **Files:** deleted and rewritten `docs/handoff/GAPS.md`; `.claude/skills/app-ux-standards/SKILL.md` deleted and
  reinstalled from the bundle (byte-identical, no diff).
- **DC prop combinations → screenshots:** none — Phase 0 renders nothing.
- **Deviations:** none.
- **Blocked:** §8, nineteen questions. Q1–Q5 block Phases 1–2 (Q12 is answered and built); Q7 and Q19 block Phase 3; the rest block the phase named beside them.
