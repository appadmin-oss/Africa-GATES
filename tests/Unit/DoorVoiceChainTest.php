<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AzureVoice, DoorVoice, DoorWelcome, ElevenLabsVoice, OpenAiVoice};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * ONE PROVIDER REFUSING USED TO TAKE THE WHOLE EVENING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `DoorVoice` chose ONE provider. If it refused — an expired key, a spent quota, a 429
 * from the free tier — the greeting was gone and the door was silent for the rest of the
 * night, with the reason written to a settings row nobody was going to read before the
 * guests arrived. The `door_voice_provider` setting chose which single point of failure
 * you wanted.
 *
 * It is a chain now: the chosen provider first, then the rest, then — past the last
 * provider, in the page itself — the browser's own speech synthesis, which needs no key
 * and no network. "The door says nothing" stops being a state this system has.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PART THAT LOOKS LIKE A DETAIL AND IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A clip is filed under the signature of the provider that MADE it, not the one that was
 * chosen. Get that wrong and a fallback clip is labelled as the preferred provider's — so
 * the door goes on serving Azure audio for as long as those files live, silently, after
 * the OpenAI key is fixed, because the only question it asks is whether a file exists.
 * That is the bug this codebase already shipped once when the provider was left out of
 * the cache key entirely.
 */
final class DoorVoiceChainTest extends TestCase
{
    private function settings(array $kv): void
    {
        foreach ($kv as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
    }

    // ══ the chain ════════════════════════════════════════════════════════════

    public function test_the_chosen_provider_is_tried_first_and_the_rest_follow(): void
    {
        $this->settings([DoorVoice::SETTING => 'azure']);

        $this->assertSame(
            [DoorVoice::AZURE, DoorVoice::OPENAI, DoorVoice::ELEVENLABS],
            DoorVoice::order(),
            'the chosen provider is not first, or a provider has dropped out of the chain');

        $this->settings([DoorVoice::SETTING => 'elevenlabs']);
        $this->assertSame(DoorVoice::ELEVENLABS, DoorVoice::order()[0]);

        // No duplicates: the chosen one appears once, however the chain is written.
        $this->assertSame(DoorVoice::order(), array_unique(DoorVoice::order()));
    }

    /**
     * A DOOR WHOSE SECOND CHOICE WORKS IS NOT BROKEN.
     *
     * `configured()` used to ask only the chosen provider, so a deployment with OpenAI
     * selected and only an ElevenLabs key reported itself silent — and `DoorWelcome::ready()`
     * refuses to render on exactly that answer, so the report would have made itself true.
     */
    public function test_any_configured_link_makes_the_door_able_to_speak(): void
    {
        $this->settings([
            DoorVoice::SETTING => 'openai',
            'ai_openai_key' => '', 'azure_speech_key' => '',
            'ai_elevenlabs_key' => 'sk-eleven',
        ]);

        $this->assertFalse(OpenAiVoice::configured(), 'fixture did not take');
        $this->assertTrue(ElevenLabsVoice::configured(), 'fixture did not take');

        $this->assertTrue(DoorVoice::configured(),
            'the door reports itself silent while a provider in its chain is configured '
            . 'and would speak');
        $this->assertSame('', DoorVoice::why(),
            'a working door still carries a reason it cannot speak: ' . DoorVoice::why());
    }

    /** With nothing configured it names the CHOSEN provider — the box they meant to fill. */
    public function test_an_empty_chain_names_the_provider_the_operator_picked(): void
    {
        $this->settings([
            DoorVoice::SETTING => 'elevenlabs',
            'ai_openai_key' => '', 'azure_speech_key' => '', 'ai_elevenlabs_key' => '',
        ]);

        $this->assertFalse(DoorVoice::configured());
        $this->assertStringContainsStringIgnoringCase('elevenlabs', DoorVoice::why());
    }

    /**
     * SAY() WALKS THE CHAIN — ASSERTED FROM SOURCE, AND HERE IS WHY.
     *
     * Proving a fall-through BEHAVIOURALLY needs a provider that is configured and then
     * refuses, which means a real HTTP call to a real vendor with a deliberately bad key.
     * A unit suite that does that is slow, flaky, and reaches the network from CI.
     *
     * So the loop itself is pinned. This is the weakest assertion in this file and it is
     * the one that matters most: collapsing `say()` back to a single provider passed every
     * other test here, which is exactly the mutation that reintroduces the bug — a door
     * that goes silent for the evening because its first choice refused.
     */
    public function test_the_chain_is_actually_walked_rather_than_read_once(): void
    {
        // Comments stripped: the note above sayWith() describes the single call it
        // replaced, in the words it replaced.
        $src = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DoorVoice.php'));

        $at   = (int) strpos($src, 'function sayWith');
        $this->assertGreaterThan(0, $at, 'sayWith() has gone; the chain cannot fall through');
        $body = substr($src, $at, 900);

        $this->assertStringContainsString('foreach (self::order()', $body,
            'sayWith() no longer iterates the chain, so one provider refusing takes the '
            . 'whole evening with it');
        $this->assertStringContainsString("continue", $body,
            'an unconfigured provider is being ATTEMPTED rather than skipped — which '
            . 'overwrites the recorded reason of the one that genuinely refused');
        $this->assertStringContainsString('\'provider\' => $p', $body,
            'sayWith() does not report who spoke, so the clip cannot be filed correctly');

        // And say() is the thin wrapper, not a second implementation.
        $this->assertStringContainsString('return self::sayWith($text)[\'mp3\'];', $src,
            'say() has grown its own provider logic beside sayWith()');
    }

    // ══ the cache key ════════════════════════════════════════════════════════

    /**
     * EVERY PROVIDER GETS ITS OWN KEY, AND THEY ARE ALL DIFFERENT.
     *
     * The provider is in the signature so a clip cannot outlive the voice that made it.
     */
    public function test_each_provider_files_a_clip_under_its_own_key(): void
    {
        $this->settings([
            'ai_openai_key' => 'sk-x', 'ai_elevenlabs_key' => 'sk-e',
            'azure_speech_key' => 'az', 'azure_speech_region' => 'southafricanorth',
        ]);
        $line = 'Good evening. Ada, you are welcome.';

        $keys = [
            DoorWelcome::keyFor($line, DoorVoice::OPENAI),
            DoorWelcome::keyFor($line, DoorVoice::ELEVENLABS),
            DoorWelcome::keyFor($line, DoorVoice::AZURE),
        ];

        $this->assertSame($keys, array_unique($keys),
            'two providers share a cache key, so one will serve the other\'s audio');
        foreach ($keys as $k) $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $k);
    }

