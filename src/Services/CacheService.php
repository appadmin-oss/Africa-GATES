<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class CacheService {
    public function remember(string $key, int $ttl, callable $cb, array $tags = []): mixed {
        try { $r=DB::table('gates_cache')->where('cache_key',$key)->where('expires_at','>',Carbon::now())->first(); if($r) return json_decode($r->payload,true); } catch(\Throwable) {}
        $v=$cb();
        try { DB::table('gates_cache')->updateOrInsert(['cache_key'=>$key],['payload'=>json_encode($v),'tags'=>$tags?implode(',',$tags):null,'expires_at'=>Carbon::now()->addSeconds($ttl)->toDateTimeString(),'created_at'=>Carbon::now()->toDateTimeString()]); } catch(\Throwable) {}
        return $v;
    }
    public function get(string $key): mixed {
        try { $r=DB::table('gates_cache')->where('cache_key',$key)->where('expires_at','>',Carbon::now())->first(); return $r?json_decode($r->payload,true):null; } catch(\Exception) { return null; }
    }
    public function forget(string $key): void { try { DB::table('gates_cache')->where('cache_key',$key)->delete(); } catch(\Exception) {} }
    public function forgetByTag(string $tag): void { try { DB::table('gates_cache')->where('tags','LIKE',"%$tag%")->delete(); } catch(\Exception) {} }
    public function prune(): void { try { DB::table('gates_cache')->where('expires_at','<',Carbon::now())->delete(); } catch(\Exception) {} }
}
