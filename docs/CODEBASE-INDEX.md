# Africa GATES — Codebase Index

> **Continental Cultural Recognition Platform · An Afrovanguard Initiative**
> Navigational map of the codebase. Originally generated 2026-06-24; **refreshed 2026-07-23**
> to cover the Batch 3 wave (contact channels, share links, webhooks, member area, community v2,
> AI-everywhere) and the follow-on admin-AI features (assistant, triage, dedup/merge, galleries).
> Pair it with `README.md` (deployment), the Batch 3 design at
> `docs/superpowers/specs/2026-07-04-batch3-design.md`, and the redesign plan at
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

Defined in `src/routes.php`. **~250 route registrations** across the groups below (up from ~115 at
the last index — the Batch 3 wave added share-link, member-account, community-write, gated-form,
forms-builder, and agent-bridge surfaces). Groups and their middleware:

| Group | Middleware | Contents |
|-------|-----------|----------|
| **Public** | (global only) | Home, `/awards`, `/leaderboard`, `/registry`, `/register`, `/legacy`, `/opportunities`, `/events`, `/blog`, `/pulse`, `/nominate` (incl. `?share={token}` prefill), `/vote`, `/partner`, `/community/*`, `/account/*` (member dashboard, sign-in/verify), `/form/{token}` (single-use gated forms), `/f/{key}` (admin-built public forms), legal pages, `/ping`, `robots.txt`, `sitemap.xml` |
| **`/api`** | CSRF (with explicit exemptions) | registry/awards/nominees/leaderboard/dashboard/legacy/opportunities/map-pins (reads); `otp/request`, `vote`, `register`, `nominations`, `nominations/share-link`, `community/{comment,cheer,activity,poll,report}`, `newsletter/subscribe`, `funnel`, `guide`, `agent/gee` (writes) |
| **`/pay`** | CSRF (webhook exempt) | `init`, `callback`, `success`, `webhook` (votes & tickets) |
| **`/shop`** | CSRF | browse, `{slug}`, `checkout`, `callback`, `success` |
| **`/donate`** | CSRF | page, start, `callback`, `success` |
| **`/judge`** | `JudgeAuthMiddleware` | login/OTP, dashboard, `ballot`, `ballot/{programmeId}`, `score/{nomineeId}`, `conflict/{programmeId}` |
| **`/admin`** | `AdminAuthMiddleware` (+ `RoleMiddleware('superadmin')` on judges/admins/settings/cycle/categories/webhooks/legal) | login + magic-link (unauthed), dashboard, `assistant` (AI copilot), `moderation` queue, `data` explorer, `forms` builder, `registrations`, `webhooks`, `legal`, `users`, and CRUD for profiles, nominations, programmes/cycles, nominees (+ galleries/merge), opportunities, partners, events, posts, legacy, products, media, settings |

