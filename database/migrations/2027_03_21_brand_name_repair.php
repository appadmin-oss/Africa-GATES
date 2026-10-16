<?php
declare(strict_types=1);

use AfricaGates\Support\Brand;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "AFRICA G8" BECOMES "AFRICA GATES" IN WHAT IS ALREADY STORED (owner, 16 Oct 2026).
 *
 * Support\Brand rewrites the retired name when award text is saved and again when it is
 * drawn; this puts the stored copies right once, so an export, a search or an email built
 * from a raw column says the same as the page.
 *
 * What it touches, and how:
 *   · gates_award_programmes — title, subtitle, description, the legacy `terms` column and
 *     the two JSON documents (wording, details), rewritten in place.
 *   · gates_award_terms — NOT rewritten. A version is what people accepted, and changing
 *     its words afterwards changes what they agreed to. Where the CURRENT version carries
 *     the old name, a new version is published with the name corrected and a changelog
 *     line saying exactly that; the earlier versions stand as they were.
 *   · gates_legal_docs (body, title) and gates_settings (value) — rewritten in place.
 *
 * Only rows that actually carry a retired spelling are written, so a second run changes
 * nothing. Idempotent; never exits.
 */
require_once __DIR__ . '/../bootstrap.php';

$fixed = 0;
$fix = static function (string $table, string $key, array $cols) use (&$fixed): void {
    if (!DB::schema()->hasTable($table)) return;
    $have = array_values(array_filter($cols, static fn ($c) => DB::schema()->hasColumn($table, $c)));
    if ($have === []) return;
    foreach (DB::table($table)->get(array_merge([$key], $have)) as $row) {
        $patch = [];
        foreach ($have as $c) {
            $v = $row->{$c};
            if (is_string($v) && Brand::needsRepair($v)) $patch[$c] = Brand::normalise($v);
        }
        if ($patch !== []) {
            DB::table($table)->where($key, $row->{$key})->update($patch);
            $fixed++;
        }
    }
};

$fix('gates_award_programmes', 'id', ['title', 'subtitle', 'description', 'terms', 'wording_json', 'details_json']);
$fix('gates_legal_docs', 'slug', ['title', 'body_html']);
$fix('gates_settings', 'key_name', ['value']);

// Terms: a corrected NEW version where the one in force still says the old name.
if (DB::schema()->hasTable('gates_award_terms')) {
    $current = DB::table('gates_award_terms as t')
        ->whereRaw('t.version = (SELECT MAX(v.version) FROM gates_award_terms v WHERE v.programme_id = t.programme_id)')
        ->get(['t.programme_id', 't.body']);
    foreach ($current as $t) {
        if (!Brand::needsRepair((string) $t->body)) continue;
        \AfricaGates\Services\AwardTerms::publish((int) $t->programme_id, Brand::normalise((string) $t->body),
            'The platform\'s name corrected to Africa GATES. Nothing else changed.', null);
        $fixed++;
    }
}

echo $fixed ? "  + {$fixed} record(s) now say Africa GATES\n" : "  = no record carried the old name\n";
