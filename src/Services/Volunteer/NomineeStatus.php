<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\EventsFront;
use AfricaGates\Services\Grants;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
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
 *
 * ── ANY TIME, AND MORE THAN THREE ANSWERS (owner, same day: "can also do it without the asks.
 * Also please broaden") ──────────────────────────────────────────────────────────────────
 * An update never needs a request: the request only marks who is still owed one. And beyond the
 * three answers the coordinators named, an update can say whether the nominee has been reached,
 * what their profile still needs, where they have been shared, who is mobilising for them, what
 * would help and the next step — each optional, so a quick update stays quick. "What would
 * help" is the one a lead acts on, so their view leads with it.
 *
 * ── A REQUEST IS EMAILED ────────────────────────────────────────────────────────────────
 * Asking the team emails everybody who reaches nominees on that award (the lead excepted), with
 * the message, the date and their own list. Claimed per request and address before it is sent
 * (Support\BroadcastLog), so a retry or a double press never sends it twice.
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

    /** Optional detail (2027_05_28). Lists are stored as JSON lists of these keys. */
    public const REACH = [
        'reached'     => 'Spoken to them recently',
        'trying'      => 'Still trying to reach them',
        'unreachable' => 'Can’t reach them',
        'declined'    => 'They don’t want to take part',
    ];
    public const MISSING = [
        'photo'    => 'A photo',
        'story'    => 'Their story',
        'evidence' => 'Evidence of their work',
        'links'    => 'Social media links',
    ];
    public const CHANNELS = [
        'whatsapp'  => 'WhatsApp status or groups',
        'social'    => 'Facebook, Instagram, X or TikTok',
        'flier'     => 'Their flier and voting link',
        'community' => 'Church, mosque, school or community groups',
        'media'     => 'Radio, TV or a newspaper',
        'print'     => 'Posters or banners',
        'event'     => 'An event or a rally',
    ];
    public const BACKERS = [
        'nominee'      => 'The nominee themselves',
        'family'       => 'Family and friends',
        'organisation' => 'Their school, church or workplace',
        'leaders'      => 'Community leaders',
        'team'         => 'Our volunteer team',
    ];
    public const HELP = [
        'graphics' => 'A flier or graphics',
        'guide'    => 'A how-to-vote guide',
        'call'     => 'A call from a lead',
        'data'     => 'Airtime or data',
        'event'    => 'Help with an event',
    ];
    /** The list answers, field name => their words. */
    public const LISTS = ['missing' => self::MISSING, 'channels' => self::CHANNELS, 'backers' => self::BACKERS, 'needs' => self::HELP];

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
        $portal = (string) ($f['portal'] ?? ''); $mob = (string) ($f['mobilising'] ?? '');
        $lists = [];
        foreach (self::LISTS as $k => $words) $lists[$k] = array_values(array_intersect(array_keys($words), array_map('strval', (array) ($f[$k] ?? []))));
        // Publicity is the channels ticked, summarised for the one-line view; an older form that
        // still posts the single answer is taken at its word.
        $pub = (string) ($f['publicity'] ?? '');
        if (!isset(self::PUBLICITY[$pub])) {
            $ch = $lists['channels'];
            $pub = $ch === [] ? 'none' : (in_array('flier', $ch, true) ? 'flier' : (in_array('community', $ch, true) ? 'groups' : 'social'));
        }
        $reach = (string) ($f['reach'] ?? '');
        if (!isset(self::PORTAL[$portal]))     return ['ok' => false, 'error' => 'Say where their profile on the portal stands.'];
        if (!isset(self::MOBILISING[$mob]))    return ['ok' => false, 'error' => 'Say where the voting mobilisation stands.'];
        if ($reach !== '' && !isset(self::REACH[$reach])) $reach = '';
        $note = trim(mb_substr(strip_tags((string) ($f['status_note'] ?? '')), 0, 2000));
        $next = trim(mb_substr(strip_tags((string) ($f['next_step'] ?? '')), 0, 500));
        $row = ['cycle_id' => (int) $n->cycle_id, 'nominee_id' => (int) $n->id, 'volunteer_id' => (int) $v->id,
                'portal' => $portal, 'publicity' => $pub, 'mobilising' => $mob, 'note' => $note !== '' ? $note : null,
                'created_at' => Carbon::now()->toDateTimeString()];
        if (SchemaHas::column('gates_vol_nominee_status', 'needs')) {
            $row += ['reach' => $reach !== '' ? $reach : null, 'next_step' => $next !== '' ? $next : null];
            foreach ($lists as $k => $l) $row[$k] = $l !== [] ? json_encode($l) : null;
        }
        try {
            DB::table('gates_vol_nominee_status')->insert($row);
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

    /** A list answer read back as its keys. @return list<string> */
    public static function list(object $s, string $field): array
    {
        $raw = $s->{$field} ?? null;
        $l = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return is_array($l) ? array_values(array_intersect(array_keys(self::LISTS[$field] ?? []), array_map('strval', $l))) : [];
    }

    /** "WhatsApp status or groups, posters or banners" — a list answer in words. */
    public static function listWords(object $s, string $field): string
    {
        $w = array_map(static fn ($k) => self::LISTS[$field][$k], self::list($s, $field));
        // Lower-cased to sit mid-sentence, except a name: "WhatsApp", "Facebook".
        return $w === [] ? '' : implode(', ', array_map(static fn ($x) => preg_match('~^(WhatsApp|Facebook)\b~', $x) ? $x : lcfirst($x), $w));
    }

    /** "Reached · Portal: profile complete · Shared: WhatsApp status or groups · Voting: started asking" */
    public static function words(object $s): string
    {
        $ch = self::listWords($s, 'channels');
        return (isset(self::REACH[$s->reach ?? '']) ? self::REACH[$s->reach] . ' · ' : '')
             . 'Portal: ' . lcfirst(self::PORTAL[$s->portal] ?? '?')
             . ' · ' . ($ch !== '' ? 'Shared: ' . $ch : 'Publicity: ' . lcfirst(self::PUBLICITY[$s->publicity] ?? '?'))
             . ' · Voting: ' . lcfirst(self::MOBILISING[$s->mobilising] ?? '?');
    }

    /** @return array{ok:bool, error?:string, emailed?:int} */
    public static function request(object $lead, int $cycleId, string $message, string $dueOn, ?OtpService $mailer = null, ?callable $send = null): array
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
            $id = (int) DB::table('gates_vol_status_requests')->insertGetId(['cycle_id' => $cycleId, 'volunteer_id' => (int) $lead->id,
                'message' => $msg !== '' ? $msg : self::ASK, 'due_on' => $dueOn, 'created_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'The request could not be saved. Please try again.'];
        }
        return ['ok' => true, 'emailed' => self::emailRequest($id, $lead, $cycleId, $msg !== '' ? $msg : self::ASK, $dueOn, $mailer, $send)];
    }

    /**
     * Everybody who reaches nominees on an award: an outreach grant there, or at a night that
     * presents it ({@see Workspace::places()} reads them the same way). Active volunteers only.
     *
     * @return list<object> volunteer rows with `email`
     */
    public static function team(int $cycleId): array
    {
        $users = [];
        foreach (Grants::holders(Outreach::CAPABILITY, 'cycle', $cycleId) as $g) if ($g->actor_kind === 'member') $users[(int) $g->actor_id] = true;
        try {
            $events = DB::table('gates_grants')->where('capability', Outreach::CAPABILITY)->where('scope_kind', 'event')->distinct()->pluck('scope_id')->all();
        } catch (\Throwable) {
            $events = [];
        }
        foreach ($events as $e) {
            if (!isset(EventsFront::cyclesFor((int) $e)[$cycleId])) continue;
            foreach (Grants::holders(Outreach::CAPABILITY, 'event', (int) $e) as $g) if ($g->actor_kind === 'member') $users[(int) $g->actor_id] = true;
        }
        if ($users === []) return [];
        return DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')
            ->whereIn('v.user_id', array_keys($users))->where('v.status', 'active')->get(['v.*', 'u.email', 'u.name as user_name'])->all();
    }

    /** The request, emailed once to each person on the award's team. Returns how many it reached. */
    private static function emailRequest(int $reqId, object $lead, int $cycleId, string $msg, string $dueOn, ?OtpService $mailer, ?callable $send): int
    {
        if ($mailer === null && $send === null) return 0;
        $award = Workspace::scopeName('cycle', $cycleId);
        $by = trim((string) ($lead->display_name ?? '')) ?: (string) (DB::table('gates_users')->where('id', (int) ($lead->user_id ?? 0))->value('name') ?? '');
        $by = $by !== '' ? $by : 'Your lead';
        $href = rtrim(SiteUrl::base(), '/') . '/volunteer/cycle/' . $cycleId . '/nominees/update';
        $day = Workspace::day($dueOn);
        $sent = 0;
        foreach (self::team($cycleId) as $r) {
            if ((int) $r->id === (int) $lead->id || !filter_var((string) $r->email, FILTER_VALIDATE_EMAIL)) continue;
            $key = 'vol-status-request:' . $reqId;
            if (!BroadcastLog::claim($key, (string) $r->email)) continue;
            $n = count(array_filter(Outreach::nominees($r, $cycleId), static fn ($x) => !$x->stopped));
            $subject = $by . ' asked for an update on your nominees';
            $lines = ['Hello ' . Volunteers::firstName($r) . ',',
                      $by . ' asked everybody on ' . $award . ' for an update on every nominee by ' . $day . ':',
                      '“' . $msg . '”',
                      'You can send each one from the nominee’s page in a minute: where their profile stands, where they’ve been shared, the voting mobilisation and anything that would help.'
                      . ($n > 0 ? ' The link below opens the ' . ($n === 1 ? 'one nominee' : $n . ' nominees') . ' still to update, and takes you from one to the next.' : '')];
            $plain = implode("\n\n", $lines) . "\n\nSend your updates: " . $href;
            $html = implode('', array_map(static fn (string $l): string => '<p>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</p>', $lines))
                  . '<p><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">Send your updates</a></p>';
            $ok = false;
            try {
                $ok = $send ? (bool) $send((string) $r->email, $subject, $html, $plain)
                            : (($mailer->sendBranded((string) $r->email, $subject, $html, $plain, 'Volunteering')['success'] ?? false) === true);
            } catch (\Throwable) {}
            BroadcastLog::settle($key, (string) $r->email, $ok);
            if ($ok) $sent++;
        }
        return $sent;
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
