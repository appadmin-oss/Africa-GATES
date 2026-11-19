<?php
/**
 * Ads: a short video on an ad, the shape an ad takes, and what was watched (Services\Ads\AdVideo; owner, 11 Nov
 * 2026: "video ads too").
 *
 *   gates_ads.video_path        an MP4 this platform stored (AdVideo::store); NULL for an ad
 *                               that is its image alone, which every existing row stays
 *   gates_ads.video_seconds     its length, read from the file's own header, never the
 *                               browser's word. SMALLINT: the cap is thirty seconds, and a
 *                               TINYINT's 255 would be one change of cap from clamping
 *   gates_ads.shape             square | portrait (4:5) | landscape (16:9), AdRules::SHAPES;
 *                               'square' for every existing row, which is what they all are.
 *                               VARCHAR, not ENUM (CLAUDE.md)
 *   gates_ad_stats.plays        times a drawn ad's video started, counted once per drawing
 *   gates_ad_stats.completes    of those, watched to the end or for fifteen seconds (Meta's
 *                               ThruPlay), counted once per drawing
 *
 * Plays and completes sit beside views and clicks in the same daily row, so every reader of
 * that table keeps working and a campaign's video figures come from the one place its
 * other figures do. Idempotent + driver-aware. NEVER exit/die here.
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
$add('gates_ads', 'video_path', $sqlite ? 'TEXT NULL' : 'VARCHAR(400) NULL');
$add('gates_ads', 'video_seconds', $sqlite ? 'INTEGER NULL' : 'SMALLINT UNSIGNED NULL');
$add('gates_ads', 'shape', $sqlite ? "TEXT NOT NULL DEFAULT 'square'" : "VARCHAR(12) NOT NULL DEFAULT 'square'");
$add('gates_ad_stats', 'plays', $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0');
$add('gates_ad_stats', 'completes', $sqlite ? 'INTEGER NOT NULL DEFAULT 0' : 'INT UNSIGNED NOT NULL DEFAULT 0');
echo "ad video OK\n";
