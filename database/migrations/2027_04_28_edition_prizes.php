<?php
/**
 * Prizes per edition, by place (owner, 31 Oct 2026). They were one free-text box on the AWARD,
 * so every edition of it shared one prize text and nothing said which place won what.
 *
 *   gates_award_prizes   one row per place in one edition: 1st, 2nd, 3rd… what it receives, and
 *                        a cash figure in whole naira where there is one. EditionPrizes.
 *
 * The old box is retired, not left behind: an award's text is copied into its LATEST edition as
 * the first place, so an operator finds their words where they now belong and splits them by
 * place. Then the key is removed from the award, so there is one place prizes live, not two.
 *
 * VARCHAR, never ENUM. Indexes through SchemaIndex. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\SchemaIndex;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$ref = $sqlite ? 'INTEGER NOT NULL' : 'BIGINT UNSIGNED NOT NULL';
$pl  = $sqlite ? 'INTEGER NOT NULL' : 'INT UNSIGNED NOT NULL';
$txt = $sqlite ? 'TEXT NOT NULL' : 'TEXT NOT NULL';
$big = $sqlite ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL';
$dt  = $sqlite ? 'TEXT NULL' : 'DATETIME NULL';
$eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_award_prizes')) {
    DB::statement("CREATE TABLE gates_award_prizes (id $id, cycle_id $ref, place $pl, award $txt, cash_naira $big, updated_at $dt)$eng");
    echo "  + gates_award_prizes\n";
}
SchemaIndex::ensure('gates_award_prizes', 'uq_award_prize_place', ['cycle_id', 'place'], true);

if ($schema->hasColumn('gates_award_programmes', 'details_json')) {
    foreach (DB::table('gates_award_programmes')->whereNotNull('details_json')->get(['id', 'details_json']) as $p) {
        $d = json_decode((string) $p->details_json, true);
        if (!is_array($d) || !array_key_exists('prize', $d)) continue;
        $text = trim((string) $d['prize']);
        $cycle = DB::table('gates_award_cycles')->where('programme_id', $p->id)->orderByDesc('year')->orderByDesc('id')->value('id');
        if ($text !== '' && $cycle && !DB::table('gates_award_prizes')->where('cycle_id', $cycle)->exists()) {
            DB::table('gates_award_prizes')->insert(['cycle_id' => $cycle, 'place' => 1, 'award' => mb_substr($text, 0, 1000),
                'cash_naira' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            echo "  ~ programme {$p->id}: prize text moved to edition $cycle, 1st place\n";
        }
        unset($d['prize']);
        DB::table('gates_award_programmes')->where('id', $p->id)->update(['details_json' => $d === [] ? null : json_encode($d, JSON_UNESCAPED_UNICODE)]);
    }
}
