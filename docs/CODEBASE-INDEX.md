# Africa GATES — Codebase Index

> **Continental Cultural Recognition Platform · An Afrovanguard Initiative**
> Generated audit + index, 2026-06-24. This is a navigational map of the codebase and a
> point-in-time audit. Pair it with `README.md` (deployment) and the redesign plan at
> `docs/superpowers/specs/2026-06-20-sitewide-redesign-plan.md`.

---

## 1. What this is

A server-rendered PHP web application for running continental cultural-recognition awards:
public **profiles/registry**, **nominations → voting → expert judging → winners** per award
cycle, a **Continental Power Index (CPI)** leaderboard, **payments** (paid votes, event
tickets, donations, a small shop), a **community/Pulse** layer (threads, comments, cheers),
**content** (blog, events, opportunities, a legacy vault), an **admin back-office**, and a
**judge portal**.

### Stack
- **PHP ≥ 8.4** (per `composer.json`; note README says 8.1+ — see Findings)
- **Slim 4** (routing/PSR-7) · **slim/twig-view** (Twig 3 templates)
- **Eloquent / Illuminate\Database (Capsule)** used as a **query builder** (no `Models/` dir; tables are addressed directly with the `gates_` prefix)
- **PHP-DI 7** container · **Monolog** logging · **PHPMailer** (Brevo SMTP)
- **Intervention/Image** (uploads) · **Respect/Validation** · **Symfony Console** (CLI) · **Ramsey/UUID** · **League/CSV** · **Guzzle**
- **MySQL 8** in production, **SQLite** zero-config fallback for local dev
- Frontend: vanilla JS + Alpine.js + Lottie; CSS mid-migration to a modular system

---

## 2. How to run

```bash
# Local dev (SQLite, zero-config)
composer install
cp .env.example .env          # set DB_DRIVER=sqlite and APP_ENV=development
php database/setup-sqlite.php # seeds 20 profiles, 40 nominees, 2,400+ votes, etc.
cd public && php -S 127.0.0.1:8000

# Tests
composer run test             # = phpunit, config in phpunit.xml.dist (strict: failOnWarning/failOnRisky)

# Production (MySQL) — see README.md §Quick Start
php bin/console db:migrate --with-seed-admin --with-seed-rubric
```

Document root must point at `public/`. CLI entry for jobs is `bin/console` (Symfony Console).

---

## 3. Request lifecycle & entry points

```
Apache .htaccess (rewrite → index.php; deny .env/uploads exec)
  └─ public/index.php          load env, boot Capsule (setAsGlobal), build DI container, start session, mint CSRF
       └─ middleware stack (outer → inner):
            RoutingMiddleware → TwigMiddleware → SecurityHeadersMiddleware
            → CsrfMiddleware → BodyParsingMiddleware → ErrorMiddleware
       └─ src/routes.php        the route table (the single most important file to read)
            └─ Controllers       constructor-injected via DI closures in config/container.php
```

- **Front controller:** `public/index.php` — env parsing (with malformed-file fallback), Capsule bootstrap, container init, secure session cookies (HttpOnly, SameSite=Lax, Secure on HTTPS, 7-day), CSRF token mint, `APP_BASE_PATH` subpath support.
- **Routing spine:** `src/routes.php` — all routes; route groups carry middleware.
- **DI wiring:** `config/container.php` — ~40 services + every controller registered as factory closures; Twig globals (CSRF token, session flags, flash, announcement, asset version); Monolog → `var/logs/app.log`.
- **DB config:** `config/database.php` — switches MySQL/SQLite on `DB_DRIVER`; auto-creates the SQLite file.
- **Errors:** `src/Handlers/ErrorHandler.php` — JSON vs HTML negotiation; stack traces sealed off in production (triple-gated via `Support/Environment`).

---

## 4. Directory map

