<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "CAN WE HAVE AN UPDATE ON ALL NOMINEES, PLEASE?" — nominee status updates in the portal
 * (owner, 16 Nov 2026, forwarding the coordinators' own message: "Kindly review your assigned
 * nominees and provide their current status, including portal updates, publicity and voting
 * mobilisation. Please ensure all updates are submitted promptly so we can track our
 * progress.").
 *
 * That message went out on WhatsApp, and the answers came back as WhatsApp messages — in a
 * dozen threads, in a dozen formats, with no way to see which nominee nobody had answered for.
 * So the portal holds the question and the answers:
 *
 *   · an UPDATE is three plain answers about one nominee — where their profile on the portal
 *     stands, the publicity done for them, the voting mobilisation — and a note; kept as a
 *     history, newest first, with who sent it;
 *   · a REQUEST is a lead asking everybody on an award for an update on every nominee by a
 *     date. It shows on each volunteer's Today and nominee list; every nominee without an update
 *     since it was asked is marked "Update due"; and the lead sees "4 of 6 updated" and who did
 *     which.
 *
 * ── WHO MAY ──────────────────────────────────────────────────────────────────
 * An update is about a nominee, so it needs the same access as reaching them: the outreach role
 * on that award, and its terms agreed ({@see Outreach::nominee()}). A request is a lead's: only
 * somebody who leads that award ({@see Team::scopes()}) can ask the team.
 *
 * The answers are words a volunteer chooses from, never free numbers, so a lead reading twenty
 * updates reads one vocabulary. "Portal" is read from the nominee's actual profile where it can
 * be (a complete profile is complete whatever anybody ticks), and the volunteer says the rest.
 */
final class NomineeStatus
{
    public const PORTAL = [
        'none'    => 'Not started',
        'helping' => 'Helping them finish it',
        'done'    => 'Profile complete',
    ];
    public const PUBLICITY = [
        'none'   => 'Not yet',
        'social' => 'Shared on social media',
        'flier'  => 'Flier and voting link shared',
        'groups' => 'Shared in community, church or school groups',
    ];
    public const MOBILISING = [
        'none'    => 'Not yet',
        'started' => 'Started asking people to vote',
        'active'  => 'Actively mobilising voters',
    ];

    /** The coordinators' own words, offered as the request's default. */
    public const ASK = 'Kindly review your assigned nominees and provide their current status, including portal updates, publicity and voting mobilisation. Please submit your updates promptly so we can track our progress.';

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_nominee_status') && SchemaHas::table('gates_vol_status_requests');
    }

    /** @return array{ok:bool, error?:string} */
    public static function record(object $v, int $nomineeId, array $f): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Status updates are not switched on yet.'];
        $n = Outreach::nominee($v, $nomineeId);
        if (!$n) return ['ok' => false, 'error' => 'That nominee isn’t on an award you reach nominees on.'];
        if (!$n->agreed) return ['ok' => false, 'error' => 'Agree to the terms for this award first.'];
        $portal = (string) ($f['portal'] ?? ''); $pub = (string) ($f['publicity'] ?? ''); $mob = (string) ($f['mobilising'] ?? '');
        if (!isset(self::PORTAL[$portal]))     return ['ok' => false, 'error' => 'Say where their profile on the portal stands.'];
        if (!isset(self::PUBLICITY[$pub]))     return ['ok' => false, 'error' => 'Say what publicity has been done for them.'];
        if (!isset(self::MOBILISING[$mob]))    return ['ok' => false, 'error' => 'Say where the voting mobilisation stands.'];
        $note = trim(mb_substr(strip_tags((string) ($f['status_note'] ?? '')), 0, 2000));
        try {
            DB::table('gates_vol_nominee_status')->insert(['cycle_id' => (int) $n->cycle_id, 'nominee_id' => (int) $n->id, 'volunteer_id' => (int) $v->id,
                'portal' => $portal, 'publicity' => $pub, 'mobilising' => $mob, 'note' => $note !== '' ? $note : null,
                'created_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'The update could not be saved. Nothing was changed; please try again.'];
        }
        return ['ok' => true];
    }

    /**
     * The newest update for each nominee, with who sent it.
     *
     * @param list<int> $nomineeIds
     * @return array<int,object>
     */
    public static function latest(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $nomineeIds))));
        if (!$ids || !self::ready()) return [];
        $out = [];
        try {
            $rows = DB::table('gates_vol_nominee_status as s')
                ->leftJoin('gates_volunteers as v', 'v.id', '=', 's.volunteer_id')
                ->leftJoin('gates_users as u', 'u.id', '=', 'v.user_id')
                ->whereIn('s.nominee_id', $ids)->orderByDesc('s.id')
                ->get(['s.*', 'v.display_name', 'u.name as user_name']);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) {
            if (isset($out[(int) $r->nominee_id])) continue;
            $r->by = (string) ($r->display_name ?: $r->user_name ?: 'A volunteer');
            $out[(int) $r->nominee_id] = $r;
        }
        return $out;
    }

    /** "Portal: profile complete · Publicity: flier and voting link shared · Voting: started asking" */
    public static function words(object $s): string
    {
        return 'Portal: ' . lcfirst(self::PORTAL[$s->portal] ?? '?') . ' · Publicity: ' . lcfirst(self::PUBLICITY[$s->publicity] ?? '?')
             . ' · Voting: ' . lcfirst(self::MOBILISING[$s->mobilising] ?? '?');
    }

    /** @return array{ok:bool, error?:string} */
    public static function request(object $lead, int $cycleId, string $message, string $dueOn): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Status updates are not switched on yet.'];
        if (!in_array(['cycle', $cycleId], array_map(static fn ($s) => [(string) $s[0], (int) $s[1]], Team::scopes($lead)), true)) {
            return ['ok' => false, 'error' => 'Only a lead on this award can ask the team for an update.'];
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if (!$d || $d->format('Y-m-d') !== $dueOn) return ['ok' => false, 'error' => 'Choose the day the updates are due.'];
        if ($dueOn < date('Y-m-d')) return ['ok' => false, 'error' => 'The day the updates are due can’t be in the past.'];
        $msg = trim(mb_substr(strip_tags($message), 0, 1000));
        try {
            DB::table('gates_vol_status_requests')->insert(['cycle_id' => $cycleId, 'volunteer_id' => (int) $lead->id,
                'message' => $msg !== '' ? $msg : self::ASK, 'due_on' => $dueOn, 'created_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'The request could not be saved. Please try again.'];
        }
        return ['ok' => true];
    }

    /**
     * The request still open on an award: the newest one, until three days past its due date.
     * A request nobody can see the end of is a banner people stop reading.
     */
    public static function open(int $cycleId): ?object
    {
        if ($cycleId < 1 || !self::ready()) return null;
        try {
            $r = DB::table('gates_vol_status_requests as q')
                ->leftJoin('gates_volunteers as v', 'v.id', '=', 'q.volunteer_id')
                ->leftJoin('gates_users as u', 'u.id', '=', 'v.user_id')
                ->where('q.cycle_id', $cycleId)->where('q.due_on', '>=', date('Y-m-d', strtotime('-3 days')))
                ->orderByDesc('q.id')->first(['q.*', 'v.display_name', 'u.name as user_name']);
        } catch (\Throwable) {
            return null;
        }
        if (!$r) return null;
        $r->by = (string) ($r->display_name ?: $r->user_name ?: 'Your lead');
        return $r;
    }

    /** Has this nominee had an update since the request was made? */
    public static function answered(?object $req, ?object $latest): bool
    {
        return $req !== null && $latest !== null && (string) $latest->created_at >= (string) $req->created_at;
    }

    /**
     * How far the team has got on a request: nominees updated since it was asked, of all.
     *
     * @param list<int> $nomineeIds
     * @return array{done:int, total:int}
     */
    public static function progress(?object $req, array $nomineeIds): array
    {
        $latest = self::latest($nomineeIds);
        $done = 0;
        foreach ($nomineeIds as $id) if (self::answered($req, $latest[(int) $id] ?? null)) $done++;
        return ['done' => $done, 'total' => count(array_unique($nomineeIds))];
    }
}
