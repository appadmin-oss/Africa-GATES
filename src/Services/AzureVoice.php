<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Azure Speech, for the one thing this platform needs a real voice for: saying a Nigerian
 * name properly at the door of an evening held in Nigeria.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AZURE AND NOT THE TWO ENGINES ALREADY HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see InterviewVoice} already speaks, through OpenAI or ElevenLabs, and the door does not
 * use either. The reason is the FREE TIER, not the voices — which is worth stating plainly,
 * because an earlier version of this note said ElevenLabs had no Nigerian voice and that was
 * simply wrong. It has several, they are good, and some are Yoruba-accented in a way Azure's
 * are not.
 *
 * The numbers are what decide it. ElevenLabs' free tier is 10,000 credits a month and
 * FORBIDS COMMERCIAL USE; a ticketed awards gala is commercial, so the honest comparison is
 * against paid Starter at 30,000. Azure's F0 is 500,000 characters a month with no such
 * restriction. A three-hundred-guest gala is about 13,500 characters — more than the whole
 * ElevenLabs free month in one evening, two evenings on Starter, and about a fortieth of an
 * Azure month.
 *
 * So: Azure is what makes this a STANDING feature rather than a budget line. ElevenLabs is a
 * legitimate upgrade for somebody willing to pay for a better voice, and the day that is
 * wanted the work is a provider column in the cache key — the key is voice-scoped already
 * (see DoorWelcome::keyFor) and would have to become provider-scoped too, or one provider's
 * clips would answer for the other's.
 *
 * Azure publishes `en-NG-EzinneNeural` (female) and `en-NG-AbeoNeural` (male). A welcome with
 * its greeting is about forty-five characters, so the free tier is roughly eleven thousand
 * people a month — more than this platform will greet in a year of galas.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT IS NEVER CALLED AT THE DOOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Read that as a hard rule, not a preference. A door is a queue, and a queue cannot wait on
 * an HTTPS round trip to a datacentre — least of all from the venue wifi this page was
 * designed around, on a phone with one bar. {@see DoorWelcome} renders the whole guest list
 * ahead of time and the door plays a file that is already on disk.
 *
 * So this class has exactly one caller pattern: a maintenance sweep, hours before anybody
 * arrives. Nothing here may be wired into a request a person is waiting on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CONFIGURED FROM THE ADMIN SCREEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Same doctrine as every other credential here: `gates_settings` first, `.env` as the
 * fallback, one resolver. There is no shell on this deployment, so a key that can only be
 * set in a file is a key that cannot be set.
 */
final class AzureVoice
{
    /**
     * The Nigerian voices Azure publishes. Both are `Neural`; neither supports styles or
     * roles, so there is nothing to expose beyond the choice of person.
     */
    public const VOICES = [
        'en-NG-EzinneNeural' => 'Ezinne — Nigerian, female',
        'en-NG-AbeoNeural'   => 'Abeo — Nigerian, male',
    ];

    public const DEFAULT_VOICE  = 'en-NG-EzinneNeural';
    public const DEFAULT_REGION = 'southafricanorth';

    /**
     * MP3 at 24kHz/48kbps. Small enough to sit in a page's cache on venue wifi, and a
     * welcome is one short sentence — a higher bitrate would buy nothing anybody can hear
     * through a phone speaker in a hall.
     */
    private const FORMAT = 'audio-24khz-48kbitrate-mono-mp3';

    /**
     * The caller's marker for a beat, swapped for a <break/> in {@see ssml()}.
     *
     * A marker rather than raw SSML in the phrase, so the text that goes into the cache key
     * is a plain string: a phrase carrying angle brackets would hash differently the day
     * somebody changed the break length, silently orphaning every clip on disk.
     */
    public const PAUSE = '{{brk}}';

    /** Azure lists User-Agent as REQUIRED on this endpoint, not optional. */
    private const AGENT = 'AfricaGates/1.0';

    /** Hours before the event, not seconds during it — see the class note. */
    private const TIMEOUT = 20;

