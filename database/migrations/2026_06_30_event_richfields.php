<?php
/** Event detail: admin-customizable run-of-show, map, ticket tiers, early-bird banner + RSVP tier. Idempotent. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

$cols = [
    'gates_site_events' => [
        'schedule'            => 'TEXT',                                        // JSON run-of-show [{time,title,body}]
        'map_embed'           => $sqlite ? 'TEXT' : 'VARCHAR(500) DEFAULT NULL', // map URL / embed src
        'ticket_tiers'        => 'TEXT',                                        // JSON [{name,price,perk,sold_out}]
        'early_bird_text'     => $sqlite ? 'TEXT' : 'VARCHAR(255) DEFAULT NULL',
        'early_bird_deadline' => $sqlite ? 'TEXT' : 'DATETIME DEFAULT NULL',
        'early_bird_url'      => $sqlite ? 'TEXT' : 'VARCHAR(500) DEFAULT NULL',
    ],
    'gates_event_registrations' => [
        'tier' => $sqlite ? 'TEXT' : 'VARCHAR(80) DEFAULT NULL',                // chosen ticket tier (if any)
    ],
];

foreach ($cols as $table => $defs) {
    if (!$schema->hasTable($table)) { echo "skip (no table): $table\n"; continue; }
    foreach ($defs as $col => $type) {
        if (!$schema->hasColumn($table, $col)) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
            echo "added {$table}.{$col}\n";
        }
    }
}

echo "event-richfields migration OK\n";
