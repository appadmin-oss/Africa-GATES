# Africa GATES — Codebase Index

> **Continental Cultural Recognition Platform · An Afrovanguard Initiative**
> Navigational map of the codebase. Originally generated 2026-06-24; refreshed 2026-07-23
> to cover the Batch 3 wave (contact channels, share links, webhooks, member area, community v2,
> AI-everywhere) and the follow-on admin-AI features (assistant, triage, dedup/merge, galleries);
> **refreshed again 2026-08-22** for the interview-bot subsystem — see **§14**, which §1–§13
> predate entirely.
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
├─ deploy/cloudflare/    the Cron Trigger Worker that drives /__cron/run (see §16)
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
| **AI —** `AiService` | Pluggable provider gateway, JSON mode, graceful degrade; the ROUTE comes from `AiCapability`, and this key order is only the tail once a route is spent |
| `AiCapability` | The registry: every AI feature declared as data — tier, pinned `provider:model`, fallback ladder, budgets, advisory. The lead provider is `ai_primary` in Settings (Gemini by default); `door.name_pronounce` names OpenAI for itself and `evidence.analyse` names Gemini |
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

**Recovering votes the platform dropped.** When a vote code fails to send, the token records it
(`gates_otp_tokens.delivery_state = 'failed'`) — the platform's own admission that it let that person
down. `VoteRecoveryService` derives candidates from those records ONLY; no operator can name a person.
While voting is open it refuses and tells you to re-send instead. After the close a batch needs a
second admin's approval, passes a fraud/IP-cluster screen and a cap (25% of a nominee's verified
votes), lands as ordinary organic votes carrying `recovery_batch_id` + `otp_token_id`, is disclosed
publicly per nominee, and can be voided. The delivery failure rate — the number this feature exists
to serve, and the one that should be falling — leads `/admin/vote-recovery`, above the repair.

> **It was all true except the two parts that make it happen.** Until 2026-08-31 the only route in
> was `bin/console votes:recover`, on a deployment with no SSH — and the command cannot approve
> (its own docblock defers that to "the admin panel", which did not exist), while `apply()` refuses
> anything not `approved`. No batch could reach the tally by any route. Separately, `disclosureFor()`
> — the "disclosed publicly per nominee" above — was called by nothing, so opening a route in without
> it would have been strictly worse than the mechanism staying dead. §20.

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
8. **AI everywhere** — every feature routes through `AiCapability`'s declared pin and ladder;
   the lead provider is an admin setting (`ai_primary`, Gemini by default) and `AiService`'s own
   key order is only what remains once a route is spent. Gee uses it
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

---

## 14. The interview bot — a participant that is not a person (index pass 2026-08-22)

The largest subsystem to land since §13, and the one §1–§13 above do not mention at all.
It is what closed the gap `InterviewLive` had been honest about for months: *"the AI has no
voice in the room. Occupying a participant seat and putting audio into a Meet call needs a
persistent media process; an extension has neither, and this host has neither."*

