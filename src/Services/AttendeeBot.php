<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

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
 * the recording, which is why a sitting records that it HAS one. Not a link to it: see
 * {@see recordingUrl()} — the provider presigns that URL for thirty minutes, so it is
 * minted per click and never stored.
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

    /**
     * The admin settings that shadow each env var, and the default when neither is set.
     *
     * ── WHY THE DATABASE WINS OVER THE ENVIRONMENT ───────────────────────────
     *
     * Every one of these used to be env-only. This platform deploys to cPanel by upload
     * with no SSH, so "set ATTENDEE_API_KEY" was not a thing an operator could do — which
     * means the interview bot had no configuration surface at all, and the feature was
     * unreachable on the deployment it was written for.
     *
     * The stored value therefore takes precedence and the env is the fallback, matching
     * what the AI provider keys already do. The other order looks safer and is worse: a
     * settings screen that silently does nothing whenever an env var happens to be set is
     * the same class of defect as a sidebar offering a link the guard refuses. If the two
     * disagree, the screen says which one is in force — see {@see configReport()}.
     *
     * @var array<string,array{env:string,default:string,secret:bool,label:string}>
     */
    public const SETTINGS = [
        'attendee_api_key'   => ['env' => 'ATTENDEE_API_KEY',   'default' => '', 'secret' => true,
                                 'label' => 'API key'],
        'attendee_base_url'  => ['env' => 'ATTENDEE_BASE_URL',  'default' => '', 'secret' => false,
                                 'label' => 'Base URL'],
        'attendee_bot_name'  => ['env' => 'ATTENDEE_BOT_NAME',
                                 'default' => 'Africa GATES Interview Assistant', 'secret' => false,
                                 'label' => 'Bot display name'],
        'attendee_join_notice' => ['env' => 'ATTENDEE_JOIN_NOTICE',
                                 'default' => 'This interview is being recorded and transcribed for the judging panel.',
                                 'secret' => false, 'label' => 'Notice posted on joining'],
        'attendee_bot_image' => ['env' => 'ATTENDEE_BOT_IMAGE', 'default' => '', 'secret' => false,
                                 'label' => 'Bot avatar (server path)'],
        'attendee_stt_model' => ['env' => 'ATTENDEE_STT_MODEL', 'default' => 'gpt-4o-transcribe',
                                 'secret' => false, 'label' => 'Transcription model'],
    ];

    /**
     * One config value: stored setting, then environment, then default.
     *
     * The stored value is only honoured when NON-BLANK, so clearing a field in the admin
     * form falls back to the env rather than forcing an empty string — which for a base URL
     * would silently point the client at nothing.
     */
    public static function conf(string $key): string
    {
        $spec = self::SETTINGS[$key] ?? null;
        if ($spec === null) return '';

        try {
            $stored = trim((string) (DB::table('gates_settings')
                ->where('key_name', $key)->value('value') ?? ''));
            if ($stored !== '') return $stored;
        } catch (\Throwable) {
            // No settings table yet (a deploy before db:migrate). Env still works.
        }

        $env = trim((string) Env::get($spec['env'], ''));

        return $env !== '' ? $env : $spec['default'];
    }

    /**
     * Where each value is actually coming from, for the setup screen.
     *
     * An operator who has typed a key into the form and still sees "not configured" needs
     * to know whether the env is overriding them, whether the write failed, or whether they
     * are looking at a stale page. Printing the source answers all three.
     *
     * @return array<string,array{key:string,label:string,source:string,value:string,secret:bool}>
     */
    public static function configReport(): array
    {
        $out = [];
        foreach (self::SETTINGS as $key => $spec) {
            $stored = '';
            try {
                $stored = trim((string) (DB::table('gates_settings')
                    ->where('key_name', $key)->value('value') ?? ''));
            } catch (\Throwable) {
            }
            $env = trim((string) Env::get($spec['env'], ''));

            $source = $stored !== '' ? 'settings' : ($env !== '' ? 'env' : ($spec['default'] !== '' ? 'default' : 'unset'));
            $value  = self::conf($key);

            $out[$key] = [
                'key'    => $key,
                'label'  => $spec['label'],
                'source' => $source,
                // A secret is never echoed. The screen shows its length and its last four,
                // which is enough to tell "the key I pasted" from "some other key" without
                // putting a credential in a page that gets screenshotted.
                'value'  => $spec['secret'] ? self::mask($value) : $value,
                'secret' => $spec['secret'],
                'env'    => $spec['env'],
                // An env value BEHIND a stored one is worth surfacing: it is what the
                // deployment thought it was configured with, and it is now inert.
                'shadowed_env' => ($stored !== '' && $env !== '')
                    ? ($spec['secret'] ? self::mask($env) : $env) : '',
            ];
        }

        return $out;
    }

    /** "sk-…a1b2 (48 chars)", or '' when there is nothing set. */
    private static function mask(string $v): string
    {
        if ($v === '') return '';
        $n = strlen($v);

        return ($n > 8 ? substr($v, 0, 3) . '…' . substr($v, -4) : '••••') . " ({$n} chars)";
    }

    public static function apiKey(): string
    {
        return self::conf('attendee_api_key');
    }

    public static function botName(): string
    {
        return self::conf('attendee_bot_name');
    }

    /** The API root, with the version segment this client speaks. */
    public static function base(): string
    {
        $b = self::conf('attendee_base_url');
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
        $b = self::conf('attendee_base_url');
        return $b !== '' && stripos($b, 'attendee.dev') === false;
    }

    /**
     * Check the configuration, and say what is wrong rather than that something is.
     *
     * ── WHY THIS IS NOT JUST "DOES A CALL SUCCEED" ───────────────────────────
     *
     * Because the interesting failures all look identical from the outside. A missing key,
     * a key for the wrong instance, an `http://` base URL, a self-hosted box that is down,
     * a host with no cURL, and a firewall that eats outbound HTTPS every one produce "it
     * did not work" — and an operator with no SSH cannot tell them apart. The local checks
     * therefore run FIRST and short-circuit, so a problem that needs no network is never
     * reported as a network problem.
     *
     * `GET /bots` rather than creating anything: the test must not put a bot in a meeting.
     * A 200 or a 404 both prove the credential was accepted; a 401/403 proves it was not,
     * which is the one distinction that matters here.
     *
     * @return array{ok:bool, level:string, message:string, checks:list<array{name:string,ok:bool,detail:string}>}
     */
    public static function checkConnection(): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        // ── 1 · things that need no network ─────────────────────────────────
        $key = self::apiKey();
        $add('API key', $key !== '', $key !== '' ? self::mask($key) : 'Not set. Nothing can be requested without it.');

        $base = self::conf('attendee_base_url');
        $host = $base === '' ? self::HOSTED_BASE : $base;
        $add('Base URL', true, $base === ''
            ? self::HOSTED_BASE . ' (the hosted service — nothing was configured)'
            : $base . (self::selfHosted() ? ' (self-hosted)' : ' (hosted service)'));

        if ($base !== '' && !str_starts_with(strtolower($base), 'https://')) {
            // Redirects are deliberately not followed, so http:// does not merely warn —
            // it fails, and it fails in a way that looks like the host being unreachable.
            return ['ok' => false, 'level' => 'error', 'checks' => $checks,
                    'message' => 'The base URL must start with https://. Redirects are not followed, '
                               . 'because following one would replay your API key to wherever it points.'];
        }

        $curl = function_exists('curl_init');
        $add('cURL', $curl, $curl ? 'available' : 'This PHP has no cURL, so Attendee cannot be reached at all.');

        if ($key === '' || !$curl) {
            return ['ok' => false, 'level' => 'error', 'checks' => $checks,
                    'message' => $key === ''
                        ? 'Paste an API key from your Attendee instance and save, then test again.'
                        : 'Ask the host to enable the PHP cURL extension. Nothing else here can work without it.'];
        }

        // ── 2 · the one network call, and it creates nothing ────────────────
        $res = self::http('GET', self::base() . '/bots');

        if (isset($res['__error'])) {
            $err = (string) $res['__error'];
            $auth = stripos($err, '401') !== false || stripos($err, '403') !== false
                 || stripos($err, 'authentication') !== false || stripos($err, 'credential') !== false;

            $add('Reached the API', !$auth, $err);

            return ['ok' => false, 'level' => 'error', 'checks' => $checks,
                    'message' => $auth
                        ? 'Reached ' . $host . ', but it refused the key. It is either the wrong key or '
                          . 'a key for a different instance.'
                        : 'Could not reach ' . $host . '. If this is self-hosted, check it is running and '
                          . 'that outbound HTTPS is allowed from this server.'];
        }

        $add('Reached the API', true, 'the key was accepted');

        // ── 3 · what will and will not work once it is connected ───────────
        //
        // Reported as warnings, not failures. A bot that records with the meeting's own
        // captions is worse than one with a real recogniser and is still a bot; refusing
        // to report success over it would hide a working integration.
        $stt = trim((string) Env::get('OPENAI_API_KEY', ''));
        $add('Transcription', true, $stt !== ''
            ? self::conf('attendee_stt_model') . ' (OpenAI)'
            : 'No OPENAI_API_KEY, so the bot falls back to the meeting platform\'s own captions.');

        $notice = self::joinNotice();
        $add('Join notice', true, $notice !== '' ? $notice : 'Off — the bot joins without announcing itself.');

        $img = self::conf('attendee_bot_image');
        if ($img !== '') {
            $ok = self::botImage() !== null;
            $add('Avatar', $ok, $ok ? $img
                : $img . ' — not readable, or not a PNG/JPEG under 1.5MB. The bot will join with no picture.');
        }

        $warn = array_values(array_filter($checks, fn ($c) => !$c['ok']));

        return ['ok' => true, 'level' => $warn === [] ? 'ok' : 'warn', 'checks' => $checks,
                'message' => $warn === []
                    ? 'Connected to ' . $host . '. The interview bot is ready.'
                    : 'Connected to ' . $host . ', with ' . count($warn) . ' thing(s) worth fixing below.'];
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
            return ['ok' => false, 'bot_id' => '', 'error' => 'Attendee is not configured (set ATTENDEE_API_KEY).', 'duplicate' => false];
        }
        $meetingUrl = trim($meetingUrl);
        if ($meetingUrl === '') {
            return ['ok' => false, 'bot_id' => '', 'error' => 'This sitting has no meeting link yet.', 'duplicate' => false];
        }

        $res = self::http('POST', self::base() . '/bots', self::buildCreateBody($meetingUrl, $opts));
        if (isset($res['__error'])) {
            $err = (string) $res['__error'];
            // A dedup collision is the key doing its job, not a failure. The caller
            // must not write bot_state=error over a sitting whose bot is already live.
            return ['ok' => false, 'bot_id' => '', 'error' => $err, 'duplicate' => stripos($err, 'deduplication') !== false];
        }

        $id = trim((string) ($res['id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'bot_id' => '', 'error' => 'Attendee accepted the request but returned no bot id.', 'duplicate' => false];
        }
        return ['ok' => true, 'bot_id' => $id, 'error' => null, 'duplicate' => false];
    }

    /**
     * The create-bot request body, built where a test can read it without a network.
     *
     * ── THE FIELD THAT WAS NEVER REAL ────────────────────────────────────────
     *
     * This used to send `webhook_url`. Attendee has no such field — it takes
     * `webhooks`, a list of `{url, triggers}` — and DRF drops unrecognised keys from
     * a request without complaining. So every call succeeded, no callback was ever
     * registered, and `auto` mode has been running on the polling path the whole
     * time. It worked because {@see InterviewBotController} was written never to
     * depend on the callback. That is the only reason this went unnoticed.
     *
     * HTTPS is a hard requirement, not a preference: Attendee's schema pins
     * `^https://` and rejects the entire create call otherwise. An unusable callback
     * URL therefore has to be dropped here — forwarding it would trade slow
     * transcripts for a sitting with no bot in it at all.
     *
     * @param array{join_at?:string, webhook_url?:string, prompt?:string, language?:string,
     *              record?:bool, metadata?:array<string,mixed>, dedup?:string} $opts
     * @return array<string,mixed>
     */
    public static function buildCreateBody(string $meetingUrl, array $opts = []): array
    {
        $body = [
            'meeting_url' => trim($meetingUrl),
            'bot_name'    => self::botName(),
        ];

        $joinAt = trim((string) ($opts['join_at'] ?? ''));
        if ($joinAt !== '') $body['join_at'] = $joinAt;

        // Optional, and genuinely optional — see the class note on polling.
        $hook = trim((string) ($opts['webhook_url'] ?? ''));
        if ($hook !== '' && stripos($hook, 'https://') === 0) {
            $body['webhooks'] = [[
                'url'      => $hook,
                // Only the two that make 'auto' mode fast. Subscribing to everything
                // would have this endpoint woken by chat and participant traffic it
                // has no handler for, each delivery retried three times on the 400.
                'triggers' => ['bot.state_change', 'transcript.update'],
            ]];
        }

        $body['transcription_settings'] = self::transcriptionSettings(
            (string) ($opts['prompt'] ?? ''),
            (string) ($opts['language'] ?? '')
        );

        // Recording is what lets a judge check a mistranscribed name against the audio,
        // so it is on unless the caller has a reason to refuse it.
        if (($opts['record'] ?? true) === false) {
            $body['recording_settings'] = ['format' => 'none'];
        }

        $meta = self::metadata((array) ($opts['metadata'] ?? []));
        if ($meta !== []) $body['metadata'] = $meta;

        $dedup = trim((string) ($opts['dedup'] ?? ''));
        if ($dedup !== '') $body['deduplication_key'] = $dedup;

        $notice = self::joinNotice();
        if ($notice !== '') $body['bot_chat_message'] = ['to' => 'everyone', 'message' => $notice];

        $image = self::botImage();
        if ($image !== null) $body['bot_image'] = $image;

        return $body;
    }

    /**
     * What the bot says on arriving, or '' to say nothing.
     *
     * A nominee has already consented in writing before a bot is ever dispatched
     * ({@see InterviewBot::dispatch} refuses without `consent_at`), so this is not
     * the consent mechanism. It is the reminder — the moment in the room where a
     * nominee who forgot what they agreed to a week ago can see it and object. That
     * is worth more in a judging interview than it costs.
     *
     * `none` is the off switch rather than a blank value, and that is now doubly true:
     * {@see conf()} treats a blank stored setting as "not set" and falls through to the
     * env and then the default, so clearing the field in the admin form CANNOT turn the
     * notice off. Typing `none` is the only way to say it, and it is what the form says.
     * Emoji are stripped: Attendee's chat endpoint rejects them outright, and losing
     * the whole announcement over a decoration would be a poor trade.
     */
    public static function joinNotice(): string
    {
        $raw = self::conf('attendee_join_notice');
        if (strcasecmp(trim($raw), 'none') === 0) return '';

        // Strip, then collapse — the other order leaves the space the emoji sat in.
        $txt = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2190}-\x{21FF}]/u', '', $raw) ?? $raw;
        $txt = trim(preg_replace('/\s+/u', ' ', $txt) ?? '');
        return $txt === '' ? '' : mb_substr($txt, 0, 500);
    }

    /**
     * Metadata stamped on the bot and echoed back on every read of it.
     *
     * The point is that a webhook or a poll result can name its own sitting without
     * this platform's database being consulted first. Coerced hard, because Attendee
     * validates it: string values only by default, and an over-long one fails the
     * create call — losing a bot over a bookkeeping field.
     *
     * @param array<string,mixed> $in
     * @return array<string,string>
     */
    public static function metadata(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            $key = trim((string) $k);
            if ($key === '' || is_array($v) || is_object($v)) continue;
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            $val = trim((string) $v);
            if ($val === '') continue;
            $out[$key] = mb_substr($val, 0, 900);
        }
        return $out;
    }

    /**
     * The bot's avatar, or null when there is not a usable one configured.
     *
     * Identified by its magic bytes rather than its filename. Attendee validates the
     * image itself and rejects a mislabelled one, so `africa-gates-logo.svg` copied
     * to `.png` would fail the create call and the sitting would get no bot — a
     * cosmetic setting taking down the recording. SVG is not supported at all.
     *
     * @return array{type:string, data:string}|null
     */
    public static function botImage(): ?array
    {
        $path = self::conf('attendee_bot_image');
        if ($path === '' || !is_file($path) || !is_readable($path)) return null;

        $size = @filesize($path);
        if ($size === false || $size > 1500000) return null;

        $bytes = (string) @file_get_contents($path);
        if ($bytes === '') return null;

        $type = null;
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) $type = 'image/png';
        elseif (str_starts_with($bytes, "\xff\xd8\xff"))    $type = 'image/jpeg';
        if ($type === null) return null;

        return ['type' => $type, 'data' => base64_encode($bytes)];
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

        $openai = ['model' => self::conf('attendee_stt_model')];

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
            'joining', 'joining_call', 'waiting_room'          => 'joining',

            // Still in the room, and all of these must keep being polled. A paused
            // recording is a sitting in progress with capture stopped — see
            // {@see pauseRecording()} — not a bot on its way somewhere.
            'joined_recording', 'joined_not_recording',
            'joined_recording_paused',
            'joining_breakout_room', 'leaving_breakout_room',
            'in_call', 'in_meeting', 'in_waiting_room'        => 'in_call',

            'leaving', 'post_processing',
            'ended', 'done', 'complete'                       => 'done',

            // The provider erased this bot's data on request. Anything else would put it
            // back in the sweep's live set, chasing a bot that no longer has anything to
            // report.
            'data_deleted'                                    => 'removed',
            'left', 'removed', 'kicked'                       => 'removed',

            // A refused recording permission is a DEAD sitting, not a slow one. Under the
            // old default it read as 'joining' and the sweep polled it for the rest of the
            // call, reporting a bot that was never going to record.
            'joined_recording_permission_denied',
            'fatal_error', 'error', 'failed'                  => 'error',

            ''                                                => '',

            // Unknown states stay in the live set rather than ending a real sitting on a
            // string this release has not seen. Every state the provider defines at
            // 77e990ed is named above, so reaching this means a newer instance.
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
    /**
     * Utterances for a sitting, newest work first, as stable-identity rows.
     *
     * ── WHY THIS NO LONGER COUNTS POSITIONS ──────────────────────────────────
     *
     * This used to take an ORDINAL cursor: "give me everything after the Nth row", and
     * the line id handed to {@see InterviewLive::append()} was that same ordinal. Both
     * were wrong, and not in the hypothetical way the handoff filed them under ("if
     * /transcript ever paginates or reorders"). Read against the provider source at
     * 77e990ed, `TranscriptView` does not paginate — it returns every utterance in one
     * list — but it builds that list as:
     *
     *     Utterance.objects.filter(recording=…, transcription__isnull=False)
     *                      .order_by("timestamp_ms")
     *
     * ordered by WHEN THE WORDS WERE SPOKEN and filtered to those already transcribed.
     * Transcription is asynchronous. An utterance early in the call whose transcription
     * lands late does not append to the end of that list — it INSERTS AT ITS OWN
     * TIMESTAMP, in the middle, and shifts every ordinal after it by one.
     *
     * That broke twice over. The cursor skipped exactly one line, permanently. Worse, the
     * id `att-7` now named a different utterance than the `att-7` already in the buffer,
     * and `append()` dedupes on that id: it compared two unrelated sentences, found
     * neither extended the other, and kept both — leaving the stale line misattributed
     * beside the new one. A transcript that looks complete and quietly is not is the
     * failure this whole subsystem is written to avoid.
     *
     * So identity comes from the utterance itself. `timestamp_ms` is its offset into the
     * meeting and does not move; the speaker's uuid separates two people who begin on the
     * same millisecond. Re-fetching, re-ordering and re-transcribing all leave it alone,
     * which is what makes `append()`'s revise-in-place correct rather than lucky.
     *
     * The watermark is now that offset, and it is read back with {@see OVERLAP_MS} of
     * slack precisely because a late insert lands BEHIND it. The slack costs nothing on
     * the wire — the provider exposes no server-side offset filter, so the whole list
     * arrives either way — and the stable id collapses whatever the overlap repeats.
     * Sending the entire transcript every tick would do the same job, but `append()`
     * trims to its last 4,000 lines, so a long sitting would re-add trimmed lines out of
     * order.
     *
     * A cursor left over from the ordinal scheme is a small integer, reads as a few
     * milliseconds, and simply makes the next fetch return everything once. No migration.
     *
     * @param int $sinceMs Highest `timestamp_ms` already ingested. 0 fetches everything.
     * @return list<array{uid:string, index:int, speaker:string, text:string, ms:int, at:string}>
     */
    public static function transcript(string $botId, int $sinceMs = 0): array
    {
        if (!self::configured() || trim($botId) === '') return [];

        $res = self::http('GET', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/transcript');
        if (isset($res['__error'])) return [];

        return self::parseTranscript($res, $sinceMs);
    }

    /**
     * How far behind the watermark to re-read, so an utterance transcribed out of order
     * is still seen. Five minutes is far past any transcription lag and still a small
     * slice of a sitting; the stable id means the cost of overshooting is a no-op.
     */
    public const OVERLAP_MS = 300000;

    /**
     * The parsing half, split out so a test can drive it without a network — the same
     * reason {@see buildCreateBody()} exists. The bug above was invisible for exactly as
     * long as this logic could only be exercised against a live instance.
     *
     * @param mixed $payload Decoded response: a bare list, or a paginated envelope.
     * @return list<array{uid:string, index:int, speaker:string, text:string, ms:int, at:string}>
     */
    public static function parseTranscript(mixed $payload, int $sinceMs = 0): array
    {
        // Attendee has returned both a bare list and a paginated envelope depending on
        // version. Accept either rather than tying the integration to one release.
        $rows = $payload;
        if (is_array($payload) && isset($payload['results']) && is_array($payload['results'])) {
            $rows = $payload['results'];
        }
        if (!is_array($rows)) return [];

        $floor = $sinceMs > 0 ? max(0, $sinceMs - self::OVERLAP_MS) : 0;

        $out = [];
        $i   = 0;
        foreach ($rows as $row) {
            $i++;
            if (!is_array($row)) continue;

            $text = trim((string) ($row['transcription']['transcript'] ?? $row['text'] ?? ''));
            if ($text === '') continue;

            $ms = (int) ($row['timestamp_ms'] ?? 0);

            // An utterance with no offset cannot be placed, so it is never filtered out —
            // the id still de-duplicates it. Only rows that DO carry one are skipped, and
            // only when they sit behind the overlap window.
            if ($ms > 0 && $ms < $floor) continue;

            $speaker = trim((string) ($row['speaker_name'] ?? $row['speaker'] ?? ''));
            $who     = trim((string) ($row['speaker_uuid'] ?? $row['speaker_user_uuid'] ?? ''));
            if ($who === '') $who = $speaker;

            $out[] = [
                'uid'     => self::utteranceId($ms, $who, $i),
                'index'   => $i,
                'speaker' => $speaker,
                'text'    => $text,
                'ms'      => $ms,
                'at'      => trim((string) ($row['timestamp_ms'] ?? $row['created_at'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * A line id that survives the list being rebuilt underneath it.
     *
     * Capped well inside the 40 characters `InterviewLive::append()` keeps, because an id
     * truncated to collide with another is the same bug wearing a different hat. The
     * ordinal form is the last resort for a payload with no offsets at all — no worse
     * than what this replaced, and reached only when the provider stops sending
     * `timestamp_ms`.
     */
    public static function utteranceId(int $ms, string $speakerKey, int $ordinal): string
    {
        if ($ms <= 0) return 'att-i' . $ordinal;

        $who = $speakerKey === '' ? '' : '-' . substr(sha1($speakerKey), 0, 6);
        return 'att-' . $ms . $who;
    }

    public static function transcriptReady(string $botId): bool
    {
        $b = self::fetchBot($botId);
        if ($b === null) return false;

        $t = strtolower(trim((string) ($b['transcription_state'] ?? '')));
        if ($t !== '') return $t === 'complete';

        return in_array(self::normaliseState(strtolower((string) ($b['state'] ?? ''))), ['done', 'removed'], true);
    }

    /**
     * The recording, when post-processing has produced one. '' until then.
     *
     * Only ever a plain https URL — see {@see isSafeRecordingUrl()}. Filtered HERE rather
     * than at the point of display, because a value that must never be rendered must
     * never be stored: a second template added later would not remember to re-check.
     */
    /**
     * A download link for the sitting's recording. **Valid for thirty minutes.**
     *
     * Not a guess any more: `Recording.url` in the provider's `bots/models.py` at
     * 77e990ed calls `generate_presigned_url(..., ExpiresIn=1800)`, and the flat `url`
     * key this reads is what `RecordingSerializer` emits (`fields = ["url",
     * "start_timestamp_ms"]`). The `recording.url` fallback below was the guess, and it
     * is kept only because it costs nothing.
     *
     * Never store what this returns. {@see \AfricaGates\Services\InterviewBot::collectRecording()}
     * says what happened when it was.
     */
    public static function recordingUrl(string $botId): string
    {
        if (!self::configured() || trim($botId) === '') return '';
        $r = self::http('GET', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/recording');
        if (isset($r['__error'])) return '';

        $url = trim((string) ($r['url'] ?? $r['recording']['url'] ?? ''));
        return self::isSafeRecordingUrl($url) ? $url : '';
    }

    /**
     * Is this safe to put in an href on an admin page?
     *
     * This string comes from another service and lands in an anchor in the console. Twig
     * escapes the attribute value, which stops a quote breaking out of it — it does NOT
     * stop `javascript:` executing when an admin clicks the link. An Attendee instance
     * that was misconfigured or compromised would otherwise have a stored-XSS path into
     * this console, against a session that can publish a transcript to a judging panel.
     *
     * https only: the Attendee instance must be served over TLS anyway (this platform
     * refuses to register a webhook otherwise), so an http URL is a misconfiguration
     * worth surfacing rather than a case to support.
     *
     * Public because the test asserts a property ABOUT this rule, and a test carrying its
     * own copy of the pattern would keep passing after the real one changed.
     */
    public static function isSafeRecordingUrl(string $url): bool
    {
        return preg_match('~^https://[^\s<>"\'\\\\]+$~i', trim($url)) === 1;
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
    /**
     * Stop the provider recording, without taking the bot out of the room.
     *
     * The missing half of consent. `consent_at` already decides whether anything a bot
     * hears is STORED here — {@see \AfricaGates\Services\InterviewLive::mayCapture()}
     * refuses to keep a word without it — but refusing locally does nothing about the
     * recording accumulating on the bot host. Until this existed the only way to act on a
     * nominee withdrawing mid-call was to remove the bot entirely, which also ends the
     * transcript the panel is reading and cannot be undone.
     *
     * Idempotence is the caller's job: the provider answers 400 for a bot that is not in a
     * pausable state, including one already paused. {@see \AfricaGates\Services\InterviewBot::enforceConsent()}
     * reads the state first rather than pausing every tick.
     *
     * Note this is NOT `admit_from_waiting_room`, which the handoff proposed wiring for
     * the same class of problem. That endpoint is Zoom-only — `bots_api_views.py` answers
     * 400 for any other meeting type — and these interviews are on Google Meet.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function pauseRecording(string $botId): array
    {
        return self::command($botId, 'pause_recording');
    }

    /** Resume a recording paused by {@see pauseRecording()}. @return array{ok:bool, error:?string} */
    public static function resumeRecording(string $botId): array
    {
        return self::command($botId, 'resume_recording');
    }

    /**
     * Erase everything the provider holds for one bot: recording, transcript, and the
     * metadata on its events and webhooks.
     *
     * Reached from {@see \AfricaGates\Console\Commands\PrivacyEraseUserCommand}, because
     * an erasure that clears this platform's tables and leaves the recording on the bot
     * host is not an erasure. The bot's state afterwards is `data_deleted`, which
     * {@see normaliseState()} maps to `removed`.
     *
     * @return array{ok:bool, error:?string}
     */
    public static function deleteData(string $botId): array
    {
        return self::command($botId, 'delete_data');
    }

    /**
     * One of the provider's POST-with-no-body bot actions.
     *
     * Their failure mode is worth naming: a 400 here is usually not a fault but a state
     * disagreement — "this bot cannot pause from where it is" — and callers act on that
     * differently from a transport error.
     *
     * @return array{ok:bool, error:?string}
     */
    private static function command(string $botId, string $action): array
    {
        if (!self::configured() || trim($botId) === '') {
            return ['ok' => false, 'error' => 'Attendee is not configured.'];
        }

        $res = self::http('POST', self::base() . '/bots/' . rawurlencode(trim($botId)) . '/' . $action, []);
        if (isset($res['__error'])) return ['ok' => false, 'error' => (string) $res['__error']];

        return ['ok' => true, 'error' => null];
    }

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
            // JSON_FORCE_OBJECT so an empty body serialises as {} and not []. The
            // provider's action endpoints (pause/resume/delete_data) are posted with "{}"
            // by its own tests, and DRF parses a bare [] as a list — a shape none of its
            // serialisers expect.
            $flags = JSON_UNESCAPED_SLASHES | ($body === [] ? JSON_FORCE_OBJECT : 0);
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, $flags);
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
