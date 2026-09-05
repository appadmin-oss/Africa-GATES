# Africa GATES — Structure & Flaw Audit

> **Date:** 2026-08-08 · **Scope:** analysis only, no behaviour changed.
> **Method:** every number below was measured against the tree at `d96228f`, not carried over
> from an earlier document. Where an existing doc's claim was checked and held, it says so;
> where it had drifted, it says that too.
> **Companion docs:** [`CODEBASE-INDEX.md`](CODEBASE-INDEX.md) (the map, last refreshed 2026-07-23),
> [`ARCHITECTURE-AND-SCALING-REVIEW.md`](ARCHITECTURE-AND-SCALING-REVIEW.md) (2026-06-24).

---

## 1. Summary

A server-rendered PHP 8.4 monolith (Slim 4 · Twig 3 · Eloquent-as-query-builder · MySQL prod /
SQLite dev) running continental cultural-recognition awards: nominations → voting → judging →
winners, a CPI leaderboard, payments, a community layer, an admin back-office and a judge portal.

**The core is genuinely healthy.** The test suite is green on a clean install — 2,103 tests,
11,390 assertions, 0 failures, ~29s. Every one of the 289 route handlers resolves to a real
method; every one of the 109 templates named in PHP exists; MySQL and SQLite base schemas are at
exact 75/75 table parity; there are no parse errors in any of the 1,000 tracked files and no
unreferenced service classes.

**What is wrong is not the domain logic — it is the boundaries around it.** Three things
dominate:

1. **There is no persistence boundary.** 149 of 245 source files call `DB::` directly and there
   are 1,701 raw `gates_*` table-name literals. A column rename is a 35-file search-and-replace
   with no compiler help.
2. **Errors are swallowed by default.** 222 `catch` blocks do nothing at all — no log, no
   rethrow — against 92 that log or rethrow. 71% of error handling is silent, on a system with
   no metrics and no tracing.
3. **The deployment target fights the code.** Migrations are applied by hand on shared cPanel
   hosting, so the codebase has grown 54 runtime schema probes (`OptionalColumn`, `hasColumn`) to
   survive a database that does not match the code. This is documented as deliberate, and it is
   a reasonable response — but it means schema drift is now a normal operating state rather than
   a bug.

Nothing found is a live security defect. The security posture claimed in the earlier reviews
holds up under re-check (§4).

---

## 2. Structure, measured

| Area | Files | Lines |
|---|---:|---:|
| `src/` (PHP) | 245 | 58,981 |
| `templates/` (Twig) | 135 | 24,225 |
| `tests/` | 220 | 41,661 |
| `public/assets/` | — | 32,725 |
| `database/migrations/` | 87 | — |
| `docs/` | — | 33,129 |

```
public/index.php        front controller, 305 lines — PHP-7.4-parse-safe preamble,
                        env load, Clock::boot, session, CSRF mint, Capsule, DI,
                        middleware stack, routes, and a shutdown-time "web cron"
  └─ middleware (outer → inner)
       SecurityHeadersMiddleware → ErrorMiddleware → BodyParsing
       → CsrfMiddleware → TrailingSlashMiddleware → Twig → Routing
  └─ src/routes.php     1,281 lines · 289 class:method handlers + 22 inline closures
       ├─ ''            public site
       ├─ /api, /api/v1 ApiVersionMiddleware
       ├─ /judge        JudgeAuthMiddleware
       ├─ /account      UserAuthMiddleware
       └─ /admin        AdminAuth + SectionGuard + Role
  └─ src/
       Services/    106 classes    Controllers/  30
       Admin/        47            Judge/         4
       Support/      29 helpers    Console/      22 commands
       Middleware/    5            Handlers/      1
  └─ cron/           3 entrypoints (maintenance, recalculate-cpi, aggregate-dashboard)
```

### Layer inventory

- **`src/Services/` is doing four unrelated jobs.** It holds domain services (`VoteService`,
  `CpiService`), infrastructure (`CacheService`, `QueueService`, `RateLimitService`), value
  objects (`AiResult`, `RefundDecision`, `PhaseError`, `CyclePhase`), and 1,284 lines of
  hardcoded help-centre content (`HelpCentre`). One flat directory, no sub-namespaces, so the
  distinctions are invisible from the filesystem.
- **86 of 245 classes are all-static** (static methods, no constructor) — 637 static methods
  against 1,158 instance methods. Static helpers cannot be substituted in a test or swapped per
  environment; they are reached through hard class references, not the container.
- **DI wiring is split two ways.** `config/container.php` registers 91 entries explicitly, but
  only 22 of the 106 services are among them — the other 84 arrive through PHP-DI autowiring.
  Both mechanisms work; the inconsistency means "is this service configured or inferred?" cannot
  be answered without grepping. The index doc still describes "~40 services" — that count has
  drifted.
