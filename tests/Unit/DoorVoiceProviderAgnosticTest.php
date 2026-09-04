<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AzureVoice, DoorVoice, OpenAiVoice};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * THE SCREEN THAT TESTS THE VOICE WAS TESTING THE WRONG ONE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE OPERATOR SAW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * OpenAI selected. OpenAI key set and correct. And the door-voice card said
 *
 *     "Not configured — the door works exactly as it does now, in silence."
 *     "No Azure Speech key, so nobody is greeted by name."   (in red)
 *
 * with every Hear button DISABLED and a four-step Azure portal walkthrough above them.
 * Pressing the one enabled control answered "No voice is configured" and told them to add
 * an Azure key. Their configuration was correct the whole time.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \AfricaGates\Services\DoorWelcome::render()} — the thing that actually speaks —
 * dispatches on {@see DoorVoice::provider()} and was always right. Everything built to
 * REPORT on it asked `AzureVoice` directly: the controller's `azure_voice_on` and
 * `azure_voice_why`, five gates in the template, all four branches of the preview
 * endpoint, and the bulk-render failure message.
 *
 * Two readers of one setting, which CLAUDE.md names as how the halves of an integration
 * come to disagree about whether it is configured — here sitting inside the tool built to
 * find that out. The door would have spoken; nothing on the screen could say so.
 *
 * And it is the shape that survives: every symptom pointed at a missing key, which is the
 * first thing anybody checks and the one thing that was fine.
 */
