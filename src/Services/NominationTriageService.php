<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Review-at-scale triage for nominations. Everything here is ADVISORY — it
 * exists so an operator can review a thousand nominations quickly WITHOUT
 * skipping process: each nomination still gets a human decision through the
 * audited approve/reject path.
 *
 * Two independent layers:
 *   • duplicatesFor()  — deterministic SQL: other nominations for the same
 *     person in the same cycle, and whether the person is already a live
 *     nominee. Always available, no AI required.
 *   • generate()       — optional AI layer (quality score 0–100 + a
 *     2-sentence reviewer summary) stored in gates_nomination_insights;
 *     silently skipped when no provider is configured.
 *
 * Queue job JOB_TRIAGE runs generate() off the request path; an hourly cron
 * backfill catches nominations submitted while AI was unconfigured.
 */
class NominationTriageService
{
    public const JOB_TRIAGE = 'ai.nomination_triage';

    /**
     * Normalise a person name for duplicate matching: lowercase, ALL
     * whitespace removed — mirrors the SQL side (LOWER + REPLACE(' ','')) so
     * "ADA   obi " and "Ada Obi" resolve to the same key on both engines.
     */
    private static function norm(string $name): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtolower(trim($name)));
    }

    /**
     * Deterministic duplicate hints for a nomination.
     *
     * Three signals:
     *   same_cycle    — EXACT name matches (case/whitespace-insensitive) in
     *                   this cycle.
     *   similar       — near-miss spellings in this cycle (edit distance ≤ 2
     *                   on the normalised name, e.g. Muhammed/Mohammed), so a
     *                   typo can't split one person into two review threads.
     *   live_nominee  — the person is already a live nominee.
     *
     * @return array{
     *   same_cycle: list<array{id:int,status:string,nominator_email:string,created_at:string}>,
     *   similar: list<array{id:int,name:string,status:string}>,
     *   live_nominee: array{id:int,name:string,status:string}|null
     * }
     */
    public static function duplicatesFor(object|array $nom): array
    {
        $nom = (object) $nom;
        $norm = self::norm((string) $nom->nominee_name);
        $out = ['same_cycle' => [], 'similar' => [], 'live_nominee' => null, 'live_nominees' => [], 'merge_candidates' => null];
        if ($norm === '') return $out;
        try {
            $rows = DB::table('gates_nominations')
                ->where('cycle_id', (int) $nom->cycle_id)
                ->where('id', '!=', (int) $nom->id)
                ->whereRaw("LOWER(REPLACE(nominee_name, ' ', '')) = ?", [$norm])
                ->orderBy('id')
                ->limit(25)
                ->get(['id', 'status', 'nominator_email', 'created_at']);
            $out['same_cycle'] = array_map(static fn($r) => [
                'id' => (int) $r->id,
                'status' => (string) $r->status,
                'nominator_email' => (string) $r->nominator_email,
                'created_at' => (string) $r->created_at,
            ], $rows->all());
        } catch (\Throwable) {}
        try {
            // ALL live nominees for this person, strongest first — when there are
            // 2+, they are vote-splitting duplicates the reviewer can merge right
            // from the desk (see _review_body.twig). Tombstones excluded.
            $lq = DB::table('gates_nominees')
                ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [$norm])
                ->whereIn('status', ['approved', 'winner', 'runner_up'])
                ->orderByDesc('vote_count');
            \AfricaGates\Services\MergeService::notMerged($lq);
            $lives = $lq->limit(10)->get(['id', 'name', 'status', 'vote_count', 'category_id']);
            $out['live_nominees'] = array_map(static fn($r) => [
                'id' => (int) $r->id, 'name' => (string) $r->name, 'status' => (string) $r->status,
                'vote_count' => (int) $r->vote_count, 'category_id' => (int) $r->category_id,
            ], $lives->all());
            $out['live_nominee'] = $out['live_nominees'][0] ?? null;   // back-compat (first/strongest)

            // If 2+ live nominees for this person sit in ONE category, they are
            // vote-splitting duplicates the reviewer can merge in a single click
            // (MergeService only folds within a category). Pick the largest such
            // group; survivor = its strongest (highest vote_count, already first).
            $byCat = [];
            foreach ($out['live_nominees'] as $ln) { $byCat[$ln['category_id']][] = $ln; }
            $best = [];
            foreach ($byCat as $grp) { if (count($grp) > count($best)) $best = $grp; }
            if (count($best) >= 2) {
                $ids = array_map(static fn($g) => (int) $g['id'], $best);
                $out['merge_candidates'] = [
                    'keep_id'     => $ids[0],
                    'merge_ids'   => array_slice($ids, 1),
                    'names'       => array_map(static fn($g) => (string) $g['name'], $best),
                    'category_id' => (int) $best[0]['category_id'],
                    'count'       => count($best),
                ];
            }
        } catch (\Throwable) {}
        // Fuzzy near-misses: bounded scan of this cycle's names, edit distance
        // ≤ 2 on the normalised form (min 6 chars — short names are too noisy).
        if (strlen($norm) >= 6) {
            try {
                $exactIds = array_column($out['same_cycle'], 'id');
                $candidates = DB::table('gates_nominations')
                    ->where('cycle_id', (int) $nom->cycle_id)
                    ->where('id', '!=', (int) $nom->id)
                    ->limit(2000)
                    ->get(['id', 'nominee_name', 'status']);
                foreach ($candidates as $c) {
                    if (in_array((int) $c->id, $exactIds, true)) continue;
                    $cn = self::norm((string) $c->nominee_name);
                    if ($cn === '' || $cn === $norm || abs(strlen($cn) - strlen($norm)) > 2) continue;
                    // Small edit distance AND high character similarity, so a
                    // distance-2 pair of DIFFERENT names isn't flagged as a dup.
                    similar_text($norm, $cn, $pct);
                    if (levenshtein($norm, $cn) <= 2 && $pct >= 80.0) {
                        $out['similar'][] = ['id' => (int) $c->id, 'name' => (string) $c->nominee_name, 'status' => (string) $c->status];
                        if (count($out['similar']) >= 10) break;
                    }
                }
            } catch (\Throwable) {}
        }
        return $out;
    }

    /** Stored insight row (quality_score, summary, duplicates_json…) or null. */
    public static function insight(int $nominationId): ?array
    {
        try {
            $r = DB::table('gates_nomination_insights')->where('nomination_id', $nominationId)->first();
            return $r ? (array) $r : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build + store the insight row for one nomination. Duplicates are always
     * computed; the AI score/summary only when a provider is configured. Safe
     * to re-run (upsert). Returns the stored row, or null if the nomination
     * is gone.
     */
    public static function generate(int $nominationId, ?AiService $ai = null): ?array
    {
        $nom = DB::table('gates_nominations')->where('id', $nominationId)->first();
        if (!$nom) return null;

        $dupes = self::duplicatesFor($nom);
        $score = null;
        $summary = null;
        $model = null;

        $ai ??= AiService::boot();
        if ($ai->configured()) {
            $system = 'You triage award nominations for Africa GATES reviewers. Reply ONLY with JSON: '
                . '{"score": <0-100 integer — how complete, specific and credible the nomination reads (NOT whether the person deserves to win)>, '
                . '"summary": "<2 sentences max: who is nominated, what the case rests on, and any gap a reviewer should probe>"}. '
                . 'Be strict: vague or generic reasons score low; specific verifiable impact scores high. Advisory only.';
            $user = 'Nominee: ' . $nom->nominee_name
                . ($nom->nominee_org ? ' (' . $nom->nominee_org . ')' : '')
                . ' — ' . ($nom->country_code ?? '') . ' ' . ($nom->nominee_state ?? '') . "\n"
                . 'Reason given: ' . mb_substr((string) ($nom->reason ?? ''), 0, 2500) . "\n"
                . 'Reference links provided: ' . (int) (!empty($nom->reference_url)) + (int) (!empty($nom->reference_url_2)) + (int) (!empty($nom->reference_url_3));
            try {
                $raw = $ai->complete($system, $user, 250, true, 0.1);
                $j = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($j) && isset($j['score'])) {
                    $score = max(0, min(100, (int) $j['score']));
                    $summary = mb_substr(trim((string) ($j['summary'] ?? '')), 0, 600) ?: null;
                    $model = $ai->activeProvider();
                }
            } catch (\Throwable) {
                // AI is optional — duplicates alone still make the row useful.
            }
        }

        $row = [
            'quality_score'   => $score,
            'summary'         => $summary,
            'duplicates_json' => (string) json_encode($dupes, JSON_UNESCAPED_UNICODE),
            'model'           => $model,
            'created_at'      => date('Y-m-d H:i:s'),
        ];
        DB::table('gates_nomination_insights')->updateOrInsert(['nomination_id' => $nominationId], $row);
        return ['nomination_id' => $nominationId] + $row;
    }

    /** Queue triage for a nomination (fire-and-forget from the submit path). */
    public static function enqueue(int $nominationId): void
    {
        try {
            (new QueueService())->push(self::JOB_TRIAGE, ['nomination_id' => $nominationId]);
        } catch (\Throwable) {}
    }

    /**
     * Hourly backfill: queue triage for pending nominations that have no
     * insight row yet (covers pre-feature backlogs and AI-was-off gaps).
     * Returns how many were queued.
     */
    public static function backfill(int $limit = 100): int
    {
        try {
            $ids = DB::table('gates_nominations as n')
                ->leftJoin('gates_nomination_insights as i', 'i.nomination_id', '=', 'n.id')
                ->where('n.status', 'pending')
                ->whereNull('i.nomination_id')
                ->orderBy('n.id')
                ->limit(max(1, $limit))
                ->pluck('n.id');
        } catch (\Throwable) {
            return 0;
        }
        $q = new QueueService();
        $n = 0;
        foreach ($ids as $id) {
            try { $q->push(self::JOB_TRIAGE, ['nomination_id' => (int) $id]); $n++; }
            catch (\Throwable) {}
        }
        return $n;
    }

    // ── Review-desk queue helpers (fair FIFO, deterministic) ───────────────

    /**
     * The pending nomination to review: the OLDEST pending, or — when walking
     * the queue — the oldest pending with id > $afterId. Null = queue empty.
     */
    public static function nextPending(?int $afterId = null): ?object
    {
        $q = DB::table('gates_nominations')->where('status', 'pending')->orderBy('id');
        if ($afterId !== null && $afterId > 0) $q->where('id', '>', $afterId);
        return $q->first();
    }

    /** 1-based position of a pending nomination in the FIFO queue + total. */
    public static function queuePosition(int $nominationId): array
    {
        $total = (int) DB::table('gates_nominations')->where('status', 'pending')->count();
        $before = (int) DB::table('gates_nominations')->where('status', 'pending')->where('id', '<=', $nominationId)->count();
        return ['position' => max(1, $before), 'total' => $total];
    }
}
