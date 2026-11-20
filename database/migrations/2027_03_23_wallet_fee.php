<?php
/**
 * The wallet's withdrawal fee, frozen on every referral payout request.
 *
 * Community-return withdrawals carried `fee_kobo` from their first migration; referral
 * payouts had no fee at all. The fee is now one schedule for every way money leaves a
 * member's wallet (Services\WalletFee), so a referral request stores the fee it was shown,
 * the way a community-return request does — a later change to the bands must never restate
 * what somebody agreed to. 0 on every existing row: they were requested fee-free.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
if (DB::schema()->hasTable('gates_referral_payouts')
    && !DB::schema()->hasColumn('gates_referral_payouts', 'fee_naira')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_referral_payouts ADD COLUMN fee_naira INTEGER NOT NULL DEFAULT 0'
        : 'ALTER TABLE gates_referral_payouts ADD COLUMN fee_naira INT UNSIGNED NOT NULL DEFAULT 0 AFTER amount_naira');
    echo "  + gates_referral_payouts.fee_naira\n";
} else {
    echo "  = gates_referral_payouts.fee_naira already present (or no table yet)\n";
}
