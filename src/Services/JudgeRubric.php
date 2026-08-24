<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The judging rubric: the criteria a panel scores against, and what each one is worth.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_judge_criteria` is the table the entire scoring system runs on — every ballot,
 * every weighted average, every bias check and every published result — and there was NO
 * WAY TO EDIT IT. It was written by the installer and by the sandbox seeder, and read
 * everywhere. An operator running a real awards cycle could not add a criterion, change a
 * weight, correct a description, or retire one.
 *
 * That is not a missing convenience on a platform whose integrity argument is "the criteria
 * are fixed and published before judging starts". You cannot publish criteria you cannot
 * author.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * FOUR RULES THIS ENFORCES, AND WHY EACH ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1 · A CRITERION THAT HAS BEEN SCORED IS NEVER DELETED. `gates_judge_criteria_scores`
 *     points at it by id, and deleting the row would orphan every ballot that used it —
 *     silently changing published results, because {@see NomineeScoringService} counts only
 *     criteria it can still resolve. Retiring sets `is_active = 0`, which stops it being
 *     asked in future without rewriting the past.
 *
 * 2 · THE SLUG IS IMMUTABLE ONCE SCORED, for the same reason one level up: the per-programme
 *     override in NomineeScoringService::criteriaWeights() resolves by SLUG, so renaming one
 *     silently re-points a programme's override at nothing.
 *
 * 3 · WEIGHTS ARE RELATIVE, NOT PERCENTAGES, and the screen says so. The scorer divides by
 *     the total of whatever is active, so four criteria at 25 and four at 1 produce
 *     identical results. An operator who believes they must total 100 will spend an
 *     afternoon making them, and an operator who believes 10 means "10%" will be wrong
 *     about what they published.
 *
 * 4 · A PROGRAMME'S OWN CRITERION OVERRIDES THE GLOBAL ONE WITH THE SAME SLUG. That is
 *     existing behaviour in the scorer; this makes it visible, because an invisible
 *     override is how a programme ends up judged on criteria nobody meant to give it.
 */
final class JudgeRubric
{
    public const MAX_LABEL  = 160;
    public const MAX_DESC   = 600;

    /** Ten criteria is already a long ballot; beyond that judges stop reading. */
    public const MAX_PER_SCOPE = 12;

    /** Relative, not a percentage — see the class note. */
    public const MIN_WEIGHT = 1;
    public const MAX_WEIGHT = 100;

    // ═══════════════════════════════════════════════════════════════════════
    // READING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every criterion in one scope, active or not.
     *
     * @param int|null $programmeId null = the global rubric
     * @return array<int,object>
     */
    public static function forScope(?int $programmeId): array
    {
        try {
            $q = DB::table('gates_judge_criteria');
            $programmeId === null
                ? $q->whereNull('programme_id')
                : $q->where('programme_id', $programmeId);

            return $q->orderBy('sort_order')->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * What a panel judging this programme will ACTUALLY be asked, after the override.
     *
     * Mirrors {@see NomineeScoringService::criteriaWeights()} exactly — resolve by slug,
     * programme wins over global — because a screen that showed a different rubric from the
     * one the scorer uses would be worse than no screen.
     *
     * @return array<int,object> keyed by nothing, in ballot order
     */
    public static function effective(?int $programmeId): array
    {
        try {
            $rows = DB::table('gates_judge_criteria')
                ->where('is_active', 1)
                ->where(function ($q) use ($programmeId): void {
                    $q->whereNull('programme_id');
                    if ($programmeId !== null) $q->orWhere('programme_id', $programmeId);
                })
                ->orderBy('sort_order')->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }

        $bySlug = [];
        foreach ($rows as $r) {
            $slug = (string) $r->slug;
            // The programme's own row wins. Same precedence as the scorer.
            if (!isset($bySlug[$slug]) || $r->programme_id !== null) $bySlug[$slug] = $r;
        }

        return array_values($bySlug);
    }

    /**
     * What each criterion is worth as a share of the total, for display only.
     *
     * The stored weight is relative; this is the number an operator actually wants to see
     * and the one they would otherwise compute wrongly in their head.
     *
     * @return array<int,float> criterion id => percentage, one decimal
     */
    public static function shares(?int $programmeId): array
    {
        $rows  = self::effective($programmeId);
        $total = 0;
        foreach ($rows as $r) $total += max(self::MIN_WEIGHT, (int) $r->weight);
        if ($total < 1) return [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = round(max(self::MIN_WEIGHT, (int) $r->weight) * 100 / $total, 1);
        }
        return $out;
    }

    /** How many ballots already reference this criterion. */
    public static function scoreCount(int $criterionId): int
    {
        try {
            return (int) DB::table('gates_judge_criteria_scores')
                ->where('criterion_id', $criterionId)->count();
        } catch (\Throwable) {
            // Unknown is treated as USED. Guessing "nobody scored it" here would license a
            // delete that orphans ballots, and the cost of being wrong the other way is one
            // criterion that has to be retired instead of removed.
            return 1;
        }
    }

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        try {
            return DB::table('gates_judge_criteria')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Add or edit one criterion.
     *
     * @return array{ok:bool, message:string, id?:int, field?:string}
     */
    public static function save(?int $programmeId, int $id, array $in): array
    {
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '') {
            return ['ok' => false, 'field' => 'label',
                    'message' => 'Give the criterion a name — it is the question a judge answers.'];
        }
        if (mb_strlen($label) > self::MAX_LABEL) {
            return ['ok' => false, 'field' => 'label',
                    'message' => 'That name is longer than ' . self::MAX_LABEL . ' characters. '
                               . 'It sits above a score box on every ballot, so it has to be short.'];
        }

        // Parsed strictly rather than scrubbed. Stripping non-digits — which is what this
        // did first — turns "-5" into 5, so a typed minus sign is silently accepted as a
        // valid weight and the operator never learns what they actually saved.
        $rawWeight = trim((string) ($in['weight'] ?? ''));
        $weight    = ctype_digit($rawWeight) ? (int) $rawWeight : 0;
        if ($weight < self::MIN_WEIGHT || $weight > self::MAX_WEIGHT) {
            return ['ok' => false, 'field' => 'weight',
                    'message' => 'Weight must be between ' . self::MIN_WEIGHT . ' and '
                               . self::MAX_WEIGHT . '. It is a RELATIVE number, not a '
                               . 'percentage — they do not need to add up to anything.'];
        }

        $existing = $id > 0 ? self::find($id) : null;
        if ($id > 0 && !$existing) {
            return ['ok' => false, 'message' => 'That criterion no longer exists.'];
        }
        // Scoped on write, not only on read: an id in a hidden field is not authorisation
        // to edit a different programme's rubric.
        if ($existing && (int) ($existing->programme_id ?? 0) !== (int) ($programmeId ?? 0)) {
            return ['ok' => false, 'message' => 'That criterion belongs to a different rubric.'];
        }

        // ── the slug ─────────────────────────────────────────────────────────
        $scored = $existing !== null && self::scoreCount($id) > 0;
        $slug   = $existing !== null
            ? (string) $existing->slug
            : Slug::make((string) ($in['slug'] ?? '') ?: $label, 40);

        if ($slug === '') {
            return ['ok' => false, 'field' => 'label',
                    'message' => 'That name has no letters or numbers in it, so it cannot be '
                               . 'given a reference. Add a word.'];
        }

        // A rename is refused rather than silently ignored: the per-programme override in
        // the scorer resolves by SLUG, so changing one re-points it at nothing.
        $wanted = trim((string) ($in['slug'] ?? ''));
        if ($existing !== null && $wanted !== '' && Slug::make($wanted, 40) !== $slug && $scored) {
            return ['ok' => false, 'field' => 'slug',
                    'message' => 'This criterion has already been scored, so its reference '
                               . 'cannot change — ballots point at it. Retire it and add a '
                               . 'new one if the meaning has changed.'];
        }
        if ($existing !== null && $wanted !== '' && !$scored) {
            $slug = Slug::make($wanted, 40) ?: $slug;
        }

        // Unique within the scope. Two criteria with one slug means the scorer keeps one of
        // them and silently drops the other's weight.
        try {
            $clash = DB::table('gates_judge_criteria')->where('slug', $slug);
            $programmeId === null
                ? $clash->whereNull('programme_id')
                : $clash->where('programme_id', $programmeId);
            if ($id > 0) $clash->where('id', '!=', $id);

            if ($clash->exists()) {
                return ['ok' => false, 'field' => 'slug',
                        'message' => 'There is already a criterion with the reference “' . $slug
                                   . '” in this rubric. Two with the same reference means the '
                                   . 'scorer keeps one and drops the other.'];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be checked just now.'];
        }

        $row = [
            'programme_id' => $programmeId,
            'slug'         => $slug,
            'label'        => mb_substr($label, 0, self::MAX_LABEL),
            'description'  => mb_substr(trim((string) ($in['description'] ?? '')), 0, self::MAX_DESC) ?: null,
            'weight'       => $weight,
            'sort_order'   => max(0, min(999, (int) ($in['sort_order'] ?? 0))),
            'is_active'    => empty($in['retired']) ? 1 : 0,
        ];

        try {
            if ($id > 0) {
                DB::table('gates_judge_criteria')->where('id', $id)->update($row);
                return ['ok' => true, 'id' => $id, 'message' => 'Saved.'];
            }

            if (count(self::forScope($programmeId)) >= self::MAX_PER_SCOPE) {
                return ['ok' => false,
                        'message' => 'This rubric already has ' . self::MAX_PER_SCOPE
                                   . ' criteria, which is a long ballot. Retire one first — '
                                   . 'a panel that stops reading is a panel that stops judging.'];
            }

            $newId = (int) DB::table('gates_judge_criteria')->insertGetId($row);
            return ['ok' => true, 'id' => $newId, 'message' => 'Added to the rubric.'];
        } catch (\Throwable $e) {
            error_log('[rubric] save failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }
    }

    /**
     * Retire a criterion, or delete it if nothing has ever been scored against it.
     *
     * ── WHY THIS IS ONE ACTION AND NOT TWO BUTTONS ──────────────────────────
     *
     * Because the operator's intent is the same in both cases — "stop asking this" — and
     * only the platform knows which of the two is safe. Offering both would put the
     * destructive one in front of somebody with no way to tell whether it orphans a hundred
     * ballots, and the answer requires a query they cannot run.
     *
     * @return array{ok:bool, message:string, deleted:bool}
     */
    public static function retire(?int $programmeId, int $id): array
    {
        $row = self::find($id);
        if (!$row) return ['ok' => false, 'deleted' => false, 'message' => 'That criterion no longer exists.'];

        if ((int) ($row->programme_id ?? 0) !== (int) ($programmeId ?? 0)) {
            return ['ok' => false, 'deleted' => false,
                    'message' => 'That criterion belongs to a different rubric.'];
        }

        $used = self::scoreCount($id);

        try {
            if ($used > 0) {
                DB::table('gates_judge_criteria')->where('id', $id)->update(['is_active' => 0]);
                return ['ok' => true, 'deleted' => false,
                        'message' => 'Retired. It will not be asked again, and the '
                                   . number_format($used) . ' score' . ($used === 1 ? '' : 's')
                                   . ' already recorded against it are untouched — deleting it '
                                   . 'would have changed results that have already been '
                                   . 'published.'];
            }

            DB::table('gates_judge_criteria')->where('id', $id)->delete();
            return ['ok' => true, 'deleted' => true,
                    'message' => 'Removed. Nothing had been scored against it.'];
        } catch (\Throwable $e) {
            error_log('[rubric] retire failed: ' . $e->getMessage());
            return ['ok' => false, 'deleted' => false, 'message' => 'That could not be done just now.'];
        }
    }

    /** Put a retired criterion back into the ballot. */
    public static function restore(?int $programmeId, int $id): array
    {
        $row = self::find($id);
        if (!$row || (int) ($row->programme_id ?? 0) !== (int) ($programmeId ?? 0)) {
            return ['ok' => false, 'message' => 'That criterion is not in this rubric.'];
        }

        try {
            DB::table('gates_judge_criteria')->where('id', $id)->update(['is_active' => 1]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be done just now.'];
        }

        return ['ok' => true, 'message' => 'Back in the rubric. Judges will be asked it again.'];
    }

    /**
     * Whether editing this rubric is still safe to do freely.
     *
     * Not a lock — an operator may have a real reason to change a rubric mid-cycle, and a
     * platform that refuses would be one they work around in the database. But they should
     * be told, because scores already recorded were given against the OLD weights and the
     * published result will move under them.
     *
     * @return array{scored:bool, ballots:int, note:string}
     */
    public static function exposure(?int $programmeId): array
    {
        $ids = array_map(static fn (object $r): int => (int) $r->id, self::forScope($programmeId));
        if ($ids === []) return ['scored' => false, 'ballots' => 0, 'note' => ''];

        try {
            $n = (int) DB::table('gates_judge_criteria_scores')->whereIn('criterion_id', $ids)->count();
        } catch (\Throwable) {
            $n = 0;
        }

        if ($n < 1) {
            return ['scored' => false, 'ballots' => 0,
                    'note' => 'Nothing has been scored against this rubric yet, so it is free '
                            . 'to change.'];
        }

        return ['scored' => true, 'ballots' => $n,
                'note' => number_format($n) . ' score' . ($n === 1 ? ' has' : 's have')
                        . ' already been recorded against this rubric. You can still change '
                        . 'it — but those scores were given against the weights as they '
                        . 'stood, so changing a weight moves a result that has already been '
                        . 'reached.'];
    }
}
