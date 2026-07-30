<?php
/**
 * Transactional email state for checkout — two claim stamps on gates_donations.
 *
 * WHY COLUMNS AND NOT A LOG TABLE. Both emails must be sent EXACTLY ONCE per
 * order, and both have more than one caller racing to send them:
 *
 *   receipt_sent_at    A paid-vote order confirms from two independent places —
 *                      the browser callback (/vote/paid/callback) and the gateway
 *                      webhook (/pay/webhook), whichever lands first. Without a
 *                      claim both send, and the buyer gets two receipts for one
 *                      payment. `UPDATE … WHERE receipt_sent_at IS NULL` is a
 *                      single statement, so exactly one caller wins — the same
 *                      mechanism `votes_used` already uses to mint votes once.
 *
 *   abandoned_mail_at  The recovery sweep runs every maintenance tick, and the
 *                      rows it selects stay selectable until something records
 *                      that they were mailed. Without a claim the sweep re-emails
 *                      the same abandoned order every fifteen minutes.
 *
 * Nullable timestamps, so this is additive on both MySQL and SQLite with no ENUM
 * or CHECK rebuild. Idempotent. NEVER exit/die here — the runner applies the
 * whole directory in one pass.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$ts     = $sqlite ? 'TEXT' : 'TIMESTAMP';

if (DB::schema()->hasTable('gates_donations')) {
    foreach (['receipt_sent_at', 'abandoned_mail_at'] as $col) {
        if (!DB::schema()->hasColumn('gates_donations', $col)) {
            DB::statement("ALTER TABLE gates_donations ADD COLUMN {$col} {$ts} NULL DEFAULT NULL");
            echo "  + gates_donations.{$col} added\n";
        } else {
            echo "  = gates_donations.{$col} already present\n";
        }
    }

    /**
     * The sweep's query is `status = 'pending' AND abandoned_mail_at IS NULL AND
     * created_at BETWEEN … `. Without this it is a full scan of every order ever
     * placed, on a table that only grows, run on every maintenance tick.
     */
    try {
        if ($sqlite) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_donations_abandon ON gates_donations(status, abandoned_mail_at, created_at)');
            echo "  = idx_donations_abandon ensured\n";
        } else {
            $exists = DB::selectOne(
                "SELECT COUNT(*) AS n FROM information_schema.statistics
                  WHERE table_schema = DATABASE() AND table_name = 'gates_donations'
                    AND index_name = 'idx_donations_abandon'"
            );
            if ((int) ($exists->n ?? 0) === 0) {
                DB::statement('ALTER TABLE gates_donations ADD INDEX idx_donations_abandon (status, abandoned_mail_at, created_at)');
                echo "  + idx_donations_abandon added\n";
            } else {
                echo "  = idx_donations_abandon already present\n";
            }
        }
    } catch (\Throwable $e) {
        // An index is an optimisation. A host that refuses it must not block the
        // columns, which are what correctness depends on.
        echo '  ! idx_donations_abandon skipped: ' . $e->getMessage() . "\n";
    }
} else {
    echo "  = gates_donations absent (fresh install applies the base schema instead)\n";
}

echo "checkout mail state migration OK\n";
