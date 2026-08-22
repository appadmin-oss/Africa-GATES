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
     * @param array{title:string, description?:string, start:string, minutes?:int,
     *              timezone?:string, guests?:list<string>} $opts  start is UTC 'Y-m-d H:i:s'
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