The second half of that is still true. This is PHP-FPM on cPanel and it will never hold a
WebRTC session. What changed is that **the media process no longer lives here**.
[Attendee](https://github.com/attendee-labs/attendee) — an open-source bot that joins a
Meet, Zoom or Teams call, records it, transcribes it, and plays audio back into it — runs
on its own host, and this platform talks to it exactly the way it talks to Paystack: an API
key and a URL.

### Files

| File | What it owns |
|---|---|
| `src/Services/AttendeeBot.php` | **Transport only.** HTTP to the Attendee API, request-body construction, state normalisation. No policy. |
| `src/Services/InterviewBot.php` | **The sitting.** `dispatch` / `sweep` / `poll` / `ingestFor` / `turn` / `remove`. |
| `src/Services/InterviewVoice.php` | **The mouth.** ElevenLabs or OpenAI TTS, the clip cache, and the rules for using it. Deliberately *not* `VoiceService`. |
| `src/Services/InterviewGuard.php` | **What the bot may say**, checked before it says it — invention, drift, and harm are three different failures. Every refusal logged. |
| `src/Controllers/InterviewBotController.php` | Attendee's callback. Optional by construction — see below. |
| `database/migrations/2026_09_27_interview_bot.php` | Bot columns on `gates_interviews`. |
| `database/migrations/2026_09_28_interview_guard_log.php` | The refusal log. |
| `database/migrations/2026_09_29_interview_speak_lock.php` | One utterance at a time. |
| `tests/Unit/InterviewBotTest.php`, `tests/Unit/AttendeeBotRequestTest.php`, `tests/Unit/InterviewGuardTest.php` | 69 tests across the bot surface. |

Runbooks: **`docs/INTERVIEW-BOT.md`** (the subsystem), **`docs/ATTENDEE-ON-GOOGLE-CLOUD.md`**
(the default host — single VM, Postgres container), **`docs/ATTENDEE-GOOGLE-CLOUD.md`** (the
Cloud SQL / private-VPC variant), **`docs/INTERVIEW-BOT-HANDOFF.md`** (open items).

### Polling is the primary path; the webhook is only an accelerant

`InterviewBot::sweep()` runs off `Support/Maintenance.php:155` on the cron tick that already
exists, and recovers **everything** by asking Attendee. The callback at
`POST /api/v1/interview/bot/webhook` only makes `auto` mode fast.

That was designed for a cPanel host that cannot be relied on to receive a webhook, and it
is the reason a real bug survived unnoticed for the life of the integration: `createBot()`
had been sending **`webhook_url`**, a field Attendee does not have (it takes `webhooks`, a
list of `{url, triggers}`), and DRF drops unrecognised keys silently. Every call succeeded,
no callback was ever registered, and `auto` mode ran on the polling path the whole time.
Fixed in the merge of 2026-08-22; `AttendeeBot::buildCreateBody()` exists as a separate
public method precisely so a test can read the request body without a network.

The controller **never trusts the payload's contents.** The body names a bot; that is used
to look up a sitting and for nothing else. Everything is then re-fetched from Attendee over
an authenticated connection by the same code the cron calls. A forged body with the right
secret can cause an early poll of a real bot, and nothing else. Authentication takes either
Attendee's own HMAC signature (`X-Webhook-Signature`, preferred) or a shared secret injected
by a reverse proxy (`X-Attendee-Secret`); with neither configured the route answers **404**,
not 401, so a scanner learns nothing. A 120/min cap sits after the secret and before the
work, keyed on the endpoint rather than the caller IP — there is exactly one legitimate
caller, so a per-IP key would just invite address rotation.

### `voice_mode` is three values, not a boolean

`'off'` records and transcribes, never speaks (the default, and what an operator gets by not
thinking about the field). `'assisted'` puts a human click behind every utterance. `'auto'`
lets the bot ask and follow up on its own. An award interview feeds **55% of a nominee's CPI**
through expert judgement, so these carry genuinely different risk — which is why it is
per-sitting. `interview_voice_max` in `gates_settings` caps the whole platform underneath it;
`interview_bot_enabled` is the master switch, and turning it off withdraws live bots on the
next sweep.

**Consent is not re-invented here.** `consent_at` already gates capture via
`InterviewLive::mayCapture()`, and the bot obeys the same gate — never dispatched to a
sitting without consent, and nothing it hears is stored if consent is absent. What *is* new
is `bot_disclosed_at`, stamped by the invitation rather than by an admin ticking a box,
because a bot in the room is a materially different thing to consent to than a human taking
notes.

### Configuration

`ATTENDEE_API_KEY` · `ATTENDEE_BASE_URL` · `ATTENDEE_BOT_NAME` · `ATTENDEE_BOT_IMAGE` (checked
by its actual bytes — SVG is unsupported) · `ATTENDEE_JOIN_NOTICE` (`none` to say nothing;
blank reads as *unset* and gets the default) · `ATTENDEE_STT_MODEL` ·
`ATTENDEE_WEBHOOK_SIGNING_SECRET` · `ATTENDEE_WEBHOOK_SECRET` · `INTERVIEW_TTS_ENGINE` ·
`INTERVIEW_TTS_MODEL` · `INTERVIEW_TTS_VOICE` · `INTERVIEW_ELEVEN_MODEL` ·
`INTERVIEW_ELEVEN_VOICE_ID`. All off by default; `.env.example` carries the reasoning.

### Admin surface

`/admin/interviews/{id}/bot/{send,remove,voice,say}` — gated in the controller on
superadmin/admin/**moderator**, not superadmin-only like `/admin/judges`, on the same
reasoning as the rest of `/admin/interviews`: appointing a judge is a governance act, while
running an interview is programme work a moderator does.

### Real-time conversation is possible here — the docs used to say otherwise

`InterviewBot`'s docblock, `docs/INTERVIEW-BOT.md` and several commit messages stated that
duplex conversational interviewing is impossible on this host. **It is not**, and the claim
is corrected in the first two (the third is commit history and cannot be rewritten — this
entry stands in for it).

Attendee's **`voice_agent_settings.url`** loads a page you supply *inside an
Attendee-managed container*, streams its audio into the meeting and feeds meeting audio
back as its microphone — *"No backend worker required"*, per `docs/voice_agents.md` at
`77e990ed`, and `url_is_allowed_for_voice_agent()` in `bots/serializers.py` confirms the
API accepts it. The cPanel host would never carry an audio packet. (The *other* real-time
path, `websocket_settings.audio`, does need a backend worker — that is the one the old
text was really describing.)

**It stays off for a different reason**: that path bypasses `InterviewGuard` entirely, and
trading every grounding and protected-characteristic check for latency in the feature that
decides 55% of a nominee's score is a blocker, not a footnote. Note also that
`VOICE_AGENT_URL_PREFIX_ALLOWLIST` **defaults to allowing every URL** when unset.

### The transcript cursor is an offset, not a position

`bot_cursor` holds the highest `timestamp_ms` ingested, and line ids are
`att-{ms}-{speaker}`. It used to be an ordinal into the response array, which was unsafe
because `/transcript` reorders: it is built as
`filter(transcription__isnull=False).order_by("timestamp_ms")`, so an utterance transcribed
late inserts mid-list and shifts everything after it. That skipped a line *and* re-pointed
every id under `append()`'s dedupe. The watermark is read back with `OVERLAP_MS` of slack
because a late insert lands behind it. `AttendeeBot::parseTranscript()` is the pure half,
so fixtures can drive it without a network.

### The recording link is minted per click

`/recording` returns a presigned URL valid for **thirty minutes**. Nothing stores it: a
sitting records that a recording exists (`bot_recording_at`) and
`GET /admin/interviews/{id}/bot/recording` redirects to a fresh one. The `bot_recording_url`
column survives from the original migration and is deliberately unread — a test fails if
anything reads it again.

### Two traps that are not guessable from Attendee's docs

- **`LAUNCH_BOT_METHOD` must be left unset** on a single-VM deployment. Unset means bots run
  as Celery tasks in the worker. `kubernetes` or `docker-compose-multi-host` dispatch them to
  infrastructure that does not exist, and they *silently never launch*.
- **There is no published Docker image** (upstream CI builds with `push: false`), and the
  image is **amd64-only** — `zoom-meeting-sdk` is an x86 wheel, so an Arm machine type will
  not run it.


---

## 15. Nominee campaigns (2026-08-22)

`HANDOFF.md` §3.6/§6. The campaign copy moved out of `templates/emails/final-hours.twig`
and into `gates_email_campaigns`, so changing a comma no longer needs a deploy — the
operator has no SSH, and `/__setup/broadcast` sits beside the database migrator.

| File | What it owns |
|---|---|
| `src/Services/EmailCampaign.php` | The block vocabulary, validation, placeholder substitution, HTML and plain-text rendering. |
| `src/Services/EmailInboxGuard.php` | The inbox rules an *edit* can break, checked on rendered output. Refuses the save. |
| `src/Admin/Controllers/CampaignsController.php` | The screens. Holds the send gate. |
| `templates/emails/campaign.twig` | The skeleton — built from `final-hours.twig` so the proven structure is unchanged. |
| `database/migrations/2026_08_22_email_campaigns.php` | `gates_email_campaigns` + `_versions`. |

**Structured blocks, not a document.** A rich-text editor emits `<div>`s and inline styles
Outlook drops on the floor, and `EmailInboxCompatTest` holds twelve properties a WYSIWYG
would break in an afternoon. So the skeleton is fixed and the editor edits *fields*; every
text value is `strip_tags`'d on the way in and there is no block type that emits raw markup.
`CampaignInboxCompatTest` extends the original test so those twelve properties apply to the
new skeleton by inheritance rather than being copied.

**Link destinations are chosen from a whitelist, never typed** — `vote_url` differs per
recipient so it could not be typed anyway, and free-text URLs in a template a non-developer
edits is an open redirect with a mailing list attached.

**One definition of who gets mail.** `NomineeBroadcast` still resolves recipients, applies
`EmailOptOut` and writes `gates_broadcast_log` under its `UNIQUE(campaign, email_hash)`. A
campaign changes only three things: the log key (its slug — fixed on create, because
renaming one mid-send would re-mail everybody already done), the subject, and the body.

**The plain-text part is generated from the blocks**, not hand-written — a hand-written
alternative would keep sending the old copy after an edit.

**The order of the screens is the safety mechanism**: edit → preview → test-send → approve
→ read the plan → send, and `send` refuses unless the campaign is `approved`. Any edit
clears the approval, because an approval is of specific words. Sends in batches of 25 with
the same auto-continue the setup endpoint uses.

## 16. Scheduled work has no shell — how it is actually driven (2026-08-24)

**Read this before touching `Maintenance`, `CronHealth`, `/__cron/run`, or anything on
`/status`.** The constraint below is not incidental to this platform; several of its
oddest-looking decisions are downstream of it.

### The constraint

Production is **cPanel with no SSH**, and cPanel's own cron has not been dependable on this
account. So there are two front doors into the same orchestrator
(`src/Support/Maintenance.php`) and neither one can be assumed present:

| Door | Driven by | Notes |
|---|---|---|
| `cron/maintenance.php` | system cron | needs a shell the operator may not have |
| `GET/POST /__cron/run` | any HTTP scheduler | token-gated, `X-Cron-Token` or `?token=` |

`Support\CronGuard::acquire('maintenance', …)` is an flock single-instance lock, so both
may be scheduled at once — whichever arrives second exits cleanly with
`{"ok":true,"skipped":"another run in progress"}`. Two schedulers is the recommended
configuration, not a misconfiguration.

### The trap: 200 does not mean success

`/__cron/run` answers **`200` with `ok:false`** when the orchestrator finished but
individual tasks threw. This is deliberate and was a bug fix, not an oversight:

> An earlier version answered `500` on a partial failure. Webcron services responded the
> way they are built to — they backed off and disabled the job — so one broken task
> stopped every task that was still working. Tasks are isolated in `Maintenance::task()`
> now, the run always completes, and the status describes *what happened* rather than
> *whether anything went wrong*.

The cost of that choice: **any monitor that only reads status codes reports green through
exactly the failure it was hired to catch.** Anything you write against this endpoint must
parse the body. Likewise `Maintenance::TASK_FAILED` is `-1`, not `0` — `0` means "ran, no
work", which is the overwhelmingly common case and must not be able to hide a crash.

### The shipped driver

`deploy/cloudflare/status-worker.js` + `wrangler.toml`, documented click-by-click in
`docs/CLOUDFLARE-CRON-WORKER.md`. A Cloudflare Worker on a Cron Trigger, every 15 minutes.
It is preferred over a webcron service for three reasons, in ascending order of importance:
the token rides in a header instead of a URL that lands in access logs; it emails on a run
of consecutive failures; and **it parses the body**, per the trap above.

It does not require the domain to be proxied through Cloudflare — a Worker can fetch any
public URL.

### Why the cadence is 15 minutes

`CronHealth::STALE_HOURS` is `6`, and the refund retry ladder is 1h → 6h → 24h against a
two-hour payment in-flight window. The tick has to land well inside an hour for the ladder
to keep its shape; 15 minutes leaves room for several missed runs before `/status` calls
scheduled work stale. Changing one of those numbers without the other is how the ladder
silently stops being a ladder.

### And why `/status` history has holes rather than green

`SystemStatus::record()` is called from the maintenance tick, never from a request —
a row per visitor would make the table a traffic log and let anyone grow it by holding
down refresh. The consequence worth knowing: **a gap in `gates_status_log` is the evidence
that scheduled work stopped**, which is the one outage no self-report can cover, because
the thing that would report it is the thing that stopped. `templates/pages/status.twig`
therefore renders a missing day as a DASHED square, not a blank one and not a green one.

---

## 17. The AI time budget, and the pattern behind it (2026-08-25)

`/status` read `AI assistance · Slower than usual · 0% answering` for weeks. The cause was
that **`AiCapability::$timeout` was read by nothing.** Every capability declares one — 4s
for the classifier on the nomination submit, 20s for a thread summary, 30s for a judge's
dossier map, 120s for the document reader — and `AiGateway` never put it on the wire, so
every call ran on `AiService`'s 6s constructor default. The fourteen capabilities declaring
more than six seconds were cut off mid-generation on every request; the chain then paid six
more seconds a hop for two more hops.

Read the docblocks on `AiService::withTimeout()`, `AiService::httpPost()` and
`ProviderBreaker::isUnreachable()` before touching any of them. In brief:

- The budget is a **per-call override, consumed and restored**, not a `complete()`
  parameter: the gateway's own test double overrides `complete()` by signature, and a
  subclass with fewer parameters than its parent is a PHP fatal error. `boot()` results are
  reused inside a request, so a 120s budget left set would be inherited by a classifier on a
  form POST.
- **`HTTP 0` and `TIMEOUT after Ns` are different faults.** cURL reports code 0 for both a
  read timeout and a connection that never opened, and `ProviderBreaker` sidelines a
  provider for five minutes on that text. `CURLINFO_CONNECT_TIME` is the discriminator:
  non-zero means the handshake completed, which proves the network path. `HTTP 0` is the
  fault this host actually has (egress); a timeout means the job needs longer or a shorter
  answer.
- `CURLOPT_CONNECTTIMEOUT` is bounded **separately and tightly** (`CONNECT_TIMEOUT`).
  Without it, a capability that legitimately needs 120s to answer would also wait 120s to
  discover a blocked outbound port.

### The pattern, which is the part worth generalising

Six instances of the same shape are now known in this codebase, and every one of them
reached production:

| Declared | Reader |
|---|---|
| `AiCapability::$model` | read into the audit log only, until `route()` was passed |
| `AiCapability::$timeout` | **none** |
| `TicketLinkService::prune()` | **no caller** — every dead link was permanent |
| `gates_ai_calls.error` | **none** — the admin console rendered a count and no cause |
| `gates_judge_orientation.status = 'failed'` | **none** — a broken dossier was retried for ever |
| `gates_status_log.components_json` | **none** — the page could say "something broke on the 14th" and not which thing |
| `gates_interviews.bot_disclosed_at` | **none** — see §19.1 |
| `gates_nominee_submissions.skipped_json` | **none** — see §19.2 |
| `gates_nominee_submissions.reminded_at` | **none** — see §19.3 |

Each was written carefully, documented honestly, and inert. On a deployment with no shell
this is the most expensive class of bug available, because the symptom always looks like
something else: a status page reporting flaky providers, a link that expired early, a
feature that "randomly" costs money. **When you add a field to a declaration here, grep for
a reader before you believe it.** `AiModelDelegationTest` exists because the first row of
that table shipped twice.

---

## 18. Three things that were configured correctly and did nothing (2026-08-25)

§17's pattern is "a declared field with no reader". This section is its sibling: **a
mechanism with no route in.** Each of these was complete, documented, and unreachable — and
each reported itself accurately while being unreachable, which is what made all three
survive.

### 18.1 The Google secret could only be set in a file nobody can open

`GoogleMeetService::boot()` read `GAS_URL` and `GAS_SECRET` from `.env` and nowhere else.
There is no SSH on production (§16), so the only way to configure the calendar was to edit
a file the operator cannot reach — and every screen said so *correctly*: the judging
schedule said the calendar could not be checked, the interview screen offered a paste box,
and Settings said "set `GAS_SECRET` in `.env`". All true, all unactionable. The integration
read as a deliberate manual workflow rather than a dead one.

Both values now resolve **`gates_settings` first, `.env` as the fallback** — the pattern
`AiService::boot()` already used — via `GoogleMeetService::gasUrl()` / `::gasSecret()`.
`GoogleSheetsService::boot()` uses the same resolver: Sheets and Calendar are two actions on
**one** Apps Script deployment, and two resolvers for one value is how the two halves come
to disagree about whether it is configured. `/admin/settings` has a field for each; the URL
is echoed (a stale `/exec` address is the commonest way this half-works and is invisible if
the field is blanked), the secret is write-only. A malformed URL is **refused and reported**
rather than stored, because a bad `/exec` fails silently: curl returns nothing and every
action says "the Apps Script did not answer".

`GoogleSettingsSourceTest` covers the precedence, the blank-row fall-through, and that the
on-screen advice names a place the operator can reach.

### 18.2 `<form>` inside `<form>` — three buttons posting to the wrong route

The HTML parser holds a *form element pointer*. A `<form>` start tag arriving while that
pointer is set is **ignored** — not nested, not errored: dropped. Its children survive and
are adopted by the outer form. So the markup renders, the button is styled and enabled and
in the right place, and it posts to the **outer** form's action. No console warning, no
validator in the pipeline, and the server sees a well-formed request to a route that exists.

Three shipped:

| Screen | Button | Where it actually posted |
|---|---|---|
| Settings | "Check the sync" | `/admin/settings` — saved the page, returned no probe rows, which is indistinguishable from a Google integration that cannot be reached |
| Programme cycle | a category's "Delete" | the category **update** route — pressing Delete saved the category, after the confirm said yes |
| Questionnaire | "Copy them in so I can edit them" | the outcomes **save** route, with none of the derived rows in the body |

All three now use `formaction` on the submit button. `NestedFormTest` scans every template.

**And the JS half:** `form.submit()` ignores the submitter, so a `data-confirm` button
carrying a `formaction` would confirm and then post to the form's own action anyway — the
same bug wearing a dialog. Both confirm handlers in `public/assets/js/admin.js` use
`requestSubmit(submitter)`; the submitter is captured before the dialog opens, because the
event is long finished by the time it is answered.

### 18.3 The extension could not be obtained, and would not have worked if it had been

Two independent blockers, either sufficient on its own.

**No route served it.** The interview screen said "Load unpacked → the `extension/` folder
from the upload". Nothing served that folder: it sits outside the web root — correctly, a
browsable directory of extension source under `public/` is one anybody can enumerate — and
there is no SSH. `GET /admin/interviews/extension.zip` now builds it
(`InterviewExtension`), and the screen has a download button.

**The host was hardcoded in four files.** `manifest.json`'s `host_permissions`, and
`DEFAULT_BASE` in `worker.js`, `popup.js` and `popup.html`'s placeholder. The manifest one
is the dangerous one: `host_permissions` is what makes the service worker's fetch a
*privileged extension request*. Pointed at the wrong host, every call is an ordinary
cross-origin fetch blocked outright — and **typing the right address into the popup does not
help**, because the popup sets `DEFAULT_BASE`, not the permission. The panel then reports
"Could not reach the platform" from inside a live interview and nothing in Chrome names the
manifest. The host is injected at download time from the request's own URI, anchored to the
`SOURCE_HOST` literal rather than a URL pattern so a file that stops containing it is
reported rather than silently shipped. `README.md` is deliberately **not** rewritten: it
names the committed host while warning what happens if you load the repo folder directly.

**And the content script gave up before it started.** `content.js` opened with
`if (!CODE) return;` — where `CODE` came from the URL. A content script is injected once per
document load and Meet is a single-page app: the ordinary way into a call is to open
`meet.google.com`, find the meeting in the list and click it, which is a history navigation.
The script had already run, at a moment when the path was `/`, and it returned permanently.
The panel never appeared in exactly the tab it was needed in. Pasting the key again did
nothing; reinstalling did nothing; only opening the call URL in a fresh tab worked, and no
screen said so.

It now reads the URL on a 1s interval — polling rather than a history hook, because patching
`History.prototype` from a content script reaches only the isolated world's copy and
`popstate` misses `pushState` entirely — mounts the panel when the tab is in a call, and
reconnects when the tab moves to a different one. That made `connect()` re-entrant, which
broke three things written on the assumption it ran once: `hunt()` self-schedules (guarded),
`observe()` never disconnected the previous `MutationObserver` (every caption line queued
twice), and the pending buffer would carry one call's captions into another interview's
transcript (cleared).

### 18.3a The tier colour picker — the same pattern, third instance

`EventTierPalette` shipped six named slots, a redmean separation pass so no two swatches read
as the same colour on any accent, and a per-swatch `edge` guaranteed to clear 3:1 against
white. `2026_09_17_tier_colour.php` created the column. The printed ticket read it.
`TicketTierColourTest` asserted that changing an event's accent moves the tier's colour with
it — the whole point of storing a slot.

**Nothing in the admin could write it.** No field on the event form, and `saveTiers()` did not
read one. So `gates_event_tiers.colour` was NULL for every tier on the platform, `forTier()`
returned null everywhere, and every surface fell back to a default — which is what made the
registration card's selection light sweep the platform green for everybody, matching a colour
nobody could set.

The event editor has the picker now (a select per tier row plus a resolved swatch strip, a
delegated `data-ag-do="tier-colour"` listener for the live dot, `OptionalColumn`-guarded
because the column is on its own dated migration and writing an absent column inside
`saveTiers()`' try/catch loses the whole tier silently).

**And `EventTierTone::hues()` returns two values, not one.** `hue` is `fill`: the identity —
the picker's swatch, the ticket's dot, the light. `edge` is the darker variant and belongs
only to what owes 3:1 (WCAG 1.4.11) — a border, the radio's ring, the rim that holds after
the arc has gone. The light was `edge` at first, which was the right instinct applied to the
wrong element: it is `aria-hidden` and decorative, owes nothing, and painting it with a
darkened derivative showed the organiser a colour they had not chosen.

### 18.4 The interview screen, grouped by phase

`templates/admin/interviews/show.twig` was eleven sections, all open, six screens of
scroll, ordered by the life of a sitting — so whichever moment you opened it in, most of it
addressed a different one. Someone pasting a transcript scrolled past the panel picker, the
invitation, the guest list, the question pack, the extension key and the recording bot;
someone opening it an hour before the call scrolled past the transcript box and
**"Close the sitting"**, which is not reversible and was last on the page.

Three `<details>` groups now — before / during / after — and the one matching the sitting's
state starts open. `<details>` and not a tab strip: the admin CSP has no `'unsafe-inline'`,
a tab strip is unopenable without script, and it breaks find-in-page. A transcript beats a
`live` status, because someone catching up after the call belongs in the last group. Each
group's heading carries a fact about *this interview* ("no joining link yet", "3 caption
lines captured", "in the judges' dossier") rather than a count of the sections inside it,
which would be a fact about the template. `InterviewScreenPhaseTest` renders all three
states, because `phase` is set at template scope and a Twig `set` that fell inside a block
would render as `null` in all three tags with a clean 200.

---

## 19. The column sweep (2026-08-30)

§17 says to grep for a reader before believing a declaration. This was that grep, run over
the whole schema rather than one file: the fully-migrated database dumped (140 tables, 1745
columns) and every column name searched across `src`, `templates`, `cron` and
`public/assets/js`.

Eight columns had no reader. One, `gates_interviews.bot_recording_url`, is a **deliberate**
vestige — Attendee's download URL expires in thirty minutes, so it is minted on demand and
a test enforces that nothing reads the stored one. Four are unbuilt features and are listed
at the end. Three were the real thing: a behaviour this index describes, with no code.

The distinguishing question, worth carrying into the next sweep: **is there prose somewhere
promising what this column does?** A column nothing has claimed for is a vestige. A column
the documentation, a migration comment, or a screen has already promised is a lie with a
schema behind it.

### 19.1 `gates_interviews.bot_disclosed_at` — the consent record that did not exist

§14 of this file says the disclosure is "stamped by the invitation rather than by an admin
ticking a box, because a bot in the room is a materially different thing to consent to than
a human taking notes."

It was not stamped by anything. The literal string appeared in no file under `src`,
`templates`, `cron` or `public`.

The invitation *did* say the sitting "may be recorded and written down" — that is the
consent that gates capture, and it worked. What it never said is that a **participant would
join to do the recording**. Somebody who opens a Meet link and finds an unnamed stranger
already in the list has been surprised in a conversation about their own work.

`InterviewService::botDisclosure()` now writes the sentence, gated on the recorder being
both switched on and configured — a paragraph about a participant who never arrives is its
own small dishonesty — and `invite()` stamps the column only when the sentence really went,
never overwriting it, because a stamp with no sentence behind it is a consent record that
lies and the fact worth keeping is when the person was **first** told. The operator screen
renders both states, gated on `iv.bot.available`, which is the one resolver for "a bot will
attend".

### 19.2 `gates_nominee_submissions.skipped_json` — the decline that rolled off the log

Its migration named its own harm: kept "so an operator reading it later can tell 'not
asked' from 'asked and skipped' — two very different silences, and a dossier that conflated
them would let a panel read an absence as a refusal."

A decline lived only in `chat_json`, and `QuestionnaireChat::store()` keeps the last **120**
turns. So it had two live consequences:

- **The conversation forgot.** A question declined early in a long conversation slid off the
  front of the window, and `nextQuestion()` offered it again — pressing somebody about the
  one thing they had said they would rather not discuss.
- **The panel could not tell.** The dossier printed "not answered" whether the nominee
  declined outright or simply ran out of evening. Nothing on that page is weighed harder
  than a refusal to answer.

The turns are a **transcript** and truncate like any log; the decline is a **decision** and
has to keep. `QuestionnaireChat::declined()` is the single reader that merges the two, so
nothing else has to know the fact lives in two places.

### 19.3 `gates_nominee_submissions.reminded_at` — nobody warned, and then removed

The sharpest of the three, because the missing reader was not a dormant column: it was the
reason a whole warning did not exist.

`QuestionnairePolicy::enforce()` **removes a nominee from an award** for not answering. It
runs unattended out of the 06:00 maintenance pass, on a host with no shell. The only message
that nominee had ever had was the invitation — one email, months earlier, to an address the
**nominator** typed, which can be wrong, spam-filed, or sent to a job they have since left.

The invitations screen already carried the diagnosis in a hint: *"Try sending to them again
first — most of these are people who never saw the email."* It had the sentence and no
mechanism.

`QuestionnaireReminders::sweep()` warns at 14, 5 and 1 days before the deadline plus its
grace — one message per mark, capped per tick, never to somebody not yet invited or already
answered — and stamps the column. `enforce()` now **holds back** any row with no stamp.
Held, not forgiven: the next sweep warns them, a later run takes them.

Three details worth keeping:

- **The sweep runs before the rule, in the same pass.** Ordering, not tidiness: warn second
  and every first warning costs a day. `QuestionnaireRemindersTest` asserts the order.
- The guard is wrapped in `OptionalColumn::on()`, so a database that has not run the
  migration behaves as it did rather than refusing to enforce anything at all.
- The screen no longer hides its whole card when nobody can be taken yet. An empty screen
  reads as "the rule found nobody", which is the opposite of what is true.

### The four that are vestiges, not faults

Listed so the next sweep does not re-derive them. Each is a column for a feature that was
never built, and none of them makes a false statement today:

| Column | What it was for |
|---|---|
| `gates_legacy_events.video_url` | no legacy-event video surface exists |
| `gates_nominations.show_nominator` | an attribution opt-in for a public nominator credit that does not exist — the nominator appears only on admin screens and in mail to themselves, so nobody is named without consent |
| `gates_nominee_claims.revoked_reason` | there is no claim-revoke path at all; `revoked_at` is unused beside it |
| `gates_nominee_evidence.verified_by` / `verified_at` | the inverse shape — `verified` **is** read (it drives the judge's "nothing independently checked" coverage line) but nothing sets it, so that line is permanently true. Honest by default, and the screen's own doctrine says verification means somebody outside this platform checked it |

---

## 20. The mechanism nobody could reach, and the disclosure nobody could read (2026-08-31)

§19 swept the columns. This is the same sweep run over **public methods**: 2360 of them, each
searched for a call site across `src`, `templates`, `cron`, `config`, `database`, `public` and
`bin`. Three had none anywhere, thirty-three were called only from their own tests. Most of the
thirty-three are harmless — a convenience wrapper, a test seam, a PSR-15 `process()` that is
invoked by dispatch and never by name.

One was `VoteRecoveryService::disclosureFor()`, and pulling on it found three faults stacked.

### 20.1 The whole feature was unreachable

`VoteRecoveryService` mints votes for people whose vote code **this platform failed to deliver** —
derived from our own delivery log, never from a list anybody types. It is careful and thoroughly
proven: `VoteRecoveryTest` holds its derivation, its cap, its two-person rule and its reversal
twenty-three ways.

Its only route in was `bin/console votes:recover`. **There is no SSH on this deployment.**

That is the fault `VoteDeliveryController` was written to fix, and its docblock says so in as many
words: shipping an incident repair as a shell-only tool "repeated a mistake this project had already
made and already fixed once". `votes:proof` and `votes:remint` were brought into the browser.
`votes:recover` was left behind.

### 20.2 And it could not have worked from a shell either

The command's own docblock: "`approve` happens in the admin panel where the approver is an
authenticated person, because identity on a shell is not evidence." There was no admin panel.
`apply()` refuses any batch not `approved`. So the mechanism was not merely awkward to reach —
**no batch could reach the tally by any route at all**, and the two-person rule was enforced against
a door that did not exist.

### 20.3 The control the doctrine calls the strongest one was a method nothing called

The service lists six controls and says none is ceremony. Five were enforced in code. The sixth —
"public disclosure of every applied batch" — was `disclosureFor()`, with no caller. The batch
reference column's own migration comment says it is "**printed on the public disclosure** so a
reader can name the thing that put these votes on the tally and ask about it", and §on the CPI
above stated as settled fact that a batch "is disclosed publicly per nominee".

This is why the fix could not be the admin panel alone. **Opening a route in without publishing its
use would have been strictly worse than leaving the mechanism dead**: a way to add votes to a public
tally, quietly. `/admin/vote-recovery` and the disclosure on the nominee's own ballot page shipped
in the same change, and `VoteRecoveryReachableTest` holds both halves in one file for that reason.

Three details worth keeping:

- **Every step is its own POST.** A single form with a mode would invite one person to walk the
  workflow end to end and discover only at the last press that the approver must be somebody else.
- **The screen explains the refusal rather than hiding the button.** A control that simply vanishes
  teaches nobody that a second person is required; the operator concludes the page is broken.
- **The disclosure is omitted at zero, not zeroed.** A permanent "0 recovered votes" would be noise
  on every nominee page on the site in order to be honest about none of them.

### 20.4 The rest of the sweep, so nobody re-runs it

Thirty-five other methods had no production caller. Most claim only what they compute —
a helper waiting for a caller is not a lie — and several are correctly labelled test seams
(`CronGuard::releaseAll()`, `DisposableEmail::flushCache()`, `SpamService::resetThresholdCache()`)
or PSR-15 `process()` methods invoked by dispatch and never by name. Two more were real:

**`GatewayEventLog::everReceived()`** called itself "the single most useful diagnostic on this
platform" and named its own harm: an empty table after a week of live payments means the webhook
URL or the signing secret is wrong, "which presents to a buyer as *I paid and nothing happened* and
to an operator as nothing at all." Nothing called it. It now leads `/admin/payments/ledger` through
`health()`, which is a strictly better reading than the bare question: **"never" is the loud
failure, and the quiet one is a feed that worked for months and stopped** because a signing secret
was rotated — that site has received deliveries, so the bare question answers *yes* and everything
looks well. The card also says plainly that a site with no sales looks identical to a broken
webhook, because a diagnostic that alarms on every fresh install is one operators learn to scroll
past. `health()` calls `everReceived()` rather than re-deriving it: two readers of one fact is how
the halves of a diagnostic come to disagree, and this one is a diagnostic about disagreement.

**`PaidVoteService::votesPer1000()`** was a trap rather than a gap. Deprecated, and its docblock
said it "survives only so `2026_07_31_vote_tiers_from_bundle.php` can read what a live site had
configured" — but that migration reads the setting **raw**, deliberately, and routing it through the
getter would have been a bug: the getter substitutes `DEFAULT_PER_1000` when the setting is absent,
so a site that never configured a bundle would have been migrated to a translation of a bundle
nobody set. The method and its constant are gone and the migration now says why it must stay raw.

The lesson generalises past this one: **a docblock naming a caller is a claim to verify, not a
reason to keep the code.** This one named a caller that did not exist, and had anybody made the
claim true the pricing would have broken.

### The question this sweep turns on

§19's was "is there prose somewhere promising what this column does?". The method sweep's is
narrower and sharper: **what does this method's own docblock claim about the world?**
`disclosureFor()` said "Published, because the strongest control on a mechanism like this is not any
of the approvals — it is that using it cannot be quiet." A method whose docblock describes a
property of the running system, with no caller, is that property being false.

---

## 21. The MySQL/SQLite sweeps (2026-08-31)

`CLAUDE.md` opens by calling the dev/production driver split "the single most productive
source of bugs in this codebase". §19 and §20 swept for things with no reader; this sweeps
for things SQLite forgives.

**Two came back clean, and the null results are worth recording so nobody re-derives them.**

- **`ONLY_FULL_GROUP_BY`** (MySQL's default since 5.7, and no such rule in SQLite). All 48
  `groupBy()` sites select only grouped columns plus aggregates. Grouping by a select alias
  — `groupBy('d')` against `substr(created_at,1,10) AS d` — is a MySQL extension and is
  fine.
- **Driver-specific SQL in raw expressions.** 475 raw-SQL strings scanned for `strftime`,
  `julianday`, `DATE_FORMAT`, `CONCAT`, `NOW()`, `RAND()`, `REGEXP` and the rest: none. The
  codebase disciplines its raw SQL to the intersection of both dialects, and
  `AnalyticsService::dayExpr()` is where that discipline is written down.

### 21.1 The third came back positive: integer widths

**SQLite ignores integer widths entirely.** A column declared `SMALLINT UNSIGNED` stores
100000 without complaint; MySQL in strict mode rejects the statement with *Out of range
value for column*. So a form field that is lower-bounded and not upper-bounded is green
here, green in review, and broken in production alone — for whoever typed the long number,
with an error naming a column rather than a field. `CLAUDE.md` records that this already
cost a `sort_order` once.

The schema declares 44 non-boolean narrow integer columns. Every admin-settable one is
clamped — `max_len` at 8000, `max_turns` at 200, `grace_days` at 365, `guest_quota` at 500,
`failed_attempts` reset to 0 at five — **except two**, which were `max(1, (int) $input)`:
`gates_shop_codes.max_per_email` and `gates_event_codes.max_per_email`, both
`SMALLINT UNSIGNED`. Their `max_uses` neighbours had the same shape against `INT UNSIGNED`.

`Support\ColumnRange` now holds the MySQL maxima and both writers clamp through it.
Two decisions in it are deliberate:

- **It clamps to the column, never to a product limit.** "Nobody needs a code usable 65,535
  times by one address" is true and is not the schema's business; an invented ceiling is a
  second opinion that drifts the day somebody widens the column.
- **`fits()` exists beside `clamp()`** because clamping is only right where a value past the
  ceiling means the same thing as the ceiling. For a price or an ordered quantity the
  magnitude carries meaning and the path should refuse.

`ColumnWidthTest` reads the declared type **out of the migration** rather than asserting
≤ 65535: the invariant is that the clamp and the column agree, so widening a column fails
the test and says the clamp beside it is now wrong, instead of leaving a ceiling nobody can
find a reason for.

One detail from writing it, because the next schema reader will hit it. A dated migration
holds **both** branches in one file, sqlite first — so a parser that stops at the first
`CREATE TABLE <name>` reads the branch whose widths are fiction, and `INT` without a `\b`
matches the leading three letters of the sqlite branch's `INTEGER`. The first version of
this test did both, reported `max_per_email` as `INT`, and would have blessed a clamp four
orders of magnitude too generous. **A schema reader that reads the wrong branch is worse
than none.**

---

## 22. The whole-schema column sweep (2026-09-01)

§17 and §19 were run against a handful of tables somebody already suspected. This is the
same question asked of **every** column: 141 tables, 1,759 columns, is anything written
here ever read back?

**76 came back.** Two were fixed the same day and are §22.1; the rest are listed below so
nobody re-derives them, and so the next person picks the one that matters rather than the
one they happened to open.

### The detector, and the way it lies if you let it

A column is a candidate when it appears as `'col' =>` somewhere in `src/` and **never** as
a read: a `where`/`value`/`orderBy`/`pluck`/`groupBy` naming it, a `$row->col`, a
`$row['col']`, a mention inside `DB::raw`/`selectRaw`/`whereRaw`, or any occurrence at all
in a template.

The first version of that read pattern included `->select(` in the alternation. `select(`
appears in nearly every query, so every column matched something and the sweep reported a
confident **zero**. It was caught by re-running it with a known reader removed from the
haystack and checking the column came back — which is the only way to trust a detector
that reports good news. **Validate the sweep before you believe the sweep.**

It is also too slow to run per-column against the corpus. Tokenise once into two counters
and look each column up; the naive form takes minutes and times out.

### 22.1 The two that sat under a control

`gates_otp_tokens.delivery_error` and `gates_vote_recovery_rows.reject_reason`. The
two-person recovery review could see that 412 codes failed and never **what** failed —
and a full mailbox, an address the provider rejected, and a four-minute outage of ours are
three different decisions about whether to recover a vote. Both now render on the batch
review, grouped and counted rather than listed. See §20 for why that review is the control
this platform leans on hardest.

### 22.2 The other 74, ranked by what a person is owed

Nobody is *harmed* by these today. They are recorded facts that reach no screen, so the
question each one answers is unanswerable:

- **Who did this?** — `updated_by` on `gates_settings`, `gates_legal_docs`,
  `gates_email_campaigns`, `gates_questionnaire_policy`, `gates_shortlist_rules`,
  `gates_stand_presets`; `decided_by` on stand applications; `vetted_by` / `vetted_at` on
  partner orgs; `published_by` / `withdrawn_by` / `withdrawn_at` on shortlists;
  `voided_by` / `voided_at` on recovery batches; `uploader_id` on uploads and support
  attachments. An audit trail that exists and is shown to nobody is not an audit trail.
- **Why did this not arrive?** — `gates_otp_tokens.delivery_at`,
  `gates_messages.to_hash`, `gates_webhooks.last_event_at`.
- **What was said about it?** — `review_note` on submissions and org campaigns,
  `outcome_note` on interviews, `dispute_note` on claims, `refund_note` on orders,
  `consent_note` on nominee interviews.
- **Vestiges** — `gates_media_migrations.target_table` / `target_column`,
  `gates_phase_drift.phase_allows` / `would_allow`, `gates_events.actor_type`,
  `gates_form_submissions.form_id`. Nothing has ever claimed for these.

`gates_sms_optout.phone_masked` is on the list and is a special case: its own docblock
promises a support desk can use it to answer "am I still getting these", and there is no
opt-out screen at all. That is §19's question answered yes — prose promising behaviour
with nothing behind it — and it is the next one worth doing.

### The question this sweep turns on

The same one §19 named, and it held for all 76: **is there prose somewhere promising what
this column does?** A column nothing has claimed for is a vestige and costs nothing to
leave. One a docblock, a migration comment or a screen has already promised is a lie with
a schema behind it.

---

## 23. The record everything writes and nothing could ask (2026-09-02)

§17 is a column nothing writes to. §22 asked it of all 1,759 columns. This is the same
question's mirror image, and the sweeps could not see it because every counter said the
data was fine: **a table 124 places write, that nothing could ask a question of.**

### What was actually there

`gates_audit_log` is written from 124 call sites across every admin controller — around a
hundred distinct actions, each carrying the admin, the target type and id, a JSON `meta`
naming what changed, a hashed IP and the user agent. As a *record* it is close to
complete. Nothing in it was missing.

It had two readers:

1. **`AuditService::recent(12)`** on the dashboard. Twelve rows. On a busy morning that is
   under a minute of activity, and there was no way to see the thirteenth.
2. **`/admin/data/audit-log`**, the generic table browser. It dumps the raw columns: the
   admin is `7`, the target is `412`, and the free-text search covers the `action` string
   alone. `meta` — the column holding *what changed* — is not in its list. And `ip_hash`
   ends in `_hash`, so `DataRegistry::isHidden()` strips it from the detail page **and the
   CSV export**: it has never been rendered anywhere, in any form, since the table shipped.

So on a host with no shell, every question anybody actually brings to an audit log was
unanswerable. What has this admin been doing. Everything that ever happened to this
nominee. Who changed the payment settings last month. Was that run from the same machine
as the rest of their session. Each fact was on disk. None of them could be reached.

### The sharper question this adds

§17 asks *is anything reading this column*. §22 asks *is there prose promising what it
does*. Both would clear `gates_audit_log` — 124 writers, two readers, a docblock that
describes precisely what it stores and stores it.

The question that catches it is about **shape**, not existence:

> **Can the only reader answer the question the data was collected for?**

Twelve unfiltered rows is a reader. It is not a reader of a log. A generic table dump is a
reader. It is not a reader of a *relational* record, because the columns that carry the
meaning — who, what, on which thing — are foreign keys, and a dump renders them as
integers. Both readers pass a grep. Neither closes the loop.

### What was built

- `Admin\Support\AuditTargets` — 50 target types across ~40 tables resolved to names, in
  **one batched query per type present on the page**, never one per row. Every lookup
  degrades to the bare `type #id` the log already had: a target deleted two years ago is
  the audit trail working, not failing.
- `AuditService::search()` — the one query builder. Filters on admin (including the
  unattributed rows, which had no way to be selected), area, action, target, date range
  and free text; pages; counts before the limit so the screen can say *3 of 41,220*.
- `AuditService::facets()` — the filter lists built from the log itself. A hardcoded list
  would go stale the first time somebody added a controller, and a filter that silently
  omits an action is how you conclude something never happened.
- `AuditService::actorSummary()` — volume, span, and **distinct networks**, which is the
  thing `ip_hash` was recorded for and had never been counted.
- `/admin/audit`, `/admin/audit/actor/{id}`, `/admin/audit/on/{type}/{id}`. Same roles as
  the raw dump it replaces, so this adds a reader and changes nobody's access. Read-only,
  and deliberately **not** audited itself: a log that records being read fills with rows
  about itself and buries what somebody opened it to find.
- The dashboard strip now calls `search()`. It would have been less work to leave its own
  query alone, and that is exactly how two readers of one table come to disagree — the
  strip would keep printing `nominee #412` while the screen resolved it, and nobody would
  know which was right.

### Two defects only a reader could find

- **One subject filed under two names.** `gates_site_events` is written as `'site_event'`
  by `EventsController` and `'event'` by `StandsController`; `gates_settings` as both
  `'settings'` and `'setting'`. So one event's history sits in two buckets, and anybody
  filtering for it sees roughly half depending on which word they picked — a *complete
  looking* answer that is wrong, which is worse than none. Reconciled in
  `AuditTargets::ALIASES` rather than by rewriting the rows: the log is the record, and a
  record edited to look tidier is not a record.
- **The dashboard printed stored UTC raw.** Same fault as the tickets screen: an operator
  in Lagos was shown a time an hour out with nothing on the page naming a zone. It is
  `|when` now.

### And the reader found a shipped bug in the writer

Building the per-admin view meant asking what an "unattributed" row is, and the answer was
that on production **there are none, because they were all refused.**

`gates_audit_log.admin_id` carries `fk_audit_admin` to `gates_admins(id)` — in the base
schema, both drivers, since the table shipped. There is no admin with id 0. And **71 call
sites** pass `(int) ($_SESSION['admin_id'] ?? 0)`, so every action taken without a live
session inserts a `0`:

```
mysql> INSERT INTO gates_audit_log (admin_id, action) VALUES (0, 'votes.deliver');
ERROR 1452 (23000): Cannot add or update a child row: a foreign key constraint fails
```

`AuditService::record()` wraps the insert in a catch whose comment reads *"Audit failures
must never break the app"*, which is correct and is also why nobody found out. So the
audit log has been failing at precisely the moment it matters most: **the action nobody
was logged in for** — scheduled work, the console, a session that expired between the page
and the post.

It was doubly invisible. The harness runs `PRAGMA foreign_keys = OFF` (`TestCase`), so the
suite has always been green; and an audit write is deliberately forbidden from surfacing
an error, so production could not report it either. Neither the §17 sweep nor the §22 one
could see it: the column has 124 writers and a reader, and every counter said it was fine.

**Fixed in `record()`, not at 71 call sites.** 0 was never an admin id — it is the "no
session" sentinel `?? 0` leaks, and NULL is what the column already means by it. Pinned by
asserting the **stored value**, so it holds on SQLite too, where the constraint is off and
the wrong value sails straight in.

**No repair migration, and nothing to repair.** The FK has been on the table from the
start on MySQL, so no `admin_id = 0` row was ever written there. The rows that should have
existed do not, and nothing here can invent them — stated plainly rather than papered
over, the same as `2026_12_01_sms_optout_mask_widen.php`.

### The LIKE escape, which is a MySQL/SQLite trap pointing the wrong way

Filtering by area needs `action LIKE 'stand_call.%'`, and several areas contain an
underscore — `stand_call`, `vendor_policy`, `stand_type`. Escaping it the obvious way
gives `LIKE 'stand\_call.%'`, and **MySQL's default LIKE escape is a backslash while
SQLite has none at all**. Measured on both engines here:

| form | MySQL | SQLite |
|---|---|---|
| `LIKE 'stand\_call.%'` | 1 | **0** |
| `LIKE 'stand!_call.%' ESCAPE '!'` | 1 | 1 |

So the naive form works on production and silently returns nothing in dev and in the
suite. That is §21's divergence running backwards, and it is the more dangerous direction:
the failure looks like the feature simply not working, so somebody "fixes" a filter that
was never broken where it runs.

Spelling the clause out is the fix, but **not with a backslash**: `ESCAPE '\\'` is one
character to MySQL and two to SQLite, which does not process escapes inside string
literals, and `ESCAPE '\'` is an unterminated literal to MySQL. `!` needs no escaping in
either dialect and is a wildcard in neither. See `AuditService::like()`.

### Tests

`tests/Unit/AuditLogReaderTest.php` (23) and `tests/Unit/AuditScreenRenderTest.php` (19).
**Eleven mutations were run against them and all eleven were caught**: the naive LIKE
escape (0 rows on SQLite), `aliasesOf()` returning only what was asked for, `recent()`
growing its own query, the bare end-date bound not extended to end of day, a wrong table
name in the target map, `record()` no longer normalising the sentinel, the summary growing
its own admin predicate, the hidden action field dropped from the form, the area's actions
not rendered, the inert admin control left on the per-admin view, and the target index
removed from both the schema and the migration. The render test compiles the real template against a stubbed layout with
`strict_variables` on — a route test proves a screen is *reachable*, which is a different
claim from the screen *working*, and everything that 500s an admin page happens at render.

---

## 24. The policy that described a different platform (2026-09-12)

`/cookies` is a published legal page with two sentences in bold, and both were false.

> **"We set one cookie."**  There were three. `ag_region` and `ag_currency` are written by
> `document.cookie` from the shop's region and currency selects and last a **year**. They
> arrived long after the policy was written and nothing connected the two events.
>
> **"We run no analytics."**  `VisitTracker` records every arrival's source, campaign,
> landing page, device and country, **on by default**, into `gates_visits` — which feeds
> `AnalyticsService::arrivals()` and a report an operator reads every week.

Neither was ever a lie somebody told. Both were true the day they were typed and outlived
the code by a release, which is §19's shape on a document people are asked to rely on.

### Three things made it durable, and each is worth naming separately

**The evidence it cited was the wrong evidence.** `LegalSeeder::cookies()`'s own docblock
said "everything in here is checkable against the code: see `session_set_cookie_params()`
in public/index.php". Checking the named place *confirmed the false answer*, because the
second writer is a line of JavaScript in a template, reached through a `data-cookie`
attribute and one delegated listener. A sweep that only understood `document.cookie =
'name='` would have found nothing either.

**A test asserted the false claim by name.** `LegalCoverageTest::test_the_cookie_policy_does_not_claim_trackers_we_do_not_run`
required the string `no analytics` to be **present**. So the claim was not merely
unnoticed — it was enforced, exactly as `SecurityHeadersTest` pinned `camera=()` while the
door's scanner was dead. A second test in the same class forbade the bare string
`google analytics`, which reads as a check and is not one: the sentence worth having on
that page is "there is **no** Google Analytics here", and forbidding the product name
forbids saying so. A sweep that cannot tell a denial from an admission pushes a page
towards saying nothing, which is how it got vague enough to be wrong.

**The opt-out could not be exercised by most visitors.** It was `DNT` and `Sec-GPC` and
nothing else. Chrome removed the Do Not Track setting and Safari removed it before that, so
for the majority of this platform's audience — Chrome on an Android phone — there was **no
way to say no**, while the same page promised "you will be asked before it runs". The
mechanism was honest and could only hear the people whose browsers already spoke for them.

### What replaced it

**The facts are generated, not written.** `Support\CookieRegistry` declares every cookie and
every browser-storage prefix, *derived* where it can be — the session cookie's name and
lifetime come from `session_name()` and `session_get_cookie_params()`, the two shop cookies
from the constants their services already expose. `LegalDocument::cookiesHtml()` renders it
into the page on every draw, the same way the AI disclosure is built from `AiPrivacy`, and
for the same reason: it is in `LegalDocument` rather than in the template so `/cookies.txt`
and `/cookies.md` carry it too.

**`CookieRegistryTest` is the part that survives forgetting.** It sweeps `templates/`,
`public/assets/js/` and `src/` for `document.cookie`, `data-cookie="…"`, `setcookie(` and
`(local|session)Storage.setItem`, and fails by name on anything not declared. Storage keys
are declared as PREFIXES because several are per-item (`afg_voted_prog_12`,
`ag-celebrated:nominee-88`), and `vendor/` is excluded by path — a library's own key inside
a player the page never instantiates is not something a visitor is owed a policy entry for.
**Both halves were proved by breaking the code**: renaming a `data-cookie` value and a
`sessionStorage` key each produced the finding, by name.

**`Services\CookiePrefs` is the one resolver for "may we count this person".** The rule is
one sentence — *if anything said no, the answer is no* — so a `DNT`/`Sec-GPC` header beats
a stored yes. That is **stricter than the Global Privacy Control specification requires**,
which permits a site-specific opt-in to override the general signal, and the cost is real:
somebody browsing with GPC on who deliberately presses "count my visits" is still not
counted, and the page says so rather than silently disagreeing with its own button. The
only thing at stake on our side is a row in a report about which flier worked.

`VisitTracker` lost its private `optedOut()` and asks `CookiePrefs`. Two resolvers for one
decision is how a page comes to show somebody a consent question it has already counted
them without.

**The posture is a setting, because it is a legal position rather than a fact.**
`visits_consent_mode` = `exempt` (the default: count unless refused, no banner) or
`consent` (count nobody until they agree). `exempt` is defensible because the counting
**stores nothing new on the device** — it reuses the session cookie, so ePrivacy Art.5(3),
which governs storage and access rather than measurement, is not engaged, and the CNIL
exempted-audience-measurement conditions hold on every limb: first party, no cross-site
anything, no third-party recipient, aggregate reporting, no identifier surviving the day
(the IP hash is re-salted daily), bounded retention. The generated section states **which
posture is running**, so a page describing the wrong one is the fault it exists to end.

**A `consent` mode with nowhere to consent is a switch that counts nobody for ever** —
never asked, so never a yes, so the report goes quiet and the screen says "nobody came".
The mode and the notice shipped together. The notice is drawn from the layout, gated on a
memo `VisitTrackingMiddleware` primes from the real request, because Twig has no Request
and a template helper reading `$_COOKIE` itself would be the second resolver again.

### The control, and the specifics that make it one

`partials/cookie-choice.twig` on `/cookies`, `partials/cookie-notice.twig` from the layout.
Both are **plain forms that post** — a privacy control that needs JavaScript is missing for
exactly the people most likely to have switched it off. Neither may become a nested
`<form>`: the notice sits at the very end of `<body>`, and inside a page form its buttons
would post the page the visitor was reading to `/cookies/choice`, styled correctly, with no
console warning.

The notice's two answers carry an **identical class string**, asserted mechanically, because
"accept is a button and decline is grey text" is the design the consent rules exist to
stop. Nothing is pre-selected, the page stays readable (no modal, no overlay, no `inert`),
and **dismissing without answering is deliberately not offered** — a close button that
leaves the question open means the notice returns on the next page, which trains people to
press whichever button makes it go away.

`ag_privacy` holds one character and is `HttpOnly`: handing every page's scripts a privacy
preference to read would add a fingerprinting surface to save a round trip nobody is
making. The `return` field is re-validated server-side by `CookiePrefs::safeReturn()` —
"begins with a slash" is not the check, because `//evil.example` is a protocol-relative URL
a browser follows off-site.

### The repair, and why the prose needed one

`LegalSeeder::install()` never overwrites an existing document, so rewriting the seeder
fixes a deployment that has never installed it and fixes **nothing** on production, which
installed it long ago. `2027_01_23_cookie_policy_repair.php` updates the stored body **only
where `updated_by IS NULL`** — the stamp `LegalService::save()` writes on every edit, so a
NULL is the platform's own contemporaneous record that no person has claimed those words.
It creates nothing (a missing policy heals itself on first request) and stamps no admin id
(the next repair would read it as somebody's edit). Where an operator *has* edited, their
words stand and the generated section still carries the facts under its own heading: **the
prose is the promise, the generated section is the fact.**

That migration runs against an empty table in the harness, so it would have shipped
completely untested — the `gates_event_invites.audience` shape exactly. `CookiePolicyRepairTest`
plants rows that look like production's and exercises both branches.

### Also removed

`templates/pages/privacy.twig` — unrouted, rendered by nothing, and carrying a **third**
account of the cookies ("a remembered-locale cookie if you choose one, and a fingerprint
hash used only for rate-limiting"), none of which matched either of the other two.

### Tests

`CookieRegistryTest` (6), `CookiePrefsTest` (12), `CookieChoiceScreenTest` (11),
`CookiePolicyRepairTest` (5), `tests/Feature/CookieConsentRouteTest` (7, end-to-end through
the real router and middleware stack), plus the rewritten cookie group in
`LegalCoverageTest`. **Five mutations were run and all five were caught**: an undeclared
cookie, an undeclared storage key, the precedence inverted so a stored yes beat a header,
the notice's two buttons given different classes, and the migration's `updated_by` guard
removed.

`CsrfFieldNameTest` caught this feature's first draft shipping both forms with
`name="csrf_token"` — the name of the Twig **global**, not of the field `CsrfMiddleware`
reads, which is `_token`. A correct token in a box nothing opens: every press would have
been rejected as a forgery, and the button would have looked broken rather than refused.

---

## 25. The site had one accent colour and it was invisible (2026-09-12)

The house style names one gold accent, `#f3b416`. Against the house paper `#f0f2f2` it is
**1.65:1** — below the 3:1 a border owes (WCAG 1.4.11) and far below the 4.5:1 a word owes
(1.4.3). It was being used as a hairline and as a mono micro-label: the two places where
1.65:1 is nothing at all.

That is the whole explanation for a palette that feels absent. Not too little colour —
**colour used as a LINE when it is only ever visible as a FIELD.**

| token | on paper | verdict |
|---|---|---|
| `#f3b416` house gold | **1.65** | fails both floors |
| `--ag-gold` `#c9a24b` | **2.13** | a SECOND gold the house style never mentions |
| `--ag-green-light` `#7fc87c` | **1.79** | fails the non-text floor |
| `--ag-pulse` `#e0245e` | **4.08** | passes for a border, fails for a word |
| `--ag-green` `#237b22` | 4.76 | correct, untouched |

And underneath: **642 distinct hex values across the templates**, among them four golds
(`#f3b416`, `#fbc329`, `#c9a24b`, `#7a5600`) and a gold wash `#fff8df` used forty times.
Nobody was inventing colours carelessly — they were hand-deriving the ramp below,
separately, because there was nowhere to put it.

### `Support\Accent` — four roles, four values each

The same `fill`/`edge` split `EventTierTone::hues()` already draws, extended by the two the
rest of the site needs. **Do not invent a fifth name.**

- `fill` — the identity. A chip, a swatch, a filled surface. **Owes nothing**, because it is
  neither a boundary nor a letter.
- `edge` — the same hue at ≥3:1 on paper. Borders, rings, rules, icon strokes. *This is the
  one that did not exist*, so every accent border on the site was under 1.4.11.
- `ink` — the same hue at ≥4.5:1 on paper. Words.
- `wash` — a tint holding house ink above 12:1. Where an accent is **allowed to be loud**.

`honour.ink` is `#7a5600` — already in thirty-five files, hand-derived by somebody who got
it right, so adopting it makes those correct rather than legacy. Where a role's identity
already clears 4.5:1 (`action`, `caution`) the three values coincide; three names for one
colour is the answer to three different questions that happen to agree.

**A fill deliberately owes no floor**, and that is the load-bearing decision: demanding 3:1
of it would force the house gold to a mustard nobody chose, which is how a palette gets
fixed into blandness by an accessibility pass. The rule held instead is that a fill is never
the only carrier of meaning.

`AccentTest` re-derives every floor against the real ground rather than trusting the
constants, holds each lifted value within **18° of its identity** (lift a gold far enough and
it is a brown, and the page has complied its way out of having an accent), and enforces a
**ceiling of two roles per public template** — restraint is the setup and colour is the
payoff, so the payoff has to be rare, and rarity is not something a palette can hope for.
The palette reaches the page from `Accent::css()` through one nonced style block, so the
values a browser receives and the values the test measures cannot drift apart.

### `Support\Contrast` — there were four copies of relative luminance

`Swatch`, `EventTierPalette`, `OrgBrand`, `EventTicketDesign`. Three linearise at `0.03928`
(WCAG 2.x's wording), `OrgBrand` at `0.04045` (sRGB's). Those are 10.01/255 and 10.31/255,
**no integer channel falls between them**, and the two were verified to agree on all 256
inputs and every ratio in the palette — so this was a maintenance fault and not a shipped
one, and it was worth proving before claiming either way. Each delegate keeps its own
validation and its own threshold: `Swatch`'s 0.45 tick cut and `EventTicketDesign`'s 0.36 ink
cut travelled unchanged, because a printed ticket's contrast decision must not move
underneath a consolidation.

### Three of WCAG 2.2's four newest criteria were unmet

- **2.4.11 Focus Not Obscured.** `.ag-nav` sticky at the top, `.ag-mobnav` fixed at the
  bottom — a keyboard user tabbing down had each newly-focused control scrolled to the
  viewport edge and covered. The guidance names sticky headers and cookie banners as the
  usual culprits and this site has both. `scroll-padding` on the scroll container is the
  whole fix, read from `--ag-nav-h` / `--ag-mobile-nav-h`.
- **2.5.7 Dragging Movements.** The globe rotated by drag and nothing else. The same
  component also failed **2.4.7**: a marker on the far side is drawn at `opacity:0` and stays
  a `<button>` in the tab order, so tabbing walked a keyboard user through nations they could
  not see with the focus ring at zero opacity. **Both are one fix** — focusing a marker turns
  the globe to it, through the easing loop that already exists.
- **2.5.8 Target Size.** 44px under `pointer: coarse` was the *only* floor; the AA minimum of
  24×24 is not conditional on the pointer. 24 and not 44 site-wide, deliberately: 44
  everywhere would inflate every inline control on a dense admin table.

### The colour sweep took four attempts to stop lying

The first cut reported **36 findings and essentially all were correct code**. Four distinct
mechanisms defeat a general version, each proved rather than assumed:

- **BEM naming does not encode containment.** `.vn-ballot__k` and `.vn-ballot__top` are both
  elements of `.vn-ballot`; the second contains the first and is `#10292C`. Inferring
  ancestry from the name reported a light-green label as a failure on a white card.
- **A selector can be declared twice with different grounds.** `.jg-chip` is a translucent
  chip on a dark hero and a white filter chip five lines later; keyed by selector the second
  overwrote the first. A selector that disagrees with itself is now unresolvable — the
  `OneResolverPerSettingTest` shape.
- **A CSS comment above a rule became part of its selector**, and the guard then skipped the
  rule. Caught by mutation: a sweep that goes quiet in exactly the files somebody has
  documented is worse than one that never ran.
- **The ground decides the verdict, not the hex.** `#e0245e` is 4.58:1 on white, 4.42:1 on
  `#fbfbfa`, 4.08:1 on paper. A *candidate* fails on either house ground; a *finding* is
  measured against the ground proved for that rule.

What survives asks only what CSS itself states: the block declares its own background, or a
descendant selector names an ancestor that declares one. One genuine failure was fixed
(`.pl-eyebrow`, 4.42:1); two `#e0245e` words on white cards passed by eight hundredths and
are **not claimed as failures**.

### `/winners` — the hall of fame

A ledger organised by edition answers "what happened in 2026"; somebody typing `/winners` is
asking "who is in here". It was a redirect to `/results`.

**It adds no query of its own.** `WHERE gates_nominees.status = 'winner'` would print an index
nobody was ever given — `PublicResults::category()` lays a cycle's sealed standing back over
the live computation, and a released nominee has already moved 693 → 885 across a week of
scoring changes. `HallOfFame` regroups what `/results` already drew.

**Identity is `gates_nominees.profile_id`, resolved in one query for the whole wall.** A
nominee row belongs to one category, so somebody entered twice is two rows. **Never by name**:
two people share a name, and a hall that merged them would attribute somebody's award to a
stranger and print it under their face. With no profile the rows stay separate — the same
person listed twice is a thinner claim than the wrong person honoured once.

This is the page the gold exists for: one washed band, an `edge` ring at 3:1 on a repeat
winner, the `ink` on the index, the `fill` on the chip. All four slots of **one** role. The
ring is never the only signal — a repeat winner carries a worded chip.

### Both downloads of the cookie policy were mush

`DocText::BLOCKS` carried `tr` and not `td`/`th`, so cells concatenated:
`PHPSESSIDIdentifies your session…Until you close your browser, or sign outEssential`. The
policy has carried a table since the legal pages shipped, so **both downloadable editions of
a published legal document have been illegible that whole time** — invisibly, because the
HTML renders perfectly and nobody reads their own `.txt`.

`.txt` renders a table as labelled records (column alignment in plain text needs a width the
reader's terminal may not have, and one wrapped cell destroys it); `.md` renders a real
markdown table with a pipe inside a cell escaped rather than stripped. Two traps on the way,
both found by the output being wrong: an **opening** tag flushes the enclosing block, so an
empty `tr` flush produced a boundary before every cell; and `if ($text === '') continue;`
threw every table away, because a row boundary is deliberately empty. The boundary is emitted
on the **closing** tag, the shape `ul`/`ol` already use.

On the page the table stacks into records below 640px via `data-label` — four columns with a
paragraph in one of them is a horizontal scrollbar inside a document at 380px.

### Tests

`AccentTest` (7), `AccessibilityFloorTest` (8), `HallOfFameTest` (11), plus four table tests
in `LegalDocumentTest`. **Eight mutations were run and all eight caught**: the raw gold in the
ink slot, a grey lifted into the edge slot, an undeclared accent as a word on a white card,
`scroll-padding` removed, the 24px floor pushed back inside the media query, the globe's
arrow keys removed, the seal overlay removed from the hall, the hall's identity key dropped
back to the nominee row, and `DocText::BLOCKS` reverted to the shipped list.
