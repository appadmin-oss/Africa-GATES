<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/** The public portrait and gallery share the same active-photo policy. */
final class NomineePortrait
{
    public static function uploads(int|array $ids): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('gates_uploads')->where('attached_to_type', 'nominee')->whereIn('attached_to_id', is_array($ids) ? $ids : [$ids]);
        if (SchemaHas::table('gates_nominee_files')) {
            $q->whereNotExists(function ($removed) {
                $removed->selectRaw('1')->from('gates_nominee_files')->whereColumn('nominee_id', 'gates_uploads.attached_to_id')
                    ->where('kind', 'photo')->whereNotNull('removed_at')->whereColumn('path', 'gates_uploads.path');
            });
        }
        return $q;
    }

    /** Preserve a selected portrait; fill an empty one or replace a removed one. */
    public static function reconcile(int $id): void
    {
        DB::connection()->transaction(function () use ($id) {
            $n = DB::table('gates_nominees')->where('id', $id)->lockForUpdate()->first();
            if (!$n) return;
            $primary = (string) ($n->photo_path ?? '');
            $removed = $primary !== '' && SchemaHas::table('gates_nominee_files') && DB::table('gates_nominee_files')
                ->where('nominee_id', $id)->where('kind', 'photo')->where('path', $primary)->whereNotNull('removed_at')->exists();
            if ($primary === '' || $removed) {
                $next = SchemaHas::table('gates_nominee_files') ? DB::table('gates_nominee_files')
                    ->where('nominee_id', $id)->where('kind', 'photo')->whereNull('removed_at')->whereNotNull('path')
                    ->where('path', '<>', '')->orderBy('id')->value('path') : null;
                $next ??= self::uploads($id)->orderBy('id')->value('path');
                DB::table('gates_nominees')->where('id', $id)->update(['photo_path' => $next]);
            }
        });
        self::refresh();
    }

    /** Remove gallery metadata atomically, retaining self-service history. */
    public static function remove(int $id, string $path): void
    {
        DB::connection()->transaction(function () use ($id, $path) {
            DB::table('gates_nominees')->where('id', $id)->lockForUpdate()->first();
            if (SchemaHas::table('gates_nominee_files')) {
                DB::table('gates_nominee_files')->where('nominee_id', $id)->where('kind', 'photo')->where('path', $path)
                    ->whereNull('removed_at')->update(['removed_at' => date('Y-m-d H:i:s')]);
            }
            DB::table('gates_uploads')->where('attached_to_type', 'nominee')->where('attached_to_id', $id)->where('path', $path)->delete();
            DB::table('gates_nominees')->where('id', $id)->where('photo_path', $path)->update(['photo_path' => null]);
            self::reconcile($id);
        });
    }

    public static function refresh(): void
    {
        $cache = new CacheService();
        $cache->forgetAwardViews();
        foreach (['registry', 'recognitions', 'home'] as $tag) $cache->forgetByTag($tag);
    }
}
