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
     * Everything known about how one programme judges.
     *
     * @return array{
     *   programme: ?object,
     *   cycles: list<array<string,mixed>>,
     *   judges: list<array<string,mixed>>,
     *   changes: array{rows:list<array<string,mixed>>, total:int},
     *   conflicts: list<array<string,mixed>>,
     *   criteria: list<array<string,mixed>>,
     *   incomplete: array{pairs:int, rows:list<array<string,mixed>>, required:int, in_play:int,
     *                       never_marked:list<array{label:string, share:float|null}>,
     *                       dead_share:float},
     *   disagreement: list<array<string,mixed>>,
     *   totals: array<string,int>
     * }
     */
    public static function forProgramme(int $programmeId, int $changeLimit = 200): array
    {
        $programme = DB::table('gates_award_programmes')->where('id', $programmeId)->first();

        $empty = ['programme' => $programme, 'cycles' => [], 'judges' => [],
                  'changes' => ['rows' => [], 'total' => 0],
                  'conflicts' => [], 'criteria' => [],
                  'incomplete' => ['pairs' => 0, 'rows' => [], 'required' => 0, 'in_play' => 0,
                                   'never_marked' => [], 'dead_share' => 0.0],
                  'disagreement' => [], 'totals' => self::zeroTotals()];
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
            'changes'   => self::changes($nominees, $cycles, $changeLimit),
            'conflicts' => self::conflicts($programmeId, $scores),
            // ── THE THREE THAT ASK WHETHER THE RUBRIC ITSELF WORKED ──────────
            //
            // Everything above answers "was the process followed". These answer the
            // question a serious challenge actually opens with — "and does following it
            // decide anything?" A criterion nobody varied, a scorecard left half-marked,
            // and the nominees the panel could not agree about are the three places where
            // a result is thinnest, and none of them was visible.
            'criteria'     => self::criteriaBehaviour($programmeId, $scores),
            'incomplete'   => self::incompleteScorecards($programmeId, $scores),
            'disagreement' => self::disagreement($programmeId, $scores),
            'totals'       => self::totals($cycles, $nominees, $scores),
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

            $onList    = 0;
            $offList   = 0;
            $noDossier = 0;
            $gaps      = $orientationGaps[$cid] ?? [];

            foreach ($rows as $n) {
                $panel = count($judged[$cid][(int) $n['id']] ?? []);

                // A nominee the shortlist left off was never the panel's to judge, so
                // counting them as "never scored" reports the system working as a
                // failure. They are counted separately — and only when somebody scored
                // them anyway, which IS a finding.
                if (empty($n['shortlisted'])) {
                    if ($panel > 0) $offList++;
                    continue;
                }

                $onList++;
                $panels[] = $panel;
                if ($panel === 0)      $unjudged++;
                elseif ($panel < 2)    $thin++;
                if (isset($gaps[(int) $n['id']])) $noDossier++;
            }

            $out[] = $c + [
                'nominees'  => $onList,
                'unjudged'  => $unjudged,
                'thin'      => $thin,
                // Marks on somebody the panel was not asked for: wasted panel time, or a
                // shortlist republished after judging began. Never a silent omission —
                // the whole reason the flag rides on the row rather than filtering it.
                'off_list'  => $offList,
                // The SMALLEST panel any nominee in this cycle faced. A mean would hide
                // the one person judged by a single panellist inside an average of four,
                // and that person is the whole question.
                'min_panel' => $panels === [] ? 0 : min($panels),
                'max_panel' => $panels === [] ? 0 : max($panels),
                // Nominees judged without the orientation dossier the rest of the field
                // had. Counted over the shortlist like every other column in this row —
                // see orientationGaps() for why it hands over ids rather than a total.
                'no_dossier' => $noDossier,
            ];
        }

        return $out;
    }

    /**
     * Every change to a mark, newest first, with the cycle's phase attached.
     *
     * ── THE DISTINCTION THAT MATTERS ─────────────────────────────────────────
     *
     * `old_score` NULL means this was the FIRST mark for that pair — not a change, and
     * not a score of zero. The column was built with that distinction and this is the
     * first screen to honour it: a first mark is the judging, a second is the audit.
     *
     * ── FILTERED BY NOMINEE, NOT BY JUDGE ────────────────────────────────────
     *
     * The log carries no cycle, so the rows belonging to this programme have to be
     * recognised some other way. The first cut narrowed by JUDGE and then dropped rows
     * whose (judge, nominee, criterion) triple was not in this programme, fetching four
     * times the limit to leave room for the discards.
     *
     * That was wrong twice. A judge who also sits on two other programmes can fill the
     * window with their changes elsewhere, so this programme's changes fall off the end —
     * an audit silently showing fewer changes than exist, which is the worst thing an
     * audit can do. And a mark that was changed and then DELETED had no surviving triple,
     * so it vanished from the record entirely — exactly the row somebody would most want
     * to see.
     *
     * A nominee belongs to one category, which belongs to one cycle, which belongs to one
     * programme. So `nominee_id IN (…)` is exact: no foreign rows to discard, the limit
     * means what it says, and a change survives its score being deleted.
     *
     * @param array<int,list<array<string,mixed>>> $nominees
     * @param list<array<string,mixed>> $cycles
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    private static function changes(array $nominees, array $cycles, int $limit): array
    {
        // nominee id => [cycle, name], so a row can be placed and named without a join.
        $where = [];
        foreach ($nominees as $cycleId => $rows) {
            foreach ($rows as $n) {
                $where[(int) $n['id']] = ['cycle' => (int) $cycleId, 'name' => (string) $n['name']];
            }
        }
        if ($where === []) return ['rows' => [], 'total' => 0];

        $ids = array_keys($where);

        $base = DB::table('gates_judge_score_log')
            ->whereNotNull('old_score')
            ->whereIn('nominee_id', $ids);

        // Counted before it is limited, so the screen can say "the most recent 200 of
        // 1,412" rather than presenting a truncated list as the whole record.
        $total = (clone $base)->count();

        $rows = $base->orderByDesc('changed_at')->orderByDesc('id')
                     ->limit(max(1, $limit))->get();

        $judges   = self::namesFor('gates_judges', 'name');
        $criteria = self::namesFor('gates_judge_criteria', 'label');

        $results = [];
        foreach ($cycles as $c) $results[(int) $c['id']] = (string) ($c['results_date'] ?? '');

        $out = [];
        foreach ($rows as $r) {
            $n         = $where[(int) $r->nominee_id];
            $changedAt = (string) $r->changed_at;
            $resultsAt = $results[$n['cycle']] ?? '';

            $out[] = [
                'judge'     => $judges[(int) $r->judge_id] ?? ('Judge #' . (int) $r->judge_id),
                'nominee'   => $n['name'],
                'criterion' => $criteria[(int) $r->criterion_id] ?? '',
                'cycle_id'  => $n['cycle'],
                'old'       => (int) $r->old_score,
                'new'       => (int) $r->new_score,
                'changed_at' => $changedAt,
                // Computed here rather than left to a template to work out with a date
                // comparison in Twig.
                'after_results' => $resultsAt !== '' && strtotime($changedAt) > strtotime($resultsAt),
                // AND the date it is after. Carried with the flag because a flag whose
                // threshold is invisible cannot be checked, and this is the one row on
                // that screen that is a record rather than an inference.
                'results_at'    => $resultsAt,
            ];
        }

        return ['rows' => $out, 'total' => $total];
    }

    /**
     * id => display name for one table, read once.
     *
     * @return array<int,string>
     */
    private static function namesFor(string $table, string $column): array
    {
        try {
            $out = [];
            foreach (DB::table($table)->get(['id', $column]) as $r) {
                $out[(int) $r->id] = (string) ($r->$column ?? '');
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
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
                           'compared' => 0, 'first' => '', 'last' => '', 'at' => []];

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

                // ── ONE STAMP PER SCORECARD, NOT PER MARK ────────────────────
                //
                // This collected every criterion row's timestamp, and a scorecard writes
                // all of its criterion rows in one loop off a single Carbon::now() — so
                // most of the gaps between consecutive stamps were exactly zero, and with
                // the shipped four-criterion rubric three out of every four were. The
                // median of that is 0 whatever the judge did, so the column read "0s" and
                // the amber flag fired for every judge on every panel, permanently.
                //
                // An alarm that cannot not fire is not an alarm; it teaches an operator
                // that this column means nothing, which is worse than the column being
                // absent, because the real signal it was built for — a panel that spent
                // four seconds a nominee — now has nowhere to appear.
                //
                // The earliest stamp per NOMINEE is when that judge opened that scorecard,
                // and the gap between consecutive ones is the time they gave it.
                $nid = (int) $s->nominee_id;
                if (!isset($by[$jid]['at'][$nid]) || $at < $by[$jid]['at'][$nid]) {
                    $by[$jid]['at'][$nid] = $at;
                }
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
                // ── HOW LONG THEY SPENT ON EACH ONE ──────────────────────────
                //
                // The MEDIAN gap between one SCORECARD and the next, in seconds. Judges
                // score in sittings across days, so a mean is meaningless — one overnight
                // gap of fourteen hours swamps two hundred real ones — and the span
                // between first and last says nothing about the reading either.
                //
                // The median is the typical time a nominee got, and it is a fact an
                // operator can weigh: a judge who averaged four seconds across a hundred
                // and twenty nominees did not open a dossier, and that is a different
                // objection from any of the arithmetic above it. Null below two
                // scorecards: one has no gap, and reporting 0 would read as instant.
                'median_gap'  => self::medianGap(array_values($j['at'])),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['scores'] <=> $a['scores']);

        return $out;
    }

    /**
     * The median seconds between one scorecard and the next. Null with no gap to take.
     *
     * The caller passes ONE stamp per nominee, never one per criterion row — see the note
     * at the call site for what happened when it did.
     *
     * @param list<string> $stamps
     */
    private static function medianGap(array $stamps): ?int
    {
        if (count($stamps) < 2) return null;

        sort($stamps);
        $gaps = [];
        for ($i = 1, $n = count($stamps); $i < $n; $i++) {
            $a = strtotime($stamps[$i - 1]);
            $b = strtotime($stamps[$i]);
            if ($a === false || $b === false) continue;
            $gaps[] = max(0, $b - $a);
        }
        if ($gaps === []) return null;

        sort($gaps);
        $mid = intdiv(count($gaps), 2);

        return count($gaps) % 2 === 1
            ? (int) $gaps[$mid]
            : (int) round(($gaps[$mid - 1] + $gaps[$mid]) / 2);
    }

    /**
     * What each criterion actually DID — and whether it did anything.
     *
     * ── THE QUESTION NOBODY COULD ASK ────────────────────────────────────────
     *
     * A rubric's weights say what the award claims to value. They do not say what
     * separated the field. A criterion every judge marked 8 out of 10 on, for every
     * nominee, decided nothing at all — and because it still carries its weight, the
     * share of the decision it was given went nowhere. On a five-criterion rubric with an
     * inert 25% criterion, the result was really decided by three criteria and nobody
     * could see it.
     *
     * ── WHY `distinct` AND NOT A VARIANCE ────────────────────────────────────
     *
     * The scale is small integers. A standard deviation over eleven marks on a 1–10 scale
     * invites a precision the data does not carry, and an operator cannot check it. "Every
     * judge used one value" and "judges used four of the ten" are facts a person can act
     * on: the first is a criterion to rewrite, the second is one that is working.
     *
     * The weight share travels WITH the finding rather than in a rubric screen somewhere
     * else, because "this decided nothing" and "this was worth a quarter of the mark" only
     * mean something together.
     *
     * @param list<object> $scores
     * @return list<array<string,mixed>>
     */
    private static function criteriaBehaviour(int $programmeId, array $scores): array
    {
        if ($scores === []) return [];

        $shares = JudgeRubric::shares($programmeId);

        $by = [];
        foreach ($scores as $s) {
            $cid = (int) $s->criterion_id;
            $by[$cid] ??= ['id' => $cid, 'label' => (string) ($s->criterion_name ?: 'Criterion #' . $cid),
                           'marks' => 0, 'sum' => 0.0, 'min' => null, 'max' => null,
                           'values' => [], 'nominees' => []];

            $v = (float) $s->score;
            $by[$cid]['marks']++;
            $by[$cid]['sum'] += $v;
            $by[$cid]['values'][(string) $v] = true;
            $by[$cid]['nominees'][(int) $s->nominee_id] = true;
            $by[$cid]['min'] = $by[$cid]['min'] === null ? $v : min($by[$cid]['min'], $v);
            $by[$cid]['max'] = $by[$cid]['max'] === null ? $v : max($by[$cid]['max'], $v);
        }

        $out = [];
        foreach ($by as $cid => $c) {
            $distinct = count($c['values']);
            $out[] = [
                'id'       => $cid,
                'label'    => $c['label'],
                'marks'    => $c['marks'],
                'nominees' => count($c['nominees']),
                'mean'     => round($c['sum'] / max(1, $c['marks']), 2),
                'low'      => $c['min'],
                'high'     => $c['max'],
                'spread'   => round((float) $c['max'] - (float) $c['min'], 2),
                'distinct' => $distinct,
                // Display only, and rounded by the rubric rather than here — one resolver.
                'share'    => $shares[$cid] ?? null,
                // A criterion every judge marked the same is not judging, it is a
                // formality with a weight attached.
                'inert'    => $distinct <= 1,
            ];
        }

        // ── AND THE CRITERIA NOBODY HAS EVER MARKED ──────────────────────────
        //
        // This listed only criteria that appear in the scores, which is every criterion
        // that WAS used and none of the ones that were not. So a rubric where three of
        // six criteria have never been marked by anybody on any nominee rendered as a
        // tidy three-row table — whose "worth" column added up to 57% and was left to be
        // read as a rounding oddity.
        //
        // It is the opposite of an oddity. Those criteria are in force: the scorer
        // divides by the weight actually marked, so 43% of every mark in this programme
        // was silently reweighted onto the three the panel did use. That is the largest
        // single fact about how this award decides, and the page could not say it because
        // the row was not there to carry it.
        //
        // Listed with `marks: 0` and a null mean rather than omitted, because a criterion
        // that decided nothing BY BEING UNUSED is the same finding as one that decided
        // nothing by being marked identically — and an operator scanning for "does this
        // add up to a hundred" needs every row that carries a share.
        foreach (JudgeRubric::effective($programmeId) as $r) {
            $cid = (int) $r->id;
            if ((int) $r->is_active !== 1 || isset($by[$cid])) continue;

            $out[] = [
                'id'       => $cid,
                'label'    => (string) $r->label,
                'marks'    => 0,
                'nominees' => 0,
                'mean'     => null,
                'low'      => null,
                'high'     => null,
                'spread'   => 0.0,
                'distinct' => 0,
                'share'    => $shares[$cid] ?? null,
                'inert'    => true,
                // Distinct from `inert`, because the remedy is different: an inert
                // criterion needs rewriting, an unmarked one needs either putting on the
                // ballot or taking out of the rubric.
                'unmarked' => true,
            ];
        }
        foreach ($out as $i => $c) $out[$i]['unmarked'] ??= false;

        // Worst first: a criterion nobody marked, then an inert one, then the narrowest
        // spread among the rest.
        usort($out, static function (array $a, array $b): int {
            return [$b['unmarked'], $b['inert'], -$a['spread']]
               <=> [$a['unmarked'], $a['inert'], -$b['spread']];
        });

        return $out;
    }

    /**
     * Scorecards a judge left part-marked, which still counted.
     *
     * ── WHY THIS IS NOT THE SAME AS "UNJUDGED" ───────────────────────────────
     *
     * Coverage above counts nominees NOBODY marked. This counts nominees somebody marked
     * INCOMPLETELY: three criteria of five, then the tab was closed. That mark is in the
     * result — the weighted average divides by the weight actually marked, so a partial
     * scorecard is not thrown away, it is silently reweighted onto whichever criteria the
     * judge happened to finish.
     *
     * A nominee whose only judge marked the two criteria they scored well on is a real
     * outcome of that, and it is invisible in every other number on this page.
     *
     * The required set is {@see JudgeRubric::effective()} — the same list the scorer uses
     * and the same one `completeScorecards()` counts against. Retiring a criterion shrinks
     * it, so an old scorecard does not become incomplete retrospectively.
     *
     * ── TWO FINDINGS, AND THEY WERE BEING REPORTED AS ONE ────────────────────
     *
     * Measured against the whole rubric, this card said the same thing about a judge who
     * abandoned a scorecard halfway and a judge who marked every criterion that has ever
     * been on the ballot. That second case is not rare: `effective()` is the programme's
     * own criteria PLUS the global rubric it inherits, so a programme that adds three
     * criteria without retiring the four it inherits has a seven-criterion rubric that
     * every judge answers three of. Every scorecard in the programme then appears here,
     * identically, reading as a panel that could not finish anything.
     *
     * It is a rubric fault and it belongs stated once. `never_marked` is the set no
     * scorecard has EVER covered — criteria in force, carrying a share of every mark,
     * that nobody has been asked. What remains is measured against what the panel
     * actually had in play, so a judge is listed here only when they fell short of what
     * their colleagues managed on the same rubric.
     *
     * @param list<object> $scores
     * @return array{pairs:int, rows:list<array<string,mixed>>, required:int, in_play:int,
     *               never_marked:list<array{label:string, share:float|null}>, dead_share:float}
     */
    private static function incompleteScorecards(int $programmeId, array $scores): array
    {
        $active = array_values(array_filter(
            JudgeRubric::effective($programmeId),
            static fn (object $r): bool => (int) $r->is_active === 1
        ));
        $required = count($active);
        $blank = ['pairs' => 0, 'rows' => [], 'required' => $required, 'in_play' => 0,
                  'never_marked' => [], 'dead_share' => 0.0];

        if ($required < 1 || $scores === []) return $blank;

        $marked = [];
        $used   = [];
        foreach ($scores as $s) {
            $k = (int) $s->judge_id . ':' . (int) $s->nominee_id;
            $marked[$k] ??= ['judge' => (string) $s->judge_name, 'nominee' => (string) $s->nominee_name,
                             'judge_id' => (int) $s->judge_id, 'nominee_id' => (int) $s->nominee_id,
                             'criteria' => []];
            $marked[$k]['criteria'][(int) $s->criterion_id] = true;
            $used[(int) $s->criterion_id] = true;
        }

        // The rubric fault, separated out before anybody's name is attached to it.
        $shares  = JudgeRubric::shares($programmeId);
        $never   = [];
        $deadPct = 0.0;
        foreach ($active as $r) {
            $cid = (int) $r->id;
            if (isset($used[$cid])) continue;
            $never[] = ['label' => (string) $r->label, 'share' => $shares[$cid] ?? null];
            $deadPct += (float) ($shares[$cid] ?? 0);
        }

        // And what a scorecard is now measured against: the criteria this panel was
        // actually asked, never the ones nobody was.
        $inPlay = count($used);

        $rows = [];
        foreach ($marked as $m) {
            $n = count($m['criteria']);
            if ($n >= $inPlay) continue;
            $rows[] = [
                'judge'    => $m['judge'],
                'nominee'  => $m['nominee'],
                'marked'   => $n,
                'required' => $inPlay,
                // The share of what was in play that decided this scorecard, which is
                // what reweighting actually means to the nominee.
                'covered'  => (int) round($n * 100 / max(1, $inPlay)),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['marked'] <=> $b['marked']);

        return ['pairs' => count($rows), 'rows' => array_slice($rows, 0, 50),
                'required' => $required, 'in_play' => $inPlay,
                'never_marked' => $never, 'dead_share' => round($deadPct, 1)];
    }

    /**
     * The nominees the panel could not agree about.
     *
     * ── WHERE A CHALLENGE LANDS ──────────────────────────────────────────────
     *
     * Not the lowest scores and not the closest results — the widest DISAGREEMENT. A
     * nominee two judges rated 8.4 and 4.1 has a mark that is the average of two different
     * opinions, and averaging them produced a number neither judge held. That is the row
     * somebody appeals, and it was on no screen.
     *
     * ── THE PER-JUDGE AVERAGE IS THE SCORER'S, NOT A NEW ONE ─────────────────
     *
     * `sum(score × weight) / sum(weight)`, which is what {@see JudgeService::activity()}
     * shows a judge about their own marking. Weight comes from the rubric rather than
     * being assumed equal: a rubric where one criterion is worth half the mark would
     * otherwise report agreement that does not exist.
     *
     * Deliberately NOT a ranking. This page does not decide who won — it says where the
     * panel was furthest from itself, and leaves the result to the screens that publish it.
     *
     * @param list<object> $scores
     * @return list<array<string,mixed>>
     */
    private static function disagreement(int $programmeId, array $scores): array
    {
        if ($scores === []) return [];

        $weights = [];
        foreach (JudgeRubric::effective($programmeId) as $r) {
            $weights[(int) $r->id] = max(1, (int) $r->weight);
        }

        // [nominee][judge] => weighted sum + weight total
        $acc = [];
        foreach ($scores as $s) {
            $nid = (int) $s->nominee_id;
            $jid = (int) $s->judge_id;
            $w   = $weights[(int) $s->criterion_id] ?? 1;

            $acc[$nid]['name']   ??= (string) $s->nominee_name;
            $acc[$nid]['status'] ??= (string) ($s->nominee_status ?? '');
            $acc[$nid]['j'][$jid] ??= ['ws' => 0.0, 'wt' => 0, 'judge' => (string) $s->judge_name];
            $acc[$nid]['j'][$jid]['ws'] += (float) $s->score * $w;
            $acc[$nid]['j'][$jid]['wt'] += $w;
        }

        $out = [];
        foreach ($acc as $nid => $a) {
            $avgs = [];
            foreach ($a['j'] as $j) {
                if ($j['wt'] > 0) $avgs[$j['judge']] = round($j['ws'] / $j['wt'], 2);
            }
            // One judge cannot disagree with anybody. That nominee's problem is a thin
            // panel and coverage above already names it.
            if (count($avgs) < 2) continue;

            $out[] = [
                'nominee_id' => $nid,
                'nominee'    => $a['name'],
                // ── DID THIS LAND ON A RESULT? ───────────────────────────────
                //
                // The card said where the panel was furthest from itself and stopped
                // there, which left the reader to hold twenty-five names in their head and
                // go and look up each one. Whether the nominee was crowned is the whole
                // difference between an interesting row and the row somebody appeals: a
                // 4.4 spread on a nominee who finished ninth is a note about the rubric,
                // and the same spread on the winner is the challenge.
                //
                // It reports a placing that already happened; it still decides nothing.
                'crowned'    => in_array($a['status'] ?? '', ['winner', 'runner_up'], true)
                    ? (string) $a['status'] : null,
                'judges'     => count($avgs),
                'low'        => min($avgs),
                'high'       => max($avgs),
                'spread'     => round(max($avgs) - min($avgs), 2),
                'marks'      => $avgs,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            // Spread first — it is the finding. A crowned nominee breaks the tie, because
            // between two equally contested rows the one that decided something is the
            // one to read first.
            return [$b['spread'], $b['crowned'] !== null]
               <=> [$a['spread'], $a['crowned'] !== null];
        });

        return array_slice($out, 0, 25);
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
            ->select('n.id', 'n.name', 'c.cycle_id', 'n.category_id');
        MergeService::notMerged($q, 'n.merged_into');

        $rows = $q->get();

        // ── THE PANEL IS ASKED FOR THE SHORTLIST, SO THE AUDIT MEASURES IT ───
        //
        // This counted every approved nominee, and the panel is only ever given the
        // published shortlist — so a nominee the shortlist rules correctly left off was
        // reported as a coverage FAILURE. "Never scored: 1" in red, on a nominee nobody
        // was ever supposed to score. The audit's loudest column was flagging the system
        // working, which is the fastest way to teach an operator to ignore it.
        //
        // Per CATEGORY, not per cycle, and through {@see ResultRelease::shortlistedIn()}
        // rather than a lookup written here: it returns NULL for "this category does not
        // shortlist", which is a different state from "shortlisted nobody" and the one
        // that must leave every nominee in scope. A cycle-wide id set cannot tell them
        // apart — every nominee of an unshortlisted category would read as left off.
        $listed = [];
        foreach (array_unique(array_map(static fn ($r): int => (int) $r->category_id, $rows->all())) as $catId) {
            $ids = ResultRelease::shortlistedIn($catId);
            // null → this category does not shortlist, so its whole field is the panel's.
            $listed[$catId] = $ids === null ? null : array_fill_keys($ids, true);
        }

        $out = [];
        foreach ($rows as $r) {
            $cat = (int) $r->category_id;
            $map = $listed[$cat] ?? null;

            $out[(int) $r->cycle_id][] = [
                'id'   => (int) $r->id,
                'name' => (string) $r->name,
                // Kept on the row rather than filtered out here. A nominee left off the
                // list who was scored ANYWAY is a real finding — panel time spent on
                // somebody who cannot win, or a shortlist republished after judging
                // began — and dropping them would make the marks in `scores` belong to
                // nobody the coverage table has heard of.
                'shortlisted' => $map === null || isset($map[(int) $r->id]),
            ];
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
                     // Whether this nominee ended up crowned. Selected here so a finding
                     // can say whether it landed on a RESULT — see disagreement().
                     'n.status as nominee_status',
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
     * ── WHICH NOMINEES, DECIDED BY THE CALLER ────────────────────────────────
     *
     * Returns the IDS rather than a count, because this figure sits in a row whose every
     * other column is measured over the published shortlist. Counting here would put a
     * failed dossier for somebody the panel was never asked about beside "shortlisted: 3"
     * — the same false alarm the coverage column has just stopped raising, one cell to the
     * right. {@see cycleCoverage()} holds the shortlist flags, so it does the counting.
     *
     * @param list<int> $cycleIds
     * @return array<int, array<int,true>> cycle id => nominee ids with no usable orientation
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
        foreach ($latest as $nomineeId => $v) {
            if (!$v['ok']) $out[$v['cycle']][(int) $nomineeId] = true;
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

        // The panel's own list, for the same reason cycleCoverage() uses it: "never
        // scored" has to mean somebody who should have been and was not.
        $all = 0; $judgedOnList = 0; $offList = 0;
        foreach ($nominees as $rows) {
            foreach ($rows as $n) {
                $scored = isset($nomJudged[(int) $n['id']]);
                if (empty($n['shortlisted'])) { if ($scored) $offList++; continue; }
                $all++;
                if ($scored) $judgedOnList++;
            }
        }

        return [
            'cycles'   => count($cycles),
            'nominees' => $all,
            'judged'   => $judgedOnList,
            'unjudged' => max(0, $all - $judgedOnList),
            'off_list' => $offList,
            'judges'   => count($judges),
            'scores'   => count($scores),
        ];
    }

    /** @return array<string,int> */
    private static function zeroTotals(): array
    {
        return ['cycles' => 0, 'nominees' => 0, 'judged' => 0, 'unjudged' => 0,
                'off_list' => 0, 'judges' => 0, 'scores' => 0];
    }
}
