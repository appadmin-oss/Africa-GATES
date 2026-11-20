<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DoorWelcome;
use Tests\TestCase;

/**
 * "OPENAI ONLY SPEAKS WHEN YOU TEST IT, BUT NEVER IN CHECK-IN."
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * BOTH HALVES WERE TRUE, AND NOTHING CONNECTED THEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The settings preview SYNTHESISES on demand — press Hear it and a request goes to the
 * provider and audio comes back. It spoke perfectly, which is why every investigation
 * into the key, the provider and the API found nothing: there was nothing wrong with any
 * of them.
 *
 * The door does the opposite, on purpose. It asks only whether a clip is already on disk,
 * because a queue cannot wait on a round trip to a datacentre. Clips are made ahead by
 * {@see DoorWelcome::sweep()} — which runs from maintenance, which runs from cron, and
 * there is no shell on this host, so cron is a Cloudflare Worker somebody has to have set
 * up. Where that was never done the sweep has never run, no clip has ever existed, and
 * every scan fell through to silence.
 *
 * A voice that works and a door that is silent, with the working half being the only half
 * anybody could test.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE MOMENT NOTHING WAS USING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The door page is opened minutes before the gate is, by a steward with nobody in front
 * of them. That is the one point in the evening when a round trip costs nothing, and the
 * only one this platform was not using. `POST /door/{token}/prime` renders there.
 *
 * It is NOT the scan path and must never become it — asserted below, because that is the
 * change somebody makes later when a walk-up is not greeted.
 */
final class DoorPrimesItsOwnClipsTest extends TestCase
{
    private static function controller(): string
    {
        // Comments stripped: the note on prime() explains the scan path it must not
        // become, in the words of the rule it is obeying.
        return (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DoorController.php'));
    }

    // ══ the door can fill its own cache ══════════════════════════════════════

    public function test_a_per_event_render_exists_and_ignores_the_lead_window(): void
    {
        $this->assertTrue(method_exists(DoorWelcome::class, 'sweepEvent'),
            'the door can only render through the global sweep, which covers events '
            . 'inside the lead window — so a door opened four days early renders nothing '
            . 'and says nothing about why');

        $src = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DoorWelcome.php'));
        // Bounded at the method's END, not a fixed width: a window that runs past it
        // reaches soonEvents() and reports the sweep's own filter as this method's.
        $at   = (int) strpos($src, 'function sweepEvent');
        $next = (int) strpos($src, 'function eventOf', $at);
        $body = substr($src, $at, $next - $at);

        $this->assertStringNotContainsString('soonEvents', $body,
            'the per-event render is filtered by the lead window, so the door it was '
            . 'opened for can be excluded from its own priming');

        // The pieces that must stay shared with the sweep, or the two disagree about what
        // a line IS and every clip one makes is orphaned by the other.
        $this->assertStringContainsString('NameSays::learn', $body,
            'the pronunciation is not learned before the queue is built, so every clip is '
            . 'rendered with the old reading and then orphaned when it is');
        $this->assertStringContainsString('genericLine()', $body,
            'the generic clip is not made first, so a part-rendered door has nothing to '
            . 'fall back on for a walk-up');
        $this->assertStringContainsString('DoorVoice::throttled()', $body,
            'a spent quota no longer stops the run, so it walks the whole guest list '
            . 'collecting refusals');
        $this->assertStringContainsString('self::have($line)', $body,
            'clips already on disk are being remade, which spends a quota on nothing');
    }

    /** Nothing renders when the door is not set up — it says the blocker instead. */
    public function test_it_renders_nothing_when_the_door_is_not_ready(): void
    {
        // door_welcome_enabled unset in this harness, so ready() is false.
        $this->assertSame(0, DoorWelcome::sweepEvent(1),
            'a door that is switched off still attempted to render, spending a quota on '
            . 'clips nothing will ever play');
    }

    // ══ and it is not the scan path ══════════════════════════════════════════

    /**
     * THE RULE THIS FIX IS ONE EDIT AWAY FROM BREAKING.
     *
     * Nothing may synthesise while somebody is standing at the door. `check()` asks only
     * whether a file exists, and the next person to notice that a walk-up is not greeted
     * will be tempted to render right there — which puts a datacentre round trip in front
     * of a queue on venue wifi.
     */
    public function test_the_scan_path_still_never_renders(): void
    {
        $src = self::controller();
        $at  = (int) strpos($src, 'public function check(');
        $this->assertGreaterThan(0, $at);
        $end = (int) strpos($src, 'public function undo(', $at);
        $scan = substr($src, $at, ($end > $at ? $end - $at : 4000));

        foreach (['DoorWelcome::render', 'sweepEvent', 'DoorVoice::say', 'sayWith'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $scan,
                'the scan path synthesises: a queue is now waiting on a round trip to a '
                . 'datacentre over venue wifi (' . $forbidden . ')');
        }

        // What it may do, and all it may do.
        $this->assertStringContainsString('keyToPlay', $scan,
            'the scan no longer looks up a clip at all');
    }

    /** Priming is behind the door token and rate-limited like every other door write. */
    public function test_priming_is_gated_and_bounded(): void
    {
        $src = self::controller();
        $at  = (int) strpos($src, 'public function prime(');
        $this->assertGreaterThan(0, $at, 'the prime endpoint has gone');
        $body = substr($src, $at, 2200);

        $this->assertStringContainsString('EventScanPass::resolve', $body,
            'anybody can make this platform spend a synthesis quota');
        $this->assertStringContainsString("tooFast(\$pass, 'door_prime')", $body,
            'a page that re-primes in a loop can spend the whole quota');
        $this->assertStringContainsString('403', $body);
    }

    /** The page primes on open, and does not wait on it. */
    public function test_the_door_page_primes_itself_without_blocking(): void
    {
        $twig = (string) preg_replace('~\{#.*?#\}~s', ' ',
            (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig'));

        $this->assertStringContainsString("'/door/' + TOKEN + '/prime'", $twig,
            'the door never asks for its own greetings, so a deployment with no cron is '
            . 'silent forever and nothing on the page says why');
        $this->assertStringContainsString('.catch(function () {', $twig,
            'a failed prime is not swallowed — a door that cannot render must remain '
            . 'exactly as usable as it was');
        $this->assertStringContainsString('if (!welcomeOn) return;', $twig,
            'a door with the greeting switched off still calls the renderer');
    }
}