CSRF exemptions are explicit and justified: `/api/otp/request`, `/api/vote`, `/pay/webhook`,
`/api/agent/gee` (bearer-authenticated inbound agent bridge). Other `/api` writes fail-closed on
same-origin. Community write endpoints (comment/thread/poll-vote/cheer/report) additionally require a
signed-in member — guests get a 401→login redirect.

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
| `OtpService` | Email OTP issue/verify (PHPMailer/Brevo, loud-fail in prod) |
| `Notifier` | Channel router — picks email/SMS/WhatsApp per available contact; branded mail |
| `SmsService` | Outbound SMS (Twilio) + WhatsApp (Meta WA Cloud / Twilio); off by default, `gates_messages` audit |
| `RateLimitService` | Per-IP/action sliding-window throttle (atomic, TOCTOU-safe); backs the invisible AI budget |
| `QueueService` | Durable `gates_jobs` queue for slow side-effects (SMS/WA retry, AI triage, sheet sync) |
| `WebhookService` | Outbound event webhooks (full catalog, HMAC-signed, `gates_webhooks`/`gates_webhook_deliveries`) |
| `GoogleSheetsService` | Best-effort sync to the Apps Script endpoint |
| **AI —** `AiService` | Pluggable provider gateway (Groq → Gemini → Anthropic → OpenAI), JSON mode, graceful degrade |
| `AiHelper` | Small typed helpers over `AiService` (classify/summarize/suggest) |
| `AiFilterService` | Plain-English → whitelisted admin list filters (nominations desk) |
| `GuideService` | "Gee" the site guide — `AiService` chain + cached `siteState()` snapshot + optional Make agent bridge; scripted fallback |
| `NominationTriageService` | Advisory review-at-scale triage (quality score + summary) → `gates_nomination_insights` |
| `NominationFeedbackService` | Closes the nominator feedback loop (every nomination gets a response) |
| `MergeSuggestionService` / `MergeService` | Detect probable-duplicate nominees; merge them (fixes vote-splitting) |
| `SpamService` / `TurnstileService` | Heuristic + AI moderation (admin-tunable thresholds); Cloudflare bot check |
| **Members / commerce —** `UserAccountService` | Public member accounts (`gates_users`): register/verify/login, distinct from admins/judges |
| `MemberActivityService` | Read-only member-dashboard aggregation (my votes/nominations/links/activity) |
| `NominationLinkService` | Shareable prefill nomination links (`gates_nomination_links`, tokened, expiring) |
| `PointsService` | Signed-delta points ledger (`gates_points_ledger`) |
| `PaidVoteService` | Paid voting business model (admin-toggleable, off by default) |
| `CurrencyService` / `ShopPricing` | NGN base prices; display conversion + regional shop tiers |
| `FormService` / `GatedFormService` | Admin form-builder engine; single-use gated forms for nominees/judges |
| `LegalService` | Editable legal/policy documents (`gates_legal_docs`) |
| `MigrationRunner` | Applies all schema files + dated PHP migrations (used by `db:migrate`) |
| `ProfileService`, `LegacyService`, `OpportunityService`, `EventService`, `StatsService`, `CacheService` | Feature/data services |

### Admin module (`src/Admin/`)
- **Middleware:** `AdminAuthMiddleware` (session gate, fail-closed), `RoleMiddleware` (superadmin gate).
- **Services:** `AuthService` (login + per-IP/per-account lockout + magic links + session rotation), `AuditService` (`gates_audit_log`), `SettingsService` (`gates_settings` key/value), `LogService`, `UploadService` (finfo magic-byte validation + re-encode), `Validator` (Respect wrapper).
- **Controllers (25):** CRUD/back-office — Dashboard, Profiles, Nominations, Nominees (+ galleries,
  merge, AI dedup scan, plain-English filter), Programmes, Judges, Opportunities, Partners, Events,
  Posts, Legacy, Products, Media, AwardsPage, Admins, Users (member points), Registrations, Settings,
  Auth — plus the Batch 3 additions: **`AssistantController`** (`/admin/assistant` AI copilot grounded
  with live read-only stats), **`AiAssistController`** (shared drafting/prose helpers used across the
  console), **`ModerationController`** (community moderation queue + AI re-check), **`WebhooksController`**
  (outbound endpoints), **`FormsController`** (form builder + submissions), **`DataController`** (read-only
  data explorer), **`LegalController`** (policy-doc editor). Every mutating action is audit-logged.

### Judge portal (`src/Judge/`)
Email/OTP login → session. `JudgeService::canScore()` gates scoring on: judge active, assigned to programme, nominee on ballot, cycle in `judging` phase, no declared conflict-of-interest. Scores are per (judge, nominee, criterion), clamped 0–10; only complete scorecards count toward quorum.

### Console (`src/Console/Commands/`)
`MigrateCommand` (db:migrate), `CycleAdvanceCommand` (hourly phase advance + winner promotion),
`CpiRecomputeCommand`, `CollusionScanCommand`, `AdminCreateCommand`, `CacheClearCommand`,
`PaymentReconcileCommand` (`payments:reconcile` — re-verify stale `pending` orders/donations),
`PrivacyPurgeCommand` (`privacy:purge` — PII retention purge, gated on `RETAIN_*`),
`PrivacyEraseUserCommand` (`privacy:erase` — right-to-erasure for a single member).

