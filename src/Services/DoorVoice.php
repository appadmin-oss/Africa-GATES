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
     * Azure by default, and the reason is the door's own purpose: this platform exists to
     * say African names correctly, and `en-NG` is the only option here that does it without
     * a respelling standing in for it. OpenAI is the better-sounding voice and the operator
     * can choose it knowingly.
     */
    public const DEFAULT = self::AZURE;

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