- **No base controller and no repository layer.** 59 controllers repeat index/form/save/delete +
  audit + flash by hand; 40 of them query the database directly.

### The ten largest source files

```
1403  src/Services/SupportContext.php        765  src/Services/PaymentService.php
1317  src/Services/FlierService.php          744  src/Support/Maintenance.php
1284  src/Services/HelpCentre.php            730  src/Services/AiService.php
1023  src/Services/RefundService.php         722  src/Services/ActivityFeedService.php
 808  src/Services/NomineeClaimService.php   712  src/Admin/Services/AnalyticsService.php
```

31 source files exceed 500 lines; 60 exceed 300. The largest template,
`templates/pages/pulse.twig`, is 1,837 lines.

### An unusual property worth naming

**30.8% of `src/` is comments** (18,173 comment lines against 35,901 code lines). These are not
docblocks — they are narrative post-mortems written inline, recording production incidents and
why the code is shaped as it is. `public/index.php` explains a nested-deploy 403 and a LiteSpeed
vs PHP-FPM response-detachment bug; `OptionalColumn` explains the paid-vote outage that created
it. This is the codebase's best feature and its most fragile: none of it is verified by anything,
so it decays into confident, wrong documentation the moment behaviour moves underneath it.

---

## 3. Flaws

### High

**H-1 · No persistence boundary — 1,701 table-name literals across 149 files.**
`DB::` is called directly from 149 of 245 source files, including 40 controllers.
`gates_donations` is named in 35 different files, `gates_votes` in 31. There is no `Models/`
directory, no repository, no schema constants. Consequences: a column rename cannot be done
safely, there is no single place to add a read-replica split (S-8 in the scaling review is
blocked on exactly this), and the query-shape knowledge for one table is spread across
controllers, services, admin services and console commands.
*Evidence:* `grep -rl "DB::" src/ | wc -l` → 149; `grep -rho "gates_[a-z_]*" src/ | wc -l` → 1,701.

**H-2 · 222 catch blocks swallow the exception entirely.**
Against 92 that log or rethrow — so 71% of error handling is silent. Worst concentrations:
`FinanceService` (13), `SupportContext` (11), `FinanceInsights` (8), `CacheService` (8),
`Maintenance` (7), `SupportSignals` (7), `MergeService` (7). Every `CacheService` method
swallows, so a cache outage presents as a slow site with no signal. This compounds O-1 from the
scaling review (no metrics, no tracing): there is neither a push signal nor a pull signal for
failure. The codebase already knows the cost — `OptionalColumn`'s own docblock records that a
paid-vote outage took money without minting votes and *"both were inside a `try`, so neither
surfaced as anything an operator could act on."* The lesson was applied to that one column, not
to the pattern.

**H-3 · Runtime schema probing has become load-bearing: 54 call sites.**
`OptionalColumn::on`/`filter` (54 calls across 25 files) plus 21 files probing
`hasColumn`/`hasTable`. The rationale is sound and documented — migrations are applied by hand on
shared hosting, so code and database routinely disagree, and an omitted optional column beats a
failed checkout. But the mechanism now silently disables features rather than reporting a broken
deployment, each probe is a real query on first use per process, and the set of columns treated as
"optional" is a scattered, untracked list. The underlying cause — no automatic migration on
deploy — is unaddressed; `SupportTicketService` alone carries 7 probes.

### Medium

**M-1 · 33 of 59 controllers have no test reference at all.**
26 controllers are exercised from `tests/` (and 40 test files do build real requests, so the HTTP
layer is *not* untested wholesale). But the 33 with no coverage include the money and support
paths: `DonationController`, `ShopController`, `RefundsController`, `PaymentsTriageController`,
`VoteDeliveryController`, `SupportController`, `ClaimController`, `SupportAttachmentController`.
`RefundService` is 1,023 lines of money-moving logic whose admin entrypoint is untested.

**M-2 · 1,205 KB of uncompressed CSS+JS on every page, with four coexisting design systems.**
The main layout links **21 stylesheets (404 KB, render-blocking)** and **17 scripts (801 KB,
deferred)**. Four generations of CSS are live simultaneously — `main.css` (4,989 lines),
`professional.css`, `ui-overhaul.css`, `redesign-2026.css` — alongside the newer modular
`base/` + `components/` system, with design tokens defined in two places (`base/tokens.css`,
`tokens.motion.css`). The JS includes **two carousel libraries** (Swiper 144 KB *and* Splide
30 KB), GSAP + ScrollTrigger (116 KB), Plyr (315 KB), Alpine, Popper, Tippy, split-type and a
WebGL gradient — on a server-rendered content site. The scripts are correctly `defer`ed, so this
is bandwidth and parse cost rather than blocked paint; the CSS is not.
This is the deliberately-deferred item #10 from the index, and it has grown since it was deferred.

