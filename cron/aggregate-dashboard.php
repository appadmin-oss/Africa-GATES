<?php
declare(strict_types=1);
use Illuminate\Support\Carbon;
/**
 * AFRICA GATES DASHBOARD CRON — Run every 4h
 * cPanel: 0 0,4,8,12,16,20 * * * /usr/bin/php /path/to/cron/aggregate-dashboard.php
 */
require __DIR__.'/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__.'/../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;
$cap=new DB();$cap->addConnection(require __DIR__.'/../config/database.php');$cap->setAsGlobal();$cap->bootEloquent();
if(!\AfricaGates\Support\CronGuard::acquire('aggregate-dashboard', __DIR__.'/../var/data')){fwrite(STDERR,"[dashboard] another run is still in progress — exiting.\n");exit(0);}
$t0=microtime(true);$log=fn(string $m)=>print('['.date('Y-m-d H:i:s').'] '.$m.PHP_EOL);$log('Dashboard aggregation starting…');

$tp=DB::table('gates_profiles')->where('status','approved')->count();
$tv=DB::table('gates_votes')->count();
$ag=DB::table('gates_nominees')->whereIn('status',['winner','runner_up'])->count();
$rd=DB::table('gates_profiles')->where('status','approved')->selectRaw('region,COUNT(*) as count')->groupBy('region')->orderByDesc('count')->get()->map(fn($r)=>['region'=>$r->region,'count'=>(int)$r->count])->toArray();
$td=DB::table('gates_profiles')->where('status','approved')->whereNotIn('cpi_tier',['unranked'])->selectRaw('cpi_tier as tier,COUNT(*) as count')->groupBy('cpi_tier')->get()->map(fn($r)=>['tier'=>$r->tier,'count'=>(int)$r->count])->toArray();
$cats=DB::table('gates_profiles')->where('status','approved')->selectRaw('category,COUNT(*) as count')->groupBy('category')->orderByDesc('count')->limit(12)->get()->map(fn($r)=>['category'=>$r->category,'count'=>(int)$r->count])->toArray();
$cs=DB::table('gates_profiles')->where('status','approved')->selectRaw('country_code,COUNT(*) as profiles,MAX(cpi_score) as top_cpi')->groupBy('country_code')->get()->mapWithKeys(fn($r)=>[$r->country_code=>['profiles'=>(int)$r->profiles,'top_cpi'=>(int)$r->top_cpi]])->toArray();

$payload=json_encode(['total_profiles'=>$tp,'total_votes'=>$tv,'awards_given'=>$ag,'region_dist'=>$rd,'tier_dist'=>$td,'categories'=>$cats,'country_stats'=>$cs,'aggregated_at'=>Carbon::now()->toDateTimeString()]);
$exp=Carbon::now()->addHours(5)->toDateTimeString();
DB::table('gates_cache')->updateOrInsert(['cache_key'=>'api:dashboard'],['payload'=>$payload,'expires_at'=>$exp,'created_at'=>Carbon::now()->toDateTimeString()]);
DB::table('gates_cache')->updateOrInsert(['cache_key'=>'home:stats'],['payload'=>json_encode(['total_profiles'=>$tp,'total_votes'=>$tv,'events_count'=>DB::table('gates_legacy_events')->where('is_published',1)->count(),'awards_given'=>$ag]),'expires_at'=>$exp,'created_at'=>Carbon::now()->toDateTimeString()]);
$pruned=DB::table('gates_cache')->where('expires_at','<',Carbon::now()->toDateTimeString())->delete();
$ms=(int)round((microtime(true)-$t0)*1000);
DB::table('gates_cron_log')->insert(['job_name'=>'dashboard','status'=>'success','message'=>"Aggregated stats, pruned $pruned stale rows",'runtime_ms'=>$ms,'ran_at'=>Carbon::now()]);
$log("Done in {$ms}ms — $tp profiles, $tv votes");
