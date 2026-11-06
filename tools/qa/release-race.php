<?php
/**
 * Opt-in MySQL publication race regression. Requires a prepared disposable DB:
 * DB_NAME=africa_gates_test DB_USER=... DB_PASS=... php tools/qa/release-race.php
 * Never runs against a database without an explicit _test or _audit suffix.
 * Creates its own award, releases it from two processes, then removes only its fixtures.
 */
declare(strict_types=1);
use AfricaGates\Services\CycleMaterialiser;
use Illuminate\Database\Capsule\Manager as DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$name=getenv('DB_NAME');
if (!$name || !preg_match('/_(test|audit)$/D',$name)) {
    fwrite(STDERR,"Set DB_NAME explicitly to a prepared disposable _test or _audit database.\n");exit(2);
}
$_ENV['DB_DRIVER']='mysql';
foreach (['DB_NAME','DB_HOST','DB_PORT','DB_USER','DB_PASS'] as $key) {
    if (getenv($key)!==false) $_ENV[$key]=getenv($key);
}
AfricaGates\Support\Clock::boot();
$db=new DB();$db->addConnection(require dirname(__DIR__,2).'/config/database.php');$db->setAsGlobal();$db->bootEloquent();
if (DB::table('gates_webhooks')->where('is_active',1)->exists()) {
    fwrite(STDERR,"Use a disposable database with outbound webhooks disabled.\n");exit(2);
}

if (($argv[1]??'')==='--worker') {
    [$cycle,$role,$dir]=[(int)$argv[2],$argv[3],$argv[4]];
    file_put_contents($dir.'/'.$role.'.ready','1');$deadline=microtime(true)+15;
    while (!is_file($dir.'/go')) {
        if(microtime(true)>$deadline)throw new RuntimeException('Worker start barrier timed out');
        usleep(5000);
    }
    echo json_encode((new CycleMaterialiser())->release($cycle)),"\n";exit(0);
}

$dir=sys_get_temp_dir().'/gates-release-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$programme=null;$createdProgramme=false;$cycles=[];$children=[];
try {
    // The programme primary key is TINYINT; use an unused valid ID rather than
    // letting a long test run overflow its auto-increment counter.
    $used=DB::table('gates_award_programmes')->pluck('id')->map(static fn($id)=>(int)$id)->all();
    foreach(range(1,254) as $id)if(!in_array($id,$used,true)){$programme=$id;break;}
    if($programme===null)throw new RuntimeException('No unused programme ID for the isolated fixture');
    DB::table('gates_award_programmes')->insert(['id'=>$programme,'slug'=>'race-'.bin2hex(random_bytes(6)),'title'=>'Release race fixture','is_active'=>1]);
    $createdProgramme=true;
    for($round=0;$round<5;$round++) {
        foreach(glob($dir.'/*')?:[] as $file)unlink($file);
        $cycle=(int)DB::table('gates_award_cycles')->insertGetId(['programme_id'=>$programme,'year'=>(int)date('Y'),'status'=>'judging',
            'voting_open'=>date('Y-m-d H:i:s',time()-86400*2),'voting_close'=>date('Y-m-d H:i:s',time()-3600)]);
        $cycles[]=$cycle;
        $category=(int)DB::table('gates_award_categories')->insertGetId(['cycle_id'=>$cycle,'slug'=>'leadership','title'=>'Leadership']);
        DB::table('gates_nominees')->insert(['category_id'=>$category,'name'=>'Release fixture','status'=>'approved','vote_count'=>7]);
        $children=[];
        foreach(['a','b'] as $role) {
            $command=[PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--worker',(string)$cycle,$role,$dir];
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start release worker');
            fclose($pipes[0]);$children[]=[$process,$pipes];
        }
        $deadline=microtime(true)+15;
        while(!is_file($dir.'/a.ready')||!is_file($dir.'/b.ready')) {
            if(microtime(true)>$deadline)throw new RuntimeException('Workers did not become ready');
            usleep(5000);
        }
        touch($dir.'/go');$sealed=0;
        foreach($children as [$process,$pipes]) {
            $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            $result=json_decode($out,true);
            if($exit!==0||empty($result['ok']))throw new RuntimeException('Release worker failed: '.$error.' '.$out);
            $sealed+=(int)$result['sealed'];
        }
        $children=[];
        $rows=DB::table('gates_vote_snapshots')->where('cycle_id',$cycle)->where('capture_kind','release')->count();
        if($sealed!==1||$rows!==1)throw new RuntimeException("Round $round created $rows seal rows ($sealed reported)");
        echo 'Round ',($round+1),": two successful releases, exactly one seal\n";
    }
} finally {
    foreach($children as [$process,$pipes]) {
        if(is_resource($process))proc_terminate($process);
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        if(is_resource($process))proc_close($process);
    }
    if($cycles) {
        DB::table('gates_vote_snapshots')->whereIn('cycle_id',$cycles)->delete();
        DB::table('gates_cycle_transitions')->whereIn('cycle_id',$cycles)->delete();
        $categories=DB::table('gates_award_categories')->whereIn('cycle_id',$cycles)->pluck('id');
        DB::table('gates_nominees')->whereIn('category_id',$categories)->delete();
        DB::table('gates_award_categories')->whereIn('cycle_id',$cycles)->delete();
        DB::table('gates_award_cycles')->whereIn('id',$cycles)->delete();
    }
    if($createdProgramme)DB::table('gates_award_programmes')->where('id',$programme)->delete();
    foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);
}