```
africa-gates/
├─ public/                 web root
│  ├─ index.php            front controller          router.php  (php -S dev router)
│  ├─ .htaccess            rewrite + security        uploads/.htaccess (3-layer exec block)
│  └─ assets/              css/ (legacy + modular), js/, img/, lottie/
├─ src/
│  ├─ routes.php           ★ the route table
│  ├─ Controllers/         public controllers (Home, Vote, Nomination, Awards, Registry,
│  │                        Leaderboard, Legacy, Opportunity, Blog, Events, Guide, Partner,
│  │                        Pulse, Community, Shop, ShopCheckout, Payment, Donation, Api)
│  ├─ Services/            domain logic (see §6)
│  ├─ Admin/               back-office module: Controllers/ Middleware/ Services/
│  ├─ Judge/               judge portal: Controllers/ Services/ Middleware/
│  ├─ Middleware/          CsrfMiddleware, SecurityHeadersMiddleware
│  ├─ Handlers/            ErrorHandler
│  ├─ Console/             Commands/ (CLI) + CronLog
│  └─ Support/             Session, Html, Environment, Assets, CronGuard, Assets
├─ templates/              Twig: layout/, pages/, admin/, judge/, partials/
├─ database/              *.sql schema (MySQL + sqlite-*), migrations/*.php, setup/seed scripts
├─ config/                container.php, database.php, AfricaGATES_AppScript.gs
├─ tests/Unit/            PHPUnit (in-memory SQLite harness)
├─ docs/                  this file, redesign spec, redesign-ref/
└─ bin/console            Symfony Console entry
```

---

## 5. Route table (grouped)

Defined in `src/routes.php`. ~115 routes total. Groups and their middleware:

| Group | Middleware | Contents |
|-------|-----------|----------|
| **Public** | (global only) | Home, `/awards`, `/leaderboard`, `/registry`, `/register`, `/legacy`, `/opportunities`, `/events`, `/blog`, `/pulse`, `/nominate`, `/vote`, `/partner`, `/community/*`, legal pages, `/ping`, `robots.txt`, `sitemap.xml` |
| **`/api`** | CSRF (with explicit exemptions) | registry/awards/nominees/leaderboard/dashboard/legacy/opportunities/map-pins (reads); `otp/request`, `vote`, `register`, `nominations`, `community/{comment,cheer,activity}`, `newsletter/subscribe`, `funnel`, `guide` (writes) |
| **`/pay`** | CSRF (webhook exempt) | `init`, `callback`, `success`, `webhook` (votes & tickets) |
| **`/shop`** | CSRF | browse, `{slug}`, `checkout`, `callback`, `success` |
| **`/donate`** | CSRF | page, start, `callback`, `success` |
| **`/judge`** | `JudgeAuthMiddleware` | login/OTP, dashboard, `ballot`, `ballot/{programmeId}`, `score/{nomineeId}`, `conflict/{programmeId}` |
| **`/admin`** | `AdminAuthMiddleware` (+ `RoleMiddleware('superadmin')` on judges/admins/settings/cycle/categories) | login + magic-link (unauthed), dashboard, and CRUD for profiles, nominations, programmes/cycles, nominees, opportunities, partners, events, posts, legacy, products, media, settings |

CSRF exemptions are explicit and justified: `/api/otp/request`, `/api/vote`, `/pay/webhook`. Other `/api` writes fail-closed on same-origin.

---

## 6. Module index

### Public controllers (`src/Controllers/`)
Thin HTTP layer; delegate to services. Home aggregates stats/leaderboard/featured/legacy/events/posts (heavily cached). `ApiController` is the JSON surface.

### Services (`src/Services/`) — the domain core
| Service | Responsibility |
|---------|----------------|
| `VoteService` | Cast/validate/record a vote; OTP-bound, idempotent, transactional |
| `BonusVoteService` | Redeem paid bonus votes from confirmed donations; capped vs organic |
| `AwardService` / `NomineeScoringService` | Award programmes/cycles/categories; per-category scoring |
| `CpiService` | Pure-math Continental Power Index (no I/O) |
| `RuleEngine` | Layered rule resolution (global → programme → cycle): weights, quorum, caps |
| `FraudService` | Per-vote fraud signal scoring (pre-cast) |
| `CollusionService` | Cluster-level fraud rings (shared device/IP, timing burst) — advisory |
| `SnapshotService` | Tamper-evident hash-chained standings snapshots |
| `MilestoneService` | Vote-milestone notifications |
| `PaymentService` | Paystack + Flutterwave init/verify/webhook-signature facade |
| `CommunityService` | Threads, comments, cheers, activity feed |
| `SpamService` / `TurnstileService` | Heuristic + AI moderation; Cloudflare bot check |
| `OtpService` / `Notifier` | Email OTP + transactional/branded mail (PHPMailer/Brevo) |
| `RateLimitService` | Per-IP/action sliding-window throttle (atomic, TOCTOU-safe) |
| `QueueService` | Durable `gates_jobs` queue for slow side-effects |
| `GoogleSheetsService` | Best-effort sync to the Apps Script endpoint |
| `ProfileService`, `LegacyService`, `OpportunityService`, `EventService`, `GuideService`, `StatsService`, `CacheService` | Feature/data services |

