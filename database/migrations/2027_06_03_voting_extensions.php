<?php
/**
 * EXTEND VOTING, ON THE RECORD (Services\VotingExtension; docs/VOTING-EXTENSION-POLICY.md).
 *
 * An extension used to be a quiet edit of `voting_close` in the cycle editor: no record that it
 * happened, no reason given in public, and nothing to stop it looking like a way to sell more
 * paid votes. One row per extension now, written by the cycle editor's "Extend voting" form,
 * which can also RECORD an extension already made by editing the date directly.
 *
 *   gates_voting_extensions  cycle_id, previous_close → new_close (UTC), the PUBLIC reason,
 *                            paid_cutoff_at (paid checkout stops at this moment, normally the
 *                            previous close; NULL = paid voting runs to the new close),
 *                            snapshot_ref (the vote-snapshot chain hash taken when it was
 *                            recorded, NULL when the capture failed), mode ('moved' when this
 *                            form moved the date, 'recorded' when it was already moved),
 *                            admin_id (NULL when there is no admin, never 0: CLAUDE.md, the
 *                            audit FK), created_at.
 *
 * No foreign keys: the audit log's admin FK is exactly the trap CLAUDE.md records. VARCHAR,
 * never ENUM. Additive, idempotent, driver-aware; indexes through SchemaIndex. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$int  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_voting_extensions')) {
    DB::statement("CREATE TABLE gates_voting_extensions (id $id, cycle_id $int NOT NULL,
        previous_close DATETIME NOT NULL, new_close DATETIME NOT NULL, reason TEXT NOT NULL,
        paid_cutoff_at DATETIME NULL, snapshot_ref VARCHAR(80) NULL,
        mode VARCHAR(10) NOT NULL DEFAULT 'moved', admin_id $int NULL,
        created_at DATETIME NOT NULL)$tail");
    echo "  + gates_voting_extensions\n";
} else {
    echo "  = gates_voting_extensions already present\n";
}
echo SchemaIndex::ensure('gates_voting_extensions', 'idx_voting_ext_cycle', ['cycle_id', 'id']) . "\n";
echo "voting extensions OK\n";
