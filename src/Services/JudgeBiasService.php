<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Does a judge score systematically differently depending on WHO they are scoring?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THIS IS NOT WHAT JudgeAnomalyService DOES, AND THE DIFFERENCE IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see JudgeAnomalyService} finds a judge who disagreed with their panel about ONE
 * nominee. That is disagreement, and disagreement is what a panel is for — the outlier is
 * frequently the judge who read the evidence.
 *
 * This asks a different question, and it is the one people mean by bias: across everything
 * a judge scored, does their deviation from the rest of the panel move with an ATTRIBUTE of
 * the nominee? Harsher on entries from one country. Softer in one category. Consistently
 * low on one criterion while everyone else is not.
 *
 * A single disagreement is noise. A pattern across fifteen nominees is a finding.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE MEASUREMENT, AND WHY IT IS BUILT THE WAY IT IS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · DEVIATION, NOT SCORE. For every (nominee, criterion) a judge scored, the unit is
 *     their score MINUS the mean of the other judges on that same nominee and criterion.
 *
 *     This is the whole design. Comparing raw scores by country would "discover" that a
 *     judge who happened to be assigned stronger entries scores higher — which is not bias,
 *     it is the assignment. Differencing against the same nominee removes the nominee's
 *     actual quality from the measurement entirely.
 *
 * 2 · RELATIVE TO THE JUDGE'S OWN BASELINE. A judge who marks everybody half a point down
 *     is strict, not biased, and reporting them would bury the real finding under a dozen
 *     harmless ones. So what is reported is the group's deviation MINUS that judge's
 *     overall deviation. A judge at −0.1 everywhere and −1.3 on one country is the signal.
 *
 * 3 · A FLOOR ON n, AND IT IS NOT NEGOTIABLE. Three nominees from one country tells you
 *     nothing at all, and a screen that reports it will have somebody confronted about it.
 *     {@see MIN_OBSERVATIONS} is the bar; groups under it are counted and named as "too few
 *     to say", never silently dropped — an organiser needs to know the question was asked
 *     and could not be answered.
 *
 * 4 · MULTIPLE COMPARISONS ARE STATED, NOT HIDDEN. Twenty judges across three axes is
 *     hundreds of tests, and at any sensible threshold some will look striking by chance.
 *     {@see scan()} returns the number of comparisons it made so the screen can say it. A
 *     "finding" presented without that number is the most misleading thing this file could
 *     produce, because it is arithmetically correct and still wrong.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY NO MODEL IS INVOLVED IN THE DETECTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Deliberately. Asking a language model whether a judge is biased produces a fluent,
 * confident answer with no arithmetic behind it and no way to check it — about a named
 * person, in a record they may later see. The detection here is a mean and a subtraction,
 * reproducible by hand from the same table.
 *
 * The model's job comes after: {@see IntegrityBriefService} takes these findings, along
 * with the vote-fraud and collusion signals, and writes the summary. Explaining a number is
 * something it is good at. Deciding the number is not.
 *
 * Nothing here adjusts a score, drops a judge, or changes a result. It produces a list for
 * a person to look at.
 */
final class JudgeBiasService
{
    /**
     * The axes a nominee can be grouped by.
     *
     * Three, and only these three, because they are the attributes the platform actually
     * HOLDS and that a person can interpret. `organisation` is free text a nominator typed
     * and would group "Lagos State University", "LASU" and "lasu" separately; there is no
     * gender, age or ethnicity field and adding one to test for bias against it would be a
     * far worse idea than the bias.
     */
    public const AXES = [
        'country'   => "The nominee's country",
        'category'  => 'The award category',
        'criterion' => 'The scoring criterion',
    ];

    /**
     * Scores in a group before it is reportable.
     *
     * Eight (nominee, criterion) pairs — so roughly two nominees across four criteria, or
     * eight across one. Below this the mean is dominated by whichever entry the judge felt
     * strongly about, and a screen that reports it will get somebody accused on the strength
     * of two opinions.
     */
    public const MIN_OBSERVATIONS = 8;

    /**
     * How far a group has to sit from the judge's own baseline to be worth naming, on the
     * platform's 0-10 criterion scale.
     *
     * 0.75 of a point. Low enough to catch a real lean, high enough that ordinary variation
     * in taste does not fill the screen — and every finding carries its size, so a reader
     * weighs 0.8 differently from 2.4 rather than seeing them both as "flagged".
     */
    public const MIN_EFFECT = 0.75;

