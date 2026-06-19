<?php
/** Dev-only: seed approved nominees into the two open VOTING cycles so the
 *  voting flow is exercisable locally. Idempotent. Safe to delete in admin. */
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
$c = new DB(); $c->addConnection(require __DIR__ . '/../config/database.php'); $c->setAsGlobal(); $c->bootEloquent();

$now = Carbon::now()->toDateTimeString();
$seed = [
    // Carol Awards · Most Influential Choir (cat 12)
    [12,'Cathedral Voices','Lagos cathedral choir, 80 voices','NG'],
    [12,'Grace Chorale','Accra contemporary gospel ensemble','GH'],
    [12,'Harmony Collective','Nairobi inter-faith youth choir','KE'],
    // Carol Awards · Best Contemporary Choir (cat 13)
    [13,'The Redeemed Sound','Abuja praise collective','NG'],
    [13,'Cape Town Voices','Western Cape a-cappella group','ZA'],
    // Business Excellence · Entrepreneur of the Year (cat 17)
    [17,'Adunni Foods','Farm-to-table agritech, Lagos','NG'],
    [17,'Sahel Solar','Off-grid solar for rural homes','SN'],
    [17,'Kazi Logistics','Last-mile delivery network','KE'],
    // Business Excellence · Innovative Start-up (cat 19)
    [19,'PayWave','Cross-border payments rails','NG'],
    [19,'MediTrack','Cold-chain for vaccines','RW'],
];
$added = 0;
foreach ($seed as [$cat,$name,$tag,$cc]) {
    $exists = DB::table('gates_nominees')->where('category_id',$cat)->where('name',$name)->exists();
    if ($exists) continue;
    DB::table('gates_nominees')->insert([
        'category_id'=>$cat,'profile_id'=>null,'name'=>$name,'tagline'=>$tag,
        'country_code'=>$cc,'status'=>'approved','vote_count'=>0,'nominated_at'=>$now,
    ]);
    $added++;
}
echo "added $added nominees. total nominees: " . DB::table('gates_nominees')->count() . "\n";
