# Africa GATES audit — logic, architecture and UX

Audited 31 October 2026, branch `claude/ai-assistance-judges-features-1ka4oz`, commit `713d180c994066af78a36407db40591dcb3150d3`. Repository source, tests, dependency declarations and lockfile were left unchanged.

Second-pass review: two P1 defects, five P2 defects, and one P3 conditional queue-hardening issue. All eight failure mechanisms were reproduced, but reproduction does not establish their frequency in production. P1 denotes high-priority publication correctness; P2 denotes a material functional or UX defect; P3 denotes a demonstrated weakness whose ordinary single-host exposure is mitigated. This is a focused engineering audit, not a claim that every feature has been exhaustively verified.

| ID | Priority | Finding | Areas |
|---|---|---|---|
| A1 | P1 | Concurrent release captures can change the supposedly immutable published standing | Architecture, award integrity |
| A2 | P1 | Manual release accepts a stale stored phase while voting is actually open | Logic, award integrity |
| A3 | P2 | An old results edition hides a newer, date-open nomination edition | Logic, architecture, UX |
| A4 | P2 | A committed vote is reported as unrecorded after a lost response; retries cannot recover it | UX, integration logic |
| A5 | P3 | Stale queue ownership permits duplicate execution and resurrects completed jobs | Architecture, concurrency |
| A6 | P2 | Failed Google Sheets jobs are marked done without retry | Logic, operational reliability |
| A7 | P2 | Every vote insert failure is mislabeled as an existing vote | Logic, UX, diagnosis |
| A8 | P2 | DNS failure becomes confident, false advice that a valid email domain cannot receive mail | UX, diagnostic logic |

## A1 — Concurrent release captures overwrite the public meaning of a seal

References: [SnapshotService.php:147](/workspace/Africa-GATES/src/Services/SnapshotService.php:147), [SnapshotService.php:179](/workspace/Africa-GATES/src/Services/SnapshotService.php:179), [SnapshotService.php:211](/workspace/Africa-GATES/src/Services/SnapshotService.php:211), [ReleasedStanding.php:104](/workspace/Africa-GATES/src/Services/ReleasedStanding.php:104).

`captureRelease()` checks whether a release exists before entering the appender's transaction. Two callers can both observe absence. If the second reaches the appender after the first commits, it locks the new chain tail and successfully appends a second release. The unique previous-hash constraint prevents a chain fork; it does not enforce one release per cycle.

Confirmed with two real PHP processes and query-boundary barriers against MySQL. Both calls returned one row written. With an audit-only tally change between them, release rows for the same nominee contained 2 and 99 votes. `ReleasedStanding::forCycle()` orders all release rows ascending and overwrites entries by nominee ID, so it returned 99 while retaining the first capture's timestamp. A valid hash chain therefore does not guarantee an immutable announcement.

Fix: make a cycle's release a uniquely claimed aggregate/header inside a transaction, lock the cycle before checking/creating it, and associate snapshot rows with that release ID. Read exactly that capture, not all rows labeled `release`. Test the interleaving where both callers pass the existence check and the second appends after the first commits.

Second-pass verification called the actual `CycleMaterialiser::release()` method in two real processes, with no tally or rule changes between callers. All three concurrent runs created two captures; the sequential control created one and correctly returned `sealed: 0` for the second call. Concurrency was deliberately coordinated with barriers to establish the allowed interleaving, not to measure its production frequency. The original 2→99 example deliberately changed an audit tally to demonstrate the public reader's behavior; it was not an observed production tally change.

Evidence: [release-race.json](/workspace/audit-africa-gates/recheck/release-race.json), [seal-race-details.json](/workspace/audit-africa-gates/seal-race-details.json), [seal-race.py](/workspace/audit-africa-gates/seal-race.py), [seal-worker.php](/workspace/audit-africa-gates/seal-worker.php).

## A2 — Stored phase can authorize publication during voting

Reference: [CycleMaterialiser.php:423](/workspace/Africa-GATES/src/Services/CycleMaterialiser.php:423).

`release()` refuses only when BOTH stored and computed phases precede judging. If stored status is `judging` while the actual date windows say `voting`—for example after voting dates are extended or materialized status drifts—the stored value bypasses the computed guard.

Confirmed with a cycle whose voting close was seven days in the future. `CyclePolicy` returned `voting`; stored status was `judging`. `release()` returned `ok: true`, changed stored status to `results`, and created a release capture. That lets the platform publish a standing while its date-based authorization still accepts votes.

