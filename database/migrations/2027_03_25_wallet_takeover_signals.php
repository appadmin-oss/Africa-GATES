<?php
/**
 * Two more moments an automatic withdrawal has to know about (WalletRisk::takeover()).
 *
 *   name_changed_at   when the member last changed the name on their account. The bank's
 *                     account name is checked against it, so a name edited to match a
 *                     stranger's account is the cheapest way past that check — and it was
 *                     recorded nowhere. Written by UserAccountService::updateProfile() only
 *                     when the name actually changes.
 *   otp_signin_at     when the member last signed in with an emailed or texted CODE. A code
 *                     proves the inbox, nothing more: somebody reading the inbox can sign in
 *                     with no password. It counts against a payout only to an account this
 *                     member has never been paid at.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if ($schema->hasTable('gates_users')) {
    foreach (['name_changed_at', 'otp_signin_at'] as $c) {
        if ($schema->hasColumn('gates_users', $c)) continue;
        DB::statement("ALTER TABLE gates_users ADD COLUMN {$c} " . ($sqlite ? 'TEXT NULL' : 'TIMESTAMP NULL DEFAULT NULL'));
        echo "  + gates_users.{$c}\n";
    }
}

echo "wallet takeover signals OK\n";
