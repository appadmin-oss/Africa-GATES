<?php
/**
 * The review desk's three new decisions (AdminConsole v2, README §10b "Review desk").
 *
 * The desk asks three things of a nomination the table could not record:
 *
 *   Ask for more   status `needs_details` (already in the ENUM and the SQLite CHECK since
 *                  2027_02_10 / 2027_02_11), plus the reviewer's message, when it was
 *                  sent, and the hash of the one link that lets the nominator answer it.
 *                  The link is the whole point: an "ask" with no way to reply is a
 *                  rejection that tells nobody it is one.
 *   Merge          a duplicate of an earlier, approved nomination is approved INTO that
 *                  entry — one nominee, both nominators credited — and `merged_into`
 *                  records which one, so the history can say so.
 *   Not duplicate  a reviewer's ruling that a same-name entry is a different person.
 *                  Without somewhere to keep it, the desk would raise the same flag on
 *                  the same pair for ever.
 *
 * Columns only, every one nullable or defaulted, so a row written before this file
 * reads exactly as it did. `merged_into` carries no foreign key: a merged-into row may
 * be archived later, and the record of the merge must outlive it.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_nominations')) {
    echo "  = gates_nominations not present yet\n";
    echo "review desk OK\n";
    return;
}

$cols = [
    'asked_message'  => [$sqlite ? 'TEXT NULL' : 'TEXT NULL'],
    'asked_at'       => [$sqlite ? 'TEXT NULL' : 'DATETIME NULL'],
    // sha256 hex of the answer link's token. The token itself is never stored.
    'ask_token_hash' => [$sqlite ? 'TEXT NULL' : 'CHAR(64) NULL'],
    'merged_into'    => [$sqlite ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL'],
    'not_duplicate'  => [$sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0'],
];
foreach ($cols as $name => [$type]) {
    if ($schema->hasColumn('gates_nominations', $name)) { echo "  = gates_nominations.$name\n"; continue; }
    DB::statement("ALTER TABLE gates_nominations ADD COLUMN $name $type");
    echo "  + gates_nominations.$name\n";
}

// The answer page looks a nomination up by this, once per visit.
echo '  ' . SchemaIndex::ensure('gates_nominations', 'idx_nom_ask_token', ['ask_token_hash']) . "\n";

echo "review desk OK\n";
