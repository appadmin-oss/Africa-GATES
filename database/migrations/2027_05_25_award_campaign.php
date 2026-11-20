<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * An award's campaign (Support\AwardCampaign): the community it belongs to, its rally line
 * and promise, what share of a contributed vote reaches a named beneficiary, the benefits a
 * supporter's votes unlock, and whether the vote page shows the Supporter Spotlight. One
 * TEXT column holding a JSON document, beside `details_json`. Idempotent.
 */
require_once __DIR__ . '/../bootstrap.php';

$sqlite = DB::connection()->getDriverName() === 'sqlite';
if (DB::schema()->hasTable('gates_award_programmes')
    && !DB::schema()->hasColumn('gates_award_programmes', 'campaign_json')) {
    DB::statement($sqlite
        ? 'ALTER TABLE gates_award_programmes ADD COLUMN campaign_json TEXT NULL'
        : 'ALTER TABLE gates_award_programmes ADD COLUMN campaign_json TEXT NULL AFTER details_json');
    echo "  + gates_award_programmes.campaign_json\n";
} else {
    echo "  = gates_award_programmes.campaign_json already present\n";
}
