<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class AwardService {
    public function __construct(private readonly ?SpamService $spam = null) {}

    public function getActiveProgrammesWithStatus(): array {
        $progs=DB::table('gates_award_programmes as p')
            ->select(['p.id','p.slug','p.title','p.subtitle','p.description','p.icon_emoji','p.sort_order','c.id as cycle_id','c.status as cycle_status','c.year','c.nominations_open','c.nominations_close','c.voting_open','c.voting_close','c.results_date'])
            ->leftJoin(DB::raw('(
                SELECT c.* FROM gates_award_cycles c
                INNER JOIN (SELECT programme_id, MAX(year) AS max_year FROM gates_award_cycles GROUP BY programme_id) latest
                ON c.programme_id = latest.programme_id AND c.year = latest.max_year
            ) AS c'), 'c.programme_id', '=', 'p.id')
            ->where('p.is_active',1)->orderBy('p.sort_order')->get();
        return $progs->map(fn($p)=>[
            'id'=>$p->id,'slug'=>$p->slug,'title'=>$p->title,'subtitle'=>$p->subtitle,
            'description'=>$p->description,'icon_emoji'=>$p->icon_emoji,
            'cycle_id'=>$p->cycle_id,'cycle_status'=>$p->cycle_status??'upcoming','year'=>$p->year??date('Y'),
            'nominations_open'=>$p->nominations_open,'nominations_close'=>$p->nominations_close,
            'voting_open'=>$p->voting_open,'voting_close'=>$p->voting_close,'results_date'=>$p->results_date,
            'categories'=>DB::table('gates_award_categories')->where('cycle_id',$p->cycle_id??0)->orderBy('sort_order')->get()->toArray(),
        ])->values()->all();
    }

    public function getProgrammeBySlug(string $slug): ?array {
        $p=DB::table('gates_award_programmes')->where('slug',$slug)->where('is_active',1)->first();
        if(!$p) return null;
        $cycle=DB::table('gates_award_cycles')->where('programme_id',$p->id)->orderByDesc('year')->first();
        $cats=$cycle?DB::table('gates_award_categories')->where('cycle_id',$cycle->id)->orderBy('sort_order')->get()->map(fn($c)=>['id'=>$c->id,'slug'=>$c->slug,'title'=>$c->title,'description'=>$c->description,'nominee_count'=>DB::table('gates_nominees')->where('category_id',$c->id)->where('status','approved')->count()])->values()->all():[];
        return['id'=>$p->id,'slug'=>$p->slug,'title'=>$p->title,'subtitle'=>$p->subtitle,'description'=>$p->description,'icon_emoji'=>$p->icon_emoji,'cycle'=>$cycle?(array)$cycle:null,'categories'=>$cats];
    }

    public function getNominees(int $programmeId, int $categoryId=0, int $year=0): array {
        $year=$year?:(int)date('Y');
        $cycle=DB::table('gates_award_cycles')->where('programme_id',$programmeId)->where('year',$year)->first();
        if(!$cycle) return [];
        $q=DB::table('gates_nominees as n')->join('gates_award_categories as c','c.id','=','n.category_id')
            ->where('c.cycle_id',$cycle->id)->where('n.status','approved');
        if($categoryId) $q->where('n.category_id',$categoryId);
        return $q->select(['n.id','n.name','n.tagline','n.photo_path','n.vote_count','n.category_id','n.country_code','c.title as category'])
            ->orderByDesc('n.vote_count')->get()
            ->map(fn($n)=>['id'=>$n->id,'name'=>$n->name,'tagline'=>$n->tagline,'photo_path'=>$n->photo_path,'vote_count'=>(int)$n->vote_count,'category_id'=>$n->category_id,'category'=>$n->category,'country_code'=>$n->country_code])
            ->values()->all();
    }

    public function submitNomination(array $data, string $ip): int {
        $cycle = DB::table('gates_award_cycles')
            ->where('programme_id', $data['programme_id'])
            ->whereIn('status', ['nominations'])
            ->orderByDesc('year')
            ->first();
        if (!$cycle) throw new \RuntimeException('Nominations are not currently open for this programme.');

        // Validate category — required when the cycle has categories, and must belong to this cycle.
        $catId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
        $hasCategories = DB::table('gates_award_categories')->where('cycle_id', $cycle->id)->exists();
        if ($hasCategories) {
            if (!$catId) throw new \RuntimeException('Please select a category.');
            $catValid = DB::table('gates_award_categories')
                ->where('id', $catId)->where('cycle_id', $cycle->id)->exists();
            if (!$catValid) throw new \RuntimeException('The selected category is not valid for this programme.');
        }

        // Spam/abuse gate — block obvious spam before it reaches the moderation
        // queue. Heuristics first, AI only on borderline; degrades gracefully if
        // no provider is configured (quarantine/allow → still goes to 'pending').
        if ($this->spam) {
            $check = trim(($data['nominee_name'] ?? '') . "\n" . ($data['reason'] ?? ''));
            if ($check !== '' && ($this->spam->evaluate($check, ['target' => 'nomination'])['decision'] ?? 'allow') === 'reject') {
                throw new \RuntimeException('This nomination was flagged as spam. Please rephrase the reason and try again.');
            }
        }

        // Build the core row — columns that have always existed
        $row = [
            'cycle_id'       => $cycle->id,
            'category_id'    => $catId,
            'nominee_name'   => trim($data['nominee_name']),
            'nominee_email'  => trim($data['nominee_email'] ?? ''),
            'country_code'   => strtoupper($data['country_code'] ?? ''),
            'reason'         => trim($data['reason'] ?? ''),
            'nominator_name' => trim($data['nominator_name']),
            'nominator_email'=> strtolower(trim($data['nominator_email'])),
            'status'         => 'pending',
            'ip_hash'        => $ip ? hash('sha256', $ip) : null,
            'created_at'     => \Illuminate\Support\Carbon::now(),
        ];

        // Extended fields — only include if the columns exist (migration may not have run yet)
        $schema  = DB::getSchemaBuilder();
        $hasNew  = $schema->hasColumn('gates_nominations', 'nominee_state');
        if ($hasNew) {
            $row['nominee_state']       = trim($data['nominee_state']       ?? '');
            $row['nominee_lga']         = trim($data['nominee_lga']         ?? '');
            $row['reference_url']       = trim($data['reference_url']       ?? '');
            $row['reference_url_2']     = trim($data['reference_url_2']     ?? '');
            $row['reference_url_3']     = trim($data['reference_url_3']     ?? '');
            $row['nominator_phone']     = trim($data['nominator_phone']     ?? '');
            $row['nominator_location']  = trim($data['nominator_location']  ?? '');
            $row['nominator_country']   = strtoupper($data['nominator_country'] ?? '');
            $row['nominator_state']     = trim($data['nominator_state']     ?? '');
            $row['nominator_lga']       = trim($data['nominator_lga']       ?? '');
        }

        return DB::table('gates_nominations')->insertGetId($row);
    }
}
