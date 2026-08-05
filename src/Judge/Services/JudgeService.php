<?php
declare(strict_types=1);

namespace AfricaGates\Judge\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

class JudgeService
{
    /** Find judge by email (case-insensitive). */
    public function findByEmail(string $email): ?object
    {
        $email = strtolower(trim($email));
        $row = DB::table('gates_judges')->where('email', $email)->where('is_active', 1)->first();
        return $row ?: null;
    }

    public function findById(int $id): ?object
    {
        $row = DB::table('gates_judges')->where('id', $id)->first();
        return $row ?: null;
    }

    /**
     * Public-facing roster for the "Meet the Judges" page: active judges only,
     * with display fields (never email or programme assignments). Judges with a
     * photo are surfaced first, then alphabetical — so the showcase always leads
     * with complete cards.
     */
    public function publicRoster(): array
    {
        $judges = DB::table('gates_judges')
            ->where('is_active', 1)
            ->orderByRaw("CASE WHEN avatar_path IS NULL OR avatar_path = '' THEN 1 ELSE 0 END")
            ->orderBy('name')
            ->get();
        $progs = DB::table('gates_award_programmes')->get()->keyBy('id'); // resolved once
        return $judges->map(fn ($r) => $this->shapePublic($r, $progs))->all();
    }

    /** One judge's public profile (for /judges/{slug}); null if missing or inactive. */
    public function publicJudge(int $id): ?array
    {
        $r = DB::table('gates_judges')->where('id', $id)->where('is_active', 1)->first();
        if (!$r) return null;
        $progs = DB::table('gates_award_programmes')->get()->keyBy('id');
        return $this->shapePublic($r, $progs, true);
    }

    /** Public, non-sensitive shape of a judge row (never email or assignments JSON). */
    private function shapePublic(object $r, $progs, bool $rich = false): array
    {
        $ids = $r->programme_ids ? (json_decode((string) $r->programme_ids, true) ?: []) : [];
        $jp = [];
        foreach ($ids as $pid) {
            $p = $progs[$pid] ?? null;
            if ($p && (int) $p->is_active === 1) {
                $jp[] = $rich
                    ? ['slug' => $p->slug, 'title' => $p->title, 'icon_emoji' => $p->icon_emoji, 'subtitle' => $p->subtitle]
                    : ['slug' => $p->slug, 'title' => $p->title];
            }
        }
        return [
            'id'           => (int) $r->id,
            'name'         => (string) $r->name,
            'title'        => (string) ($r->title ?? ''),
            'organisation' => (string) ($r->organisation ?? ''),
            'bio'          => (string) ($r->bio ?? ''),
            'avatar_path'  => (string) ($r->avatar_path ?? ''),
            'country_code' => strtoupper((string) ($r->country_code ?? '')),
            'programmes'   => $jp,
            'slug'         => $this->judgeSlug((int) $r->id, (string) $r->name),
        ];
    }

    /** Canonical judge slug: {id}-{name}. */
    public function judgeSlug(int $id, string $name): string
    {
        $s = \AfricaGates\Support\Slug::make($name, 60);
        return $id . ($s !== '' ? '-' . $s : '');
    }

    /** Programmes this judge is assigned to. */
    public function programmes(int $judgeId): array
    {
        $j = $this->findById($judgeId);
        if (!$j) return [];
        $ids = $j->programme_ids ? (json_decode((string)$j->programme_ids, true) ?: []) : [];
        if (!$ids) return [];
        return DB::table('gates_award_programmes')->whereIn('id', $ids)->orderBy('sort_order')
            ->get()->map(fn($r) => (array)$r)->all();
    }

    /** All criteria (currently global; programme-specific override supported). */
    public function criteria(int $programmeId): array
    {
        $rows = DB::table('gates_judge_criteria')
            ->where('is_active', 1)
            ->where(function ($q) use ($programmeId) {
                $q->where('programme_id', $programmeId)->orWhereNull('programme_id');
            })
            ->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        // Prefer programme-specific over global if both exist for same slug
        $bySlug = [];
        foreach ($rows as $r) {
            if (!isset($bySlug[$r['slug']]) || $r['programme_id']) $bySlug[$r['slug']] = $r;
        }
        return array_values($bySlug);
    }

