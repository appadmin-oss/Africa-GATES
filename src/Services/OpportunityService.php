<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class OpportunityService {
    public function getActiveOpportunities(int $limit=0): array {
        $q=DB::table('gates_opportunities')->where('status','active')->where(fn($q)=>$q->whereNull('deadline')->orWhere('deadline','>=',Carbon::now()->toDateString()))->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')->orderBy('deadline');
        if($limit>0) $q->limit($limit);
        return $q->get()->map(fn($o)=>(array)$o)->values()->all();
    }
}