### Admin module (`src/Admin/`)
- **Middleware:** `AdminAuthMiddleware` (session gate, fail-closed), `RoleMiddleware` (superadmin gate).
- **Services:** `AuthService` (login + per-IP/per-account lockout + magic links + session rotation), `AuditService` (`gates_audit_log`), `SettingsService` (`gates_settings` key/value), `LogService`, `UploadService` (finfo magic-byte validation + re-encode), `Validator` (Respect wrapper).
- **Controllers:** ~17 CRUD controllers (Dashboard, Profiles, Nominations, Nominees, Programmes, Judges, Opportunities, Partners, Events, Posts, Legacy, Products, Media, AwardsPage, Admins, Settings, Auth). Every mutating action is audit-logged.

### Judge portal (`src/Judge/`)
Email/OTP login → session. `JudgeService::canScore()` gates scoring on: judge active, assigned to programme, nominee on ballot, cycle in `judging` phase, no declared conflict-of-interest. Scores are per (judge, nominee, criterion), clamped 0–10; only complete scorecards count toward quorum.

### Console (`src/Console/Commands/`)
`MigrateCommand` (db:migrate), `CycleAdvanceCommand` (hourly phase advance + winner promotion), `CpiRecomputeCommand`, `CollusionScanCommand`, `AdminCreateCommand`, `CacheClearCommand`.

### Support (`src/Support/`)
`Session::rotate()` (fixation defense), `Html::sanitize()` (allowlist DOM-walk for admin rich text), `Environment` (production error-detail sealing), `CronGuard` (flock single-instance), **`Assets`** (cache-bust token — *currently an unimplemented stub; see Findings*).

---

## 7. Data model (`gates_*` tables)

No ORM models — the schema lives in `database/*.sql` and is evolved by `database/migrations/*.php`
(tracked in `gates_migrations`, idempotent, driver-aware MySQL/SQLite).

- **Identity / admin:** `gates_profiles`, `gates_admins`, `gates_magic_links`, `gates_audit_log`, `gates_admin_settings`, `gates_settings`
- **Awards / voting:** `gates_award_programmes`, `gates_award_cycles`, `gates_award_categories`, `gates_nominees`, `gates_votes`, `gates_nominations`, `gates_nomination_drafts`, `gates_otp_tokens`
- **Judging:** `gates_judges`, `gates_judge_criteria`, `gates_judge_scorecards`, `gates_judge_coi`, `gates_judge_notes`
- **Integrity / jobs:** `gates_vote_snapshots` (hash-chained), `gates_cycle_transitions`, `gates_jobs`, `gates_rule_sets`, `gates_collusion_findings`, `gates_fraud_scores`, `gates_vote_milestones`, `gates_cron_log`
- **Payments / commerce:** `gates_donations` (votes/tickets/donations), `gates_products`, `gates_orders`
- **Community:** `gates_community_*` / `gates_threads`, `gates_comments`, `gates_cheers`, `gates_activity`, `gates_moderation_log`
- **Content:** `gates_posts`, `gates_site_events`, `gates_event_registrations`, `gates_legacy_events`, `gates_opportunities`, `gates_partner_enquiries`, `gates_newsletter`
- **Infra:** `gates_cache`, `gates_rate_limits`, `gates_stats`, `gates_cpi_history`, `gates_migrations`

---

## 8. Domain deep-dives

