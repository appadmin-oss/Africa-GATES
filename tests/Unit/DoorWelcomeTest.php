<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{AzureVoice, DoorWelcome};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The Nigerian voice at the door — and the rule that keeps it free and instant.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * NOTHING IS SYNTHESISED WHILE SOMEBODY IS STANDING THERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * That is the whole design and it is the thing these tests exist to hold. A door is a
 * queue; putting an HTTPS round trip to a datacentre between the scan and the verdict costs
 * several hundred milliseconds on venue wifi, on a phone with one bar, in front of forty
 * people — and it would fail hardest exactly when the queue is longest.
 *
 * So every clip is rendered hours early by a maintenance sweep and the door does a filename
 * lookup. {@see DoorWelcome::keyToPlay()} must never reach the network, and there is a test
 * below that fails if it ever starts to.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY AZURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The two engines already here have no Nigerian English voice. An American voice reading
 * "Chidinma Okonkwo" at a Lagos gala is the platform sounding like it was built somewhere
 * else at the moment it is welcoming somebody by name. Azure publishes `en-NG-EzinneNeural`
 * and `en-NG-AbeoNeural`, on a free tier of 0.5M characters a month — about twenty thousand
 * welcomes, which is why this can be a standing feature rather than a budget line.
 */
final class DoorWelcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    private function on(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'door_welcome_enabled', 'value' => '1'],
            ['key_name' => 'azure_speech_key', 'value' => 'test-key-not-real'],
        ]);
    }

    /** A clip already on disk, without going anywhere near the network. */
    private function plant(string $line): string
    {
        $key  = DoorWelcome::keyFor($line);
        $path = DoorWelcome::pathFor($key);
        $this->assertNotNull($path);
        file_put_contents($path, "ID3" . str_repeat("\x00", 128));

        return $key;
    }

    // ══ what it says ═════════════════════════════════════════════════════════

    /**
     * "You are welcome", not "welcome" — that is how the greeting is actually said here,
     * and this is a Nigerian evening.
     */
    public function test_the_greeting_is_the_nigerian_one(): void
    {
        $this->assertSame('Ada, you are welcome.', DoorWelcome::line('Ada Obi'));
    }

    /** First name only: warmer, and it halves the chance of mangling a surname. */
    public function test_only_the_first_name_is_spoken(): void
    {
        $this->assertSame('Chidinma, you are welcome.', DoorWelcome::line('chidinma okonkwo'));
        $this->assertSame('Ngozi', DoorWelcome::firstName('  NGOZI   ADAEZE  '));
    }

    /** A guest of honour is met differently, because their arrival is different. */
    public function test_a_guest_of_honour_is_greeted_as_one(): void
    {
        $l = DoorWelcome::honourLine('Tunde Cole', 'nominee');

        $this->assertStringContainsString('Tunde, you are welcome', $l);
        $this->assertStringContainsString('nominee', $l);
    }

    /**
     * A booking form takes free text. Sending "N/A" or an address to a voice engine spends
     * characters and produces something nobody wants to hear at a door.
     */
    public function test_things_that_are_not_names_are_not_spoken(): void
    {
        foreach (['', '   ', 'ada@example.com', 'X', '12345', 'Guest#4'] as $junk) {
            $this->assertSame('', DoorWelcome::line($junk), 'spoke: ' . $junk);
        }
    }

    // ══ the rule ═════════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. The door's lookup never synthesises.
     *
     * Asserted structurally as well as behaviourally: a future edit that "helpfully" renders
     * a missing clip on demand would pass every other test in this file while putting a
     * datacentre round trip into a queue.
     */
    public function test_the_door_lookup_never_reaches_the_network(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DoorWelcome.php');
        $from = (int) strpos($src, 'function keyToPlay');
        $body = substr($src, $from, (int) strpos($src, 'function genericLine') - $from);

        $this->assertStringNotContainsString('render(', $body,
            'keyToPlay() renders on demand — that is a synthesis call inside a queue');
        $this->assertStringNotContainsString('AzureVoice::say', $body);
    }

    /** With nothing rendered there is simply no sound. A silent door is a working door. */
    public function test_a_missing_clip_is_silence_and_not_an_error(): void
    {
        $this->on();

        $this->assertSame('', DoorWelcome::keyToPlay('Ada, you are welcome.'));
    }

    /** The generic clip covers every walk-up and every unusable name. */
    public function test_somebody_with_no_clip_still_gets_the_voice(): void
    {
        $this->on();
        $this->plant(DoorWelcome::genericLine());

        $key = DoorWelcome::keyToPlay('Somebody Nobody Rendered, you are welcome.');

        $this->assertSame(DoorWelcome::keyFor(DoorWelcome::genericLine()), $key,
            'a walk-up got silence when the fallback was sitting on disk');
    }

    public function test_a_rendered_name_is_preferred_to_the_fallback(): void
    {
        $this->on();
        $this->plant(DoorWelcome::genericLine());
        $mine = $this->plant('Ada, you are welcome.');

        $this->assertSame($mine, DoorWelcome::keyToPlay('Ada, you are welcome.'));
    }

    /** Off means off — not a fallback, not a generic clip, nothing. */
    public function test_nothing_plays_when_the_feature_is_off(): void
    {
        $this->plant(DoorWelcome::genericLine());

        $this->assertSame('', DoorWelcome::keyToPlay('Ada, you are welcome.'));
    }

    // ══ the cache ════════════════════════════════════════════════════════════

    /** Changing the voice must not serve half an evening in the old one. */
    public function test_the_key_is_scoped_to_the_voice(): void
    {
        $before = DoorWelcome::keyFor('Ada, you are welcome.');
        DB::table('gates_settings')->insert(['key_name' => 'azure_speech_voice', 'value' => 'en-NG-AbeoNeural']);

        $this->assertNotSame($before, DoorWelcome::keyFor('Ada, you are welcome.'));
    }

    /** The key comes off a URL, so it cannot be allowed to walk out of the cache directory. */
    public function test_a_crafted_key_cannot_escape_the_cache(): void
    {
        foreach (['../../../etc/passwd', 'nope', '', str_repeat('g', 40), '/etc/passwd'] as $bad) {
            $this->assertNull(DoorWelcome::pathFor($bad), 'accepted: ' . $bad);
        }
        $this->assertNotNull(DoorWelcome::pathFor(str_repeat('a', 40)));
    }

    // ══ the engine ═══════════════════════════════════════════════════════════

    /** A voice name typed into a settings box is a 400 from Azure on an unwatched sweep. */
    public function test_an_unknown_voice_falls_back_rather_than_being_sent(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'azure_speech_voice', 'value' => 'en-US-JennyNeural']);

        $this->assertSame(AzureVoice::DEFAULT_VOICE, AzureVoice::voice());
    }

    /** Both voices Azure actually publishes for Nigerian English, and only those. */
    public function test_the_offered_voices_are_the_nigerian_ones(): void
    {
        $this->assertArrayHasKey('en-NG-EzinneNeural', AzureVoice::VOICES);
        $this->assertArrayHasKey('en-NG-AbeoNeural', AzureVoice::VOICES);
        $this->assertCount(2, AzureVoice::VOICES);
        foreach (array_keys(AzureVoice::VOICES) as $v) {
            $this->assertStringStartsWith('en-NG-', $v);
        }
    }

    /** Control characters are a 400 on a sweep nobody is watching, and it is billed per char. */
    public function test_what_is_sent_is_bounded_and_clean(): void
    {
        $this->assertSame('Ada Obi', AzureVoice::tidy("  Ada\x00  Obi \n"));
        $this->assertSame(240, mb_strlen(AzureVoice::tidy(str_repeat('a', 900))));
    }

    /** With no key nothing is attempted at all — the sweep is a no-op, never an error. */
    public function test_nothing_is_rendered_without_a_key(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'door_welcome_enabled', 'value' => '1']);

        $this->assertFalse(AzureVoice::configured());
        $this->assertFalse(DoorWelcome::ready());
        $this->assertSame(0, DoorWelcome::sweep());
    }

    // ══ the routes in ════════════════════════════════════════════════════════

    /** §18: a sweep nothing calls is a feature that never runs, on a host with no shell. */
    public function test_the_sweep_is_scheduled_and_addressable(): void
    {
        $m = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('DoorWelcome::sweep()', $m);
        $this->assertStringContainsString("'welcome'   =>", $m,
            'an organiser who imports a guest list at 4pm cannot wait for tomorrow at 06:00');
    }

    /** And the key is a credential: write-only, never rendered back into the page. */
    public function test_the_azure_key_is_never_echoed_to_the_page(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/SettingsController.php');
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        $this->assertStringContainsString("'azure_speech_key' => 'azure_key'", $c,
            'the key is not on the write-only path');
        $this->assertStringNotContainsString('values.azure_speech_key', $t,
            'a credential in the page source of every settings render');
    }
}