### Support (`src/Support/`)
`Session::rotate()` (fixation defense), `Html::sanitize()` (allowlist DOM-walk for admin rich text),
`Environment` (production error-detail sealing), `CronGuard` (flock single-instance),
`Assets` (cache-bust token — **now implemented + wired**, see §12), `Paginator` (shared count/fetch +
numbered pagination), `Filters` (list-filter parse/validate), `Phone` (E.164 normalize/validate for
SMS/WhatsApp), `Reference` (enterprise `AGN-YYYY-XXXXXX-C` nomination references), `Regions`
(structured country → state/region data).

---

## 7. Data model (`gates_*` tables)

No ORM models — the schema lives in `database/*.sql` (base `schema.sql` + `admin-schema.sql` +
`community-schema.sql`, with `sqlite-schema.sql` parity for the test harness) and is evolved by
`database/migrations/*.php` (tracked in `gates_migrations`, idempotent, driver-aware MySQL/SQLite).
**~65 `gates_*` tables.**

- **Identity / admin / members:** `gates_profiles`, `gates_users` (public member accounts),
  `gates_admins`, `gates_magic_links`, `gates_audit_log`, `gates_admin_settings`, `gates_settings`,
  `gates_uploads`, `gates_points_ledger`
- **Awards / voting:** `gates_award_programmes`, `gates_award_cycles`, `gates_award_categories`,
  `gates_nominees`, `gates_votes`, `gates_nominations`, `gates_nomination_drafts`,
  `gates_nomination_links` (share prefill), `gates_nomination_insights` (AI triage), `gates_otp_tokens`
- **Judging:** `gates_judges`, `gates_judge_criteria`, `gates_judge_criteria_scores`,
  `gates_judge_coi`, `gates_judge_notes`
- **Integrity / jobs:** `gates_vote_snapshots` (hash-chained), `gates_cycle_transitions`,
  `gates_jobs`, `gates_rule_sets`, `gates_collusion_findings`, `gates_fraud_scores`,
  `gates_vote_milestones`, `gates_cron_log`
- **Messaging / webhooks:** `gates_messages` (SMS/WA delivery audit), `gates_mail_log`,
  `gates_webhooks`, `gates_webhook_deliveries`, `gates_newsletter`
- **Payments / commerce:** `gates_donations` (votes/tickets/donations). Shop `gates_products` /
  `gates_orders` are defined in the shop migration (not the base schema; the test harness creates
  them in `setUp`).
- **Community:** `gates_threads`, `gates_comments`, `gates_cheers`, `gates_activity`, `gates_polls`,
  `gates_poll_votes`, `gates_reports`, `gates_reposts`, `gates_follows`, `gates_bookmarks`,
  `gates_moderation_log`
- **Content / forms:** `gates_posts`, `gates_site_events`, `gates_event_registrations`,
  `gates_legacy_events`, `gates_opportunities`, `gates_partner_enquiries`, `gates_legal_docs`,
  `gates_forms`, `gates_form_submissions`, `gates_form_tokens`
- **Infra / analytics:** `gates_cache`, `gates_rate_limits`, `gates_cpi_history`,
  `gates_funnel_events`, `gates_migrations`

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
the chain — a `UNIQUE (prev_hash)` makes a link extendable exactly once, so two concurrent captures
cannot fork the chain into something that reports itself as tampered with forever. The chain is
**read**: `bin/console standings:verify` walks it (chunked, non-zero exit on a break), and the
06:00 maintenance task `chain` runs the same walk and fails the cron run when it does not hold.

Winner promotion breaks a CPI tie on **`organic_vote_count`**, never `vote_count` — the tiebreak is
the last place money could otherwise decide an award, and a true dead heat is logged for a human
rather than settled quietly by row id (`CycleMaterialiser::promoteWinners`).

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

**Current status (2026-07-23): `Tests: 437, Assertions: 2306, Failures: 0` — green** (verified this
session with `composer run test`). The suite grew from 171 across the Batch 3 wave. Beyond the
original critical domain (vote, CPI, bonus/paid separation, fraud, collusion, judge scoring/quorum,
cycle transitions/winners, snapshots, nominations, auth, CSRF, rate-limit, queue, cache, milestones,
environment, stats, Twig escaping, HTML sanitization, newsletter, payment reconciliation), the new
tests cover: `Phone` E.164 normalization, `Reference` enterprise nomination refs, `Notifier` channel
routing, `SmsService` config gating, `NominationLinkService` (tokened prefill), `WebhookService`
catalog/signing, `AiFilterService` whitelist sanitization, member erasure, and several controller paths.

