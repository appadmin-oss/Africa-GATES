<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\DoorController;
use AfricaGates\Services\{DoorWelcome, EventScanPass, RateLimitService};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\{ResponseFactory, ServerRequestFactory};
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * THE GREETING HAD NEVER PLAYED, ON ANY DEVICE, SINCE THE VOICE SHIPPED.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY EVERYTHING ELSE WAS GREEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The server half is covered end to end and always was: EventLifecycleTest walks a guest
 * through the door and asserts the check returns the key of the clip the sweep rendered,
 * rather than the fallback. That test is right, and it passed the whole time.
 *
 * Everything it asserts happens BEFORE the only step that matters to a person standing at
 * a gate. The page receives a key, points a player at it, and asks the browser to play —
 * and the browser said no, every time, for two separate reasons, neither of which produces
 * an error the page can see:
 *
 *   1. `Permissions-Policy: autoplay=()` denied the feature to our own documents, site
 *      wide. Held by SecurityHeadersTest now, beside the `camera=()` that had the ticket
 *      scanner dead on the same header.
 *   2. The player was built LAZILY, inside greet(), which runs from the scanner's decode
 *      loop. So the only <audio> element that ever existed was created outside a user
 *      gesture and had never been played inside one — which is the condition both mobile
 *      browsers gate audible playback on. The element's own comment described banking that
 *      gesture; the code never gave it one to bank.
 *
 * Both refusals arrive as a rejected promise, and the page swallowed it on purpose: a door
 * with no sound is a working door, and nothing about a greeting may hold up a queue. That
 * is still the right thing to do with the rejection. It is also exactly why this survived —
 * no error, no console line, no log, nothing to grep. A hall of people, never greeted.
 *
 * So this file holds the PAGE half: that the player exists before the loop needs it, that
 * the steward's first touch of anything unlocks it, and that a refusal after all that is
 * shown to the one person who can fix it rather than dropped on the floor.
 */
