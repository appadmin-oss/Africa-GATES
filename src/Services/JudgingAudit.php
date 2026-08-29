<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Judge\Services\JudgeService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * How one award programme was judged, and by whom — for somebody who has to defend it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS SCOPED TO A PROGRAMME AND NOT A CYCLE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \AfricaGates\Admin\Controllers\IntegrityController} already answers "is this
 * cycle's result sound", which is the question asked in the hours before publishing one.
 *
 * This answers a different one, asked at a different time and usually by somebody else:
 * "how does this award decide, and who has been deciding it". A programme runs for years,
 * its panel turns over, and its rubric changes. A challenge to the Incredible Principal
 * Awards is not a challenge to one cycle — it is a challenge to whether the award means
 * anything — and a screen that can only show one year at a time cannot answer it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TRAIL THAT EXISTED AND NOBODY COULD SEE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_judge_score_log` has recorded every mark and every change to a mark since the
 * day it was added — judge, nominee, criterion, old score, new score, timestamp. Nothing
 * read it. It appeared in `DataRegistry` as a table to export and in no screen anywhere.
 *
 * That is the seventh instance of §17 in this codebase and by some distance the most
 * expensive: it is the audit trail of the process this platform exists to make credible,
 * and the only way to see it was to have a database client and a shell, on a host that
 * has neither. An organiser answering "did anyone change a mark after the panel met"
 * had to say they could not tell.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS EVIDENCE HERE, AND WHAT IS ONLY A QUESTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two of these findings are FACTS: a judge scored in a programme they declared a conflict
 * on, and a mark was changed after the cycle moved to results. Both are records of things
 * that happened, and both are serious.
 *
 * The rest are arithmetic over small samples and are presented as questions. A judge who
 * marks 1.4 points above the panel across eleven scores may be generous, or may have
 * judged eleven strong nominees. {@see JudgeBiasService} makes this point at length and
 * this class does not restate its findings — it links to them. What it adds is the
 * denominator beside every number, so nobody reads "scored 40% of the field" without
 * seeing that the field was five people.
 */
final class JudgingAudit
{
    /**
     * A mark changed this long after it was first set is worth a second look.
     *
     * Not a rule and not a threshold anybody is judged by: scoring is done over days and
     * a judge revisiting their own sheet in the same sitting is ordinary. What is not
     * ordinary is a mark moving weeks later, and the trail can now show which is which.
     */
    private const LATE_CHANGE_HOURS = 48;

    /**
     * Everything known about how one programme judges.
     *
     * @return array{
     *   programme: ?object,
     *   cycles: list<array<string,mixed>>,
     *   judges: list<array<string,mixed>>,
     *   changes: list<array<string,mixed>>,
     *   conflicts: list<array<string,mixed>>,
     *   totals: array<string,int>
     * }
     */
    public static function forProgramme(int $programmeId, int $changeLimit = 200): array
    {
        $programme = DB::table('gates_award_programmes')->where('id', $programmeId)->first();

        $empty = ['programme' => $programme, 'cycles' => [], 'judges' => [], 'changes' => [],
                  'conflicts' => [], 'totals' => self::zeroTotals()];
        if (!$programme) return $empty;

        $cycles = self::cycles($programmeId);
        if ($cycles === []) return $empty;

        $cycleIds  = array_column($cycles, 'id');
        $nominees  = self::nomineesByCycle($cycleIds);
        $scores    = self::scoreRows($cycleIds);

        return [
            'programme' => $programme,
            'cycles'    => self::cycleCoverage($cycles, $nominees, $scores,
                                              self::orientationGaps($cycleIds)),
            'judges'    => self::judgeConduct($programmeId, $scores),
            'changes'   => self::changes($scores, $cycles, $changeLimit),
            'conflicts' => self::conflicts($programmeId, $scores),
            'totals'    => self::totals($cycles, $nominees, $scores),
        ];
    }

    // ══ the process ══════════════════════════════════════════════════════════

    /**
     * Was every nominee actually judged, and by how many people?
     *
     * ── WHY "SCORED" IS COUNTED PER JUDGE AND NOT PER ROW ────────────────────
     *
     * One judge marking four criteria writes four rows. Counting rows would report a
     * nominee seen by one judge as "4 scores" and read as a panel of four — which is the
     * exact fact somebody defending a result is being asked about.
     *
     * @param list<array<string,mixed>> $cycles
     * @param array<int,list<array<string,mixed>>> $nominees
     * @param list<object> $scores
     * @param array<int,int> $orientationGaps
     * @return list<array<string,mixed>>
     */
    private static function cycleCoverage(array $cycles, array $nominees, array $scores,
                                          array $orientationGaps): array
    {
        // judged[cycle][nominee][judge] = true
        $judged = [];
        foreach ($scores as $s) {
            $judged[(int) $s->cycle_id][(int) $s->nominee_id][(int) $s->judge_id] = true;
        }

        $out = [];
        foreach ($cycles as $c) {
            $cid  = (int) $c['id'];
            $rows = $nominees[$cid] ?? [];

            $unjudged = 0;
            $thin     = 0;
            $panels   = [];

            foreach ($rows as $n) {
                $panel = count($judged[$cid][(int) $n['id']] ?? []);
                $panels[] = $panel;
                if ($panel === 0)      $unjudged++;
                elseif ($panel < 2)    $thin++;
            }

            $out[] = $c + [
                'nominees'  => count($rows),
                'unjudged'  => $unjudged,
                'thin'      => $thin,
                // The SMALLEST panel any nominee in this cycle faced. A mean would hide
                // the one person judged by a single panellist inside an average of four,
                // and that person is the whole question.
                'min_panel' => $panels === [] ? 0 : min($panels),
                'max_panel' => $panels === [] ? 0 : max($panels),
                // Nominees judged without the orientation dossier the rest of the field
                // had. See orientationGaps().
                'no_dossier' => $orientationGaps[$cid] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Every change to a mark, newest first, with the cycle's own phase attached.
     *
     * ── THE DISTINCTION THAT MATTERS ─────────────────────────────────────────
     *
     * `old_score` NULL means this was the FIRST mark for that pair — not a change, and
     * not a score of zero. The column was built with that distinction and this is the
     * first screen to honour it: only rows with an old score are changes, and only those
     * are shown. A first mark is the judging; a second mark is the audit.
     *
     * @param list<object> $scores
     * @param list<array<string,mixed>> $cycles
     * @return list<array<string,mixed>>
     */
    private static function changes(array $scores, array $cycles, int $limit): array
    {
        // The log has no cycle on it, so the (judge, nominee, criterion) triples that
        // belong to this programme are collected from the scores and matched.
        $mine = [];
        foreach ($scores as $s) {
            $mine[(int) $s->judge_id . ':' . (int) $s->nominee_id . ':' . (int) $s->criterion_id]
                = ['cycle_id' => (int) $s->cycle_id, 'nominee' => (string) $s->nominee_name,
                   'judge' => (string) $s->judge_name, 'criterion' => (string) $s->criterion_name,
                   'first_at' => (string) $s->created_at];
        }
        if ($mine === []) return [];

        $results = [];
        foreach ($cycles as $c) $results[(int) $c['id']] = (string) ($c['results_date'] ?? '');

        $rows = DB::table('gates_judge_score_log')
            ->whereNotNull('old_score')
            ->whereIn('judge_id', array_values(array_unique(array_map(
                static fn (object $s): int => (int) $s->judge_id, $scores))))
            ->orderByDesc('changed_at')
            ->limit(max(1, $limit) * 4)   // room to drop rows from other programmes
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $key = (int) $r->judge_id . ':' . (int) $r->nominee_id . ':' . (int) $r->criterion_id;
            $ctx = $mine[$key] ?? null;
            if ($ctx === null) continue;   // another programme's row, same judge

            $changedAt = (string) $r->changed_at;
            $resultsAt = $results[$ctx['cycle_id']] ?? '';

            $out[] = [
                'judge'      => $ctx['judge'],
                'nominee'    => $ctx['nominee'],
                'criterion'  => $ctx['criterion'],
                'cycle_id'   => $ctx['cycle_id'],
                'old'        => (int) $r->old_score,
                'new'        => (int) $r->new_score,
                'changed_at' => $changedAt,
                // The two facts that make a change worth reading, computed here rather
                // than left to a template to work out with a date comparison in Twig.
                'after_results' => $resultsAt !== '' && strtotime($changedAt) > strtotime($resultsAt),
                'late'          => self::hoursBetween($ctx['first_at'], $changedAt) > self::LATE_CHANGE_HOURS,
            ];
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    // ══ the judges ═══════════════════════════════════════════════════════════

    /**
     * What each judge on this programme actually did.
     *
     * Volume first, and deliberately: "how much of the field did they see" is the
     * question an audit starts with, and it is a count rather than an inference. The
     * leniency figure is beside it with its own denominator, because a mean gap computed
     * over six scores is not the same claim as one computed over sixty and a column of
     * bare numbers invites the reader to treat them as though they were.
     *
     * @param list<object> $scores
     * @return list<array<string,mixed>>
     */
    private static function judgeConduct(int $programmeId, array $scores): array
    {
        // Panel mean per (nominee, criterion) — the thing each judge's mark is compared
        // against. Computed once here rather than per judge.
        $panel = [];
        foreach ($scores as $s) {
            $panel[(int) $s->nominee_id . ':' . (int) $s->criterion_id][] = (float) $s->score;
        }

        $by = [];
        foreach ($scores as $s) {
            $jid = (int) $s->judge_id;
            $by[$jid] ??= ['judge_id' => $jid, 'judge' => (string) $s->judge_name,
                           'scores' => 0, 'nominees' => [], 'sum' => 0.0, 'gap' => 0.0,
                           'compared' => 0, 'first' => '', 'last' => ''];

            $by[$jid]['scores']++;
            $by[$jid]['nominees'][(int) $s->nominee_id] = true;
            $by[$jid]['sum'] += (float) $s->score;

            // Compared only where somebody else also marked that criterion. A judge who
            // was the sole scorer of a nominee has no panel to lean against, and counting
            // their own mark as the panel would report a gap of exactly zero — which
            // reads as "perfectly average" and means "nobody else looked".
            $peers = $panel[(int) $s->nominee_id . ':' . (int) $s->criterion_id] ?? [];
            if (count($peers) > 1) {
                $others = array_sum($peers) - (float) $s->score;
                $mean   = $others / (count($peers) - 1);
                $by[$jid]['gap'] += (float) $s->score - $mean;
                $by[$jid]['compared']++;
            }

            $at = (string) $s->created_at;
            if ($at !== '') {
                if ($by[$jid]['first'] === '' || $at < $by[$jid]['first']) $by[$jid]['first'] = $at;
                if ($at > $by[$jid]['last']) $by[$jid]['last'] = $at;
            }
        }

        $out = [];
        foreach ($by as $jid => $j) {
            $out[] = [
                'judge_id'    => $jid,
                'judge'       => $j['judge'],
                'scores'      => $j['scores'],
                'nominees'    => count($j['nominees']),
                'mean'        => $j['scores'] > 0 ? round($j['sum'] / $j['scores'], 2) : 0.0,
                // Rounded to one place, because two would imply a precision that a mean
                // over eleven integer marks does not have.
                'lean'        => $j['compared'] > 0 ? round($j['gap'] / $j['compared'], 1) : null,
                // The denominator, carried WITH the number it qualifies rather than in a
                // footnote. A lean with no comparison count is a rumour.
                'compared'    => $j['compared'],
                'first_at'    => $j['first'],
                'last_at'     => $j['last'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['scores'] <=> $a['scores']);

        return $out;
    }

    /**
     * Judges who declared a conflict on this programme and scored in it anyway.
     *
     * ── THIS IS A FACT, NOT A SIGNAL ─────────────────────────────────────────
     *
     * Everything else on this screen is arithmetic that invites a question. This is a
     * record of two things that both happened: a declaration in `gates_judge_coi`, and
     * rows in `gates_judge_criteria_scores` afterwards. It needs no threshold, carries no
     * caveat, and is the single most serious thing an audit of a panel can surface.
     *
     * Nothing was checking it. The declaration was collected and stored and never
     * compared against what the judge then did.
     *
     * @param list<object> $scores
     * @return list<array<string,mixed>>
     */
    private static function conflicts(int $programmeId, array $scores): array
    {
        $declared = DB::table('gates_judge_coi as coi')
            ->leftJoin('gates_judges as j', 'j.id', '=', 'coi.judge_id')
            ->where('coi.programme_id', $programmeId)
            ->select('coi.judge_id', 'coi.reason', 'coi.created_at', 'j.name')
            ->get();
        if ($declared->isEmpty()) return [];

        $scored = [];
        foreach ($scores as $s) {
            $jid = (int) $s->judge_id;
            $scored[$jid] ??= ['n' => 0, 'first' => ''];
            $scored[$jid]['n']++;
            $at = (string) $s->created_at;
            if ($at !== '' && ($scored[$jid]['first'] === '' || $at < $scored[$jid]['first'])) {
                $scored[$jid]['first'] = $at;
            }
        }

        $out = [];
        foreach ($declared as $d) {
            $jid = (int) $d->judge_id;
            if (!isset($scored[$jid])) continue;

            $out[] = [
                'judge_id'    => $jid,
                'judge'       => (string) ($d->name ?? 'Judge #' . $jid),
                'reason'      => trim((string) ($d->reason ?? '')),
                'declared_at' => (string) $d->created_at,
                'scores'      => $scored[$jid]['n'],
                'first_score' => $scored[$jid]['first'],
                // Which came first changes what happened: scoring then declaring is a
                // judge who recused themselves partway, and the marks may need removing.
                // Declaring then scoring is a control that did not hold.
                'declared_first' => $scored[$jid]['first'] !== ''
                                 && strtotime((string) $d->created_at) < strtotime($scored[$jid]['first']),
            ];
        }

        return $out;
    }

    // ══ the reads ════════════════════════════════════════════════════════════

    /** @return list<array<string,mixed>> */
    private static function cycles(int $programmeId): array
    {
        return DB::table('gates_award_cycles')
            ->where('programme_id', $programmeId)
            ->orderByDesc('year')
            ->get()
            ->map(static fn (object $r): array => (array) $r)
            ->all();
    }

    /**
     * @param list<int> $cycleIds
     * @return array<int,list<array<string,mixed>>>
     */
    private static function nomineesByCycle(array $cycleIds): array
    {
        if ($cycleIds === []) return [];

        $q = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->whereIn('c.cycle_id', $cycleIds)
            ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
            ->select('n.id', 'n.name', 'c.cycle_id');
        MergeService::notMerged($q, 'n.merged_into');

        $out = [];
        foreach ($q->get() as $r) {
            $out[(int) $r->cycle_id][] = ['id' => (int) $r->id, 'name' => (string) $r->name];
        }

        return $out;
    }

    /**
     * Every criterion mark in this programme, with the names already attached.
     *
     * The sandbox is excluded through {@see JudgeService::realJudges()} rather than by a
     * flag of this class's own: `DemoSeeder` creates a real judge row with real marks
     * against demo nominees so an operator can walk the portal, and an audit that counted
     * them would report rehearsal marks as evidence about a real panel.
     *
     * @param list<int> $cycleIds
     * @return list<object>
     */
    private static function scoreRows(array $cycleIds): array
    {
        if ($cycleIds === []) return [];

        return DB::table('gates_judge_criteria_scores as s')
            ->join('gates_nominees as n', 'n.id', '=', 's.nominee_id')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->joinSub(JudgeService::realJudges()->select('id', 'name'), 'j',
                      'j.id', '=', 's.judge_id')
            ->leftJoin('gates_judge_criteria as cr', 'cr.id', '=', 's.criterion_id')
            ->whereIn('c.cycle_id', $cycleIds)
            ->select('s.judge_id', 's.nominee_id', 's.criterion_id', 's.score', 's.created_at',
                     'c.cycle_id', 'n.name as nominee_name', 'j.name as judge_name',
                     'cr.label as criterion_name')
            ->get()
            ->all();
    }

    /**
     * Nominees whose orientation dossier never came out, per cycle.
     *
     * ── WHAT THIS TABLE ACTUALLY IS ──────────────────────────────────────────
     *
     * `gates_judge_orientation` is keyed by NOMINEE, not by judge: it is the generated
     * map of what a nominee's dossier evidences and what it merely asserts, which a judge
     * reads before scoring. A `failed` row means a panellist scored that nominee without
     * the orientation every other nominee's judges had.
     *
     * That is a process fact and it belongs in an audit. The `failed` rows and the
     * `error` beside them have been written since the day the table was added and
     * rendered nowhere — §17 names both, and this is the screen they were missing.
     *
     * @param list<int> $cycleIds
     * @return array<int,int> cycle id => how many nominees have no usable orientation
     */
    private static function orientationGaps(array $cycleIds): array
    {
        if ($cycleIds === []) return [];

        try {
            // The LATEST row per nominee decides it: a dossier that failed and was
            // regenerated is not a gap, and taking any row would report it as one.
            $rows = DB::table('gates_judge_orientation as o')
                ->join('gates_nominees as n', 'n.id', '=', 'o.nominee_id')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('c.cycle_id', $cycleIds)
                ->orderBy('o.id')
                ->select('o.nominee_id', 'o.status', 'c.cycle_id')
                ->get();
        } catch (\Throwable) {
            // A deployment that has not run the orientation migration is not a broken
            // audit — it is an audit with one column it cannot fill.
            return [];
        }

        $latest = [];
        foreach ($rows as $r) {
            $latest[(int) $r->nominee_id] = ['cycle' => (int) $r->cycle_id,
                                             'ok' => strtolower(trim((string) $r->status)) === 'ok'];
        }

        $out = [];
        foreach ($latest as $v) {
            if (!$v['ok']) $out[$v['cycle']] = ($out[$v['cycle']] ?? 0) + 1;
        }

        return $out;
    }

    // ══ totals and helpers ═══════════════════════════════════════════════════

    /**
     * @param list<array<string,mixed>> $cycles
     * @param array<int,list<array<string,mixed>>> $nominees
     * @param list<object> $scores
     * @return array<string,int>
     */
    private static function totals(array $cycles, array $nominees, array $scores): array
    {
        $judges   = [];
        $nomJudged = [];
        foreach ($scores as $s) {
            $judges[(int) $s->judge_id] = true;
            $nomJudged[(int) $s->nominee_id] = true;
        }

        $all = 0;
        foreach ($nominees as $rows) $all += count($rows);

        return [
            'cycles'   => count($cycles),
            'nominees' => $all,
            'judged'   => count($nomJudged),
            'unjudged' => max(0, $all - count($nomJudged)),
            'judges'   => count($judges),
            'scores'   => count($scores),
        ];
    }

    /** @return array<string,int> */
    private static function zeroTotals(): array
    {
        return ['cycles' => 0, 'nominees' => 0, 'judged' => 0, 'unjudged' => 0,
                'judges' => 0, 'scores' => 0];
    }

    private static function hoursBetween(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) return 0.0;

        return abs($tb - $ta) / 3600;
    }
}