Fix: authorize release using the computed phase, under the same cycle lock used for publication. If an early/manual override is a product requirement, make it an explicit, audited operation that also closes voting atomically; do not infer permission from a cache column.

Second-pass verification used the real `ProgrammesController::cycleSave()` to extend a closed voting window while leaving stored status at `judging`. The editor returned “Cycle saved”; computed phase became `voting`, and `release()` still succeeded. This confirms a supported editor path to the drift condition, not merely a hand-edited database scenario.

Evidence: `A2_real_editor_extension` in [verify.json](/workspace/audit-africa-gates/recheck/verify.json), `premature_release` in [logic-repros.json](/workspace/audit-africa-gates/logic-repros.json).

## A3 — The previous edition can block the current nomination window (P2, stale-status condition)

References: [BallotGuard.php:191](/workspace/Africa-GATES/src/Services/BallotGuard.php:191), [AwardService.php:18](/workspace/Africa-GATES/src/Services/AwardService.php:18), [AwardService.php:57](/workspace/Africa-GATES/src/Services/AwardService.php:57).

The current-cycle query ranks stored statuses `nominations`, `shortlisting`, `voting`, `judging`, and `results` ahead of `upcoming`, before considering year or ID. A previous year's `results` row therefore beats a newer row still marked `upcoming`, even when the newer row's nomination dates are already open. Computing the correct phase after selecting the wrong edition cannot repair this.

Confirmed with a 2025 results cycle and a 2026 upcoming cycle whose nominations opened yesterday. The new cycle computed as `nominations`; `currentCycleForProgramme()` selected the 2025 row and `assertNominable()` refused entry. The same selection expression is duplicated in public award readers.

**Second-pass qualification:** this happens while the new edition's stored status remains stale. A passing control changed that status to `nominations`, and both public and write-side selectors then chose the new edition. A future unopened edition correctly left the older edition selected. This is not a blanket rule that the newest edition must always win. A healthy materialization tick can remove the immediate stale condition; downgraded from P1 to P2. The original reproduction read the public result using the wrong array key and recorded null; the corrected second-pass probe reads `["cycle"]["id"]` and confirms that the public reader selects the old edition too.

Fix: establish a single authoritative current-edition selector, with an explicit active edition relationship or a date-aware selection policy. Use it for public readers and write guards. Include old results + new upcoming, multiple editions in one year, and scheduler downtime in regression coverage.

Evidence: `cycle_selection` in [logic-repros.json](/workspace/audit-africa-gates/logic-repros.json).

## A4 — Lost-response recovery tells a successful voter the opposite of what happened

References: [vote.js:223](/workspace/Africa-GATES/public/assets/js/vote.js:223), [vote.js:233](/workspace/Africa-GATES/public/assets/js/vote.js:233), [vote-nominee.twig:211](/workspace/Africa-GATES/templates/pages/vote-nominee.twig:211), [VoteService.php:71](/workspace/Africa-GATES/src/Services/VoteService.php:71).

The browser does not send an idempotency key, although the server supports one. Its network-error text asserts that nothing was recorded. A network failure cannot establish that: the server may have committed before the response was lost.

Confirmed in Chromium against the real audit server: the vote endpoint returned HTTP 200 with `success: true` and incremented the tally. The browser interception discarded only that response. The visible ballot said, “We couldn’t reach Africa GATES. Nothing was recorded. Try again.” Retrying sent the consumed OTP again and showed “Invalid or expired code.” The person remains on the code screen despite having successfully voted.

Fix: generate and retain one idempotency key for each voting attempt and reuse it across retries; bind replay validation to the same nominee/category. Use uncertainty-aware copy and provide a way to check confirmation. Ensure a retry returns the original confirmed result. Avoid duplicate post-vote emails/events on replay as part of the contract.

Second-pass verification used paired fresh-browser controls. Normal response: one persisted vote and visible confirmation. Dropped response: one persisted vote, false “Nothing was recorded” message, then `INVALID_OTP` on retry. A separate backend control confirmed that sending a stable idempotency key returns the original success on replay.

Evidence: [browser-controls.json](/workspace/audit-africa-gates/recheck/browser-controls.json), [vote-flow.json](/workspace/audit-africa-gates/vote-flow.json), [mobile screenshot](/workspace/audit-africa-gates/vote-network-retry-mobile.png), [browser reproduction](/workspace/audit-africa-gates/vote-flow.cjs). The OTP-request response was mocked to avoid sending a message; the vote write and retry reached the real application.

