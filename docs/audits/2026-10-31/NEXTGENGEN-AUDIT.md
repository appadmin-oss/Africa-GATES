# nextgengen audit — latest branch, 31 October 2026

Audited `/workspace/nextgengen`, branch `claude/finme-dues-training-fees-l54o5j`, commit `a46f01534dc8a3aadbbe1ebfd5923713f55a96d6`. Fetched all origin refs and selected the newest commit date (31 October 2026, 11:39 UTC), rather than the older default branch or main. Tracked source is unchanged.

The current architecture is React globals compiled by esbuild, with a local PHP domain dispatcher and SQLite/MySQL database. Apps Script is now a mirror, not the live transactional backend. Old-branch findings about TOTP reset, stale authorization, proxy-header spoofing and unlocked rate-limit writes do not carry over: the latest branch has explicit protections for them.

## Confirmed findings

### P1 — Paying the exit training fee does not settle it

`api/_lib/domain/fees.php:1023` computes payable as `max(0, realCharged - realCredited) + trainingOwed`. Payments and waivers reduce real charges, but trainingOwed is then added independently. Once a member leaves with no real charges left, no payment can reduce the training amount due.

Reproduction against the actual domain functions and a disposable migrated SQLite database: inactive member with a ₦100,000 training charge; record a successful ₦100,000 cash payment. Before: payable ₦100,000, trainingOwed ₦100,000. After: payable ₦100,000, trainingOwed ₦100,000. The ledger accepts the payment while the account still asks for the full amount. This can cause repeated collection/reminders and makes a genuine settlement impossible through the normal payment path.

Fix: allocate credits consistently across real charges and crystallized exit training liability, preserving the distinction between notional training coverage and collectible debt. Cover full/partial payments, surplus credit, waivers, completed service and departure/re-entry. Do not simply subtract every payment from training: that would double-apply payments already allocated to dues.

### P2 — A retried cash payment creates another credit

`api/_lib/domain/fees.php:1568` accepts payments without an operation identity. `fees_post()` at line 902 generates a fresh random period key when one is absent. The client at `api-client.js:2084` submits no stable idempotency key. A lost response followed by a retry therefore records another incoming payment. Bank-reference checks do not protect cash with an empty reference.

Reproduction: call the same cash-payment operation twice for the same member, amount and note. Both return `ok: true`; two payment rows are stored. No second physical payment took place in the scenario. Financial buttons being disabled during a request does not protect a reload, another tab or retry after an ambiguous response.

Fix: mint a stable client operation ID for the payment attempt and enforce its uniqueness transactionally on the server, returning the original result on replay. Different legitimate cash receipts must still receive distinct IDs. The same design should be assessed for fines and waivers; the confirmed reproduction here is the cash-payment path.

### P2 — Public startup loads the full staff application

`build.js:37` and its compilation loop concatenate 104 source files into one deferred production script, including the staff consoles and specialist modules. This build produced 5,614.7 KiB minified JavaScript (1,563.9 KiB gzip), before the other assets. A first-time visitor to a public page must transfer and parse that application before the SPA is ready. At 1 Mbit/s, the compressed application alone takes roughly 13 seconds to transfer; this is an illustrative bandwidth calculation, not a measured production page-load time.

Fix: retain the supported shared-host deployment but separate public entry code from authenticated/staff and specialist modules, loading those when required. Add an initial JavaScript size budget. A new server framework or Node production server is unnecessary.

### P3 — A backend regression test expires with calendar time

`api/tests/run.php:28465–28473` inserts a journal entry dated 8 October 2026, then expects marking it read to decrement the current unread count. `mp_unread_count()` only includes the last 21 days. On 31 October that fixture is already outside the window, so decrementing the count is the wrong expectation. This is a test defect, not evidence that current entries cannot be marked read.

Fix: derive fixture dates from a controlled clock, or use a relative date that remains within the unread window. Include a separate assertion for expired entries.

## Evidence and validation

- `node build.js`: passed. Build-generated tracked manifest restored to its original contents; source remains clean.
- `php api/tests/run.php`: 6,423 checks passed, one failed (the fixed-date unread fixture above).
- `node tests/prod-csp.test.js`: 11 passed, zero failed; home, journal and timetable render under production CSP, with no CSP violations or unexpected external hosts.
- `node tests/portal-account.test.js`: 50 passed, two failed (read-aloud control presence and journal share control presence). Voice recording, sending and playback, journal save and mentor notification, desktop/phone messaging and sign-out passed. An external copy added explicit waits for those asynchronously loaded controls and passed all 52 checks. This confirms two wait-condition defects in the original browser test; the tracked test is unchanged. The voice and journal flows succeeded.
- Financial proof: `/workspace/nextgengen-audit-proof.php`; run with the prepared PHP runtime. It creates and deletes its own SQLite DB and uses only example.invalid fixtures. No live account or production balance is touched. Output: `/tmp/nextgen-audit-proof.log`.
- Logs: `/tmp/nextgen-build.log`, `/tmp/nextgen-api-audit.log`, `/tmp/nextgen-csp.log`, `/tmp/nextgen-portal.log`, `/tmp/nextgen-portal-recheck.log`.

This is a focused code and local runtime audit of authentication boundaries, finances, member workflows and frontend startup, not a claim that every feature or production integration was verified. Real SMTP, SMS, gateways and external storage were not exercised. The current full backend run used SQLite; production MySQL parity remains a separate check. No nextgengen fixes were applied as part of this audit-only request.
