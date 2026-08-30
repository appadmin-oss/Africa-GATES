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

**Recovering votes the platform dropped.** When a vote code fails to send, the token records it
(`gates_otp_tokens.delivery_state = 'failed'`) — the platform's own admission that it let that person
down. `VoteRecoveryService` derives candidates from those records ONLY; no operator can name a person.
While voting is open it refuses and tells you to re-send instead. After the close a batch needs a
second admin's approval, passes a fraud/IP-cluster screen and a cap (25% of a nominee's verified
votes), lands as ordinary organic votes carrying `recovery_batch_id` + `otp_token_id`, is disclosed
publicly per nominee, and can be voided. `bin/console votes:recover health` reports the delivery
failure rate — the number this feature exists to serve, and the one that should be falling.

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
