<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * An award's own words on its "Award details" tab (Support\AwardDetails): who can be
 * nominated, what winners receive, a note under each generated rule, and a short FAQ.
 * One TEXT column holding a JSON document, beside `wording_json`. Idempotent.
 */
require_once __DIR__ . '/../bootstrap.php';

$sqlite = DB::connection()->getDriverName() === 'sqlite';
if (DB::schema()->hasTable('gates_award_programmes')
    && !DB::schema()->hasColumn('gates_award_programmes', 'details_json')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_award_programmes ADD COLUMN details_json TEXT NULL'
        : 'ALTER TABLE gates_award_programmes ADD COLUMN details_json TEXT NULL AFTER terms');
    echo "  + gates_award_programmes.details_json\n";
} else {
    echo "  = gates_award_programmes.details_json already present\n";
}
