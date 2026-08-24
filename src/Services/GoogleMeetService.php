<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;

/**
 * Google Meet, reached the only way this deployment can reach it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY NOT THE GOOGLE API DIRECTLY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Creating a Meet link means the Calendar API with `conferenceDataVersion=1`, or the Meet
 * REST API's `spaces.create`. Both need OAuth as a *user* — a service account cannot own a
 * Meet space unless the Workspace admin has set up domain-wide delegation, which is a
 * different organisation's IT decision and not something a nomination platform can assume.
 *
 * That leaves a refresh-token dance: a consent screen, a token store, a rotation policy,
 * and a client library. On a cPanel host with no shell, no queue daemon and no way to run
 * an interactive consent flow, every one of those is a thing that breaks quietly six months
 * later when nobody remembers it exists.
 *
 * This platform already has a door into Google that avoids all of it. {@see GoogleSheetsService}
 * posts to an Apps Script web app deployed by the operator with "execute as: me". Inside
 * that script, `Calendar.Events.insert` and the Meet REST API run AS THE OPERATOR'S OWN
 * GOOGLE ACCOUNT with no token for us to hold, no consent screen to re-run, and no secret
 * on this server that could leak a Google identity. The operator's existing deployment is
 * the credential.
 *
 * So: same door, two new actions. `config/AfricaGATES_AppScript.gs` carries them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SHARED SECRET, AND WHY IT IS NEW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Apps Script is deployed with "access: anyone", because the platform posts to it
 * unauthenticated. For appending a row to a private sheet that is a tolerable trade — the
 * worst a stranger with the URL can do is write junk into a spreadsheet.
 *
 * Creating calendar events and reading meeting transcripts is not that. Anyone holding the
 * /exec URL could book meetings in the operator's calendar or pull the text of their
 * conversations. So the two new actions require `GAS_SECRET` to match a constant in the
 * script, and the script must refuse them outright when that constant is left empty. Sheet
 * writes keep working exactly as before, so an existing deployment does not break on
 * upload — it simply cannot do the new things until the secret is set at both ends.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING HERE IS REQUIRED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every method degrades to "do it by hand", and the admin screens say so in words. A Meet
 * link can be pasted; a transcript can be pasted. This service makes the common path one
 * press instead of six, and its absence costs convenience rather than the feature — which
 * is the only safe shape for an integration that depends on somebody else's Apps Script
 * deployment still being there.
 */
final class GoogleMeetService
{
    /** Longer than a page render should ever block for; these are called from jobs and buttons. */
    private const TIMEOUT_CREATE = 15;
    private const TIMEOUT_FETCH  = 25;

    public function __construct(
        private readonly string $url = '',
        private readonly string $secret = '',
    ) {}

    public static function boot(): self
    {
        return new self(
            (string) Env::get('GAS_URL', ''),
            (string) Env::get('GAS_SECRET', ''),
        );
    }

    /**
     * The calendar event's title for a sitting.
     *
     * One definition, because it is used twice and the two uses are a day apart: once when
     * the event is created, and once when the Apps Script looks for the transcript Google
     * named after that event. If they ever disagree the fetch stops finding anything, and
     * the symptom is "no transcript" — indistinguishable from nobody having switched
     * transcription on.
     */
    public static function eventTitle(string $nominee): string
    {
        return mb_substr('Africa GATES interview — ' . trim($nominee), 0, 200);
    }

    /** The door exists. */
    public function isConfigured(): bool
    {
        return $this->url !== '' && filter_var($this->url, FILTER_VALIDATE_URL) !== false;
    }

    /** The door exists AND the privileged actions are unlocked at this end. */
    public function canSchedule(): bool
    {
        return $this->isConfigured() && $this->secret !== '';
    }

    /**
     * Why the operator cannot use the automatic path, in words they can act on.
     *
     * Returned rather than logged: this appears on the admin screen beside the "paste a
     * link" box, because a button that silently does nothing is how the last three
     * half-features on this platform stayed broken for months.
     */
    public function why(): string
    {
        if (!$this->isConfigured()) {
            return 'GAS_URL is not set, so Meet links cannot be created automatically. '
                 . 'Create the meeting in Google Calendar and paste its link here.';
        }
        if ($this->secret === '') {
            return 'GAS_SECRET is not set. Creating calendar events needs a shared secret so that '
                 . 'nobody holding the Apps Script URL can book meetings in your calendar. Set '
                 . 'GAS_SECRET in .env and the matching SECRET in the Apps Script, then redeploy it. '
                 . 'Until then, paste a Meet link in by hand.';
        }
        return '';
    }

