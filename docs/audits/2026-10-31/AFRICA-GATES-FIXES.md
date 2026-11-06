# Africa GATES fixes

Base: `713d180c994066af78a36407db40591dcb3150d3` on `claude/ai-assistance-judges-features-1ka4oz`. Changes are local and have not been deployed.

| Audit item | Implementation | Regression coverage |
|---|---|---|
| A1: duplicate release seals | `SnapshotService::captureRelease` locks the cycle and checks existing captures with a current locking read in one transaction. | `tools/qa/release-race.php`: five two-process rounds, exactly one seal in each. Existing snapshot/released-standing tests also run. |
| A2: publication while voting | `CycleMaterialiser::release` authorizes against the computed phase, regardless of stale stored status. | Added stale-judging/extended-window regression to `CycleMaterialiserTest`. |
| A3: stale current edition | New `CurrentCycle` selector evaluates date-based phases and is shared by `AwardService` and `BallotGuard`. Future unopened editions still do not displace an in-flight edition. | `CurrentCycleTest`, existing phase-guard and award tests. |
| A4: lost-response vote recovery | The browser reuses an attempt key. Replays restore confirmation without repeating side effects, and keys cannot replay another nominee's vote. Code-request and confirmation failures have distinct, truthful messages. | `VoteRetryTest`, `VoteServiceTest`; paired Chromium normal/lost-response check. |
| A5: stale queue ownership | Each job gets a fresh lease; claim/settlement predicates include the attempt version and ownership timestamp. A stale worker cannot acknowledge or reschedule a newer claim. | New long-batch and stale-owner interleaving tests in `QueueServiceTest`. |
| A6: lost Sheets deliveries | Failed vote deliveries throw to the queue's retry path. Missing delivery configuration also fails the job honestly. | `GoogleSheetsQueueTest` exercises the real maintenance handler with 503, rejected 200, invalid response, and accepted delivery. |
| A7: wrong storage-failure message | Only a duplicate constraint with an actual matching voter/category record becomes ALREADY_VOTED. Other rejected writes propagate and roll back. | Targeted non-duplicate QueryException injection in `VoteServiceTest` checks zero votes and an unconsumed OTP. |
| A8: DNS certainty | Failed DNS lookups produce an unknown result; successful routing checks no longer claim mailbox validity or inbox delivery. Supports MX, implicit IPv4/IPv6 routing, and null MX. | `SupportDnsTest`; existing support diagnostic test now uses a deterministic resolver fixture. |

No dependency or lockfile changes and no database migration are required. Historical publication records are not rewritten.

Validation completed:

- 19 new regression cases added. All 143 targeted MySQL tests passed (456 assertions).
- The publication race regression passed five final two-process rounds: two successful callers, one seal per round.
- Chromium normal-response and lost-response cases both confirmed exactly one persisted vote. The lost-response retry reused the same idempotency key and returned the original success. Evidence: `/workspace/audit-africa-gates/recheck/browser-fixed.json`.
- Full SQLite suite: 7,608 tests, 58,099 assertions; one pre-existing colour-budget failure, three pre-existing warnings, two deprecations and one environment-dependent skipped timeout test.
- Full MySQL suite: 7,608 tests, 58,085 assertions; two known failures (the same colour-budget assertion and the proxy-dependent blackhole-timeout test), one pre-existing warning, two deprecations and four intentional MySQL skips. No new failures appeared.
- The colour-budget failure and all three SQLite warnings reproduce on a source archive of the untouched base commit (27-test comparison); class locations were checked to ensure that comparison loaded the original source.
- PHP/JavaScript syntax and `git diff --check` passed.

Full-run logs are `/workspace/.afg-tools/fix-full-sqlite.log` and `/workspace/.afg-tools/fix-full-mysql.log`; the baseline comparison is `/workspace/.afg-tools/baseline-check.log`. The full suites are not green because of those existing issues; none were hidden or disabled.

Added repository: `appadmin-oss/nextgengen` at `/workspace/nextgengen`, default branch `claude/jolly-lovelace-LrNN2`, commit `d2676e1e161a95625920fbe2b4ff8b037f9354b6`. Both repository memberships were saved to the environment draft. Review/save the environment settings and publish to retain that configuration in future environments.
