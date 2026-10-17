# Africa GATES — Architecture & Scaling Review

> **Scope:** Analysis only. No business functionality or behaviour was changed by this review.
> **Date:** 2026-06-24 · **Lens:** Staff engineer / architect / tech lead owning this system for 5+ years.
> **Companion docs:** [`CODEBASE-INDEX.md`](CODEBASE-INDEX.md) (the map), [`ENTERPRISE-VOTING-NOMINATION-PROPOSAL.md`](ENTERPRISE-VOTING-NOMINATION-PROPOSAL.md) (2026-06-14 blueprint — its Phases 1–2 are largely implemented; this review extends its §11 / Phase 3).

---

## 1. Executive Summary

Africa GATES is a **well-built server-rendered PHP monolith** (Slim 4 · Twig · Eloquent-as-query-builder · MySQL/SQLite) with a genuinely strong core: server-authoritative payments, an OTP-bound row-locked vote transaction, CPI-based winner selection with judge quorum, hash-chained tamper-evident snapshots, fail-closed authz, thoughtful indexing, and a green test suite (171 tests). It is **production-appropriate for its current scale (~1k users)** and a large fraction of the prior enterprise proposal has already shipped.

The system is **not currently at risk** — there are no critical security or correctness defects outstanding. What limits it is **scale headroom and operational visibility**, and these are architectural, not code-quality, problems:

1. **The cache and sessions are the two hard horizontal-scaling blockers.** "Cache" is rows in `gates_cache` (a DB round-trip per fragment); sessions use PHP's default **file handler** (pins the app to one host). Neither hurts at 1k users; both must change *before* a second app node exists.
2. **Automation is a single-host serial cron hub** (`cron/maintenance.php`) that drains the queue, recomputes CPI full-table, snapshots, and emails — fine now, a throughput ceiling later.
3. **One current-state reliability bug:** that cron references a `global $container` it never builds, so **Google Sheets sync and voting-reminder emails silently no-op**. Small fix, real impact.
4. **Observability is thin** — no metrics/tracing, `gates_cron_log.runtime_ms` is written `null`. You cannot operate at scale what you cannot see.

**Recommendation:** keep the monolith (the bottlenecks are stateful infra, not a need for microservices), fix the one cron bug now, add observability next, and stage the cache/session/worker/replica changes against real growth — not preemptively. Avoid premature complexity (no Elasticsearch, no Kubernetes, no service mesh at 1k users).

---

## 2. Architecture Breakdown

### 2.1 Components
- **HTTP edge:** Apache (`.htaccess` rewrite + upload-exec denial) → `public/index.php` (front controller) → middleware stack → `src/routes.php` → controllers.
- **Middleware (outer→inner):** Routing → Twig → `SecurityHeadersMiddleware` → `CsrfMiddleware` → BodyParsing → ErrorMiddleware (`public/index.php:106-114`).
- **DI container:** `config/container.php` (PHP-DI) — ~40 services + every controller as factory closures.
- **Domain services (`src/Services/`):** voting/CPI/judging/integrity (`VoteService`, `CpiService`, `NomineeScoringService`, `RuleEngine`, `FraudService`, `CollusionService`, `SnapshotService`, `BonusVoteService`), payments (`PaymentService`), community/content, and infra (`CacheService`, `RateLimitService`, `QueueService`, `OtpService`, `GoogleSheetsService`).
- **Admin & Judge modules:** `src/Admin/**` (auth, RBAC, CRUD, audit), `src/Judge/**` (OTP auth, scoring, quorum, COI).
- **CLI / automation:** `bin/console` (Symfony Console) + `cron/maintenance.php` (the 15-min hub) + `cron/recalculate-cpi.php`, `cron/aggregate-dashboard.php`.
- **Data:** MySQL (prod) / SQLite (dev), `gates_*` tables, evolved by idempotent driver-aware migrations.
- **External:** Paystack/Flutterwave, Brevo SMTP, Cloudflare Turnstile, AI moderation (Groq/Gemini/Anthropic/OpenAI), Google Apps Script (Sheets).

### 2.2 Deployment topology (current)
Single cPanel/shared host: Apache + PHP-FPM, local MySQL, **local-disk file sessions**, **DB-backed cache**, one cron line driving `maintenance.php`. No CDN; imagery hotlinked from `images.unsplash.com`. This is a **vertically-scaled single node** — the defining constraint of the roadmap in §10.

