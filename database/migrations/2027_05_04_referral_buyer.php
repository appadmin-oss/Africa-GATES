<?php
declare(strict_types=1);

/**
 * WHO PAID, ON EACH REFERRAL CREDIT — SO "TEN PEOPLE" MEANS TEN PEOPLE.
 *
 * The referral gate is "once ten people have actually paid", and it counted credit ROWS:
 * one person buying ten cheap tickets or orders through their own link under ten aliases
 * unlocked it and took a tenth back. A credit carried nothing about its buyer, so the gate
 * could not tell. `buyer_hash` is sha256 of the buyer's normalised email (ReferralService::
 * buyerHash()), written when the credit is minted; the gate counts distinct hashes. Older
 * credits have none and keep counting once each, as they always did — nothing already
 * unlocked is locked again by a migration.
 *
 * VARCHAR, nullable, idempotent; the index through SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$t = 'gates_referral_credits';

if (!$schema->hasTable($t)) {
    echo "  · $t absent — nothing to add\n";
} else {
    if (!$schema->hasColumn($t, 'buyer_hash')) {
        DB::statement("ALTER TABLE $t ADD COLUMN buyer_hash " . ($sqlite ? 'TEXT NULL' : 'VARCHAR(64) NULL'));
        echo "  + $t.buyer_hash\n";
    }
    echo SchemaIndex::ensure($t, 'idx_referral_credits_buyer', ['user_id', 'buyer_hash']) . "\n";
}
echo "referral buyer OK\n";
