<?php
/**
 * Broader nominee status updates (owner, 16 Nov 2026: "can also do it without the asks. Also
 * please broaden"). Services\Volunteer\NomineeStatus.
 *
 *   reach      whether the volunteer has reached the nominee (a word, optional)
 *   missing    what their portal profile still needs (a JSON list of words)
 *   channels   where they have been shared (a JSON list of words)
 *   backers    who is mobilising voters for them (a JSON list of words)
 *   needs      what would help (a JSON list of words) — the lead reads this first
 *   next_step  the volunteer's next step, in their words
 *
 * Added to the table 2027_05_27 created, column by column, so a database that already holds
 * updates gains them too (CLAUDE.md: a corrected definition repairs only fresh databases).
 * VARCHAR and TEXT, never ENUM. Idempotent. NEVER exit/die here.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_vol_nominee_status')) {
    echo "  = gates_vol_nominee_status not present yet; 2027_05_27 creates it\n";
    return;
}
foreach (['reach' => 'VARCHAR(16) NULL', 'missing' => 'TEXT NULL', 'channels' => 'TEXT NULL', 'backers' => 'TEXT NULL',
          'needs' => 'TEXT NULL', 'next_step' => 'VARCHAR(500) NULL'] as $col => $type) {
    if (DB::schema()->hasColumn('gates_vol_nominee_status', $col)) { echo "  = $col already present\n"; continue; }
    DB::statement("ALTER TABLE gates_vol_nominee_status ADD COLUMN $col $type");
    echo "  + gates_vol_nominee_status.$col\n";
}
echo "volunteer status detail OK\n";