    /**
     * Create a calendar event with a Meet link, and invite whoever is given.
     *
     * ── ON "CO-HOST", BECAUSE IT IS THE WORD THE REQUEST ARRIVES IN ─────────
     *
     * Google Calendar's Events resource has NO co-host field. Co-host is a Meet concept,
     * granted through the Meet REST API's `spaces.members` (role COHOST) — which needs
     * Google Workspace and a scope this integration does not hold — or by the host with one
     * click inside the call.
     *
     * What an EVENT can express, and what people usually mean when they ask, is two things:
     * the guest is INVITED, so Meet admits them straight in instead of making them knock and
     * they get the invitation and the reminders; and `guestsCanModify` lets them change the
     * event. Both are set here. Promotion to a true Meet co-host stays a click for the host,
     * and the admin screen says so rather than implying otherwise.
     *
     * @param array{title:string, description?:string, start:string, minutes?:int,
     *              timezone?:string, guests?:list<string>, guests_can_edit?:bool} $opts
     *              start is UTC 'Y-m-d H:i:s'
     * @return array{ok:bool, meet_url:string, meet_code:string, event_id:string, message:string}
     */
    public function createSpace(array $opts): array
    {
        $no = fn (string $m): array => ['ok' => false, 'meet_url' => '', 'meet_code' => '',
                                        'event_id' => '', 'message' => $m];
        if (!$this->canSchedule()) return $no($this->why());

        $start = strtotime((string) ($opts['start'] ?? ''));
        if (!$start) return $no('That start time could not be read.');
        $mins = max(10, min(180, (int) ($opts['minutes'] ?? 30)));

        $res = $this->call('meet.create', [
            'title'       => mb_substr((string) ($opts['title'] ?? 'Africa GATES interview'), 0, 200),
            'description' => mb_substr((string) ($opts['description'] ?? ''), 0, 2000),
            // ISO 8601 with the offset, so the script does not have to guess a zone.
            'startIso'    => gmdate('Y-m-d\TH:i:s\Z', $start),
            'endIso'      => gmdate('Y-m-d\TH:i:s\Z', $start + $mins * 60),
            'timezone'    => (string) ($opts['timezone'] ?? 'Africa/Lagos'),
            'guests'      => array_values(array_filter(array_map(
                static fn ($e): string => trim((string) $e),
                (array) ($opts['guests'] ?? [])
            ), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)),
            // Whether those guests may edit the event itself. Not "co-host" — see
            // createSpace()'s note — but the only elevation a calendar event can express.
            'guestsCanModify' => !empty($opts['guests_can_edit']),
        ], self::TIMEOUT_CREATE);

        if (!($res['ok'] ?? false)) {
            return $no((string) ($res['message'] ?? 'The Apps Script did not answer.'));
        }

        $url  = trim((string) ($res['meetUrl'] ?? ''));
        $code = InterviewService::meetCode($url);
        if ($url === '') {
            return $no('The event was created but Google returned no Meet link. The Calendar '
                     . 'advanced service may not be enabled in the Apps Script project — '
                     . 'Services → add "Calendar API". Meanwhile, open the event and paste its '
                     . 'Meet link in by hand.');
        }

        return ['ok' => true, 'meet_url' => $url, 'meet_code' => $code,
                'event_id' => trim((string) ($res['eventId'] ?? '')),
                'message'  => 'Meet link created' . ($code !== '' ? ' (' . $code . ')' : '') . '.'];
    }

