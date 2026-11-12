<?php
/**
 * Ads: the homepage placement, and ads posted by staff.
 *
 *   gates_ad_campaigns.source     'self'   — made and paid for by the advertiser (the default,
 *                                            so every existing row keeps its meaning)
 *                                 'direct' — sold by our staff and paid outside the site; its
 *                                            money is recorded when it is posted, and any
 *                                            refund is made by a person, never the gateway
 *                                 'house'  — our own, labelled "From Africa GATES", never
 *                                            "Sponsored", and served only into space nobody
 *                                            has bought (Services\Ads\AdStaff)
 *   gates_ad_campaigns.posted_by  the admin who posted a direct or house campaign
 *
 *   ads_cpm_home                  the homepage's starting price, set once from Discover's
 *                                 at 1.75× (owner, 4 Nov 2026: the most-seen page, priced as
 *                                 such) — only where Discover is on sale and the homepage has
 *                                 no price yet. The daily market moves it from there.
 *
 * VARCHAR, not ENUM (CLAUDE.md). Idempotent + driver-aware. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$add = static function (string $t, string $c, string $def) use ($schema): void {
    if (!$schema->hasTable($t) || $schema->hasColumn($t, $c)) return;
    DB::statement("ALTER TABLE $t ADD COLUMN $c $def");
    echo "  + $t.$c\n";
};
$add('gates_ad_campaigns', 'source', $sqlite ? "TEXT NOT NULL DEFAULT 'self'" : "VARCHAR(16) NOT NULL DEFAULT 'self'");
$add('gates_ad_campaigns', 'posted_by', $sqlite ? 'INTEGER NULL' : 'BIGINT UNSIGNED NULL');

if ($schema->hasTable('gates_settings')) {
    $d = (int) (DB::table('gates_settings')->where('key_name', 'ads_cpm_discover')->value('value') ?? 0);
    if ($d > 0 && !DB::table('gates_settings')->where('key_name', 'ads_cpm_home')->exists()) {
        DB::table('gates_settings')->insert(['key_name' => 'ads_cpm_home', 'value' => (string) (int) (round($d * 1.75 / 50) * 50 ?: 50)]);
        echo "  homepage starting price set from Discover's\n";
    }
}
echo "ads home + staff OK\n";
