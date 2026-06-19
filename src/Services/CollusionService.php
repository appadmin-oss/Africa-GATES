<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Collusion / anomaly detection — a deterministic graph pass over CAST votes
 * that surfaces coordinated voting rings for human review. Three signals:
 *
 *   • shared_device — one device casts votes for the same nominee from several
 *                     distinct accounts (the classic sock-puppet ring).
 *   • shared_ip     — many distinct accounts vote one nominee from a single IP
 *                     (weaker — offices/carriers legitimately share IPs).
 *   • timing_burst  — an abnormal velocity of votes for one nominee in a short
 *                     window (scripted lockstep).
 *
 * Findings are CLUSTER-level (distinct from per-vote gates_fraud_scores) and are
 * advisory: a scan never voids a vote, it only flags rings so an admin can
 * bulk-review and void with an audit trail. Re-scans upsert (preserving review
 * status + first-seen), so the queue reflects the current state without churn.
 *
 * Paid/bonus votes (vote_type != 'standard') are excluded — they carry no
 * device/IP and are independently audited via their donation_id.
 */
class CollusionService
{
    private const MIN_DEVICE_RING  = 3;   // distinct accounts sharing one device → ring
    private const MIN_IP_RING      = 6;   // higher bar: shared IPs are often benign
    private const BURST_WINDOW_MIN = 10;  // minutes
    private const BURST_MIN_VOTES  = 8;   // votes within the window → burst

    public function __construct(private readonly ?LoggerInterface $log = null) {}

    /**
     * Run all detectors (optionally scoped to one category), persist findings,
     * and return a summary. @return array{findings:int, by_kind:array<string,int>}
     */
    public function scan(?int $categoryId = null): array
    {
        $findings = array_merge(
            $this->ringFindings('device_hash', self::MIN_DEVICE_RING, 50, 12, 'shared_device', $categoryId),
            $this->ringFindings('ip_hash', self::MIN_IP_RING, 25, 5, 'shared_ip', $categoryId),
            $this->burstFindings($categoryId),
        );

        $byKind = ['shared_device' => 0, 'shared_ip' => 0, 'timing_burst' => 0];
        foreach ($findings as $f) {
            $this->upsert($f);
            $byKind[$f['kind']]++;
        }

        $this->log?->info('[collusion] scan complete', [
            'findings' => count($findings), 'by_kind' => $byKind, 'category' => $categoryId,
        ]);
        return ['findings' => count($findings), 'by_kind' => $byKind];
    }

    /**
     * Rings sharing a single key (device or IP) for one nominee across several
     * distinct voters. $baseRisk is the floor; +$perExtra per voter beyond the
     * minimum, capped at 100.
     */
    private function ringFindings(string $col, int $minVoters, int $baseRisk, int $perExtra, string $kind, ?int $catId): array
    {
        $q = DB::table('gates_votes')
            ->where('vote_type', 'standard')
            ->whereNotNull($col)->where($col, '!=', '');
        if ($catId) $q->where('category_id', $catId);

        $rows = $q->groupBy('nominee_id', 'category_id', $col)
            ->havingRaw('COUNT(DISTINCT voter_email_hash) >= ?', [$minVoters])
            ->select('nominee_id', 'category_id', $col . ' as shared_key',
                DB::raw('COUNT(DISTINCT voter_email_hash) as voters'),
                DB::raw('COUNT(*) as votes'))
            ->get();

        $what = $kind === 'shared_device' ? 'device' : 'IP address';
        $out = [];
        foreach ($rows as $r) {
            $voters = (int) $r->voters;
            $out[] = [
                'kind'            => $kind,
                'nominee_id'      => (int) $r->nominee_id,
                'category_id'     => (int) $r->category_id,
                'shared_key'      => (string) $r->shared_key,
                'vote_count'      => (int) $r->votes,
                'distinct_voters' => $voters,
                'risk_score'      => min(100, $baseRisk + ($voters - $minVoters) * $perExtra),
                'explanation'     => "One {$what} cast {$r->votes} vote(s) from {$voters} different accounts for this nominee.",
            ];
        }
        return $out;
    }

