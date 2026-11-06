<?php
/**
 * Automatic withdrawals: what a request needs to be sent by itself, and what it records.
 *
 * Both withdrawal tables (gates_return_withdrawals, gates_referral_payouts) gain the same
 * columns, because both are paid by one engine (Services\WalletAutoPay):
 *
 *   bank_code          the bank's Paystack code. A transfer cannot be built from a bank
 *                      NAME, and a request made before this column has none — so it is
 *                      always a person's to pay, never guessed from the name.
 *   resolved_name      the account name the BANK holds for the number (Paystack
 *                      /bank/resolve), as checked against the member. What the member typed
 *                      is a claim; this is the evidence.
 *   auto_state         where the engine has it: checking · held · sending · done · failed.
 *                      NULL means the engine has not looked at it yet. VARCHAR, read through
 *                      WalletAutoPay::A_* and nowhere else.
 *   risk_json          the checks it ran and what each found, frozen on the row so a
 *                      reviewer reads what was true when it was decided.
 *   transfer_ref       OUR reference, minted and committed before the gateway is called —
 *                      the only thing between a retry and paying twice (see OrgPayout).
 *   transfer_code, transfer_status, transfer_message, transfer_attempts,
 *   transfer_checked_at, sent_at — the gateway's side, in its own vocabulary.
 *
 * gates_users gains the saved bank's code, the Paystack recipient created for it (one per
 * account, reused — two live handles to one account make a reconciliation unanswerable),
 * the key that recipient was made for, and when the saved details last CHANGED: new bank
 * details followed at once by a withdrawal is the commonest shape of a taken-over account.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

$cols = [
    'bank_code'           => ['TEXT NULL', 'VARCHAR(12) NULL'],
    'resolved_name'       => ['TEXT NULL', 'VARCHAR(160) NULL'],
    'auto_state'          => ['TEXT NULL', 'VARCHAR(12) NULL'],
    'risk_json'           => ['TEXT NULL', 'TEXT NULL'],
    'transfer_ref'        => ['TEXT NULL', 'VARCHAR(64) NULL'],
    'transfer_code'       => ['TEXT NULL', 'VARCHAR(64) NULL'],
    'transfer_status'     => ['TEXT NULL', 'VARCHAR(16) NULL'],
    'transfer_message'    => ['TEXT NULL', 'VARCHAR(250) NULL'],
    'transfer_attempts'   => ['INTEGER NOT NULL DEFAULT 0', 'INT UNSIGNED NOT NULL DEFAULT 0'],
    'transfer_checked_at' => ['TEXT NULL', 'TIMESTAMP NULL DEFAULT NULL'],
    'sent_at'             => ['TEXT NULL', 'TIMESTAMP NULL DEFAULT NULL'],
];
foreach (['gates_return_withdrawals', 'gates_referral_payouts'] as $t) {
    if (!$schema->hasTable($t)) { echo "  = {$t} not present\n"; continue; }
    foreach ($cols as $c => [$lite, $my]) {
        if ($schema->hasColumn($t, $c)) continue;
        DB::statement("ALTER TABLE {$t} ADD COLUMN {$c} " . ($sqlite ? $lite : $my));
        echo "  + {$t}.{$c}\n";
    }
    echo SchemaIndex::ensure($t, 'idx_' . substr($t, 6) . '_xref', ['transfer_ref'], true) . "\n";
    echo SchemaIndex::ensure($t, 'idx_' . substr($t, 6) . '_auto', ['auto_state', 'status']) . "\n";
}

$userCols = [
    'payout_bank_code'       => ['TEXT NULL', 'VARCHAR(12) NULL'],
    'payout_recipient_code'  => ['TEXT NULL', 'VARCHAR(64) NULL'],
    'payout_recipient_key'   => ['TEXT NULL', 'VARCHAR(64) NULL'],
    'payout_bank_changed_at' => ['TEXT NULL', 'TIMESTAMP NULL DEFAULT NULL'],
];
if ($schema->hasTable('gates_users')) {
    foreach ($userCols as $c => [$lite, $my]) {
        if ($schema->hasColumn('gates_users', $c)) continue;
        DB::statement("ALTER TABLE gates_users ADD COLUMN {$c} " . ($sqlite ? $lite : $my));
        echo "  + gates_users.{$c}\n";
    }
}

echo "wallet auto payout OK\n";
