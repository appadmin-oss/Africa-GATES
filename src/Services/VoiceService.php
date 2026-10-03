<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Voice for the questionnaire — ElevenLabs, in both directions.
 *
 * ── WHY A VOICE AT ALL ───────────────────────────────────────────────────────
 *
 * The conversational questionnaire already asks one question at a time, in plain words,
 * and stores the nominee's answer verbatim. It still assumes one thing about the person
 * answering: that typing four paragraphs into a phone is something they can do. For a
 * craftsperson, a farmer-cooperative organiser, a 71-year-old master drummer — the exact
 * people a continental awards platform exists to find — that assumption is the whole
 * barrier. Somebody who can talk for an hour about the work of their life should not lose
 * the nomination to a thumb keyboard.
 *
 * So: the AI's questions can be SPOKEN (text-to-speech), and an answer can be TALKED
 * (speech-to-text). Neither replaces the text; both sit beside it.
 *
 * ── WHAT THIS SERVICE DELIBERATELY DOES NOT DO ───────────────────────────────
 *
 * It does not record the answer. {@see transcribe()} hands the words back to the PAGE,
 * which puts them in the answer box for the nominee to read, correct and then send
 * themselves. Only the normal chat turn writes to the row. That ordering is not a UI
 * preference — it is the "stored verbatim, and the nominee approved it" rule that the
 * whole questionnaire rests on. A transcriber that wrote straight into the record would
 * put words in a nominee's mouth that a judge would later read as a quotation, and speech
 * recognition on Nigerian, Ghanaian and Kenyan English is exactly where that goes wrong.
 *
 * It also never speaks arbitrary text. {@see QuestionnaireChat::spokenTurn()} resolves an
 * INDEX into that submission's own conversation, so the caller cannot hand this service a
 * paragraph of its own choosing. Two reasons, and the second is the load-bearing one:
 *
 *   1. Cost. ElevenLabs bills per character. An endpoint that speaks whatever it is given
 *      is an open text-to-speech proxy on somebody else's invoice.
 *   2. It bounds the spend WITHOUT a quota table. A conversation holds at most
 *      {@see QuestionnaireChat::MAX_TURNS} turns, the cache below is keyed by the text
 *      itself, and so the worst a submission can ever cost is "each of its own questions,
 *      once, ever" — every replay after that is a file read. That is why there is no
 *      per-day character budget in here: the shape of the guard already is the budget.
 *
 * ── INERT WITHOUT A KEY, AND THAT IS A SUPPORTED STATE ───────────────────────
 *
 * With no key configured, {@see configured()} is false, the page renders no microphone and
 * no speaker, and the questionnaire is exactly the working text conversation it was before
 * this file existed. Same doctrine as every other AI path on this platform: rules first,
 * model as an upgrade, nothing breaks when the upgrade is absent. {@see why()} exists so an
 * operator gets a sentence explaining the silence instead of having to guess.
 */
/*
 * Not final, deliberately: {@see httpSpeak()} and {@see httpTranscribe()} are protected so a
 * test can replace the ONE network call and still exercise the tidying, the truncation, the
 * clip cache and the counters — i.e. everything that decides what is actually sent and
 * billed. Same seam AiService uses, for the same reason.
 */
class VoiceService
{
    /**
     * Multilingual, because the questions are read to people whose English carries an
     * accent the model should not flatten, and because a nominee may want the question in
     * French or Portuguese — thirteen of the continent's countries make one of those the
     * language of record.
     */
    public const TTS_MODEL = 'eleven_multilingual_v2';

    /** ElevenLabs' transcription model. */
    public const STT_MODEL = 'scribe_v1';

    /**
     * ElevenLabs' longest-standing pre-made voice ("Rachel"), used only when nobody has
     * chosen one. It is a placeholder in the honest sense: an operator running African
     * awards should pick a voice whose accent the nominees will recognise, and the settings
     * field says so. A default that pretends to be the right answer would be worse than one
     * that admits it is a starting point.
     */
    public const DEFAULT_VOICE = '21m00Tcm4TlvDq8ikWAM';

    /**
     * A question, not a chapter. The longest thing this ever reads aloud is one AI turn,
     * and the opening greeting is the longest of those at roughly 600 characters. Anything
     * past this is truncated at a sentence boundary rather than refused, because a
     * half-spoken question is more useful than an error.
     */
    public const MAX_CHARS = 1400;

