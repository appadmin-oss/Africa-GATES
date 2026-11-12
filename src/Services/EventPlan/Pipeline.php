<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE CONTACT PIPELINE (design plan: "Keeps contacts and tasks in one place, so progress counts
 * itself"; "A contact becomes a task result when its stage moves").
 *
 *   · A PROSPECT IS SOMEBODY THE TEAM MEANS TO ASK TO TAKE PART — a person who might be
 *     nominated — with the team member working them and a stage.
 *   · "NOMINATED" MEANS A SUBMITTED FORM, never a verbal yes (one of the plan's own corrected
 *     defects). A stage cannot be set to it by hand: {@see signal()} moves a contact there when
 *     a nomination in this event's awards carries their email or phone, and records which.
 *   · THE REACH RATING IS PRIVATE: the member working the contact and the event's leads see it,
 *     nobody else (plan AT-9's rule for any score about a person). It rates how far the
 *     prospect's own network reaches; it is never shown to them.
 *   · ONE PERSON, ONE ROW. A second contact with the same email or phone is refused and the
 *     first one named; across awards the Rules flag it to the lead (plan AT-10).
 */
final class Pipeline
{
    public const STAGES = ['prospect' => 'To contact', 'contacted' => 'Contacted', 'yes' => 'Said yes', 'nominated' => 'Nomination submitted', 'declined' => 'Declined'];
    /** What a person may set by hand: everything but the stage only a submitted form proves. */
    public const MANUAL = ['prospect', 'contacted', 'yes', 'declined'];
    public const REACH = [3 => 'Wide reach', 2 => 'Some reach', 1 => 'Little reach'];

    public static function ready(): bool
    {
        return Goals::ready();
    }

    private static function email(string $e): ?string
    {
        $e = strtolower(trim($e));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    /** The last ten digits, so +234 803…, 0803… and 803… are one number. */
    public static function phone(string $p): ?string
    {
        $d = preg_replace('/\D+/', '', $p) ?? '';
        return strlen($d) >= 7 ? substr($d, -10) : null;
    }

    /** @return array{ok:bool, error?:string, id?:int, event?:int, msg?:string} */
    public static function add(int $eventId, array $in, int $memberId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!self::mayWork($memberId, $eventId)) return ['ok' => false, 'error' => 'Adding prospects needs a lead, a role ticked for the pipeline, or a volunteer role at this event.'];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => 'Their name, at least.'];
        $email = self::email((string) ($in['email'] ?? ''));
        $phone = self::phone((string) ($in['phone'] ?? ''));
        if (trim((string) ($in['email'] ?? '')) !== '' && $email === null) return ['ok' => false, 'error' => 'That email address is not one.'];
        if ($dupe = self::existing($eventId, $email, $phone)) return ['ok' => false, 'error' => 'Already in the pipeline as ' . $dupe->name . ' (' . (self::STAGES[$dupe->stage] ?? $dupe->stage) . ').'];
        $owner = $memberId;
        if (!empty($in['owner']) && Plan::may($memberId, $eventId, 'plan.edit') && preg_match('/^member:(\d+)$/', (string) $in['owner'], $m)) $owner = (int) $m[1];
        [$kind, $cat] = Goals::scopeOf((string) ($in['scope'] ?? ''), $eventId);
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_event_contacts')->insertGetId(['event_id' => $eventId, 'category_id' => $kind === 'category' ? $cat : null,
            'name' => mb_substr($name, 0, 190), 'email' => $email, 'phone' => $phone, 'stage' => 'prospect', 'owner_id' => $owner,
            'source' => mb_substr((string) ($in['source'] ?? 'added'), 0, 32), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null,
            'created_at' => $now, 'updated_at' => $now]);
        self::signal($eventId);
        return ['ok' => true, 'id' => $id, 'event' => $eventId, 'msg' => 'Added to the pipeline.'];
    }

    /**
     * Several at once, one per line — "Name, email, phone" in any order after the name, as a
     * sheet pastes. Duplicates are skipped and counted, never merged silently.
     */
    public static function import(int $eventId, string $text, int $memberId, array $in = []): array
    {
        $added = $dupes = $bad = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $cells = array_values(array_filter(array_map('trim', preg_split('/[,\t;]/', $line) ?: []), static fn ($c) => $c !== ''));
            if (!$cells) continue;
            $row = ['name' => $cells[0], 'source' => 'imported'] + $in;
            foreach (array_slice($cells, 1) as $c) {
                if (str_contains($c, '@')) $row['email'] = $c; elseif (self::phone($c)) $row['phone'] = $c;
            }
            $r = self::add($eventId, $row, $memberId);
            if ($r['ok']) $added++; elseif (str_starts_with((string) $r['error'], 'Already')) $dupes++; else $bad++;
            if (!$r['ok'] && !str_starts_with((string) $r['error'], 'Already') && str_contains((string) $r['error'], 'team')) return $r;
        }
        if ($added + $dupes + $bad === 0) return ['ok' => false, 'error' => 'Paste one person a line: name, then their email and phone.'];
        return ['ok' => true, 'event' => $eventId, 'msg' => $added . ' added' . ($dupes ? ', ' . $dupes . ' already there' : '') . ($bad ? ', ' . $bad . ' could not be read' : '') . '.'];
    }

    private static function existing(int $eventId, ?string $email, ?string $phone): ?object
    {
        if ($email === null && $phone === null) return null;
        return DB::table('gates_event_contacts')->where('event_id', $eventId)->where(static function ($w) use ($email, $phone) {
            if ($email !== null) $w->orWhere('email', $email);
            if ($phone !== null) $w->orWhere('phone', $phone);
        })->first();
    }

    /**
     * Who works the pipeline: a lead, a team role ticked "Work the pipeline" (a role does only
     * what it is ticked for — EventTeamsTest sweeps every act), or a volunteer holding a role at
     * the event, from their own workspace.
     */
    public static function mayWork(int $memberId, int $eventId): bool
    {
        if (Plan::may($memberId, $eventId, 'pipeline.work')) return true;
        return \AfricaGates\Services\Grants::ready() && DB::table('gates_grants')->where('actor_kind', Plan::ACTOR)->where('actor_id', $memberId)
            ->where('scope_kind', 'event')->where('scope_id', $eventId)->where('capability', 'like', 'vol.%')
            ->whereNull('revoked_at')->where('expires_at', '>', date('Y-m-d H:i:s'))->exists();
    }

    /** Move a contact. The member working it, or a lead; never to "nominated" by hand. */
    public static function move(int $contactId, string $stage, int $memberId): array
    {
        $c = self::ready() ? DB::table('gates_event_contacts')->where('id', $contactId)->first() : null;
        if (!$c) return ['ok' => false, 'error' => 'That contact is not there.'];
        if ((int) $c->owner_id !== $memberId && !Plan::may($memberId, (int) $c->event_id, 'plan.edit')) return ['ok' => false, 'error' => 'Only who is working this contact, or a lead, moves it.'];
        if ($stage === 'nominated') return ['ok' => false, 'error' => 'That stage is set by the nomination itself: it moves when their form is submitted.'];
        if (!in_array($stage, self::MANUAL, true)) return ['ok' => false, 'error' => 'That is not a stage.'];
        if ($c->stage === 'nominated') return ['ok' => false, 'error' => 'Their nomination is in; the stage stays.'];
        DB::table('gates_event_contacts')->where('id', $contactId)->update(['stage' => $stage, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $c->event_id, 'msg' => 'Moved to ' . mb_strtolower(self::STAGES[$stage]) . '.'];
    }

    public static function rate(int $contactId, int $reach, int $memberId): array
    {
        $c = self::ready() ? DB::table('gates_event_contacts')->where('id', $contactId)->first() : null;
        if (!$c || ((int) $c->owner_id !== $memberId && !Plan::may($memberId, (int) $c->event_id, 'plan.edit'))) return ['ok' => false, 'error' => 'Only who is working this contact rates it.'];
        if (!isset(self::REACH[$reach])) return ['ok' => false, 'error' => 'Wide, some or little?'];
        DB::table('gates_event_contacts')->where('id', $contactId)->update(['reach' => $reach, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $c->event_id, 'msg' => 'Rated. Only you and the leads see it.'];
    }

    /** Give a contact to somebody else on the team (a lead's call). */
    public static function assign(int $contactId, string $owner, int $memberId): array
    {
        $c = self::ready() ? DB::table('gates_event_contacts')->where('id', $contactId)->first() : null;
        if (!$c || !Plan::may($memberId, (int) $c->event_id, 'plan.edit')) return ['ok' => false, 'error' => 'A lead gives contacts to the team.'];
        if (!preg_match('/^member:(\d+)$/', $owner, $m) || !TeamLoop::onTeam((int) $m[1], (int) $c->event_id)) return ['ok' => false, 'error' => 'Pick somebody on this event’s team.'];
        DB::table('gates_event_contacts')->where('id', $contactId)->update(['owner_id' => (int) $m[1], 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $c->event_id, 'msg' => 'Given to them.'];
    }

    /**
     * THE SIGNAL: a nomination submitted in this event's awards whose nominee email or phone
     * matches a contact moves it to nominated and records which nomination proved it. Read-only
     * on nominations. @return list<object> the contacts it moved
     */
    public static function signal(int $eventId): array
    {
        if (!self::ready()) return [];
        $cats = Goals::categoryIds($eventId, 'event', null);
        if ($cats === []) return [];
        $open = DB::table('gates_event_contacts')->where('event_id', $eventId)->whereNotIn('stage', ['nominated'])->get()->all();
        if (!$open) return [];
        $moved = [];
        $noms = DB::table('gates_nominations')->whereIn('category_id', $cats)->where('status', '!=', 'rejected')->whereNull('merged_into')
            ->get(['id', 'nominee_email', 'nominee_phone', 'category_id'])->all();
        $byEmail = $byPhone = [];
        foreach ($noms as $n) {
            if ($e = self::email((string) $n->nominee_email)) $byEmail[$e] ??= $n;
            if ($p = self::phone((string) $n->nominee_phone)) $byPhone[$p] ??= $n;
        }
        foreach ($open as $c) {
            $n = ($c->email && isset($byEmail[$c->email])) ? $byEmail[$c->email] : (($c->phone && isset($byPhone[$c->phone])) ? $byPhone[$c->phone] : null);
            if (!$n) continue;
            DB::table('gates_event_contacts')->where('id', $c->id)->where('stage', '!=', 'nominated')
                ->update(['stage' => 'nominated', 'nomination_id' => (int) $n->id, 'category_id' => $c->category_id ?: (int) $n->category_id, 'updated_at' => date('Y-m-d H:i:s')]);
            $c->nomination_id = (int) $n->id;
            $moved[] = $c;
        }
        return $moved;
    }

    /** @return list<object> the event's contacts, by stage then name; ->reach hidden unless this member may see it */
    public static function list(int $eventId, int $viewerId, string $stage = '', bool $mineOnly = false): array
    {
        if (!self::ready()) return [];
        $q = DB::table('gates_event_contacts')->where('event_id', $eventId);
        if ($stage !== '' && isset(self::STAGES[$stage])) $q->where('stage', $stage);
        if ($mineOnly) $q->where('owner_id', $viewerId);
        $lead = Plan::may($viewerId, $eventId, 'plan.edit');
        return array_map(static function ($c) use ($viewerId, $lead) {
            if (!$lead && (int) $c->owner_id !== $viewerId) $c->reach = null;   // private: who works it and the leads
            return $c;
        }, $q->orderByRaw("CASE stage WHEN 'prospect' THEN 1 WHEN 'contacted' THEN 2 WHEN 'yes' THEN 3 WHEN 'nominated' THEN 4 ELSE 5 END")->orderBy('name')->get()->all());
    }

    /** @return array<string,int> stage => how many */
    public static function counts(int $eventId): array
    {
        if (!self::ready()) return [];
        $out = array_fill_keys(array_keys(self::STAGES), 0);
        foreach (DB::table('gates_event_contacts')->where('event_id', $eventId)->groupBy('stage')->get([DB::raw('stage as s'), DB::raw('COUNT(*) as n')]) as $r) $out[(string) $r->s] = (int) $r->n;
        return $out;
    }
}
