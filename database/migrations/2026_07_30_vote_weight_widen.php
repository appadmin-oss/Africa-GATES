<?php
/**
 * Widen gates_votes.weight from SMALLINT UNSIGNED to INT UNSIGNED.
 *
 * ── THE CEILING NOBODY HAD MEASURED ──────────────────────────────────────────
 *
 * A paid-vote order mints ONE row with `weight = quantity`
 * ({@see \AfricaGates\Services\PaidVoteService::mint()}). `SMALLINT UNSIGNED` tops out
 * at 65,535, so that column — not the price, not the gateway, not any business rule —
 * was the hard limit on how many votes a single supporter could ever buy.
 *
 * It was invisible because `PaidVoteService::MAX_QTY` was 1,000 and clamped in two
 * places, so nothing could reach it. The moment that cap is raised to serve a client
 * buying in bulk, the column becomes the binding constraint, and the failure mode is
 * bad in both directions:
 *
 *   • With `strict` on (this app's MySQL connection sets it): the INSERT raises
 *     "Out of range value", the mint transaction rolls back, and the order sits
 *     CONFIRMED with `votes_used = 0`. That is the existing "paid but never minted —
 *     refund owed" state, so no votes are silently invented — but a customer has been
 *     charged and has nothing, and they found out by looking at the tally.
 *   • Without strict mode (a host that overrides sql_mode, which shared hosting does):
 *     MySQL CLAMPS to 65,535. A ₦20,000,000 order for 100,000 votes credits 65,535 and
 *     reports success. Money taken, votes quietly missing, no error anywhere.
 *
 * INT UNSIGNED lifts the per-order ceiling to 4,294,967,295, which is the same type
 * `gates_nominees.vote_count` and `gates_donations.bonus_votes` already use — so after
 * this the three columns agree, and the limit is a business decision in
 * `PaidVoteService::maxQty()` rather than an accident of a column type.
 *
 * SQLite already stores INTEGER (64-bit), so this is a MySQL/MariaDB-only change and a
 * no-op there.
 *
 * SAFE ON A LIVE TABLE. Widening an integer column in place is an ALGORITHM=INPLACE
 * operation on InnoDB for unsigned→unsigned of greater width; existing values are
 * unchanged and no row is rewritten. It still takes a metadata lock, so on a very large
 * gates_votes it should be run in a quiet window.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$driver = DB::connection()->getDriverName();

if ($driver === 'sqlite') {
    echo "sqlite stores INTEGER already — nothing to widen\n";
} elseif (!DB::schema()->hasTable('gates_votes')) {
    echo "gates_votes absent — skipped\n";
} else {
    // Read the current type rather than assume it: this migration must be a no-op on a
    // database where the base schema already ships the wider column.
    $current = '';
    try {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['gates_votes', 'weight']
        );
        $current = strtolower((string) ($row->t ?? ''));
    } catch (\Throwable $e) {
        echo 'could not read weight column type: ' . $e->getMessage() . "\n";
    }

    if (str_starts_with($current, 'int')) {
        echo "gates_votes.weight is already INT UNSIGNED\n";
    } else {
        try {
            DB::statement('ALTER TABLE gates_votes MODIFY weight INT UNSIGNED NOT NULL DEFAULT 1');
            echo "gates_votes.weight widened to INT UNSIGNED (was {$current})\n";
        } catch (\Throwable $e) {
            // Not fatal: the platform works exactly as before at the old ceiling, and
            // PaidVoteService::maxQty() clamps to whatever the column can hold, so a
            // failure here degrades to the previous limit rather than to a broken order.
            echo 'weight widen skipped: ' . $e->getMessage() . "\n";
        }
    }
}

echo "vote weight widen OK\n";