### 2.3 Data flow (the two hot paths)
- **Read (Home / Leaderboard / Registry):** controller → `CacheService::remember(key, ttl, …)` → on miss, query MySQL (indexed: `idx_cpi_score DESC`, `FULLTEXT ft_search`) → cache row written to `gates_cache`. *Every cached read is still a DB SELECT against `gates_cache`.*
- **Write (Vote):** `/api/vote` → rate-limit + pre-vote fraud score → `VoteService::castVote()` (transaction: lock OTP token, verify, unique-insert, increment counts) → enqueue `vote.sheets_push` → best-effort email + cache-bust. Most side-effects are correctly off the hot path; AI moderation and payment `verify` remain synchronous where they occur.

---

## 3. Critical Problem Areas (Risk Register)

Severity reflects **operational impact at the scale where it bites**. None are Critical today; the system is sound now.

| ID | Risk | Why it's a problem | Evidence | Severity |
|----|------|--------------------|----------|----------|
| **R-1** | `cron/maintenance.php` uses `global $container` that is never instantiated | `$sheets`/`$mailer` resolve to `null` → **Google Sheets vote sync and voting-reminder emails silently do nothing** (jobs still mark `done`). Silent integration failure. | `cron/maintenance.php:131-146`, `:152-201` (no `ContainerBuilder` in file) | **Medium (now)** |
| **R-2** | Three cron entrypoints with overlapping duties | `maintenance.php` (15-min hub, also CPI every 6h) vs README's `recalculate-cpi.php` + `aggregate-dashboard.php`. Risk of double-running or not scheduling CPI. | `cron/*.php`, `README.md` Cron section | **Low–Med** |
| **S-1** | Cache **is** the database | Every cached fragment = a `gates_cache` SELECT; `forgetByTag` is `LIKE '%tag%'` DELETE. No in-memory tier. DB becomes the bottleneck before app code does. | `src/Services/CacheService.php:8-19` | **High @100k+** |
| **S-2** | File-based PHP sessions | Default handler writes session files to local disk → cannot run >1 app node without sticky sessions / shared store. **The horizontal-scale blocker.** | `public/index.php:51-68` | **High @100k+** |
| **S-3** | Single-host serial automation hub | One 15-min cron drains queue (`work(50)`), advances cycles, recomputes CPI (`exec`), snapshots, emails — serially, single-instance-locked. Queue ceiling ≈ 50 jobs/15min; no horizontal workers. | `cron/maintenance.php:227-249`, `QueueService::work` | **High @100k+** |
| **S-4** | CPI recompute is full-table every 6h | Loops **all** categories × **all** approved profiles each run; cost O(N), leaderboard staleness up to 6h. | `CpiRecomputeCommand.php:42-78`, `cron/maintenance.php:101-108` | **Med→High @100k+** |
| **S-5** | Registry search via MySQL `FULLTEXT` | Fine to ~10⁵–10⁶ rows; relevance/typo-tolerance/QPS ceiling beyond. | `database/schema.sql:30` | **Low→Med @1M** |
| **S-6** | Some external calls remain synchronous | Turnstile + AI moderation inline on community/nomination writes; payment `verify` inline on callback. p99 hostage to third parties. (Vote side-effects already queued.) | `SpamService`, `PaymentController::callback` | **Medium** |
| **S-7** | Monolith on shared hosting; no CDN | Vertical ceiling; no documented horizontal topology; assets hotlinked from Unsplash (third-party availability + no edge caching). | `README.md`, asset refs | **High @100k+** |
| **S-8** | Single DB connection, no read-replica / pooler | Read-heavy paths (leaderboard/registry/home) saturate the primary; no read/write split. | `config/database.php:30-46` | **Med→High @100k+** |
| **O-1** | No metrics/tracing; `runtime_ms` written null | No `/metrics`, no request tracing, no alerting, cron durations unrecorded. Flying blind operationally. | `cron/maintenance.php:284` | **High @10k+** |
| **M-1** | SQLite-dev / MySQL-prod schema divergence | Shop tables exist only in migrations, not base `sqlite-*.sql` → test harness can't see them; drift + untested paths. | base schema vs `migrations/2026_06_22_shop.php` | **Medium** |
| **M-3** | Nomination/donor PII stored plaintext indefinitely | NDPR/GDPR exposure; no retention/erasure/consent. (Voter emails *are* hashed — good.) | `gates_nominations`, `gates_donations` | **Medium** |