    // ═══════════════════════════════════════════════════════════════════════
    // THE SCAN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every reportable lean in a cycle.
     *
     * @return array{findings:list<array<string,mixed>>, comparisons:int, judges:int,
     *               scores:int, thin:list<array<string,mixed>>, note:string}
     */
    public static function forCycle(int $cycleId): array
    {
        $rows = self::scores($cycleId);

        $empty = ['findings' => [], 'comparisons' => 0, 'judges' => 0, 'scores' => 0,
                  'thin' => []];

        if ($rows === []) {
            return $empty + ['note' => 'No scores have been recorded for this cycle yet.'];
        }

        // ── STEP 1 · the panel mean per (nominee, criterion) ─────────────────
        //
        // Keyed on both, not on the nominee alone: criteria are not interchangeable, and
        // averaging "impact" with "originality" would compare a judge's impact score against
        // a mixture that includes nobody's impact score.
        $panel = [];
        foreach ($rows as $r) {
            $key = $r['nominee_id'] . ':' . $r['criterion_id'];
            $panel[$key][] = (float) $r['score'];
        }

        // ── STEP 2 · each score as a deviation from the OTHER judges ────────
        //
        // Leave-one-out, and it matters: including the judge in the mean they are compared
        // against pulls the mean toward them and shrinks every deviation, most for the
        // smallest panels — which is exactly where a lean is easiest to hide.
        $dev = [];
        foreach ($rows as $r) {
            $key    = $r['nominee_id'] . ':' . $r['criterion_id'];
            $others = $panel[$key];
            $n      = count($others);

            // A single judge on a nominee has nobody to deviate from. Not a finding, not an
            // error — a fact about the panel, and it is counted in `note` below.
            if ($n < 2) continue;

            $mean = (array_sum($others) - (float) $r['score']) / ($n - 1);

            $dev[] = $r + ['deviation' => (float) $r['score'] - $mean];
        }

        if ($dev === []) {
            return $empty + ['note' => 'Every nominee here was scored by one judge, so there '
                                     . 'is nothing to compare against. Bias can only be seen '
                                     . 'where panels overlap.'];
        }

        // ── STEP 3 · each judge's own baseline ──────────────────────────────
        $byJudge = [];
        foreach ($dev as $d) $byJudge[(int) $d['judge_id']][] = $d;

        $findings    = [];
        $thin        = [];
        $comparisons = 0;

        foreach ($byJudge as $judgeId => $mine) {
            $baseline = self::mean(array_column($mine, 'deviation'));
            $name     = (string) ($mine[0]['judge_name'] ?? ('Judge #' . $judgeId));

            foreach (array_keys(self::AXES) as $axis) {
                $groups = [];
                foreach ($mine as $d) {
                    $label = trim((string) ($d[$axis . '_label'] ?? ''));
                    if ($label === '') continue;
                    $groups[$label][] = (float) $d['deviation'];
                }

                // A judge who only ever saw one country has no contrast to show. Counting
                // it as a comparison would inflate the denominator the screen reports.
                if (count($groups) < 2) continue;

                // ── TWO GROUPS ARE ONE FINDING, NOT TWO ─────────────────
                //
                // Deviations are measured against the judge's own baseline, and the baseline
                // is the weighted mean of all of them — so across an axis the relative
                // deviations sum to zero by construction. With exactly two groups that makes
                // them exact mirror images: "harsher on Kenya" and "softer on Nigeria" are
                // not two discoveries, they are one fact stated from either end.
                //
                // Reporting both doubles the count on the screen and doubles the apparent
                // seriousness of something that happened once. So a two-group axis emits a
                // single finding and the sentence names both sides.
                $mirrored = count($groups) === 2;
                $emitted  = false;

                foreach ($groups as $label => $devs) {
                    $n = count($devs);

                    if ($n < self::MIN_OBSERVATIONS) {
                        // Named, not dropped. An organiser needs to know the question was
                        // asked of this group and could not be answered — otherwise the
                        // absence of a finding reads as a clean bill of health.
                        $thin[] = ['judge_id' => $judgeId, 'judge' => $name, 'axis' => $axis,
                                   'group' => (string) $label, 'scores' => $n];
                        continue;
                    }

                    $comparisons++;

                    $relative = self::mean($devs) - $baseline;
                    if (abs($relative) < self::MIN_EFFECT) continue;

                    // The lean is reported from the side it goes AGAINST. That is the
                    // direction a person will be asked about, and "scores Kenya lower" is
                    // the sentence somebody can actually check, where "scores Nigeria
                    // higher" invites the wrong follow-up question.
                    if ($mirrored) {
                        if ($emitted) continue;
                        if ($relative > 0 && self::hasNegativeGroup($groups, $baseline)) continue;
                        $emitted = true;
                    }

                    $other = $mirrored
                        ? (string) (array_values(array_diff(array_keys($groups), [$label]))[0] ?? '')
                        : '';

                    $findings[] = [
                        'judge_id'  => $judgeId,
                        'judge'     => $name,
                        'axis'      => $axis,
                        'axis_label'=> self::AXES[$axis],
                        'group'     => (string) $label,
                        'scores'    => $n,
                        // Rounded for the screen; the sign is the readable part.
                        'relative'  => round($relative, 2),
                        'baseline'  => round($baseline, 2),
                        'direction' => $relative > 0 ? 'higher' : 'lower',
                        'mirror'    => $other,
                        'sentence'  => self::sentence($name, $axis, (string) $label,
                                                      $relative, $baseline, $n, $other),
                    ];
                }
            }
        }

        // Largest lean first: the screen is read from the top and rarely to the bottom.
        usort($findings, static fn (array $a, array $b): int
            => abs($b['relative']) <=> abs($a['relative']));

        return [
            'findings'    => $findings,
            'comparisons' => $comparisons,
            'judges'      => count($byJudge),
            'scores'      => count($dev),
            'thin'        => $thin,
            'note'        => self::note(count($findings), $comparisons),
        ];
    }