    /** Nominees in this programme's current cycle, with this judge's existing scores. */
    public function ballot(int $judgeId, int $programmeId): array
    {
        $cycle = DB::table('gates_award_cycles')->where('programme_id', $programmeId)->orderByDesc('year')->first();
        if (!$cycle) return ['cycle' => null, 'categories' => []];

        $cats = DB::table('gates_award_categories')->where('cycle_id', $cycle->id)
            ->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        $catIds = array_column($cats, 'id');
        if (!$catIds) return ['cycle' => (array)$cycle, 'categories' => []];

        $nq = DB::table('gates_nominees')->whereIn('category_id', $catIds)
            ->whereIn('status', ['approved','winner','runner_up']);
        \AfricaGates\Services\MergeService::notMerged($nq);       // tombstones drop off the ballot
        // NOT orderByDesc('vote_count'), which is what this used to be.
        //
        // The ballot prints "judge on documented impact, not popularity" and then walked
        // the panel through the nominees in exactly popularity order, most-voted first.
        // The number itself was never rendered, so it looked clean; the ordering carried
        // it anyway, and position is one of the better-evidenced anchors there is. Every
        // judge saw the SAME order, so the bias pointed the same way for the whole panel
        // and landed on the 55% that exists to be independent of the 45%.
        //
        // Shuffled per judge instead, and deterministically: one judge gets the same
        // order every time they open the ballot — a list that reshuffles between page
        // loads is how somebody scores the wrong nominee — while different judges get
        // different orders, so position bias cancels across a panel instead of
        // accumulating. Seeded on judge + cycle, so it is reproducible months later if a
        // result is ever questioned.
        $nominees = $nq->get()->map(fn($r) => (array)$r)->all();
        $seat = static fn(array $n): string =>
            hash('sha256', $judgeId . ':' . $cycle->id . ':' . ($n['id'] ?? 0));
        usort($nominees, static fn(array $a, array $b): int => $seat($a) <=> $seat($b));

        $criteria = $this->criteria($programmeId);
        $criteriaIds = array_column($criteria, 'id');

        $scores = $criteriaIds ? DB::table('gates_judge_criteria_scores')
            ->where('judge_id', $judgeId)
            ->whereIn('criterion_id', $criteriaIds)
            ->get() : collect([]);
        $byNominee = [];
        foreach ($scores as $s) {
            $byNominee[$s->nominee_id][$s->criterion_id] = (int)$s->score;
        }

        $notes = DB::table('gates_judge_notes')->where('judge_id', $judgeId)
            ->get()->keyBy('nominee_id');

        $byCategory = [];
        foreach ($cats as $c) {
            $byCategory[$c['id']] = ['category' => $c, 'nominees' => []];
        }
        // The dossier, in one query for the whole ballot rather than one per nominee on
        // the screen a judge keeps open for hours. See EvidenceService.
        $dossiers = (new \AfricaGates\Services\EvidenceService())
            ->forBallot(array_column($nominees, 'id'));

        foreach ($nominees as $n) {
            // Popularity is stripped at the boundary, not merely left unrendered. The row
            // arrives from `select *` carrying vote_count and organic_vote_count, and the
            // template not using them today is a property of today's template — one
            // `{{ n.vote_count }}` added in good faith by somebody building a nicer card
            // would put the community signal back inside the expert one. It cannot be
            // printed if it is not there.
            foreach (\AfricaGates\Services\EvidenceService::FORBIDDEN_FIELDS as $banned) {
                unset($n[$banned]);
            }

            $n['scores'] = $byNominee[$n['id']] ?? [];
            $n['notes']  = isset($notes[$n['id']]) ? (string)$notes[$n['id']]->notes : '';
            $n['avg']    = $this->avgFromScores($n['scores'], $criteria);
            $n['complete'] = count($n['scores']) === count($criteria);
            $n['evidence'] = $dossiers[(int) $n['id']] ?? ['items' => [], 'interviews' => [], 'coverage' => null];
            $byCategory[$n['category_id']]['nominees'][] = $n;
        }

        // Whether this judge can actually write scores now — the same gate
        // saveScore() enforces server-side. The template uses it to render a
        // read-only ballot with a clear reason instead of live sliders that
        // would only fail on submit.
        $coi = $this->hasConflict($judgeId, $programmeId);
        $judgingOpen = ($cycle->status === 'judging') && !$coi;
        $lockReason = $coi
            ? 'You have declared a conflict of interest for this programme, so scoring is disabled.'
            : (($cycle->status !== 'judging')
                ? 'Scoring is closed — this cycle is not in the judging phase yet.'
                : '');

        return [
            'cycle' => (array)$cycle,
            'criteria' => $criteria,
            'categories' => array_values($byCategory),
            'judging_open' => $judgingOpen,
            'coi' => $coi,
            'lock_reason' => $lockReason,
            'progress' => [
                'total' => count($nominees),
                'scored' => count(array_filter($nominees, fn($n) => count($byNominee[$n['id']] ?? []) === count($criteria))),
            ],
        ];
    }

