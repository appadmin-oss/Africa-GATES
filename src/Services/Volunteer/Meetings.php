<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\GoogleMeetService;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * MEETINGS BETWEEN VOLUNTEERS (owner, 29 Oct 2026), ported from the Afrovanguard site's
 * lib/Meetings.php and cut down to what a volunteer needs: schedule one, invite the people you
 * volunteer with, a Meet link or a place, an agenda, answers, a reminder, notes afterwards.
 *
 * ── WHO MEETS WHOM ───────────────────────────────────────────────────────────
 *
 * An invitee is somebody the organiser already volunteers WITH: a shift or a volunteer grant
 * at one of the organiser's own places (Workspace::places). Not a directory of every
 * volunteer — that would be a way to reach strangers. At most MAX_PEOPLE, and each invitation
 * is a message to one person about a meeting they are in, so this is not a broadcast (the
 * owner: none). Somebody under 18 never organises, and is offered as an invitee only to a team
 * lead: a private call between an adult and a child volunteer is the safeguarding case.
 *
 * ── WHO SEES IT ──────────────────────────────────────────────────────────────
 *
 * Only the organiser and the people invited, checked on the server for every read and every
 * change ({@see get()}). Anybody else is told it does not exist.
 *
 * ── THE LINK ─────────────────────────────────────────────────────────────────
 *
 * Online meetings take a Meet link from Google where the calendar integration is set up
 * (GoogleMeetService, the same one interviews use), and otherwise a link the organiser pastes.
 * A pasted link must be one of a few meeting services, parsed by host: a workspace that sends
 * people to any URL a volunteer types is a phishing tool with our name on the email.
 *
 * Afrovanguard's transcript import and AI notetaker are not ported: a recording of volunteers
 * needs its own consent rules. The organiser writes notes afterwards instead.
 */
final class Meetings
{
    public const MAX_PEOPLE = 12;
    public const REPEATS = ['once' => 'Doesn’t repeat', 'weekly' => 'Every week', 'fortnightly' => 'Every two weeks', 'monthly' => 'Every month'];
    public const LENGTHS = [15 => '15 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '1 hour', 90 => '1½ hours', 120 => '2 hours'];
    /** Meeting services a pasted link may point at, by host (and its subdomains). */
    public const HOSTS = ['meet.google.com', 'zoom.us', 'teams.microsoft.com', 'teams.live.com', 'whereby.com', 'meet.jit.si'];

    /** For tests: replaces Google, `fn(array $opts): array` shaped like createSpace()'s answer. */
    public static ?\Closure $makeLink = null;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_meetings');
    }

    /** May this person organise one? Approved, and 18 or older. */
    public static function canOrganise(object $v): bool
    {
        return in_array(Volunteers::role($v), ['active', 'lead'], true) && self::adult($v);
    }

    public static function adult(object $v): bool
    {
        return $v->dob !== null && (string) $v->dob <= date('Y-m-d', strtotime('-18 years'));
    }

