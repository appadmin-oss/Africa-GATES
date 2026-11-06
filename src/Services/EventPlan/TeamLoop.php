<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use AfricaGates\Services\Grants;
use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE EVENT TEAM'S LOOP: who does the work, how they say where it is, how it is proved done,
 * and how the team asks for more of it (owner, 5 Nov 2026: "Events leads cannot add reports,
 * they cannot create tasks … the events and volunteer features are too basic", with the
 * "Event and Project Management: Design Plan").
 *
 * Built from the plan's MVP, and only the parts that rest on data this platform already has:
 *
 *   · WORK REACHES ITS OWNER. A lead gave work to a volunteer and the page said "It's in their
 *     week now" while nothing anywhere showed it to them — the volunteer workspace read a
 *     different task table. {@see mine()} is what their event page lists now.
 *   · QUICK REPORTS (plan: "a routine report takes seconds"). One tap of state — on track, at
 *     risk, blocked — a confidence and an optional line, about one piece of work or the whole
 *     event. Append-only: the next report supersedes the last, nothing is edited.
 *   · PROOF CHOSEN IN ADVANCE (plan: "Verified work"). Work carries a proof type from the
 *     moment it is created. Marked done without it, it is HELD as awaiting proof and does not
 *     count towards readiness (plan AT-6). A sign-off is a lead's.
 *   · THE TEAM PROPOSES, A LEAD DECIDES (plan: "the system proposes, people report, the
 *     hierarchy approves"; new work is a MEDIUM change). Anybody on the event team may suggest
 *     work; it waits, uncounted, until a lead approves or declines it with a reason. A declined
 *     suggestion is not raised again unless the person says what has changed (plan rule 6).
 *   · A CALM QUEUE (plan rules 1 and 12). {@see decisions()} ranks what needs a lead — work
 *     with two blocker reports in a week, proof waiting for sign-off, suggestions — and the
 *     Today page shows at most three.
 *
 * Every write is re-checked against the actor's standing on the ROW's event, so a crafted
 * post about another event's work changes nothing. The caller (LeadController,
 * VolunteerController) audits each one.
 */
final class TeamLoop
{
    public const PROOF = ['none' => 'No proof needed', 'link' => 'A link', 'note' => 'A short written note', 'signoff' => 'A lead signs it off'];
    public const STATES = ['ok' => 'On track', 'risk' => 'At risk', 'blocked' => 'Blocked'];
    public const CONFIDENCE = [3 => 'Sure', 2 => 'Fairly sure', 1 => 'Unsure'];
    /** Plan rule 1: two blocker reports on one piece of work within this many days needs a lead. */
    public const BLOCKER_DAYS = 7;
    /** Plan rule 12: at most this many decisions on a lead's Today. */
    public const TOP = 3;

    public static function ready(): bool
    {
        return Plan::ready() && SchemaHas::table('gates_event_reports') && SchemaHas::column('gates_event_work', 'proof_type');
    }

    /** When a report was made, in the words a list wants: "today 14:05", "yesterday", "Tue 4 Nov". */
    public static function when(string $at): string
    {
        $t = strtotime($at);
        if ($t === false) return '';
        if (date('Y-m-d', $t) === date('Y-m-d')) return 'today ' . date('H:i', $t);
        if (date('Y-m-d', $t) === date('Y-m-d', time() - 86400)) return 'yesterday';
        return Workspace::day($at);
    }

    // ── who may ────────────────────────────────────────────────────────────────────────

