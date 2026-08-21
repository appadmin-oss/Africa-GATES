<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The voice in the room, and everything that has to be true before it uses it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO ENGINES, AND WHY THIS IS NOT {@see VoiceService}
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Either ElevenLabs or OpenAI can speak here, chosen by `INTERVIEW_TTS_ENGINE` and
 * defaulting to whichever has a key. The model that decides WHAT to say is a separate
 * question and stays with {@see AiGateway} — the brain and the mouth are billed by
 * different vendors on different units, and coupling them would mean changing the voice
 * required re-testing the questions.
 *
 * VoiceService already speaks ElevenLabs, and this does not call it. Not preference:
 *
 *   - It returns audio for a BROWSER to play, at a nominee's own pace on their own
 *     device. A silent play button is a nuisance; a bot that says nothing for nine
 *     seconds in a live interview is a broken sitting. Different latency budget,
 *     different failure behaviour, and Attendee takes MP3 and nothing else.
 *   - Its cache, counters and truncation are tuned for questionnaire prompts.
 *
 * They DO share the ElevenLabs key and therefore the quota. That is worth knowing before
 * switching this on: a season of interviews can exhaust what nominees use for the
 * questionnaire, and nobody finds out until a play button stops working. The clip cache
 * below is most of the mitigation.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING REACHES THE ROOM UNGUARDED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every utterance — model-written or typed by a panellist — passes {@see InterviewGuard}
 * before it is synthesised. A question that names a figure nobody mentioned, praises the
 * nominee, promises a result, or wanders into religion or health is refused and logged,
 * and the bot stays quiet. See that file for why the checks are deterministic rather than
 * a second model call.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE MODES, AND WHAT EACH ONE IS ACTUALLY CLAIMING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   'off'      No audio is ever produced. The bot is a recorder. This is the default and
 *              every sitting that existed before this feature has it.
 *
 *   'assisted' Audio is produced only when a human asked for it. {@see say()} is reached
 *              from a button on the panel console; the model may have WRITTEN the
 *              question but a person decided it would be asked. This is the mode the
 *              honest description of "AI-assisted interview" fits.
 *
 *   'auto'     {@see InterviewBot} may call {@see say()} on its own, driven by the
 *              transcript. A model is conducting the interview. Not hedged here, because
 *              a panel signing off on a nominee's score should know which of these was
 *              running.
 *
 * `interview_voice_max` in gates_settings caps all of them at once, so an operator can
 * allow 'assisted' platform-wide and 'auto' nowhere without touching a single sitting.
 * The cap is a ceiling and not a default: it can only ever reduce what a sitting asked
 * for.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CONSENT GATES THE VOICE, NOT ONLY THE RECORDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see InterviewLive::mayCapture()} already refuses to STORE a word without
 * `consent_at`. Speaking is gated by the same column, which is a stronger rule than it
 * needs to be and deliberately so: a bot that talks to somebody who has not agreed to be
 * recorded is participating in a conversation it is not allowed to remember. Refusing
 * both together means there is one thing to check and one thing to get wrong.
 */
final class InterviewVoice
{
    public const MODE_OFF      = 'off';
    public const MODE_ASSISTED = 'assisted';
    public const MODE_AUTO     = 'auto';

    /** Ordered weakest to strongest, which is what makes the platform cap a `min()`. */
    public const MODES = [self::MODE_OFF, self::MODE_ASSISTED, self::MODE_AUTO];

    // ── the two voices ───────────────────────────────────────────────────────

    /**
     * ElevenLabs first, and the ordering is the recommendation.
     *
     * Not on price — ElevenLabs is generally DEARER per character than OpenAI, and saying
     * otherwise here would be the kind of comfortable lie this codebase keeps catching. It
     * is first because of accent. A judging panel interviewing Nigerian, Ghanaian and
     * Kenyan nominees is choosing a voice that will ask forty people about their life's
     * work, and OpenAI's catalogue is eight American-English presets with no say in the
     * matter. ElevenLabs has a library you can actually pick from.
     *
     * What genuinely saves money is neither: it is {@see cachePath()} below. The opening
     * disclosure and every scripted pack question are byte-identical across sittings, so
     * they are synthesised once for the whole season and replayed from disk after that.
     * Only the generated follow-ups — one short sentence each — are ever paid for twice.
     */
    public const ENGINES = ['elevenlabs', 'openai'];