## A5 — Queue lease/ownership weakness (P3, conditional exposure)

Reference: [QueueService.php:79](/workspace/Africa-GATES/src/Services/QueueService.php:79), [QueueService.php:107](/workspace/Africa-GATES/src/Services/QueueService.php:107), [QueueService.php:118](/workspace/Africa-GATES/src/Services/QueueService.php:118).

`$now` is captured once at the start of `work()` and reused for every claim. If earlier jobs consume more than the five-minute lease timeout, a later job receives a lock timestamp that is already stale when it is first claimed. A concurrent worker can claim it immediately. Completion and failure updates then filter only by job ID, not claim ownership, so an old worker can overwrite the new worker's outcome.

Confirmed with controlled Carbon time and interleaved workers: an earlier job advanced the clock six minutes; the next job ran twice. Worker B completed it, then worker A's simulated failure changed the same job back to `pending`. This is stronger than the ordinary at-least-once delivery caveat: a successfully completed job was explicitly resurrected by a stale owner.

**Second-pass qualification:** the only production call to `QueueService::work()` is in Maintenance, and the CLI, webcron and opportunistic-web entrypoints acquire the same `CronGuard` file lock. A two-process control confirmed that the second worker cannot acquire that lock on one host. The earlier queue reproduction bypasses those entrypoint guards. Therefore it establishes a queue-class concurrency defect, not that ordinary guarded single-host operation currently runs jobs concurrently. Exposure requires separate hosts/filesystems, inability to create the fail-open lock file, or another unguarded caller. Downgraded from P2 to P3; not an immediate single-host incident claim.

Fix: compute claim time per job, use a unique ownership token or monotonically increasing claim version, and condition completion/retry/poison updates on that ownership. Long handlers need a bounded execution policy or lease renewal. Keep handlers idempotent as an additional safeguard.

Evidence: `stale_queue_owner` in [logic-repros.json](/workspace/audit-africa-gates/logic-repros.json).

## A6 — Sheets delivery failure is acknowledged as a completed durable job

References: [GoogleSheetsService.php:80](/workspace/Africa-GATES/src/Services/GoogleSheetsService.php:80), [GoogleSheetsService.php:94](/workspace/Africa-GATES/src/Services/GoogleSheetsService.php:94), [Maintenance.php:1112](/workspace/Africa-GATES/src/Support/Maintenance.php:1112), [QueueService.php:117](/workspace/Africa-GATES/src/Services/QueueService.php:117).

`push()` reports failure with `false`. `pushVote()` discards that return value and returns void. The maintenance handler also discards the result, while the queue retries only when a handler throws. Google downtime, a rejected token, or quota exhaustion therefore consumes the only queued job.

Confirmed using the existing injectable Sheets transport returning HTTP 503: the worker reported `done: 1`, `retried: 0`; the row was `done`, attempts 1, last_error null. No external request was sent.

Fix: give queued delivery an explicit success/failure contract and throw or return a structured retry outcome on transient failure. Distinguish an intentionally disabled integration from a configured integration that failed. Preserve the failure reason and provide dead-letter replay.

Second-pass verification used the actual `Maintenance::run("queue")` registration path and compared HTTP 503 and HTTP 200 transport controls. Both were marked done, with no retries/failures; the 503 is therefore acknowledged through the real handler too.

Evidence: `A6_actual_maintenance_503` in [verify.json](/workspace/audit-africa-gates/recheck/verify.json), `lost_sheet_job` in [logic-repros.json](/workspace/audit-africa-gates/logic-repros.json).

## A7 — Storage faults are presented as “already voted”

Reference: [VoteService.php:151](/workspace/Africa-GATES/src/Services/VoteService.php:151).

The vote INSERT catches every `Throwable` and returns `ALREADY_VOTED`, regardless of the actual error. That includes schema problems, rejected writes and operational failures, not just the intended duplicate-voter constraint.

Confirmed by injecting an audit-only MySQL trigger that rejected the insert with a non-duplicate SQLSTATE. With no vote recorded, the service returned “You have already voted in this category.” The trigger was removed after the check.

Fix: classify the specific duplicate-key violation and verify the matching voter/category record. Log and surface other failures as retryable server errors, with appropriate rollback behavior and without claiming that a vote exists.