    /**
     * AND THE DOOR FINDS A CLIP WHICHEVER PROVIDER MADE IT.
     *
     * This is what makes a fallback clip usable. Looking it up under the chosen provider's
     * key alone would miss it, re-render it on every sweep, and burn a quota making a file
     * that is already on disk.
     */
    public function test_a_clip_made_by_a_fallback_is_found_and_played(): void
    {
        $this->settings([
            DoorVoice::SETTING => 'openai',
            'ai_openai_key' => 'sk-x', 'azure_speech_key' => 'az',
            'azure_speech_region' => 'southafricanorth', 'door_welcome_enabled' => '1',
        ]);
        $line = 'Good evening. Ada, you are welcome.';

        $dir = DoorWelcome::dir();
        $this->assertNotNull($dir, 'the cache directory is not writable in this harness');

        // A clip that AZURE made, while OPENAI is the chosen provider.
        $azureKey = DoorWelcome::keyFor($line, DoorVoice::AZURE);
        file_put_contents((string) DoorWelcome::pathFor($azureKey),
            "ID3\x03\x00\x00\x00" . str_repeat("\x00", 200));

        try {
            $this->assertNotSame($azureKey, DoorWelcome::keyFor($line, DoorVoice::OPENAI),
                'the fixture is not testing a cross-provider lookup');

            $this->assertTrue(DoorWelcome::have($line),
                'a clip made by a fallback provider is invisible, so the sweep remakes it '
                . 'every run and the door plays the generic welcome instead');
            $this->assertSame($azureKey, DoorWelcome::keyToPlay($line),
                'the door will not play a clip a fallback provider made');
        } finally {
            @unlink((string) DoorWelcome::pathFor($azureKey));
        }
    }

