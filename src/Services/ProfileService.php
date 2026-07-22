<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class ProfileService {
    private const GRADS=[['#003020','#006040'],['#2a0050','#6020a0'],['#003060','#0060c0'],['#3a0060','#7a40b0'],['#002040','#004880'],['#400010','#900020'],['#3a0820','#6a1840'],['#302000','#604000'],['#001840','#003880'],['#003800','#007000']];
    private function g(int $id): array { return self::GRADS[$id%count(self::GRADS)]; }

    public function paginatedList(int $page,int $perPage,string $cat,string $tier,string $search,string $sort='cpi_desc',string $region='',string $country=''): array {
        $q=DB::table('gates_profiles')->where('status','approved');
        if($cat) $q->where('profile_type',$cat);
        if($tier) $q->where('cpi_tier',$tier);
        if($region) $q->where('region',$region);
        if($country) $q->where('country_code',$country);
        if($search) $q->where(fn($s)=>$s->where('display_name','LIKE',"%$search%")->orWhere('bio','LIKE',"%$search%")->orWhere('category','LIKE',"%$search%"));
        [$col,$dir]=[['cpi_desc','cpi_score','desc'],['cpi_asc','cpi_score','asc'],['recent','registered_at','desc'],['name_asc','display_name','asc']][array_search($sort,['cpi_desc','cpi_asc','recent','name_asc'])??0] ?? ['cpi_score','desc'];
        $ordArr=['cpi_desc'=>['cpi_score','desc'],'cpi_asc'=>['cpi_score','asc'],'recent'=>['registered_at','desc'],'name_asc'=>['display_name','asc']];
        [$oc,$od]=$ordArr[$sort]??['cpi_score','desc'];
        $q->orderBy($oc,$od);
        $total=$q->count();
        $rows=$q->select(['id','slug','display_name','category','profile_type','cpi_score','cpi_tier','verification_tier','avatar_path','country_code','region','completeness_pct'])->offset(($page-1)*$perPage)->limit($perPage)->get();
        return ['profiles'=>$rows->map(fn($r)=>$this->fmt($r))->values()->all(),'total'=>$total];
    }

    private function fmt(object $r): array {
        $g=$this->g((int)$r->id);
        return['id'=>$r->id,'slug'=>$r->slug,'display_name'=>$r->display_name,'category'=>$r->category,'profile_type'=>$r->profile_type,'cpi_score'=>(int)$r->cpi_score,'cpi_tier'=>$r->cpi_tier,'verification_tier'=>$r->verification_tier,'avatar_path'=>$r->avatar_path??null,'country_code'=>$r->country_code,'region'=>$r->region,'completeness_pct'=>(int)$r->completeness_pct,'cover_from'=>$g[0],'cover_to'=>$g[1]];
    }

    public function getLeaderboard(int $limit=20, string $category='', string $country=''): array {
        $q=DB::table('gates_profiles')->where('status','approved')->orderByDesc('cpi_score');
        if($category) $q->where('category',$category);
        if($country)  $q->where('country_code',$country);
        return $q->limit($limit)->get(['id','slug','display_name','category','profile_type','cpi_score','cpi_tier','verification_tier','avatar_path','country_code','region','completeness_pct'])->map(fn($r)=>array_merge($this->fmt($r),['rank'=>0]))->values()->all();
    }

    public function getTopCpiProfiles(int $limit=8): array {
        return DB::table('gates_profiles')->where('status','approved')->orderByDesc('cpi_score')->limit($limit)->get(['id','display_name','cpi_score','cpi_tier','category','country_code'])->map(fn($r)=>['display_name'=>$r->display_name,'cpi_score'=>(int)$r->cpi_score,'cpi_tier'=>$r->cpi_tier,'category'=>$r->category,'country_code'=>$r->country_code])->values()->all();
    }

    public function getFeaturedProfiles(int $limit=5): array {
        return DB::table('gates_profiles')->where('status','approved')->whereIn('cpi_tier',['diamond','platinum','gold'])->orderByDesc('cpi_score')->limit($limit)->get(['id','slug','display_name','category','profile_type','cpi_score','cpi_tier','verification_tier','avatar_path','country_code','region','completeness_pct'])->map(fn($r)=>$this->fmt($r))->values()->all();
    }

    public function getBySlug(string $slug): ?array {
        $r=DB::table('gates_profiles')->where('slug',$slug)->where('status','approved')->first();
        if(!$r) return null;
        DB::table('gates_profiles')->where('id',$r->id)->increment('view_count');
        return array_merge($this->fmt($r),['bio'=>$r->bio,'phone'=>$r->phone,'website'=>$r->website,'instagram_handle'=>$r->instagram_handle,'twitter_handle'=>$r->twitter_handle,'latitude'=>$r->latitude,'longitude'=>$r->longitude,'gallery_paths'=>$r->gallery_paths?json_decode($r->gallery_paths,true):[],'achievements'=>$r->achievements?json_decode($r->achievements,true):[],'tags'=>$r->tags?json_decode($r->tags,true):[],'registered_at'=>$r->registered_at,'view_count'=>(int)$r->view_count,'cpi_last_computed'=>$r->cpi_last_computed]);
    }

    public function register(array $d): int {
        $slug=$this->makeSlug($d['display_name']);
        return DB::table('gates_profiles')->insertGetId(['slug'=>$slug,'display_name'=>trim($d['display_name']),'profile_type'=>$d['profile_type']??'individual','category'=>$d['category']??'','bio'=>trim($d['bio']??''),'email'=>strtolower(trim($d['email'])),'country_code'=>strtoupper($d['country_code']??'NG'),'region'=>$this->countryToRegion($d['country_code']??'NG'),'completeness_pct'=>$this->completeness($d),'status'=>'pending','registered_at'=>Carbon::now(),'updated_at'=>Carbon::now()]);
    }

    public function getMapPins(): array {
        return DB::table('gates_profiles')->where('status','approved')->whereNotNull('latitude')->whereNotNull('longitude')->get(['id','slug','display_name','category','cpi_tier','cpi_score','latitude','longitude','country_code'])->map(fn($r)=>['id'=>$r->id,'slug'=>$r->slug,'display_name'=>$r->display_name,'category'=>$r->category,'cpi_tier'=>$r->cpi_tier,'cpi_score'=>(int)$r->cpi_score,'lat'=>(float)$r->latitude,'lng'=>(float)$r->longitude,'country_code'=>$r->country_code])->values()->all();
    }

    /**
     * Build a unique profile slug. The base is AI-generated when a provider is
     * configured (transliterates accents/non-Latin names, drops honorifics —
     * e.g. "Dr. Chinwé Okónkwò" → "chinwe-okonkwo"), with a deterministic
     * slugifier as the guaranteed fallback; uniqueness (-2, -3…) is enforced
     * against gates_profiles either way.
     */
    private function makeSlug(string $name): string {
        return AiHelper::uniqueSlug(
            $name,
            fn(string $slug): bool => DB::table('gates_profiles')->where('slug', $slug)->exists(),
            'profile'
        );
    }

    private function completeness(array $d): int {
        $s=10;
        foreach(['bio'=>20,'category'=>10,'phone'=>5,'website'=>5,'instagram_handle'=>5,'twitter_handle'=>5] as $f=>$w) if(!empty(trim($d[$f]??''))) $s+=$w;
        return min(100,$s);
    }

    private function countryToRegion(string $code): string {
        $west=['NG','GH','SN','CI','CM','ML','BF','NE','GN','BJ','TG','SL','LR','MR','GW','GM'];
        $east=['ET','KE','TZ','UG','RW','SO','SS','ER','DJ','BI','MG','KM','SC','MU'];
        $north=['EG','MA','DZ','TN','LY','SD','EH'];
        $south=['ZA','ZW','ZM','MW','MZ','BW','NA','LS','SZ'];
        if(in_array($code,$west)) return 'west';
        if(in_array($code,$east)) return 'east';
        if(in_array($code,$north)) return 'north';
        if(in_array($code,$south)) return 'south';
        return 'central';
    }
}
