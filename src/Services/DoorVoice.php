<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHICH voice speaks at the door, and whether it needs help with the names.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE RESOLVER, BECAUSE THE CACHE KEY DEPENDS ON THE ANSWER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Clips are rendered hours ahead and looked up on disk by a hash of the sentence and the
 * voice that will say it. Add a second provider without folding it into that hash and
 * switching provider serves every guest the OLD provider's clip — silently, because the
 * file exists and the door only ever asks whether it exists. {@see signature()} is why
 * this class exists at all.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE THING THAT WAS MAKING THE DOOR SOUND WRONG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see DoorWelcome::suggest()} respells a name into pseudo-syllables — Ada becomes
 * `Ah-dah`, Ngozi becomes `N-goh-zee`. It was written for a voice that reads Nigerian names
 * by English rules, and it fixed a real fault for that voice.
 *
 * The default voice is now `en-NG-EzinneNeural`, which is trained on Nigerian English and
 * says Ada correctly. Handing IT a respelling does not help; it hands a neural voice a
 * hyphenated non-word to over-articulate, which is exactly the "does not sound smart" a
 * steward hears. The crutch became the limp.
 *
 * So the respelling is scoped to the voices that need it — and that is a property of the
 * PROVIDER, not a setting anybody should have to reason about. A hand-written correction is
 * never suppressed: an operator who typed one heard something wrong and fixed it, and that
 * judgement outranks any rule here. {@see DoorWelcome::saidAs()}.
 */
final class DoorVoice
{
    public const AZURE      = 'azure';
    public const OPENAI     = 'openai';
    public const ELEVENLABS = 'elevenlabs';

    /**
     * THE ORDER THE DOOR TRIES, AND WHY IT IS A CHAIN AT ALL.
     *
     * This class used to pick ONE provider. A provider that refused took the greeting with
     * it: a key expires on the morning of a gala, four hundred people walk past a door
     * that has gone quiet, and nobody on the night can do anything about it. The
     * `door_voice_provider` setting chose which single point of failure you wanted.
     *
     * It is a chain now. The chosen provider goes first and the rest follow in this order;
     * {@see say()} walks it and returns the first provider that actually produces audio, so
     * a refusal costs one attempt rather than the evening.
     *
     * Ordered by how the operator ranked them: ElevenLabs is the most natural, Azure is the
     * one that says African names correctly without a respelling, and it is last because
     * it needs a region as well as a key and is therefore the likeliest to be
     * half-configured. A provider with no key is skipped without being attempted — it is
     * not a failure, it is not set up.
     *
     * And past the end of the chain there is still one more voice: the BROWSER's own
     * speech synthesis, in `door.twig`, which needs no key and no network. That is what
     * makes "the door says nothing" no longer a state this system has.
     */
    public const CHAIN = [self::OPENAI, self::ELEVENLABS, self::AZURE];

    public const SETTING = 'door_voice_provider';

    /**
     * OPENAI BY DEFAULT.
     *
     * It was Azure, on the reasoning that `en-NG` is the only voice here that says African
     * names correctly without a respelling standing in for it. That reasoning is still
     * true and it is no longer the right default, for two reasons the operator settled by
     * listening to both:
     *
     *  · The respelling works. {@see DoorWelcome::saidAs()} spells a first name out
     *    syllable by syllable and {@see OpenAiVoice::INSTRUCTIONS} tells the model to read
     *    that spelling verbatim, so the name is said properly and the sentence around it
     *    sounds like a person rather than a station announcement.
     *  · Azure needs a key AND a region, and the door was reaching neither on a
     *    deployment that had only ever configured OpenAI — for the whole platform, since
     *    `ai_openai_key` is the one this site already uses everywhere else.
     *
     * A deployment that has an Azure key and wants the `en-NG` voice can still choose it;
     * this only changes which one is assumed.
     */
    public const DEFAULT = self::OPENAI;

    /** @var array<string,string> */
    public const PROVIDERS = [
        self::OPENAI     => 'OpenAI — sounds more natural, needs the respelling to say the names',
        self::ELEVENLABS => 'ElevenLabs — the most natural, needs the respelling too',
        self::AZURE      => 'Azure — Nigerian voices, says the names correctly',
    ];

    public static function provider(): string
    {
        try {
            $v = trim((string) (DB::table('gates_settings')
                ->where('key_name', self::SETTING)->value('value') ?? ''));
            if (isset(self::PROVIDERS[$v])) return $v;
        } catch (\Throwable) {}

        return self::DEFAULT;
    }

    /** The chain as it will actually be tried: chosen first, then the rest. */
    public static function order(): array
    {
        $first = self::provider();

        return array_values(array_unique(array_merge([$first], self::CHAIN)));
    }

    /** Can ANY link in the chain speak? */
    public static function configured(): bool
    {
        foreach (self::order() as $p) {
            if (self::configuredFor($p)) return true;
        }
        return false;
    }

    /** Is this one provider set up? */
    public static function configuredFor(string $provider): bool
    {
        return match ($provider) {
            self::OPENAI     => OpenAiVoice::configured(),
            self::ELEVENLABS => ElevenLabsVoice::configured(),
            default          => AzureVoice::configured(),
        };
    }

    /**
     * Why nothing can speak, in words an operator can act on. '' when something can.
     *
     * Reports the CHOSEN provider's reason, because that is the one they meant to use and
     * the box they will go and look at — but only once the whole chain is empty. A door
     * whose second choice is working is not broken and must not say it is.
     */
    public static function why(): string
    {
        if (self::configured()) return '';

        return match (self::provider()) {
            self::OPENAI     => OpenAiVoice::why(),
            self::ELEVENLABS => ElevenLabsVoice::why(),
            default          => AzureVoice::why(),
        };
    }

