<?php
/**
 * "Not a duplicate" is a ruling about a PAIR, so it is stored as one.
 *
 * It used to be a flag on one nomination (`gates_nominations.not_duplicate`), and the
 * review queue then took that nomination out of every duplicate group, its own and
 * everybody else's. So a reviewer who ruled "Ada Obi (Lagos) is not Ada Obi (Abuja)"
 * had also, without being asked, ruled on every LATER Ada Obi in the edition: a third
 * entry arriving next week — possibly a genuine repeat of the first — was never flagged
 * against either. The ruling covered people nobody had looked at.
 *
 *   gates_nomination_apart   one row per pair a reviewer has said are different
 *                            people. `pair_key` is "min:max" so the pair is one row
 *                            whichever side the ruling was made from.
 *
 * The old flag is still read for rows ruled before this table existed and with no pair
 * recorded (`NominationApart::legacy()`), so nothing already ruled starts flagging again.
 * Idempotent + driver-aware; indexes through SchemaIndex. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
$int  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$key  = $sqlite ? 'TEXT' : 'VARCHAR(48)';
$ts   = $sqlite ? 'TEXT' : 'DATETIME';
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_nomination_apart')) {
    DB::statement("CREATE TABLE gates_nomination_apart ($id,
        pair_key $key NOT NULL,
        nomination_a $int NOT NULL,
        nomination_b $int NOT NULL,
        ruled_by $int NULL,
        created_at $ts NOT NULL$tail)$eng");
    echo "  + gates_nomination_apart\n";
}
SchemaIndex::ensure('gates_nomination_apart', 'uq_nom_apart_pair', ['pair_key'], true);
SchemaIndex::ensure('gates_nomination_apart', 'idx_nom_apart_b', ['nomination_b']);
