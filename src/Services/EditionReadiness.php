<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\EditionPrizes;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "Is this edition ready?" — a checklist on the edition screen (11 Nov 2026).
 *
 * The owner asked for research on what award platforms give their operators. Every one of
 * them clones editions and filters entries; NONE checks readiness in the product — Evalato
 * sells it as an onboarding call. So an operator here found a gap the way the public did: a
 * category opened to voting with one nominee on its ballot, or a results date never set, so
 * the automation had nothing to move to.
 *
 * Every line is COMPUTED from the edition's own rows, never typed — a checklist somebody
 * ticks is a checklist that says "done" about work that is not. Each item says what is
 * missing and links to the place that fixes it. Items only apply from the phase they matter
 * in: a category short of nominees is a blocker for voting, not for an edition still taking
 * nominations, where it is the normal state.
 */
final class EditionReadiness
{
    /** Fewer than this many on a ballot is not a choice. */
    public const MIN_ON_BALLOT = 2;

    /**
     * @return list<array{state:string, title:string, detail:string, href:?string}>  state: ok | todo | note
     */
    public static function for(object $cycle): array
    {
        $cycleId = (int) $cycle->id;
        $programmeId = (int) $cycle->programme_id;
        $edit = "/admin/programmes/$programmeId/cycle?cycle=$cycleId";
        $phase = CyclePolicy::stateFor($cycle);
        $ordinal = (int) $phase['ordinal'];
        $votingOrdinal = CyclePhase::Voting->ordinal();
        $out = [];

        // ── Dates: the automation moves an edition on its dates, so a missing one is a
        //    phase that never begins (or never ends).
        $labels = ['nominations_open' => 'nominations open', 'nominations_close' => 'nominations close',
            'voting_open' => 'voting opens', 'voting_close' => 'voting closes', 'results_date' => 'results are due'];
        $missing = array_values(array_map(fn ($k) => $labels[$k], array_filter(array_keys($labels), fn ($k) => empty($cycle->$k))));
        $err = CycleService::windowError((array) $cycle);
        $out[] = $err !== null
            ? ['state' => 'todo', 'title' => 'The dates are out of order', 'detail' => $err, 'href' => $edit . '#f-nominations_open']
            : ($missing === []
                ? ['state' => 'ok', 'title' => 'Every date is set, in order', 'detail' => '', 'href' => null]
                : ['state' => 'todo', 'title' => 'Dates to set', 'detail' => 'When ' . self::list($missing) . '.', 'href' => $edit . '#f-nominations_open']);

        // ── Categories, and what each holds.
        $cats = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->orderBy('sort_order')->get(['id', 'title', 'description']);
        if ($cats->isEmpty()) {
            $out[] = ['state' => 'todo', 'title' => 'No categories yet', 'detail' => 'Nobody can be nominated until there is something to be nominated for.', 'href' => $edit . '#f-title'];
            return $out;
        }
        $out[] = ['state' => 'ok', 'title' => $cats->count() . ' categor' . ($cats->count() === 1 ? 'y' : 'ies'), 'detail' => '', 'href' => null];
        $bare = $cats->filter(fn ($c) => trim((string) $c->description) === '')->pluck('title')->all();
        if ($bare) {
            $out[] = ['state' => 'note', 'title' => count($bare) . ' categor' . (count($bare) === 1 ? 'y has' : 'ies have') . ' no description',
                'detail' => 'Nominators and voters read it to know who belongs: ' . self::list($bare, 4) . '.', 'href' => $edit];
        }

        $q = DB::table('gates_nominees')->whereIn('category_id', $cats->pluck('id')->all());
        MergeService::notMerged($q, 'merged_into');
        $byCat = $q->groupBy('category_id', 'status')->selectRaw('category_id, status, COUNT(*) as n')->get();
        $onBallot = []; $pending = 0;
        foreach ($byCat as $r) {
            if ($r->status === 'pending') $pending += (int) $r->n;
            else $onBallot[(int) $r->category_id] = ($onBallot[(int) $r->category_id] ?? 0) + (int) $r->n;
        }
        if ($pending > 0) {
            $out[] = ['state' => $ordinal >= $votingOrdinal ? 'todo' : 'note',
                'title' => $pending . ' nominee' . ($pending === 1 ? '' : 's') . ' waiting for approval',
                'detail' => 'They are not on the ballot until somebody approves them.', 'href' => "/admin/nominees?cycle=$cycleId&status=pending"];
        }
        $thin = $cats->filter(fn ($c) => ($onBallot[(int) $c->id] ?? 0) < self::MIN_ON_BALLOT)->pluck('title')->all();
        if ($thin) {
            // Before voting this is the normal state of an edition taking nominations, so it
            // is a note; from voting on it is a ballot with no choice on it.
            $out[] = ['state' => $ordinal >= $votingOrdinal ? 'todo' : 'note',
                'title' => count($thin) . ' categor' . (count($thin) === 1 ? 'y has' : 'ies have') . ' fewer than ' . self::MIN_ON_BALLOT . ' approved nominees',
                'detail' => self::list($thin, 4) . '.', 'href' => "/admin/nominees?cycle=$cycleId"];
        } else {
            $out[] = ['state' => 'ok', 'title' => 'Every category has a choice on its ballot', 'detail' => '', 'href' => null];
        }

        // ── The panel's questions, and the prizes.
        $rubric = count(JudgeRubric::effective($programmeId));
        $out[] = $rubric > 0
            ? ['state' => 'ok', 'title' => $rubric . ' judging criteri' . ($rubric === 1 ? 'on' : 'a'), 'detail' => '', 'href' => null]
            : ['state' => 'todo', 'title' => 'No judging criteria', 'detail' => 'The panel would have nothing to score against, and the judge half would be empty.', 'href' => "/admin/rubric?programme=$programmeId"];
        if (EditionPrizes::for($cycleId) === []) {
            $out[] = ['state' => 'note', 'title' => 'No prizes listed', 'detail' => 'Optional — shown on the award page and in the voting letter.', 'href' => $edit . '#prizes'];
        }
        return $out;
    }

    /** "a, b and c", at most $max named and the rest counted. @param list<string> $items */
    private static function list(array $items, int $max = 6): string
    {
        $more = count($items) - $max;
        $items = array_slice($items, 0, $max);
        if ($more > 0) $items[] = $more . ' more';
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items) : (string) ($items[0] ?? '');
    }
}