    /** The preferred provider's clip wins where both exist. */
    public function test_the_chosen_providers_clip_is_preferred_when_both_are_on_disk(): void
    {
        $this->settings([
            DoorVoice::SETTING => 'openai',
            'ai_openai_key' => 'sk-x', 'azure_speech_key' => 'az',
            'azure_speech_region' => 'southafricanorth', 'door_welcome_enabled' => '1',
        ]);
        $line = 'Good evening. Ada, you are welcome.';
        $mp3  = "ID3\x03\x00\x00\x00" . str_repeat("\x00", 200);

        $openai = DoorWelcome::keyFor($line, DoorVoice::OPENAI);
        $azure  = DoorWelcome::keyFor($line, DoorVoice::AZURE);
        file_put_contents((string) DoorWelcome::pathFor($openai), $mp3);
        file_put_contents((string) DoorWelcome::pathFor($azure),  $mp3);

        try {
            $this->assertSame($openai, DoorWelcome::keyToPlay($line),
                'the door plays the fallback clip while the chosen provider\'s is on disk');
        } finally {
            @unlink((string) DoorWelcome::pathFor($openai));
            @unlink((string) DoorWelcome::pathFor($azure));
        }
    }

    // ══ and the browser, past the end of the chain ═══════════════════════════

    /**
     * THE WORDS TRAVEL WITH THE KEY.
     *
     * A walk-up or a late booking has no clip by definition, and on a host with no shell
     * the sweep only runs if somebody wired up the cron. The browser can say the line with
     * no key and no request — but only if it is given the words.
     */
    public function test_the_scan_answer_carries_the_line_as_well_as_the_clip(): void
    {
        $src = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DoorController.php'));

        $this->assertSame(2, substr_count($src, "'say'"),
            'both admit paths must send the words: a guest of honour with no clip is the '
            . 'likeliest person in the room to be greeted by name');
        $this->assertStringContainsString('DoorVoice::plain(', $src,
            'the pause marker is being sent to a synthesiser that will read it aloud');
    }

    /**
     * AND THE PAGE USES THEM — BUT NEVER OVER A REAL CLIP.
     *
     * A rendered greeting is better in every way. The browser voice is for the case where
     * there is no clip at all, and it is deliberately NOT tried when playback is refused:
     * that refusal is the autoplay gate, speechSynthesis is behind the same gate, and a
     * second silence would bury the one-tap control that actually fixes it.
     */
    public function test_the_door_speaks_in_the_browser_only_when_there_is_no_clip(): void
    {
        $twig = (string) preg_replace('~\{#.*?#\}~s', ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig'));

        $this->assertStringContainsString('speechSynthesis', $twig,
            'the door has no voice of its own, so an unrendered name is silent');
        $this->assertStringContainsString('if (!key) { speakHere(say); return; }', $twig,
            'the browser voice is not reached on a missing clip');
        $this->assertStringContainsString('greet(v.welcome, v.say)', $twig,
            'the words are never passed to greet(), so speakHere() has nothing to say');

        // Not inside the play-refusal handler.
        $at  = (int) strpos($twig, 'pending = key;');
        $this->assertGreaterThan(0, $at);
        $this->assertStringNotContainsString('speakHere', substr($twig, $at - 400, 500),
            'the browser voice fires on an autoplay refusal, where it is behind the same '
            . 'gate and will fail identically — burying the tap that fixes it');
    }
}