final class DoorGreetingPlaysTest extends TestCase
{
    private int $eventId = 0;
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearClips();
    }

    protected function tearDown(): void
    {
        $this->clearClips();
        parent::tearDown();
    }

    // ══ the page the steward is actually handed ══════════════════════════════

    /**
     * The page is given a real clip to unlock its player with, and it is one of ours.
     *
     * The usual way to unlock a media element is a silent `data:` URI, and it would be
     * blocked here with nothing to say so: `media-src` is `'self'` plus two video hosts,
     * with no `data:`. So the primer has to be a file on our own origin, and the generic
     * greeting is the only clip guaranteed to exist whenever the voice is on at all.
     */
    public function test_the_page_carries_a_same_origin_clip_to_unlock_the_player_with(): void
    {
        $this->stage();
        $this->voiceOn();
        $this->plant(DoorWelcome::genericLine());

        $html = $this->page();

        $key = DoorWelcome::keyFor(DoorWelcome::genericLine());
        $this->assertStringContainsString('var primeKey  = "' . $key . '"', $html,
            'the door has nothing to unlock its player with, so the first guest is met in silence');
        $this->assertStringNotContainsString('data:audio', $html,
            'a data: URI primer is refused by media-src and the page cannot tell');
    }

    /** With no clip on disk there is nothing to unlock with, and the page says so honestly. */
    public function test_with_no_clip_rendered_the_page_claims_no_primer(): void
    {
        $this->stage();
        $this->voiceOn();

        $this->assertStringContainsString('var primeKey  = ""', $this->page(),
            'the page named a clip that is not on disk — every unlock would 404');
    }

    /** Voice off is a valid answer, and then the player is never built at all. */
    public function test_with_the_voice_off_no_player_is_created(): void
    {
        $this->stage();

        $html = $this->page();
        $this->assertStringContainsString('var welcomeOn = false', $html);
        $this->assertStringContainsString('var primeKey  = ""', $html);
    }

    // ══ the two faults, pinned in the source ═════════════════════════════════

    /**
     * THE DEFECT ITSELF: the player must not be created inside greet().
     *
     * greet() is called from the decode loop — a timer, never a gesture. An element first
     * constructed there has never been played inside a user gesture and never will be, so
     * every browser that gates audible playback refuses it forever. This is a source
     * assertion because it cannot be observed any other way: the behaviour it protects
     * belongs to a phone, and every headless browser available here plays regardless.
     */
    public function test_the_player_is_not_built_inside_the_scan_callback(): void
    {
        $door = $this->doorJs();

        $from = strpos($door, 'function greet(key)');
        $this->assertIsInt($from, 'greet() moved; this test must follow it');

        // To the end of that function — the next declaration at the same indentation.
        $to = strpos($door, "\n  }", $from);
        $this->assertIsInt($to);

        $body = substr($door, $from, $to - $from);
        $this->assertStringNotContainsString('new Audio(', $body,
            'the player is built inside the scan callback, so it is never unlocked and '
            . 'every greeting is refused');
        $this->assertStringContainsString('new Audio(', substr($door, 0, $from),
            'nothing builds the player before the loop needs it');
    }

    /**
     * The steward's first touch of ANYTHING banks the gesture the player needs.
     *
     * Not a tap the door asks for: a door is a queue and this page's whole design is that
     * nothing about a greeting costs it a tap. Whatever they touch first — the reticle, the
     * torch, the arrivals sheet — unlocks the player, muted, and every guest after that is
     * greeted without anybody having done anything.
     */
    public function test_the_first_gesture_unlocks_the_player(): void
    {
        $door = $this->doorJs();

        $this->assertMatchesRegularExpression(
            '~addEventListener\(\s*.pointerdown.,\s*unlock~', $door,
            'no gesture is banked, so the player is never unlocked on a phone');
        $this->assertMatchesRegularExpression(
            '~addEventListener\(\s*.keydown.,\s*unlock~', $door,
            'a keyboard-driven gate banks nothing');

        $from = strpos($door, 'function unlock()');
        $this->assertIsInt($from, 'unlock() moved; this test must follow it');
        $body = substr($door, $from, 600);

        $this->assertStringContainsString('audio.muted = true', $body,
            'the unlock is audible, so tapping the torch answers "You are welcome"');
        $this->assertStringContainsString('.play()', $body,
            'the unlock never plays, which is the only thing that banks the gesture');

        // And its cleanup must not stop a greeting that started while it was still running.
        // The steward taps and scans in the same second — that is the ordinary case at a
        // gate, and the guest it would silence is the first one of the evening.
        $this->assertStringContainsString('if (audio.src === mine) audio.pause()', $body,
            'the primer pauses the player unconditionally, so a greeting that lands during '
            . 'the unlock is cut off a beat after it starts');
    }

    /**
     * A refusal that survives all of that is SHOWN, not swallowed.
     *
     * This is the half that makes the fault self-correcting at the gate. Dropped silently,
     * a refused greeting is indistinguishable from a muted phone, a missing clip, or the
     * voice being switched off — the steward has nothing to tell apart and nothing to try.
     * One tap fixes it for the rest of the evening, so the door offers that tap.
     */
    public function test_a_refused_greeting_offers_the_steward_the_tap_that_fixes_it(): void
    {
        $door = $this->doorJs();

        $from = strpos($door, 'function greet(key)');
        $this->assertIsInt($from);
        $body = substr($door, $from, strpos($door, "\n  }", $from) - $from);

        $this->assertStringContainsString('el.sound.hidden = false', $body,
            'a refused greeting is dropped on the floor; nothing on the screen changes and '
            . 'the steward has no way to know sound is off');

        // And the control must do the one thing that works: play INSIDE the gesture.
        $tap = strpos($door, "el.sound.addEventListener('click'");
        $this->assertIsInt($tap, 'the control exists but nothing is wired to it');
        $this->assertStringContainsString('greet(key)', substr($door, $tap, 400),
            'the tap hides the button without playing anything, so it looks like it failed');

        // It starts hidden: a control that is always there is one more thing on a screen
        // glanced at with a queue in front of it, and for most doors there is nothing wrong.
        $this->assertMatchesRegularExpression('~id="drSound"[^>]*\shidden~s',
            $this->doorSource(), 'the sound control is on screen when nothing is wrong');
    }

    // ══ why it is silent, said on the door ═══════════════════════════════════

    /**
     * A SILENT DOOR MUST SAY WHY, WHERE SOMEBODY CAN READ IT.
     *
     * Switched off, no clips made yet, and a browser refusing playback all produce exactly
     * the same thing at a gate: nobody is greeted. So all three reach a person as "the
     * voice is not working" — the one report that cannot be acted on, and the report this
     * platform has now received three times in a row.
     *
     * `DoorWelcome::readiness()` has been able to answer this since it was written, and
     * only the admin screen ever asked.
     */
    public function test_the_door_says_why_it_cannot_speak(): void
    {
        $this->stage();
        // Switched ON, but nothing configured to speak with — the commonest real state,
        // and the one that looks identical to a bug from the outside.
        DB::table('gates_settings')->insert([
            ['key_name' => 'door_welcome_enabled', 'value' => '1'],
        ]);

        $html = (string) preg_replace('~\s+~', ' ', $this->page());

        $this->assertStringContainsString('No speech voice is configured', $html,
            'the door is silent and says nothing about why');
    }

    /** With everything ready the line is present but hidden — no noise at a working gate. */
    public function test_a_working_door_carries_the_notice_hidden(): void
    {
        $this->stage();
        $this->voiceOn();
        $this->plant(DoorWelcome::genericLine());

        $html = (string) preg_replace('~\s+~', ' ', $this->page());

        $this->assertMatchesRegularExpression('~<p class="dr__quiet" id="drQuiet" hidden>~', $html,
            'a working door is showing an operational notice to a steward mid-queue');
    }

    /**
     * A door that is not meant to speak says NOTHING — the element is not drawn at all.
     *
     * A silent door is a working door, and an explanation of a feature nobody switched on
     * is clutter on the one screen a steward glances at with a queue in front of them.
     */
    public function test_with_the_voice_off_there_is_no_notice_at_all(): void
    {
        $this->stage();

        // The ELEMENT, not the id: `$('drQuiet')` is in the script's element map on every
        // render, so scanning for the bare id passes on markup that is not there and fails
        // on markup that is.
        $this->assertStringNotContainsString('<p class="dr__quiet"', $this->page(),
            'a door with the voice switched off is explaining itself anyway');
    }

    /**
     * AND THE ONE THE SERVER CANNOT SEE.
     *
     * `Permissions-Policy` is decided before a line of the page runs, and has twice switched
     * a shipped feature off here with nothing on the page to say so — the camera once, and
     * this voice. The server cannot see the header the browser actually received. The
     * browser can, and answers in one call, so the page asks it.
     */
    public function test_the_page_asks_the_browser_whether_sound_is_allowed(): void
    {
        $door = $this->doorJs();

        $this->assertStringContainsString("allowsFeature('autoplay')", $door,
            'the page never asks the browser whether it may make a sound, so a header '
            . 'switching the voice off is indistinguishable from no clips being made');
        $this->assertStringContainsString('blocking sound on this site', $door,
            'the browser refusing is not reported in words anybody could act on');
    }

    // ══ fixtures ═════════════════════════════════════════════════════════════

    private function stage(): void
    {
        if ($this->eventId > 0) return;

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'door-voice-gala', 'title' => 'The Gala',
            'event_date' => Carbon::now()->addDay()->format('Y-m-d') . ' 18:00:00',
            'status' => 'published', 'timezone' => 'Africa/Lagos', 'capacity' => 200,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->token = (string) EventScanPass::issue(
            $this->eventId, Carbon::now()->addHours(12)->toDateTimeString(), null, 'Main gate');
    }

    private function page(): string
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates', ['cache' => false]);
        // The one function this page calls, bound exactly as the container binds it. The
        // rest of the container is not worth standing up for a page that asks for nothing
        // else — but this has to be the SAME resolver, or the test renders a door that
        // does not exist.
        $twig->getEnvironment()->addFunction(new \Twig\TwigFunction(
            'asset', [\AfricaGates\Support\Assets::class, 'url']));

        $ctrl = new DoorController($twig, new RateLimitService());
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/door/' . $this->token);
        $res = $ctrl->page($req, (new ResponseFactory())->createResponse(), ['token' => $this->token]);

        return (string) $res->getBody();
    }

    private function voiceOn(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'door_welcome_enabled', 'value' => '1'],
            ['key_name' => 'azure_speech_key', 'value' => 'test-key-not-real'],
        ]);
    }

    private function plant(string $line): void
    {
        $p = DoorWelcome::pathFor(DoorWelcome::keyFor($line));
        if ($p !== null) file_put_contents($p, 'ID3' . str_repeat("\x00", 128));
    }

    private function clearClips(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
    }

    private function doorSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/events/door.twig');
    }

    /**
     * The door's script with every comment blanked.
     *
     * Not tidiness: the fixed player now carries a comment naming `new Audio(` and
     * `autoplay=()` while explaining this bug, and a scanner that reads comments reports
     * the fix as the fault. Four scanners in this repository have needed exactly this.
     */
    private function doorJs(): string
    {
        $src = $this->doorSource();
        $src = (string) preg_replace('~\{#.*?#\}~s', ' ', $src);
        $src = (string) preg_replace('~/\*.*?\*/~s', ' ', $src);

        return (string) preg_replace('~(?<!:)//[^\n]*~', ' ', $src);
    }
}
