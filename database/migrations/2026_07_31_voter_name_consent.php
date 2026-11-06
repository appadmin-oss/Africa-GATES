<?php
/**
 * Publishing a supporter's name requires their permission, and permission cannot be
 * inferred from a name field that never said what the name was for.
 *
 * ── WHY A COLUMN AND NOT A QUERY ─────────────────────────────────────────────
 *
 * On the paid ballot the name field is OPTIONAL and now says what filling it in does, so
 * typing a name is the consent — there is no separate tickbox. That reading cannot be
 * applied at query time, though, and this column is why.
 *
 * The field has always existed, labelled "Shown as the supporter name" — which named no
 * audience. Every row already in the table was filled in under that label, for a RECEIPT
 * and an admin ledger. A reader that inferred consent from `donor_name != ''` would
 * publish all of them retroactively, including the people who typed a real name
 * precisely because they believed only the site would see it.
 *
 * So consent is recorded at WRITE time in its own column, DEFAULT 0. Every historical
 * order — the entire live table at the moment this runs — is therefore private, and
 * stays private, without a backfill and without anyone having to remember an exception.
 * The public list can only ever grow from orders placed after the ballot started saying
 * what the field is for.
 *
 * Recording it as "0 unless told otherwise" is also the only version that survives the
 * next feature: any future surface that wants to show supporter names reads the same
 * flag, and inherits the same default, rather than re-deciding the question.
 *
 * ── TWO TABLES, BECAUSE THERE ARE TWO KINDS OF VOTER ─────────────────────────
 *
 *   • gates_donations.show_name — the PAID path, where the name is optional and giving
 *     one is the choice. The order carries the answer through the gateway round-trip and
 *     {@see \AfricaGates\Services\PaidVoteService::mint()} copies it onto the vote it
 *     mints, so the public list never joins back to a payments table.
 *   • gates_votes.show_name — the mint target above, and what the public list reads.
 *     Free OTP votes keep the 0 default: that path REQUIRES a name, so supplying one is
 *     not a choice to be published. The column lives here anyway so the list is built
 *     from vote rows alone, and so a free-path opt-in has somewhere to land later.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$driver = DB::connection()->getDriverName();

// SQLite has no TINYINT; it stores INTEGER either way. The DEFAULT 0 is the part that
// matters and both drivers honour it — that default is what keeps every existing row
// out of the public list.
$flag = $driver === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';

foreach (['gates_donations', 'gates_votes'] as $table) {
    if (!$schema->hasTable($table)) {
        echo "  ~ {$table} absent — skipped\n";
        continue;
    }
    if ($schema->hasColumn($table, 'show_name')) {
        echo "  = {$table}.show_name already present\n";
        continue;
    }
    try {
        DB::statement("ALTER TABLE {$table} ADD COLUMN show_name {$flag}");
        echo "  + {$table}.show_name added (default 0 — existing rows stay private)\n";
    } catch (\Throwable $e) {
        // Degrades to "nobody appears on the public list", which is the safe direction:
        // the reader treats a missing column as "no consent recorded".
        echo "  ! {$table}.show_name skipped: " . $e->getMessage() . "\n";
    }
}

echo "voter name consent OK\n";