    /** Save a scoring update for one nominee. */
    /** True if the judge is assigned to the programme that owns this nominee. */
    public function canScore(int $judgeId, int $nomineeId): bool
    {
        $j = $this->findById($judgeId);
        if (!$j || !(int)$j->is_active) return false;
        $ids = $j->programme_ids ? (json_decode((string)$j->programme_ids, true) ?: []) : [];
        if (!$ids) return false;
        $progId = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('n.id', $nomineeId)
            ->value('cy.programme_id');
        return $progId !== null && in_array((int)$progId, array_map('intval', $ids), true);
    }

    public function saveScore(int $judgeId, int $nomineeId, array $criteriaScores, ?string $notes = null): array
    {
        $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();
        if (!$nominee) return ['ok' => false, 'message' => 'Nominee not found'];
        // Only nominees actually on the ballot are scoreable — a crafted POST must
        // not let a judge pre-score a pending/rejected nominee, or a merged-away
        // tombstone, before/after it leaves the ballot.
        if (!in_array($nominee->status, ['approved', 'winner', 'runner_up'], true) || !empty($nominee->merged_into ?? null)) {
            return ['ok' => false, 'message' => 'This nominee is not open for scoring.'];
        }
        // Authorisation: a judge may only score nominees in a programme they're
        // assigned to — prevents cross-panel score tampering via crafted POSTs.
        if (!$this->canScore($judgeId, $nomineeId)) {
            return ['ok' => false, 'message' => 'You are not assigned to this nominee\'s programme.'];
        }
        $catId = (int)$nominee->category_id;

        // Judging window: scores are writable only while this nominee's cycle is in
        // the 'judging' phase — locked before (nominations/voting) and after (results).
        $cy = DB::table('gates_award_cycles AS cy')
            ->join('gates_award_categories AS c', 'c.cycle_id', '=', 'cy.id')
            ->where('c.id', $catId)->select('cy.status', 'cy.programme_id')->first();
        if (!$cy || $cy->status !== 'judging') {
            return ['ok' => false, 'message' => 'Scoring is closed — this cycle is not in the judging phase.'];
        }
        // Conflict of interest: a judge who recused from this programme cannot score it.
        if ($this->hasConflict($judgeId, (int)$cy->programme_id)) {
            return ['ok' => false, 'message' => 'You have declared a conflict of interest for this programme.'];
        }

        // Only accept criteria belonging to THIS programme's rubric — silently
        // ignore unknown/injected criterion ids from a crafted request.
        $allowed = array_map('intval', array_column($this->criteria((int)$cy->programme_id), 'id'));
        $valid = 0;
        foreach ($criteriaScores as $criterionId => $score) {
            $cid = (int)$criterionId;
            if (!in_array($cid, $allowed, true)) continue;
            $score = max(0, min(10, (int)$score));
            DB::table('gates_judge_criteria_scores')->updateOrInsert(
                ['judge_id' => $judgeId, 'nominee_id' => $nomineeId, 'criterion_id' => $cid],
                [
                    'category_id' => $catId,
                    'score' => $score,
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]
            );
            $valid++;
        }
        if ($notes !== null) {
            DB::table('gates_judge_notes')->updateOrInsert(
                ['judge_id' => $judgeId, 'nominee_id' => $nomineeId],
                [
                    'notes' => mb_substr($notes, 0, 5000),
                    'submitted_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]
            );
        }
        return ['ok' => true, 'saved' => $valid];
    }

    /** Record a programme-level conflict-of-interest recusal for a judge. */
    public function declareConflict(int $judgeId, int $programmeId, ?string $reason = null): void
    {
        DB::table('gates_judge_coi')->updateOrInsert(
            ['judge_id' => $judgeId, 'programme_id' => $programmeId],
            [
                'reason'     => $reason !== null && $reason !== '' ? mb_substr(trim($reason), 0, 500) : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]
        );
    }

    /** Withdraw a previously-declared conflict of interest (a judge may have declared in error). */
    public function withdrawConflict(int $judgeId, int $programmeId): void
    {
        DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->delete();
    }

    /** True if the judge has declared a conflict of interest for the programme. */
    public function hasConflict(int $judgeId, int $programmeId): bool
    {
        return DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->exists();
    }

    /** The COI recusal row for a judge+programme, or null. */
    public function coiFor(int $judgeId, int $programmeId): ?object
    {
        return DB::table('gates_judge_coi')
            ->where('judge_id', $judgeId)->where('programme_id', $programmeId)->first() ?: null;
    }

    /** All COI recusals for a judge, with the programme title, newest first. */
    public function conflicts(int $judgeId): array
    {
        return DB::table('gates_judge_coi as coi')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'coi.programme_id')
            ->where('coi.judge_id', $judgeId)
            ->orderByDesc('coi.created_at')
            ->select('coi.programme_id', 'p.title as programme', 'coi.reason', 'coi.created_at')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    // ── Judge home / dashboard ──────────────────────────────────────────────

    /**
     * Everything the judge home needs in one payload: a cross-programme overview,
     * per-programme detail (deadline, COI, progress), an auditable activity trail,
     * a self-audit scoring profile, and the published scoring criteria. Safe and
     * fully zeroed when the judge has no assignments.
     */
    public function dashboard(int $judgeId): array
    {
        $progs = $this->programmes($judgeId);

        $programmes = [];
        $total = 0; $scored = 0; $open = 0;
        foreach ($progs as $p) {
            $b        = $this->ballot($judgeId, (int) $p['id']);
            $cycle    = is_array($b['cycle'] ?? null) ? $b['cycle'] : null;
            $progress = $b['progress'] ?? ['total' => 0, 'scored' => 0];
            $coi      = $this->coiFor($judgeId, (int) $p['id']);
            $status   = $cycle['status'] ?? null;
            // The judging deadline is when results are published; fall back to the
            // close of voting if no results date is set.
            $deadline = $cycle['results_date'] ?? ($cycle['voting_close'] ?? null);
            $judgingOpen = $status === 'judging' && !$coi;

            $total  += (int) $progress['total'];
            $scored += (int) $progress['scored'];
            if ($judgingOpen) $open++;

            $programmes[] = [
                'programme'    => $p,
                'cycle'        => $cycle,
                'progress'     => $progress,
                'categories'   => count($b['categories'] ?? []),
                'coi'          => $coi ? (array) $coi : null,
                'status'       => $status,
                'judging_open' => $judgingOpen,
                'deadline'     => $deadline,
                'days_left'    => $this->daysUntil($deadline),
            ];
        }

        return [
            'overview' => [
                'programmes' => count($progs),
                'total'      => $total,
                'scored'     => $scored,
                'remaining'  => max(0, $total - $scored),
                'pct'        => $total > 0 ? (int) round($scored / $total * 100) : 0,
                'open'       => $open,
            ],
            'programmes' => $programmes,
            'activity'   => $this->activity($judgeId, 10),
            'summary'    => $this->scoringSummary($judgeId),
            'conflicts'  => $this->conflicts($judgeId),
            'criteria'   => $progs ? $this->criteria((int) $progs[0]['id']) : [],
        ];
    }

    /**
     * An auditable trail of this judge's scoring: one entry per nominee touched,
     * with the weighted average given, how many criteria were marked, and when it
     * was last updated — newest first.
     */
    public function activity(int $judgeId, int $limit = 12): array
    {
        $byNom = [];
        foreach ($this->rawScores($judgeId) as $r) {
            $k = (int) $r->nominee_id;
            $byNom[$k] ??= [
                'nominee_id' => $k, 'nominee' => $r->nominee, 'category' => $r->category,
                'programme' => $r->programme, 'ws' => 0.0, 'wt' => 0.0, 'count' => 0, 'last_at' => $r->updated_at,
            ];
            $byNom[$k]['ws']    += $r->score * $r->weight;
            $byNom[$k]['wt']    += $r->weight;
            $byNom[$k]['count'] += 1;
            if ((string) $r->updated_at > (string) $byNom[$k]['last_at']) {
                $byNom[$k]['last_at'] = $r->updated_at;
            }
        }

        $out = [];
        foreach ($byNom as $v) {
            $out[] = [
                'nominee_id'      => $v['nominee_id'],
                'nominee'         => $v['nominee'],
                'category'        => $v['category'],
                'programme'       => $v['programme'],
                'criteria_scored' => $v['count'],
                'avg'             => $v['wt'] > 0 ? round($v['ws'] / $v['wt'], 1) : 0.0,
                'last_at'         => $v['last_at'],
            ];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));
        return array_slice($out, 0, $limit);
    }

