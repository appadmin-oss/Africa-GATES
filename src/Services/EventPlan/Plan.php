<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Requests;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE EVENT PLAN: work, risks and budget lines for one event, and the rules that keep them
 * honest (consoles v7 handoff §5; review slice L1, gaps G7–G9). The event lead console reads
 * only this, and every figure it prints comes out of a row here.
 *
 *   · ONLY A LEAD OF THE EVENT CHANGES ITS PLAN. Every writer takes the acting lead (a member) and asks
 *     Grants whether they hold `event.lead` there NOW, so a grant that ended yesterday writes
 *     nothing today, and a crafted post naming another event is refused like a button.
 *   · READINESS IS COUNTED, NEVER TYPED (G7): done ÷ all work. With no work there is no
 *     percentage at all, only the days to go — a bar over nothing is a fake number (§6).
 *   · A RISK'S LEVEL IS COMPUTED from how likely and how bad, so "High" means the same thing
 *     on every screen and nobody can file a likely, high-impact risk as Low.
 *   · A LEAD APPROVES SPEND UP TO THEIR LIMIT, and above it the line becomes a spend REQUEST
 *     (§2.6) that finance decides. The limit is a setting with one reader here. Over-limit is
 *     never a dead end: the answer is "ask", not a disabled button.
 *   · NOTHING HERE MOVES MONEY. A budget line records an estimate, what is committed and what
 *     was paid; the payment itself happens on the existing gateway.
 */
final class Plan
{
    /**
     * Who an event lead IS: a member account, signed in like everybody else (owner, 29 Oct
     * 2026). It was a staff admin, which only works while every lead is on our own staff; an
     * organisation running its own event on this platform brings leads who are not, and must
     * never be handed the admin console to reach their event. The console is /lead, behind the
     * account sign-in, and the role is an `event.lead` grant held by a member.
     */
    public const ACTOR = 'member';

    /** The operator's settings, and their defaults (CONSOLES-V7-REVIEW G9; one reader each). */
    public const RULES = [
        'lead_spend_limit_naira' => 200000,   // a lead approves one line up to this
        'lead_week_hours'        => 40,       // a person's week, for the load on People
    ];

    public const STATUSES = ['todo' => 'Not started', 'doing' => 'In progress', 'done' => 'Done'];
    public const LIKELIHOOD = ['unlikely' => 'Unlikely', 'possible' => 'Possible', 'likely' => 'Likely'];
    public const IMPACT = ['low' => 'Low impact', 'medium' => 'Medium impact', 'high' => 'High impact'];
    public const LINE_STATUSES = ['proposed' => 'Not approved yet', 'approved' => 'Approved', 'requested' => 'With finance', 'declined' => 'Declined'];

