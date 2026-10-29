<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\Grants;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Shifts: a block of time at an event or for an award, with slots (handoff §6.3).
 *
 * The rules the screens state, each enforced here rather than in a template:
 *   · joining needs the shift's training, a free slot, a start in the future and no clash with
 *     a shift the person is already on;
 *   · a shift whose time moved after somebody joined shows "Time changed" until they accept it,
 *     and a clash that a move created shows on both shifts with "Drop one";
 *   · "Fits you" ranks by availability, place, training and past shifts, and every row says why
 *     — a ranking nobody can explain is a ranking nobody trusts;
 *   · joining an event shift grants the shift's capability at that event until the day after it,
 *     and dropping the last such shift ends the grant (Grants: access never outlives its reason).
 */
final class Shifts
{
    /** @return list<object> shifts the person is on, soonest first, with their signup */
    public static function mine(object $v): array
    {
        return self::decorate($v, DB::table('gates_vol_shifts as h')->join('gates_vol_signups as s', 's.shift_id', '=', 'h.id')
            ->where('s.volunteer_id', $v->id)->where('s.status', 'on')->where('h.status', 'open')
            ->where('h.ends_at', '>=', date('Y-m-d H:i:s'))->orderBy('h.starts_at')
            ->get(['h.*', 's.id as signup_id', 's.status as signup_status', 's.change_seen_at', 's.joined_at'])->all());
    }

    /** @return list<object> past shifts: kept, missed, or dropped late */
    public static function past(object $v): array
    {
        return DB::table('gates_vol_shifts as h')->join('gates_vol_signups as s', 's.shift_id', '=', 'h.id')
            ->leftJoin('gates_vol_hours as r', static fn ($j) => $j->on('r.ref_id', '=', 's.id')->where('r.ref_kind', '=', 'signup'))
            ->where('s.volunteer_id', $v->id)
            ->where(static fn ($w) => $w->whereIn('s.status', ['kept', 'missed'])
                ->orWhere(static fn ($o) => $o->where('s.status', 'dropped')->where('h.starts_at', '<', date('Y-m-d H:i:s'))))
            ->orderByDesc('h.starts_at')->limit(50)->get(['h.*', 's.status as signup_status', 's.dropped_at', 'r.hours as hours'])->all();
    }

    /** @return list<object> every open future shift with room, not already the person's */
    public static function open(object $v): array
    {
        $mine = DB::table('gates_vol_signups')->where('volunteer_id', $v->id)->where('status', 'on')->pluck('shift_id')->all();
        $q = DB::table('gates_vol_shifts')->where('status', 'open')->where('starts_at', '>', date('Y-m-d H:i:s'));
        if ($mine) $q->whereNotIn('id', $mine);
        return self::decorate($v, $q->orderBy('starts_at')->limit(200)->get()->all());
    }

    /**
     * Open shifts ranked for this person, with the reason for each.
     *
     * @return list<object> each with ->fit (0–100) and ->why
     */
    public static function fits(object $v): array
    {
        $rows = array_filter(self::open($v), static fn ($s) => (int) $s->filled < (int) $s->slots);
        $past = DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
            ->where('s.volunteer_id', $v->id)->where('s.status', 'kept')->get(['h.location', 'h.scope_kind', 'h.scope_id'])->all();
        foreach ($rows as $s) {
            $score = 50;
            $why = [];
            $t = strtotime((string) $s->starts_at);
            $weekend = in_array((int) date('N', $t), [6, 7], true);
            $evening = (int) date('G', $t) >= 16;
            if ($s->from_home) {
                $score += $v->from_home ? 20 : -10;
                $why[] = 'From home';
            } elseif ($weekend) {
                $ok = $v->weekends === 'both' || ($v->weekends === 'sat' && date('N', $t) === '6') || ($v->weekends === 'sun' && date('N', $t) === '7');
                $score += $ok ? 20 : -25;
                if ($ok) $why[] = date('l', $t) . 's suit you';
            } else {
                $ok = $v->weekdays === 'any' || ($v->weekdays === 'evenings' && $evening);
                $score += $ok ? 20 : -25;
                if ($ok) $why[] = $evening ? 'weekday evenings suit you' : 'weekdays suit you';
            }
            if (!$s->from_home && $v->area && $s->location && stripos((string) $s->location, (string) strtok((string) $v->area, ',')) !== false) {
                $score += 10;
                $why[] = 'near ' . strtok((string) $v->area, ',');
            }
            if ($s->module_key) {
                if ($s->trained) { $score += 10; $why[] = 'uses your ' . $s->module_title . ' training'; }
                else { $score -= 20; $why[] = 'needs ' . $s->module_title . ' training'; }
            } else {
                $why[] = 'no training needed';
            }
            $same = count(array_filter($past, static fn ($p) => $p->location === $s->location || ($p->scope_kind === $s->scope_kind && (int) $p->scope_id === (int) $s->scope_id)));
            if ($same > 0) { $score += min(10, $same * 3); $why[] = "you've done " . $same . ' here'; }
            $s->fit = max(0, min(100, $score));
            $s->why = ucfirst(implode(' · ', array_slice($why, 0, 3)));
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => $b->fit <=> $a->fit ?: strcmp((string) $a->starts_at, (string) $b->starts_at));
        return $rows;
    }