**Genuinely solid — do not "fix" (avoid wasted effort):** the vote transaction & idempotency, CPI math + quorum, hash-chained snapshots, server-authoritative payments, CSRF/authz/session-cookie security model, and the DB indexing (incl. `idx_cpi_score DESC` and `FULLTEXT`). These were audited and are strengths.

> **Status (2026-06-24):** the **do-now tier is implemented** — **R-1** (cron now builds the DI container → Google Sheets sync + voting reminders restored), **R-2** (README documents `cron/maintenance.php` as the canonical hub; warns against double-scheduling CPI), and the **O-1 down-payment** (`gates_cron_log.runtime_ms` is now recorded). Verified: suite green (171), cron boots in CLI without fatal. The **S-* scale-tier items remain deferred by design** — they are infrastructure (Redis, read replicas, CDN, search service) appropriate at their growth tier, not at ~1k users; implementing them now would be the premature complexity this review warns against.

---

## 4. Technical Decisions & Rationale

| Decision | Rationale |
|---|---|
| **Keep the monolith** | Bottlenecks are stateful (cache/session/DB/queue) and solved with managed infra, not service decomposition. Microservices here would add ops cost and latency for no scaling benefit. Extract only the **async worker** and (later) **search** as load demands. |
| **Abstract the cache behind PSR-16** before swapping backends | Lets `gates_cache` → APCu → Redis become a config change, not a rewrite of every `remember()` caller. Small now; avoids a big-bang later. |
| **Shared session store is a prerequisite, not a reaction** | Moving sessions to Redis/DB must precede the second app node — discovering it during an outage is the bad path. |
| **Defer the search service** | MySQL `FULLTEXT` is adequate for now; adopting Elasticsearch/Meilisearch at 1k users is premature complexity. Add it when search QPS/relevance actually hurt. |
| **Incremental CPI only when full-table approaches the interval** | Full-table recompute is simpler and correct today. Optimize when its duration nears the cron cadence — not before. |
| **Observability first among the "scale" items** | You cannot safely make the other changes without measuring queue depth, recompute duration, and error rates. Cheapest, highest-leverage step. |

---

## 5. Tradeoff Analysis (key scaling choices)

- **Cache: DB vs APCu vs Redis.** DB-cache (today) — zero new infra, survives restarts, but a DB round-trip per fragment and weak invalidation. APCu — in-process, near-zero latency, but per-node (no shared invalidation) and lost on restart. Redis — shared, fast, supports tags/TTL natively, but new infra to run. **Path:** PSR-16 interface → APCu at 10k (single host) → Redis at 100k (multi-host).
- **Sessions: sticky LB vs shared store.** Sticky sessions — no code change, but uneven load and lost sessions on node loss. Shared store (Redis) — even load, resilient, but new infra + a `session.save_handler` change. **Recommend the shared store**; sticky is a stopgap only.
- **Queue: cron-drain vs daemon vs managed (SQS).** Cron (today) — simple, but latency = cron interval and a throughput ceiling. Daemon (`queue:work` supervised) — low latency, but a process to supervise. SQS/Redis — elastic + durable, but vendor/infra. **Path:** 1-min cron → supervised daemon → managed queue, all behind the existing `QueueService` interface (no caller changes).
- **DB scale: vertical vs read replicas vs sharding.** Vertical (today) — simplest, real ceiling. Read replicas — absorb read-heavy paths with modest app changes (read/write split via the query-builder). Sharding/partitioning `gates_votes` — last resort, high complexity; only if vote write volume demands it (a viral cycle). **Recommend replicas well before sharding.**

---

## 6. Component & Interface Architecture

- **Backend:** thin controllers → services (domain logic) → Eloquent query-builder → MySQL. Cross-cutting concerns are middleware (CSRF, security headers, errors) and infra services (cache, rate-limit, queue). This layering is clean and is the right shape to scale within.
- **Frontend:** server-rendered Twig (`templates/`) + a modular CSS system mid-migration (`assets/css/base|components`) + vanilla JS/Alpine. Not a SPA — appropriate for SEO/content and low client complexity.
- **Key interfaces (stable contracts to preserve):**
  - HTTP: the public route table (`src/routes.php`) — the API surface for clients.
  - `CacheService` (PSR-16-shaped: `remember/get/forget/forgetByTag`) — the seam for the cache-backend swap.
  - `QueueService` (`push/on/work`) — the seam for the queue-backend swap; explicitly designed for Redis/SQS migration.
  - `PaymentService` (`initialize/verify/enabledProviders`) — the gateway abstraction; new providers are additive.
  - `RuleEngine` (`effective/weights`) — per-programme/cycle policy without deploys.