    /**
     * A judge's self-audit profile: how many marks given, their average, the
     * spread, and a 0–10 distribution. Surfacing this lets a judge see leniency
     * or harshness bias and whether they use the full scale.
     */
    public function scoringSummary(int $judgeId): array
    {
        $scores = [];
        foreach ($this->rawScores($judgeId) as $r) { $scores[] = (int) $r->score; }
        $n = count($scores);

        $bands = ['low' => 0, 'mid' => 0, 'good' => 0, 'high' => 0]; // 0-3 / 4-6 / 7-8 / 9-10
        foreach ($scores as $s) {
            if ($s <= 3)      $bands['low']++;
            elseif ($s <= 6)  $bands['mid']++;
            elseif ($s <= 8)  $bands['good']++;
            else              $bands['high']++;
        }

        $notes = DB::table('gates_judge_notes')->where('judge_id', $judgeId)
            ->whereNotNull('notes')->where('notes', '!=', '')->count();

        return [
            'total_marks'   => $n,
            'avg'           => $n > 0 ? round(array_sum($scores) / $n, 1) : null,
            'min'           => $n > 0 ? min($scores) : null,
            'max'           => $n > 0 ? max($scores) : null,
            'range_used'    => $n > 0 ? max($scores) - min($scores) : 0,
            'bands'         => $bands,
            'notes_written' => $notes,
        ];
    }