    /**
     * The people this organiser may invite: everybody who volunteers at one of their places,
     * by name, with where. Under-18s only for a team lead.
     *
     * @return array<int,array{name:string, where:string}> by volunteer id
     */
    public static function people(object $v): array
    {
        $lead = Volunteers::role($v) === 'lead';
        $out = [];
        foreach (Workspace::places($v) as $p) {
            $ids = DB::table('gates_vol_signups as s')->join('gates_vol_shifts as h', 'h.id', '=', 's.shift_id')
                ->where('h.scope_kind', $p['kind'])->where('h.scope_id', $p['id'])->whereIn('s.status', ['on', 'kept'])->pluck('s.volunteer_id')->all();
            $ids = array_merge($ids, DB::table('gates_grants as g')->join('gates_volunteers as v', 'v.user_id', '=', 'g.actor_id')
                ->where('g.actor_kind', 'member')->where('g.scope_kind', $p['kind'])->where('g.scope_id', $p['id'])->where('g.capability', 'like', 'vol.%')
                ->whereNull('g.revoked_at')->where('g.expires_at', '>', date('Y-m-d H:i:s'))->pluck('v.id')->all());
            $ids = array_diff(array_unique(array_map('intval', $ids)), [(int) $v->id], array_keys($out));
            if (!$ids) continue;
            foreach (DB::table('gates_volunteers')->whereIn('id', $ids)->where('status', 'active')->get() as $r) {
                if (!$lead && !self::adult($r)) continue;
                $out[(int) $r->id] = ['name' => Volunteers::name($r), 'where' => $p['name']];
            }
        }
        uasort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Schedule a meeting and invite people.
     *
     * @param ?callable(string $to, string $subject, string $html, string $plain): bool $send for tests
     * @return array{ok:bool, error?:string, id?:int, invited?:int}
     */
    public static function schedule(object $v, array $f, ?OtpService $mailer = null, ?callable $send = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Meetings are not set up yet.'];
        if (!self::canOrganise($v)) return ['ok' => false, 'error' => self::adult($v) ? 'Meetings open once you’ve done a shift or logged hours.' : 'A team lead sets up meetings for volunteers under 18.'];
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($f['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > 160) return ['ok' => false, 'error' => 'Give it a short title.'];
        $at = strtotime(trim((string) ($f['date'] ?? '')) . ' ' . trim((string) ($f['time'] ?? '')));
        if (!$at || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['date'] ?? '')) || !preg_match('/^\d{2}:\d{2}$/', (string) ($f['time'] ?? ''))) return ['ok' => false, 'error' => 'Pick a date and a time.'];
        if ($at < time() + 300) return ['ok' => false, 'error' => 'Pick a time that’s still to come.'];
        if ($at > strtotime('+1 year')) return ['ok' => false, 'error' => 'Pick a time within the next year.'];
        $minutes = (int) ($f['minutes'] ?? 30);
        if (!isset(self::LENGTHS[$minutes])) return ['ok' => false, 'error' => 'Pick how long it is.'];
        $repeats = (string) ($f['repeats'] ?? 'once');
        if (!isset(self::REPEATS[$repeats])) return ['ok' => false, 'error' => 'Pick whether it repeats.'];
        $times = $repeats === 'once' ? 1 : max(2, min(12, (int) ($f['times'] ?? 4)));

        $allowed = self::people($v);
        $ids = array_values(array_unique(array_map('intval', (array) ($f['people'] ?? []))));
        if ($ids === []) return ['ok' => false, 'error' => 'Choose who to invite.'];
        if (count($ids) > self::MAX_PEOPLE) return ['ok' => false, 'error' => 'Invite ' . self::MAX_PEOPLE . ' people at most. For more, ask your team lead.'];
        foreach ($ids as $i) if (!isset($allowed[$i])) return ['ok' => false, 'error' => 'You can only invite people you volunteer with.'];

        $mode = ($f['mode'] ?? '') === 'place' ? 'place' : 'online';
        $location = mb_substr(trim((string) ($f['location'] ?? '')), 0, 200);
        $url = trim((string) ($f['link'] ?? ''));
        $eventId = null;
        if ($mode === 'place') {
            if ($location === '') return ['ok' => false, 'error' => 'Say where you’re meeting.'];
            $url = '';
        } elseif ($url !== '') {
            if (!self::linkOk($url)) return ['ok' => false, 'error' => 'Paste a Google Meet, Zoom, Teams, Whereby or Jitsi link, starting https://'];
        } else {
            $emails = DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->whereIn('v.id', $ids)->pluck('u.email')->all();
            $link = self::$makeLink ? (self::$makeLink)(['title' => $title, 'start' => date('Y-m-d H:i:s', $at), 'minutes' => $minutes, 'guests' => $emails])
                : (static function () use ($title, $at, $minutes, $emails, $f): array {
                    $g = GoogleMeetService::boot();
                    if (!$g->canSchedule()) return ['ok' => false];
                    return $g->createSpace(['title' => $title, 'description' => mb_substr((string) ($f['agenda'] ?? ''), 0, 2000),
                        'start' => gmdate('Y-m-d H:i:s', $at), 'minutes' => min(180, $minutes), 'guests' => $emails]);
                })();
            if (!($link['ok'] ?? false) || ($link['meet_url'] ?? '') === '') return ['ok' => false, 'error' => 'We couldn’t make a Meet link just now. Paste a meeting link instead.'];
            [$url, $eventId] = [(string) $link['meet_url'], ((string) ($link['event_id'] ?? '')) ?: null];
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) DB::transaction(static function () use ($v, $title, $at, $minutes, $repeats, $times, $mode, $location, $url, $eventId, $f, $ids, $now): int {
            $id = (int) DB::table('gates_vol_meetings')->insertGetId([
                'organiser_id' => (int) $v->id, 'title' => $title, 'starts_at' => date('Y-m-d H:i:s', $at), 'minutes' => $minutes,
                'repeats' => $repeats, 'times' => $times, 'mode' => $mode, 'location' => $location ?: null, 'meet_url' => $url ?: null, 'event_id' => $eventId,
                'agenda' => mb_substr(trim((string) ($f['agenda'] ?? '')), 0, 2000) ?: null, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now]);
            foreach ($ids as $i) DB::table('gates_vol_meeting_people')->insert(['meeting_id' => $id, 'volunteer_id' => $i, 'answer' => 'invited', 'created_at' => $now]);
            return $id;
        });
        $m = DB::table('gates_vol_meetings')->where('id', $id)->first();
        $sent = 0;
        foreach (self::emails($ids) as $vid => [$email, $first]) {
            $lines = ['Hello ' . $first . ',', Volunteers::name($v) . ' has invited you to a meeting: ' . $title . '.', ...self::factLines($m),
                      'Let them know if you can come.'];
            if (self::mail('vol-meet-inv:' . $id, $email, 'Meeting: ' . $title, $lines, '/volunteer/meetings/' . $id, $mailer, $send)) $sent++;
        }
        return ['ok' => true, 'id' => $id, 'invited' => $sent];
    }

    /** Is this a link to one of the meeting services, over https? Parsed, never substring-matched. */
    public static function linkOk(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return false;
        $host = strtolower((string) ($p['host'] ?? ''));
        foreach (self::HOSTS as $h) if ($host === $h || str_ends_with($host, '.' . $h)) return true;
        return false;
    }

    /**
     * One meeting, only for the organiser or somebody invited; null for anybody else.
     * Carries ->mine (organiser), ->answer (this person's), ->people, ->next, ->dates, ->past.
     */
    public static function get(object $v, int $id): ?object
    {
        if (!self::ready()) return null;
        $m = DB::table('gates_vol_meetings')->where('id', $id)->first();
        if (!$m) return null;
        $mine = (int) $m->organiser_id === (int) $v->id;
        $me = DB::table('gates_vol_meeting_people')->where('meeting_id', $id)->where('volunteer_id', $v->id)->first();
        if (!$mine && !$me) return null;
        $m->mine = $mine;
        $m->answer = $me ? (string) $me->answer : null;
        $m->organiser = ($o = Volunteers::byId((int) $m->organiser_id)) ? Volunteers::name($o) : 'A volunteer';
        $m->people = [];
        foreach (DB::table('gates_vol_meeting_people')->where('meeting_id', $id)->orderBy('id')->get() as $p) {
            $pv = Volunteers::byId((int) $p->volunteer_id);
            $m->people[] = (object) ['name' => $pv ? Volunteers::name($pv) : 'A volunteer', 'answer' => (string) $p->answer, 'me' => (int) $p->volunteer_id === (int) $v->id];
        }
        self::times($m);
        return $m;
    }

    /** @return list<object> meetings this person organised or was invited to: coming first, soonest first; or past, latest first */
    public static function mine(object $v, bool $past = false): array
    {
        if (!self::ready()) return [];
        $ids = DB::table('gates_vol_meeting_people')->where('volunteer_id', $v->id)->pluck('meeting_id')->all();
        $out = [];
        foreach (DB::table('gates_vol_meetings')->where(static fn ($w) => $w->where('organiser_id', $v->id)->orWhereIn('id', $ids ?: [0]))->orderByDesc('starts_at')->limit(200)->get() as $m) {
            self::times($m);
            $done = $m->past || $m->status === 'cancelled';
            if ($done !== $past) continue;
            $m->mine = (int) $m->organiser_id === (int) $v->id;
            $m->answer = $m->mine ? null : (string) DB::table('gates_vol_meeting_people')->where('meeting_id', $m->id)->where('volunteer_id', $v->id)->value('answer');
            $m->going = (int) DB::table('gates_vol_meeting_people')->where('meeting_id', $m->id)->where('answer', 'yes')->count();
            $out[] = $m;
        }
        if (!$past) usort($out, static fn ($a, $b) => strcmp((string) $a->next, (string) $b->next));
        return $out;
    }

    /** Can you come? yes or no, from somebody invited, to a meeting still to come. */
    public static function answer(object $v, int $id, string $answer): bool
    {
        if (!in_array($answer, ['yes', 'no'], true) || !($m = self::get($v, $id)) || $m->mine || $m->past || $m->status !== 'scheduled') return false;
        return DB::table('gates_vol_meeting_people')->where('meeting_id', $id)->where('volunteer_id', $v->id)
            ->update(['answer' => $answer, 'answered_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /** Notes afterwards, by the organiser only, once it has started. */
    public static function notes(object $v, int $id, string $notes): array
    {
        $m = self::get($v, $id);
        if (!$m || !$m->mine) return ['ok' => false, 'error' => 'Only the organiser writes the notes.'];
        if (strtotime((string) $m->starts_at) > time()) return ['ok' => false, 'error' => 'Notes open once the meeting has started.'];
        $notes = mb_substr(trim($notes), 0, 8000);
        DB::table('gates_vol_meetings')->where('id', $id)->update(['notes' => $notes ?: null, 'notes_at' => $notes ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    /** Called off by the organiser; everybody who hadn't said no is told. Never deleted. */
    public static function cancel(object $v, int $id, ?OtpService $mailer = null, ?callable $send = null): bool
    {
        $m = self::get($v, $id);
        if (!$m || !$m->mine || $m->status !== 'scheduled' || $m->past) return false;
        DB::table('gates_vol_meetings')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        if ($m->event_id && !self::$makeLink) {
            try { GoogleMeetService::boot()->cancelEvent(['event_id' => (string) $m->event_id, 'notify' => false]); } catch (\Throwable) {}
        }
        $ids = DB::table('gates_vol_meeting_people')->where('meeting_id', $id)->where('answer', '!=', 'no')->pluck('volunteer_id')->all();
        foreach (self::emails(array_map('intval', $ids)) as [$email, $first]) {
            self::mail('vol-meet-off:' . $id, $email, 'Called off: ' . $m->title,
                ['Hello ' . $first . ',', $m->organiser . ' has called off ' . $m->title . ($m->repeats === 'once' ? '' : ', and the meetings after it') . '.', 'There’s nothing you need to do.'],
                '/volunteer/meetings', $mailer, $send);
        }
        return true;
    }

    /**
     * Day-before reminders, for Reminders::due(): [claim key, email, subject, lines, link].
     * One per occurrence per person, to the organiser and everybody who hasn't said no.
     *
     * @return list<array{0:string,1:string,2:string,3:list<string>,4:string}>
     */
    public static function due(int $hours): array
    {
        if (!self::ready()) return [];
        $out = [];
        $soon = time() + $hours * 3600;
        foreach (DB::table('gates_vol_meetings')->where('status', 'scheduled')->where('starts_at', '<=', date('Y-m-d H:i:s', $soon))->limit(500)->get() as $m) {
            self::times($m);
            $i = array_search($m->next, $m->dates, true);
            if ($m->next === null || $i === false || strtotime($m->next) <= time() || strtotime($m->next) > $soon) continue;
            $ids = DB::table('gates_vol_meeting_people')->where('meeting_id', $m->id)->where('answer', '!=', 'no')->pluck('volunteer_id')->all();
            $ids[] = (int) $m->organiser_id;
            foreach (self::emails(array_map('intval', $ids)) as $vid => [$email, $first]) {
                $out[] = ['vol-meet:' . $m->id . ':' . $i, $email, 'Tomorrow: ' . $m->title, ['Hello ' . $first . ',', 'A reminder of your meeting: ' . $m->title . '.', ...self::factLines($m)], '/volunteer/meetings/' . $m->id];
            }
        }
        return $out;
    }

    /** The facts in the order a person needs them; read aloud as often as seen. */
    public static function factLines(object $m): array
    {
        self::times($m);
        $at = (string) ($m->next ?? $m->starts_at);
        $lines = ['When: ' . Workspace::day($at) . ', ' . date('H:i', (int) strtotime($at)) . ', for ' . self::LENGTHS[(int) $m->minutes] . '.'];
        if ($m->repeats !== 'once') $lines[] = 'Repeats: ' . mb_strtolower(self::REPEATS[$m->repeats]) . ', ' . $m->times . ' times.';
        $lines[] = $m->mode === 'place' ? 'Where: ' . $m->location . '.' : 'Online. The link is on the meeting page.';
        if ($m->agenda) $lines[] = 'Agenda: ' . $m->agenda;
        return $lines;
    }

    /** Every start of a meeting, the next one still to come, and whether it is all over. */
    private static function times(object $m): void
    {
        if (isset($m->dates)) return;
        $base = (int) strtotime((string) $m->starts_at);
        $m->dates = [];
        for ($i = 0; $i < ($m->repeats === 'once' ? 1 : max(1, (int) $m->times)); $i++) {
            $m->dates[] = date('Y-m-d H:i:s', match ($m->repeats) {
                'weekly' => (int) strtotime('+' . $i . ' weeks', $base),
                'fortnightly' => (int) strtotime('+' . (2 * $i) . ' weeks', $base),
                // A meeting on the 31st falls on the month's last day where there is no 31st.
                'monthly' => (int) strtotime(($first = date('Y-m', (int) strtotime('+' . $i . ' months', (int) strtotime(date('Y-m-01', $base))))) . '-'
                             . min((int) date('j', $base), (int) date('t', (int) strtotime($first . '-01'))) . date(' H:i:s', $base)),
                default => $base,
            });
        }
        $m->next = null;
        foreach ($m->dates as $d) if (strtotime($d) + (int) $m->minutes * 60 > time()) { $m->next = $d; break; }
        $m->past = $m->next === null;
    }

    /** @return array<int,array{0:string,1:string}> volunteer id → [email, first name], only deliverable addresses, never somebody on a break */
    private static function emails(array $ids): array
    {
        if ($ids === []) return [];
        $out = [];
        foreach (DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')->whereIn('v.id', $ids)->get(['v.*', 'u.email']) as $r) {
            if ($r->status !== 'active' || !filter_var((string) $r->email, FILTER_VALIDATE_EMAIL)) continue;
            $out[(int) $r->id] = [(string) $r->email, Volunteers::firstName($r)];
        }
        return $out;
    }

    /** One message to one person, claimed first so a retry never sends it twice. */
    private static function mail(string $key, string $email, string $subject, array $lines, string $href, ?OtpService $mailer, ?callable $send): bool
    {
        if (!BroadcastLog::claim($key, $email)) return false;
        $site = rtrim(SiteUrl::base(), '/');
        $plain = implode("\n", $lines) . "\n\n" . $site . $href;
        $html = implode('', array_map(static fn (string $l): string => '<p>' . htmlspecialchars($l) . '</p>', $lines))
              . '<p><a href="' . htmlspecialchars($site . $href) . '">Open it in your volunteering</a></p>';
        $ok = false;
        try {
            $ok = $send ? (bool) $send($email, $subject, $html, $plain)
                        : ($mailer !== null && (($mailer->sendBranded($email, $subject, $html, $plain, 'Volunteering')['success'] ?? false) === true));
        } catch (\Throwable) {}
        BroadcastLog::settle($key, $email, $ok);
        return $ok;
    }
}
