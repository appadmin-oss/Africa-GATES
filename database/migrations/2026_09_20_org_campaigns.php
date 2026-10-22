<?php
/**
 * Campaigns — a partner organisation raising for a stated purpose rather than in general.
 *
 * ── A TARGET IS A REPRESENTATION, NOT A DECORATION ───────────────────────────
 *
 * The moment a page says "₦2,000,000 to rebuild the science lab", a donor is giving for THAT
 * and the money is restricted to it. Two columns exist because of that and not because a
 * progress bar looks good:
 *
 *   `shortfall_policy` — what happens if the target is missed. A campaign that raises ₦400k
 *   of ₦2m has to do something with the ₦400k, and the honest options are narrow: spend it on
 *   the same purpose at a smaller scale, move it to the organisation's general funds, or
 *   return it. Whichever is chosen is shown to the donor BEFORE they give, because deciding
 *   it afterwards means deciding it in whatever way is most convenient at the time.
 *
 *   `closes_on` — an appeal with no end is not an appeal. It also makes "still collecting for
 *   a lab that was rebuilt last year" a state the system can refuse rather than one somebody
 *   has to notice.
 *
 * ── AND THE TOTAL IS NEVER STORED ────────────────────────────────────────────
 *
 * There is no `raised_naira` column. It is summed from confirmed donation rows every time it
 * is shown. A cached total on a fundraising page is a number that drifts from the rows
 * underneath it, and the first time anybody notices is when a partner asks why the bar says
 * more than their dashboard does.
 *
 * ── REVIEW BEFORE PUBLIC ─────────────────────────────────────────────────────
 *
 * `status` is draft → review → live → closed. An organisation writes its own appeal and does
 * not publish it: the platform is putting its name beside the claim, so somebody reads it
 * first. That is the same doctrine as approving the organisation itself, applied to the thing
 * a donor actually reads.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

$campaigns = $sqlite
    ? "CREATE TABLE IF NOT EXISTS gates_org_campaigns (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         org_id INTEGER NOT NULL,
         slug TEXT NOT NULL,
         title TEXT NOT NULL,
         summary TEXT,
         story TEXT,
         target_naira INTEGER,
         shortfall_policy TEXT NOT NULL DEFAULT 'same_purpose',
         status TEXT NOT NULL DEFAULT 'draft',
         opens_on TEXT,
         closes_on TEXT,
         reviewed_by INTEGER,
         reviewed_at TEXT,
         review_note TEXT,
         created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TEXT
       )"
    : "CREATE TABLE IF NOT EXISTS gates_org_campaigns (
         id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         org_id BIGINT UNSIGNED NOT NULL,
         slug VARCHAR(120) NOT NULL,
         title VARCHAR(200) NOT NULL,
         summary VARCHAR(400) DEFAULT NULL,
         story TEXT,
         target_naira INT UNSIGNED DEFAULT NULL,
         shortfall_policy VARCHAR(20) NOT NULL DEFAULT 'same_purpose',
         status VARCHAR(16) NOT NULL DEFAULT 'draft',
         opens_on DATE NULL DEFAULT NULL,
         closes_on DATE NULL DEFAULT NULL,
         reviewed_by BIGINT UNSIGNED DEFAULT NULL,
         reviewed_at TIMESTAMP NULL DEFAULT NULL,
         review_note VARCHAR(400) DEFAULT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_campaign_slug (org_id, slug),
         KEY idx_campaign_status (status, closes_on)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$pdo->exec($campaigns);

// SQLite has no inline UNIQUE across two columns in the form above; add it separately so both
// engines enforce one slug per organisation. Two campaigns sharing a slug would make
// /donate/<org>/<slug> ambiguous, which is a 50/50 chance of the money going to the wrong appeal.
if ($sqlite) {
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_campaign_slug ON gates_org_campaigns (org_id, slug)");
    } catch (\Throwable $e) {
        echo "*** could not add uq_campaign_slug: {$e->getMessage()}\n";
    }
}

// Donations gain a campaign. NULL means the organisation's general fund, which is every
// partner donation taken before today — additive and nullable, so the backfill is nothing.
try {
    if (!DB::schema()->hasColumn('gates_donations', 'campaign_id')) {
        $pdo->exec('ALTER TABLE gates_donations ADD COLUMN campaign_id '
                 . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED NULL DEFAULT NULL'));
    }
} catch (\Throwable $e) {
    echo "*** could not add gates_donations.campaign_id: {$e->getMessage()}\n";
}

try {
    $pdo->exec($sqlite
        ? 'CREATE INDEX IF NOT EXISTS idx_donation_campaign ON gates_donations (campaign_id, status)'
        : 'CREATE INDEX idx_donation_campaign ON gates_donations (campaign_id, status)');
} catch (\Throwable) {
    // MySQL has no CREATE INDEX IF NOT EXISTS; a second run throws and that is fine.
}

echo DB::schema()->hasTable('gates_org_campaigns') ? "gates_org_campaigns OK\n" : "*** campaigns FAILED ***\n";
echo DB::schema()->hasColumn('gates_donations', 'campaign_id') ? "gates_donations.campaign_id OK\n" : "*** campaign_id FAILED ***\n";
