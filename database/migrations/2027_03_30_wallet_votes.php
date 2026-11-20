<?php
/**
 * Votes paid from a member's wallet (WalletVotes).
 *
 * One row per purchase: who spent, from which page's community return, on which nominee,
 * how many votes for how much, and the vote row and ledger row it produced. The money
 * itself never moves through a gateway — it leaves the community-return ledger as a
 * `payout` entry (the type that already means "left the nominee's balance") and the
 * votes are written beside it in the same transaction — so this table is the receipt
 * and the join between the two, not a second ledger.
 *
 * Deliberately NOT gates_donations. Fifty-odd readers treat a confirmed row there as
 * money that arrived through a gateway: revenue screens, the milestone's "eligible
 * paid-vote revenue", the community-return accrual, the qualifying-support count and the
 * refund sweep that sends unminted orders back to Paystack. A wallet spend is none of
 * those — no new money came in — and each reader would have had to learn to exclude it.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_wallet_votes')) {
    DB::statement($sqlite
        ? "CREATE TABLE gates_wallet_votes (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              reference TEXT NOT NULL,
              user_id INTEGER NOT NULL,
              source_nominee_id INTEGER NOT NULL,
              nominee_id INTEGER NOT NULL,
              votes INTEGER NOT NULL,
              amount_kobo INTEGER NOT NULL,
              vote_id INTEGER NULL,
              ledger_id INTEGER NULL,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )"
        : "CREATE TABLE gates_wallet_votes (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              reference VARCHAR(40) NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              -- The page whose community return paid. A member may hold more than one.
              source_nominee_id BIGINT UNSIGNED NOT NULL,
              -- Who the votes went to: any nominee on the ballot, their own page included.
              nominee_id BIGINT UNSIGNED NOT NULL,
              -- INT, not the TINYINT this codebase has been bitten by: one order may
              -- carry up to PaidVoteService::HARD_MAX_QTY votes.
              votes INT UNSIGNED NOT NULL,
              amount_kobo BIGINT UNSIGNED NOT NULL,
              vote_id BIGINT UNSIGNED NULL,
              ledger_id BIGINT UNSIGNED NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_wallet_votes\n";
}
echo '  ' . SchemaIndex::ensure('gates_wallet_votes', 'uq_wallet_votes_ref', ['reference'], true) . "\n";
echo '  ' . SchemaIndex::ensure('gates_wallet_votes', 'idx_wallet_votes_user', ['user_id', 'created_at']) . "\n";

echo "wallet votes OK\n";