    private const OPENAI_URL = 'https://api.openai.com/v1/audio/speech';
    private const ELEVEN_URL = 'https://api.elevenlabs.io/v1/text-to-speech/';

    /**
     * `gpt-4o-mini-tts` rather than `tts-1-hd`: this is a voice asking a question over a
     * conference codec that will resample it to 16kHz anyway, and the hd model's extra
     * fidelity is inaudible by the time it reaches the room. It is also faster, which is
     * the only quality metric that matters mid-conversation.
     */
    private const DEFAULT_MODEL = 'gpt-4o-mini-tts';

    /**
     * ElevenLabs' multilingual model, matching what the questionnaire already uses.
     *
     * `_v2` and not a turbo variant: the latency difference is a few hundred milliseconds
     * on one short sentence, and this is a voice that has to survive a conference codec.
     */
    private const DEFAULT_ELEVEN_MODEL = 'eleven_multilingual_v2';

    /** Cached clips kept before the oldest are dropped. ~500 × 30KB ≈ 15MB. */
    public const CACHE_FILES = 500;

    /**
     * A question, not a monologue. Anything longer is a bug upstream — the follow-up
     * capability caps at 34 words — and truncating loudly beats reading two paragraphs
     * at a nominee.
     */
    public const MAX_CHARS = 600;

    /**
     * Per sitting, across the whole conversation. An hour of interview at one question
     * per ninety seconds is forty utterances; eighty is generous and still bounds what a
     * stuck 'auto' loop can spend before somebody notices.
     */
    public const MAX_UTTERANCES = 80;

    /**
     * Seconds between utterances. Long enough that the bot cannot talk over a nominee
     * who paused, short enough not to be the reason an interview drags.
     */
    public const MIN_GAP_SECONDS = 8;

    // ── policy ───────────────────────────────────────────────────────────────

    /** The platform ceiling. Never raises a sitting's mode, only lowers it. */
    public static function platformMax(): string
    {
        $v = self::setting('interview_voice_max');
        return in_array($v, self::MODES, true) ? $v : self::MODE_AUTO;
    }

    /**
     * What this sitting may actually do, after the ceiling and the configuration.
     *
     * Configuration counts as policy here, not as an error: an operator with no TTS key of
     * either kind has, in effect, chosen 'off', and reporting that as a failure every time
     * the console renders would train them to ignore the console.
     */
    public static function mode(object $iv): string
    {
        $want = (string) ($iv->voice_mode ?? self::MODE_OFF);
        if (!in_array($want, self::MODES, true)) $want = self::MODE_OFF;
        if ($want === self::MODE_OFF) return self::MODE_OFF;

        if (!self::configured()) return self::MODE_OFF;

        $cap = self::platformMax();
        $wi  = array_search($want, self::MODES, true);
        $ci  = array_search($cap,  self::MODES, true);
        return self::MODES[min((int) $wi, (int) $ci)];
    }

    public static function configured(): bool
    {
        return self::engine() !== '' && AttendeeBot::configured();
    }

    /**
     * Which voice will actually answer: 'elevenlabs', 'openai', or '' for none.
     *
     * `INTERVIEW_TTS_ENGINE` names one explicitly and is honoured ONLY if that engine has
     * a key — an operator who names a provider they have not paid for should get the other
     * one and a working interview, not silence and a log line. Unset, the order in
     * {@see ENGINES} decides.
     */
    public static function engine(): string
    {
        $want = strtolower(trim(self::setting('interview_tts_engine')
            ?: (string) Env::get('INTERVIEW_TTS_ENGINE', '')));

        if (in_array($want, self::ENGINES, true) && self::engineKeyed($want)) return $want;

        foreach (self::ENGINES as $e) {
            if (self::engineKeyed($e)) return $e;
        }
        return '';
    }

