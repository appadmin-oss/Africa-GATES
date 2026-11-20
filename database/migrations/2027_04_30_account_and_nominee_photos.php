<?php
/** Member portraits and repair of previously disconnected nominee uploads. Idempotent. */
require_once __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
if ($schema->hasTable('gates_users') && !$schema->hasColumn('gates_users', 'avatar_path')) {
    $schema->table('gates_users', function ($table) { $table->string('avatar_path', 400)->nullable(); });
}
if ($schema->hasTable('gates_nominee_files') && $schema->hasTable('gates_nominees')) {
    DB::table('gates_nominee_files')->where('kind', 'photo')->whereNull('removed_at')
        ->whereNotNull('path')->where('path', '<>', '')->orderBy('id')->chunkById(200, function ($files) {
            foreach ($files as $file) {
                // Conditional update preserves staff selections and makes re-running safe.
                DB::table('gates_nominees')->where('id', $file->nominee_id)
                    ->where(function ($q) { $q->whereNull('photo_path')->orWhere('photo_path', ''); })
                    ->update(['photo_path' => $file->path]);
            }
        });
    (new \AfricaGates\Services\CacheService())->forgetAwardViews();
}