    /** Roughly four minutes of phone-recorded Opus. Beyond this, ask them to send it in parts. */
    public const MAX_AUDIO_BYTES = 8388608;

    /** Cached clips kept before the oldest are dropped. ~400 × 30KB ≈ 12MB. */
    public const CACHE_FILES = 400;

    /** What a browser is allowed to hand the transcriber. */
    public const AUDIO_TYPES = [
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/m4a',
        'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'video/webm',
    ];

    private const TTS_URL = 'https://api.elevenlabs.io/v1/text-to-speech/';
    private const STT_URL = 'https://api.elevenlabs.io/v1/speech-to-text';

    public function __construct(
        private readonly ?string $key = null,
        private readonly ?string $voice = null,
        private readonly ?string $ttsModel = null,
        private readonly ?string $sttModel = null,
        private readonly int $timeout = 20,
        /**
         * Where clips are cached. Null means the deployment's own `var/cache/voice`.
         *
         * A parameter rather than a constant because the cache is keyed by TEXT and is
         * therefore shared across submissions by design — which makes it shared across a test
         * run too, and a suite whose second run reports "cached" where the first reported
         * "fetched" is a suite that passes or fails depending on what ran yesterday.
         */
        private readonly ?string $cacheRoot = null,
    ) {}

    /**
     * Build from admin settings (gates_settings) with .env fallback — the same resolution
     * order every other provider on this platform uses, so a key can be pasted into the
     * console on a host where nobody can edit a file over SSH. Which, on this deployment,
     * is the only way it is ever going to be set.
     */
    public static function boot(): self
    {
        $resolve = static function (string $settingKey, string $envKey): ?string {
            $v = null;
            try { $v = DB::table('gates_settings')->where('key_name', $settingKey)->value('value'); }
            catch (\Throwable) {}
            $v = is_string($v) ? trim($v) : '';
            if ($v !== '') return $v;
            $env = Env::get($envKey);
            return ($env !== null && $env !== '') ? (string) $env : null;
        };

        return new self(
            $resolve('voice_elevenlabs_key', 'ELEVENLABS_API_KEY'),
            $resolve('voice_elevenlabs_voice', 'ELEVENLABS_VOICE_ID'),
            $resolve('voice_elevenlabs_model', 'ELEVENLABS_MODEL'),
            $resolve('voice_elevenlabs_stt_model', 'ELEVENLABS_STT_MODEL'),
        );
    }

    /** True when a key is present. Everything else in here checks this first. */
    public function configured(): bool
    {
        return $this->key !== null && $this->key !== '';
    }

    /** The voice id a request would use. */
    public function voiceId(): string
    {
        $v = trim((string) $this->voice);
        return $v !== '' ? $v : self::DEFAULT_VOICE;
    }

    /** The models a request would use — shown on the settings screen so it cannot drift. */
    public function ttsModel(): string
    {
        $m = trim((string) $this->ttsModel);
        return $m !== '' ? $m : self::TTS_MODEL;
    }

    public function sttModel(): string
    {
        $m = trim((string) $this->sttModel);
        return $m !== '' ? $m : self::STT_MODEL;
    }

    /**
     * One sentence an operator can act on, for the settings screen and the doctor command.
     *
     * Not shown to a nominee: a person filling in their own questionnaire has no use for
     * "no ElevenLabs key is configured", and telling them a feature is missing that they
     * never saw offered is only noise.
     */
    public function why(): string
    {
        if (!$this->configured()) {
            return 'No ElevenLabs key is set, so the questionnaire stays text-only. Paste one in '
                 . 'Settings → AI (or set ELEVENLABS_API_KEY) and the spoken questions and the '
                 . 'microphone appear on the nominee\'s page by themselves.';
        }
        return 'Voice is on: questions are read aloud with ' . $this->ttsModel()
             . ' and spoken answers are transcribed with ' . $this->sttModel() . '.';
    }

    // ══ Text to speech ════════════════════════════════════════════════════════

