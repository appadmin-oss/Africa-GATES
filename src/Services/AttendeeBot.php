<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;

/**
 * The bot that can sit in a Meet call, because nothing here can.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS AND WHY IT IS NOT PART OF THIS APPLICATION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Attendee (github.com/attendee-labs/attendee) is an open-source service that joins a
 * Google Meet, Zoom or Teams call as a participant, records it, transcribes it, and will
 * play audio into the room on request. It is Django plus Postgres plus Redis plus Celery,
 * and it launches a headless browser per meeting.
 *
 * None of that can run here. {@see InterviewLive} and {@see GoogleMeetService} both say
 * so for their own reasons and both are right: this deployment is PHP-FPM on cPanel with
 * no long-running process, no shell and no queue daemon. So Attendee runs on its own
 * host — a container on Google Cloud, in this deployment's case — and this file is a
 * client. The relationship is the same one this platform already has with Paystack: a
 * base URL, an API key, and no illusion that the work happens locally.
 *
 * That separation is the whole design, and it survives the thing that usually breaks
 * these integrations. A cPanel host cannot be relied on to receive a webhook — the
 * hostname changes, the firewall is somebody else's, and a missed callback is a silently
 * lost transcript. So every read here is POLLABLE, driven by the existing cron sweep, and
 * the webhook is an optimisation that makes the live path faster when it happens to work.
 * Nothing is only reachable by callback.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HOSTED INSTANCE IS A TRAP, AND THE DEFAULT REFLECTS THAT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `app.attendee.dev` is the vendor's hosted service and it bills per meeting-hour. It is
 * the right thing for a pilot and the wrong thing to discover you have been using for a
 * season of interviews. {@see selfHosted()} exists so the admin screen can say which one
 * is answering, rather than leaving an operator to infer it from an invoice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TRANSCRIPTION RUNS ON OPENAI, DELIBERATELY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Attendee can transcribe with several providers, including the meeting platform's own
 * free captions. Those captions are what the browser extension has been scraping, and
 * their weakness is the reason this integration is worth building: Meet's recogniser is
 * tuned for American English and mangles Nigerian, Ghanaian and Kenyan names and place
 * names — in a transcript whose entire purpose is to quote a nominee accurately to a
 * judging panel.
 *
 * OpenAI's model is asked instead, with a prompt seeded from the nominee's own name and
 * category. That is not a claim that it is unbiased; it is a claim that a recogniser you
 * can prime with the words you expect beats one you cannot. Where it still gets a name
 * wrong, {@see InterviewReview} quotes the transcript as it stands and a judge can hear
 * the recording, which is why `bot_recording_url` is kept.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS NOT HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No policy. This file will happily send a bot into a sitting with no consent on file and
 * play any audio it is handed. The refusals live in {@see InterviewBot}, one layer up,
 * where the sitting and its consent state are in scope. Splitting it the other way would
 * mean a transport client that needs a database, and every test of the HTTP shape would
 * need a fixture nominee.
 */
final class AttendeeBot
{
    /**
     * The vendor's hosted instance — metered, and the default only so that a
     * half-configured install fails with a bill rather than a crash.
     */
    public const HOSTED_BASE = 'https://app.attendee.dev';

    /** Normalised states. The same five words every provider in this codebase reports. */
    public const STATES = ['requested', 'joining', 'in_call', 'done', 'error', 'removed'];

    /**
     * Attendee accepts one audio content type and this is it.
     *
     * Worth naming as a constant because {@see InterviewVoice} has to ask OpenAI for this
     * exact format, and the two ends drifting apart produces a bot that joins, stays
     * silent, and reports success.
     */
    public const AUDIO_MIME = 'audio/mp3';

    /**
     * Seconds. Short on purpose: these calls happen inside a cron tick or, worse, inside
     * a live conversation. A provider taking longer than this has already failed at the
     * only thing that mattered.
     */
    private const TIMEOUT = 12;

    // ── configuration ────────────────────────────────────────────────────────

    public static function apiKey(): string
    {
        return trim((string) Env::get('ATTENDEE_API_KEY', ''));
    }

    public static function botName(): string
    {
        return trim((string) Env::get('ATTENDEE_BOT_NAME', 'Africa GATES Interview Assistant'));
    }