    private static function engineKeyed(string $engine): bool
    {
        return match ($engine) {
            'elevenlabs' => self::elevenKey() !== '',
            'openai'     => trim((string) Env::get('OPENAI_API_KEY', '')) !== '',
            default      => false,
        };
    }

    /**
     * The ElevenLabs key, shared with the questionnaire.
     *
     * Worth knowing before you switch this on: {@see VoiceService} spends the same quota
     * reading questionnaire prompts to nominees on their own devices. A season of
     * interviews can exhaust the thing nominees actually use, and nobody finds out until a
     * play button stops working. The cache below is most of the mitigation; the rest is
     * watching the ElevenLabs dashboard during a judging round.
     */
    private static function elevenKey(): string
    {
        $s = self::setting('voice_elevenlabs_key');
        return $s !== '' ? $s : trim((string) Env::get('ELEVENLABS_API_KEY', ''));
    }

    /**
     * The interview's own ElevenLabs voice, falling back to the questionnaire's.
     *
     * Separate because they are different jobs: the questionnaire coaxes a nominee through
     * a form on their phone, and this asks a panel's questions in a room where the answer
     * decides an award. An operator may well want the second to sound older.
     */
    private static function elevenVoiceId(): string
    {
        $v = trim((string) Env::get('INTERVIEW_ELEVEN_VOICE_ID', ''));
        if ($v !== '') return $v;

        $s = self::setting('voice_elevenlabs_voice');
        return $s !== '' ? $s : VoiceService::DEFAULT_VOICE;
    }

    /**
     * May the bot speak in this sitting right now, and if not, why not.
     *
     * `$autonomous` distinguishes the two callers. A human pressing "ask this" is allowed
     * in 'assisted'; the turn loop is not, and the difference must be checked here rather
     * than trusted to the caller — the whole point of 'assisted' is that the loop cannot
     * reach the microphone.
     *
     * @return array{0:bool, 1:string}
     */
    public static function maySpeak(object $iv, bool $autonomous): array
    {
        $mode = self::mode($iv);

        if ($mode === self::MODE_OFF) {
            return [false, 'Voice is off for this sitting.'];
        }
        if ($autonomous && $mode !== self::MODE_AUTO) {
            return [false, 'This sitting is in assisted mode, so only a panellist can make the bot speak.'];
        }
        if (in_array((string) $iv->status, ['cancelled', 'no_show'], true)) {
            return [false, 'This interview is marked ' . $iv->status . '.'];
        }
        if (empty($iv->consent_at)) {
            return [false, 'The nominee has not given permission to be recorded, so the bot stays silent.'];
        }
        if (trim((string) ($iv->bot_id ?? '')) === '') {
            return [false, 'No bot has been sent to this sitting.'];
        }
        if ((string) ($iv->bot_state ?? '') !== 'in_call') {
            return [false, 'The bot is not in the call (state: ' . ((string) ($iv->bot_state ?? 'unknown')) . ').'];
        }

        $meta = self::meta((int) $iv->id);
        if ((int) ($meta['said'] ?? 0) >= self::MAX_UTTERANCES) {
            return [false, 'This sitting has reached its limit of ' . self::MAX_UTTERANCES . ' spoken questions.'];
        }
        $last = (int) ($meta['said_at'] ?? 0);
        if ($last > 0 && (Carbon::now()->getTimestamp() - $last) < self::MIN_GAP_SECONDS) {
            return [false, 'The bot spoke a moment ago; giving the nominee room to answer.'];
        }

        return [true, ''];
    }

    // ── speaking ─────────────────────────────────────────────────────────────

    /**
     * Say something in the meeting.
     *
     * @param bool $autonomous true when the turn loop is calling, false when a panellist
     *                         pressed a button. See {@see maySpeak()}.
     * @param bool $scripted   true for the fixed opening — text a human wrote, which is
     *                         the ground rather than something to be grounded against.
     *                         Every other guard rule still applies to it.
     * @return array{ok:bool, error:?string, spoken:string}
     */
    public static function say(int $id, string $text, bool $autonomous = false, bool $scripted = false): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['ok' => false, 'error' => 'No such interview.', 'spoken' => ''];

