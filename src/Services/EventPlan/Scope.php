<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE REST OF AN EVENT'S RECORD, for its lead (consoles v7 §5; `nav-admin.json` scope
 * `event`): decisions, the run of show, sponsors and their contacts, the safety checklist and
 * the seating plan. Plan holds work, risks and money; this holds everything else a lead
 * writes, under the same rule.
 *
 *   · ONLY A LEAD OF THE EVENT WRITES, checked against the grant on the row's own event every
 *     time (Plan::isLead), never against the page somebody was on.
 *   · A DECISION IS NEVER EDITED. It is reversed by a stamp with a reason, so the record of
 *     what was agreed, and when it stopped being agreed, survives a change of mind.
 *   · A TABLE'S SEATS ARE A REFUSAL, not a warning: a guest list longer than the table is
 *     refused and the number over is named.
 */
final class Scope
{
    public const STAGES = ['prospect' => 'Prospect', 'asked' => 'Asked', 'agreed' => 'Agreed', 'paid' => 'Paid', 'declined' => 'Declined'];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_event_decisions');
    }

    // ── decisions ───────────────────────────────────────────────────────────────────────

    public static function decide(int $eventId, string $title, string $why, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can record its decisions.'];
        $title = trim($title);
        if ($title === '') return ['ok' => false, 'error' => 'Say what was decided.'];
        $id = (int) DB::table('gates_event_decisions')->insertGetId([
            'event_id' => $eventId, 'title' => mb_substr($title, 0, 255), 'why' => trim($why) ?: null,
            'decided_kind' => Plan::ACTOR, 'decided_id' => $leadId, 'decided_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    public static function reverse(int $id, string $why, int $leadId): array
    {
        $d = self::ready() ? DB::table('gates_event_decisions')->where('id', $id)->first() : null;
        if (!$d) return ['ok' => false, 'error' => 'That decision is not there.'];
        if (!Plan::isLead($leadId, (int) $d->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can reverse its decisions.'];
        if (trim($why) === '') return ['ok' => false, 'error' => 'Say why it no longer stands.'];
        $n = DB::table('gates_event_decisions')->where('id', $id)->whereNull('reversed_at')
            ->update(['reversed_at' => date('Y-m-d H:i:s'), 'reversed_by' => $leadId, 'reversed_why' => mb_substr(trim($why), 0, 255)]);
        return $n > 0 ? ['ok' => true, 'event' => (int) $d->event_id] : ['ok' => false, 'error' => 'That decision was already reversed.'];
    }

    /** @return list<object> newest first */
    public static function decisions(int $eventId): array
    {
        return self::ready() ? DB::table('gates_event_decisions')->where('event_id', $eventId)->orderByDesc('decided_at')->orderByDesc('id')->get()->all() : [];
    }

    // ── run of show ─────────────────────────────────────────────────────────────────────

    /**
     * Add a cue. A bare time ("18:30") is on the night itself, which is how people write a
     * running order; a full date and time is taken as given.
     */
    public static function addCue(int $eventId, array $in, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its running order.'];
        $e = Plan::event($eventId);
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || !$e) return ['ok' => false, 'error' => 'Say what happens.'];
        $at = trim((string) ($in['at'] ?? ''));
        if (preg_match('/^\d{1,2}:\d{2}$/', $at)) $at = date('Y-m-d', (int) strtotime((string) $e->event_date)) . ' ' . $at . ':00';
        $t = $at === '' ? false : strtotime($at);
        if ($t === false) return ['ok' => false, 'error' => 'Give the time it starts, like 18:30.'];
        $mins = (int) ($in['minutes'] ?? 5);
        if ($mins < 1 || $mins > 600) return ['ok' => false, 'error' => 'A cue runs between 1 and 600 minutes.'];
        [$ok, $kind, $oid, $err] = self::owner($eventId, (string) ($in['owner'] ?? ''));
        if (!$ok) return ['ok' => false, 'error' => $err];
        $id = (int) DB::table('gates_event_show')->insertGetId([
            'event_id' => $eventId, 'starts_at' => date('Y-m-d H:i:s', $t), 'minutes' => $mins, 'title' => mb_substr($title, 0, 255),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255) ?: null, 'owner_kind' => $kind, 'owner_id' => $oid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    /** Mark a cue done (on the night), or take it back. */
    public static function cueDone(int $id, bool $done, int $leadId): array
    {
        $c = self::ready() ? DB::table('gates_event_show')->where('id', $id)->first() : null;
        if (!$c) return ['ok' => false, 'error' => 'That cue is not there.'];
        if (!Plan::isLead($leadId, (int) $c->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can run its show.'];
        DB::table('gates_event_show')->where('id', $id)->update(['done_at' => $done ? date('Y-m-d H:i:s') : null]);
        return ['ok' => true, 'event' => (int) $c->event_id];
    }

    public static function removeCue(int $id, int $leadId): array
    {
        $c = self::ready() ? DB::table('gates_event_show')->where('id', $id)->first() : null;
        if (!$c) return ['ok' => false, 'error' => 'That cue is not there.'];
        if (!Plan::isLead($leadId, (int) $c->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its running order.'];
        if ($c->done_at) return ['ok' => false, 'error' => 'That cue has happened, so it stays in the record.'];
        DB::table('gates_event_show')->where('id', $id)->delete();
        return ['ok' => true, 'event' => (int) $c->event_id];
    }

    /** @return list<object> in time order */
    public static function show(int $eventId): array
    {
        return self::ready() ? DB::table('gates_event_show')->where('event_id', $eventId)->orderBy('starts_at')->orderBy('id')->get()->all() : [];
    }

    /** The cue happening now: the first one not done whose start has come, else null. */
    public static function nowCue(array $cues, ?int $now = null): ?object
    {
        $now ??= time();
        foreach ($cues as $c) if (!$c->done_at && strtotime((string) $c->starts_at) <= $now) return $c;
        return null;
    }

    // ── sponsors ────────────────────────────────────────────────────────────────────────

    public static function addSponsor(int $eventId, array $in, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its sponsors.'];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => 'Name the sponsor.'];
        $stage = (string) ($in['stage'] ?? 'prospect');
        if (!isset(self::STAGES[$stage])) return ['ok' => false, 'error' => 'That is not a stage a sponsor can be at.'];
        $value = (string) ($in['value'] ?? '') === '' ? 0 : Plan::kobo((string) $in['value']);
        if ($value === null) return ['ok' => false, 'error' => 'Give the value in naira.'];
        $email = trim((string) ($in['contact_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'That email address does not look right.'];
        [$ok, $kind, $oid, $err] = self::owner($eventId, (string) ($in['owner'] ?? ''));
        if (!$ok) return ['ok' => false, 'error' => $err];
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_event_sponsors')->insertGetId([
            'event_id' => $eventId, 'name' => mb_substr($name, 0, 190), 'stage' => $stage, 'value_minor' => $value,
            'benefits' => mb_substr(trim((string) ($in['benefits'] ?? '')), 0, 255) ?: null,
            'contact_name' => mb_substr(trim((string) ($in['contact_name'] ?? '')), 0, 190) ?: null, 'contact_email' => $email ?: null,
            'contact_phone' => mb_substr(trim((string) ($in['contact_phone'] ?? '')), 0, 190) ?: null,
            'owner_kind' => $kind, 'owner_id' => $oid, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    public static function setStage(int $id, string $stage, int $leadId): array
    {
        $s = self::ready() ? DB::table('gates_event_sponsors')->where('id', $id)->first() : null;
        if (!$s) return ['ok' => false, 'error' => 'That sponsor is not there.'];
        if (!Plan::isLead($leadId, (int) $s->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its sponsors.'];
        if (!isset(self::STAGES[$stage])) return ['ok' => false, 'error' => 'That is not a stage a sponsor can be at.'];
        DB::table('gates_event_sponsors')->where('id', $id)->update(['stage' => $stage, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $s->event_id];
    }

    /** @return list<object> by stage, then name */
    public static function sponsors(int $eventId): array
    {
        if (!self::ready()) return [];
        $rows = DB::table('gates_event_sponsors')->where('event_id', $eventId)->orderBy('name')->get()->all();
        $rank = array_flip(['paid', 'agreed', 'asked', 'prospect', 'declined']);
        usort($rows, static fn ($a, $b) => [$rank[$a->stage] ?? 9, $a->name] <=> [$rank[$b->stage] ?? 9, $b->name]);
        return $rows;
    }

    // ── safety checks ───────────────────────────────────────────────────────────────────

    public static function addCheck(int $eventId, string $title, string $owner, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its checks.'];
        if (trim($title) === '') return ['ok' => false, 'error' => 'Say what has to be checked.'];
        [$ok, $kind, $oid, $err] = self::owner($eventId, $owner);
        if (!$ok) return ['ok' => false, 'error' => $err];
        $id = (int) DB::table('gates_event_checks')->insertGetId(['event_id' => $eventId, 'kind' => 'safety', 'title' => mb_substr(trim($title), 0, 255),
            'owner_kind' => $kind, 'owner_id' => $oid, 'created_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    public static function checkDone(int $id, bool $done, int $leadId): array
    {
        $c = self::ready() ? DB::table('gates_event_checks')->where('id', $id)->first() : null;
        if (!$c) return ['ok' => false, 'error' => 'That check is not there.'];
        if (!Plan::isLead($leadId, (int) $c->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can sign off its checks.'];
        DB::table('gates_event_checks')->where('id', $id)->update(['done_at' => $done ? date('Y-m-d H:i:s') : null, 'done_by' => $done ? $leadId : null]);
        return ['ok' => true, 'event' => (int) $c->event_id];
    }

    /** @return list<object> open first */
    public static function checks(int $eventId): array
    {
        if (!self::ready()) return [];
        $rows = DB::table('gates_event_checks')->where('event_id', $eventId)->where('kind', 'safety')->orderBy('id')->get()->all();
        usort($rows, static fn ($a, $b) => [$a->done_at !== null, $a->id] <=> [$b->done_at !== null, $b->id]);
        return $rows;
    }

    // ── seating ─────────────────────────────────────────────────────────────────────────

    /** Add a table, or change one's guests; more guests than seats is refused, by how many. */
    public static function saveTable(int $eventId, array $in, int $leadId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $id = (int) ($in['table'] ?? 0);
        $row = $id > 0 ? DB::table('gates_event_tables')->where('id', $id)->first() : null;
        if ($id > 0 && !$row) return ['ok' => false, 'error' => 'That table is not there.'];
        if ($row) $eventId = (int) $row->event_id;
        if (!Plan::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its seating.'];
        $label = trim((string) ($in['label'] ?? ($row->label ?? '')));
        $seats = (int) ($in['seats'] ?? ($row->seats ?? 10));
        if ($label === '') return ['ok' => false, 'error' => 'Name the table.'];
        if ($seats < 1 || $seats > 500) return ['ok' => false, 'error' => 'A table seats between 1 and 500.'];
        $guests = self::guestList((string) ($in['guests'] ?? ($row->guests ?? '')));
        // Typed names and people seated by record share the table's seats.
        $linked = $row && SchemaHas::table('gates_event_seats') ? (int) DB::table('gates_event_seats')->where('table_id', $id)->count() : 0;
        if (count($guests) + $linked > $seats) return ['ok' => false, 'error' => 'That is ' . (count($guests) + $linked - $seats) . ' more than the ' . $seats . ' seats at ' . $label . '.'];
        $data = ['label' => mb_substr($label, 0, 190), 'seats' => $seats, 'guests' => $guests ? implode("\n", $guests) : null];
        if ($row) DB::table('gates_event_tables')->where('id', $id)->update($data);
        else $id = (int) DB::table('gates_event_tables')->insertGetId($data + ['event_id' => $eventId, 'created_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'id' => $id, 'event' => $eventId];
    }

    /** Seats a table holds right now: typed names plus people seated by record. */
    public static function seated(object $table): int
    {
        $n = count(self::guestList((string) $table->guests));
        if (SchemaHas::table('gates_event_seats')) $n += (int) DB::table('gates_event_seats')->where('table_id', $table->id)->count();
        return $n;
    }

    /**
     * Seat a guest of honour or a ticket holder at a table, by their record. Seating somebody
     * already seated MOVES them; a full table is refused.
     *
     * @param 'invite'|'ticket' $kind
     */
    public static function seat(int $tableId, string $kind, int $refId, int $leadId): array
    {
        if (!SchemaHas::table('gates_event_seats')) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $t = DB::table('gates_event_tables')->where('id', $tableId)->first();
        if (!$t) return ['ok' => false, 'error' => 'Pick a table.'];
        $eventId = (int) $t->event_id;
        if (!Plan::isLead($leadId, $eventId)) return ['ok' => false, 'error' => 'Only a lead of this event can change its seating.'];
        $person = self::person($eventId, $kind, $refId);
        if ($person === null) return ['ok' => false, 'error' => 'That guest is not on this event’s list.'];
        $current = DB::table('gates_event_seats')->where('event_id', $eventId)->where('kind', $kind)->where('ref_id', $refId)->first();
        if ($current && (int) $current->table_id === $tableId) return ['ok' => true, 'event' => $eventId, 'name' => $person];
        if (self::seated($t) >= (int) $t->seats) return ['ok' => false, 'error' => $t->label . ' is full: all ' . $t->seats . ' seats are taken.'];
        if ($current) DB::table('gates_event_seats')->where('id', $current->id)->update(['table_id' => $tableId, 'seated_by' => $leadId]);
        else DB::table('gates_event_seats')->insert(['event_id' => $eventId, 'table_id' => $tableId, 'kind' => $kind, 'ref_id' => $refId, 'seated_by' => $leadId, 'created_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => $eventId, 'name' => $person];
    }

    public static function unseat(int $seatId, int $leadId): array
    {
        $s = SchemaHas::table('gates_event_seats') ? DB::table('gates_event_seats')->where('id', $seatId)->first() : null;
        if (!$s) return ['ok' => false, 'error' => 'That seat is already free.'];
        if (!Plan::isLead($leadId, (int) $s->event_id)) return ['ok' => false, 'error' => 'Only a lead of this event can change its seating.'];
        DB::table('gates_event_seats')->where('id', $seatId)->delete();
        return ['ok' => true, 'event' => (int) $s->event_id];
    }

    /**
     * Who sits at each table by record, with their names and what they are.
     *
     * @return array<int, list<array{id:int, name:string, kind:string}>> by table id
     */
    public static function seatsByTable(int $eventId): array
    {
        if (!SchemaHas::table('gates_event_seats')) return [];
        $out = [];
        foreach (DB::table('gates_event_seats')->where('event_id', $eventId)->orderBy('id')->get() as $s) {
            $out[(int) $s->table_id][] = ['id' => (int) $s->id, 'name' => self::person($eventId, (string) $s->kind, (int) $s->ref_id) ?? 'A guest no longer on the list', 'kind' => (string) $s->kind];
        }
        return $out;
    }

    /**
     * Guests with no seat yet: guests of honour whose letter went, then confirmed ticket holders.
     *
     * @return array{total:int, list:list<array{kind:string, id:int, name:string, sub:string}>}
     */
    public static function unseated(int $eventId, int $limit = 60): array
    {
        $taken = [];
        if (SchemaHas::table('gates_event_seats')) {
            foreach (DB::table('gates_event_seats')->where('event_id', $eventId)->get(['kind', 'ref_id']) as $s) $taken[$s->kind . ':' . $s->ref_id] = true;
        }
        $all = [];
        foreach (self::invites($eventId) as $i) {
            if ($i->sent_at === null || isset($taken['invite:' . $i->id])) continue;
            $all[] = ['kind' => 'invite', 'id' => (int) $i->id, 'name' => (string) $i->name, 'sub' => $i->audience === 'judge' ? 'Judge' : 'Nominee'];
        }
        try {
            foreach (DB::table('gates_event_registrations')->where('event_id', $eventId)->where('status', 'confirmed')->orderBy('name')->get(['id', 'name', 'tier', 'quantity']) as $r) {
                if (isset($taken['ticket:' . $r->id])) continue;
                $all[] = ['kind' => 'ticket', 'id' => (int) $r->id, 'name' => (string) $r->name,
                          'sub' => trim((string) ($r->tier ?? '') . ((int) ($r->quantity ?? 1) > 1 ? ' · ' . $r->quantity . ' places' : '')) ?: 'Ticket'];
            }
        } catch (\Throwable) {
            // No registrations table is nobody holding a ticket.
        }
        return ['total' => count($all), 'list' => array_slice($all, 0, $limit)];
    }

    /** A guest's name if they are on THIS event's list, else null. */
    private static function person(int $eventId, string $kind, int $refId): ?string
    {
        try {
            if ($kind === 'invite') return DB::table('gates_event_invites')->where('id', $refId)->where('event_id', $eventId)->value('name') ?: null;
            if ($kind === 'ticket') return DB::table('gates_event_registrations')->where('id', $refId)->where('event_id', $eventId)->where('status', 'confirmed')->value('name') ?: null;
        } catch (\Throwable) {
        }
        return null;
    }

    /** @return list<string> one guest per line or comma, blanks dropped */
    public static function guestList(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text) ?: []), static fn ($g) => $g !== ''));
    }

    /** @return list<object> */
    public static function tables(int $eventId): array
    {
        return self::ready() ? DB::table('gates_event_tables')->where('event_id', $eventId)->orderBy('id')->get()->all() : [];
    }

    // ── what other parts of the platform already hold ───────────────────────────────────

    /** The awards this event is the ceremony for (gates_event_programmes). @return list<object> */
    public static function awards(int $eventId): array
    {
        if (!SchemaHas::table('gates_event_programmes')) return [];
        return DB::table('gates_event_programmes as ep')->join('gates_award_programmes as p', 'p.id', '=', 'ep.programme_id')
            ->where('ep.event_id', $eventId)->orderBy('p.title')->get(['p.id', 'p.title', 'p.slug'])->all();
    }

    /** Guests of honour invited to the event, optionally of one audience. @return list<object> */
    public static function invites(int $eventId, ?string $audience = null): array
    {
        if (!SchemaHas::table('gates_event_invites')) return [];
        $q = DB::table('gates_event_invites')->where('event_id', $eventId)->where('reference', 'not like', 'AGI-SAMPLE%');
        if ($audience !== null) $q->where('audience', $audience);
        return $q->orderBy('name')->limit(500)->get(['id', 'audience', 'name', 'guest_quota', 'sent_at', 'opened_at', 'scans'])->all();
    }

    /** What the tickets brought in, in kobo: confirmed registrations' amounts. */
    public static function ticketIncomeMinor(int $eventId): int
    {
        try {
            return (int) DB::table('gates_event_registrations')->where('event_id', $eventId)->where('status', 'confirmed')->sum('amount_naira') * 100;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** What the lead console wrote about this event, newest first (the Audit tab). @return list<object> */
    public static function audit(int $eventId, int $limit = 100): array
    {
        try {
            return DB::table('gates_audit_log as a')->leftJoin('gates_admins as ad', 'ad.id', '=', 'a.admin_id')
                ->where('a.target_type', 'event')->where('a.target_id', $eventId)->where('a.action', 'like', 'lead.%')
                ->orderByDesc('a.id')->limit($limit)->get(['a.action', 'a.meta', 'a.created_at', 'ad.name'])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{0:bool, 1:?string, 2:?int, 3:string} */
    private static function owner(int $eventId, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') return [true, null, null, ''];
        foreach (Plan::team($eventId) as $p) if ($p['key'] === $owner) return [true, $p['kind'], $p['id'], ''];
        return [false, null, null, 'Pick somebody on this event’s team.'];
    }
}
