<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE LEGACY VAULT'S TWO MISSING FACTS — which edition a night was, and where (Phase 6, §8.18).
 *
 * The redesign's vault is a list of award EDITIONS: each one carries its overall winner, its
 * category winners and its sealed record, and is filtered by region. `gates_legacy_events` was
 * a free-standing archive row — a title, a date, a free-text `location` — with nothing tying
 * it to the cycle whose results it commemorates, so a vault page could only ever restate what
 * an operator typed, and "who won" would have been typed too. That is the invented-statistic
 * fault with a gallery on it.
 *
 *   cycle_id      the released cycle this night was. The edition page then draws its winners
 *                 from the SEAL ({@see \AfricaGates\Services\PublicResults::edition()}), never
 *                 from prose. Nullable: an archive night from before this platform has none,
 *                 and says so by showing no winners rather than invented ones.
 *   country_code  ISO 3166-1 alpha-2. The region filter is derived from it
 *                 ({@see \AfricaGates\Support\AfricaRegion}) — a free-text location cannot be
 *                 filtered without guessing what "Nairobi" means.
 *
 * Both are additive and nullable, so every existing row keeps working unchanged.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_legacy_events')) {
    echo "  = gates_legacy_events absent; nothing to do\n";
    return;
}

if (!DB::schema()->hasColumn('gates_legacy_events', 'cycle_id')) {
    DB::statement('ALTER TABLE gates_legacy_events ADD COLUMN cycle_id ' . ($sqlite ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL DEFAULT NULL'));
    echo "  + gates_legacy_events.cycle_id\n";
} else {
    echo "  = gates_legacy_events.cycle_id already present\n";
}

if (!DB::schema()->hasColumn('gates_legacy_events', 'country_code')) {
    DB::statement('ALTER TABLE gates_legacy_events ADD COLUMN country_code ' . ($sqlite ? 'TEXT NULL' : 'CHAR(2) NULL DEFAULT NULL'));
    echo "  + gates_legacy_events.country_code\n";
} else {
    echo "  = gates_legacy_events.country_code already present\n";
}

echo '  ' . SchemaIndex::ensure('gates_legacy_events', 'idx_legacy_cycle', ['cycle_id']) . "\n";
echo "legacy edition link OK\n";