        [$may, $why] = self::maySpeak($iv, $autonomous);
        if (!$may) return ['ok' => false, 'error' => $why, 'spoken' => ''];

        $text = self::tidy($text);
        if ($text === '') return ['ok' => false, 'error' => 'Nothing to say.', 'spoken' => ''];

        // ── the guard, on BOTH paths ─────────────────────────────────────────
        //
        // A panellist typing a question gets checked as well as a model writing one, and
        // that is deliberate rather than distrustful of the panel. The rules this enforces
        // — no promise of a result, nothing about faith or health, no bank details on a
        // recorded call — are things a tired human types at four in the afternoon too, and
        // a guard that only watches the machine is a guard with a hole in it the size of
        // the console.
        //
        // Scripted text (the fixed opening) skips only the grounding rule; see the note on
        // InterviewGuard::check().
        $verdict = InterviewGuard::check($text, $id, $scripted);
        if (!$verdict['ok']) {
            return ['ok' => false, 'error' => $verdict['note'], 'spoken' => ''];
        }

        // Stamped BEFORE synthesis, for the reason InterviewLive::maybeFollowUp() stamps
        // its cooldown before the model call: a provider that takes six seconds and then
        // fails would otherwise leave the gap unset, and an 'auto' loop polling every few
        // seconds would queue a second utterance on top of the first.
        $meta            = self::meta($id);
        $meta['said']    = (int) ($meta['said'] ?? 0) + 1;
        $meta['said_at'] = Carbon::now()->getTimestamp();
        self::putMeta($id, $meta);

        $mp3 = self::synthesise($text);
        if ($mp3 === null) {
            return ['ok' => false, 'error' => 'The voice could not be generated.', 'spoken' => ''];
        }