Second-pass verification used a trigger targeted to one audit voter. A different voter succeeded and replayed successfully with a stable key; the injected non-duplicate failure returned `ALREADY_VOTED` with zero recorded votes. The test asserts the injection state, and the trigger was removed afterward. This is a verified error-classification fault under simulated storage rejection, not a claim that production storage is currently failing.

Evidence: [vote-controls.json](/workspace/audit-africa-gates/recheck/vote-controls.json), `vote_storage_failure` in [logic-repros.json](/workspace/audit-africa-gates/logic-repros.json).

## A8 — Support confuses an unavailable resolver with an invalid address

Reference: [SupportTools.php:185](/workspace/Africa-GATES/src/Services/SupportTools.php:185), [SupportTools.php:202](/workspace/Africa-GATES/src/Services/SupportTools.php:202).

`checkdnsrr()` returning false does not distinguish “record absent” from “lookup failed.” The catch does not cover the common false-return case. When both lookups fail, the support tool returns `ok: true`, `reason: no_mx`, and tells the assistant to state that the domain cannot receive mail and that the problem is not the platform's delivery.

Confirmed in this environment: `emailDomain('audit@gmail.com')` said Gmail has no mail server and recommended changing the address. This is a code-level diagnostic error triggered by the environment's DNS limitation, not evidence that Gmail is misconfigured. Conversely, a domain having MX/A records also does not prove that a particular mailbox is valid or that a message reached spam, despite the success copy's certainty.

Fix: model DNS results as present / authoritatively absent / unknown, distinguish resolver errors and timeouts, and return uncertainty rather than blaming the address. Provider acceptance, bounce events and message delivery logs should inform delivery advice; DNS alone cannot establish mailbox validity or inbox delivery.

Second-pass DNS controls supplied the native resolver's two relevant return behaviors without changing repository code: healthy MX lookup produced `accepts_mail`; a non-throwing failed lookup produced the false `no_mx` assertion. The diagnosis is conditional on resolver unavailability and is not a claim that the production resolver is unavailable. Evidence: [dns-controls.php](/workspace/audit-africa-gates/recheck/dns-controls.php).

## Architectural direction

The highest-value changes are consistency boundaries rather than a framework rewrite:

1. One current-edition and phase policy for both reads and writes. Stored status should not silently regain authority in selectors or manual actions.
2. One transactional publication operation with a unique release identity, explicit state transition and immutable snapshot association. A hash chain is an integrity mechanism, not a substitute for business uniqueness.
3. Explicit background-delivery outcomes, owned queue claims and conditional settlement. Boolean-returning transports and exception-only retry semantics currently disagree.
4. A shared browser/server retry contract for irreversible actions, with stable idempotency keys and truthful recovery states.

The broad route/container files (4,983 and 1,022 lines respectively) make ownership harder to follow, but size alone is not treated as a defect. The reproduced selector duplication and contract mismatches above are the concrete reasons to extract focused domain boundaries.

## UX observations and validation limits

Ten public routes were exercised at 390px and 1440px widths in Chromium, including home, awards, voting, registry, nominations, sign-in, giving, shop and events. All returned HTTP 200 in those checks; no page-level JavaScript errors, local failed-resource responses, or horizontal document overflow were observed. The mobile ballot was also exercised through code entry and a failed-response retry.

The most consequential UX defects found are trust and recovery: successful votes represented as failures, publication permitted in an incompatible phase, and support giving confident but false delivery advice. The existing colour-budget assertion failure from setup remains a separate visual-consistency issue; it is not elevated above those functional defects.

Tests were performed against `africa_gates_audit`, a disposable clone of the local development database. Reproductions and artifacts are outside the repository. The earlier full MySQL suite was not rerun for this audit. On the second pass, 106 existing MySQL tests across voting, phase guards, materialization, queues, Sheets, snapshots and released standings passed with 337 assertions (104 tests/330 assertions in the main targeted run, plus 2 Sheets tests/7 assertions). New checks targeted stale-state and concurrent interleavings that those existing cases do not exercise. Test output: [existing-tests.log](/workspace/audit-africa-gates/recheck/existing-tests.log) and [sheets-tests.log](/workspace/audit-africa-gates/recheck/sheets-tests.log). Live payment-provider settlement, real outbound mail/SMS, load testing, and authenticated console journeys were not exercised end to end. This report should not be read as clearance of those areas.

Browser scan: [browser-scan.json](/workspace/audit-africa-gates/browser-scan.json). Logic reproduction: [logic-repros.php](/workspace/audit-africa-gates/logic-repros.php). No production data was accessed or changed.