    /** @return array{ok:bool, error?:string} */
    public static function join(object $v, int $shiftId): array
    {
        if (!in_array(Volunteers::role($v), ['new', 'active', 'lead'], true)) return ['ok' => false, 'error' => 'Shifts open once your application is approved.'];
        $s = DB::table('gates_vol_shifts')->where('id', $shiftId)->first();
        if (!$s || $s->status !== 'open') return ['ok' => false, 'error' => 'That shift is not open.'];
        if (strtotime((string) $s->starts_at) <= time()) return ['ok' => false, 'error' => 'That shift has already started.'];
        if ($s->module_key && !Training::holds((int) $v->id, (string) $s->module_key)) {
            return ['ok' => false, 'error' => 'This shift needs training first.', 'train' => (string) $s->module_key];
        }
        $have = DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('volunteer_id', $v->id)->first();
        if ($have && $have->status === 'on') return ['ok' => true];
        $filled = (int) DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('status', 'on')->count();
        if ($filled >= (int) $s->slots) return ['ok' => false, 'error' => 'That shift is full. You can join the waitlist.', 'full' => true];
        foreach (self::mine($v) as $m) {
            if (self::overlaps($m, $s)) return ['ok' => false, 'error' => 'That clashes with ' . $m->title . '. Drop one first.'];
        }
        $now = date('Y-m-d H:i:s');
        if ($have) {
            DB::table('gates_vol_signups')->where('id', $have->id)->update(['status' => 'on', 'joined_at' => $now, 'dropped_at' => null, 'change_seen_at' => $now, 'promoted_at' => null]);
        } else {
            DB::table('gates_vol_signups')->insert(['shift_id' => $shiftId, 'volunteer_id' => $v->id, 'status' => 'on', 'joined_at' => $now, 'change_seen_at' => $now]);
        }
        if ($s->capability && $s->scope_kind === 'event') {
            $until = date('Y-m-d 23:59:59', strtotime((string) $s->ends_at . ' +1 day'));
            if (!Grants::allows('member', (int) $v->user_id, (string) $s->capability, 'event', (int) $s->scope_id)) {
                Grants::grant('member', (int) $v->user_id, (string) $s->capability, 'event', (int) $s->scope_id, $until, null, 'From shift ' . $s->id);
            }
        }
        return ['ok' => true];
    }

    public static function drop(object $v, int $shiftId): bool
    {
        $n = DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('volunteer_id', $v->id)->where('status', 'on')
            ->update(['status' => 'dropped', 'dropped_at' => date('Y-m-d H:i:s')]);
        if ($n === 0) return false;
        $s = DB::table('gates_vol_shifts')->where('id', $shiftId)->first();
        // The place goes to whoever has waited longest and can still take it.
        self::promote($shiftId);
        if ($s && $s->capability && $s->scope_kind === 'event') {
            $still = DB::table('gates_vol_signups as x')->join('gates_vol_shifts as h', 'h.id', '=', 'x.shift_id')
                ->where('x.volunteer_id', $v->id)->where('x.status', 'on')->where('h.scope_kind', 'event')
                ->where('h.scope_id', $s->scope_id)->where('h.capability', $s->capability)->exists();
            if (!$still) {
                foreach (Grants::forActor('member', (int) $v->user_id, (string) $s->capability) as $g) {
                    if ($g->scope_kind === 'event' && (int) $g->scope_id === (int) $s->scope_id && str_starts_with((string) $g->note, 'From shift')) Grants::revoke((int) $g->id);
                }
            }
        }
        return true;
    }

