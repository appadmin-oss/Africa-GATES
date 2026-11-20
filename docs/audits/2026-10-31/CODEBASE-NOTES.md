# Africa GATES: development map

Inspected branch: `claude/ai-assistance-judges-features-1ka4oz`, commit `713d180c994066af78a36407db40591dcb3150d3`. This was the remote branch with the newest tip commit on 31 October 2026. `master` remains substantially behind it.

## Architecture

A PHP 8.4 server-rendered monolith: Slim 4 routing, Twig templates, PHP-DI wiring, and Illuminate Capsule used as a query builder against `gates_*` tables. There is no Eloquent model layer and no mandatory Node build.

Requests enter `public/index.php`, which loads configuration, starts sessions, initializes Capsule and the DI container, attaches middleware, and loads `src/routes.php`. `public/router.php` is the supported built-in-server router. `config/container.php` connects controllers, services, Twig globals, and logging. `config/database.php` selects MySQL or SQLite and pins the database session timezone.

## Domain map

- Awards: programmes → editions/cycles → categories → nominations/nominees → shortlist → voting/judging → released results. Start with `CyclePolicy` / `CyclePhase`, `AwardService`, `ShortlistService`, `NomineeScoringService`, and `ReleasedStanding`; inspect the current resolver before duplicating date/status decisions.
- Voting: `VoteService`, `OtpService`, `FraudService`, `RuleEngine`, and `BonusVoteService`; the write path uses database transactions, token/identity validation, unique constraints, and idempotency. `SnapshotService` and `CollusionService` support audit/integrity work.
- Payments: `PaymentService` handles gateway APIs, with controllers and domain services performing server-side confirmation and fulfillment. This branch also includes recurring gifts, shop orders, event tickets, refunds, reconciliation, and referrals. Read the relevant flow before treating a callback as proof of payment.
- Public content: profiles/registry, CPI leaderboard, events, blog, opportunities, shop, donations, community/Pulse, and member accounts.
- Admin: `src/Admin/` contains controllers, middleware, RBAC and audit services. Judge access and scoring live separately under `src/Judge/`.
- AI: `AiGateway` is the common capability/budget/privacy/schema-validation/logging boundary. Interview/questionnaire features have their own detailed handoff in `docs/INTERVIEW-BOT-HANDOFF.md`.
- Automation: `bin/console` exposes operational commands. `cron/maintenance.php` is the shared maintenance entrypoint; the database-backed queue handles deferred work. Real outbound integrations need separate credentials and validation.

## Data and validation

`MigrationRunner` is shared by CLI and web setup and records schema files plus filename-ordered dated migrations in `gates_migrations`. Fresh MySQL setup completed 244 steps. Migration filenames extend into 2027 even though the tip commit is dated October 2026; the runner selects by filename/ledger, not today's date.

The default PHPUnit harness uses in-memory SQLite. Opt in to MySQL using `TEST_DB_DRIVER=mysql` and a disposable, separate database. The harness drops/rebuilds tables: never point it at the development or production database. MySQL strict mode, ENUMs, integer widths, foreign keys, indexes, and timezone handling are material differences.

Read `CLAUDE.md`, `HANDOFF.md`, and `docs/CODEBASE-INDEX.md` before changes. These contain historical material; verify assertions against current code. A local `.env` can contaminate AI tests, and maintenance markers left by a running web app can contaminate maintenance tests. The local test wrapper temporarily moves `.env`, clears those markers, uses `africa_gates_test`, and restores configuration in a finally block.

## Local environment

- Activate tools: `export PATH=/workspace/.afg-tools/bin:$PATH`.
- MySQL: `/workspace/.afg-tools/mysql-start.sh`, published only on `127.0.0.1:3306`.
- Database files: `/workspace/.afg-tools/mysql-data` (persistent host bind mount).
- Development DB: `africa_gates`; parity DB: `africa_gates_test`.
- Local generated credentials are kept in private setup files and the ignored `.env`; do not paste them into chat or commit them.
- Web: from the repository root, `php -S 127.0.0.1:8000 -t public public/router.php`.
- Migration: `php bin/console db:migrate`.
- Full MySQL suite: `python /workspace/.afg-tools/mysql-tests.py` (log: `/workspace/.afg-tools/mysql-tests.log`). Avoid app requests during the test run while `.env` is temporarily absent.
- Build static CSS: `php bin/console assets:build`.

Each cloud task is already isolated. Use the existing checkout; do not create a worktree unless requested.

## Validation results

MySQL 8.4.13 ran with strict mode and ONLY_FULL_GROUP_BY. All 244 migration steps applied; a second migration run reported everything already applied. The complete installer reran successfully. Frontend CSS built successfully, and /ping, /, /awards, /registry (now titled Discover), /vote, and /admin/login (sign-in entry) returned HTTP 200 with expected content against MySQL. Tracked repository files remain unchanged.

The full MySQL suite completed 7,589 tests and 57,981 assertions, with 13 failures, 4 warnings, 2 deprecations, and 4 skips. After fixing PHP's writable session path and routing PHP diagnostics to stderr (to avoid marking headers sent before session configuration), a focused 74-test rerun resolved all ten session-related failures and retained only three failures: ColourBudgetTest reports six templates exceeding their declared colour budget; ProviderBreakerTest expects a blackholed connection timeout but this environment returns an HTTP 403 proxy policy denial; SupportResilienceTest assumes live DNS resolution of gmail.com mail records, which is unavailable here. Do not describe the full suite as green; it was not rerun in full after the environment corrections. The source/test files were preserved.

Logs: /workspace/.afg-tools/mysql-tests-full.log and /workspace/.afg-tools/mysql-tests-session-diagnostics.log. The test wrapper accepts PHPUnit arguments for targeted reruns.

The four warning/skip cases were retested: 30 tests, 116 assertions, exit 0, four intentional MySQL skips and no warnings. The full run warnings arose during session setup; the affected tests now complete without them. The four skips explicitly cover SQLite-only destructive schema cases. PHP 8.4 source deprecations remain.