**M-3 · The CSS fast path depends on a manual build step that is not committed.**
`bin/console assets:build` collapses the 21 stylesheets into one bundle; `AssetBundle::url()`
returns null and the layout falls back to all 21 files if it has not run. No built bundle is
committed, so on any deploy where the operator forgets the step — on a host where they may have
no shell — the site is correct but paints roughly 2.4s slower on mid-range Android, silently. The
fallback is well engineered; the trigger for it is a human remembering.

**M-4 · 16 environment variables are read by code but absent from `.env.example`.**
`SETUP_TOKEN` (which `README.md` explicitly instructs operators to set), the entire Cloudflare R2
integration (`R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`,
`R2_PUBLIC_URL`), `AUTO_REFUND_UNMINTED` (a flag that governs whether money is returned
automatically), `MEDIA_MODERATION`, `ADSENSE_CLIENT`/`ADSENSE_SLOT`/`ADSENSE_SLOT_2`, `GEE_MODEL`,
`GEMINI_VISION_MODEL`, `MAIL_HOST`, `DB_PATH`, `DB_TIMEZONE`. An operator provisioning from the
template gets a site with R2 storage, ad slots and automatic refunds all silently inert.
*(The reverse direction is clean: every one of the 56 documented variables is genuinely read —
many through a `$resolve('db_setting_key', 'ENV_NAME')` indirection, which is why a naive grep
suggests otherwise.)*

**M-5 · Maintenance runs on the visitor's request, at shutdown.**
`register_shutdown_function` → `Maintenance::tick()` (744 lines) drains the queue, reconciles
stale payments and sends mail after the response is detached. The detachment bug is fixed and
well documented (LiteSpeed vs PHP-FPM), and it now refuses to run rather than block a visitor
when neither detach function exists. But the work is still on a web worker: a slow gateway during
reconciliation consumes a PHP-FPM/LiteSpeed process, and there is no dead-letter visibility when
it fails — see H-2, `Maintenance` swallows 7 exceptions silently.

**M-6 · Cache invalidation by substring.**
`CacheService::forgetByTag()` deletes on `tags LIKE '%tag%'` — a substring match against a
comma-joined string, so a tag that is a substring of another over-deletes. Four callers remain
(`ApiController` ×2, `PulseController`, `PaidVoteService`). A safer prefix-based method exists
alongside it and is documented as the replacement for exactly this hazard; the migration is
incomplete.

### Low

**L-1 · Three cron entrypoints still overlap.** R-2 from the scaling review was resolved by
*documenting* `maintenance.php` as canonical; `recalculate-cpi.php` and `aggregate-dashboard.php`
are still present and still schedulable, so double-running CPI remains possible.

**L-2 · No static analysis anywhere.** CI (`.github/workflows/ci.yml`) runs PHPUnit on PHP 8.4
and nothing else. No PHPStan, Psalm, PHP-CS-Fixer, Rector, `php -l` sweep, or Twig lint in
`composer.json` or CI. On 58,981 lines with 30% comments and heavy `mixed`-shaped array passing,
a type checker is the cheapest coverage available. *(A manual `php -l` sweep across all 1,000
tracked files is clean, and the suite is green — so this is missing capability, not hidden
breakage.)*

**L-3 · Five orphaned templates (~17 KB).** `pages/privacy.twig` and `pages/terms.twig` are dead —
legal pages now render from `pages/legal.twig` off `gates_legal_docs` — plus
`pages/registry/register-success.twig`, `partials/comments.twig`, `partials/share.twig`.

**L-4 · `routes.php` mixes two reference styles.** Imported short names for public controllers,
inline `\AfricaGates\Admin\Controllers\X::class` FQCNs for most admin ones, in the same file.

**L-5 · 76 query-in-loop sites.** Concentrated in batch and CLI paths where per-row work is
inherent and bounded (`VoteRecoveryService` 16, `MergeService` 7, `PaymentReconciler` 7,
`MergeJournal` 6, `CycleMaterialiser` 5). The only public-request instances are in
`ShopCheckoutController` (per-cart-item product lookups, bounded by cart size). Not urgent;
worth knowing before the batch paths meet larger data.

**L-6 · Help-centre content is compiled in.** 1,284 lines of user-facing copy hardcoded in
`HelpCentre.php`, rendered through `|raw`. Safe (author-controlled, and the `|raw` uses across
templates are overwhelmingly `json_encode(JSON_SAFE)`), but every copy fix is a deploy — unlike
legal docs, which were correctly moved to the database.

### Still open by design

