<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The other voice at the door — OpenAI's, for when Azure's does not sound like a person.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT EACH ONE IS BETTER AT, WHICH IS THE WHOLE CHOICE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Azure publishes `en-NG` voices — Ezinne and Abeo — trained on Nigerian English. They say
 * Ada, Ngozi and Chidinma the way the people holding those names say them. What they do
 * not do is sound especially alive: the delivery is even, and a greeting read evenly four
 * hundred times in an evening starts to sound like a turnstile.
 *
 * OpenAI's voices are the other way round. They are markedly more natural and they can be
 * STEERED — {@see INSTRUCTIONS} tells the model what kind of person is speaking, which is
 * the lever Azure has no equivalent for. But they are American English, and left to
 * themselves they will anglicise exactly the names this door exists to say properly.
 *
 * So this is a real choice with a real cost on each side, and the platform must not pretend
 * otherwise. The one thing that makes it safe is {@see needsRespelling()}: the phonetic
 * respelling in {@see DoorWelcome::suggest()} is a crutch for a voice that does not know
 * these names, and it is switched ON here and OFF for the Nigerian voices — which is the
 * opposite of how it shipped, and the reason the door has been saying "Ah-dah".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE KEY, RESOLVED WHERE EVERY OTHER OPENAI CALL RESOLVES IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `ai_openai_key` first, `OPENAI_API_KEY` after — the same order and the same setting
 * {@see AiService::boot()} uses, because two readers of one credential is how the halves of
 * an integration come to disagree about whether it is configured.
 */
final class OpenAiVoice
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    /**
     * The model that accepts `instructions`. `tts-1` does not, and without steering this
     * voice has no advantage over Azure's worth the pronunciation it costs.
     */
    public const MODEL = 'gpt-4o-mini-tts';

    /** @var array<string,string> */
    public const VOICES = [
        'alloy'   => 'Alloy — even, unhurried',
        'ash'     => 'Ash — warm, low',
        'ballad'  => 'Ballad — soft, lyrical',
        'coral'   => 'Coral — bright, welcoming',
        'echo'    => 'Echo — calm, male',
        'sage'    => 'Sage — measured, female',
        'shimmer' => 'Shimmer — light, female',
        'verse'   => 'Verse — expressive, male',
    ];

    public const DEFAULT_VOICE = 'coral';

    /**
     * What kind of person is speaking. This is the reason to use this provider at all.
     *
     * Written as a DOOR rather than as a broadcast: the failure mode of an expressive TTS
     * on a greeting is theatre — a sing-song announcer voice that is charming once and
     * unbearable by the fortieth guest. The instruction asks for the register of somebody
     * who has been on the door all evening and is still pleased to see you.
     *
     * It also names the pronunciation duty explicitly, because the text arriving here has
     * already been respelled and the model must read that respelling rather than "correct"
     * it back to an English guess.
     */
    public const INSTRUCTIONS =
        'You are a warm Nigerian host greeting a guest at the door of an evening held in '
        . 'their honour. Speak naturally and unhurriedly, at conversational volume, as if '
        . 'to one person standing in front of you — never as an announcement to a room. '
        . 'Be genuinely pleased, not theatrical or sing-song. Read any hyphenated '
        . 'respelling of a name exactly as written: it is there because it is how that '
        . 'person says their own name.';

    /** Same resolver, same order, as every other OpenAI call on this platform. */
    private static function key(): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'ai_openai_key')->value('value');
            if (is_string($v) && trim($v) !== '') return trim($v);
        } catch (\Throwable) {}

        return trim((string) Env::get('OPENAI_API_KEY', ''));
    }

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    public static function why(): string
    {
        return self::configured() ? ''
            : 'No OpenAI key — add it under Settings → AI providers.';
    }

    public static function voice(): string
    {
        try {
            $v = trim((string) (DB::table('gates_settings')
                ->where('key_name', 'door_voice_openai')->value('value') ?? ''));
            if (isset(self::VOICES[$v])) return $v;
        } catch (\Throwable) {}

        return self::DEFAULT_VOICE;
    }

    /**
     * One line, as MP3 bytes. Null on any failure — a door with no sound is a working door.
     *
     * The PAUSE marker is stripped rather than translated: this endpoint takes plain text,
     * not SSML, and a literal `{{brk}}` would be read aloud. The model's own phrasing puts
     * a beat after a name without being asked, which is most of why it sounds like a person.
     */
    public static function say(string $text): ?string
    {
        $key  = self::key();
        $line = AzureVoice::plain($text);
        if ($key === '' || $line === '') return null;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode([
                'model'          => self::MODEL,
                'voice'          => self::voice(),
                'input'          => $line,
                'instructions'   => self::INSTRUCTIONS,
                'response_format' => 'mp3',
            ]),
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $code !== 200) {
            // Left where an operator can read it — `error_log` has no reader on this host.
            // The body is JSON on a failure and MP3 on success, so a short prefix of it is
            // the message rather than a wall of audio.
            self::remember($code, $err !== '' ? $err : substr((string) $raw, 0, 180));
            return null;
        }

        // Same guard Azure's path uses: a 200 carrying JSON is an error nobody caught, and
        // writing it to disk as a clip would give a guest a mouthful of silence at the door.
        return self::looksLikeMp3($raw) ? $raw : null;
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
                ['key_name' => 'openai_voice_last_error'],
                ['value' => trim('HTTP ' . $code . ' ' . $detail)]);
        } catch (\Throwable) {}
    }
}