    /**
     * ══════════════════════════════════════════════════════════════════════════
     * THE TIER, WHICH IS A RATE LIMIT AND NOT A PRICE
     * ══════════════════════════════════════════════════════════════════════════
     *
     * F0 is the free tier this whole feature is built on, and its constraint is not the
     * half-million characters a month — a gala spends about 13,500 of those. It is
     * **20 requests per minute**. A sweep that renders sixty clips back to back gets
     * twenty and then forty `429`s.
     *
     * That was the shape of the bug this constant exists to fix. `sweep()` counted
     * SUCCESSES against its cap, so a throttled run never reached the cap and walked the
     * entire guest list — every remaining name a request, every request a 429, every hour,
     * for as long as the event was inside the lead window. On a host with no shell the
     * only symptom was most guests not being greeted, which looks exactly like a feature
     * somebody forgot to switch on.
     *
     * So the tier is asked for, and the per-tick budget comes from it. The numbers are
     * requests per minute, taken a little under Microsoft's published figures because a
     * limiter that admits exactly its quota does not exist.
     *
     * @var array<string, array{label:string, rpm:int}>
     */
    public const TIERS = [
        'f0' => ['label' => 'Free (F0) — 20 requests a minute', 'rpm' => 18],
        's0' => ['label' => 'Standard (S0) — pay as you go',    'rpm' => 120],
    ];

    public const DEFAULT_TIER = 'f0';

    /** @var array<string, array{env:string, default:string, secret:bool, label:string}> */
    public const SETTINGS = [
        'azure_speech_key'    => ['env' => 'AZURE_SPEECH_KEY', 'default' => '', 'secret' => true,
                                  'label' => 'Azure Speech key'],
        'azure_speech_region' => ['env' => 'AZURE_SPEECH_REGION', 'default' => self::DEFAULT_REGION,
                                  'secret' => false, 'label' => 'Azure region'],
        'azure_speech_voice'  => ['env' => 'AZURE_SPEECH_VOICE', 'default' => self::DEFAULT_VOICE,
                                  'secret' => false, 'label' => 'Voice'],
        // Defaults to F0 because that is what somebody following this platform's own
        // instructions will have created, and because guessing the GENEROUS tier is the
        // guess that produces the silent failure above.
        'azure_speech_tier'   => ['env' => 'AZURE_SPEECH_TIER', 'default' => self::DEFAULT_TIER,
                                  'secret' => false, 'label' => 'Azure tier'],
    ];

    /** Where the last failure is left, because `error_log` has no reader on this host. */
    public const LAST_ERROR = 'azure_speech_last_error';

    public static function conf(string $key): string
    {
        $spec = self::SETTINGS[$key] ?? null;
        if ($spec === null) return '';

        try {
            $stored = trim((string) (DB::table('gates_settings')->where('key_name', $key)->value('value') ?? ''));
            if ($stored !== '') return $stored;
        } catch (\Throwable) {
            // No settings table yet (a deploy before db:migrate). Env still works.
        }

        $env = trim((string) Env::get($spec['env'], ''));

        return $env !== '' ? $env : $spec['default'];
    }

    public static function key(): string    { return self::conf('azure_speech_key'); }
    public static function region(): string { return self::conf('azure_speech_region') ?: self::DEFAULT_REGION; }

    /** The chosen tier, validated against the list. */
    public static function tier(): string
    {
        $t = strtolower(trim(self::conf('azure_speech_tier')));

        return isset(self::TIERS[$t]) ? $t : self::DEFAULT_TIER;
    }

    /**
     * How many requests one sweep may make.
     *
     * The whole point of asking for the tier. A run that exceeds this does not fail
     * loudly — it collects `429`s, one per guest, and the door stays quiet for everybody
     * after the twentieth.
     */
    public static function perMinute(): int
    {
        return max(1, (int) (self::TIERS[self::tier()]['rpm'] ?? self::TIERS[self::DEFAULT_TIER]['rpm']));
    }

    /**
     * The HTTP status of the last call, so a caller can tell a throttle from a fault.
     *
     * A sweep must stop on a `429` — the quota is spent for this minute and every further
     * request is guaranteed to fail — and must keep going past a one-off `500`, which is
     * about that clip rather than about the run.
     */
    private static int $lastStatus = 0;

    public static function throttled(): bool { return self::$lastStatus === 429; }

