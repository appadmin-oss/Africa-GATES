# Africa GATES — Enterprise Voting & Nomination System
### Technical Proposal & Implementation Blueprint

**Prepared:** 14 June 2026 · **Status:** Draft for review · **Owner:** Engineering / Afrovanguard

---

## 1. Executive Summary

Africa GATES is built on a sound foundation — a clean Slim 4 / Eloquent service architecture, an exemplary OTP-bound, row-locked vote transaction, a configurable judging portal, and a correct Cultural Power Index (CPI) calculation. The platform is closer to production than its surface gaps suggest.

However, a full-stack audit found a single systemic problem that outweighs everything else: **the three pillars of the published methodology — community votes, expert judging, and fraud protection — are not actually connected to outcomes.**

- **Winners are selected on raw vote counts alone**, ignoring the computed CPI and the 55% expert-judge weight the platform advertises.
- **The fraud engine can never block a vote** — its decision function has no "block" branch — yet the public Integrity Center states that high-risk votes are blocked.
- **The device-fraud layer is dead end-to-end** — a broken client fingerprint that is never stored, feeding signals that can never fire.

These are not abstract risks; each one directly contradicts the public `/integrity` page, which is a trust and (for an awards body) potentially a legal exposure. The encouraging finding is that all three are **small, surgical fixes**.

This proposal sets out a phased path from the current state to an **enterprise-grade** voting & nomination platform that is **strategic** (per-programme/cycle configuration, not hardcoded rules), **flexible** (pluggable verification and vote types), **automated** (queue-driven side-effects, self-advancing cycles, tamper-evident snapshots), **AI-optimized** (advisory AI with human oversight, never in the blocking path), and **production-standard** (observable, horizontally scalable, fully auditable, privacy-compliant).

It is delivered in three phases. **Phase 1 restores methodological integrity** and is mostly days-not-weeks work. Phases 2 and 3 build the automation, configurability, and intelligence that make the system durable at continental scale.

---

## 2. Current State — What Works, What's Broken

### 2.1 What works (keep and build on)

| Component | File | Assessment |
|---|---|---|
| Vote transaction | `src/Services/VoteService.php` | **Exemplary.** OTP-bound, `lockForUpdate` on the token, attempt cap, wrong-code does not consume token, one-vote-per-category by DB `UNIQUE(voter_email_hash, category_id)`, race-safe. |
| CPI math | `src/Services/CpiService.php` | Pure, DB-free, correct. 45% community (per-category cohort-normalised) + 55% judges → 0–1000 → tiers. |
| Judging portal | `src/Judge/**` | OTP auth with session rotation; per-criterion scoring; programme-scoped authorization (`canScore`). |
| Security baseline | `src/Middleware/**`, `public/index.php` | CSRF (timing-safe), session rotation on login, full security-header set + CSP, rate limiting, secure-by-default session cookies. |

### 2.2 What's broken — the three integrity defects (verified against code)

| # | Defect | Location | Impact |
|---|---|---|---|
| **R1** | Winners chosen by raw `vote_count`; CPI & 55% judge weight ignored | `CycleAdvanceCommand.php:106-110` | Outcome contradicts published methodology. |
| **R2** | Fraud `decide()` has no `block` branch; the only enforcement checks `=== 'block'` | `FraudService.php:189-195` ↔ `ApiController.php:94` | No vote is ever blocked. Integrity page claim is false. |
| **R3** | Device fingerprint broken → never persisted on `gates_votes` → 75 of ~95 fraud points can never fire | `afg-features.js:17-55`, `VoteService` insert, drift migration | Single-device bulk voting is invisible. |

### 2.3 Other material gaps

