<?php
/**
 * The Champion of the Day, SEALED (Services\DailyChampion; owner, 17 Nov 2026: "they get the
 * gift if they become champion of the day. The champion is announced at the end of the day
 * and is shareable").
 *
 *   gates_daily_champions  one row per edition per West Africa Time day, written the morning
 *                          after once the day's votes are settled. The announcement is the row:
 *                          a refund afterwards does not quietly hand the day to somebody else.
 *                          `gift` marks the first time this person is champion in the edition —
 *                          one gift per person per campaign, as the framework sets.
 *
 * VARCHAR, never ENUM. Idempotent + driver-aware; indexes through SchemaIndex. NEVER exit/die.
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
$int = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!DB::schema()->hasTable('gates_daily_champions')) {
    DB::statement("CREATE TABLE gates_daily_champions (id $id, programme_id $int NOT NULL, cycle_id $int NOT NULL, day VARCHAR(10) NOT NULL,
        person_key VARCHAR(80) NOT NULL, votes INT NOT NULL DEFAULT 0, name VARCHAR(80) NULL, uid $int NOT NULL DEFAULT 0,
        backing VARCHAR(190) NULL, gift SMALLINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$tail");
    echo "  + gates_daily_champions\n";
} else {
    echo "  = gates_daily_champions already present\n";
}
echo SchemaIndex::ensure('gates_daily_champions', 'uq_daily_champion', ['cycle_id', 'day'], true) . "\n";
echo SchemaIndex::ensure('gates_daily_champions', 'idx_daily_champion_person', ['cycle_id', 'person_key']) . "\n";
echo "daily champions OK\n";