    /** The API root, with the version segment this client speaks. */
    public static function base(): string
    {
        $b = trim((string) Env::get('ATTENDEE_BASE_URL', self::HOSTED_BASE));
        return rtrim($b === '' ? self::HOSTED_BASE : $b, '/') . '/api/v1';
    }

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    /**
     * True when pointed at an instance the organisation runs itself.
     *
     * Printed on the admin screen. An operator who thinks they are self-hosted and is not
     * finds out from a bill; this is cheaper.
     */
    public static function selfHosted(): bool
    {
        $b = trim((string) Env::get('ATTENDEE_BASE_URL', ''));
        return $b !== '' && stripos($b, 'attendee.dev') === false;
    }

    // ── sending a bot ────────────────────────────────────────────────────────

    /**
     * Ask for a bot in a meeting.
     *
     * @param array{join_at?:string, webhook_url?:string, prompt?:string, language?:string, record?:bool} $opts
     *        join_at     RFC3339. Attendee holds the bot until then. Never in the past —
     *                    the caller clamps, because a past join time is rejected outright
     *                    and the resulting sitting has no bot and no explanation.
     *        prompt      Names and terms to prime the recogniser with. See the class note.
     * @return array{ok:bool, bot_id:string, error:?string}
     */
    public static function createBot(string $meetingUrl, array $opts = []): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'bot_id' => '', 'error' => 'Attendee is not configured (set ATTENDEE_API_KEY).'];
        }
        $meetingUrl = trim($meetingUrl);
        if ($meetingUrl === '') {
            return ['ok' => false, 'bot_id' => '', 'error' => 'This sitting has no meeting link yet.'];
        }

        $body = [
            'meeting_url' => $meetingUrl,
            'bot_name'    => self::botName(),
        ];

        $joinAt = trim((string) ($opts['join_at'] ?? ''));
        if ($joinAt !== '') $body['join_at'] = $joinAt;

        // Optional, and genuinely optional — see the class note on polling.
        $hook = trim((string) ($opts['webhook_url'] ?? ''));
        if ($hook !== '') $body['webhook_url'] = $hook;

        $body['transcription_settings'] = self::transcriptionSettings(
            (string) ($opts['prompt'] ?? ''),
            (string) ($opts['language'] ?? '')
        );

        // Recording is what lets a judge check a mistranscribed name against the audio,
        // so it is on unless the caller has a reason to refuse it.
        if (($opts['record'] ?? true) === false) {
            $body['recording_settings'] = ['format' => 'none'];
        }

        $res = self::http('POST', self::base() . '/bots', $body);
        if (isset($res['__error'])) {
            return ['ok' => false, 'bot_id' => '', 'error' => (string) $res['__error']];
        }

        $id = trim((string) ($res['id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'bot_id' => '', 'error' => 'Attendee accepted the request but returned no bot id.'];
        }
        return ['ok' => true, 'bot_id' => $id, 'error' => null];
    }

    /**
     * The transcription block, kept in one place because two callers build it.
     *
     * Falls back to the meeting platform's own captions when no OpenAI key is configured,
     * rather than sending a bot that records and transcribes nothing. A worse transcript
     * is recoverable; a silent one is the bug this whole integration exists to fix.
     *
     * @return array<string,mixed>
     */
    public static function transcriptionSettings(string $prompt = '', string $language = ''): array
    {
        if (trim((string) Env::get('OPENAI_API_KEY', '')) === '') {
            return ['meeting_closed_captions' => ['merge_consecutive_captions' => true]];
        }

        $openai = ['model' => trim((string) Env::get('ATTENDEE_STT_MODEL', 'gpt-4o-transcribe'))];

        // The recogniser is primed with the names it is about to hear. Capped because
        // this rides on every utterance and a long prompt is charged for every one.
        $prompt = trim(preg_replace('/\s+/u', ' ', $prompt) ?? '');
        if ($prompt !== '') $openai['prompt'] = mb_substr($prompt, 0, 900);

        if (trim($language) !== '') $openai['language'] = trim($language);

        return ['openai' => $openai];
    }

    // ── watching it ──────────────────────────────────────────────────────────

    /**
     * Normalised status, or '' when the provider could not be reached.
     *
     * The empty string is NOT an error state and callers must not store it as one. A
     * provider that times out once during a five-minute sweep has told us nothing about
     * the bot; overwriting `in_call` with `error` on that basis would end a sitting that
     * is still running.
     */
    public static function botStatus(string $botId): string
    {
        $b = self::fetchBot($botId);
        if ($b === null) return '';

        $state = strtolower(trim((string) ($b['state'] ?? '')));
        return self::normaliseState($state);
    }

    /** @return array<string,mixed>|null */
    public static function fetchBot(string $botId): ?array
    {
        if (!self::configured() || trim($botId) === '') return null;
        $b = self::http('GET', self::base() . '/bots/' . rawurlencode(trim($botId)));
        return isset($b['__error']) ? null : $b;
    }

    /**
     * Attendee's vocabulary, mapped onto this codebase's five words.
     *
     * The default arm matters: Attendee has added states across versions (waiting-room
     * handling, captcha login, post-processing) and a self-hosted instance may be older
     * or newer than this file. An unknown state is treated as "in flight" rather than as
     * a failure, because the failure arm stops the sweep from ever looking again.
     */
    public static function normaliseState(string $state): string
    {
        return match ($state) {
            'ready', 'scheduled', 'staged'                    => 'requested',
            'joining', 'joining_call', 'waiting_room'         => 'joining',
            'joined_recording', 'joined_not_recording',
            'in_call', 'in_meeting', 'in_waiting_room'        => 'in_call',
            'post_processing', 'ended', 'done', 'complete'    => 'done',
            'fatal_error', 'error', 'failed'                  => 'error',
            'left', 'removed', 'kicked'                       => 'removed',
            ''                                                => '',
            default                                           => 'joining',
        };
    }

    /**
     * Utterances since `$after`, oldest first.
     *
     * `$after` is an index into the provider's own ordering rather than a timestamp,
     * because two people talking over each other produce utterances with the same
     * second and a timestamp cursor drops one of them.
     *
     * @return list<array{index:int, speaker:string, text:string, at:string}>
     */
    public static function transcript(string $botId, int $after = 0): array
    {
        if (!self::configured() || trim($botId) === '') return [];

        $res = self::http('GET', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/transcript');
        if (isset($res['__error'])) return [];

        // Attendee has returned both a bare list and a paginated envelope depending on
        // version. Accept either rather than tying the integration to one release.
        $rows = $res;
        if (isset($res['results']) && is_array($res['results'])) $rows = $res['results'];
        if (!is_array($rows)) return [];

        $out = [];
        $i   = 0;
        foreach ($rows as $row) {
            $i++;
            if (!is_array($row)) continue;
            if ($i <= $after) continue;

            $text = trim((string) ($row['transcription']['transcript'] ?? $row['text'] ?? ''));
            if ($text === '') continue;

            $out[] = [
                'index'   => $i,
                'speaker' => trim((string) ($row['speaker_name'] ?? $row['speaker'] ?? '')),
                'text'    => $text,
                'at'      => trim((string) ($row['timestamp_ms'] ?? $row['created_at'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Whether the provider considers the transcript finished.
     *
     * An older self-hosted instance may not report `transcription_state` at all, so a bot
     * that has ended counts as ready and an empty fetch means "nothing was said" rather
     * than "not yet". Waiting forever for a field that will never arrive is the worse
     * failure: it leaves a held interview permanently unpublished.
     */
    public static function transcriptReady(string $botId): bool
    {
        $b = self::fetchBot($botId);
        if ($b === null) return false;

        $t = strtolower(trim((string) ($b['transcription_state'] ?? '')));
        if ($t !== '') return $t === 'complete';

        return in_array(self::normaliseState(strtolower((string) ($b['state'] ?? ''))), ['done', 'removed'], true);
    }

    /** The recording, when post-processing has produced one. '' until then. */
    public static function recordingUrl(string $botId): string
    {
        if (!self::configured() || trim($botId) === '') return '';
        $r = self::http('GET', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/recording');
        if (isset($r['__error'])) return '';
        return trim((string) ($r['url'] ?? $r['recording']['url'] ?? ''));
    }

    // ── speaking, and leaving ────────────────────────────────────────────────

    /**
     * Play audio into the meeting.
     *
     * Raw MP3 rather than Attendee's own `/speech` endpoint, and that is the load-bearing
     * decision in this whole integration. `/speech` synthesises server-side and supports
     * exactly one voice provider — Google Cloud TTS — which would mean a second vendor,
     * a service-account JSON on the Attendee host, and a voice chosen from a catalogue
     * that has one Nigerian English option.
     *
     * `/output_audio` takes bytes and asks no questions. So the voice is synthesised here,
     * by OpenAI, and Attendee is left doing the one thing only it can do: holding the
     * media session. No fork of Attendee, no second TTS vendor, and the voice is
     * swappable by changing {@see InterviewVoice} alone.
     *
     * @param string $mp3 raw bytes, NOT base64 — this method encodes
     * @return array{ok:bool, error:?string}
     */
    public static function speak(string $botId, string $mp3): array
    {
        if (!self::configured())      return ['ok' => false, 'error' => 'Attendee is not configured.'];
        if (trim($botId) === '')      return ['ok' => false, 'error' => 'This sitting has no bot in the room.'];
        if ($mp3 === '')              return ['ok' => false, 'error' => 'No audio to play.'];

        $res = self::http('POST', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/output_audio', [
            'type' => self::AUDIO_MIME,
            'data' => base64_encode($mp3),
        ]);

        return isset($res['__error'])
            ? ['ok' => false, 'error' => (string) $res['__error']]
            : ['ok' => true, 'error' => null];
    }

    /**
     * Ask the bot to leave.
     *
     * Idempotent from the caller's point of view: a bot that has already gone reports
     * success, because "it is not in the room" is what the caller wanted either way.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function leave(string $botId): array
    {
        if (!self::configured() || trim($botId) === '') return ['ok' => true, 'error' => null];

        $res = self::http('POST', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/leave', []);
        if (!isset($res['__error'])) return ['ok' => true, 'error' => null];

        $err = (string) $res['__error'];
        // 400 from a bot that has already ended is the ordinary case, not a failure.
        if (str_contains($err, '400') || stripos($err, 'not in') !== false) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => $err];
    }

    // ── transport ────────────────────────────────────────────────────────────

    /**
     * One HTTP call. Never throws; a failure comes back as `__error`.
     *
     * Every caller of this class runs inside either a cron tick or an admin page load, and
     * an uncaught exception in the first is an invisible dead sweep while in the second it
     * is a 500 on a screen an operator opened to find out what went wrong.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private static function http(string $method, string $url, ?array $body = null): array
    {
        if (!function_exists('curl_init')) {
            return ['__error' => 'This server has no cURL, so Attendee cannot be reached.'];
        }

        $ch = curl_init($url);
        if ($ch === false) return ['__error' => 'Could not open a connection to Attendee.'];

        $headers = [
            'Authorization: Token ' . self::apiKey(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            // Not followed: a redirect from an API host is a misconfiguration (usually
            // http:// in the base URL), and following it would replay the Authorization
            // header to wherever it points.
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['__error' => 'Could not reach Attendee: ' . ($cerr !== '' ? $cerr : 'connection failed')];
        }
        $raw = (string) $raw;

        if ($code >= 400) {
            // The provider's own message, when it sent one. "meeting not found" and "the
            // host removed the bot" call for different responses from an operator, and a
            // flattened "HTTP 400" tells them to retry the thing that cannot work.
            $j    = json_decode($raw, true);
            $note = '';
            if (is_array($j)) {
                $note = trim((string) ($j['error'] ?? $j['detail'] ?? $j['message'] ?? ''));
                if ($note === '') $note = mb_substr(json_encode($j) ?: '', 0, 300);
            }
            error_log('[attendee] ' . $method . ' ' . $code . ': ' . mb_substr($raw, 0, 300));
            return ['__error' => 'Attendee returned ' . $code . ($note !== '' ? ': ' . $note : '')];
        }

        if (trim($raw) === '') return [];

        $j = json_decode($raw, true);
        return is_array($j) ? $j : ['__error' => 'Attendee returned something that is not JSON.'];
    }
}
