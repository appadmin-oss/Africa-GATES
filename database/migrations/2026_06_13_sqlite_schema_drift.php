<?php
/**
 * Schema-drift repair for the SQLite dev/fallback DB.
 *
 * Six tables shipped in the MySQL INSTALL.sql were never added to the SQLite
 * schema, so on SQLite the services that use them (FraudService, MilestoneService,
 * EventService, nomination-draft autosave) silently no-op inside their try/catch.
 * This adds the missing tables (SQLite dialect) + the fraud columns FraudService
 * stamps onto gates_votes. Idempotent: safe to re-run.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

if (DB::connection()->getDriverName() !== 'sqlite') {
    fwrite(STDERR, "Refusing to run: connection is not SQLite (use INSTALL.sql / schema.sql for MySQL).\n");
    exit(1);
}

$ddl = <<<'SQL'
CREATE TABLE IF NOT EXISTS gates_fraud_scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  vote_id INTEGER,
  email_hash TEXT NOT NULL,
  ip_hash TEXT,
  device_hash TEXT,
  risk_score INTEGER NOT NULL DEFAULT 0,
  signals TEXT,
  decision TEXT NOT NULL DEFAULT 'allow' CHECK(decision IN ('allow','monitor','flag','block')),
  reviewed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_fraud_email    ON gates_fraud_scores(email_hash);
CREATE INDEX IF NOT EXISTS idx_fraud_score    ON gates_fraud_scores(risk_score);
CREATE INDEX IF NOT EXISTS idx_fraud_decision ON gates_fraud_scores(decision);

CREATE TABLE IF NOT EXISTS gates_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  actor_type TEXT NOT NULL DEFAULT 'system' CHECK(actor_type IN ('voter','nominator','admin','judge','system')),
  actor_hash TEXT,
  subject_type TEXT,
  subject_id INTEGER,
  payload TEXT,
  ip_hash TEXT,
  device_hash TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_event_name    ON gates_events(name);
CREATE INDEX IF NOT EXISTS idx_event_created ON gates_events(created_at);
CREATE INDEX IF NOT EXISTS idx_event_subject ON gates_events(subject_type, subject_id);

CREATE TABLE IF NOT EXISTS gates_funnel_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL,
  step TEXT NOT NULL,
  nominee_id INTEGER,
  award_id INTEGER,
  device_hash TEXT,
  ip_hash TEXT,
  meta TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_funnel_session ON gates_funnel_events(session_id);
CREATE INDEX IF NOT EXISTS idx_funnel_step    ON gates_funnel_events(step);
CREATE INDEX IF NOT EXISTS idx_funnel_created ON gates_funnel_events(created_at);

CREATE TABLE IF NOT EXISTS gates_vote_milestones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nominee_id INTEGER NOT NULL,
  milestone INTEGER NOT NULL,
  notified INTEGER NOT NULL DEFAULT 0,
  achieved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(nominee_id, milestone),
  FOREIGN KEY(nominee_id) REFERENCES gates_nominees(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS gates_nomination_drafts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_key TEXT NOT NULL UNIQUE,
  payload TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_draft_updated ON gates_nomination_drafts(updated_at);

CREATE TABLE IF NOT EXISTS gates_vote_snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cycle_id INTEGER NOT NULL,
  nominee_id INTEGER NOT NULL,
  vote_count INTEGER NOT NULL,
  judge_score REAL,
  cpi_score INTEGER NOT NULL DEFAULT 0,
  snapshot_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hash TEXT
);
CREATE INDEX IF NOT EXISTS idx_snap_cycle   ON gates_vote_snapshots(cycle_id);
CREATE INDEX IF NOT EXISTS idx_snap_nominee ON gates_vote_snapshots(nominee_id);
SQL;

$before = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'gates_%'"))->pluck('name')->all();

DB::connection()->getPdo()->exec($ddl);

// FraudService stamps risk_score + fraud_flag onto the vote row; add if absent.
$schema = DB::schema();
foreach (['risk_score' => 'INTEGER NOT NULL DEFAULT 0', 'fraud_flag' => 'INTEGER NOT NULL DEFAULT 0'] as $col => $type) {
    if (!$schema->hasColumn('gates_votes', $col)) {
        DB::statement("ALTER TABLE gates_votes ADD COLUMN {$col} {$type}");
        echo "  + gates_votes.{$col} added\n";
    } else {
        echo "  = gates_votes.{$col} already present\n";
    }
}

$after = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'gates_%'"))->pluck('name')->all();
$created = array_values(array_diff($after, $before));

echo "\nTables created this run: " . (count($created) ? implode(', ', $created) : '(none — already present)') . "\n";
echo "Total gates_ tables now: " . count($after) . "\n";

foreach (['gates_fraud_scores','gates_events','gates_funnel_events','gates_vote_milestones','gates_nomination_drafts','gates_vote_snapshots'] as $t) {
    printf("  %-26s %s\n", $t, $schema->hasTable($t) ? 'OK' : '*** STILL MISSING ***');
}
