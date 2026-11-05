<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE NUMBERS A GOAL RESTS ON, AND WHAT EACH PERSON MUST DO TO REACH IT (design plan:
 * "The planning engine refuses vague goals, shows every number it assumed … Back-solving
 * targets … Each step is an editable assumption, so a corrected figure re-solves everything
 * below it").
 *
 *   · EVERY FIGURE NOBODY MEASURED IS AN ASSUMPTION, stored with where it came from and what
 *     would reopen it. A figure the platform can read (the vote price, the volunteers with a
 *     role, the nominees already confirmed) is read; anything else starts at the plan's default
 *     and says so.
 *   · BACK-SOLVING is arithmetic on those figures and nothing else, so the same assumptions
 *     always give the same targets ({@see solve()}).
 *   · GATES FOLLOW FROM THEM. A money goal gets one gate per week of its voting curve
 *     (cumulative), signed by an owner; a count goal a midpoint and a final gate, signed by a
 *     lead. Changing a figure re-drafts the gates that are still open — a signed gate is history.
 */
final class Planner
{
    /** key => [label, unit, default, what would reopen it]. A default of null is read from the platform. */
    public const KEYS = [
        'price'      => ['Price of one paid vote', 'naira', null, 'The vote price changes in the award’s settings'],
        'nominees'   => ['Nominees the plan counts on', 'count', null, 'Fewer nominees confirmed by the nomination gate'],
        'curve'      => ['Share of the money in each week of voting', 'percent list', '20,35,45', 'Two weeks of real voting that do not fit it'],
        'conversion' => ['Prospects who submit a nomination', 'percent', '33', 'The pipeline’s own rate after its first week'],
        'buffer'     => ['Extra prospects for withdrawals', 'percent', '120', 'Withdrawals running above it'],
        'volunteers' => ['Volunteers doing outreach', 'count', null, 'Somebody joins or leaves the outreach'],
        'fees'       => ['The target is counted', 'words', 'before payment fees', 'Finance confirms how the target is counted'],
    ];
    /** Which figures a goal of each metric rests on. */
    public const FOR = [
        'revenue'     => ['price', 'nominees', 'curve', 'fees', 'conversion', 'buffer', 'volunteers'],
        'nominees'    => ['conversion', 'buffer', 'volunteers'],
        'nominations' => ['conversion', 'buffer', 'volunteers'],
        'converted'   => ['conversion', 'buffer', 'volunteers'],
        'contacted'   => ['volunteers'],
    ];

    /** Write a new goal's assumptions: what the platform knows, read; the rest, the plan's defaults. @param array<string,string> $given */
    public static function seed(int $goalId, array $given, int $memberId): void
    {
        $g = DB::table('gates_event_goals')->where('id', $goalId)->first();
        if (!$g) return;
        $now = date('Y-m-d H:i:s');
        foreach (self::FOR[(string) $g->metric] ?? [] as $k) {
            [$label, $unit, $default, $reopen] = self::KEYS[$k];
            $source = 'The plan’s default';
            $v = $given[$k] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                $source = 'Given at setup';
            } elseif ($default === null) {
                [$v, $source] = self::read($k, $g);
            } else {
                $v = $default;
            }
            DB::table('gates_event_assumptions')->insert(['event_id' => (int) $g->event_id, 'goal_id' => $goalId, 'akey' => $k, 'label' => $label,
                'value' => mb_substr(trim((string) $v), 0, 64), 'unit' => $unit, 'source' => $source, 'reopen_when' => $reopen,
                'status' => $source === 'The plan’s default' ? 'open' : 'confirmed', 'updated_by' => $memberId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @return array{0:string,1:string} a figure the platform can read, and where it was read from */
    private static function read(string $k, object $g): array
    {
        $eventId = (int) $g->event_id;
        $cats = Goals::categoryIds($eventId, (string) $g->scope_kind, $g->scope_id !== null ? (int) $g->scope_id : null);
        return match ($k) {
            'price' => [(string) PaidVoteService::pricePerVote(), 'The award’s vote price'],
            'nominees' => ($n = Goals::value($eventId, 'nominees', (string) $g->scope_kind, $g->scope_id !== null ? (int) $g->scope_id : null)) > 0
                ? [(string) $n, 'Nominees confirmed so far'] : [(string) max(20, 20 * count($cats)), 'Twenty for each category (the plan’s minimum)'],
            'volunteers' => ($v = Goals::value($eventId, 'volunteers')) > 0 ? [(string) $v, 'Volunteers holding a role at this event'] : ['10', 'The plan’s default'],
            default => ['0', 'Unknown'],
        };
    }

    /** @return array<string,object> a goal's assumptions by key */
    public static function assumptions(int $goalId): array
    {
        $out = [];
        foreach (DB::table('gates_event_assumptions')->where('goal_id', $goalId)->orderBy('id')->get()->all() as $a) $out[(string) $a->akey] = $a;
        return $out;
    }

    /**
     * Correct a figure. A lead's correction applies and is logged; the targets re-solve and the
     * open gates are re-drafted from it. (It moves what each person is asked for, never the goal
     * itself — changing the goal or its target is a large change, Tiers.)
     */
    public static function setAssumption(int $id, string $value, int $memberId): array
    {
        $a = DB::table('gates_event_assumptions')->where('id', $id)->first();
        if (!$a) return ['ok' => false, 'error' => 'That figure is not there.'];
        if (!Plan::may($memberId, (int) $a->event_id, 'plan.edit')) return ['ok' => false, 'error' => 'Only a lead of this event changes the numbers its plan rests on.'];
        $value = trim($value);
        if ($a->unit === 'percent list') {
            $parts = array_map('trim', explode(',', $value));
            if (!$parts || array_filter($parts, static fn ($p) => !is_numeric($p) || (float) $p <= 0) || abs(array_sum(array_map('floatval', $parts)) - 100) > 0.5) {
                return ['ok' => false, 'error' => 'Give one share per week, separated by commas, adding up to 100 — for example 20,35,45.'];
            }
            $value = implode(',', array_map(static fn ($p) => (string) (0 + $p), $parts));
        } elseif ($a->unit !== 'words') {
            $n = Goals::number($value);
            if ($n === null || $n <= 0) return ['ok' => false, 'error' => 'That needs a number above nought.'];
            if ($a->unit === 'percent' && $n > 500) return ['ok' => false, 'error' => 'That share is too high to be right.'];
            $value = (string) $n;
        }
        if ($value === (string) $a->value) return ['ok' => true, 'event' => (int) $a->event_id, 'msg' => 'No change.'];
        DB::table('gates_event_assumptions')->where('id', $id)->update(['value' => mb_substr($value, 0, 64), 'source' => 'Corrected by a lead (was ' . mb_substr((string) $a->value, 0, 40) . ')',
            'status' => 'confirmed', 'updated_by' => $memberId, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($a->goal_id) self::draftGates((int) $a->goal_id);
        return ['ok' => true, 'event' => (int) $a->event_id, 'msg' => 'Changed. Everything below it re-solved, and the open gates moved with it.'];
    }

    private static function num(array $as, string $k, float $fallback): float
    {
        $v = isset($as[$k]) ? (float) $as[$k]->value : 0.0;
        return $v > 0 ? $v : $fallback;
    }

    /** @return list<float> the weekly shares, as fractions summing to 1 */
    public static function curve(array $as): array
    {
        $parts = array_values(array_filter(array_map('floatval', explode(',', (string) ($as['curve']->value ?? '20,35,45'))), static fn ($p) => $p > 0));
        $sum = array_sum($parts) ?: 1;
        return array_map(static fn ($p) => $p / $sum, $parts ?: [1.0]);
    }

    /**
     * Back-solve: from the goal to what each person must do. Each step names the figures it
     * used, so a reader can check it against the assumptions above it.
     *
     * @return list<array{t:string, v:string, from:string}>
     */
    public static function solve(object $g): array
    {
        $as = self::assumptions((int) $g->id);
        $target = (int) $g->target;
        $weeks = max(1, (int) ceil((strtotime((string) $g->deadline) - time()) / (7 * 86400)));
        $steps = [];
        $nominees = null;
        if ($g->metric === 'revenue') {
            $price = self::num($as, 'price', PaidVoteService::DEFAULT_PRICE_NAIRA);
            $votes = (int) ceil($target / $price);
            $nominees = (int) self::num($as, 'nominees', 20);
            $per = (int) ceil($votes / max(1, $nominees));
            $steps[] = ['t' => 'Paid votes needed', 'v' => number_format($votes), 'from' => Goals::fmt('revenue', $target) . ' at ₦' . number_format((int) $price) . ' a vote'];
            $steps[] = ['t' => 'Each nominee averages', 'v' => number_format($per) . ' votes (' . Goals::fmt('revenue', (int) round($per * $price)) . ')',
                        'from' => number_format($votes) . ' votes across ' . number_format($nominees) . ' nominees — a planning figure for the team, never shown to a nominee'];
            $acc = 0.0;
            foreach (self::curve($as) as $i => $share) {
                $acc += $share;
                $steps[] = ['t' => 'Week ' . ($i + 1) . ' of voting', 'v' => Goals::fmt('revenue', (int) round($target * $share)) . ' (' . Goals::fmt('revenue', (int) round($target * $acc)) . ' in all)',
                            'from' => round($share * 100) . '% of the target, from the weekly curve'];
            }
        } elseif (in_array($g->metric, ['nominees', 'nominations', 'converted'], true)) {
            $nominees = $target;
        }
        if ($nominees !== null && isset($as['conversion'])) {
            $conv = self::num($as, 'conversion', 33) / 100;
            $buffer = self::num($as, 'buffer', 120) / 100;
            $prospects = (int) ceil($nominees / max(0.01, $conv) * $buffer);
            $vols = (int) self::num($as, 'volunteers', 10);
            $steps[] = ['t' => 'Prospects to contact', 'v' => number_format($prospects),
                        'from' => number_format($nominees) . ' nominees at ' . round($conv * 100) . '% conversion, with ' . round(($buffer - 1) * 100) . '% extra for withdrawals'];
            $steps[] = ['t' => 'Each volunteer contacts', 'v' => number_format((int) ceil($prospects / max(1, $vols) / $weeks)) . ' a week',
                        'from' => number_format($prospects) . ' prospects, ' . $vols . ' volunteers, ' . $weeks . ' ' . ($weeks === 1 ? 'week' : 'weeks') . ' to the deadline'];
        }
        if ($g->metric === 'contacted') {
            $vols = (int) self::num($as, 'volunteers', 10);
            $steps[] = ['t' => 'Each volunteer contacts', 'v' => number_format((int) ceil($target / max(1, $vols) / $weeks)) . ' a week', 'from' => $vols . ' volunteers, ' . $weeks . ' weeks'];
        }
        return $steps;
    }

    /**
     * Draft (or re-draft) a goal's open gates from its assumptions. Signed gates are history
     * and are never moved.
     */
    public static function draftGates(int $goalId): void
    {
        $g = DB::table('gates_event_goals')->where('id', $goalId)->first();
        if (!$g) return;
        DB::table('gates_event_gates')->where('goal_id', $goalId)->where('status', 'open')->delete();
        $signed = DB::table('gates_event_gates')->where('goal_id', $goalId)->pluck('due_at')->map(static fn ($d) => substr((string) $d, 0, 10))->all();
        $end = strtotime((string) $g->deadline);
        $target = (int) $g->target;
        $rows = [];
        if ($g->metric === 'revenue') {
            $shares = self::curve(self::assumptions($goalId));
            $n = count($shares);
            $acc = 0.0;
            foreach ($shares as $i => $s) {
                $acc += $s;
                $rows[] = ['Week ' . ($i + 1) . ' of voting: ' . round($acc * 100) . '% of the money', $end - ($n - 1 - $i) * 7 * 86400, (int) round($target * $acc), 'owner'];
            }
        } else {
            $start = strtotime((string) $g->created_at);
            if ($end - $start > 14 * 86400) $rows[] = ['Halfway', (int) ($start + ($end - $start) / 2), (int) ceil($target / 2), 'lead'];
            $rows[] = ['The deadline', $end, $target, 'lead'];
        }
        foreach ($rows as [$title, $at, $value, $approver]) {
            $day = date('Y-m-d', (int) $at);
            if (in_array($day, $signed, true)) continue;
            DB::table('gates_event_gates')->insert(['event_id' => (int) $g->event_id, 'goal_id' => $goalId, 'title' => mb_substr($title, 0, 190),
                'due_at' => $day . ' 23:59:59', 'target' => max(1, $value), 'approver' => $approver, 'status' => 'open', 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** Where the plan says the count should be by now: between the gates either side of today. */
    public static function expectedNow(object $g): int
    {
        $gates = DB::table('gates_event_gates')->where('goal_id', (int) $g->id)->orderBy('due_at')->get(['due_at', 'target'])->all();
        if (!$gates) return 0;
        $now = time();
        $prevAt = strtotime((string) $g->created_at);
        // Money only moves once voting opens: the first week starts seven days before its gate,
        // so a revenue goal set during nominations is not "behind" before anybody can pay.
        if ($g->metric === 'revenue') $prevAt = max($prevAt, strtotime((string) $gates[0]->due_at) - 7 * 86400);
        $prevV = 0;
        foreach ($gates as $x) {
            $at = strtotime((string) $x->due_at);
            if ($now <= $at) {
                if ($at <= $prevAt) return (int) $x->target;
                return (int) round($prevV + ((int) $x->target - $prevV) * max(0, ($now - $prevAt)) / ($at - $prevAt));
            }
            $prevAt = $at;
            $prevV = (int) $x->target;
        }
        return (int) $g->target;
    }
}