    /**
     * Create or move a sitting in the operator's calendar. Idempotent on `$key`.
     *
     * ── WHY THIS EXISTS BESIDE createSpace() ─────────────────────────────────
     *
     * {@see createSpace()} calls `meet.create`, which inserts an event with a fresh
     * conference uuid every time. That is correct for the conference and means the CALL is
     * not idempotent: run it twice for one sitting and the operator has two events, two
     * Meet links and two sets of invitations to a meeting that happens once. There was also
     * no update path at all, so rescheduling produced a second event and left every guest
     * holding a stale invitation.
     *
     * This carries a STABLE key — stamped into the event's private extended properties, so
     * the calendar itself is the record of which sitting it is — and the script looks it up
     * before deciding whether to insert or patch. Re-running is then a no-op returning the
     * same event and the same link, which is what makes it safe on a cron.
     *
     * The key is not stored on our side deliberately: a mapping row and a calendar can
     * disagree, and when they do the calendar is right and the row is a lie.
     *
     * @param array{key:string, start:string, minutes?:int, title?:string, description?:string,
     *              timezone?:string, guests?:list<string>, notify?:bool, with_meet?:bool,
     *              calendar_id?:string} $opts
     * @return array{ok:bool, created:bool, meet_url:string, meet_code:string, event_id:string,
     *               html_link:string, message:string}
     */
    /**
     * READ an event back out of the calendar — the missing direction.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS MATTERS MORE THAN IT LOOKS
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Everything here pushed. The calendar is where the appointment actually LIVES, and
     * our `gates_interviews` row is a copy of it — so an organiser who drags the meeting to
     * Thursday, which is the entire reason for putting it in a calendar, changed the truth
     * and told us nothing.
     *
     * The recording bot is dispatched off OUR row. So the bot turned up on Tuesday to an
     * empty room, and the interview happened on Thursday with nobody recording it — and
     * the only symptom was a missing transcript afterwards, at the point where nobody can
     * do anything about it.
     *
     * The Meet link has the same shape of problem: a conference re-created, or an event
     * rebuilt by hand after a mistake, gives a new URL the platform never learns, and the
     * bot is sent to a room that no longer opens.
     *
     * @param array{event_id?:string, key?:string, calendar_id?:string} $opts
     * @return array{ok:bool, found:bool, start:string, meet_url:string, event_id:string,
     *               html_link:string, message:string}
     */
    public function readEvent(array $opts): array
    {
        $no = fn (string $m, bool $found = false): array => ['ok' => false, 'found' => $found,
            'start' => '', 'meet_url' => '', 'event_id' => '', 'html_link' => '', 'message' => $m];

        if (!$this->canSchedule()) return $no($this->why());

        $eventId = trim((string) ($opts['event_id'] ?? ''));
        $key     = trim((string) ($opts['key'] ?? ''));
        if ($eventId === '' && $key === '') return $no('A read needs an event id or a key.');

        $res = $this->call('calendar.read', array_filter([
            'eventId'    => $eventId !== '' ? $eventId : null,
            'key'        => $key !== '' ? mb_substr($key, 0, 120) : null,
            'calendarId' => trim((string) ($opts['calendar_id'] ?? '')) ?: null,
        ], static fn ($v): bool => $v !== null));

        if (!($res['ok'] ?? false)) {
            return $no((string) ($res['message'] ?? 'The Apps Script did not answer.'));
        }

        // Gone is an ANSWER, not a failure: the caller's correct response is to stop
        // expecting a meeting, and reporting it as an error would read as a broken
        // integration and have somebody go looking for one.
        if (empty($res['found'])) {
            return ['ok' => true, 'found' => false, 'start' => '', 'meet_url' => '',
                    'event_id' => '', 'html_link' => '',
                    'message' => 'That event is no longer on the calendar.'];
        }

        // Normalised to the platform's storage shape — UTC 'Y-m-d H:i:s'. Google returns
        // ISO-8601 WITH an offset, and storing that verbatim is the T-separated-datetime
        // trap this codebase has already been bitten by: MySQL normalises it into a
        // TIMESTAMP column and SQLite keeps the string, so the two databases then disagree
        // about when the interview is.
        $startRaw = trim((string) ($res['startIso'] ?? ''));
        $ts       = $startRaw !== '' ? strtotime($startRaw) : false;

        return [
            'ok'        => true,
            'found'     => true,
            'start'     => $ts ? gmdate('Y-m-d H:i:s', $ts) : '',
            'meet_url'  => trim((string) ($res['meetUrl'] ?? '')),
            'event_id'  => trim((string) ($res['eventId'] ?? '')),
            'html_link' => trim((string) ($res['htmlLink'] ?? '')),
            'message'   => 'Read from the calendar.',
        ];
    }

