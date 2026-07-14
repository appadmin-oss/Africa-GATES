<?php
/** Blog posts: optional audio (narration / podcast) URL. Idempotent + driver-aware. */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasColumn('gates_posts', 'audio_path')) {
    DB::statement('ALTER TABLE gates_posts ADD COLUMN audio_path ' . ($sqlite ? 'TEXT' : 'VARCHAR(255) DEFAULT NULL'));
    echo "added gates_posts.audio_path\n";
}

echo "post-audio migration OK\n";