---

## 7. Implementation Guidance (phasing — no code changed in this review)

1. **Now (hours):** Fix **R-1** (build the DI container in `maintenance.php`, or instantiate `GoogleSheetsService`/`OtpService` from env as `CycleAdvanceCommand` does). Clarify **R-2** to one documented crontab line. Start writing `runtime_ms` (**O-1** down payment).
2. **Next (10k):** PSR-16 cache seam + APCu; `/metrics` + structured logs + alerting; tighten external-call timeouts + circuit breakers; shorten queue cadence.
3. **Horizontal step (100k):** Redis sessions + cache; managed MySQL + read replicas + pooler; supervised queue worker off the web nodes; incremental CPI; CDN + object storage; LB + ≥2 app nodes + autoscaling.
4. **Continental (1M):** search service; edge/WAF rate-limiting; vote-write buffering for spikes; regional replicas + data residency; GDPR/NDPR retention/erasure.

---

## 8. Refactoring Opportunities (low-risk, high-leverage)

- **Single migration source of truth** for both drivers (fixes M-1 and the "shop untested in harness" gap). The proposal's §12 already calls for this.
- **One cron entrypoint** (`maintenance.php`) — retire/clarify `recalculate-cpi.php` + `aggregate-dashboard.php` (R-2).
- **PSR-16 cache interface** extraction (enables S-1 without caller churn).
- **Read/write DB split** behind the query-builder for read-heavy endpoints (S-8).
- *(From the prior audit, deliberately deferred:* centralizing payment-confirm logic, a base admin controller, legacy-CSS removal post-redesign — each safe to leave.)

---

## 9. Scaling Roadmap

| Tier | What holds / what breaks first | Actions |
|------|-------------------------------|---------|
| **~1,000 (today)** | Everything works on one host. | Fix R-1 cron bug; consolidate crons; start recording `runtime_ms`. No infra change. |
| **~10,000** | DB-cache round-trips + 15-min cron cadence begin to matter; you lack visibility. | APCu cache tier (PSR-16 seam); `/metrics` + alerting; circuit-breakers/timeouts on external calls; 1-min queue drain. Still single host (scale vertically). |
| **~100,000** | Single host is the ceiling; sessions + cache block horizontal scale; read load saturates the primary; full-table CPI lags. | **The horizontal step:** Redis sessions + cache; managed MySQL + read replicas + pooler; dedicated queue worker(s); incremental CPI; CDN + object storage (drop Unsplash hotlinking); LB + ≥2 app nodes + autoscaling; full observability. |
| **~1,000,000+** | Vote spikes (viral cycle) dominate; FULLTEXT + single-region DB strain; abuse at scale. | Search service (Meilisearch/ES); buffer/queue vote writes + async tally; regional read replicas + data residency; edge WAF + rate-limiting; GDPR/NDPR retention/erasure; routine load + chaos testing. |

---

## 10. Best-Practices Checklist

**Security** ✅ strong: server-authoritative payments, global CSRF, fail-closed authz, hashed PII (voters), signed webhooks, sealed prod errors. ⬜ add: PII retention/erasure (nominations/donors), edge WAF at scale, secret rotation runbook.
**Performance** ✅ good indexing, heavy caching, queued vote side-effects. ⬜ add: in-memory cache tier, CDN, read replicas, incremental CPI.
**Reliability** ✅ idempotent payments/votes, queue retries, CronGuard, snapshots. ⬜ fix: R-1 silent no-op; add circuit-breakers, dead-letter visibility, multi-node redundancy.
**Observability** ⬜ **biggest gap:** no metrics/tracing/alerting; `runtime_ms` null. Add `/metrics`, structured request logs (Monolog is present), alerting, queue-depth + recompute-duration dashboards.
**Accessibility** ⬜ verify WCAG AA through the redesign (focus states, contrast, semantics, keyboard paths); not yet audited.
**Testing** ✅ 171 tests, strong domain coverage. ⬜ add: controller/community/shop integration tests; single migration runner so the harness matches prod.
**Maintainability** ✅ clean layering, DI, RuleEngine config-over-code. ⬜ resolve schema-source-of-truth; consolidate crons.
**i18n** ⬜ externalize copy (francophone Africa as the platform scales toward 54 nations) — proposal §7.

---

*Grounded in a line-level reading of the codebase as of 2026-06-24. Analysis only — no behaviour was changed. Concrete implementation should be scoped per phase and verified against the existing test suite.*
