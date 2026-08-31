<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventScanPass;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The door's effects, and the two gates the check-in audit left open.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE EFFECTS ARE NEVER THE SIGNAL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The word and the mark carry the verdict; the sweep, the burst and the shake are added to
 * them. A steward who cannot tell two colours apart, whose phone is in sunlight, or who has
 * motion switched off must lose nothing that matters — which is why every effect here is
 * off under `prefers-reduced-motion` rather than merely slowed, and why none of them is the
 * only thing distinguishing an admit from a refusal.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THEY ARE PAINTED IN THE ORGANISER'S OWN COLOUR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A tier's colour is a slot resolved from the event's accent everywhere else on this
 * platform — the ticket, the flier, the selection light. The door hardcoded gold and green,
 * so a gold gala burst emerald at its own door.
 */
final class DoorEffectsAndGuardsTest extends TestCase
{
    private function door(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/events/door.twig');
    }

    // ══ 1 · one loop, cancellable ════════════════════════════════════════════

    /**
     * THE BUG THIS REPLACED. The burst started a fresh requestAnimationFrame on every call
     * with nothing stopping the last — two guests of honour scanned inside its 2.6 seconds
     * left two loops writing to one canvas, each clearing the other's frame. At a door,
     * effects overlap by definition.
     */
    public function test_a_second_effect_cancels_the_first(): void
    {
        $s = $this->door();

        // The guard AND the call as one unit. Asserting the call alone passes against
        // `if (false) cancelAnimationFrame(...)`, which is a live regression wearing the
        // right words — a structural test that only proves a string is present proves the
        // author typed it, not that it runs.
        $this->assertMatchesRegularExpression(
            '~if \(fx\.raf\)\s*cancelAnimationFrame\(fx\.raf\);~', $s,
            'nothing stops a running effect, so two scans in quick succession fight');
        $this->assertStringContainsString('fx.raf = requestAnimationFrame(frame)', $s,
            'the handle is not kept, so it cannot be cancelled');

        $from = (int) strpos($s, 'function celebrate()');
        $body = substr($s, $from, 400);
        $this->assertStringContainsString('fxStop()', $body,
            'celebrate() does not clear whatever was already running');
    }

    // ══ 2 · the organiser's colour ═══════════════════════════════════════════

    public function test_the_effects_take_the_events_own_accent(): void
    {
        $s = $this->door();

        $this->assertStringContainsString('var ACCENT = {{ accent', $s,
            'the burst is painted in a colour the organiser cannot change');
        $this->assertStringContainsString('[ACCENT,', $s, 'ACCENT does not lead the palette');
        $this->assertStringContainsString('accent_soft', $s, 'the sweep is not accent-driven');
    }

    /**
     * Resolved server-side rather than with color-mix(). A browser that does not know the
     * function treats the whole gradient as invalid and drops the background with it —
     * silently, on the oldest phone in the building.
     */
    public function test_the_sweep_needs_no_css_colour_function(): void
    {
        $this->assertStringNotContainsString('color-mix', $this->door());
    }

    // ══ 3 · the accessibility floor ══════════════════════════════════════════

    /** Off, not slowed. A full-width band and a shaking panel are what the setting is for. */
    public function test_every_effect_is_off_under_reduced_motion(): void
    {
        $s = $this->door();
        $at = strpos($s, '@media (prefers-reduced-motion:reduce){\n    .dr__v.is-sweep');
        $at = $at !== false ? $at : strpos($s, '.dr__v.is-sweep::after{ animation:none');

        $this->assertNotFalse($at, 'the sweep still animates under reduced motion');
        $this->assertStringContainsString('.dr__v.is-nudge{ animation:none }', $s);
        // And the JS half, because a hidden canvas still costs animation frames.
        $this->assertStringContainsString('if (!ctx || reduced()) return;', $s);
        $this->assertMatchesRegularExpression('~function sweep\(\)\s*\{\s*if \(reduced\(\)\) return;~', $s);
        $this->assertMatchesRegularExpression('~function nudge\(\)\s*\{\s*if \(reduced\(\)\) return;~', $s);
    }