- **Voting is not gated on cycle state** — an approved nominee is votable during nominations, judging, and results phases (`VoteService::castVote` reads the cycle only for a countdown).
- **Judging is unbounded** — uses the latest cycle (not the active one), has no submission lock or window enforcement, and conflict-of-interest is client-side `sessionStorage` only.
- **`gates_vote_snapshots` is a dead table** — tamper-evident standings were designed but nothing writes to it.
- **Nominations are unmoderated** — `SpamService` is wired only into the community forum; nominee names and free-text reasons reach the public shortlist unscreened. Dedup is exact-string only, so "Dr. Jane Doe" and "Jane Doe" split votes.
- **All side-effects run synchronously inside the vote request** — fraud scoring, 1–3 emails, Google Sheets push, milestone check — so a slow SMTP/Sheets call inflates vote latency and can fail the user-visible path.
- **Schema is fragmented** — `INSTALL.sql` and `schema.sql` describe materially different tables; there is no single source of truth.
- **Stale copy** — three transactional emails still state "40%/60%" while the engine uses 45/55.

---

## 3. Design Principles

1. **Configuration over code.** Weights, tiers, milestones, fraud thresholds, eligibility, and winner-selection are data (per programme/cycle), not constants. A new programme runs a different ruleset without a deploy.
2. **The vote request does exactly one thing** — records the vote in a transaction. Everything else is enqueued.
3. **Everything state-changing is auditable** — append-only, hash-chained, reconcilable.
4. **AI advises; humans decide.** No model is ever in the synchronous, blocking path of recording a vote, and every AI decision has a deterministic human-reviewable fallback.
5. **Secure and correct by default; degrade gracefully.** Risk-based friction never drops below today's baseline; missing dependencies fail loud in logs, not silent in production.

---

## 4. Target Architecture

```
                         ┌─────────────────────────────────────────────┐
  Browser / API ───────► │  Slim 4 HTTP edge                            │
                         │  · Idempotency-Key   · structured req log    │
                         └──────┬────────────────────────┬──────────────┘
                                │ enqueue                 │ fast synchronous path only
                                ▼                         ▼
                    ┌─────────────────────┐   ┌──────────────────────────────────┐
                    │ Job queue           │   │ VoteService (txn)                 │
                    │ gates_jobs          │   │  · token lockForUpdate            │
                    │ + worker            │   │  · RuleEngine.gate(cycle, vote)   │
                    │  (queue:work cron / │   │  · unique vote · increment        │
                    │   daemon later)     │   └──────────────────────────────────┘
                    └──────┬──────────────┘
       ┌──────────────────┼────────────────────────┬────────────────────┐
       ▼                  ▼                         ▼                    ▼
 Notifications     Fraud / anomaly           Sheets / CRM push     Snapshot + hash-chain
 (OtpService)      (FraudService v2 + AI)     (GoogleSheets)        (SnapshotService)
       │                  │                                               │
       └──────────────────┴───── all services emit events ───────────────┘
                                         │
                          ┌──────────────┴───────────────┐
                          ▼                               ▼
              Append-only audit (gates_audit,    Metrics endpoint /metrics
              hash-chained)                       + gates_cron_log v2
```

**New core components**

- **`RuleEngine`** — resolves the *effective ruleset* for a `(programme, cycle)` from `gates_rule_sets` (JSON), with the current hardcoded values as defaults. Drives weights, tiers, milestones, fraud thresholds, eligibility, vote-type multipliers, and **winner-selection strategy**.
- **`QueueService` + worker** — turns the synchronous tail of `castVote` into enqueued jobs. On the current single host the worker is a 1-minute cron draining `gates_jobs`; the interface is built so it can move to Redis/SQS + a daemon with no caller changes.
- **`CycleStateMachine`** — replaces the order-fragile date `if`-chain with an explicit transition table, guards, idempotency, and a `gates_cycle_transitions` audit row per change.
- **`SnapshotService`** — activates `gates_vote_snapshots` with a hash chain (`row_hash = sha256(prev_hash + payload)`) for tamper-evident standings, plus a reconciliation job.
- **Append-only `gates_audit`** — hash-chained record of every state-changing admin/judge/system action, extending the existing `AuditService`.

---

## 5. Data Model Evolution