    /** @var array<string,int>|null */
    private static ?array $rules = null;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_event_work');
    }

    public static function rule(string $key): int
    {
        if (self::$rules === null) {
            self::$rules = [];
            try {
                foreach (DB::table('gates_settings')->whereIn('key_name', array_keys(self::RULES))->get(['key_name', 'value']) as $r) {
                    $v = trim((string) $r->value);
                    if ($v !== '' && is_numeric($v)) self::$rules[(string) $r->key_name] = max(0, (int) $v);
                }
            } catch (\Throwable) {
                // No settings table is the defaults.
            }
        }
        return self::$rules[$key] ?? (int) (self::RULES[$key] ?? 0);
    }

    public static function resetRules(): void
    {
        self::$rules = null;
    }

    /** The lead's spend limit per line, in kobo. */
    public static function limitMinor(): int
    {
        return self::rule('lead_spend_limit_naira') * 100;
    }

    // ── who ──────────────────────────────────────────────────────────────────────────────

    public static function isLead(int $leadId, int $eventId): bool
    {
        return $leadId > 0 && $eventId > 0 && Grants::allows(self::ACTOR, $leadId, 'event.lead', 'event', $eventId);
    }

    /**
     * The events this member leads right now, soonest first. A grant at the platform covers every
     * event still ahead or within the last month.
     *
     * @return list<object>
     */
    public static function leadEvents(int $leadId): array
    {
        if ($leadId <= 0 || !SchemaHas::table('gates_site_events')) return [];
        $ids = [];
        $all = false;
        foreach (Grants::forActor(self::ACTOR, $leadId, 'event.lead') as $g) {
            if ($g->capability !== 'event.lead') continue;
            if ($g->scope_kind === 'platform') $all = true;
            if ($g->scope_kind === 'event' && $g->scope_id) $ids[(int) $g->scope_id] = true;
        }
        $q = DB::table('gates_site_events');
        if ($all) $q->where('event_date', '>=', date('Y-m-d H:i:s', time() - 30 * 86400));
        elseif ($ids === []) return [];
        else $q->whereIn('id', array_keys($ids));
        return $q->orderBy('event_date')->get()->all();
    }

    public static function event(int $id): ?object
    {
        if ($id <= 0 || !SchemaHas::table('gates_site_events')) return null;
        return DB::table('gates_site_events')->where('id', $id)->first() ?: null;
    }

    /**
     * Who can own work at this event: its leads and the volunteers holding a role there,
     * all from live grants, one row per person.
     *
     * @return list<array{key:string, kind:string, id:int, name:string, role:string}>
     */
    public static function team(int $eventId): array
    {
        if (!Grants::ready()) return [];
        $now = date('Y-m-d H:i:s');
        $rows = DB::table('gates_grants')->where('scope_kind', 'event')->where('scope_id', $eventId)
            ->whereNull('revoked_at')->where('expires_at', '>', $now)
            ->where(static fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->orderBy('id')->get()->all();
        $out = [];
        foreach ($rows as $g) {
            if ($g->capability !== 'event.lead' && !str_starts_with((string) $g->capability, 'vol.')) continue;
            $key = $g->actor_kind . ':' . $g->actor_id;
            if (isset($out[$key])) continue;
            $out[$key] = ['key' => $key, 'kind' => (string) $g->actor_kind, 'id' => (int) $g->actor_id,
                          'name' => self::name((string) $g->actor_kind, (int) $g->actor_id),
                          'role' => Grants::CAPABILITIES[$g->capability] ?? 'Team'];
        }
        return array_values($out);
    }

    public static function name(?string $kind, ?int $id): string
    {
        if (!$kind || !$id) return '';
        $t = $kind === 'admin' ? 'gates_admins' : 'gates_users';
        try {
            return (string) (DB::table($t)->where('id', $id)->value('name') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    // ── work ─────────────────────────────────────────────────────────────────────────────

    /**
     * Add a piece of work. Title required; the owner must be on the event's team (or nobody,
     * which sends it to the leads' inboxes).
     *
     * @param array<string,mixed> $in title, detail, owner ("member:3"), due_at, est_hours, blocks_night, blocks_note
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function addWork(int $eventId, array $in, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!self::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'Say what has to be done.'];
        [$ok, $kind, $oid, $err] = self::owner($eventId, (string) ($in['owner'] ?? ''));
        if (!$ok) return ['ok' => false, 'error' => $err];
        $due = self::when((string) ($in['due_at'] ?? ''));
        if ($due === false) return ['ok' => false, 'error' => 'That due date is not a date.'];
        $hours = (string) ($in['est_hours'] ?? '') === '' ? null : (float) $in['est_hours'];
        if ($hours !== null && ($hours < 0 || $hours > 500)) return ['ok' => false, 'error' => 'Hours must be between 0 and 500.'];
        $blocks = (string) ($in['blocks_night'] ?? '') === '1';
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_event_work')->insertGetId([
            'event_id' => $eventId, 'title' => mb_substr($title, 0, 255), 'detail' => trim((string) ($in['detail'] ?? '')) ?: null,
            'owner_kind' => $kind, 'owner_id' => $oid, 'due_at' => $due, 'est_hours' => $hours, 'status' => 'todo',
            'blocks_night' => $blocks ? 1 : 0, 'blocks_note' => $blocks ? (mb_substr(trim((string) ($in['blocks_note'] ?? '')), 0, 255) ?: null) : null,
            'created_by' => $leadId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    /** Move a piece of work: todo, doing or done. @return array{ok:bool, error?:string, event?:int} */
    public static function setStatus(int $workId, string $status, int $leadId): array
    {
        if (!isset(self::STATUSES[$status])) return ['ok' => false, 'error' => 'That is not a state work can be in.'];
        $w = self::ready() ? DB::table('gates_event_work')->where('id', $workId)->first() : null;
        if (!$w) return ['ok' => false, 'error' => 'That work is not there.'];
        if (!self::isLead($leadId, (int) $w->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        DB::table('gates_event_work')->where('id', $workId)->update([
            'status' => $status, 'done_at' => $status === 'done' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'event' => (int) $w->event_id];
    }

    /** Give a piece of work to somebody else on the team (Rebalance). */
    public static function reassign(int $workId, string $owner, int $leadId): array
    {
        $w = self::ready() ? DB::table('gates_event_work')->where('id', $workId)->first() : null;
        if (!$w) return ['ok' => false, 'error' => 'That work is not there.'];
        if (!self::isLead($leadId, (int) $w->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        [$ok, $kind, $oid, $err] = self::owner((int) $w->event_id, $owner);
        if (!$ok) return ['ok' => false, 'error' => $err];
        DB::table('gates_event_work')->where('id', $workId)->update(['owner_kind' => $kind, 'owner_id' => $oid, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $w->event_id];
    }

    /**
     * Work at these events, soonest due first (undated last).
     *
     * @param list<int> $eventIds
     * @return list<object>
     */
    public static function work(array $eventIds, bool $openOnly = false): array
    {
        if (!self::ready() || $eventIds === []) return [];
        $q = DB::table('gates_event_work')->whereIn('event_id', $eventIds);
        if ($openOnly) $q->where('status', '!=', 'done');
        $rows = $q->orderBy('id')->get()->all();
        usort($rows, static fn ($a, $b) => [$a->due_at === null ? 1 : 0, (string) $a->due_at, $a->id] <=> [$b->due_at === null ? 1 : 0, (string) $b->due_at, $b->id]);
        return $rows;
    }

    public static function isLate(object $w, ?int $now = null): bool
    {
        return $w->status !== 'done' && $w->due_at !== null && strtotime((string) $w->due_at) < ($now ?? time());
    }

    /** @return array{done:int, all:int, pct:int}|null — null when the event has no plan yet */
    public static function readiness(int $eventId): ?array
    {
        if (!self::ready()) return null;
        $all = (int) DB::table('gates_event_work')->where('event_id', $eventId)->count();
        if ($all === 0) return null;
        $done = (int) DB::table('gates_event_work')->where('event_id', $eventId)->where('status', 'done')->count();
        return ['done' => $done, 'all' => $all, 'pct' => (int) floor($done * 100 / $all)];
    }

    /**
     * What blocks a night: open work the night waits on, already late.
     *
     * @param list<int> $eventIds
     * @return list<object>
     */
    public static function blockers(array $eventIds): array
    {
        return array_values(array_filter(self::work($eventIds, true), static fn ($w) => (int) $w->blocks_night === 1 && self::isLate($w)));
    }

    // ── risks ────────────────────────────────────────────────────────────────────────────

    /** high, medium or low, from how likely and how bad. */
    public static function level(string $likelihood, string $impact): string
    {
        $l = ['unlikely' => 1, 'possible' => 2, 'likely' => 3][$likelihood] ?? 2;
        $i = ['low' => 1, 'medium' => 2, 'high' => 3][$impact] ?? 2;
        $s = $l * $i;
        return $s >= 6 ? 'high' : ($s >= 3 ? 'medium' : 'low');
    }

    /** @return array{ok:bool, id?:int, error?:string} */
    public static function addRisk(int $eventId, array $in, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!self::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'Say what could happen.'];
        $l = (string) ($in['likelihood'] ?? '');
        $i = (string) ($in['impact'] ?? '');
        if (!isset(self::LIKELIHOOD[$l]) || !isset(self::IMPACT[$i])) return ['ok' => false, 'error' => 'Say how likely it is and how bad it would be.'];
        [$ok, $kind, $oid, $err] = self::owner($eventId, (string) ($in['owner'] ?? ''));
        if (!$ok) return ['ok' => false, 'error' => $err];
        $id = (int) DB::table('gates_event_risks')->insertGetId([
            'event_id' => $eventId, 'title' => mb_substr($title, 0, 255), 'likelihood' => $l, 'impact' => $i,
            'owner_kind' => $kind, 'owner_id' => $oid, 'plan' => mb_substr(trim((string) ($in['plan'] ?? '')), 0, 255) ?: null,
            'status' => 'open', 'created_by' => $leadId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    public static function closeRisk(int $riskId, int $leadId): array
    {
        $r = self::ready() ? DB::table('gates_event_risks')->where('id', $riskId)->first() : null;
        if (!$r) return ['ok' => false, 'error' => 'That risk is not there.'];
        if (!self::isLead($leadId, (int) $r->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        DB::table('gates_event_risks')->where('id', $riskId)->where('status', 'open')->update(['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $r->event_id];
    }

    /**
     * Open risks at these events, high first, each carrying its computed `level`.
     *
     * @param list<int> $eventIds
     * @return list<object>
     */
    public static function risks(array $eventIds): array
    {
        if (!self::ready() || $eventIds === []) return [];
        $rows = DB::table('gates_event_risks')->whereIn('event_id', $eventIds)->where('status', 'open')->orderBy('id')->get()->all();
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        foreach ($rows as $r) $r->level = self::level((string) $r->likelihood, (string) $r->impact);
        usort($rows, static fn ($a, $b) => [$rank[$a->level], $a->id] <=> [$rank[$b->level], $b->id]);
        return $rows;
    }

    // ── budget ───────────────────────────────────────────────────────────────────────────

    /**
     * Add a line of spend to the plan, not yet approved.
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function addLine(int $eventId, array $in, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!self::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        $title = trim((string) ($in['title'] ?? ''));
        $amount = self::kobo((string) ($in['estimate'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'Say what the money is for.'];
        if ($amount === null || $amount <= 0) return ['ok' => false, 'error' => 'Give the estimate in naira.'];
        $now = date('Y-m-d H:i:s');
        $row = ['event_id' => $eventId, 'title' => mb_substr($title, 0, 255), 'estimate_minor' => $amount, 'status' => 'proposed',
                'proposed_kind' => self::ACTOR, 'proposed_id' => $leadId, 'created_at' => $now, 'updated_at' => $now];
        // Who is paid, for Money › Vendors; the column arrives with 2027_04_14_event_scope.
        if (SchemaHas::column('gates_event_budget', 'vendor')) $row['vendor'] = mb_substr(trim((string) ($in['vendor'] ?? '')), 0, 190) ?: null;
        $id = (int) DB::table('gates_event_budget')->insertGetId($row);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    /** Approve a line at or under the lead's limit. Above it the answer is askLine(). */
    public static function approveLine(int $lineId, int $leadId): array
    {
        $l = self::ready() ? DB::table('gates_event_budget')->where('id', $lineId)->first() : null;
        if (!$l) return ['ok' => false, 'error' => 'That line is not there.'];
        if (!self::isLead($leadId, (int) $l->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can approve its spend.'];
        if ($l->status !== 'proposed') return ['ok' => false, 'error' => 'That line has already been decided.'];
        if ((int) $l->estimate_minor > self::limitMinor()) {
            return ['ok' => false, 'error' => 'That is over your limit of ' . self::naira(self::limitMinor()) . '. Ask finance instead.'];
        }
        $n = DB::table('gates_event_budget')->where('id', $lineId)->where('status', 'proposed')->update([
            'status' => 'approved', 'approved_kind' => self::ACTOR, 'approved_id' => $leadId,
            'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $n > 0 ? ['ok' => true, 'event' => (int) $l->event_id] : ['ok' => false, 'error' => 'Somebody else answered this a moment ago.'];
    }

    /**
     * Send a line to finance as a spend request (§2.6). Why is required, as on every request.
     *
     * @return array{ok:bool, request?:int, error?:string}
     */
    public static function askLine(int $lineId, int $leadId, string $leadName, string $why, string $by = ''): array
    {
        $l = self::ready() ? DB::table('gates_event_budget')->where('id', $lineId)->first() : null;
        if (!$l) return ['ok' => false, 'error' => 'That line is not there.'];
        if (!self::isLead($leadId, (int) $l->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can ask for its spend.'];
        if (!in_array($l->status, ['proposed', 'declined'], true)) return ['ok' => false, 'error' => 'That line is already approved or with finance.'];
        $r = Requests::create('lead', 'spend', self::ACTOR, $leadId, $leadName,
            ['title' => (string) $l->title, 'amount' => (string) intdiv((int) $l->estimate_minor, 100), 'by' => $by, 'why' => $why],
            'event', (int) $l->event_id);
        if (!$r['ok']) return $r;
        DB::table('gates_event_budget')->where('id', $lineId)->update(['status' => 'requested', 'request_id' => $r['id'], 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'request' => (int) $r['id'], 'event' => (int) $l->event_id];
    }

    /**
     * Bring a line in step with its request after the request moved — approved, declined,
     * withdrawn, or an undo. The request is the record; the line follows it.
     */
    public static function syncRequest(int $requestId): void
    {
        if (!self::ready() || $requestId <= 0) return;
        $r = Requests::find($requestId);
        if (!$r) return;
        $line = match ((string) $r->status) {
            'approved' => ['status' => 'approved', 'approved_kind' => 'role', 'approved_id' => null, 'approved_at' => $r->decided_at ?? date('Y-m-d H:i:s')],
            'declined' => ['status' => 'declined', 'approved_kind' => null, 'approved_at' => null],
            'withdrawn' => ['status' => 'proposed', 'request_id' => null, 'approved_kind' => null, 'approved_at' => null],
            default => ['status' => 'requested', 'approved_kind' => null, 'approved_at' => null],
        };
        DB::table('gates_event_budget')->where('request_id', $requestId)->update($line + ['updated_at' => date('Y-m-d H:i:s')]);
    }

    /** Record what is committed and what has been paid on a line. */
    public static function setSpend(int $lineId, string $committed, string $paid, int $leadId): array
    {
        $l = self::ready() ? DB::table('gates_event_budget')->where('id', $lineId)->first() : null;
        if (!$l) return ['ok' => false, 'error' => 'That line is not there.'];
        if (!self::isLead($leadId, (int) $l->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its plan.'];
        if ($l->status !== 'approved') return ['ok' => false, 'error' => 'Only approved spend can be committed or paid.'];
        $c = self::kobo($committed);
        $p = self::kobo($paid);
        if (($committed !== '' && $c === null) || ($paid !== '' && $p === null)) return ['ok' => false, 'error' => 'Give the figures in naira.'];
        DB::table('gates_event_budget')->where('id', $lineId)->update([
            'committed_minor' => $c ?? (int) $l->committed_minor, 'paid_minor' => $p ?? (int) $l->paid_minor, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'event' => (int) $l->event_id];
    }

    /** @param list<int> $eventIds @return list<object> */
    public static function lines(array $eventIds): array
    {
        if (!self::ready() || $eventIds === []) return [];
        return DB::table('gates_event_budget')->whereIn('event_id', $eventIds)->orderBy('id')->get()->all();
    }

    /** What the event will spend, as far as anybody knows: each live line's largest figure. */
    public static function forecastMinor(int $eventId): ?int
    {
        $lines = array_filter(self::lines([$eventId]), static fn ($l) => $l->status !== 'declined');
        if ($lines === []) return null;
        $sum = 0;
        foreach ($lines as $l) $sum += max((int) $l->estimate_minor, (int) $l->committed_minor, (int) $l->paid_minor);
        return $sum;
    }

    /** "Budget forecast ₦420,000 over." / "On budget." / "Budget forecast ₦6.78m." / null */
    public static function budgetWords(object $event): ?string
    {
        $f = self::forecastMinor((int) $event->id);
        if ($f === null) return null;
        $budget = isset($event->budget_minor) && $event->budget_minor !== null ? (int) $event->budget_minor : null;
        if ($budget === null || $budget <= 0) return 'Budget forecast ' . self::naira($f) . '.';
        return $f > $budget ? 'Budget forecast ' . self::naira($f - $budget) . ' over.' : 'On budget.';
    }

    /** The event's budget and series (its settings on the lead's Overview). */
    public static function setEventSettings(int $eventId, string $budget, string $series, int $leadId): array
    {
        if (!self::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its settings.'];
        if (!SchemaHas::column('gates_site_events', 'series_key')) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $b = self::kobo($budget);
        if ($budget !== '' && $b === null) return ['ok' => false, 'error' => 'Give the budget in naira.'];
        // One spelling per series, so "Alimosho Ceremony" and "alimosho-ceremony " are one series.
        $key = Slug::make($series, 64);
        DB::table('gates_site_events')->where('id', $eventId)->update(['budget_minor' => $b ?: null, 'series_key' => $key !== '' ? $key : null]);
        return ['ok' => true, 'event' => $eventId];
    }

    // ── people ───────────────────────────────────────────────────────────────────────────

    /**
     * Each owner's open work due within the week (late work included), across these events —
     * the hours they are carrying against a week of `lead_week_hours`.
     *
     * @param list<int> $eventIds
     * @return list<array{key:string, kind:string, id:int, name:string, hours:float, items:int, events:list<int>}>
     */
    public static function load(array $eventIds, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        foreach (self::work($eventIds, true) as $w) {
            if (!$w->owner_kind || !$w->owner_id) continue;
            if ($w->due_at !== null && strtotime((string) $w->due_at) > $now + 7 * 86400) continue;
            $k = $w->owner_kind . ':' . $w->owner_id;
            $out[$k] ??= ['key' => $k, 'kind' => (string) $w->owner_kind, 'id' => (int) $w->owner_id,
                          'name' => self::name((string) $w->owner_kind, (int) $w->owner_id), 'hours' => 0.0, 'items' => 0, 'events' => []];
            $out[$k]['hours'] += (float) ($w->est_hours ?? 0);
            $out[$k]['items']++;
            $out[$k]['events'][(int) $w->event_id] = (int) $w->event_id;
        }
        foreach ($out as &$o) $o['events'] = array_values($o['events']);
        unset($o);
        $out = array_values($out);
        usort($out, static fn ($a, $b) => [$b['hours'], $a['name']] <=> [$a['hours'], $b['name']]);
        return $out;
    }


    // ── analytics ────────────────────────────────────────────────────────────────────────

    /** The previous edition of this event's series, if there is one. */
    public static function previousEdition(object $event): ?object
    {
        $key = trim((string) ($event->series_key ?? ''));
        if ($key === '') return null;
        return DB::table('gates_site_events')->where('series_key', $key)->where('event_date', '<', $event->event_date)
            ->orderByDesc('event_date')->first() ?: null;
    }

    /** Paid or free places confirmed by a moment (the analytics compare like with like). */
    public static function ticketsBy(int $eventId, string $at): int
    {
        try {
            return (int) DB::table('gates_event_registrations')->where('event_id', $eventId)->where('status', 'confirmed')
                ->where('created_at', '<=', $at)->sum(DB::raw('COALESCE(quantity, 1)'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** What the plan forecast by a moment: the lines that existed then, at their estimate. */
    public static function forecastBy(int $eventId, string $at): ?int
    {
        if (!self::ready()) return null;
        $rows = DB::table('gates_event_budget')->where('event_id', $eventId)->where('created_at', '<=', $at)->where('status', '!=', 'declined')->get(['estimate_minor'])->all();
        if ($rows === []) return null;
        return array_sum(array_map(static fn ($r) => (int) $r->estimate_minor, $rows));
    }

    // ── words ────────────────────────────────────────────────────────────────────────────

    public static function naira(int $minor): string
    {
        $n = intdiv($minor, 100);
        if ($n >= 1000000) return '₦' . rtrim(rtrim(number_format($n / 1000000, 2), '0'), '.') . 'm';
        return '₦' . number_format($n);
    }

    /** "450,000" or "₦450,000" → kobo; '' → null; nonsense → null. */
    public static function kobo(string $naira): ?int
    {
        $t = trim(str_replace([',', '₦', ' '], '', $naira));
        if ($t === '' || !is_numeric($t) || (float) $t < 0) return null;
        return (int) round((float) $t * 100);
    }

    /** @return string|null|false a stored datetime, null for empty, false for nonsense */
    private static function when(string $s): string|null|false
    {
        $s = trim($s);
        if ($s === '') return null;
        // A date with no time is due by the end of that day, not at midnight before it.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= ' 23:59:59';
        $t = strtotime($s);
        return $t === false ? false : date('Y-m-d H:i:s', $t);
    }

    /** @return array{0:bool, 1:?string, 2:?int, 3:string} */
    private static function owner(int $eventId, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') return [true, null, null, ''];
        foreach (self::team($eventId) as $p) if ($p['key'] === $owner) return [true, $p['kind'], $p['id'], ''];
        return [false, null, null, 'Pick somebody on this event’s team.'];
    }
}
