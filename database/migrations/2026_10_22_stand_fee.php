<?php
/**
 * A vendor who accepts a stand can pay for it.
 *
 * ── WHAT ACCEPTANCE DID BEFORE ───────────────────────────────────────────────
 *
 * `StandApplication::accept()` flipped a column and returned the sentence "Accepted. You
 * will be invoiced for the stand fee." Nothing invoiced anybody. There was no fee on the
 * row, no amount anywhere the vendor could see, no way to pay, and no way for an organiser
 * to tell a paid pitch from an unpaid one on the morning of the market.
 *
 * The whole point of a published price and a published quota is that the transaction is
 * defensible. "We will send you an invoice" is where a defensible allocation turns back
 * into a WhatsApp message and a bank transfer nobody can reconcile.
 *
 * ── THE FEE IS COPIED, NOT REFERENCED ────────────────────────────────────────
 *
 * `fee_naira` and `deposit_naira` are STAMPED onto the application at the moment of
 * acceptance, from the stand type as it stood then. A live reference would let an organiser
 * change a price after somebody accepted at the old one — the same reason
 * {@see \AfricaGates\Services\StandCall::open()} copies the criteria rather than pointing at
 * them, and for the same reason: a term that can move after you agreed to it is not a term.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_stand_applications')) {
    echo "  = gates_stand_applications not present yet\n";
    return;
}

$cols = [
    // What was owed when this vendor accepted, in naira. NULL until acceptance.
    'fee_naira'      => $sqlite ? 'INTEGER NULL' : 'INT UNSIGNED NULL DEFAULT NULL',
    // The part due now, if the organiser set one. 0 means the whole fee is due.
    'deposit_naira'  => $sqlite ? 'INTEGER NULL' : 'INT UNSIGNED NULL DEFAULT NULL',
    // What has actually been confirmed by the gateway. Never written from a callback
    // alone — see StandFee::confirm().
    'paid_naira'     => $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0',
    'paid_at'        => $sqlite ? 'TEXT NULL' : 'TIMESTAMP NULL DEFAULT NULL',
    'fee_provider'   => $sqlite ? 'TEXT NULL' : 'VARCHAR(20) DEFAULT NULL',
    // The token in the emailed link. Long and random: it is the whole credential for a
    // vendor who has not signed in, exactly like the questionnaire and claim links.
    'access_token'   => $sqlite ? 'TEXT NULL' : 'VARCHAR(64) DEFAULT NULL',
    // WHEN the trader agreed to the trading terms, and WHICH VERSION they agreed to.
    // The version matters: an organiser enforcing a clause a vendor never saw is the same
    // failure as a rejection with no reason, and "they accepted the terms" is worth nothing
    // if nobody can say which terms those were.
    'terms_agreed_at'  => $sqlite ? 'TEXT NULL' : 'TIMESTAMP NULL DEFAULT NULL',
    'terms_version'    => $sqlite ? 'TEXT NULL' : 'VARCHAR(40) DEFAULT NULL',
];

foreach ($cols as $name => $type) {
    if (DB::schema()->hasColumn('gates_stand_applications', $name)) {
        echo "  = gates_stand_applications.{$name} already present\n";
        continue;
    }
    DB::statement("ALTER TABLE gates_stand_applications ADD COLUMN {$name} {$type}");
    echo "  + gates_stand_applications.{$name} added\n";
}

// UNIQUE, because the token IS the authorisation: two rows sharing one would let a link
// resolve to whichever the query happened to return first.
try {
    DB::statement($sqlite
        ? 'CREATE UNIQUE INDEX IF NOT EXISTS uq_stand_token ON gates_stand_applications (access_token)'
        : 'ALTER TABLE gates_stand_applications ADD UNIQUE KEY uq_stand_token (access_token)');
    echo "  + uq_stand_token\n";
} catch (\Throwable) {
    echo "  = uq_stand_token already present\n";
}