    /**
     * Is this member on the event's team at all: a lead, an owner, somebody in one of its
     * roles, a volunteer holding a role there, or somebody who already owns work in its plan.
     */
    public static function onTeam(int $memberId, int $eventId): bool
    {
        if ($memberId <= 0 || $eventId <= 0) return false;
        if (EventPermissions::standing($memberId, $eventId)['any']) return true;
        $now = date('Y-m-d H:i:s');
        if (Grants::ready() && DB::table('gates_grants')->where('actor_kind', Plan::ACTOR)->where('actor_id', $memberId)
                ->where('scope_kind', 'event')->where('scope_id', $eventId)->where('capability', 'like', 'vol.%')
                ->whereNull('revoked_at')->where('expires_at', '>', $now)->exists()) return true;
        return Plan::ready() && DB::table('gates_event_work')->where('event_id', $eventId)->where('owner_kind', Plan::ACTOR)->where('owner_id', $memberId)->exists();
    }

    // ── reports ────────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, error?:string, event?:int, id?:int, msg?:string} */
    public static function report(int $eventId, ?int $workId, int $memberId, string $name, string $state, int $confidence, string $note): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!isset(self::STATES[$state])) return ['ok' => false, 'error' => 'Choose on track, at risk or blocked.'];
        if ($workId) {
            $w = DB::table('gates_event_work')->where('id', $workId)->first();
            if (!$w || (int) $w->event_id !== $eventId) return ['ok' => false, 'error' => 'That work is not at this event.'];
        }
        if (!self::onTeam($memberId, $eventId)) return ['ok' => false, 'error' => 'Only somebody on this event’s team can report on it.'];
        $note = trim($note);
        if ($state === 'blocked' && $note === '') return ['ok' => false, 'error' => 'Say in a line what is blocking it, so a lead can act on it.'];
        $id = (int) DB::table('gates_event_reports')->insertGetId([
            'event_id' => $eventId, 'work_id' => $workId ?: null, 'author_id' => $memberId, 'author_name' => mb_substr($name, 0, 128) ?: null,
            'state' => $state, 'confidence' => max(1, min(3, $confidence ?: 2)), 'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // Reporting progress on your own work that had not started moves it to "in progress":
        // the report is the evidence, and nobody should have to say it twice.
        if ($workId && $state !== 'blocked') {
            DB::table('gates_event_work')->where('id', $workId)->where('status', 'todo')
                ->where('owner_kind', Plan::ACTOR)->where('owner_id', $memberId)->update(['status' => 'doing', 'updated_at' => date('Y-m-d H:i:s')]);
        }
        return ['ok' => true, 'event' => $eventId, 'id' => $id, 'msg' => $state === 'blocked' ? 'Reported. A lead sees it on their Today.' : 'Reported. Thank you.'];
    }

    /** @return list<object> newest first, each with ->work_title */
    public static function reports(int $eventId, int $limit = 20): array
    {
        if (!self::ready()) return [];
        return DB::table('gates_event_reports as r')->leftJoin('gates_event_work as w', 'w.id', '=', 'r.work_id')
            ->where('r.event_id', $eventId)->orderByDesc('r.id')->limit($limit)->get(['r.*', 'w.title as work_title'])->all();
    }

    /** The latest report on each piece of work, keyed by work id. @param list<int> $workIds @return array<int,object> */
    public static function latest(array $workIds): array
    {
        if (!self::ready() || $workIds === []) return [];
        $out = [];
        foreach (DB::table('gates_event_reports')->whereIn('work_id', $workIds)->orderBy('id')->get()->all() as $r) $out[(int) $r->work_id] = $r;
        return $out;
    }

    // ── work the team owns ─────────────────────────────────────────────────────────────

    /** This member's open work at one event (or every event, 0), soonest first. @return list<object> */
    public static function mine(int $memberId, int $eventId = 0): array
    {
        if (!self::ready() || $memberId <= 0) return [];
        $q = DB::table('gates_event_work')->where('owner_kind', Plan::ACTOR)->where('owner_id', $memberId)->whereIn('status', ['todo', 'doing', 'proof']);
        if ($eventId > 0) $q->where('event_id', $eventId);
        $rows = $q->get()->all();
        usort($rows, static fn ($a, $b) => [$a->due_at === null ? 1 : 0, (string) $a->due_at, $a->id] <=> [$b->due_at === null ? 1 : 0, (string) $b->due_at, $b->id]);
        return $rows;
    }

    /** What this member has suggested at one event, still waiting or decided. @return list<object> */
    public static function suggestedBy(int $memberId, int $eventId): array
    {
        if (!self::ready()) return [];
        return DB::table('gates_event_work')->where('event_id', $eventId)->where('proposed_by', $memberId)
            ->whereIn('status', ['proposed', 'declined'])->orderByDesc('id')->limit(10)->get()->all();
    }

    /**
     * Mark work done, with its proof where it needs one. Without the proof it is HELD as
     * awaiting proof (plan AT-6), never counted done. A sign-off is a lead's: the owner's
     * "done" puts it in front of a lead, a lead's "done" is the sign-off.
     *
     * @return array{ok:bool, error?:string, event?:int, msg?:string, held?:bool}
     */
    public static function complete(int $workId, int $memberId, string $proof): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        $w = DB::table('gates_event_work')->where('id', $workId)->first();
        if (!$w || in_array($w->status, ['proposed', 'declined', 'done'], true)) return ['ok' => false, 'error' => 'That work is not open.'];
        $event = (int) $w->event_id;
        $lead = Plan::may($memberId, $event, 'plan.edit');
        $owner = $w->owner_kind === Plan::ACTOR && (int) $w->owner_id === $memberId;
        if (!$lead && !$owner) return ['ok' => false, 'error' => 'Only who owns this work, or a lead, can finish it.'];
        $type = (string) ($w->proof_type ?? 'none');
        $proof = trim($proof);
        $now = date('Y-m-d H:i:s');
        $done = static fn (array $extra = []): int => DB::table('gates_event_work')->where('id', $workId)
            ->update($extra + ['status' => 'done', 'done_at' => $now, 'updated_at' => $now]);

        if ($type === 'link' && $proof !== '' && !preg_match('~^https?://\S+$~i', $proof)) {
            return ['ok' => false, 'error' => 'The proof for this is a link: paste the whole address, starting https://.'];
        }
        if (in_array($type, ['link', 'note'], true)) {
            if ($proof === '') {
                DB::table('gates_event_work')->where('id', $workId)->update(['status' => 'proof', 'updated_at' => $now]);
                return ['ok' => true, 'event' => $event, 'held' => true,
                        'msg' => 'Held as awaiting proof: add ' . ($type === 'link' ? 'the link' : 'a line saying what was done') . ' and it counts as done.'];
            }
            $done(['proof_text' => mb_substr($proof, 0, 2000), 'proof_by' => $memberId, 'proof_at' => $now]);
            return ['ok' => true, 'event' => $event, 'msg' => 'Done, with its proof. Readiness moves with it.'];
        }
        if ($type === 'signoff' && !$lead) {
            DB::table('gates_event_work')->where('id', $workId)->update(['status' => 'proof', 'updated_at' => $now,
                'proof_text' => $proof !== '' ? mb_substr($proof, 0, 2000) : null, 'proof_by' => $memberId, 'proof_at' => $now]);
            return ['ok' => true, 'event' => $event, 'held' => true, 'msg' => 'Sent to a lead to sign off. It counts as done once they do.'];
        }
        $done($type === 'signoff' ? ['decided_by' => $memberId, 'decided_at' => $now] : []);
        return ['ok' => true, 'event' => $event, 'msg' => $type === 'signoff' ? 'Signed off. Readiness moves with it.' : 'Done. Readiness moves with it.'];
    }

    /** A lead sends held work back: the proof is not enough. */
    public static function returnProof(int $workId, int $leadId, string $why): array
    {
        $w = self::ready() ? DB::table('gates_event_work')->where('id', $workId)->first() : null;
        if (!$w || $w->status !== 'proof') return ['ok' => false, 'error' => 'That work is not waiting for a sign-off.'];
        if (!Plan::may($leadId, (int) $w->event_id, 'plan.edit')) return ['ok' => false, 'error' => 'Only a lead of this event can send work back.'];
        $why = trim($why);
        if ($why === '') return ['ok' => false, 'error' => 'Say what is missing, so they can put it right.'];
        DB::table('gates_event_work')->where('id', $workId)->update(['status' => 'doing', 'decided_note' => mb_substr($why, 0, 255), 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'event' => (int) $w->event_id, 'msg' => 'Sent back with your note.'];
    }

    // ── suggestions ────────────────────────────────────────────────────────────────────

    /**
     * Somebody on the team suggests work. A lead's own work goes straight on the plan
     * (Plan::addWork); anybody else's waits, uncounted, for a lead.
     *
     * @return array{ok:bool, error?:string, event?:int, id?:int, msg?:string}
     */
    public static function propose(int $eventId, int $memberId, array $in): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update first.'];
        if (!Plan::event($eventId)) return ['ok' => false, 'error' => 'That event is not there.'];
        if (!self::onTeam($memberId, $eventId)) return ['ok' => false, 'error' => 'Only somebody on this event’s team can suggest work for it.'];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') return ['ok' => false, 'error' => 'Say what has to be done.'];
        $why = trim((string) ($in['why'] ?? ''));
        // Plan rule 6: a declined suggestion returns only with something new.
        $declined = DB::table('gates_event_work')->where('event_id', $eventId)->where('status', 'declined')
            ->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->orderByDesc('id')->first();
        if ($declined && trim((string) ($in['changed'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'A lead declined this on ' . Workspace::day((string) $declined->decided_at) . ($declined->decided_note ? ' (“' . $declined->decided_note . '”)' : '')
                . '. Say what has changed to raise it again.'];
        }
        $due = trim((string) ($in['due_at'] ?? ''));
        $dueAt = $due === '' ? null : (strtotime(preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due . ' 23:59:59' : $due) ?: null);
        if ($due !== '' && $dueAt === null) return ['ok' => false, 'error' => 'That date is not a date.'];
        $mine = (string) ($in['mine'] ?? '') === '1';
        $now = date('Y-m-d H:i:s');
        $detail = implode("\n\n", array_filter([$why !== '' ? 'Why: ' . $why : '', trim((string) ($in['changed'] ?? '')) !== '' ? 'What has changed: ' . trim((string) $in['changed']) : '']));
        $id = (int) DB::table('gates_event_work')->insertGetId([
            'event_id' => $eventId, 'title' => mb_substr($title, 0, 255), 'detail' => $detail !== '' ? $detail : null,
            'owner_kind' => $mine ? Plan::ACTOR : null, 'owner_id' => $mine ? $memberId : null,
            'due_at' => $dueAt ? date('Y-m-d H:i:s', $dueAt) : null, 'status' => 'proposed', 'proof_type' => 'none', 'blocks_night' => 0,
            'proposed_by' => $memberId, 'created_by' => $memberId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'event' => $eventId, 'id' => $id, 'msg' => 'Suggested. A lead decides; you will see the answer here.'];
    }

    /** A lead approves or declines a suggestion. Declining needs a reason (plan: "a required decision"). */
    public static function decide(int $workId, int $leadId, string $verdict, string $note, string $proof = 'none'): array
    {
        $w = self::ready() ? DB::table('gates_event_work')->where('id', $workId)->first() : null;
        if (!$w || $w->status !== 'proposed') return ['ok' => false, 'error' => 'That suggestion has already been decided.'];
        if (!Plan::may($leadId, (int) $w->event_id, 'plan.edit')) return ['ok' => false, 'error' => 'Only a lead of this event decides what goes on its plan.'];
        if ((int) $w->proposed_by === $leadId) return ['ok' => false, 'error' => 'Somebody else decides your own suggestion.'];
        $note = trim($note);
        $now = date('Y-m-d H:i:s');
        if ($verdict === 'approve') {
            DB::table('gates_event_work')->where('id', $workId)->update(['status' => 'todo', 'proof_type' => isset(self::PROOF[$proof]) ? $proof : 'none',
                'decided_by' => $leadId, 'decided_at' => $now, 'decided_note' => $note !== '' ? mb_substr($note, 0, 255) : null, 'updated_at' => $now]);
            return ['ok' => true, 'event' => (int) $w->event_id, 'msg' => 'Approved. It is on the plan and counts towards readiness.'];
        }
        if ($verdict !== 'decline') return ['ok' => false, 'error' => 'Approve or decline it.'];
        if ($note === '') return ['ok' => false, 'error' => 'Say why, so they know what would change your mind.'];
        DB::table('gates_event_work')->where('id', $workId)->update(['status' => 'declined', 'decided_by' => $leadId, 'decided_at' => $now,
            'decided_note' => mb_substr($note, 0, 255), 'updated_at' => $now]);
        return ['ok' => true, 'event' => (int) $w->event_id, 'msg' => 'Declined, with your reason.'];
    }

    // ── what needs a lead ──────────────────────────────────────────────────────────────

    /**
     * What needs a lead at this event, most threatening first: work blocked twice in a week
     * (the night waits on it first), proof waiting for sign-off, then suggestions.
     *
     * @return list<array{kind:string, work:object, why:string}>
     */
    public static function decisions(int $eventId): array
    {
        if (!self::ready()) return [];
        $out = [];
        $since = date('Y-m-d H:i:s', time() - self::BLOCKER_DAYS * 86400);
        $blocked = DB::table('gates_event_reports')->where('event_id', $eventId)->where('state', 'blocked')->where('created_at', '>=', $since)
            ->whereNotNull('work_id')->groupBy('work_id')->havingRaw('COUNT(*) >= 2')->pluck('work_id')->map(static fn ($i) => (int) $i)->all();
        if ($blocked) {
            foreach (DB::table('gates_event_work')->whereIn('id', $blocked)->whereNotIn('status', ['done', 'declined', 'proposed'])->get()->all() as $w) {
                $out[] = ['kind' => 'blocked', 'work' => $w, 'why' => 'Reported blocked twice this week', 'rank' => (int) $w->blocks_night === 1 ? 0 : 1];
            }
        }
        // Blocked twice about the event as a whole is the same signal without a row to hang it on.
        $whole = (int) DB::table('gates_event_reports')->where('event_id', $eventId)->whereNull('work_id')->where('state', 'blocked')->where('created_at', '>=', $since)->count();
        if ($whole >= 2) {
            $lastNote = (string) DB::table('gates_event_reports')->where('event_id', $eventId)->whereNull('work_id')->where('state', 'blocked')->orderByDesc('id')->value('note');
            $out[] = ['kind' => 'blocked', 'work' => (object) ['id' => 0, 'title' => 'The event as a whole', 'blocks_night' => 1],
                      'why' => 'Reported blocked ' . $whole . ' times this week' . ($lastNote !== '' ? ': “' . $lastNote . '”' : ''), 'rank' => 0];
        }
        foreach (DB::table('gates_event_work')->where('event_id', $eventId)->where('status', 'proof')->get()->all() as $w) {
            $out[] = ['kind' => 'proof', 'work' => $w, 'why' => ($w->proof_type === 'signoff' ? 'Waiting for your sign-off' : 'Marked done, waiting for its proof'), 'rank' => 2];
        }
        foreach (DB::table('gates_event_work')->where('event_id', $eventId)->where('status', 'proposed')->orderBy('id')->get()->all() as $w) {
            $out[] = ['kind' => 'proposed', 'work' => $w, 'why' => 'Suggested by ' . Plan::name(Plan::ACTOR, (int) $w->proposed_by), 'rank' => 3];
        }
        usort($out, static fn ($a, $b) => [$a['rank'], (int) $a['work']->id] <=> [$b['rank'], (int) $b['work']->id]);
        return $out;
    }
}