    /**
     * The honest caveat, sized to what was actually done.
     *
     * Generated rather than fixed, because "we made 240 comparisons and 3 stood out" and
     * "we made 6 and 3 stood out" are very different situations and the second is the one
     * worth acting on immediately.
     */
    private static function note(int $found, int $comparisons): string
    {
        if ($comparisons === 0) {
            return 'There are not enough overlapping scores yet to test for a pattern. '
                 . 'Bias only becomes visible once several judges have scored the same '
                 . 'nominees across several groups.';
        }

        $expected = $comparisons * 0.05;

        $s = $comparisons . ' comparison' . ($comparisons === 1 ? '' : 's') . ' were made. ';

        if ($found === 0) {
            return $s . 'None showed a lean above ' . self::MIN_EFFECT . ' of a point. That is '
                 . 'the expected result on a panel behaving normally.';
        }

        $s .= $found . ' showed a lean above ' . self::MIN_EFFECT . ' of a point.';

        if ($expected >= 1 && $found <= $expected * 1.5) {
            $s .= ' With this many comparisons, roughly ' . (int) round($expected)
                . ' would look striking by chance alone, so this is close to what you would '
                . 'expect from a panel with no bias in it at all. Read them, but do not '
                . 'treat the count as evidence on its own.';
        } else {
            $s .= ' With this many comparisons, roughly ' . max(1, (int) round($expected))
                . ' would be expected by chance, so there are more here than that. '
                . 'Each one is still a question to ask a person, never an answer about them.';
        }

        return $s;
    }

    /** One finding in a sentence somebody can read out in a meeting. */
    /**
     * Whether any group on this axis sits below the judge's baseline.
     *
     * Used to pick which end of a two-group mirror to report from, so the finding is always
     * phrased as the side the lean goes against.
     *
     * @param array<string,list<float>> $groups
     */
    private static function hasNegativeGroup(array $groups, float $baseline): bool
    {
        foreach ($groups as $devs) {
            if (count($devs) >= self::MIN_OBSERVATIONS
                && (self::mean($devs) - $baseline) <= -self::MIN_EFFECT) {
                return true;
            }
        }
        return false;
    }

