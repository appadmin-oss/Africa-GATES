<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\EventTicketService;
use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * GOALS THAT COUNT THEMSELVES (owner, 5 Nov 2026, "Event and Project Management: Design Plan":
 * "keeps contacts and tasks in one place, so progress counts itself").
 *
 *   · A GOAL IS A METRIC THIS PLATFORM ALREADY MEASURES — nominations submitted, nominees
 *     confirmed, votes, paid-vote revenue, tickets, volunteers, the pipeline — so its progress
 *     is read, never typed. Nobody updates a percentage; the count moves when the thing happens.
 *     The feature READS votes and money and never writes to them (plan: "the other modules stay
 *     the system of record").
 *   · NO TASKS WITHOUT A CLEAR GOAL (plan AT-1). A goal needs its metric, a target and a
 *     deadline; one missing any of them is refused with the question it needs answered.
 *   · GATES SIGN OFF ON THE DATA. A gate is a dated checkpoint with the value it must reach and
 *     who signs it. It can be signed off as passed only when the count shows it; otherwise the
 *     mismatch is shown and it can only be recorded as missed, with a reason (plan, Gate review).
 *   · EVERY UNMEASURED FIGURE IS AN ASSUMPTION ({@see Planner}), shown and editable, and the
 *     targets below it re-solve when it changes.
 *
 * A goal on the money is never shown to a nominee. The plan's own principle — "people being
 * honoured are never asked for quotas" — means a per-nominee figure is the team's planning
 * number, drawn only inside this console.
 */
final class Goals
{
    /** What a goal can measure: label, unit, and whether it needs an award to count within. */
    public const METRICS = [
        'nominations' => ['Nominations submitted', 'count', true],
        'nominees'    => ['Nominees confirmed', 'count', true],
        'votes'       => ['Votes cast', 'count', true],
        'revenue'     => ['Paid-vote revenue', 'naira', true],
        'tickets'     => ['Tickets sold', 'count', false],
        'volunteers'  => ['Volunteers with a role', 'count', false],
        'contacted'   => ['Prospects contacted', 'count', false],
        'converted'   => ['Prospects who submitted a nomination', 'count', false],
    ];

    public static function ready(): bool
    {
        return Plan::ready() && SchemaHas::table('gates_event_goals') && SchemaHas::table('gates_event_contacts');
    }

    // ── where a goal counts ────────────────────────────────────────────────────────────

    /**
     * The award editions this event presents (its programmes' latest edition each) and their
     * categories, for a goal's scope. @return array<string,string> 'event' | 'cycle:ID' | 'category:ID' => words
     */
    public static function scopes(int $eventId): array
    {
        $out = ['event' => 'Every award this event presents'];
        foreach (self::cycles($eventId) as $c) {
            $out['cycle:' . $c->id] = $c->programme . ' ' . $c->year;
            foreach (DB::table('gates_award_categories')->where('cycle_id', $c->id)->orderBy('sort_order')->orderBy('id')->get(['id', 'title']) as $cat) {
                $out['category:' . $cat->id] = $c->programme . ' · ' . $cat->title;
            }
        }
        return $out;
    }

    /** @return list<object> each with ->id, ->year, ->programme */
    public static function cycles(int $eventId): array
    {
        if (!SchemaHas::table('gates_event_programmes')) return [];
        $out = [];
        foreach (DB::table('gates_event_programmes as ep')->join('gates_award_programmes as p', 'p.id', '=', 'ep.programme_id')
                     ->join('gates_award_cycles as c', 'c.programme_id', '=', 'p.id')->where('ep.event_id', $eventId)
                     ->orderByDesc('c.year')->orderByDesc('c.id')->get(['c.id', 'c.year', 'p.title as programme', 'p.id as pid']) as $c) {
            if (!isset($out[(int) $c->pid])) $out[(int) $c->pid] = $c;   // each programme's latest edition
        }
        return array_values($out);
    }

    /** @return list<int> the categories a scope counts within */
    public static function categoryIds(int $eventId, string $kind, ?int $id): array
    {
        if ($kind === 'category' && $id) return [$id];
        $cycles = $kind === 'cycle' && $id ? [$id] : array_map(static fn ($c) => (int) $c->id, self::cycles($eventId));
        if ($cycles === []) return [];
        return DB::table('gates_award_categories')->whereIn('cycle_id', $cycles)->pluck('id')->map(static fn ($i) => (int) $i)->all();
    }

    // ── counting ───────────────────────────────────────────────────────────────────────

    /**
     * The metric's value now (or as it stood at $until), read from the module that owns it.
     * Money is in naira.
     */
    public static function value(int $eventId, string $metric, string $kind = 'event', ?int $id = null, ?string $until = null): int
    {
        $cats = self::METRICS[$metric][2] ?? false ? self::categoryIds($eventId, $kind, $id) : [];
        if ((self::METRICS[$metric][2] ?? false) && $cats === []) return 0;
        $by = static function ($q, string $col) use ($until) { if ($until !== null) $q->where($col, '<=', $until); return $q; };
        try {
            return match ($metric) {
                'nominations' => (int) $by(DB::table('gates_nominations')->whereIn('category_id', $cats)->where('status', '!=', 'rejected')->whereNull('merged_into'), 'created_at')->count(),
                'nominees'    => (int) DB::table('gates_nominees')->whereIn('category_id', $cats)->where('status', 'approved')->whereNull('merged_into')->count(),
                'votes'       => (int) DB::table('gates_nominees')->whereIn('category_id', $cats)->whereNull('merged_into')->sum('vote_count'),
                'revenue'     => self::revenue($cats, $until),
                'tickets'     => EventTicketService::soldForEvent($eventId),
                'volunteers'  => (int) DB::table('gates_grants')->where('scope_kind', 'event')->where('scope_id', $eventId)->where('capability', 'like', 'vol.%')
                                     ->whereNull('revoked_at')->where('expires_at', '>', date('Y-m-d H:i:s'))->distinct()->count('actor_id'),
                'contacted'   => (int) self::contactsIn($eventId, $kind, $id)->whereIn('stage', ['contacted', 'yes', 'nominated'])->count(),
                'converted'   => (int) self::contactsIn($eventId, $kind, $id)->where('stage', 'nominated')->count(),
                default       => 0,
            };
        } catch (\Throwable) {
            return 0;   // a module not installed here counts nothing, and says so on the goal
        }
    }

    /** Confirmed paid-vote money for nominees in these categories, refunds out (PartnerOrg's rule: `confirmed` alone counts refunded money). */
    private static function revenue(array $cats, ?string $until): int
    {
        $q = DB::table('gates_donations as d')->join('gates_nominees as n', 'n.id', '=', 'd.intent_nominee_id')
            ->whereIn('n.category_id', $cats)->where('d.status', 'confirmed')->where('d.tier', 'paid-vote');
        if (SchemaHas::column('gates_donations', 'refunded_at')) $q->whereNull('d.refunded_at');
        if ($until !== null) $q->where('d.created_at', '<=', $until);
        return (int) $q->sum('d.amount_naira');
    }

    private static function contactsIn(int $eventId, string $kind, ?int $id)
    {
        $q = DB::table('gates_event_contacts')->where('event_id', $eventId);
        if ($kind !== 'event') $q->whereIn('category_id', self::categoryIds($eventId, $kind, $id));
        return $q;
    }

    // ── goals ──────────────────────────────────────────────────────────────────────────

    /**
     * Set a goal. Refused, with the questions it needs, unless it has a metric, a target and
     * a deadline (plan AT-1). Its gates and assumptions are drafted with it ({@see Planner}).
     *
     * @return array{ok:bool, error?:string, id?:int, event?:int, msg?:string}
     */
    public static function create(int $eventId, array $in, int $memberId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::may($memberId, $eventId, 'plan.edit')) return ['ok' => false, 'error' => 'Only a lead of this event sets its goals.'];
        $metric = (string) ($in['metric'] ?? '');
        $target = self::number((string) ($in['target'] ?? ''));
        $deadline = trim((string) ($in['deadline'] ?? ''));
        $ask = [];
        if (!isset(self::METRICS[$metric])) $ask[] = 'what should be counted';
        if ($target === null || $target <= 0) $ask[] = 'how many (or how much) by the deadline';
        if ($deadline === '' || strtotime($deadline) === false) $ask[] = 'by when';
        if ($ask) return ['ok' => false, 'error' => 'A goal needs ' . implode(', ', $ask) . ' before anything is planned from it.'];
        [$kind, $sid] = self::scopeOf((string) ($in['scope'] ?? 'event'), $eventId);
        if ((self::METRICS[$metric][2]) && self::categoryIds($eventId, $kind, $sid) === []) {
            return ['ok' => false, 'error' => 'This event presents no award yet, so there is nothing for that to count. Link its award first, in the event’s setup.'];
        }
        $title = trim((string) ($in['title'] ?? '')) ?: self::METRICS[$metric][0] . ': ' . self::fmt($metric, $target);
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_event_goals')->insertGetId([
            'event_id' => $eventId, 'title' => mb_substr($title, 0, 190), 'metric' => $metric, 'scope_kind' => $kind, 'scope_id' => $sid,
            'target' => $target, 'deadline' => date('Y-m-d 23:59:59', (int) strtotime($deadline)), 'owner_id' => $memberId,
            'status' => 'active', 'created_by' => $memberId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Planner::seed($id, (array) ($in['assume'] ?? []), $memberId);
        Planner::draftGates($id);
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Goal set. Its gates and the numbers it rests on are drafted below; change any number and the targets re-solve.'];
    }

    /** @return array{0:string,1:?int} */
    public static function scopeOf(string $scope, int $eventId): array
    {
        if (preg_match('/^(cycle|category):(\d+)$/', $scope, $m) && isset(self::scopes($eventId)[$scope])) return [$m[1], (int) $m[2]];
        return ['event', null];
    }

    /** @return list<object> the event's goals, each with ->value, ->pct, ->expected, ->words */
    public static function forEvent(int $eventId): array
    {
        if (!self::ready()) return [];
        $out = [];
        foreach (DB::table('gates_event_goals')->where('event_id', $eventId)->where('status', '!=', 'archived')->orderBy('deadline')->get()->all() as $g) {
            $out[] = self::measure($g);
        }
        return $out;
    }

    public static function find(int $goalId): ?object
    {
        if (!self::ready()) return null;
        $g = DB::table('gates_event_goals')->where('id', $goalId)->first();
        return $g ? self::measure($g) : null;
    }

    /** Progress against plan: the count, its share of the target, and where the plan says it should be by now. */
    public static function measure(object $g): object
    {
        $g->value = self::value((int) $g->event_id, (string) $g->metric, (string) $g->scope_kind, $g->scope_id !== null ? (int) $g->scope_id : null);
        $g->pct = (int) $g->target > 0 ? (int) min(100, floor($g->value * 100 / (int) $g->target)) : 0;
        $g->expected = Planner::expectedNow($g);
        $g->behind = $g->expected > 0 && $g->value < $g->expected * 0.8;   // plan rule 1: 20% behind plan
        $left = (int) floor((strtotime((string) $g->deadline) - time()) / 86400);
        $g->words = self::fmt((string) $g->metric, $g->value) . ' of ' . self::fmt((string) $g->metric, (int) $g->target)
                  . ($left >= 0 ? ' · ' . ($left === 0 ? 'due today' : $left . ' ' . ($left === 1 ? 'day' : 'days') . ' left') : ' · deadline passed')
                  . ($g->expected > 0 ? ' · the plan expects ' . self::fmt((string) $g->metric, $g->expected) . ' by now' : '');
        return $g;
    }

    // ── gates ──────────────────────────────────────────────────────────────────────────

    /** @return list<object> a goal's gates, soonest first, each with ->value (the count as of its date, or now) and ->state */
    public static function gates(int $goalId): array
    {
        if (!self::ready()) return [];
        $g = DB::table('gates_event_goals')->where('id', $goalId)->first();
        if (!$g) return [];
        $out = [];
        foreach (DB::table('gates_event_gates')->where('goal_id', $goalId)->orderBy('due_at')->get()->all() as $x) {
            $past = strtotime((string) $x->due_at) < time();
            $x->value = $x->signed_value !== null ? (int) $x->signed_value
                : self::value((int) $g->event_id, (string) $g->metric, (string) $g->scope_kind, $g->scope_id !== null ? (int) $g->scope_id : null, $past ? (string) $x->due_at : null);
            $x->met = $x->value >= (int) $x->target;
            $x->state = match (true) {
                $x->status === 'passed' => 'passed', $x->status === 'missed' => 'missed',
                $x->met => 'ready', $past => 'late', strtotime((string) $x->due_at) < time() + 3 * 86400 => 'threat', default => 'open',
            };
            $x->metric = (string) $g->metric;
            $out[] = $x;
        }
        return $out;
    }

    /**
     * Sign a gate off. Passed only when the count shows the target reached; a mismatch is
     * refused with the figures, never corrected (plan, Gate review). A gate on the money is an
     * owner's to sign; any other a lead's.
     */
    public static function signGate(int $gateId, int $memberId, string $verdict, string $note): array
    {
        $x = self::ready() ? DB::table('gates_event_gates')->where('id', $gateId)->first() : null;
        if (!$x || $x->status !== 'open') return ['ok' => false, 'error' => 'That gate has already been signed off.'];
        $eventId = (int) $x->event_id;
        // A money gate is the finance admin's (or an owner's); any other a lead's.
        $may = $x->approver === 'owner'
            ? Teams::isOwner($memberId, $eventId) || \AfricaGates\Services\Grants::allows(Plan::ACTOR, $memberId, 'event.finance', 'event', $eventId)
            : Plan::may($memberId, $eventId, 'plan.edit');
        if (!$may) return ['ok' => false, 'error' => $x->approver === 'owner' ? 'The event’s finance admin or an owner signs this gate off: it is about money.' : 'A lead of this event signs this gate off.'];
        $gate = null;
        foreach (self::gates((int) $x->goal_id) as $y) if ((int) $y->id === $gateId) $gate = $y;
        if (!$gate) return ['ok' => false, 'error' => 'That gate is not there.'];
        $note = trim($note);
        if ($verdict === 'passed' && !$gate->met) {
            return ['ok' => false, 'error' => 'The count shows ' . self::fmt($gate->metric, $gate->value) . ' of ' . self::fmt($gate->metric, (int) $x->target)
                . ', so it cannot be signed off as passed. Record it as missed, with what happens next.'];
        }
        if ($verdict === 'missed' && $note === '') return ['ok' => false, 'error' => 'Say what happens next, so the team knows.'];
        if (!in_array($verdict, ['passed', 'missed'], true)) return ['ok' => false, 'error' => 'Passed or missed?'];
        DB::table('gates_event_gates')->where('id', $gateId)->where('status', 'open')->update(['status' => $verdict, 'signed_by' => $memberId,
            'signed_at' => date('Y-m-d H:i:s'), 'signed_value' => $gate->value, 'signed_note' => $note !== '' ? mb_substr($note, 0, 255) : null]);
        return ['ok' => true, 'event' => $eventId, 'msg' => $verdict === 'passed' ? 'Signed off, with the count as it stood: ' . self::fmt($gate->metric, $gate->value) . '.' : 'Recorded as missed, with your note.'];
    }

    // ── words ──────────────────────────────────────────────────────────────────────────

    public static function fmt(string $metric, int $n): string
    {
        if ((self::METRICS[$metric][1] ?? '') === 'naira') return $n >= 1_000_000 ? '₦' . rtrim(rtrim(number_format($n / 1_000_000, 2), '0'), '.') . 'M' : '₦' . number_format($n);
        return number_format($n);
    }

    /** "15M", "₦15,000,000", "2.5k", "60" → a whole number; null when it is not one. */
    public static function number(string $s): ?int
    {
        $s = strtolower(str_replace([',', '₦', 'naira', 'ngn', ' '], '', trim($s)));
        if (!preg_match('/^(\d+(?:\.\d+)?)(k|m|million|thousand|bn|b)?$/', $s, $m)) return null;
        $mul = match ($m[2] ?? '') { 'k', 'thousand' => 1_000, 'm', 'million' => 1_000_000, 'b', 'bn' => 1_000_000_000, default => 1 };
        return (int) round((float) $m[1] * $mul);
    }

    public static function day(?string $at): string
    {
        return Workspace::day($at);
    }
}