    /**
     * Join the waitlist for a full shift (docs/architecture/WORKSPACE-COMPARISON.md §3). The
     * same questions as joining are asked now — training, a start in the future — so nobody
     * waits for a place they could never take; a clash is asked again when a place opens,
     * because the person's other shifts may have moved by then.
     *
     * @return array{ok:bool, error?:string, place?:int}
     */
    public static function wait(object $v, int $shiftId): array
    {
        if (!in_array(Volunteers::role($v), ['new', 'active', 'lead'], true)) return ['ok' => false, 'error' => 'Shifts open once your application is approved.'];
        $s = DB::table('gates_vol_shifts')->where('id', $shiftId)->first();
        if (!$s || $s->status !== 'open') return ['ok' => false, 'error' => 'That shift is not open.'];
        if (strtotime((string) $s->starts_at) <= time()) return ['ok' => false, 'error' => 'That shift has already started.'];
        if ($s->module_key && !Training::holds((int) $v->id, (string) $s->module_key)) return ['ok' => false, 'error' => 'This shift needs training first.'];
        $have = DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('volunteer_id', $v->id)->first();
        if ($have && in_array($have->status, ['on', 'waiting'], true)) return ['ok' => true, 'place' => self::placeInLine($v, $shiftId)];
        $filled = (int) DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('status', 'on')->count();
        if ($filled < (int) $s->slots) return self::join($v, $shiftId);   // a place opened meanwhile: take it
        $now = date('Y-m-d H:i:s');
        if ($have) DB::table('gates_vol_signups')->where('id', $have->id)->update(['status' => 'waiting', 'joined_at' => $now, 'dropped_at' => null, 'promoted_at' => null]);
        else DB::table('gates_vol_signups')->insert(['shift_id' => $shiftId, 'volunteer_id' => $v->id, 'status' => 'waiting', 'joined_at' => $now]);
        return ['ok' => true, 'place' => self::placeInLine($v, $shiftId)];
    }

    /** Step out of a waitlist. Not a drop: it never counts against anybody's reliability. */
    public static function unwait(object $v, int $shiftId): bool
    {
        return DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('volunteer_id', $v->id)->where('status', 'waiting')
            ->update(['status' => 'withdrawn']) > 0;
    }

    /** Where this person stands in a shift's line, 1 = next. Null when not waiting. */
    public static function placeInLine(object $v, int $shiftId): ?int
    {
        $mine = DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('volunteer_id', $v->id)->where('status', 'waiting')->first();
        if (!$mine) return null;
        return 1 + (int) DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('status', 'waiting')
            ->where(static fn ($w) => $w->where('joined_at', '<', $mine->joined_at)
                ->orWhere(static fn ($o) => $o->where('joined_at', $mine->joined_at)->where('id', '<', $mine->id)))->count();
    }

    /** @return list<object> the shifts this person is waiting for, with ->place */
    public static function waiting(object $v): array
    {
        $rows = DB::table('gates_vol_shifts as h')->join('gates_vol_signups as s', 's.shift_id', '=', 'h.id')
            ->where('s.volunteer_id', $v->id)->where('s.status', 'waiting')->where('h.status', 'open')
            ->where('h.starts_at', '>', date('Y-m-d H:i:s'))->orderBy('h.starts_at')->get(['h.*'])->all();
        foreach ($rows as $r) { $r->place = self::placeInLine($v, (int) $r->id); $r->scope_name = self::scopeName((string) $r->scope_kind, (int) $r->scope_id); }
        return $rows;
    }

