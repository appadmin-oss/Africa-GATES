<?php
/**
 * The volunteer application, rebuilt (owner, 29 Oct 2026: "not modern at all; we need all
 * essential info"). What a coordinator needs before a first shift and did not have:
 *
 *   phone          how a lead reaches them on the night (WhatsApp works on it)
 *   gender         optional, "prefer not to say" offered; for balanced teams and safe pairing
 *   occupation     student, working, and so on: when they are free follows from it
 *   skills         what they can bring beyond the roles (first aid, photography, a language…)
 *   experience     short, optional: volunteering they have done before
 *   heard_from     how they found us, so outreach that works is done again
 *   access_needs   optional: anything we should arrange so they can take part
 *   tshirt         the size of their volunteer T-shirt, so the night has the right ones
 *
 * The state, availability, emergency contact and motivation columns already existed and the
 * old form never asked for them. VARCHAR, never ENUM. Idempotent. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if (!$schema->hasTable('gates_volunteers')) return;
$sqlite = DB::connection()->getDriverName() === 'sqlite';
$str = static fn (int $n) => $sqlite ? 'TEXT NULL' : "VARCHAR($n) NULL";
foreach (['phone' => 32, 'gender' => 16, 'occupation' => 32, 'skills' => 255, 'experience' => 500, 'heard_from' => 64, 'access_needs' => 255, 'tshirt' => 8] as $col => $n) {
    if (!$schema->hasColumn('gates_volunteers', $col)) {
        DB::statement("ALTER TABLE gates_volunteers ADD COLUMN $col {$str($n)}");
        echo "  + gates_volunteers.$col\n";
    }
}