Unchanged and correctly deferred from `ARCHITECTURE-AND-SCALING-REVIEW.md`: **S-1** cache is the
database, **S-2** file-based PHP sessions (verified — no `save_handler` is configured anywhere, so
this remains the horizontal-scaling blocker), **S-3** single-host serial automation, **S-4**
full-table CPI recompute, **S-8** no read replica. **O-1** has had its down payment — `runtime_ms`
is now genuinely recorded in `Console/CronLog`, `Maintenance` and both standalone crons — but
there is still no `/metrics`, no tracing and no alerting.

---

## 4. Re-verified: claims that hold

Checked directly rather than inherited, because several are load-bearing:

- **Suite is green on a clean install.** 2,103 tests / 11,390 assertions / 0 failures, with
  `failOnWarning` and `failOnRisky` both on. The suite has grown ~5× since the index doc's
  "437 tests"; that number is stale, the health claim is not.
- **CSRF is sound.** Global on all mutating methods. Exemptions are exact-path (never suffix) and
  each is justified: OTP-gated routes, signature-verified webhooks, the token-gated setup
  endpoint. `/api/` writes fall back to same-origin, fail-closed when Origin and Referer are both
  absent. The `X-Requested-With` bypass would be a real hole under permissive CORS — there is no
  `Access-Control-Allow-Origin` anywhere in `src/`, `public/`, `.htaccess` or templates, and no
  route outside the API group contains `/api/`, so it holds.
- **Setup and cron endpoints are properly gated.** `hash_equals`, a minimum token length, empty
  token fails closed, and a wrong token returns 404 rather than 403 so the endpoint is invisible.
- **Security headers are outermost.** Added last, so error responses carry the nonce-bearing CSP —
  the specific gap the inline comment documents having closed.
- **Schema parity is exact.** 75 tables in the MySQL base schemas, the same 75 in the SQLite ones,
  no divergence in either direction. M-1 from the scaling review is genuinely fixed. Every
  `gates_*` table referenced in code is created somewhere.
- **Routing and templates are intact.** All 289 handlers resolve; all 109 PHP-referenced templates
  and all 19 Twig-to-Twig references exist.
- **No dead services, no parse errors.** All 106 services are referenced outside their own file;
  `php -l` is clean across all 1,000 tracked files.
- **Money is integer naira throughout**, converted to kobo at the gateway edge (`× 100` out,
  `round(÷100)` back) with amount-parity checks on confirm. No float accumulation.
- **Raw SQL is safe.** Of 124 raw-SQL call sites (`whereRaw`/`selectRaw`/`orderByRaw`/`DB::raw`/
  `DB::statement`/`DB::select`), only 9 have a variable in the SQL string, and each was read:
  `SchemaIndex` validates every identifier against `^[A-Za-z_][A-Za-z0-9_]*$` and **throws**
  rather than escaping; `ActivityFeedService` binds the search term and takes column names from
  in-file literals, with `LIKE ... ESCAPE` and the escape character escaped first;
  `PhaseAuditService` interpolates a `date('P')` offset and a two-branch literal predicate;
  `VoteRecoveryService` casts to `int`; `RefundService` picks between two column literals.
  No request data reaches raw SQL. No injection surface found.

---

## 5. Where to start

Ordered by leverage per unit of risk, not by severity:

1. **Make failure visible (H-2).** The 222 silent catches are the reason every other problem here
   is discovered by a user rather than a dashboard. Start with the ones that swallow on money and
   integrity paths, and give `CacheService` a single log line. This is additive, cannot change
   behaviour, and is the prerequisite for trusting anything else you change.
2. **Add PHPStan to CI (L-2).** Cheapest coverage available on 59k untyped-array lines, and it
   will find the H-1 damage automatically.
3. **Complete the `forgetByTag` → prefix migration (M-6)** — four callers, mechanical, removes a
   correctness hazard rather than a cosmetic one.
4. **Fill in `.env.example` (M-4)** — sixteen lines, and it stops a fresh deployment from silently
   losing R2, ad slots and automatic refunds.
5. **Cover the money controllers (M-1)** — `RefundsController`, `DonationController`,
   `PaymentsTriageController`, `VoteDeliveryController`. The services beneath them are well
   tested; the entrypoints are not.
6. **Then the boundaries (H-1, H-3).** A table-name constant per table, then a repository per hot
   table, then a real migration-on-deploy path so `OptionalColumn` can start shrinking rather than
   growing. This is the large one, and it should follow steps 1–2, not precede them: refactoring
   data access across 149 files without a type checker or visible errors is how a green suite
   ships a regression.

Deliberately not recommended: Redis, read replicas, a search service, CDN, or any horizontal-scale
work. The 2026-06-24 review's reasoning is still correct — those are infrastructure appropriate to
a growth tier this system has not reached, and the bottleneck today is visibility, not throughput.