### Cycle lifecycle
`upcoming → nominations → voting → judging → results → archived`. Nominations and voting are
**mutually exclusive**. `CycleAdvanceCommand` advances **one step per run** (so a missed cron
can't skip voting), is forward-only, logs to `gates_cycle_transitions`, and on entry to
`results` promotes winners/runners-up by **CPI rank** (subject to judge quorum).

### CPI scoring
`CpiService` is pure math (unit-tested). Category/nominee score = **45% community + 55% judges**,
scaled 0–1000. Community component normalizes over **organic votes only** (`organic_vote_count`)
— **purchased bonus votes never move rank**; they only bump the public `vote_count`. Judge side
counts **complete scorecards only**; nominees below `min_judges_per_nominee` (default 2) are
ineligible for promotion. Weights/quorum/caps come from `RuleEngine` (global → programme → cycle).
*(Profile-level leaderboard CPI and the README's "6-factor" table may have drifted from the code —
see Findings.)*

### Voting integrity
`VoteService::castVote()` runs in a transaction with a row-locked OTP token: OTP is bound to a
specific (nominee, award); idempotency keys are **scoped per voter**; one vote per (email,
category) is enforced by a UNIQUE constraint *and* a code check; the OTP is consumed **only on
success**. `FraudService` pre-scores each attempt; `CollusionService` finds rings post-hoc
(advisory, never auto-voids). `SnapshotService` hash-chains standings so any later tampering breaks
the chain.

### Payments (server-authoritative)
Three flows share an idempotent confirm pattern (`WHERE status='pending'` single-row UPDATE,
amount re-verified server-to-server before crediting):
- **`/pay`** — paid votes & tickets. Prices come from a **hardcoded `PaymentController::PRICES`**
  const. Has both browser **callback** and signed **webhook** (Paystack HMAC-SHA512 / Flutterwave hash over the raw body).
- **`/shop`** — `ShopCheckoutController::priceCart()` recomputes every line from `gates_products`
  (client cart amounts are ignored); stock decremented on fulfilment. **Callback only (no webhook).**
- **`/donate`** — donor-chosen amount, **clamped server-side** (₦200–₦5M). **Callback only (no webhook).**

### Integrations
`GoogleSheetsService` + `config/AfricaGATES_AppScript.gs` mirror registrations/nominations/votes/
enquiries to Google Sheets (best-effort, 4s timeout, enqueued off the hot path). Email via
PHPMailer/Brevo with a branded wrapper; falls back to a local log file when SMTP is unset.

---

## 9. Frontend state (mid-redesign)

An approved from-scratch modular CSS rebuild is in progress (see the redesign spec). **Both layers
ship today** and are linked in `templates/layout/gates.twig`:

- **Legacy (still live):** `main.css`, `ui-overhaul.css`, `professional.css`, `redesign-2026.css`, `aurora.css` (judge theme). Retained intentionally until Home/Methodology migrate — **do not delete yet**.
- **New modular:** `base/{tokens,reset,typography}.css`, `components/{nav,footer,loader,auth,gee}.css`, `tokens.motion.css`.
- **JS:** `main.js` (legacy core; some nav code now inert), `admin.js`, `gee.js` (live features), `afg-features.js` (Alpine state + cart).

Per the plan, auth/legal/error/status/help/support/nav/footer/loader are done; Awards/Shop/
Registry/Nominate/Vote/Judges are hybrid-done; **Home, Methodology, and Pulse remain**.

---

## 10. Testing

Harness: `tests/TestCase.php` boots an **in-memory SQLite** DB and loads the three schema files
per test (FK off, UNIQUE enforced); no fixtures, inline seeds. Run with `composer run test`
(strict `failOnWarning`/`failOnRisky`).

**Current status (2026-06-24, after the audit fixes): `Tests: 171, Assertions: 443, Failures: 0` — green.**
(The pre-fix run was 164 tests with 8 failures — all the unimplemented `AssetsTest`; see §12.) The
suite covers the critical domain: vote, CPI, bonus/paid separation, fraud, collusion, judge scoring/
quorum, cycle transitions/winners, snapshots, nominations, auth, CSRF, rate-limit, queue, cache,
milestones, environment, stats, Twig escaping, HTML sanitization, newsletter, and payment reconciliation.

**Coverage gaps:** most controllers, the community layer (comments/cheers), shop CRUD, event
registrations, registry search, and admin operations are not directly unit-tested.

---

## 11. Audit findings (2026-06-24)

Security was separately audited 2026-06-23 (posture **strong**: 0 critical/high; server-authoritative
payments, global CSRF, fail-closed authz, hashed PII, tamper-evident snapshots). The items below are
**architecture / quality / correctness**, not security.

### High
1. **Test suite is red — 8 failing tests, all `AssetsTest`.** `src/Support/Assets.php`'s three
   methods (`collect`, `latestMtime`, `version`) are `return [] / 0 / ''` **stubs** marked
   "implemented next", while `tests/Unit/AssetsTest.php` fully specifies the intended dev/prod
   cache-busting behavior. This is **abandoned test-first work**: the suite is red and
   `composer run test` exits non-zero. The class is also **not wired into `config/container.php`**
   (the `asset_version` Twig global is computed inline), so finishing it needs both implementation
   *and* wiring — or the test+class+intent should be removed. The failing tests are an exact spec
   for the fix. **Evidence:** phpunit output above.

### Medium
2. **No webhook reconciliation for shop orders or donations.** Only `/pay` (votes/tickets) has a
   signed webhook backstop; `/shop` and `/donate` confirm via browser callback only. A dropped
   callback (closed tab, flaky network) leaves the row stuck `pending` with no server-side recovery.
   Consider webhook handlers (reuse the existing pattern) or a reconciliation cron that re-verifies
   stale `pending` rows.
3. **CPI documentation drift (verify against code).** `README.md` documents a 6-factor profile CPI
   (Vote 25% / Verification 20% / Completeness 15% / Activity 15% / Legacy 15% / Reach 10%), but the
   implemented profile rollup appears to be the mean of linked nominees' 45/55 scores with a
   different baseline fallback (50/30/20). Reconcile the README with `CpiService` /
   `NomineeScoringService` so the public methodology matches the math.
4. **PHP version mismatch, docs vs manifest.** `composer.json` requires `php >=8.4`; `README.md`
   and the cPanel guide say "PHP 8.1+". A host on 8.1–8.3 will fail `composer install`. Align the
   docs (or relax the constraint to what's actually supported).

### Low / housekeeping
5. **Duplicated payment-confirmation logic** across `PaymentController`, `ShopCheckoutController`,
   `DonationController` (same idempotent confirm three times). Safe, but a candidate for a shared
   `PaymentReconciler`.
6. **`AdminsController` re-checks `role !== 'superadmin'` inline in 4 methods** instead of using the
   `RoleMiddleware` group pattern that `/admin/judges` already uses. DRY/consistency.
7. **No base admin controller** — ~17 CRUD controllers repeat index/form/save/delete + audit +
   flash boilerplate. Optional extraction.
8. **`ApiController::legacy()` / `opportunities()` instantiate services inline** rather than via DI,
   breaking the otherwise-consistent injection pattern.
9. **Stale `.gitignore` entries.** Lines 12–14 ignore a duplicate snapshot dir/zip that **no longer
   exist** in the tree (verified). Harmless; the lines can be removed.
10. **Legacy CSS cleanup is a *future* task, not now.** `ui-overhaul.css` / `professional.css` /
    `redesign-2026.css` are still linked in `gates.twig` and intentionally retained until the
    redesign reaches Home/Methodology. Revisit (with a visual check) only after those pages migrate.

### Strengths
Clean layered architecture; comprehensive DI; strong, well-tested integrity model (idempotent
voting, organic/paid separation, judge quorum, hash-chained snapshots); server-authoritative
payments; idempotent, driver-aware migrations; consistent audit logging; sealed production errors.

---

## 12. Resolution log (2026-06-24)

The findings above were addressed in the same session they were found:

- **#1 Assets / red suite — FIXED.** Implemented `Support/Assets.php` (`collect`/`latestMtime`/`version`) against its existing tests and wired it into `config/container.php` (dev now cache-busts off the newest mtime across *all* css/js, not one sentinel). Suite is green: **171 tests, 0 failures**.
- **#2 Shop webhook gap — FIXED (reconcile cron).** New additive `payments:reconcile` command (`src/Console/Commands/PaymentReconcileCommand.php`, registered in `bin/console`) re-verifies stale `pending` orders + donations server-to-server and confirms the paid ones, with the same amount-parity + idempotent `WHERE status='pending'` guarantees. It does **not** touch the audited controllers. Covered by `tests/Unit/PaymentReconcileTest.php` (7 tests). Schedule every ~10 min. *Discovery during the fix:* donations were already backstopped by `/pay/webhook` (same `gates_donations` table); only shop orders (`gates_orders`) were exposed.
- **#3 CPI doc drift — FIXED.** README's "6-factor" table replaced with the real 45% community / 55% judge model + 50/30/20 baseline + tier ladder.
- **#4 PHP version — FIXED.** README aligned to 8.4+ (matches `composer.json`).
- **#6 Admin role checks — FIXED.** Removed 4 redundant inline checks in `AdminsController` (the `/admins` route group already enforces superadmin); documented why in the class.
- **#8 ApiController DI — FIXED.** `LegacyService`/`OpportunityService` now constructor-injected.
- **#9 Stale `.gitignore` — FIXED.** Removed the dead snapshot-dir lines.
- **#5 / #7 / #10 — DEFERRED (deliberately).** #5 (centralizing payment-confirm logic) rewrites audited-secure code for cosmetics; #7 (base-controller refactor) is high-churn across ~17 untested controllers; #10 (legacy CSS deletion) would break the live site — those files are still linked in `gates.twig` and must stay until the redesign reaches Home/Methodology. Each is safe to leave and is better done as its own focused change.

**Test-harness note:** `gates_orders`/`gates_products` exist only in the shop migration, not the base `sqlite-*.sql` the harness loads, so `PaymentReconcileTest` creates them in `setUp`. Folding them into `sqlite-schema.sql` would let future shop tests use them without that step (small, optional follow-up).
