<?php
/**
 * Ads: a price per placement per day, and event pages as their own placement.
 *
 *   gates_ad_prices   one row per (day, placement): the price per 1,000 views that day and
 *                     the measurements it was worked out from (AdMarket::price()). Written
 *                     on the first read of the day, so every advertiser who books that day
 *                     is quoted the same number, and the history of what the market charged
 *                     — and why — can be read back. Multipliers are stored ×1000 as
 *                     integers: a DECIMAL rounds differently on the two drivers, and a price
 *                     history that disagrees with itself between dev and production is the
 *                     shape this repo has paid for most often.
 *
 *   'event_page'      split out of 'events' (owner, 3 Nov 2026: "cost should also vary for
 *                     high-impact positions"). An event's own page is read by somebody
 *                     deciding whether to buy a ticket; the list is read by somebody
 *                     browsing. Every set that booked 'events' before the split was booked
 *                     for both, so it keeps both: 'event_page' is appended to its placements
 *                     and its booked 'events' rate is copied across, so nothing anybody
 *                     bought shrinks and nothing is re-priced.
 *
 * VARCHAR, not ENUM (CLAUDE.md). Idempotent + driver-aware; indexes through SchemaIndex.
 * NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$id   = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
$int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
$v    = static fn (int $n): string => $sqlite ? 'TEXT' : "VARCHAR($n)";
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$schema->hasTable('gates_ad_prices')) {
    DB::statement("CREATE TABLE gates_ad_prices ($id,
        day {$v(10)} NOT NULL,
        placement {$v(20)} NOT NULL,
        cpm_naira $int NOT NULL DEFAULT 0,
        base_naira $int NOT NULL DEFAULT 0,
        quality_x1000 $int NOT NULL DEFAULT 1000,
        demand_x1000 $int NOT NULL DEFAULT 1000,
        sell_bps $int NOT NULL DEFAULT 0,
        ctr_bp $int NOT NULL DEFAULT 0,
        view_bps $int NOT NULL DEFAULT 0,
        slots $int NOT NULL DEFAULT 0,
        measured $int NOT NULL DEFAULT 0$tail)$eng");
    echo "  + gates_ad_prices\n";
}
echo '  ' . SchemaIndex::ensure('gates_ad_prices', 'uq_adprice_day', ['day', 'placement'], true) . "\n";

if ($schema->hasTable('gates_ad_sets')) {
    $n = 0;
    foreach (DB::table('gates_ad_sets')->get(['id', 'placements', 'rates']) as $s) {
        $p = array_values(array_filter(explode(',', (string) $s->placements)));
        if (!in_array('events', $p, true) || in_array('event_page', $p, true)) continue;
        $p[] = 'event_page';
        $rates = json_decode((string) $s->rates, true) ?: [];
        if (isset($rates['events'])) $rates['event_page'] = $rates['events'];
        DB::table('gates_ad_sets')->where('id', $s->id)->update([
            'placements' => implode(',', $p), 'rates' => json_encode($rates ?: new \stdClass()),
        ]);
        $n++;
    }
    echo "  event_page added to $n set(s)\n";
}
// The new placement starts at the price event pages were already being sold at (they were
// part of 'events'); the market moves it from there on what it measures.
if ($schema->hasTable('gates_settings')) {
    $was = DB::table('gates_settings')->where('key_name', 'ads_cpm_events')->value('value');
    if ((int) $was > 0 && !DB::table('gates_settings')->where('key_name', 'ads_cpm_event_page')->exists()) {
        DB::table('gates_settings')->insert(['key_name' => 'ads_cpm_event_page', 'value' => (string) (int) $was]);
        echo "  event_page base price copied from events\n";
    }
}
echo "ad market OK\n";
