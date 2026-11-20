<?php
/**
 * A member account can hold a photograph, and every nominee's uploaded photo reaches their page.
 *
 * 5 Nov 2026 (owner): "photos uploaded are not reflecting on the nominee page … that photo should
 * be connected to their Africa GATES account". Two writers never reached the column every public
 * page reads (`gates_nominees.photo_path`): approval dropped the nomination form's portrait, and a
 * nominee's own upload stayed in `gates_nominee_files`. See Services\NomineePhoto.
 *
 *   · `gates_users.photo_path` — the account's photo, VARCHAR(400) like every photo column here.
 *   · A REPAIR for every nominee already approved: NomineePhoto::sync() decides each one the way
 *     approval and uploads now do — the nominee's own photo first, an existing photo kept, the
 *     nominator's otherwise — and gives the account holding the page its photo. Only nominees with
 *     something to gain are visited (no photo yet, or a photo of their own on file), and sync only
 *     writes where the answer differs, so re-running changes nothing. NEVER exit/die here.
 */
require_once __DIR__ . '/../bootstrap.php';
use AfricaGates\Services\NomineePhoto;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

if (DB::schema()->hasTable('gates_users') && !SchemaHas::column('gates_users', 'photo_path')) {
    $sqlite = DB::connection()->getDriverName() === 'sqlite';
    DB::statement('ALTER TABLE gates_users ADD COLUMN photo_path ' . ($sqlite ? 'TEXT' : 'VARCHAR(400) NULL DEFAULT NULL'));
    echo "  + gates_users.photo_path\n";
}
SchemaHas::forget();   // the column above is new: drop the memoised "no"

if (!DB::schema()->hasTable('gates_nominees')) { echo "  · no nominees table\n"; return; }

$ids = DB::table('gates_nominees')->where(static fn ($q) => $q->whereNull('photo_path')->orWhere('photo_path', ''))->pluck('id')->all();
if (DB::schema()->hasTable('gates_nominee_files')) {
    $ids = array_merge($ids, DB::table('gates_nominee_files')->where('kind', 'photo')->whereNull('removed_at')
        ->whereNotNull('nominee_id')->distinct()->pluck('nominee_id')->all());
}
$fixed = 0;
foreach (array_unique(array_map('intval', $ids)) as $id) {
    $before = (string) DB::table('gates_nominees')->where('id', $id)->value('photo_path');
    try {
        if (NomineePhoto::sync($id) !== $before) $fixed++;
    } catch (\Throwable $e) {
        echo "  ! nominee $id: " . $e->getMessage() . "\n";
    }
}
echo "  · nominee photos now showing: $fixed\n";