    /**
     * Fill free places from the line, oldest first. Anybody who can no longer take the shift
     * — on a break, gone, training lapsed, or now booked on a shift that overlaps it — is
     * passed over and keeps their place in line, so a lapse that is fixed tomorrow is not a
     * lost turn. Nobody is moved onto a shift that has started.
     *
     * @return list<int> the signup ids moved on
     */
    public static function promote(int $shiftId): array
    {
        $s = DB::table('gates_vol_shifts')->where('id', $shiftId)->first();
        if (!$s || $s->status !== 'open' || strtotime((string) $s->starts_at) <= time()) return [];
        $moved = [];
        foreach (DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('status', 'waiting')->orderBy('joined_at')->orderBy('id')->get() as $w) {
            $filled = (int) DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('status', 'on')->count();
            if ($filled >= (int) $s->slots) break;
            $v = DB::table('gates_volunteers')->where('id', $w->volunteer_id)->first();
            if (!$v || !in_array(Volunteers::role($v), ['new', 'active', 'lead'], true) || $v->status === 'paused') continue;
            if ($s->module_key && !Training::holds((int) $v->id, (string) $s->module_key)) continue;
            $clash = false;
            foreach (self::mine($v) as $m) if (self::overlaps($m, $s)) { $clash = true; break; }
            if ($clash) continue;
            $now = date('Y-m-d H:i:s');
            // Guarded on the row still waiting, so two drops at once cannot promote one person twice.
            if (DB::table('gates_vol_signups')->where('id', $w->id)->where('status', 'waiting')
                    ->update(['status' => 'on', 'promoted_at' => $now, 'change_seen_at' => $now]) !== 1) continue;
            if ($s->capability && $s->scope_kind === 'event' && !Grants::allows('member', (int) $v->user_id, (string) $s->capability, 'event', (int) $s->scope_id)) {
                Grants::grant('member', (int) $v->user_id, (string) $s->capability, 'event', (int) $s->scope_id,
                    date('Y-m-d 23:59:59', strtotime((string) $s->ends_at . ' +1 day')), null, 'From shift ' . $s->id);
            }
            $moved[] = (int) $w->id;
        }
        return $moved;
    }

