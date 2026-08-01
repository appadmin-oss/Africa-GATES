<?php
/**
 * Photo and video on a community thread — which is what a Pulse post is.
 *
 * ── FOUR COLUMNS, NOT ONE ────────────────────────────────────────────────────
 *
 * A single `media_path` would force every reader to guess the type from the file
 * extension and to lay the media out without knowing its shape. Both guesses go
 * wrong in ways that show: an `<img>` pointed at an .mp4 renders a broken icon,
 * and media with no intrinsic size makes the whole feed jump as each item loads,
 * which on a phone means the post you were about to tap moves out from under
 * your thumb. So the type is recorded, and so are the dimensions — `width` and
 * `height` on the element reserve the box before a byte of the file arrives.
 *
 * ── ADDITIVE AND NULLABLE, LIKE EVERY COLUMN ON THIS TABLE ───────────────────
 *
 * Every existing thread has no media and must keep working, so all four are NULL
 * by default and every reader treats NULL as "text post". Nothing backfills,
 * because there is nothing to backfill from.
 *
 * Idempotent: each column is added only when absent, so re-running is safe.
 */
require __DIR__ . '/../../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;

$c = new DB();
$c->addConnection(require __DIR__ . '/../../config/database.php');
$c->setAsGlobal();
$c->bootEloquent();

$sqlite = DB::connection()->getDriverName() === 'sqlite';

$columns = [
    // The serveable URL or path. Long, because a Cloudinary URL with a transform
    // in it is comfortably past what VARCHAR(255) holds.
    'media_path' => $sqlite ? 'TEXT' : 'VARCHAR(500)',
    // 'image' or 'video'. Stored rather than sniffed from the extension so the
    // renderer never has to parse a filename to decide which tag to emit.
    'media_type' => $sqlite ? 'TEXT' : 'VARCHAR(10)',
    // Intrinsic pixel size, so the layout can reserve the box and the feed does
    // not reflow as images arrive.
    'media_w'    => $sqlite ? 'INTEGER' : 'INT',
    'media_h'    => $sqlite ? 'INTEGER' : 'INT',
];

foreach ($columns as $col => $type) {
    if (DB::schema()->hasColumn('gates_threads', $col)) {
        echo "  = gates_threads.{$col} already present\n";
        continue;
    }
    DB::statement("ALTER TABLE gates_threads ADD COLUMN {$col} {$type} NULL DEFAULT NULL");
    echo "  + gates_threads.{$col} added\n";
}

echo "thread media OK\n";
