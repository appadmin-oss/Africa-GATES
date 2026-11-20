<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * ElevenLabs at the door.
 *
 * ── WHY A THIRD PROVIDER ─────────────────────────────────────────────────────
 *
 * Not because two were not enough to choose from. Because choosing was the problem:
 * {@see DoorVoice} used to pick ONE provider, and a provider that refused took the
 * greeting with it. A key expires on the morning of a gala and four hundred people walk
 * past a door that has gone quiet, with nothing on the night able to fix it.
 *
 * DoorVoice is a CHAIN now, and this sits in the middle of it. What it adds on its own
 * merits is the most natural-sounding of the three, and a model built for interactive
 * latency rather than for batch.
 *
 * ── THE CONTRACT ─────────────────────────────────────────────────────────────
 *
 * Identical to {@see OpenAiVoice} and {@see AzureVoice}, deliberately: `configured()`,
 * `why()`, `say()`, `lastError()`, `forget()`. The chain calls them without knowing which
 * provider it is holding, so a fourth can be added without touching the door.
 *
 * ── AND WHY EVERY FAILURE IS RECORDED ────────────────────────────────────────
 *
 * A door with no sound is a working door — nothing about a greeting may hold a queue — so
 * every path here returns null and lets the evening continue. That is right, and it is
 * exactly why these faults survived for months: no error, no console line, nothing to
 * grep. The reason goes into `gates_settings` and {@see DoorWelcome::readiness()} reads it
 * back onto the screen where somebody set the key.
 */
final class ElevenLabsVoice
{
    /** Voice id goes in the PATH, not the body — see the endpoint shape. */
    private const ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech/';

    /**
     * Balanced quality and latency, and multilingual — which matters here.
     *
     * The alternative is `eleven_multilingual_v2`: better prosody, roughly three times
     * slower. The sweep renders hours ahead so latency is not the constraint at the door,
     * but it IS the constraint on the preview button an operator presses twenty times
     * while tuning a pronunciation.
     */
    public const MODEL = 'eleven_turbo_v2_5';

    /** MP3, 44.1kHz, 128kbps — what `DoorWelcome` writes and the door plays. */
    public const FORMAT = 'mp3_44100_128';

    /**
     * The stock voices, by id.
     *
     * ElevenLabs identifies a voice by an opaque id rather than a name, so these cannot be
     * derived and an operator cannot guess one. A custom or cloned voice is set by pasting
     * its id into the settings field, which is why {@see voice()} accepts anything
     * id-shaped rather than only these.
     */
    public const VOICES = [
        'EXAVITQu4vr4xnSDxMaL' => 'Sarah — warm, measured',
        '9BWtsMINqrJLrRacOk9x' => 'Aria — expressive',
        'CwhRBWXzGAHq8TQ4Fs17' => 'Roger — steady, male',
        'FGY2WhTYpPnrIDTdsKH5' => 'Laura — bright, welcoming',
        'IKne3meq5aSn9XLyUdCD' => 'Charlie — natural, male',
        'XB0fDUnXU5powFXDhCwa' => 'Charlotte — soft',
        'onwK4e9ZLuTAKqWW03F9' => 'Daniel — authoritative, male',
        'pFZP5JQG7iQjIQuC4Bku' => 'Lily — light',
    ];

    public const DEFAULT_VOICE = 'EXAVITQu4vr4xnSDxMaL';

    private const LAST_ERROR = 'elevenlabs_voice_last_error';

    /** Settings first, `.env` as the fallback — no shell on production. */
    private static function key(): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'ai_elevenlabs_key')->value('value');
            if (is_string($v) && trim($v) !== '') return trim($v);
        } catch (\Throwable) {}

        return trim((string) Env::get('ELEVENLABS_API_KEY', ''));
    }

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    public static function why(): string
    {
        return self::configured() ? ''
            : 'No ElevenLabs key — add it under Settings → AI providers.';
    }

    /**
     * The chosen voice id.
     *
     * Anything id-shaped is accepted, not only {@see VOICES}: a cloned or custom voice is
     * the reason most people pay for this provider, and refusing an id we do not have in a
     * hardcoded list would make that unreachable. A malformed value falls back rather than
     * being sent, because a bad id is a 404 the door swallows.
     */
    public static function voice(): string
    {
        try {
            $v = trim((string) (DB::table('gates_settings')
                ->where('key_name', 'door_voice_elevenlabs')->value('value') ?? ''));
            if (preg_match('/^[A-Za-z0-9]{16,40}$/', $v) === 1) return $v;
        } catch (\Throwable) {}

        return self::DEFAULT_VOICE;
    }

    /** One line, as MP3 bytes. Null on any failure, with the reason recorded. */
    public static function say(string $text): ?string
    {
        $key  = self::key();
        $line = AzureVoice::plain($text);

        if ($key === '') {
            self::remember(0, 'No ElevenLabs key is set — add it under Settings → AI providers.');
            return null;
        }
        if ($line === '') {
            self::remember(0, 'The line to speak was empty after cleaning.');
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT . rawurlencode(self::voice())
                                      . '?output_format=' . self::FORMAT,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // `xi-api-key`, NOT a bearer token. The other two providers here use
            // Authorization headers and copying that shape gets a 401 that reads exactly
            // like a wrong key.
            CURLOPT_HTTPHEADER     => ['xi-api-key: ' . $key, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => (string) json_encode([
                'text'     => $line,
                'model_id' => self::MODEL,
            ]),
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $code !== 200) {
            self::remember($code, $err !== '' ? $err : substr((string) $raw, 0, 180));
            return null;
        }

        // A 200 carrying JSON is an error nobody caught, and writing it to disk as a clip
        // gives a guest a mouthful of silence at the door — the most confusing failure
        // available, because every check upstream reads healthy.
        if (!self::looksLikeMp3($raw)) {
            self::remember(200, 'The reply was not audio: ' . substr($raw, 0, 180));
            return null;
        }

        self::forget();

        return $raw;
    }

    public static function lastError(): string
    {
        try {
            return trim((string) (DB::table('gates_settings')
                ->where('key_name', self::LAST_ERROR)->value('value') ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function forget(): void
    {
        try {
            DB::table('gates_settings')->where('key_name', self::LAST_ERROR)->delete();
        } catch (\Throwable) {}
    }

    private static function looksLikeMp3(string $raw): bool
    {
        if (strlen($raw) < 64) return false;

        return str_starts_with($raw, 'ID3') || (ord($raw[0]) === 0xFF && (ord($raw[1]) & 0xE0) === 0xE0);
    }

    private static function remember(int $code, string $detail): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => self::LAST_ERROR],
                ['value' => trim('HTTP ' . $code . ' ' . $detail)]);
        } catch (\Throwable) {}
    }
}