    public static function acceptChange(object $v, int $shiftId): bool
    {
        return DB::table('gates_vol_signups')->where('shift_id', $shiftId)->where('volunteer_id', $v->id)
            ->update(['change_seen_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /**
     * Staff mark who came. Kept shifts become verified hours from the shift's own times; a
     * missed one is recorded at nothing. Answered once per signup.
     */
    public static function mark(int $signupId, bool $kept, string $byName): bool
    {
        $sg = DB::table('gates_vol_signups')->where('id', $signupId)->first();
        if (!$sg || $sg->status !== 'on') return false;
        $s = DB::table('gates_vol_shifts')->where('id', $sg->shift_id)->first();
        if (!$s) return false;
        DB::table('gates_vol_signups')->where('id', $signupId)->where('status', 'on')->update(['status' => $kept ? 'kept' : 'missed']);
        $sg->status = $kept ? 'kept' : 'missed';
        $kept ? Hours::fromSignup($sg, $s, $sg->check_in_at ? 'scan' : 'signup', $byName) : Hours::missed($sg, $s);
        return true;
    }

    /**
     * Move a shift's time. Everybody on it sees "Time changed" until they accept.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function retime(int $shiftId, string $starts, string $ends): array
    {
        $a = strtotime($starts);
        $b = strtotime($ends);
        if ($a === false || $b === false || $b <= $a) return ['ok' => false, 'error' => 'The end must be after the start.'];
        DB::table('gates_vol_shifts')->where('id', $shiftId)->update([
            'starts_at' => date('Y-m-d H:i:s', $a), 'ends_at' => date('Y-m-d H:i:s', $b),
            'time_changed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true];
    }

    /** @return array{ok:bool, errors?:list<string>, id?:int} */
    public static function create(array $f, int $adminId): array
    {
        $e = [];
        $title = trim((string) ($f['title'] ?? ''));
        $kind = in_array($f['scope_kind'] ?? '', ['event', 'cycle'], true) ? (string) $f['scope_kind'] : '';
        $scope = (int) ($f['scope_id'] ?? 0);
        $a = strtotime((string) ($f['starts_at'] ?? ''));
        $b = strtotime((string) ($f['ends_at'] ?? ''));
        if ($title === '') $e[] = 'Give the shift a name.';
        if ($kind === '' || $scope <= 0) $e[] = 'Pick the event or award it is for.';
        if ($a === false || $b === false || $b <= $a) $e[] = 'Set a start, and an end after it.';
        $cap = (string) ($f['capability'] ?? '');
        if ($cap !== '' && !isset(Grants::CAPABILITIES[$cap])) $e[] = 'That is not a role this platform knows.';
        if ($e !== []) return ['ok' => false, 'errors' => $e];
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_vol_shifts')->insertGetId([
            'title' => mb_substr($title, 0, 190), 'scope_kind' => $kind, 'scope_id' => $scope, 'capability' => $cap ?: null,
            'location' => mb_substr(trim((string) ($f['location'] ?? '')), 0, 190) ?: null, 'from_home' => empty($f['from_home']) ? 0 : 1,
            'starts_at' => date('Y-m-d H:i:s', $a), 'ends_at' => date('Y-m-d H:i:s', $b), 'slots' => max(1, min(500, (int) ($f['slots'] ?? 1))),
            'module_key' => trim((string) ($f['module_key'] ?? '')) ?: null, 'bring' => mb_substr(trim((string) ($f['bring'] ?? '')), 0, 255) ?: null,
            'brief' => mb_substr(trim((string) ($f['brief'] ?? '')), 0, 4000) ?: null, 'lead_name' => mb_substr(trim((string) ($f['lead_name'] ?? '')), 0, 190) ?: null,
            'status' => 'open', 'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** @return list<array{0:object,1:object}> pairs of the person's shifts that overlap */
    public static function clashes(object $v): array
    {
        $m = self::mine($v);
        $out = [];
        for ($i = 0; $i < count($m); $i++) for ($j = $i + 1; $j < count($m); $j++) if (self::overlaps($m[$i], $m[$j])) $out[] = [$m[$i], $m[$j]];
        return $out;
    }

    public static function overlaps(object $a, object $b): bool
    {
        return strtotime((string) $a->starts_at) < strtotime((string) $b->ends_at) && strtotime((string) $b->starts_at) < strtotime((string) $a->ends_at);
    }

    /** The name of a shift's place: the event's title, or the award and edition. */
    public static function scopeName(string $kind, int $id): string
    {
        if ($kind === 'event') return (string) (DB::table('gates_site_events')->where('id', $id)->value('title') ?? 'Event');
        if ($kind === 'cycle') {
            $c = DB::table('gates_award_cycles as c')->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', $id)->first(['p.title', 'c.edition_label', 'c.year']);
            if ($c) return trim((string) $c->title . ' · ' . ((string) ($c->edition_label ?: $c->year)));
        }
        return 'Africa GATES';
    }

    /** Adds filled, trained, scope name and the module title to each row. @return list<object> */
    private static function decorate(object $v, array $rows): array
    {
        if ($rows === []) return [];
        $ids = array_map(static fn ($r) => (int) $r->id, $rows);
        $filled = DB::table('gates_vol_signups')->whereIn('shift_id', $ids)->where('status', 'on')
            ->groupBy('shift_id')->selectRaw('shift_id, COUNT(*) as n')->pluck('n', 'shift_id')->all();
        $mods = Training::titles();
        $names = [];
        foreach ($rows as $r) {
            $k = $r->scope_kind . ':' . $r->scope_id;
            $names[$k] ??= self::scopeName((string) $r->scope_kind, (int) $r->scope_id);
            $r->filled = (int) ($filled[$r->id] ?? 0);
            $r->scope_name = $names[$k];
            $r->module_title = $r->module_key ? ($mods[$r->module_key] ?? $r->module_key) : null;
            $r->trained = $r->module_key ? Training::holds((int) $v->id, (string) $r->module_key) : true;
            $r->time_changed = !empty($r->time_changed_at) && isset($r->change_seen_at) && (string) $r->time_changed_at > (string) ($r->change_seen_at ?? '');
        }
        return $rows;
    }
}