    /**
     * Speak one line. Returns raw MP3 bytes.
     *
     * Cached on disk by (voice, model, text), which is what makes replaying a question
     * free — and a nominee re-reading a question three times is the normal case, not the
     * edge case, since that is what the button is for.
     *
     * @return array{ok:bool, audio?:string, mime?:string, cached?:bool, chars?:int, message?:string}
     */
    public function speak(string $text): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'Voice is not configured.'];
        }

        $text = self::tidy($text);
        if ($text === '') return ['ok' => false, 'message' => 'Nothing to say.'];

        $path = $this->cachePath($text);
        if ($path !== null && is_file($path)) {
            $bytes = (string) @file_get_contents($path);
            if ($bytes !== '') {
                // Touched so the pruner treats "read recently" as "worth keeping". Without
                // this, the clip a nominee replays every visit is evicted on age while the
                // one nobody ever played again survives.
                @touch($path);
                return ['ok' => true, 'audio' => $bytes, 'mime' => 'audio/mpeg',
                        'cached' => true, 'chars' => 0];
            }
        }

        $bytes = $this->httpSpeak($text);
        if ($bytes === null || $bytes === '') {
            return ['ok' => false, 'message' => 'The voice service did not answer. '
                                              . 'You can still read the question and type your answer.'];
        }

        if ($path !== null) {
            @file_put_contents($path, $bytes, LOCK_EX);
            $this->prune();
        }

        return ['ok' => true, 'audio' => $bytes, 'mime' => 'audio/mpeg',
                'cached' => false, 'chars' => mb_strlen($text)];
    }

    /**
     * The single outbound TTS call. Protected so a test can intercept the network without
     * bypassing the cache, the tidying or the truncation — i.e. without skipping the parts
     * that decide what is actually sent and billed.
     */
    protected function httpSpeak(string $text): ?string
    {
        $url = self::TTS_URL . rawurlencode($this->voiceId())
             // 64kbps mono is plainly enough for one spoken sentence and is a third of the
             // bytes of the default 128k — this is read on Nigerian mobile data, where the
             // difference is the feature working or being abandoned mid-download.
             . '?output_format=mp3_44100_64';

        $payload = [
            'text'           => $text,
            'model_id'       => $this->ttsModel(),
            'voice_settings' => [
                // Higher stability than the default, because these are questions read to
                // somebody who may be nervous. An expressive read that varies line to line
                // sounds like a performance; a steady one sounds like a person asking.
                'stability'        => 0.55,
                'similarity_boost' => 0.75,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: audio/mpeg',
                'xi-api-key: ' . (string) $this->key,
            ],
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body) || $body === '') return null;
        return self::looksLikeAudio($body) ? $body : null;
    }

    /**
     * Is this body actually audio?
     *
     * ElevenLabs answers a bad key with a JSON error document, and a proxy in front of it can
     * pass that through with a 200 — this deployment has exactly such a proxy. Handing it to
     * an `<Audio>` element plays a burst of static into a nominee's ear and looks like the
     * platform breaking rather than a key needing rotating.
     *
     * An MP3 begins with an ID3 tag or an MPEG frame sync (0xFF followed by a byte whose top
     * three bits are set). Public and static so the check is testable on its own: it is the
     * kind of guard that is easy to write, easy to delete by accident, and silent when wrong.
     */
    public static function looksLikeAudio(string $body): bool
    {
        if (strlen($body) < 4) return false;
        if (str_starts_with($body, 'ID3')) return true;
        return ord($body[0]) === 0xFF && (ord($body[1]) & 0xE0) === 0xE0;
    }

    // ══ Speech to text ════════════════════════════════════════════════════════

    /**
     * Transcribe what somebody said. The words go back to the page, never to the row.
     *
     * @return array{ok:bool, text?:string, language?:string, message?:string}
     */
    public function transcribe(string $bytes, string $filename = 'answer.webm',
                               string $mime = 'audio/webm'): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'Voice is not configured.'];
        }
        if ($bytes === '') {
            return ['ok' => false, 'message' => 'That recording was empty. Try holding the button '
                                              . 'while you speak.'];
        }
        if (strlen($bytes) > self::MAX_AUDIO_BYTES) {
            return ['ok' => false, 'message' => 'That recording is too long. Say it in two or three '
                                              . 'shorter pieces and send each one.'];
        }

        $res = $this->httpTranscribe($bytes, $filename, $mime);
        if ($res === null) {
            return ['ok' => false, 'message' => 'We could not turn that recording into words. You '
                                              . 'can type the answer instead — nothing you have '
                                              . 'already answered is lost.'];
        }

        $text = self::tidy((string) ($res['text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'message' => 'We could not hear anything in that recording. '
                                              . 'Somewhere quieter, or hold the phone closer.'];
        }

        return ['ok' => true, 'text' => $text,
                'language' => (string) ($res['language_code'] ?? '')];
    }

    /**
     * The single outbound STT call. Protected for the same reason as {@see httpSpeak()}.
     *
     * @return array<string,mixed>|null
     */
    protected function httpTranscribe(string $bytes, string $filename, string $mime): ?array
    {
        // Built by hand rather than with CURLFile: the audio arrives as an uploaded stream
        // and writing it to a temp file first, purely to hand cURL a path, would put a
        // nominee's voice on this server's disk for no reason at all.
        $boundary = '----AfricaGATESVoice' . bin2hex(random_bytes(12));
        $eol      = "\r\n";
        $body     = '';

        $field = static function (string $name, string $value) use ($boundary, $eol): string {
            return '--' . $boundary . $eol
                 . 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol
                 . $value . $eol;
        };

        $body .= $field('model_id', $this->sttModel());
        // Word-level timing and speaker separation are both off: a questionnaire answer is
        // one person talking, and asking for either would return a payload several times
        // the size for information nothing here reads.
        $body .= $field('timestamps_granularity', 'none');
        $body .= $field('diarize', 'false');
        // Audio events ("[laughter]", "[music]") tagged into a nominee's answer would end up
        // quoted to a judge as though the nominee had typed them.
        $body .= $field('tag_audio_events', 'false');

        $body .= '--' . $boundary . $eol
               . 'Content-Disposition: form-data; name="file"; filename="'
               . str_replace(['"', "\r", "\n"], '', $filename) . '"' . $eol
               . 'Content-Type: ' . $mime . $eol . $eol
               . $bytes . $eol
               . '--' . $boundary . '--' . $eol;

        $ch = curl_init(self::STT_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            // Longer than the TTS timeout: transcription is proportional to how long
            // somebody spoke, and the whole point is that they were allowed to talk.
            CURLOPT_TIMEOUT        => max($this->timeout, 60),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Accept: application/json',
                'xi-api-key: ' . (string) $this->key,
            ],
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($resp) || $resp === '') return null;
        $j = json_decode($resp, true);
        return is_array($j) ? $j : null;
    }

    // ══ Housekeeping ══════════════════════════════════════════════════════════

    /**
     * Collapse whitespace and cut to {@see MAX_CHARS} at a sentence boundary.
     *
     * Cutting mid-word is what a naive substr does, and the result is a voice confidently
     * pronouncing half a word and stopping — which sounds like a fault in the platform
     * rather than a length limit.
     */
    public static function tidy(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || mb_strlen($text) <= self::MAX_CHARS) return $text;

        $cut = mb_substr($text, 0, self::MAX_CHARS);
        foreach (['. ', '? ', '! ', '; ', ', ', ' '] as $stop) {
            $at = mb_strrpos($cut, $stop);
            // Only honour a break in the last third, otherwise an early full stop would
            // throw away most of the question to end tidily.
            if ($at !== false && $at > (int) (self::MAX_CHARS * 0.66)) {
                return rtrim(mb_substr($cut, 0, $at + 1), ' ,;');
            }
        }
        return $cut;
    }

    /** Where clips live. Null when the directory cannot be created — caching is optional. */
    public function cacheDir(): ?string
    {
        $dir = $this->cacheRoot ?? dirname(__DIR__, 2) . '/var/cache/voice';
        if (is_dir($dir)) return is_writable($dir) ? $dir : null;
        return @mkdir($dir, 0775, true) || is_dir($dir) ? $dir : null;
    }

    private function cachePath(string $text): ?string
    {
        $dir = $this->cacheDir();
        if ($dir === null) return null;
        // The voice and model are in the key, so changing either in Settings does not serve
        // the old voice from cache for the rest of the cycle.
        return $dir . '/' . sha1($this->voiceId() . '|' . $this->ttsModel() . '|' . $text) . '.mp3';
    }

    /**
     * Keep the newest {@see CACHE_FILES} clips.
     *
     * Bounded rather than expiring: this deployment has no cron a human trusts and a
     * disk-quota host where "no space left on device" takes the whole site down, so the
     * limit is enforced by the thing that writes, at the moment it writes.
     */
    public function prune(): void
    {
        $dir = $this->cacheDir();
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
}