    public function syncEvent(array $opts): array
    {
        $no = fn (string $m): array => ['ok' => false, 'created' => false, 'meet_url' => '',
                                        'meet_code' => '', 'event_id' => '', 'html_link' => '',
                                        'message' => $m];
        if (!$this->canSchedule()) return $no($this->why());

        $key = trim((string) ($opts['key'] ?? ''));
        if ($key === '') return $no('A calendar sync needs a stable key, or it duplicates the event.');

        $start = strtotime((string) ($opts['start'] ?? ''));
        if (!$start) return $no('That start time could not be read.');
        $mins = max(10, min(180, (int) ($opts['minutes'] ?? 30)));

        $res = $this->call('calendar.sync', [
            'key'         => mb_substr($key, 0, 120),
            'title'       => mb_substr((string) ($opts['title'] ?? 'Africa GATES interview'), 0, 200),
            'description' => mb_substr((string) ($opts['description'] ?? ''), 0, 2000),
            'startIso'    => gmdate('Y-m-d\TH:i:s\Z', $start),
            'endIso'      => gmdate('Y-m-d\TH:i:s\Z', $start + $mins * 60),
            'timezone'    => (string) ($opts['timezone'] ?? 'Africa/Lagos'),
            'guests'      => self::emails((array) ($opts['guests'] ?? [])),
            'notify'      => ($opts['notify'] ?? true) !== false,
            'withMeet'    => ($opts['with_meet'] ?? true) !== false,
            'calendarId'  => (string) ($opts['calendar_id'] ?? 'primary'),
        ], self::TIMEOUT_CREATE);

        if (!($res['ok'] ?? false)) {
            return $no((string) ($res['message'] ?? 'The Apps Script did not answer.'));
        }

        $url = trim((string) ($res['meetUrl'] ?? ''));

        return ['ok' => true, 'created' => (bool) ($res['created'] ?? false),
                'meet_url' => $url, 'meet_code' => InterviewService::meetCode($url),
                'event_id' => trim((string) ($res['eventId'] ?? '')),
                'html_link' => trim((string) ($res['htmlLink'] ?? '')),
                'message' => (string) ($res['message'] ?? 'Synced.')];
    }

    /**
     * Take a sitting out of the calendar.
     *
     * Idempotent: cancelling one that is already gone reports success, because "it is not
     * there" is the state the caller asked for. Guests ARE notified by default — a
     * cancellation nobody is told about is the guest turning up.
     *
     * @return array{ok:bool, cancelled:bool, message:string}
     */
    public function cancelEvent(string $key, bool $notify = true, string $calendarId = 'primary'): array
    {
        if (!$this->canSchedule()) return ['ok' => false, 'cancelled' => false, 'message' => $this->why()];
        if (trim($key) === '')    return ['ok' => false, 'cancelled' => false,
                                          'message' => 'A cancel needs the key the event was created with.'];

        $res = $this->call('calendar.cancel', [
            'key' => mb_substr(trim($key), 0, 120), 'notify' => $notify, 'calendarId' => $calendarId,
        ], self::TIMEOUT_CREATE);

        return ['ok' => (bool) ($res['ok'] ?? false),
                'cancelled' => (bool) ($res['cancelled'] ?? false),
                'message' => (string) ($res['message'] ?? 'The Apps Script did not answer.')];
    }

