<?php
/**
 * Repair the gates_votes indexes that four earlier catch-up migrations failed to
 * create on MySQL.
 *
 * WHY A NEW FILE RATHER THAN THE FIX ALREADY MADE. Those four migrations used
 * `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS`, which MySQL rejects with a
 * 1064. Each was wrapped in try/catch, so it printed a warning and completed — and
 * completing is what matters here, because `MigrationRunner` tracks applied files in
 * the `gates_migrations` ledger and never re-runs one. Correcting those files fixes
 * fresh installs and does NOTHING for any database that already has them recorded,
 * which is every existing deployment. The convention is forward-only, so the repair
 * has to arrive as a new ledger entry.
 *
 * WHAT IT ENSURES, and why each one matters:
 *
 *   idx_votes_device    (device_hash)   — declared in schema.sql, so present on a
 *                                         fresh install; missing on a database old
 *                                         enough to have needed the catch-up.
 *   idx_votes_donation  (donation_id)   — declared in NEITHER base schema file, so
 *                                         missing everywhere on MySQL, fresh
 *                                         installs included. Read on every paid-vote
 *                                         clawback, which scans by donation_id.
 *   per-voter idempotency UNIQUE
 *     (voter_email_hash, idempotency_key)
 *                                       — the one that is a CORRECTNESS gap rather
 *                                         than a performance one. Without it a
 *                                         retried vote can be counted twice.
 *
 * THE NAME OF THAT UNIQUE CONSTRAINT DIFFERS BY DRIVER — `uq_votes_idem` in
 * schema.sql, `idx_votes_idem` in sqlite-schema.sql — so this checks for EITHER
 * before creating anything. Creating a second index over the same columns under the
 * other name would double the write cost of every vote to enforce a constraint that
 * was already enforced.
 *
 * IF DUPLICATES BLOCK THE UNIQUE INDEX it says so with a count and a query, because
 * that is a data problem a human must resolve first and a silent warning is what
 * caused this whole class of defect. It still does not fail the deploy: the other
 * indexes are worth having either way, and an aborted migration run would leave the
 * schema half-repaired.
 *
 * A migration runs EXACTLY ONCE — the ledger sees to that — which is fine for the
 * two plain indexes and wrong for the UNIQUE one, since that legitimately cannot be
 * created while duplicate rows exist. Resolving the duplicates afterwards would
 * otherwise leave the constraint permanently uncreated, which is the same silent gap
 * this repair exists to close. So the logic lives in {@see \AfricaGates\Services\VoteIndexRepair}
 * and is also reachable as `bin/console db:repair-indexes`, re-runnable any time.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

foreach (\AfricaGates\Services\VoteIndexRepair::run()['lines'] as $line) {
    echo $line . "\n";
}

echo "vote index repair OK\n";