    private static function sentence(string $judge, string $axis, string $group,
                                     float $relative, float $baseline, int $n,
                                     string $mirror = ''): string
    {
        $dir  = $relative > 0 ? 'higher' : 'lower';
        $size = number_format(abs($relative), 2);

        $where = match ($axis) {
            'country'   => 'entries from ' . $group,
            'category'  => 'entries in ' . $group,
            'criterion' => 'the "' . $group . '" criterion',
            default     => $group,
        };

        $base = abs($baseline) < 0.2
            ? 'They score in line with their panel overall'
            : 'They score ' . number_format(abs($baseline), 2) . ' '
              . ($baseline > 0 ? 'higher' : 'lower') . ' than their panel overall';

        $s = $judge . ' scores ' . $size . ' of a point ' . $dir . ' than the rest of the '
           . 'panel on ' . $where . ', across ' . $n . ' scores. ' . $base
           . ', so this is specific to that group rather than how they mark generally.';

        if ($mirror !== '') {
            // Said explicitly, because somebody will otherwise ask why the other group is
            // not also listed — and the answer is that it is the same finding.
            $s .= ' The only other group here is ' . $mirror . ', so this is the same fact '
                . 'as scoring ' . $mirror . ' ' . ($relative > 0 ? 'lower' : 'higher')
                . '; it is counted once.';
        }

        return $s;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // READING THE SCORES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every criterion score in a cycle, with the attributes to group it by.
     *
     * One query. The alternative — a lookup per score for the country, another for the
     * category — is a few thousand queries on a real cycle, on shared hosting, on a page
     * somebody opens while deciding a result.
     *
     * @return list<array<string,mixed>>
     */
    private static function scores(int $cycleId): array
    {
        if ($cycleId < 1) return [];

        try {
            $rows = DB::table('gates_judge_criteria_scores as s')
                ->join('gates_nominees as n', 'n.id', '=', 's.nominee_id')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_judges as j', 'j.id', '=', 's.judge_id')
                ->leftJoin('gates_judge_criteria as k', 'k.id', '=', 's.criterion_id')
                ->where('c.cycle_id', $cycleId)
                // A merge tombstone still holds its old scores; counting them would
                // double-weight whoever the nominee was merged into.
                ->whereNull('n.merged_into')
                ->get([
                    's.judge_id', 's.nominee_id', 's.criterion_id', 's.score',
                    'j.name as judge_name',
                    'n.country_code',
                    // `title` and `label`, not `name`. Both tables predate the convention
                    // the rest of the schema settled on, and a wrong column here is a fatal
                    // query on a page somebody opens while deciding a result.
                    'c.title as category_name',
                    'k.label as criterion_name',
                ]);
        } catch (\Throwable $e) {
            error_log('[judge-bias] could not read scores for cycle ' . $cycleId . ': '
                    . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'judge_id'        => (int) $r->judge_id,
                'judge_name'      => (string) ($r->judge_name ?? ''),
                'nominee_id'      => (int) $r->nominee_id,
                'criterion_id'    => (int) $r->criterion_id,
                'score'           => (int) $r->score,
                // Uppercased and trimmed so 'ng', 'NG' and ' NG' are one group rather than
                // three, each too thin to report.
                'country_label'   => strtoupper(trim((string) ($r->country_code ?? ''))),
                'category_label'  => trim((string) ($r->category_name ?? '')),
                'criterion_label' => trim((string) ($r->criterion_name ?? '')),
            ];
        }

        return $out;
    }

    /** @param list<float> $xs */
    private static function mean(array $xs): float
    {
        return $xs === [] ? 0.0 : array_sum($xs) / count($xs);
    }

    /**
     * The shape {@see IntegrityBriefService} folds into its prompt.
     *
     * Trimmed to the top few, because the brief has a token budget and a model handed forty
     * findings summarises them into "there are several" — which is less useful than the
     * table the reader already has.
     *
     * @return array<string,mixed>
     */
    public static function briefInput(int $cycleId, int $limit = 6): array
    {
        $r = self::forCycle($cycleId);

        return [
            'comparisons' => $r['comparisons'],
            'judges'      => $r['judges'],
            'findings'    => array_map(
                static fn (array $f): string => $f['sentence'],
                array_slice($r['findings'], 0, max(1, $limit))
            ),
            'total'       => count($r['findings']),
            'caveat'      => $r['note'],
        ];
    }
}