    /**
     * Times an interview could actually be booked into.
     *
     * ── THIS IS NOT A GOOGLE APPOINTMENT SCHEDULE ────────────────────────────
     *
     * Google's Appointment Schedules — the booking pages with a shareable URL — have no API
     * at any scope: not in Calendar v3, not in the Apps Script advanced service. So this
     * cannot read one and does not pretend to. It computes open slots from the calendar's
     * own free/busy inside working hours the caller passes in, which is the honest feature
     * that can be built from what exists.
     *
     * Booking a slot is then {@see syncEvent()} at that time, so the round trip is:
     * slots → the nominee picks one → sync → they get an invitation with a Meet link.
     *
     * `lead_minutes` defaults to two hours because a slot offered fifteen minutes from now
     * is one a judge cannot be in, and it is the slot somebody books.
     *
     * @param array{from:string, to:string, minutes?:int, gap_minutes?:int, day_start?:string,
     *              day_end?:string, days?:list<int>, lead_minutes?:int, max?:int,
     *              timezone?:string, calendar_id?:string} $opts
     * @return array{ok:bool, slots:list<array{start:string,end:string}>, minutes:int, message:string}
     */
    public function slots(array $opts): array
    {
        $no = fn (string $m): array => ['ok' => false, 'slots' => [], 'minutes' => 0, 'message' => $m];
        if (!$this->canSchedule()) return $no($this->why());

        $from = strtotime((string) ($opts['from'] ?? ''));
        $to   = strtotime((string) ($opts['to'] ?? ''));
        if (!$from || !$to)  return $no('That date range could not be read.');
        if ($to <= $from)    return $no('The window has to end after it starts.');
        // A month is already 100+ slots at 30 minutes; asking for a year would time the
        // Apps Script out and return an HTML error page rather than an answer.
        if ($to - $from > 90 * 86400) return $no('Ask for at most ninety days at a time.');

        $res = $this->call('calendar.slots', [
            'fromIso'     => gmdate('Y-m-d\TH:i:s\Z', $from),
            'toIso'       => gmdate('Y-m-d\TH:i:s\Z', $to),
            'minutes'     => max(5, min(480, (int) ($opts['minutes'] ?? 30))),
            'gapMinutes'  => max(0, min(240, (int) ($opts['gap_minutes'] ?? 0))),
            'dayStart'    => (string) ($opts['day_start'] ?? '09:00'),
            'dayEnd'      => (string) ($opts['day_end'] ?? '17:00'),
            'days'        => array_values(array_filter(
                array_map('intval', (array) ($opts['days'] ?? [1, 2, 3, 4, 5])),
                static fn (int $d): bool => $d >= 0 && $d <= 6
            )),
            'leadMinutes' => max(0, (int) ($opts['lead_minutes'] ?? 120)),
            'max'         => max(1, min(500, (int) ($opts['max'] ?? 100))),
            'timezone'    => (string) ($opts['timezone'] ?? 'Africa/Lagos'),
            'calendarId'  => (string) ($opts['calendar_id'] ?? 'primary'),
        ], self::TIMEOUT_CREATE);

        if (!($res['ok'] ?? false)) {
            return $no((string) ($res['message'] ?? 'The Apps Script did not answer.'));
        }

        $slots = [];
        foreach ((array) ($res['slots'] ?? []) as $s) {
            $a = trim((string) ($s['startIso'] ?? ''));
            $b = trim((string) ($s['endIso'] ?? ''));
            if ($a !== '' && $b !== '') $slots[] = ['start' => $a, 'end' => $b];
        }

        return ['ok' => true, 'slots' => $slots,
                'minutes' => (int) ($res['minutes'] ?? 30),
                'message' => (string) ($res['message'] ?? count($slots) . ' slot(s)')];
    }

    /**
     * Busy blocks in a window, for a screen that wants to draw a calendar rather than a
     * list of slots.
     *
     * @return array{ok:bool, busy:list<array{start:string,end:string}>, message:string}
     */
    public function busy(string $from, string $to, string $calendarId = 'primary'): array
    {
        if (!$this->canSchedule()) return ['ok' => false, 'busy' => [], 'message' => $this->why()];

        $a = strtotime($from);
        $b = strtotime($to);
        if (!$a || !$b || $b <= $a) return ['ok' => false, 'busy' => [],
                                            'message' => 'That date range could not be read.'];

        $res = $this->call('calendar.freebusy', [
            'fromIso' => gmdate('Y-m-d\TH:i:s\Z', $a),
            'toIso'   => gmdate('Y-m-d\TH:i:s\Z', $b),
            'calendarId' => $calendarId,
        ], self::TIMEOUT_CREATE);

        $busy = [];
        foreach ((array) ($res['busy'] ?? []) as $x) {
            $s = trim((string) ($x['start'] ?? ''));
            $e = trim((string) ($x['end'] ?? ''));
            if ($s !== '' && $e !== '') $busy[] = ['start' => $s, 'end' => $e];
        }

        return ['ok' => (bool) ($res['ok'] ?? false), 'busy' => $busy,
                'message' => (string) ($res['message'] ?? '')];
    }