        $res = AttendeeBot::speak((string) $iv->bot_id, $mp3);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string) $res['error'], 'spoken' => ''];
        }

        // The bot's own words go into the transcript buffer, attributed to the bot.
        //
        // Not cosmetic. A judge reading "what did the panel actually ask?" six weeks later
        // must see the question, and a transcript containing only the nominee's answers is
        // evidence of nothing. It also stops the turn loop from re-reading its own last
        // question as though the nominee had said it.
        self::record($id, $text);

        return ['ok' => true, 'error' => null, 'spoken' => $text];
    }

    /**
     * Text to MP3 bytes, or null.
     *
     * Never throws. Every caller is either a live conversation or a cron tick, and an
     * exception in the first is a bot that stops mid-interview while the second is an
     * invisible dead sweep.
     *
     * ── THE CACHE IS THE COST CONTROL, NOT THE ENGINE CHOICE ─────────────────
     *
     * Both providers bill per character. The opening disclosure is a fixed string and the
     * pack questions are written once per sitting and re-read across the season, so the
     * overwhelming majority of what this bot says is byte-identical to something it has
     * said before. Keyed by engine, voice, model and text — so changing the voice in
     * Settings does not keep serving the old one for the rest of the cycle.
     */
    public static function synthesise(string $text): ?string
    {
        $engine = self::engine();
        if ($engine === '' || !function_exists('curl_init')) return null;

        $text = self::tidy($text);
        if ($text === '') return null;

        $path = self::cachePath($engine, $text);
        if ($path !== null && is_file($path)) {
            $bytes = (string) @file_get_contents($path);
            if ($bytes !== '') {
                // Touched so the pruner reads "played recently" as "worth keeping".
                // Without it the opening line — replayed at every single sitting — is
                // evicted on age while a follow-up nobody will ever hear again survives.
                @touch($path);
                return $bytes;
            }
        }

        $mp3 = match ($engine) {
            'elevenlabs' => self::viaElevenLabs($text),
            'openai'     => self::viaOpenAi($text),
            default      => null,
        };
        if ($mp3 === null) return null;

        if ($path !== null) {
            @file_put_contents($path, $mp3, LOCK_EX);
            self::prune();
        }
        return $mp3;
    }

    /**
     * ElevenLabs. Same key and the same 64kbps mono as the questionnaire.
     *
     * 64k rather than the 128k default because this is one spoken sentence about to be
     * re-encoded by a conference codec anyway; the extra bytes buy nothing audible and
     * cost latency in the pause the nominee is sitting through.
     */
    private static function viaElevenLabs(string $text): ?string
    {
        $key = self::elevenKey();
        if ($key === '') return null;

        return self::http(
            self::ELEVEN_URL . rawurlencode(self::elevenVoiceId()) . '?output_format=mp3_44100_64',
            [
                'Content-Type: application/json',
                'Accept: audio/mpeg',
                'xi-api-key: ' . $key,
            ],
            [
                'text'           => $text,
                'model_id'       => trim((string) Env::get('INTERVIEW_ELEVEN_MODEL', self::DEFAULT_ELEVEN_MODEL)),
                'voice_settings' => [
                    // Steadier than the default, for the same reason VoiceService is: an
                    // expressive read that varies line to line sounds like a performance,
                    // and a nervous nominee reads performance as judgement.
                    'stability'        => 0.55,
                    'similarity_boost' => 0.75,
                ],
            ],
            'ElevenLabs'
        );
    }

    /** OpenAI. */
    private static function viaOpenAi(string $text): ?string
    {
        $key = trim((string) Env::get('OPENAI_API_KEY', ''));
        if ($key === '') return null;

        return self::http(
            self::OPENAI_URL,
            [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            [
                'model'           => trim((string) Env::get('INTERVIEW_TTS_MODEL', self::DEFAULT_MODEL)),
                'voice'           => trim((string) Env::get('INTERVIEW_TTS_VOICE', 'alloy')),
                'input'           => $text,
                'response_format' => 'mp3',
                // Steers delivery, not wording. A panel interview is not a podcast, and
                // the default reading of a hard question sounds like an accusation.
                'instructions'    => 'Speak as a calm, courteous interview host. Even pace, '
                    . 'warm but neutral. Do not sound excited, impressed, or sceptical.',
            ],
            'OpenAI'
        );
    }

    /**
     * One TTS call, for either provider.
     *
     * @param list<string>        $headers
     * @param array<string,mixed> $payload
     */
    private static function http(string $url, array $headers, array $payload, string $who): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Tighter than the Attendee client's: this one is inside the pause between a
            // nominee finishing and the next question. Past about eight seconds the room
            // has already moved on and playing the audio would be worse than dropping it.
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            error_log('[interview-voice] ' . $who . ' TTS ' . $code . ': '
                . ($raw === false ? $cerr : mb_substr((string) $raw, 0, 300)));
            return null;
        }

        // Both providers answer a bad key with a JSON error document, and a proxy in front
        // of either can pass that through with a 200 — this deployment has exactly such a
        // proxy. Playing it puts a burst of static into a judging interview.
        // {@see VoiceService::looksLikeAudio()} is the same check the questionnaire uses.
        $raw = (string) $raw;
        if ($raw === '' || !VoiceService::looksLikeAudio($raw)) {
            error_log('[interview-voice] ' . $who . ' TTS returned something that is not audio.');
            return null;
        }
        return $raw;
    }

    // ── the clip cache ───────────────────────────────────────────────────────

    public static function cacheDir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/var/cache/interview-voice';
        if (is_dir($dir)) return is_writable($dir) ? $dir : null;
        return @mkdir($dir, 0775, true) || is_dir($dir) ? $dir : null;
    }

    private static function cachePath(string $engine, string $text): ?string
    {
        $dir = self::cacheDir();
        if ($dir === null) return null;

        $voice = $engine === 'elevenlabs'
            ? self::elevenVoiceId() . '|' . Env::get('INTERVIEW_ELEVEN_MODEL', self::DEFAULT_ELEVEN_MODEL)
            : Env::get('INTERVIEW_TTS_VOICE', 'alloy') . '|' . Env::get('INTERVIEW_TTS_MODEL', self::DEFAULT_MODEL);

        return $dir . '/' . sha1($engine . '|' . $voice . '|' . $text) . '.mp3';
    }

    /**
     * Keep the newest {@see CACHE_FILES} clips.
     *
     * Bounded rather than expiring, and enforced by the writer at the moment it writes —
     * the same reasoning as {@see VoiceService::prune()}. This host has a disk quota where
     * "no space left on device" takes the whole site down, and no cron a human trusts.
     */
    public static function prune(): void
    {
        $dir = self::cacheDir();
        if ($dir === null) return;

        $files = glob($dir . '/*.mp3') ?: [];
        if (count($files) <= self::CACHE_FILES) return;

        $byAge = [];
        foreach ($files as $f) $byAge[$f] = (int) @filemtime($f);
        asort($byAge);

        $drop = count($byAge) - self::CACHE_FILES;
        foreach (array_keys($byAge) as $f) {
            if ($drop-- <= 0) break;
            @unlink($f);
        }
    }

    /**
     * The list of voices offered in the admin screen.
     *
     * Hard-coded rather than fetched: OpenAI has no list-voices endpoint, and a dropdown
     * that silently empties when a network call fails is worse than one that is a season
     * out of date.
     *
     * @return array<string,string>
     */
    public static function voices(): array
    {
        return [
            'alloy'   => 'Alloy — neutral, even',
            'ash'     => 'Ash — lower, measured',
            'ballad'  => 'Ballad — soft, unhurried',
            'coral'   => 'Coral — bright, clear',
            'echo'    => 'Echo — flat, businesslike',
            'sage'    => 'Sage — calm, older',
            'shimmer' => 'Shimmer — light, quick',
            'verse'   => 'Verse — expressive',
        ];
    }

    // ── housekeeping ─────────────────────────────────────────────────────────

    /**
     * Normalise, and cap.
     *
     * Truncation is at a sentence boundary where one exists, because a voice cut off
     * mid-clause sounds like a dropped connection and a nominee will answer the half they
     * heard.
     */
    public static function tidy(string $text): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($t === '' || mb_strlen($t) <= self::MAX_CHARS) return $t;

        $cut = mb_substr($t, 0, self::MAX_CHARS);
        $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '? ') ?: 0);
        return $end > 40 ? rtrim(mb_substr($cut, 0, $end + 1)) : rtrim($cut) . '…';
    }

    /**
     * Put the bot's own question into the caption buffer.
     *
     * Written straight to `live_json` rather than through {@see InterviewLive::append()},
     * which takes a live token this caller does not hold and would run the dedup and the
     * follow-up trigger over the bot's own words.
     */
    private static function record(int $id, string $text): void
    {
        try {
            $buf   = InterviewLive::buffer($id);
            $buf[] = [
                'id'      => 'bot-' . substr(bin2hex(random_bytes(6)), 0, 12),
                'speaker' => AttendeeBot::botName(),
                'text'    => $text,
                'at'      => Carbon::now()->toDateTimeString(),
                'bot'     => true,
            ];
            DB::table('gates_interviews')->where('id', $id)->update([
                'live_json'   => json_encode(array_values($buf)),
                'live_at'     => Carbon::now()->toDateTimeString(),
                'live_source' => 'bot',
            ]);
        } catch (\Throwable $e) {
            // The bot has already spoken by this point. Losing the line from the buffer
            // is a gap in the transcript, not a reason to report the utterance as failed.
            error_log('[interview-voice] could not record bot line for ' . $id . ': ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private static function meta(int $id): array
    {
        try {
            $raw = DB::table('gates_interviews')->where('id', $id)->value('live_meta');
        } catch (\Throwable) {
            return [];
        }
        $m = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($m) ? $m : [];
    }

    /** @param array<string,mixed> $meta */
    private static function putMeta(int $id, array $meta): void
    {
        try {
            DB::table('gates_interviews')->where('id', $id)->update(['live_meta' => json_encode($meta)]);
        } catch (\Throwable $e) {
            error_log('[interview-voice] could not store voice meta for ' . $id . ': ' . $e->getMessage());
        }
    }

    private static function setting(string $key): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return is_string($v) ? trim($v) : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
