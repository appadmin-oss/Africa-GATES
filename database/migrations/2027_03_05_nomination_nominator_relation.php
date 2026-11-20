<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * "ABOUT YOU" — two answers the nomination flow asks for (Phase 8, NominationFlow.dc.html step 5).
 *
 * · `nominator_relation` — "How do you know them?", one key of NominationRules::RELATIONS.
 *   VARCHAR and not ENUM: an ENUM outside its own list is `Data truncated` on MySQL and
 *   nothing on SQLite (CLAUDE.md), and the list is the rules class's, not the schema's.
 *   Read by the operator brief (NominationAftercare), where a reviewer weighs a
 *   nomination by who is making it.
 * · `nominator_private` — "Keep my name private from the nominee". Read by
 *   NomineeConfirmation, which otherwise opens its message with the nominator's name.
 *   A switch the nominator sets and nothing honours would be a promise broken on the
 *   first message, so the reader shipped with the column.
 *
 * Added only where absent; nothing here for a later repair to correct.
 */
$sqlite = DB::connection()->getDriverName() === 'sqlite';
if (!DB::schema()->hasTable('gates_nominations')) { echo "  = gates_nominations absent\n"; return; }

if (!DB::schema()->hasColumn('gates_nominations', 'nominator_relation')) {
    DB::statement('ALTER TABLE gates_nominations ADD COLUMN nominator_relation '
        . ($sqlite ? 'TEXT' : 'VARCHAR(40)') . ' NULL DEFAULT NULL');
    echo "  + gates_nominations.nominator_relation added\n";
}
if (!DB::schema()->hasColumn('gates_nominations', 'nominator_private')) {
    DB::statement('ALTER TABLE gates_nominations ADD COLUMN nominator_private '
        . ($sqlite ? 'INTEGER' : 'TINYINT(1)') . ' NOT NULL DEFAULT 0');
    echo "  + gates_nominations.nominator_private added\n";
}
echo "nomination nominator relation OK\n";
