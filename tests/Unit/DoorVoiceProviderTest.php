<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AzureVoice, DoorVoice, DoorWelcome, OpenAiVoice};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE DOOR SOUNDED WRONG BECAUSE IT WAS BEING HELPED.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CRUTCH BECAME THE LIMP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `DoorWelcome::suggest()` turns a name into pseudo-syllables — Ada into `Ah-dah`, Ngozi
 * into `N-goh-zee`, Chidinma into `Chee-deen-mah`. It was written because an English voice
 * read Nigerian names by English rules, and for that voice it fixed something real.
 *
 * The default voice is `en-NG-EzinneNeural`: Azure's Nigerian English, trained on these
 * names, which says Ada the way Ada says it. Handing THAT a respelling does not help. It
 * hands a neural voice a hyphenated non-word to over-articulate, and what comes out of the
 * speaker is a machine spelling at a guest — which is what a steward hears as the voice not
 * sounding smart, and it had been doing it to every guest since the respelling shipped.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SO IT IS A PROPERTY OF THE VOICE, NOT A SETTING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * OpenAI's voices are markedly more natural and can be steered, and they are American: left
 * alone they will anglicise exactly the names this door exists to say properly. So they get
 * the respelling and the Nigerian voices do not, and neither of those is a preference
 * anybody should have to reason about at 7pm with a queue forming.
 */
final class DoorVoiceProviderTest extends TestCase
{
    private function set(string $key, string $value): void
    {
        DB::table('gates_settings')->where('key_name', $key)->delete();
        DB::table('gates_settings')->insert(['key_name' => $key, 'value' => $value]);
    }

    private function useOpenAi(): void { $this->set(DoorVoice::SETTING, DoorVoice::OPENAI); }

    /**
     * Azure is no longer the default, so a test about Azure has to SAY so.
     *
     * Every Azure case here used to inherit the provider from `DoorVoice::DEFAULT` and read
     * as a statement about the platform when it was a statement about a setting. Naming it
     * is the smaller change and the better test: the default moved once and it can move
     * again, and none of these assertions is about which one is assumed.
     */
    private function useAzure(): void { $this->set(DoorVoice::SETTING, DoorVoice::AZURE); }

    // ══ who needs the names spelled out ══════════════════════════════════════

    /** The default is Azure's Nigerian voice, and it is handed the name as written. */
    public function test_a_nigerian_voice_is_given_the_name_as_written(): void
    {
        $this->useAzure();

        $this->assertFalse(DoorVoice::needsRespelling(),
            'the default voice is being handed a respelling it does not need');

        foreach (['Ada' => 'Ada', 'Ngozi' => 'Ngozi', 'Chidinma' => 'Chidinma'] as $name => $said) {
            $line = DoorWelcome::line($name . ' Obi');
            $this->assertStringContainsString($said, $line,
                $name . ' is not being said as it is spelled');
        }

        // And specifically NOT the pseudo-syllables. This is the sound being fixed.
        $line = DoorWelcome::line('Ngozi Eze');
        $this->assertStringNotContainsString('N-goh-zee', $line,
            'a voice trained on Nigerian English is being told how to spell Ngozi');
    }

    /** An American voice gets every syllable, because it will guess otherwise. */
    public function test_an_american_voice_is_given_the_respelling(): void
    {
        $this->useOpenAi();

        $this->assertTrue(DoorVoice::needsRespelling());
        $this->assertStringContainsString('N-goh-zee', DoorWelcome::line('Ngozi Eze'),
            'a voice that does not know these names is being left to guess at them');
    }

    /**
     * READ FROM THE VOICE, NOT FROM THE PROVIDER'S NAME.
     *
     * "Azure means Nigerian" is true only because `AzureVoice::VOICES` currently offers
     * nothing else, and that is an allowlist somebody can extend in a minute. The rule is
     * about the LOCALE, so the day an `en-GB` or `en-US` voice is added to that list it
     * gets the respelling back on its own — rather than inheriting an assumption made here
     * today and quietly saying "Ada" the English way to a hall of people.
     *
     * Asserted as the coupling between the two, which is the thing that can rot: every
     * voice offered must be one this rule considers safe to hand a name to unaided.
     */
    public function test_every_azure_voice_offered_is_one_that_knows_these_names(): void
    {
        $this->useAzure();

        foreach (array_keys(AzureVoice::VOICES) as $voice) {
            $this->set('azure_speech_voice', $voice);

            $this->assertFalse(DoorVoice::needsRespelling(),
                $voice . ' is offered at the door but is not a voice this rule trusts with '
                . 'these names — either it should not be on the list, or the rule is wrong');
            $this->assertMatchesRegularExpression('/^en-(NG|KE|GH|ZA|TZ)-/i', $voice,
                $voice . ' was added to the door\'s voice list without deciding whether it '
                . 'can say the names this platform exists to say correctly');
        }
    }