    /** Every score this judge has given, joined to criterion weight + names. */
    private function rawScores(int $judgeId): \Illuminate\Support\Collection
    {
        return DB::table('gates_judge_criteria_scores as s')
            ->leftJoin('gates_judge_criteria as cr', 'cr.id', '=', 's.criterion_id')
            ->leftJoin('gates_nominees as n', 'n.id', '=', 's.nominee_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 's.category_id')
            ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('s.judge_id', $judgeId)
            ->select(
                's.nominee_id', 's.score', 's.updated_at',
                DB::raw('COALESCE(cr.weight, 25) as weight'),
                'n.name as nominee', 'c.title as category', 'p.title as programme'
            )->get();
    }

    /** Whole days from now until $dt (negative if past), or null. */
    private function daysUntil(?string $dt): ?int
    {
        if (!$dt) return null;
        $ts = strtotime($dt);
        return $ts ? (int) ceil(($ts - time()) / 86400) : null;
    }

    private function avgFromScores(array $scores, array $criteria): float
    {
        if (!$scores || !$criteria) return 0;
        $weights = array_column($criteria, 'weight', 'id');
        $weightedSum = 0;
        $weightTotal = 0;
        foreach ($scores as $cid => $s) {
            $w = $weights[$cid] ?? 25;
            $weightedSum += $s * $w;
            $weightTotal += $w;
        }
        return $weightTotal > 0 ? round($weightedSum / $weightTotal, 1) : 0;
    }
}