    /**
     * Abnormal vote velocity: a greedy sliding window per nominee. Each detected
     * burst is keyed by its first vote's timestamp so re-scans of the same data
     * upsert the same row rather than duplicating.
     */
    private function burstFindings(?int $catId): array
    {
        $q = DB::table('gates_votes')->where('vote_type', 'standard')->whereNotNull('voted_at');
        if ($catId) $q->where('category_id', $catId);
        $rows = $q->select('nominee_id', 'category_id', 'voted_at')
            ->orderBy('nominee_id')->orderBy('voted_at')->get();

        $byNom = [];
        foreach ($rows as $r) {
            $nid = (int) $r->nominee_id;
            $byNom[$nid]['cat'] = (int) $r->category_id;
            $byNom[$nid]['ts'][] = strtotime((string) $r->voted_at);
        }

        $window = self::BURST_WINDOW_MIN * 60;
        $out = [];
        foreach ($byNom as $nomId => $d) {
            $ts = $d['ts'];
            $n = count($ts);
            $i = 0;
            while ($i < $n) {
                $j = $i;
                while ($j + 1 < $n && $ts[$j + 1] - $ts[$i] <= $window) $j++;
                $cnt = $j - $i + 1;
                if ($cnt >= self::BURST_MIN_VOTES) {
                    $mins = max(1, (int) round(($ts[$j] - $ts[$i]) / 60));
                    $out[] = [
                        'kind'            => 'timing_burst',
                        'nominee_id'      => $nomId,
                        'category_id'     => $d['cat'],
                        'shared_key'      => date('Y-m-d H:i:s', $ts[$i]),
                        'vote_count'      => $cnt,
                        'distinct_voters' => $cnt,
                        'risk_score'      => min(100, 30 + ($cnt - self::BURST_MIN_VOTES) * 5),
                        'explanation'     => "{$cnt} votes for this nominee within {$mins} minute(s) — abnormal velocity.",
                    ];
                    $i = $j + 1; // skip past this burst to avoid overlapping duplicates
                } else {
                    $i++;
                }
            }
        }
        return $out;
    }

    /** Upsert a finding; first_seen + status are preserved across re-scans. */
    private function upsert(array $f): void
    {
        DB::table('gates_collusion_findings')->updateOrInsert(
            ['kind' => $f['kind'], 'nominee_id' => $f['nominee_id'], 'shared_key' => $f['shared_key']],
            [
                'category_id'     => $f['category_id'],
                'vote_count'      => $f['vote_count'],
                'distinct_voters' => $f['distinct_voters'],
                'risk_score'      => $f['risk_score'],
                'explanation'     => $f['explanation'],
                'last_seen'       => Carbon::now()->toDateTimeString(),
            ]
        );
    }

    /** Open findings, richest first, joined to nominee + category names (admin queue). */
    public function openFindings(int $limit = 50): array
    {
        return DB::table('gates_collusion_findings as f')
            ->leftJoin('gates_nominees as n', 'n.id', '=', 'f.nominee_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'f.category_id')
            ->where('f.status', 'open')
            ->orderByDesc('f.risk_score')->orderByDesc('f.last_seen')
            ->limit($limit)
            ->select('f.*', 'n.name as nominee', 'c.title as category')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    /** Counts for the admin fraud panel. */
    public function summary(): array
    {
        return [
            'open'      => (int) DB::table('gates_collusion_findings')->where('status', 'open')->count(),
            'high_risk' => (int) DB::table('gates_collusion_findings')->where('status', 'open')->where('risk_score', '>=', 80)->count(),
            'by_kind'   => DB::table('gates_collusion_findings')->where('status', 'open')
                ->select('kind', DB::raw('COUNT(*) as n'))->groupBy('kind')->pluck('n', 'kind')->toArray(),
        ];
    }
}