    /** @param array<mixed> $in @return list<string> the deliverable addresses in $in */
    private static function emails(array $in): array
    {
        return array_values(array_filter(
            array_map(static fn ($e): string => trim((string) $e), $in),
            static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /**
     * Fetch the transcript Google produced for a conference.
     *
     * A transcript only exists if somebody in the call switched transcription on — it is
     * off by default, it is a Workspace feature rather than a consumer one, and the
     * operator has to press it. So "not found" is the ORDINARY answer and is reported as a
     * next step rather than an error.
     *
     * `$titleHint` is the calendar event's title, and it is what scopes the Apps Script's
     * Drive fallback to THIS sitting. Google names a Meet transcript after the event, and
     * without a hint that branch used to take the newest "Transcript" document in the whole
     * Drive — so two interviews on one day meant the second one's transcript answered a
     * fetch for the first. The script now returns nothing rather than guessing, which means
     * omitting this argument silently disables the fallback: pass it.
     *
     * @return array{ok:bool, text:string, source:string, found:bool, message:string}
     */
    public function transcript(string $meetCode, string $since = '', string $titleHint = ''): array
    {
        $no = fn (string $m, bool $found = false): array =>
            ['ok' => false, 'text' => '', 'source' => '', 'found' => $found, 'message' => $m];

        if (!$this->canSchedule()) return $no($this->why());
        $meetCode = strtolower(trim($meetCode));
        if (!preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $meetCode)) {
            return $no('No Meet code is stored for this interview, so Google cannot be asked which '
                     . 'conference it was. Paste the transcript in instead.');
        }

        $ts  = $since !== '' ? strtotime($since) : 0;
        $res = $this->call('meet.transcript', [
            'meetCode'  => $meetCode,
            'sinceIso'  => $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts - 3600) : '',
            'titleHint' => mb_substr(trim($titleHint), 0, 200),
        ], self::TIMEOUT_FETCH);

        if (!($res['ok'] ?? false)) {
            return $no((string) ($res['message'] ?? 'The Apps Script did not answer.'));
        }

        $text = trim((string) ($res['text'] ?? ''));
        if ($text === '') {
            return $no('Google has no transcript for that meeting. Transcription is off unless '
                     . 'somebody turns it on during the call ("Activities → Transcripts"), and it '
                     . 'needs a Workspace plan that includes it. If you recorded the call another '
                     . 'way, paste the text in instead.', false);
        }

        return ['ok' => true, 'text' => $text, 'found' => true,
                'source' => (string) ($res['source'] ?? 'meet'),
                'message' => 'Transcript fetched from Google (' . number_format(mb_strlen($text)) . ' characters).'];
    }

    /**
     * One POST to the Apps Script. Never throws.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function call(string $action, array $data, int $timeout): array
    {
        $payload = ['action' => $action, 'token' => $this->secret, 'data' => $data, 'source' => 'web'];
        try {
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                // Apps Script answers a POST with a 302 to script.googleusercontent.com and
                // the body is only at the far end of it.
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body === false || $code < 200 || $code >= 400) {
                return ['ok' => false, 'message' => 'The Apps Script returned ' . ($code ?: 'no response')
                                                  . ($err !== '' ? ' (' . $err . ')' : '') . '.'];
            }
            $json = json_decode((string) $body, true);
            if (!is_array($json)) {
                // Apps Script serves an HTML error page when a deployment is stale or the
                // script itself throws before ContentService is reached.
                return ['ok' => false, 'message' => 'The Apps Script did not return JSON. It is '
                    . 'usually an old deployment: open the script, Deploy → Manage deployments → '
                    . 'edit → New version.'];
            }
            // The script answers {success:…} for sheet writes and {ok:…} for these actions;
            // accept either so a half-updated deployment reports something useful.
            $ok = (bool) ($json['ok'] ?? $json['success'] ?? false);
            return ['ok' => $ok] + $json;
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach the Apps Script: ' . $e->getMessage()];
        }
    }
}