    /**
     * WHY THE LAST ATTEMPT FAILED, FROM WHICHEVER PROVIDER ACTUALLY TRIED.
     *
     * {@see why()} answers "could this deployment speak at all" — a configuration
     * question, answerable before anything is attempted. This answers the different one:
     * something WAS attempted and did not come back. A rate limit, a rejected key, a
     * region that does not host the voice.
     *
     * Split because the settings screen needs both and they have opposite failure modes:
     * `why()` empty and `lastError()` full is a configured provider that is refusing, and
     * that combination used to render as silence with no explanation anywhere.
     */
    public static function lastError(): string
    {
        return match (self::provider()) {
            self::OPENAI     => OpenAiVoice::lastError(),
            self::ELEVENLABS => ElevenLabsVoice::lastError(),
            default          => AzureVoice::lastError(),
        };
    }

    /**
     * The line as a person would read it — pause markers out, whitespace collapsed.
     *
     * Delegated rather than reimplemented. Both providers consume the same marker
     * convention (Azure translates it into SSML, OpenAI strips it), so there is one
     * normalisation and it lives where the marker constant does. A second spelling here
     * is how a preview comes to show wording the door does not actually say.
     */
    public static function plain(string $text): string
    {
        return AzureVoice::plain($text);
    }

    /**
     * DOES THIS VOICE NEED THE NAMES SPELLED OUT FOR IT?
     *
     * A property of the voice, not a preference. Azure's `en-NG` voices are trained on
     * Nigerian English and say these names as their owners do; OpenAI's are American and
     * will anglicise them unless told, syllable by syllable, how they go.
     *
     * Read from the VOICE rather than the provider name, so an operator who selects a
     * non-Nigerian Azure voice in future gets the respelling back automatically instead of
     * inheriting an assumption made here today.
     */
    public static function needsRespelling(): bool
    {
        if (self::provider() === self::OPENAI) return true;

        // en-NG-… and anything else Azure publishes for an African English locale. A voice
        // outside that set is reading these names by rules that are not theirs.
        return !preg_match('/^en-(NG|KE|GH|ZA|TZ)-/i', AzureVoice::voice());
    }

    /**
     * Everything that changes how a line SOUNDS, as one string, for the clip cache key.
     *
     * The provider is first and is not optional: without it, switching from Azure to OpenAI
     * leaves every clip on disk matching a key that no longer describes the voice that made
     * it, and the door serves the old provider's audio for as long as those files live.
     */
    public static function signature(?string $provider = null): string
    {
        return match ($provider ?? self::provider()) {
            self::OPENAI     => self::OPENAI . '|' . OpenAiVoice::MODEL . '|' . OpenAiVoice::voice(),
            self::ELEVENLABS => self::ELEVENLABS . '|' . ElevenLabsVoice::MODEL . '|' . ElevenLabsVoice::voice(),
            default          => self::AZURE . '|' . AzureVoice::voice() . '|'
                                . AzureVoice::rate() . '|' . AzureVoice::pitch(),
        };
    }

    /**
     * One line, as MP3 bytes, from the first provider in the chain that answers.
     *
     * ── WHAT THIS REPLACED ───────────────────────────────────────────────────
     *
     * A single call to the chosen provider. If it refused — expired key, spent quota, a
     * 429 from the free tier — the greeting was gone and the door was silent for the rest
     * of the evening, with the reason recorded in a settings row nobody was going to read
     * before the guests arrived.
     *
     * ── WHAT IS SKIPPED AND WHAT IS TRIED ────────────────────────────────────
     *
     * A provider with no key is skipped WITHOUT being attempted: it is not a failure, it
     * is not set up, and attempting it would overwrite the recorded reason of the one that
     * genuinely refused — which is the sentence the operator needs.
     *
     * `sayWith()` reports which provider produced the audio, because the caller has to
     * file the clip under THAT provider's signature. Filing a fallback clip under the
     * chosen provider's key is how a door comes to serve Azure audio for months while
     * every screen says OpenAI.
     */
    public static function say(string $text): ?string
    {
        return self::sayWith($text)['mp3'];
    }

    /**
     * @return array{mp3:?string, provider:?string} which provider actually spoke
     */
    public static function sayWith(string $text): array
    {
        foreach (self::order() as $p) {
            if (!self::configuredFor($p)) continue;

            $mp3 = match ($p) {
                self::OPENAI     => OpenAiVoice::say($text),
                self::ELEVENLABS => ElevenLabsVoice::say($text),
                default          => AzureVoice::say($text),
            };
            if ($mp3 !== null && $mp3 !== '') return ['mp3' => $mp3, 'provider' => $p];
        }

        return ['mp3' => null, 'provider' => null];
    }

    /**
     * How many clips one sweep tick may attempt.
     *
     * Azure's free tier is the binding constraint and {@see AzureVoice::perMinute()} knows
     * it. OpenAI has no comparable free tier to trip over, so the sweep's own ceiling
     * stands — the cap exists to keep a tick short, not to dodge a quota.
     */
    public static function perMinute(): int
    {
        return self::provider() === self::OPENAI ? DoorWelcome::CAP : AzureVoice::perMinute();
    }

    /** Has the provider asked us to stop for a moment? Only Azure meters this way. */
    public static function throttled(): bool
    {
        return self::provider() === self::AZURE && AzureVoice::throttled();
    }
}