    /** A verdict must never be distinguishable by its effect alone. */
    public function test_the_verdict_still_carries_a_word_and_a_mark(): void
    {
        $s = $this->door();

        $this->assertStringContainsString("var MARK = { admit: '✓'", $s);
        $this->assertStringContainsString("slow: '⏳'", $s, 'the throttle has no mark');
        $this->assertStringContainsString('title.textContent = v.title', $s,
            'the verdict word is not written');
    }

    /** A duplicate and a refusal shake; only an admit sweeps; only honour bursts. */
    public function test_each_verdict_gets_its_own_effect(): void
    {
        $s = $this->door();
        $from = (int) strpos($s, "if (v.honour && v.verdict === 'admit') celebrate();");
        $body = substr($s, $from, 420);

        $this->assertStringContainsString("else if (v.verdict === 'admit') sweep()", $body);
        $this->assertStringContainsString("'duplicate'", $body);
        $this->assertStringContainsString('nudge()', $body);
        $this->assertStringContainsString('else fxStop()', $body,
            'a verdict with no effect leaves the previous one on screen');
    }

    // ══ 4 · a cancelled event admits nobody ══════════════════════════════════

    /**
     * The pass window and the event's own state are different facts, and the door checked
     * only the first — so it went on admitting people to a gala an organiser had cancelled,
     * for as long as the pass lasted, with the cancellation notice already sent.
     */
    public function test_a_cancelled_event_closes_its_door(): void
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Called off', 'slug' => 'fx-cancelled', 'status' => 'cancelled',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        $token = EventScanPass::issue($id, Carbon::now()->addHours(4)->toDateTimeString());
        $this->assertNotNull($token);

        $r = EventScanPass::resolve((string) $token);

        $this->assertFalse($r['ok'], 'a cancelled gala is still admitting people');
        $this->assertSame('cancelled', $r['reason']);
        $this->assertStringContainsString('cancelled', $r['message']);
    }

    /** A draft event with a live pass is an organiser testing their door. That must work. */
    public function test_an_unpublished_event_still_lets_its_organiser_test_the_door(): void
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Draft', 'slug' => 'fx-draft', 'status' => 'draft',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        $token = EventScanPass::issue($id, Carbon::now()->addHours(4)->toDateTimeString());

        $this->assertTrue(EventScanPass::resolve((string) $token)['ok']);
    }

    // ══ 5 · the throttle ═════════════════════════════════════════════════════

    /**
     * A leaked pass was an unmetered name-lookup oracle: the endpoint returns an attendee's
     * NAME for any valid code, the link is meant to be shared into a group chat, and nothing
     * bounded attempts.
     *
     * Keyed on the PASS rather than the IP, because a hall with four gates behind one venue
     * router is four stewards sharing an address.
     */
    public function test_the_scan_endpoint_is_bounded_per_pass(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DoorController.php');

        $this->assertStringContainsString("tooFast(\$pass, 'door_scan')", $c);
        $this->assertStringContainsString("'pass:' . (int) \$pass->id", $c,
            'rate-limiting by IP would throttle the busiest gate for the other three');
    }

    /** Its own bucket, or a busy gate spends the budget it needs to fix a mistake. */
    public function test_taking_it_back_has_its_own_budget(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DoorController.php');

        $this->assertStringContainsString("tooFast(\$pass, 'door_undo')", $c);
    }

    /** A limiter that cannot read its own table must never close a working door. */
    public function test_a_broken_limiter_does_not_shut_the_door(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DoorController.php');
        $from = (int) strpos($c, 'private function tooFast');
        $body = substr($c, $from, 700);

        $this->assertStringContainsString('return false;', $body);
        $this->assertStringContainsString('catch', $body);
    }

    /** And it is wired, because a nullable dependency nobody injects is no limiter at all. */
    public function test_the_limiter_is_actually_injected(): void
    {
        $c = (string) file_get_contents(dirname(__DIR__, 2) . '/config/container.php');

        $this->assertStringContainsString('Controllers\\DoorController::class =>', $c,
            'the door is autowired, so its optional limiter is null and bounds nothing');
        $this->assertStringContainsString('RateLimitService::class', $c);
    }
}