    /**
     * AN OPERATOR'S OWN CORRECTION IS NEVER SUPPRESSED.
     *
     * Somebody heard a name said wrong and typed how it goes. No rule here is entitled to
     * overrule that — not even "this voice knows these names", because the fact that they
     * sat down and wrote the entry is evidence it did not.
     */
    public function test_a_hand_written_pronunciation_survives_a_voice_that_knows_the_names(): void
    {
        $this->useAzure();

        $this->assertFalse(DoorVoice::needsRespelling(), 'the fixture is not the case under test');
        $this->set('door_welcome_says', "Ada = A-DAH-the-operator-heard-this");

        $this->assertStringContainsString('A-DAH-the-operator-heard-this', DoorWelcome::line('Ada Obi'),
            'a correction a person typed was thrown away by a rule about the voice');
    }

    // ══ the clip cache ═══════════════════════════════════════════════════════

    /**
     * SWITCHING PROVIDER MUST RETIRE THE CLIPS.
     *
     * Clips are rendered hours ahead and looked up on disk by this hash. Leave the provider
     * out of it and every file already there matches a key that no longer describes the
     * voice that made it — so the door goes on serving the old provider's audio for as long
     * as those files live, silently, because the only question it ever asks is whether the
     * file exists.
     */
    public function test_changing_provider_changes_every_key(): void
    {
        $this->useAzure();
        $line   = DoorWelcome::line('Ada Obi');
        $before = DoorWelcome::keyFor($line);

        $this->useOpenAi();

        $this->assertNotSame($before, DoorWelcome::keyFor(DoorWelcome::line('Ada Obi')),
            'the provider changed and every clip already on disk answers for the old one');
    }

    /** And so must changing the OpenAI voice, for the same reason. */
    public function test_changing_the_openai_voice_changes_every_key(): void
    {
        $this->useOpenAi();
        $before = DoorWelcome::keyFor('Ada, you are welcome.');

        $this->set('door_voice_openai', 'verse');

        $this->assertNotSame($before, DoorWelcome::keyFor('Ada, you are welcome.'));
    }

    /**
     * Azure's pacing must NOT move an OpenAI key.
     *
     * `rate` and `pitch` are Azure's prosody controls and mean nothing to OpenAI. Folding
     * them into every key would retire a whole evening of clips because somebody nudged a
     * slider that does not reach the voice being used.
     */
    public function test_azure_pacing_does_not_retire_openai_clips(): void
    {
        $this->useOpenAi();
        $before = DoorWelcome::keyFor('Ada, you are welcome.');

        $this->set('azure_speech_rate', '-10%');

        $this->assertSame($before, DoorWelcome::keyFor('Ada, you are welcome.'),
            'an Azure slider retired clips made by a provider it does not speak to');
    }

    // ══ configuration ════════════════════════════════════════════════════════

    /** The chosen provider decides what "configured" means, and what to tell an operator. */
    public function test_readiness_names_the_provider_the_operator_chose(): void
    {
        $this->useOpenAi();
        $this->set('door_welcome_enabled', '1');
        // An Azure key present and an OpenAI key absent: the state where a wrong
        // instruction is most convincing.
        $this->set('azure_speech_key', 'an-azure-key');

        $r = DoorWelcome::readiness();

        $this->assertFalse($r['voice'], 'an Azure key was read as configuring OpenAI');
        $this->assertStringContainsString('OpenAI', (string) $r['fix'],
            'an operator using OpenAI is being told to add an Azure key');
        $this->assertStringNotContainsString('Azure Speech key', (string) $r['fix']);
    }

    /** An unknown provider in the setting falls back rather than silencing the door. */
    public function test_an_unknown_provider_falls_back_to_the_default(): void
    {
        $this->set(DoorVoice::SETTING, 'whatever-somebody-typed');

        $this->assertSame(DoorVoice::DEFAULT, DoorVoice::provider());
    }

    /** Both providers are offered, and the default is the one that says the names right. */
    /**
     * THE DEFAULT IS OPENAI.
     *
     * It was Azure, on the reasoning that `en-NG` says these names without a respelling
     * standing in for it. That reasoning is still true and it stopped being the right
     * default: Azure needs a key AND a region, and a deployment that had only ever
     * configured `ai_openai_key` — the key this platform uses everywhere else — had a door
     * that could not speak and no screen that said why. The respelling path works, and a
     * voice that says the name properly inside a sentence that sounds like a person beats
     * one that says it properly and nothing else.
     */
    public function test_the_default_is_openai(): void
    {
        $this->assertSame(DoorVoice::OPENAI, DoorVoice::DEFAULT);
        // With nothing configured at all, that is what the door reaches for.
        $this->assertSame(DoorVoice::OPENAI, DoorVoice::provider());
        // And it needs the names spelled out, which is the cost of the choice.
        $this->assertTrue(DoorVoice::needsRespelling());
        $this->assertArrayHasKey(DoorVoice::OPENAI, DoorVoice::PROVIDERS);
        $this->assertArrayHasKey(OpenAiVoice::DEFAULT_VOICE, OpenAiVoice::VOICES);
        $this->assertArrayHasKey(AzureVoice::DEFAULT_VOICE, AzureVoice::VOICES);
    }
}