| Change | Table | Rationale |
|---|---|---|
| Add `device_hash` (+ write it) | `gates_votes` | Make the existing device fraud signals function (fixes R3). |
| Add `vote_type` (`standard`/`bonus`/`paid`), `weight`, `donation_id`, `idempotency_key UNIQUE` | `gates_votes` | Paid/bonus votes (wires up the dead `gates_donations.bonus_votes`), weighted tallies, safe retries. |
| **New** `gates_rule_sets(programme_id, cycle_id, key, json_value, version, effective_from)` | — | The configuration engine; replaces hardcoded constants in `CpiService`, `FraudService`, `MilestoneService`, `OtpService`. |
| **New** `gates_jobs(type, payload, status, attempts, run_after, idempotency_key, locked_at)` | — | Async pipeline backbone. |
| **New** `gates_audit(actor_*, action, target_*, before_json, after_json, prev_hash, row_hash)` | — | Hash-chained, tamper-evident trail. |
| **New** `gates_cycle_transitions(cycle_id, from_status, to_status, reason, actor)` | — | Auditable FSM history. |
| Activate + add `prev_hash` | `gates_vote_snapshots` | Tamper-evidence (currently dead). |
| **New** `gates_judge_submissions(judge_id, cycle_id, locked_at)` + `gates_judge_coi(judge_id, nominee_id, declared, reason)` | — | Server-side judging lock + conflict-of-interest (replaces client-only `sessionStorage`). |
| **New** `gates_nominee_identities(nominee_id, canonical_key, dedup_cluster_id, confidence)` | — | Backs AI/normalised dedup. |
| Add `consent_token`, `consent_at`, `retention_expires_at` | `gates_nominations` | GDPR/NDPR consent + retention/erasure. |
| **Consolidate** schema into one migration runner; drop the orphan `gates_judge_scores`; make `gates_judge_criteria*` canonical | — | Ends schema drift. |

---

## 6. Strategic — Configurable Rule Engine

Today the platform's policy is scattered across hardcoded constants: weights in `CpiService` (`0.45/0.55`), tiers in `CpiService::TIERS`, milestones in `MilestoneService::MILESTONES`, fraud weights/thresholds in `FraudService`, and the disposable-domain list in `OtpService`. None of it can change without a deploy, and none of it can differ per programme.

**`RuleEngine::effective(programmeId, cycleId): RuleSet`** resolves all of this from `gates_rule_sets`, with the current values as defaults so behaviour is identical until a rule is changed.

- `CpiService::nomineeScore` takes weights as **parameters** instead of hardcoded constants; `CpiRecomputeCommand` resolves them per cycle. A choral programme can run 50/50 while a business programme runs 30/70 — no code change.
- **Winner-selection becomes a configurable strategy** (default `cpi_desc`, using the computed `cpi_score`, gated on completed judging). This is the structural fix for R1.

---

## 7. Flexible — Verification, Eligibility, Vote Types, i18n

- **`VerifierInterface`** with `EmailOtpVerifier` (today), `SmsOtpVerifier`, `TurnstileOnlyVerifier`, `TrustedDeviceVerifier`. The RuleEngine picks the required verifier(s) per cycle, and **risk score selects friction** (see §9): low-risk returning device → Turnstile-only; high-risk → email + SMS.
- **`EligibilityPolicy`** (config-driven): geo allow-list, one-vote-per-category vs per-programme, minimum account age, paid-vote caps. Today eligibility is two hardcoded rules.
- **Vote types:** a `BonusVoteService` mints weighted `gates_votes` rows tied to a confirmed `donation_id`, wiring up the dead `gates_donations`. The CPI community component sums `weight` rather than `COUNT(*)`. **Paid votes are excluded from the judge component and surfaced transparently** to preserve integrity.
- **i18n:** externalise inline copy (the source of the stale weight strings) to locale files — important for francophone Africa as the platform scales toward 54 nations.

---

## 8. Automated — Pipelines, State Machine, Snapshots, Reminders, Idempotency

