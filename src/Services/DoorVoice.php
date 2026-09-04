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
    public const AZURE  = 'azure';
    public const OPENAI = 'openai';

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
        self::AZURE  => 'Azure — Nigerian voices, says the names correctly',
        self::OPENAI => 'OpenAI — sounds more natural, needs the respelling to say the names',
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

    /** Can the chosen provider actually speak? */
    public static function configured(): bool
    {
        return self::provider() === self::OPENAI
            ? OpenAiVoice::configured()
            : AzureVoice::configured();
    }

    /** Why not, in words an operator can act on. '' when it can. */
    public static function why(): string
    {
        return self::provider() === self::OPENAI ? OpenAiVoice::why() : AzureVoice::why();
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
    public static function signature(): string
    {
        return self::provider() === self::OPENAI
            ? self::OPENAI . '|' . OpenAiVoice::MODEL . '|' . OpenAiVoice::voice()
            : self::AZURE . '|' . AzureVoice::voice() . '|' . AzureVoice::rate() . '|' . AzureVoice::pitch();
    }

    /** One line, as MP3 bytes, from whichever provider is chosen. Null on any failure. */
    public static function say(string $text): ?string
    {
        return self::provider() === self::OPENAI
            ? OpenAiVoice::say($text)
            : AzureVoice::say($text);
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
