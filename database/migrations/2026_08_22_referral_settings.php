<?php
/**
 * Referral terms an administrator can change, and a per-event opt-out.
 *
 * ── WHY THE RATE MOVES OUT OF A CONSTANT ─────────────────────────────────────
 *
 * `ReferralService::RATE_BPS` and `THRESHOLD` were class constants, so changing what a
 * referrer earns meant a code change and a deploy. The operator has no SSH. `HANDOFF.md`
 * §4 asks for exactly this: "an admin-editable rate/threshold".
 *
 * ── THE RATE IS ALREADY STAMPED ON EVERY CREDIT ROW, AND THAT IS WHY THIS IS SAFE ──
 *
 * `gates_referral_credits.rate_bps` records the rate that applied when the credit was
 * earned. So changing the setting changes what FUTURE referrals pay and leaves settled
 * history alone — which is the only version of this that can be changed at all without
 * rewriting what people were told they had earned.
 *
 * The threshold is different and the difference matters: it is evaluated live, so lowering
 * it can unlock balances that were locked yesterday and raising it can lock balances that
 * were payable. That is a real decision about money owed to real people, and the admin
 * screen says so rather than presenting it as a number to nudge.
 *
 * ── AND WHY AN EVENT CAN OPT OUT ─────────────────────────────────────────────
 *
 * Not every event can afford to give away a tenth of the gate. A free community night, a
 * partner-funded event, or one whose margin is already committed needs referral sharing
 * OFF without turning the whole programme off. Defaults to ON, because that is the current
 * behaviour and a migration must not silently switch a live feature off.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── the per-event switch ─────────────────────────────────────────────────────
if (DB::schema()->hasTable('gates_site_events')
    && !DB::schema()->hasColumn('gates_site_events', 'referrals_enabled')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_site_events ADD COLUMN referrals_enabled INTEGER NOT NULL DEFAULT 1'
        : 'ALTER TABLE gates_site_events ADD COLUMN referrals_enabled TINYINT(1) NOT NULL DEFAULT 1');
    echo "  + gates_site_events.referrals_enabled\n";
}

// ── the terms ────────────────────────────────────────────────────────────────
//
// Seeded to the values the constants held, so nothing changes on the day this runs. An
// operator who never opens the screen keeps exactly the behaviour they had.
if (DB::schema()->hasTable('gates_settings')) {
    foreach ([
        'referral_rate_bps'  => '1000',   // 10.00%
        'referral_threshold' => '10',     // paid referrals before earnings unlock
        'referrals_enabled'  => '1',      // the platform-wide switch
    ] as $key => $value) {
        $exists = DB::table('gates_settings')->where('key_name', $key)->exists();
        if (!$exists) {
            DB::table('gates_settings')->insert(['key_name' => $key, 'value' => $value]);
            echo "  + gates_settings.{$key} = {$value}\n";
        }
    }
}