- **Async side-effects:** `castVote` returns immediately after the transaction commits; `fraud.rescan`, `notify.vote_confirm`, `sheets.push`, `community.activity`, and `milestone.check` are enqueued. This decouples vote latency from Brevo/Sheets and removes partial-failure risk from the user path.
- **State machine:** an explicit transition table with guards (e.g. cannot enter `voting` without `voting_open`) replaces the fragile date `if`-chain; every transition is logged to `gates_cycle_transitions`.
- **Reminders fixed:** `sendVotingReminders()` is invoked from `auto` mode, queries the correct `status='approved'` (the current `status='active'` matches nothing), joins `gates_newsletter`, and is idempotent per cycle.
- **Snapshots:** `SnapshotService` runs hourly during `voting`/`judging`, writing per-nominee standings with a hash chain — a verifiable, tamper-evident record exposed via a public verification endpoint.
- **Idempotency:** `/api/vote` and `/api/nominations` accept an `Idempotency-Key`; a retry returns the original result instead of a misleading `ALREADY_VOTED`.

---

## 9. AI-Optimized — Advisory Intelligence with Human Oversight

All AI reuses the existing `SpamService` multi-provider pattern (heuristics first, AI on borderline, short timeout, graceful degradation). **No model is ever in the synchronous blocking path of recording a vote.**

| # | Capability | Model role · inputs → outputs | Plugs into | Human fallback |
|---|---|---|---|---|
| 1 | **Nomination dedup & normalisation** | Embed `name+country+reason`; cosine-cluster vs existing nominees → `dedup_cluster_id` + confidence | approve path (replaces exact match) | low → auto-link; mid → admin "possible duplicate" prompt |
| 2 | **Nomination content moderation** | Reuse `SpamService.evaluate()` on reason + name → `{decision, score, reason}` | `AwardService::submitNomination` | reject → hidden; quarantine → admin queue; allow → pending |
| 3 | **Collusion / anomaly detection** | Nightly graph pass over votes+events for shared device/IP/timing rings → cluster risk + explanation | `CollusionScanCommand` + alert digest | flags clusters only; admin bulk-reviews & voids with audit |
| 4 | **Risk-based OTP friction** | Risk model on device trust / IP reputation / funnel behaviour → 0–1 | pre-OTP in `otpRequest` | high-risk or model down → full email-OTP (never less than today) |
| 5 | **Judge-assist** | Neutral 3-bullet brief from reason + references; z-score outlier flag | ballot panel; recompute | advisory only; outlier → human re-review |

---

## 10. Anti-Fraud & Integrity Strategy (consolidated)

1. **Make blocking real** — add `'block'` to `FraudService::decide` at the rule-configured threshold; keep the monitor/flag bands and surface the currently-hidden 60–79 band for review. *(R2)*
2. **Make device identity real** — persist `device_hash` on `gates_votes`; replace the broken client fingerprint with a corrected, higher-entropy hash, treated as one signal among server-side ones (IP, velocity, graph) — never the sole gate. *(R3)*
3. **Bind OTP to device/IP** at issue; verify continuity at cast.
4. **Gate voting on cycle state** — reject when the cycle is not `voting`. *(R4)*
5. **Lock judging windows + server-side COI** — `gates_judge_submissions` lock; reject score writes outside the window; persist COI. *(R6)*
6. **Tamper-evidence** — activate `gates_vote_snapshots` with a hash chain + a reconciliation job (`SUM(weighted votes)` vs `gates_nominees.vote_count`). *(R5)*
7. **Select winners from CPI**, gated on completed judging. *(R1)*

---

## 11. Production-Standard — Observability, Scale, Privacy, Testing, Rollback

