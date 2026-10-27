<?php
/**
 * Ads: paid promotion of goods, services and events, built the way Meta builds it
 * (owner, 27 Oct 2026). Three levels, so a campaign can grow without a schema change:
 *
 *   gates_ad_campaigns   the OBJECTIVE and who is paying: whose (`user_id`), the name shown
 *                        on every ad (`advertiser`), whether it is the free trial, and the
 *                        advertiser's own switch (`status`: draft, submitted, paused,
 *                        cancelled). Whether it is delivering is DERIVED (Ads\AdCampaigns::
 *                        delivery()), never stored: a stored "active" is true the day it is
 *                        written and wrong the day the budget runs out.
 *   gates_ad_sets        WHERE, WHO, WHEN and HOW MUCH: placements, countries, the window,
 *                        the budget in kobo, the rate card as it stood when the set was
 *                        made (`rates`, so a price change never re-prices a running
 *                        campaign), and a view cap (the trial's 1,000).
 *   gates_ads            the CREATIVE: image, headline, text, button, link, an optional
 *                        customer offer, and its review (pending, approved, rejected, with
 *                        the reason the advertiser is shown).
 *   gates_ad_stats       views and clicks per ad, per placement, per day. Spend is derived
 *                        from views and the set's rates, never stored beside them.
 *   gates_ad_events      one row per counted view or click, keyed by the token it was minted
 *                        with (UNIQUE), so a beacon replayed by a script counts once. Holds
 *                        no person: a token, a kind and a time. Pruned after a few days.
 *   gates_ad_inventory   how many times each placement was drawn for a reader, per day: the
 *                        only honest basis for the builder's estimate of what a budget buys.
 *
 * Money: none of these tables holds a balance (the owner's rule: no wallet). A paid
 * campaign is one payment, and what it does not spend goes back to where it came from.
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
$big  = $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED';
$int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
$ts   = $sqlite ? 'TEXT' : 'TIMESTAMP';
$v    = static fn (int $n): string => $sqlite ? 'TEXT' : "VARCHAR($n)";
$tail = $sqlite ? '' : ', PRIMARY KEY (id)';
$eng  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$now  = $sqlite ? 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';

$tables = [
    'gates_ad_campaigns' => "$id,
        user_id $big NOT NULL,
        name {$v(80)} NOT NULL,
        advertiser {$v(60)} NOT NULL,
        objective {$v(16)} NOT NULL DEFAULT 'awareness',
        trial $int NOT NULL DEFAULT 0,
        status {$v(16)} NOT NULL DEFAULT 'draft',
        payment_ref {$v(64)} NULL,
        paid_kobo $big NOT NULL DEFAULT 0,
        paid_at $ts NULL,
        refunded_kobo $big NOT NULL DEFAULT 0,
        refunded_at $ts NULL,
        submitted_at $ts NULL,
        cancelled_at $ts NULL,
        created_at $now,
        updated_at $now",
    'gates_ad_sets' => "$id,
        campaign_id $big NOT NULL,
        placements {$v(120)} NOT NULL,
        countries {$v(400)} NOT NULL DEFAULT '',
        starts_at $ts NULL,
        ends_at $ts NULL,
        budget_kobo $big NOT NULL DEFAULT 0,
        rates {$v(400)} NOT NULL DEFAULT '{}',
        view_cap $int NULL,
        created_at $now,
        updated_at $now",
    'gates_ads' => "$id,
        ad_set_id $big NOT NULL,
        headline {$v(80)} NOT NULL,
        body {$v(300)} NOT NULL,
        cta {$v(20)} NOT NULL DEFAULT 'learn_more',
        url {$v(400)} NOT NULL,
        image_path {$v(400)} NULL,
        offer {$v(80)} NULL,
        offer_code {$v(32)} NULL,
        review {$v(16)} NOT NULL DEFAULT 'pending',
        review_reason {$v(300)} NULL,
        review_flags {$v(400)} NULL,
        reviewed_by $big NULL,
        reviewed_at $ts NULL,
        created_at $now,
        updated_at $now",
    'gates_ad_stats' => "$id,
        ad_id $big NOT NULL,
        placement {$v(20)} NOT NULL,
        day {$v(10)} NOT NULL,
        views $int NOT NULL DEFAULT 0,
        clicks $int NOT NULL DEFAULT 0",
    'gates_ad_events' => "$id,
        token {$v(64)} NOT NULL,
        kind {$v(8)} NOT NULL,
        created_at $now",
    'gates_ad_inventory' => "$id,
        placement {$v(20)} NOT NULL,
        day {$v(10)} NOT NULL,
        slots $int NOT NULL DEFAULT 0",
];

foreach ($tables as $name => $cols) {
    if ($schema->hasTable($name)) continue;
    DB::statement("CREATE TABLE $name ($cols$tail)$eng");
    echo "  + $name\n";
}

$indexes = [
    ['gates_ad_campaigns', 'uq_adc_payment', ['payment_ref'], true],
    ['gates_ad_campaigns', 'idx_adc_user', ['user_id', 'status'], false],
    ['gates_ad_sets', 'idx_ads_campaign', ['campaign_id'], false],
    ['gates_ads', 'idx_ad_set', ['ad_set_id', 'review'], false],
    ['gates_ads', 'idx_ad_review', ['review', 'id'], false],
    ['gates_ad_stats', 'uq_adstat_day', ['ad_id', 'placement', 'day'], true],
    ['gates_ad_events', 'uq_adev_token', ['token', 'kind'], true],
    ['gates_ad_events', 'idx_adev_time', ['created_at'], false],
    ['gates_ad_inventory', 'uq_adinv_day', ['placement', 'day'], true],
];
foreach ($indexes as [$t, $n, $c, $u]) {
    if ($schema->hasTable($t)) echo '  ' . SchemaIndex::ensure($t, $n, $c, $u) . "\n";
}
echo "ads OK\n";
