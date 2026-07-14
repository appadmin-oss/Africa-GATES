<?php
declare(strict_types=1);
namespace AfricaGates\Services;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class AwardService {
    public function __construct(private readonly ?SpamService $spam = null) {}

    public function getActiveProgrammesWithStatus(): array {
        $progs = DB::table('gates_award_programmes')->where('is_active', 1)->orderBy('sort_order')->get();
        return $progs->map(function ($p) {
            // Pick the programme's CURRENT cycle by status + recency, NOT a hard
            // match on the calendar year. An in-flight cycle (nominations…results)
            // wins over an idle one; otherwise the most recent. This stops an active
            // cycle from going dark at the New Year boundary or when seeded ahead.
            $c = DB::table('gates_award_cycles')
                ->where('programme_id', $p->id)
                ->orderByRaw("CASE WHEN status IN ('nominations','voting','judging','results') THEN 0 ELSE 1 END")
                ->orderByDesc('year')->orderByDesc('id')
                ->first();
            return [
                'id'=>$p->id,'slug'=>$p->slug,'title'=>$p->title,'subtitle'=>$p->subtitle ?? null,
                'description'=>$p->description ?? null,'icon_emoji'=>$p->icon_emoji ?? null,
                'cycle_id'=>$c->id ?? null,'cycle_status'=>$c->status ?? 'upcoming','year'=>$c->year ?? (int)date('Y'),
                'nominations_open'=>$c->nominations_open ?? null,'nominations_close'=>$c->nominations_close ?? null,
                'voting_open'=>$c->voting_open ?? null,'voting_close'=>$c->voting_close ?? null,'results_date'=>$c->results_date ?? null,
                'categories'=>DB::table('gates_award_categories')->where('cycle_id',$c->id ?? 0)->orderBy('sort_order')->get()->toArray(),
            ];
        })->values()->all();
    }