- **Observability:** structured request logging at the edge; a `/metrics` endpoint (votes/min, OTP delivery success, fraud-decision mix, queue depth, recompute duration); populate `gates_cron_log.runtime_ms` (currently null).
- **Scale:** make CPI recompute **incremental** (only dirty cohorts) instead of a full-table `exec("php …")` every 6 hours; move the queue to Redis/SQS when load warrants — the `QueueService` interface keeps callers unchanged.
- **Privacy (NDPR/GDPR):** votes stay pseudonymous (already hashed); add consent token + retention expiry + an erasure job for nomination PII (currently stored in plaintext indefinitely).
- **Testing:** `CpiService` is already pure and unit-tested — extend with property tests across weight configs; add integration tests for the `VoteService` transaction (double-vote race, wrong-OTP non-consumption, cycle gate), the state-machine transition table, and the queue worker (at-least-once + idempotency).
- **Rollback:** ship the RuleEngine with current values as defaults (behaviour identical until a rule changes); feature-flag async side-effects with a synchronous fallback; all migrations reversible; the snapshot chain makes any data correction auditable.

---

## 12. Phased Roadmap

> Sizing: **S** ≈ ≤2 days · **M** ≈ 3–5 days · **L** ≈ 1.5–3 weeks.

### Phase 1 — Integrity Hardening (≈ 3–4 weeks, 1–2 devs)
*Restores the published methodology. Mostly surgical.*
- **R1** — select winners from CPI, gated on judging complete. *(M)*
- **R2** — `FraudService::decide` emits `block` at threshold. *(S)*
- **R3** — persist `device_hash` on `gates_votes` + write it in `VoteService`; fix the fingerprint. *(S)*
- **R4** — gate voting on `status='voting'`. *(S)*
- Wire `SpamService` into nominations; first-pass normalised dedup. *(M)*
- Fix the three stale "40/60" emails; fix + enable voting reminders. *(S)*
- Consolidate schema into one migration runner; drop the orphan judge table. *(M)*
- Lock judging window + server-side COI. *(M)*
- Sanitise admin-authored HTML (`|raw` XSS); remove the magic-link log writer; require an env-set seed password. *(S–M, security criticals)*

### Phase 2 — Automation & Configuration (≈ 4–6 weeks)
- `RuleEngine` + `gates_rule_sets`; parameterise CpiService / Fraud / Milestone. *(L)*
- Queue + worker; move vote side-effects async; idempotency keys. *(L)*
- State-machine refactor + `gates_cycle_transitions`. *(M)*
- Activate `gates_vote_snapshots` hash chain + reconciliation + `/metrics`. *(M)*
- Wire `gates_donations` / bonus & paid vote types. *(M)*

### Phase 3 — AI & Scale (≈ 6–8 weeks)
- The five AI components (dedup, moderation, collusion, risk-based friction, judge-assist). *(L)*
- Pluggable verifiers incl. SMS; i18n externalisation. *(M)*
- Incremental CPI recompute; Redis/SQS queue; GDPR consent / retention / erasure. *(L)*

---

## 13. Appendix — Key Files & References

- **Voting:** `src/Controllers/ApiController.php`, `src/Services/VoteService.php`, `src/Services/OtpService.php`
- **Nomination:** `src/Controllers/NominationController.php`, `src/Services/AwardService.php`, `src/Admin/Controllers/NominationsController.php`
- **Judging:** `src/Judge/Services/JudgeService.php`, `src/Judge/Controllers/{Auth,Ballot}Controller.php`, `templates/judge/ballot.twig`
- **Integrity:** `src/Services/FraudService.php`, `src/Services/SpamService.php`, `src/Services/RateLimitService.php`, `public/assets/js/afg-features.js`
- **CPI / automation:** `src/Services/CpiService.php`, `src/Console/Commands/CpiRecomputeCommand.php`, `src/Console/Commands/CycleAdvanceCommand.php`, `cron/maintenance.php`
- **Schema:** `database/sqlite-schema.sql`, `database/INSTALL.sql`, `database/migrations/`

*This blueprint is grounded in a line-level audit of the codebase as of 14 June 2026. The three integrity defects (R1, R2, R3) were verified directly against source.*