    /**
     * Leave the reason where a person can read it.
     *
     * `error_log()` was the only record this class kept, and there is no shell on this
     * deployment — so "the key is wrong" and "the tier is exhausted" and "the region does
     * not host this voice" all presented as a door that simply did not speak. This lands
     * in `gates_settings` and {@see why()} publishes it on the screen where the key was
     * typed, which is the only place anybody goes to ask about it.
     */
    private static function note(string $reason): void
    {
        error_log('[azure-voice] ' . $reason);

        try {
            $row = ['key_name' => self::LAST_ERROR,
                    'value' => mb_substr(date('Y-m-d H:i') . ' · ' . $reason, 0, 500)];

            DB::table('gates_settings')->where('key_name', self::LAST_ERROR)->exists()
                ? DB::table('gates_settings')->where('key_name', self::LAST_ERROR)->update(['value' => $row['value']])
                : DB::table('gates_settings')->insert($row);
        } catch (\Throwable) {
            // No settings table, or a schema without it. The error log still has the line;
            // this is the half that has a reader, not the half that must not throw.
        }
    }

    /** A clip came back. Clears a stale complaint so the screen stops crying wolf. */
    private static function clearNote(): void
    {
        try { DB::table('gates_settings')->where('key_name', self::LAST_ERROR)->delete(); }
        catch (\Throwable) {}
    }

    /** The last recorded failure, or ''. */
    public static function lastError(): string
    {
        try {
            return trim((string) (DB::table('gates_settings')
                ->where('key_name', self::LAST_ERROR)->value('value') ?? ''));
        } catch (\Throwable) { return ''; }
    }

    /**
     * The chosen voice, validated against the list.
     *
     * A voice name typed into a settings box and passed through unchecked would send Azure a
     * name it does not know, which comes back as a 400 during a sweep nobody is watching —
     * and the first anybody would learn of it is a silent door.
     */
    public static function voice(): string
    {
        $v = self::conf('azure_speech_voice');

        return isset(self::VOICES[$v]) ? $v : self::DEFAULT_VOICE;
    }

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    /**
     * What is wrong, in the words the settings screen shows. '' when nothing is.
     *
     * ONE RESOLVER. The screen, the status page and anything else asking "is the voice
     * working" go through here — two readers of that question is how the halves of an
     * integration come to disagree about whether it is configured.
     *
     * The last recorded failure is included, and it is the part that matters: everything
     * above this line is a check the screen could make for itself, and a key Azure REFUSED
     * looks identical to a key that works until somebody tries to use it. That attempt
     * happens at 06:00, unattended, on a host with no shell.
     */
    public static function why(): string
    {
        if (self::key() === '') {
            return 'No Azure Speech key, so nobody is greeted by name — the door still works, silently.';
        }
        if (self::region() === '') {
            return 'No Azure region set.';
        }

        return self::lastError();
    }

    private static function endpoint(): string
    {
        return 'https://' . self::region() . '.tts.speech.microsoft.com/cognitiveservices/v1';
    }