**Coverage gaps:** most Twig-rendering controllers, parts of the community layer, shop CRUD, and
some admin operations are still exercised only indirectly.

---

## 11. Audit findings (2026-06-24)

> **Historical snapshot.** These are the 2026-06-24 findings; §12 records their resolution and §13
> the subsequent feature wave. As of 2026-07-23 the suite is green (437 tests) and the High/Medium
> items below are all fixed — read this section for context, not as an open backlog.

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

---

## 13. Feature wave since the last index (Batch 3 + admin-AI · to 2026-07-23)

The design at `docs/superpowers/specs/2026-07-04-batch3-design.md` was executed in priority order,
followed by a wave of admin-AI enhancements. Standing constraints held throughout: MySQL canonical
(every table lands in `schema.sql` + `sqlite-schema.sql` parity + a dated migration), audited
vote/payment paths additive-only, integrations off-by-default and superadmin-configurable with a
graceful local fallback, no hardcoded demo values, and secrets write-only in the admin UI.

**Shipped:**
1. **Contact channels** — nominee contact is now email **or** phone; phones normalized to E.164
   (`Support\Phone`); `Notifier` routes email/SMS/WhatsApp per available contact; `SmsService`
   (Twilio SMS + Meta/Twilio WhatsApp) is off by default with a `gates_messages` delivery audit and
   `QueueService`-backed retry. Never blocks a nomination.
2. **Enterprise references** — `Support\Reference` mints `AGN-YYYY-XXXXXX-C` (Crockford base32 +
   mod-37 check char); legacy `NOM-{id}` still resolves in admin search.
3. **Shareable prefill links** — `NominationLinkService` + `gates_nomination_links` (tokened,
   expiring); `POST /api/v1/nominations/share-link`, `GET /nominate?share={token}`.
4. **Webhook catalog** — `WebhookService` dispatches the full event set post-commit (nomination/
   vote/member/donation/community/moderation/cycle/winner/share-link/partner), ids + masked labels
   only; managed under `/admin/webhooks`.
5. **Member profile autofill** — `templates/partials/member-autofill.twig` one-click fill (reversible,
   editable) across nominate/vote/RSVP forms; server passes fresh session `member{name,email,phone}`.
6. **Member area** — `/account` dashboard: my votes/nominations/share-links/activity, completeness
   meter, onboarding checklist, quick actions (`MemberActivityService`, read-only aggregation).
7. **Community v2** — guests view-only, members post/vote/cheer (401→login for guests); v3-design
   thread/comment templates; report → moderation queue; polls, follows, reposts, bookmarks; AI
   "summarize thread" + composer assist with silent fallback.
8. **AI everywhere** — `AiService` provider chain (Groq → Gemini → Anthropic → OpenAI); Gee uses it
   plus a cached `siteState()` snapshot and an optional bidirectional Make-agent bridge
   (`/api/agent/gee`, bearer-auth); `/admin/assistant` copilot grounded with live read-only stats;
   per-IP + global daily AI budget via `RateLimitService` degrades silently to the scripted tier.
9. **AI moderation** — admin-tunable `mod_threshold_quarantine`/`mod_threshold_reject`, member
   reports feed the queue, `/admin/moderation` "AI re-check".
10. **Agentic nominations** — advisory triage job (`NominationTriageService` → `gates_nomination_insights`:
    quality score, summary, duplicate detection) surfaced on the review page; never auto-decides.

**Follow-on admin-AI wave:** AI natural-language nomination filtering (`AiFilterService`,
whitelist-validated), AI-assisted duplicate scan + nominee merge (`MergeSuggestionService` /
`MergeService`, counters vote-splitting), multi-photo nominee galleries, AI profile slugs, and the
ambient admin console copilot (strict-CSP-safe vendored deps).

**Task 11 audit/index pass (2026-07-23):** full suite green (437/2306); new tables carry appropriate
indexes (`gates_messages` on created_at/status, `gates_nomination_links` unique token + expires,
`gates_nomination_insights` PK on nomination_id); this index refreshed to match. Deferred items #5/#7/#10
from §12 remain deliberately open for the reasons stated there.