    public function getProgrammeBySlug(string $slug): ?array {
        $p=DB::table('gates_award_programmes')->where('slug',$slug)->where('is_active',1)->first();
        if(!$p) return null;
        // Same status-aware "current cycle" pick as getActiveProgrammesWithStatus,
        // so a programme's voting state is consistent between the /vote hub and page.
        $cycle=DB::table('gates_award_cycles')->where('programme_id',$p->id)
            ->orderByRaw("CASE WHEN status IN ('nominations','voting','judging','results') THEN 0 ELSE 1 END")
            ->orderByDesc('year')->orderByDesc('id')->first();
        $cats=$cycle?DB::table('gates_award_categories')->where('cycle_id',$cycle->id)->orderBy('sort_order')->get()->map(fn($c)=>['id'=>$c->id,'slug'=>$c->slug,'title'=>$c->title,'description'=>$c->description,'nominee_count'=>DB::table('gates_nominees')->where('category_id',$c->id)->where('status','approved')->count()])->values()->all():[];
        return['id'=>$p->id,'slug'=>$p->slug,'title'=>$p->title,'subtitle'=>$p->subtitle ?? null,'description'=>$p->description ?? null,'icon_emoji'=>$p->icon_emoji ?? null,'cycle'=>$cycle?(array)$cycle:null,'categories'=>$cats];
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

    /**
     * Vote-hub cards: every active programme with its current-cycle voting
     * summary (leading nominee, total votes, nominee count, and the top-3 vote
     * share for a segmented bar). Drives the /vote hub. All real data.
     *
     * @return array<int,array<string,mixed>>
     */
    public function voteHub(): array {
        return array_map(function (array $p) {
            $noms = !empty($p['cycle_id'])
                ? $this->getNominees((int) $p['id'], 0, (int) $p['year'])
                : [];
            $total = 0;
            foreach ($noms as $n) { $total += (int) $n['vote_count']; }
            $top = array_slice($noms, 0, 3);
            $topSum = 0;
            foreach ($top as $n) { $topSum += (int) $n['vote_count']; }
            $bars = [];
            foreach ($top as $n) {
                $bars[] = $topSum > 0
                    ? (int) round((int) $n['vote_count'] / $topSum * 100)
                    : (int) round(100 / max(1, count($top)));
            }
            return $p + [
                'total_votes'   => $total,
                'nominee_count' => count($noms),
                'leader'        => $noms[0] ?? null,
                'bars'          => $bars,
            ];
        }, $this->getActiveProgrammesWithStatus());
    }

    public function submitNomination(array $data, string $ip): int {
        $cycle = DB::table('gates_award_cycles')
            ->where('programme_id', $data['programme_id'])
            ->where('year', date('Y'))
            ->whereIn('status', ['nominations'])
            ->first();
        if (!$cycle) throw new \RuntimeException('Nominations are not currently open for this programme.');

        // Nominee contact: EMAIL OR PHONE — every approved nominee must be
        // reachable (acceptance/verification flow + the gated one-time form),
        // but either channel satisfies that. A contact the nominator actually
        // typed must validate — never silently drop it.
        $nomineeEmail    = strtolower(trim((string)($data['nominee_email'] ?? '')));
        $nomineePhoneRaw = trim((string)($data['nominee_phone'] ?? ''));
        if ($nomineeEmail !== '' && !filter_var($nomineeEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('The nominee email address is not valid.');
        }
        $nomineePhone = null;
        if ($nomineePhoneRaw !== '') {
            $nomineePhone = \AfricaGates\Support\Phone::normalize($nomineePhoneRaw, (string)($data['country_code'] ?? ''));
            if ($nomineePhone === null) {
                throw new \RuntimeException('The nominee phone number is not valid — include the country code (e.g. +234 803 123 4567).');
            }
        }
        if ($nomineeEmail === '' && $nomineePhone === null) {
            throw new \RuntimeException("Please provide the nominee's email address or phone number — at least one is required.");
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
            'category_id'    => !empty($data['category_id']) ? (int)$data['category_id'] : null,
            'nominee_name'   => trim($data['nominee_name']),
            'nominee_email'  => $nomineeEmail !== '' ? $nomineeEmail : null,
            'country_code'   => strtoupper($data['country_code'] ?? ''),
            'reason'         => trim($data['reason'] ?? ''),
            'nominator_name' => trim($data['nominator_name']),
            'nominator_email'=> strtolower(trim($data['nominator_email'])),
            'status'         => 'pending',
            'ip_hash'        => $ip ? hash('sha256', $ip) : null,
            'created_at'     => \Illuminate\Support\Carbon::now(),
        ];

        // Extended fields — include each ONLY if its column exists, checked per
        // column against the live table. The old code gated all of these on a
        // single probe column (nominee_state); on a fresh MySQL schema that probe
        // passed while nominator_country/state/lga were absent, so the INSERT threw
        // and every nomination failed. Guarding each column independently makes the
        // writer correct on any partially-migrated schema.
        $existing = DB::getSchemaBuilder()->getColumnListing('gates_nominations');
        $extended = [
            'nominee_state'       => trim($data['nominee_state']       ?? ''),
            'nominee_lga'         => trim($data['nominee_lga']         ?? ''),
            'nominee_org'         => mb_substr(trim($data['nominee_org'] ?? ''), 0, 200),
            'nominee_phone'       => (string) $nomineePhone, // E.164 or '' — validated above
            'nominee_photo_path'  => trim($data['nominee_photo_path']  ?? ''),
            'reference_url'       => trim($data['reference_url']       ?? ''),
            'reference_url_2'     => trim($data['reference_url_2']     ?? ''),
            'reference_url_3'     => trim($data['reference_url_3']     ?? ''),
            'nominator_phone'     => trim($data['nominator_phone']     ?? ''),
            'nominator_location'  => trim($data['nominator_location']  ?? ''),
            'nominator_country'   => strtoupper($data['nominator_country'] ?? ''),
            'nominator_state'     => trim($data['nominator_state']     ?? ''),
            'nominator_lga'       => trim($data['nominator_lga']       ?? ''),
            'nominator_age_range' => mb_substr(trim($data['nominator_age_range'] ?? ''), 0, 20),
        ];
        foreach ($extended as $col => $val) {
            if (in_array($col, $existing, true)) {
                $row[$col] = $val;
            }
        }

        // Device-fingerprint dedupe — one nomination per (device, nominee) within a
        // cycle, so a single device can't repeatedly nominate the same person to
        // inflate their count or the "different locations" eligibility tally.
        $deviceFp = trim((string)($data['device_fp'] ?? ''));
        if ($deviceFp !== '' && in_array('device_fp', $existing, true)) {
            $fpHash = hash('sha256', $deviceFp);
            $dup = DB::table('gates_nominations')
                ->where('cycle_id', $cycle->id)
                ->where('device_fp', $fpHash)
                ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [mb_strtolower(trim((string)$data['nominee_name']))])
                ->exists();
            if ($dup) {
                throw new \RuntimeException('You have already nominated this person from this device. Each device can nominate a person once per cycle.');
            }
            $row['device_fp'] = $fpHash;
        }

        $id = DB::table('gates_nominations')->insertGetId($row);

        // Enterprise reference (AGN-YYYY-XXXXXX-C) — persisted for lookups and
        // shown everywhere a human sees the nomination. Display metadata only:
        // a failure here must never fail the submission itself.
        if (in_array('reference', $existing, true)) {
            try {
                DB::table('gates_nominations')->where('id', $id)
                    ->update(['reference' => \AfricaGates\Support\Reference::nomination((int) $id, (int) $cycle->year)]);
            } catch (\Throwable) {}
        }
        return $id;
    }

    /**
     * Admin-toggleable eligibility rule: a nominee must have at least N nominations
     * from DIFFERENT locations to be "considered". Off by default; the admin sets
     * the toggle + threshold in Settings.
     *
     * @return array{enabled:bool, min:int}
     */
    public function eligibilityRule(): array
    {
        $enabled = false;
        $min = 5;
        try {
            $enabled = (string) DB::table('gates_settings')->where('key_name', 'nomination_eligibility_enabled')->value('value') === '1';
            $m = DB::table('gates_settings')->where('key_name', 'nomination_min_locations')->value('value');
            if ($m !== null && (int) $m > 0) $min = (int) $m;
        } catch (\Throwable $e) {}
        return ['enabled' => $enabled, 'min' => $min];
    }

    /**
     * Distinct nominator locations + eligibility for a nominee (matched by normalized
     * name) within a cycle. "Different locations" = distinct (country, state) of the
     * nominators. When the rule is disabled, everyone is eligible.
     *
     * @return array{total:int, distinct_locations:int, min:int, enabled:bool, eligible:bool}
     */
    public function nomineeEligibility(string $nomineeName, int $cycleId): array
    {
        $rule = $this->eligibilityRule();
        $name = mb_strtolower(trim($nomineeName));
        $total = 0;
        $locs = [];
        // Can we actually evaluate "different locations"? Only if the nominator
        // location columns exist. If not, the rule can't be enforced — fail OPEN
        // (never block approvals on a schema gap).
        $schema = DB::getSchemaBuilder();
        $evaluable = $schema->hasColumn('gates_nominations', 'nominator_country') || $schema->hasColumn('gates_nominations', 'nominator_state');
        try {
            if ($evaluable) {
                $rows = DB::table('gates_nominations')
                    ->where('cycle_id', $cycleId)
                    ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [$name])
                    ->get(['nominator_country', 'nominator_state']);
                $total = count($rows);
                foreach ($rows as $r) {
                    $key = strtoupper(trim((string) ($r->nominator_country ?? ''))) . '|' . mb_strtolower(trim((string) ($r->nominator_state ?? '')));
                    if (trim($key, '|') !== '') $locs[$key] = true;
                }
            } else {
                $total = (int) DB::table('gates_nominations')->where('cycle_id', $cycleId)->whereRaw('LOWER(TRIM(nominee_name)) = ?', [$name])->count();
            }
        } catch (\Throwable $e) {
            $evaluable = false;
            $total = (int) DB::table('gates_nominations')->where('cycle_id', $cycleId)->whereRaw('LOWER(TRIM(nominee_name)) = ?', [$name])->count();
        }
        $distinct = count($locs);
        return [
            'total'              => $total,
            'distinct_locations' => $distinct,
            'min'                => $rule['min'],
            'enabled'            => $rule['enabled'] && $evaluable,                       // can't enforce without location data
            'eligible'           => !$rule['enabled'] || !$evaluable || $distinct >= $rule['min'],
        ];
    }
}
