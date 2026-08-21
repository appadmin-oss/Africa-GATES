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
 * WHY OPENAI AND NOT THE ELEVENLABS ALREADY IN THIS CODEBASE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see VoiceService} exists, works, and speaks through ElevenLabs. It is not reused here
 * and the reason is not preference.
 *
 * VoiceService returns audio for a BROWSER to play — a nominee pressing play on a
 * questionnaire prompt, on their own device, at their own pace. A cached MP3 served to a
 * page is a different problem from a stream that has to land in a live conversation
 * inside a few seconds or arrive after the moment it was for. The two have different
 * latency budgets, different failure behaviour (a silent play button is a nuisance; a
 * bot that says nothing for nine seconds is a broken interview), and different formats:
 * Attendee accepts MP3 and nothing else.
 *
 * They also have different funding. ElevenLabs is charged per character against a quota
 * an operator tops up for the questionnaire; spending that quota on interviews would
 * exhaust the thing nominees use without anybody noticing until it was gone.
 *
 * So: same idea, separate path, and the two can be switched independently. If the voice
 * ever needs to change, it changes here and Attendee never knows.
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

    private const TTS_URL = 'https://api.openai.com/v1/audio/speech';

    /**
     * `gpt-4o-mini-tts` rather than `tts-1-hd`: this is a voice asking a question over a
     * conference codec that will resample it to 16kHz anyway, and the hd model's extra
     * fidelity is inaudible by the time it reaches the room. It is also faster, which is
     * the only quality metric that matters mid-conversation.
     */
    private const DEFAULT_MODEL = 'gpt-4o-mini-tts';

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
     * Configuration counts as policy here, not as an error: an operator who has not set
     * an OpenAI key has, in effect, chosen 'off', and reporting that as a failure every
     * time the console renders would train them to ignore it.
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
        return trim((string) Env::get('OPENAI_API_KEY', '')) !== '' && AttendeeBot::configured();
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
     * @return array{ok:bool, error:?string, spoken:string}
     */
    public static function say(int $id, string $text, bool $autonomous = false): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['ok' => false, 'error' => 'No such interview.', 'spoken' => ''];

        [$may, $why] = self::maySpeak($iv, $autonomous);
        if (!$may) return ['ok' => false, 'error' => $why, 'spoken' => ''];

        $text = self::tidy($text);
        if ($text === '') return ['ok' => false, 'error' => 'Nothing to say.', 'spoken' => ''];

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
     * Never throws. Every caller is either a live conversation or a cron tick.
     */
    public static function synthesise(string $text): ?string
    {
        $key = trim((string) Env::get('OPENAI_API_KEY', ''));
        if ($key === '' || !function_exists('curl_init')) return null;

        $text = self::tidy($text);
        if ($text === '') return null;

        $ch = curl_init(self::TTS_URL);
        if ($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Tighter than the Attendee client's: this one is inside the pause between a
            // nominee finishing and the next question. Past about eight seconds the room
            // has already moved on and playing the audio would be worse than dropping it.
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'           => trim((string) Env::get('INTERVIEW_TTS_MODEL', self::DEFAULT_MODEL)),
                'voice'           => trim((string) Env::get('INTERVIEW_TTS_VOICE', 'alloy')),
                'input'           => $text,
                'response_format' => 'mp3',
                // Steers delivery, not wording. A panel interview is not a podcast, and
                // the default reading of a hard question sounds like an accusation.
                'instructions'    => 'Speak as a calm, courteous interview host. Even pace, '
                    . 'warm but neutral. Do not sound excited, impressed, or sceptical.',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            error_log('[interview-voice] OpenAI TTS ' . $code . ': '
                . ($raw === false ? $cerr : mb_substr((string) $raw, 0, 300)));
            return null;
        }

        $raw = (string) $raw;
        // A JSON body from an endpoint that returns audio is an error the status code did
        // not carry. Playing it would put a few hundred bytes of noise into the meeting.
        if ($raw === '' || str_starts_with(ltrim($raw), '{')) {
            error_log('[interview-voice] OpenAI TTS returned no audio.');
            return null;
        }
        return $raw;
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
