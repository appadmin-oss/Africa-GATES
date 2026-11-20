<?php
/**
 * Ads: what an ad is FOR, and the approvals that kind of ad needs.
 *
 *   gates_ad_campaigns.category     a key of AdRules::CATEGORIES (general, events, food,
 *                                   health, finance, education). Decides which approvals
 *                                   the campaign's ads must carry once ads leave beta.
 *   gates_ad_campaigns.licence_ref  the regulator's licence number, for money services.
 *   gates_ads.nafdac_ref            the NAFDAC registration or advert approval number, for
 *                                   food, drugs, cosmetics and health products.
 *
 * Research, 28 Oct 2026: NAFDAC requires approval before food, drug and cosmetic adverts,
 * social media included (Drug and Related Products Advertisement Regulations 2019; Food
 * Products Advertisement Regulations 2019), on top of ARCON's pre-exposure vetting. Money
 * services are licensed by their regulators; the licence is the minimum we ask for.
 *
 * Its own file rather than an edit to 2027_04_08_ads.php: that one is pushed, and a
 * corrected definition never reaches a database that already ran it (CLAUDE.md).
 * Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$add = static function (string $table, string $col, string $def) use ($schema): void {
    if (!$schema->hasTable($table) || $schema->hasColumn($table, $col)) return;
    DB::statement("ALTER TABLE $table ADD COLUMN $col $def");
    echo "  + $table.$col\n";
};
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$add('gates_ad_campaigns', 'category', $sqlite ? "TEXT NOT NULL DEFAULT 'general'" : "VARCHAR(16) NOT NULL DEFAULT 'general'");
$add('gates_ad_campaigns', 'licence_ref', $sqlite ? 'TEXT NULL' : 'VARCHAR(60) NULL');
$add('gates_ads', 'nafdac_ref', $sqlite ? 'TEXT NULL' : 'VARCHAR(40) NULL');
echo "ads categories OK\n";