    /**
     * Render one line. Returns the MP3 bytes, or null.
     *
     * Null rather than an exception on every failure path: the caller is an unattended sweep
     * and the consequence of a failure is that one person is greeted by the generic clip
     * instead of by name. That must never become an error page or a stopped maintenance run.
     */
    public static function say(string $text): ?string
    {
        $text = self::tidy($text);
        if ($text === '' || !self::configured()) return null;

        $ssml = self::ssml($text);

        $ch = curl_init(self::endpoint());
        if ($ch === false) return null;

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $ssml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Ocp-Apim-Subscription-Key: ' . self::key(),
                'Content-Type: application/ssml+xml',
                'X-Microsoft-OutputFormat: ' . self::FORMAT,
                'User-Agent: ' . self::AGENT,
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        self::$lastStatus = $code;

        if (!is_string($raw) || $raw === '' || $code !== 200) {
            // NAMED, in the words somebody can act on. "HTTP 401" sends an operator to
            // search engines; "the key was refused" sends them to the box they typed it
            // into. Every one of these is a different fix and they were indistinguishable.
            self::note(match (true) {
                $code === 401 || $code === 403
                    => 'Azure refused the key. Check it, and that it belongs to the region below.',
                $code === 429
                    => 'Azure is rate-limiting this key — the ' . strtoupper(self::tier())
                     . ' tier allows about ' . self::perMinute() . ' requests a minute. '
                     . 'The rest of the guest list is rendered on the next run.',
                $code === 404
                    => 'No Speech endpoint at region "' . self::region() . '". Check the region.',
                $code === 400
                    => 'Azure rejected the request — usually a voice its region does not host.',
                $code === 0
                    => 'Could not reach Azure' . ($err !== '' ? ': ' . $err : '.'),
                default
                    => 'Azure answered ' . $code . ' — ' . substr(is_string($raw) ? $raw : '', 0, 120),
            });

            return null;
        }

        // Azure answers a bad request with 200 and a JSON body in some error shapes, so the
        // bytes are checked rather than the status: an MP3 begins with an ID3 tag or a frame
        // sync. Writing a JSON error into a .mp3 file would cache a clip that plays nothing
        // and never retries, which is worse than not caching at all.
        if (!self::looksLikeMp3($raw)) {
            self::note('Azure answered 200 with something that is not audio — the clip was '
                     . 'not cached, because a JSON error written into a .mp3 plays silence '
                     . 'and never retries.');
            return null;
        }

        self::clearNote();

        return $raw;
    }

    /**
     * The markup, not just the words.
     *
     * ── WHY A DOOR NEEDS PROSODY ─────────────────────────────────────────────
     *
     * The first version sent bare text and it read like an announcement at a railway
     * station: even, fast, and hard to catch in a hall with two hundred people in it. Three
     * changes, each for a reason somebody standing at a gate would recognise:
     *
     *   THE BEAT AFTER THE NAME. "Ada, <pause> you are welcome" is how the sentence is
     *   actually said. Without it the name runs into the greeting and the one word the
     *   person is listening for — their own — is the one they miss.
     *
     *   SLOWER, BY A TENTH. A door is noisy and the listener is not expecting to be spoken
     *   to. Azure allows 0.5–2×; -10% is the difference between a sentence you catch and
     *   one you ask to have repeated, and it costs a quarter of a second.
     *
     *   A LITTLE LOWER. Two semitones down reads as warmth rather than as a notification.
     *   The range is bounded at 0.5–1.5× the original, so this is nowhere near the edge.
     *
     * `<mstts:express-as>` is deliberately absent: Microsoft's own voice list says styles
     * and roles are NOT supported for either Nigerian English voice, and sending one gets
     * the markup ignored at best. Everything here is core SSML.
     *
     * `{{brk}}` is the caller's marker for the beat — the pause belongs to the phrasing, so
     * {@see DoorWelcome} decides where it falls, and this decides how long it is. The text
     * is escaped BEFORE the marker is swapped, so a name containing the marker's literal
     * characters cannot inject markup.
     */
    public static function ssml(string $text): string
    {
        $safe = htmlspecialchars(self::tidy($text), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $safe = str_replace(self::PAUSE, '<break time="260ms"/>', $safe);

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="en-NG">'
             . '<voice name="' . htmlspecialchars(self::voice(), ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
             . '<prosody rate="-10%" pitch="-2st">' . $safe . '</prosody>'
             . '</voice></speak>';
    }

    private static function looksLikeMp3(string $raw): bool
    {
        if (strlen($raw) < 64) return false;

        return str_starts_with($raw, 'ID3') || (ord($raw[0]) === 0xFF && (ord($raw[1]) & 0xE0) === 0xE0);
    }

    /**
     * What is safe to send.
     *
     * Bounded hard: this is billed per character and fed from names a stranger typed into a
     * booking form. Control characters go because SSML is XML and a stray one is a 400 on a
     * sweep nobody is watching.
     */
    public static function tidy(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', trim($text)) ?? '';

        $text = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, 240);

        // A cap that lands mid-marker would leave "{{br" in the spoken text. Rare, and the
        // fix is one line — a half-marker read aloud at a door is not a bug anybody would
        // enjoy diagnosing from a recording.
        return (string) preg_replace('/\{\{b?r?k?\}?\}?$/', '', $text);
    }
}
