<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The column that makes "tell them once" true.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A CLAIM WITH NO CONSTRAINT BEHIND IT IS A CHECK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `ChallengeAftercare` guards every send by inserting a ledger row and treating a
 * constraint violation as "already done". Without a UNIQUE index the insert always
 * succeeds, so the guard never fires — and the trigger is `recount()`, which runs on
 * every confirmation, approval and refund. A winner would be texted once per
 * nomination that landed after they qualified.
 *
 * ── WHY A DEDICATED COLUMN AND NOT (challenge_id, entry_id, kind) ───────────
 *
 * Because `entry_id` is NULL for a challenge-level event — the Pulse post has no
 * entry — and NULL is distinct from NULL in a UNIQUE index on both MySQL and SQLite.
 * Two `pulse_posted` rows would both insert and the feed would carry the winners
 * twice.
 *
 * `once_key` is NULL for every ordinary ledger row, which is exactly the behaviour
 * wanted: those SHOULD repeat. Only a row that sets a key is claimed, and the key
 * carries its own scope ("won_notified:412").
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_challenge_events')) {
    echo "  = gates_challenge_events not present yet\n";
    echo "challenge once-key migration OK\n";
    return;
}

if (!DB::schema()->hasColumn('gates_challenge_events', 'once_key')) {
    DB::statement('ALTER TABLE gates_challenge_events ADD COLUMN once_key '
        . ($sqlite ? 'TEXT NULL' : 'VARCHAR(80) NULL DEFAULT NULL'));
    echo "  + gates_challenge_events.once_key\n";
} else {
    echo "  = gates_challenge_events.once_key already present\n";
}

// Never the raw `CREATE UNIQUE INDEX IF NOT EXISTS` — that is SQLite syntax and a 1064
// on MySQL, and `MigrateCommand` aborts the run without recording the file.
SchemaIndex::ensure('gates_challenge_events', 'uq_event_once', ['once_key'], true);
echo "  = gates_challenge_events.uq_event_once ensured\n";

echo "challenge once-key migration OK\n";
