<?php
/**
 * Paid ad campaigns: what was charged, the VAT in it, what went back, and how long it runs.
 *
 *   gates_ad_campaigns.vat_bps            the VAT rate charged, frozen at checkout (AdCheckout)
 *   gates_ad_campaigns.vat_kobo           the VAT in paid_kobo — a liability, never revenue
 *   gates_ad_campaigns.refunded_vat_kobo  the VAT in refunded_kobo, so the VAT owed is
 *                                         vat_kobo − refunded_vat_kobo and nothing has to
 *                                         re-derive it from a rate that may have changed
 *   gates_ad_campaigns.refund_note        what the gateway said, or why nothing went back
 *   gates_ad_sets.days                    how long a PAID campaign runs once it starts: it
 *                                         starts when it is both paid and approved, so the
 *                                         window cannot be fixed at booking without a queue
 *                                         eating the days somebody paid for (the trial's rule)
 *
 * paid_kobo, paid_at, payment_ref, refunded_kobo and refunded_at were in the first ads
 * migration and unused until now. Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$int = $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'BIGINT UNSIGNED NOT NULL DEFAULT 0';
$add = static function (string $t, string $c, string $def) use ($schema): void {
    if (!$schema->hasTable($t) || $schema->hasColumn($t, $c)) return;
    DB::statement("ALTER TABLE $t ADD COLUMN $c $def");
    echo "  + $t.$c\n";
};
$add('gates_ad_campaigns', 'vat_bps', $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0');
$add('gates_ad_campaigns', 'vat_kobo', $int);
$add('gates_ad_campaigns', 'refunded_vat_kobo', $int);
$add('gates_ad_campaigns', 'refund_note', $sqlite ? 'TEXT NULL' : 'VARCHAR(255) NULL');
$add('gates_ad_sets', 'days', $sqlite ? 'INTEGER NULL' : 'INT UNSIGNED NULL');
echo "ads checkout OK\n";