final class DoorVoiceProviderAgnosticTest extends TestCase
{
    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
    }

    /** The operator's configuration, exactly: OpenAI chosen and keyed, no Azure key. */
    private function openAiOnly(): void
    {
        $this->settings([
            DoorVoice::SETTING => 'openai',
            'ai_openai_key'    => 'sk-test-intact',
            'azure_speech_key' => '',
        ]);
    }

    // ══ the state the screen reads ═══════════════════════════════════════════

    public function test_a_keyed_openai_door_reports_itself_as_able_to_speak(): void
    {
        $this->openAiOnly();

        $this->assertSame('openai', DoorVoice::provider());
        $this->assertTrue(OpenAiVoice::configured(), 'fixture did not take');
        $this->assertFalse(AzureVoice::configured(), 'fixture did not take');

        $this->assertTrue(DoorVoice::configured(),
            'the door reports itself silent while the provider it is set to is configured '
            . 'and would speak');
        $this->assertSame('', DoorVoice::why(),
            'a working door still carries a reason it cannot speak: ' . DoorVoice::why());
    }

    /**
     * AND THE AZURE ANSWER IS STILL AVAILABLE — IT WAS NEVER WRONG, ONLY MISUSED.
     *
     * The Azure key and region boxes need to know whether the Azure box is filled in.
     * Deleting that answer to fix this would break the one control it is correct for.
     */
    public function test_the_azure_specific_answer_is_kept_for_the_azure_specific_fields(): void
    {
        $this->openAiOnly();

        $this->assertFalse(AzureVoice::configured());
        $this->assertNotSame('', AzureVoice::why(),
            'the Azure box can no longer say it is empty, so the key field lost its hint');
    }

    /**
     * Selecting Azure moves every PER-PROVIDER answer with it.
     *
     * This used to assert that choosing Azure with no Azure key made the whole door
     * silent, even with OpenAI keyed. That was true of a single-provider door and is
     * wrong now: DoorVoice falls through, so the door speaks and reports so. The property
     * that survives — and the one this test was really for — is that the answer ABOUT A
     * PROVIDER follows the choice rather than being hardcoded, which `configuredFor()`
     * answers.
     */
    public function test_the_report_follows_whichever_provider_is_chosen(): void
    {
        $this->settings([
            DoorVoice::SETTING => 'azure', 'ai_openai_key' => 'sk-test-intact', 'azure_speech_key' => '',
        ]);

        $this->assertSame(DoorVoice::AZURE, DoorVoice::provider());
        $this->assertFalse(DoorVoice::configuredFor(DoorVoice::AZURE),
            'an empty Azure key is being read as configured');
        $this->assertTrue(DoorVoice::configuredFor(DoorVoice::OPENAI));

        // And the door as a whole still speaks, through the fallback.
        $this->assertTrue(DoorVoice::configured(),
            'the chain went silent because its FIRST choice was unconfigured');

        // With nothing keyed at all, the reason names the chosen provider.
        $this->settings(['ai_openai_key' => '', 'ai_elevenlabs_key' => '']);
        $this->assertFalse(DoorVoice::configured());
        $this->assertStringContainsStringIgnoringCase('azure', DoorVoice::why());
    }

    /** A recorded failure comes from the provider that was asked, not the other one. */
    public function test_the_last_failure_is_read_from_the_provider_that_tried(): void
    {
        $this->openAiOnly();
        $this->settings([
            'openai_voice_last_error' => 'OpenAI refused: 401 invalid_api_key',
            'azure_voice_last_error'  => 'Azure: 429 too many requests',
        ]);

        $this->assertStringContainsString('OpenAI refused', DoorVoice::lastError(),
            'a failure in OpenAI reports Azure\'s recorded reason — usually empty, so the '
            . 'screen falls through to advice about an Azure quota nothing touched');
    }

    // ══ and nothing reports on the door through one provider any more ════════

    /**
     * @return array<string,string> path => source with comments removed
     *
     * Comments stripped: every note left by this fix quotes the Azure call it replaced,
     * so a naive scan reports the repair as the fault. Six times in this repository.
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2) . '/';
        $out  = [];
        foreach (['src/Admin/Controllers/SettingsController.php',
                  'src/Admin/Controllers/EventsController.php'] as $rel) {
            $out[$rel] = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
                (string) file_get_contents($root . $rel));
        }
        $out['templates/admin/settings.twig'] = (string) preg_replace('~\{#.*?#\}~s', ' ',
            (string) file_get_contents($root . 'templates/admin/settings.twig'));
        return $out;
    }

    /**
     * THE PREVIEW ENDPOINT ASKS THE CHOSEN PROVIDER, IN ALL FOUR BRANCHES.
     *
     * It is the only place an operator can test the voice, and every branch of it named
     * Azure: the configured check, the wording-only fallback, the failure reason and the
     * success payload.
     */
    public function test_the_preview_endpoint_asks_whoever_is_speaking(): void
    {
        $src = self::sources()['src/Admin/Controllers/SettingsController.php'];
        $at  = (int) strpos($src, 'public function voicePreview');
        $end = (int) strpos($src, 'public function voiceSample');
        $body = substr($src, $at, $end - $at);

        $this->assertStringNotContainsString('AzureVoice::', $body,
            'the one screen that tests the door voice still asks Azure, so an OpenAI '
            . 'deployment is told to add an Azure key');

        foreach (['DoorVoice::configured()', 'DoorVoice::why()',
                  'DoorVoice::lastError()', 'DoorVoice::plain('] as $call) {
            $this->assertStringContainsString($call, $body, $body === '' ? 'empty' : $call);
        }
    }

    /**
     * AND THE CARD'S CONTROLS ARE GATED ON THE DOOR, NOT ON AZURE.
     *
     * `azure_voice_on` legitimately gates the Azure key box, the "remove the stored key"
     * checkbox and the Azure portal walkthrough. It must not gate anything that answers
     * "can this door speak" — the subtitle, the Hear buttons, or their disabled state,
     * all of which it did.
     */
    public function test_the_card_gates_the_speaking_controls_on_the_door(): void
    {
        $twig = self::sources()['templates/admin/settings.twig'];

        foreach (['{% if door_voice_on %}Hear it{% else %}Show it{% endif %}'
                      => 'the Hear button label',
                  '{% if not door_voice_on %}disabled{% endif %}'
                      => 'the per-name Hear buttons, which were disabled outright',
                  '{% if door_voice_why %}'
                      => 'the red reason line, which printed Azure\'s complaint'] as $needle => $what) {
            $this->assertStringContainsString($needle, $twig,
                $what . ' still reads the Azure answer');
        }

        // The walkthrough is Azure's and stays — but only where Azure is being asked.
        $this->assertStringContainsString(
            "{% if not azure_voice_on and door_voice_provider == 'azure' %}", $twig,
            'a four-step Azure portal walkthrough still renders on an OpenAI deployment, '
            . 'which reads as work the operator has not done');
    }

    /** The bulk render reports the failure of whoever was asked to speak. */
    public function test_the_bulk_render_failure_names_the_right_provider(): void
    {
        $src = self::sources()['src/Admin/Controllers/EventsController.php'];

        $this->assertStringNotContainsString('AzureVoice::lastError()', $src,
            'a failure in OpenAI still reports Azure\'s recorded reason');
        $this->assertStringNotContainsString('AzureVoice::perMinute()', $src,
            'an OpenAI failure still advises waiting for an Azure quota');
    }
}
