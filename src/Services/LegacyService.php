<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Database\Capsule\Manager as DB;

class LegacyService {
    public function getAllPublished(): array {
        return DB::table('gates_legacy_events')->where('is_published',1)->orderByDesc('event_date')->get()->map(fn($e)=>(array)$e)->values()->all();
    }
    public function getBySlug(string $slug): ?array {
        $e=DB::table('gates_legacy_events')->where('slug',$slug)->where('is_published',1)->first();
        if(!$e) return null;
        $a=(array)$e;
        foreach(['gallery_paths','highlight_reel'] as $f) {
            if(!empty($a[$f]) && is_string($a[$f])) {
                $decoded=json_decode($a[$f],true);
                $a[$f]=is_array($decoded)?$decoded:[];
            }
        }
        return $a;
    }
}
