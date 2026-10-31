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

    private function css(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/door.css');
    }

    // ══ 1 · one acknowledgement, restartable ═════════════════════════════════

    /**
     * THE BUG THIS REPLACED, IN ITS SECOND FORM.
     *
     * The old burst started a fresh requestAnimationFrame per call with nothing stopping
     * the last, so two guests of honour inside 2.6 seconds left two loops writing to one
     * canvas. The canvas is gone — the redesign acknowledges a scan by flaring the frame's
     * own light, which cannot fight itself — but the same hazard moved rather than
     * disappearing: assigning an animation a second time does NOT replay it, because the
     * browser coalesces the two assignments and sees no change.
     *
     * So the reflow between them is the whole mechanism. Without `void offsetWidth` the
     * second guest of honour gets no acknowledgement at all, which is a silent failure at
     * exactly the moment the door is busiest. Asserting the two assignments alone would
     * pass against the broken version.
     */
    public function test_a_second_scan_replays_the_acknowledgement(): void
    {
        $s = $this->door();

        $this->assertMatchesRegularExpression(
            '~node\.style\.animation = \'none\';\s*\n\s*void node\.offsetWidth;~', $s,
            'the animation is reassigned with no reflow between, so it never replays and a '
            . 'second scan in quick succession is acknowledged with nothing');

        $this->assertMatchesRegularExpression('~function flare\(\)\s*\{\s*restart\(aura,~', $s,
            'the flare does not go through the restart helper');
        $this->assertStringContainsString('restart(slab,', $s, 'the slab entry is not replayed');
        $this->assertStringContainsString('restart(el.rule,', $s, 'the rule does not redraw');
    }

    // ══ 2 · the organiser's colour ═══════════════════════════════════════════

    /**
     * The light is the organiser's, and it is lifted for a dark frame rather than reused.
     *
     * Everywhere else on this platform an event's colour is resolved for PAPER. The door
     * hardcoded gold and green once, so a gold gala burst emerald at its own door; using
     * the ticket accent raw would be the opposite failure, since
     * `EventTicketDesign::DEFAULT_ACCENT` is a near-black teal that cannot be seen here.
     */
    public function test_the_effects_take_the_events_own_accent(): void
    {
        $s = $this->door();

        $this->assertStringContainsString('DoorTone', (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Controllers/DoorController.php'),
            'the door paints itself in a colour the organiser cannot change');

        foreach (['--dr-accent:{{ tone.accent }}',
                  '--dr-accent-text:{{ tone.accent_text }}',
                  '--dr-accent-soft:{{ tone.soft }}'] as $decl) {
            $this->assertStringContainsString($decl, $s, 'the door does not take ' . $decl);
        }

        $this->assertStringContainsString('var(--dr-accent-soft)', $this->css(),
            'the sweep is not accent-driven');
    }

    /**
     * Resolved server-side rather than with color-mix(). A browser that does not know the
     * function treats the whole gradient as invalid and drops the background with it —
     * silently, on the oldest phone in the building.
     */
    public function test_the_sweep_needs_no_css_colour_function(): void
    {
        // Comments are stripped first, and that is not tidying: the note explaining WHY
        // this rule exists names `color-mix()`, so scanning raw source made the
        // documentation fail the assertion its own code satisfies. A test that reads
        // prose as markup breaks on the next person who explains themselves.
        $strip = static fn (string $s): string => (string) preg_replace(
            ['~\{#.*?#\}~s', '~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $s);

        $this->assertStringNotContainsString('color-mix', $strip($this->door()));
        $this->assertStringNotContainsString('color-mix', $strip($this->css()));
    }

    // ══ 3 · the accessibility floor ══════════════════════════════════════════

    /**
     * Off, not slowed — and ALL of it.
     *
     * The frame's two drifting fields, the breathing, the flare, the reticle's sweep and
     * every entry animation. The setting exists for exactly this kind of screen, and a
     * door that keeps one of five moving parts has not honoured it.
     */
    public function test_every_effect_is_off_under_reduced_motion(): void
    {
        $css = $this->css();

        $at = strpos($css, '@media (prefers-reduced-motion:reduce)');
        $this->assertNotFalse($at, 'the door has no reduced-motion block at all');

        $block = substr($css, $at);
        $this->assertStringContainsString('animation:none !important', $block,
            'reduced motion does not stop the animations');

        // The pseudo-elements carry the two drifting fields, so a rule that names only
        // elements leaves the light moving — which is the largest motion on the screen.
        $this->assertStringContainsString('.dr__aura::before', $block,
            'the drifting light keeps moving under reduced motion');
        $this->assertStringContainsString('.dr__aura::after', $block);
    }

    /**
     * A verdict must never be distinguishable by its effect, or its colour, alone.
     *
     * Every state carries its own WORD and its own MARK. `ask` is the one exception and it
     * is the design's: it has no glyph, because it is a question rather than a verdict, and
     * its word names the size of the party instead.
     */
    public function test_the_verdict_still_carries_a_word_and_a_mark(): void
    {
        $s = $this->door();

        $at = strpos($s, 'var SAY = {');
        $this->assertNotFalse($at, 'the verdict vocabulary is not in one table');
        $table = substr($s, $at, (int) strpos($s, '};', $at) - $at);

        $words = [];
        $marks = [];
        foreach (['admit', 'honour', 'ask', 'duplicate', 'refuse', 'held', 'undone', 'slow'] as $kind) {
            $this->assertMatchesRegularExpression(
                '~\b' . $kind . ':\s*\{\s*mark: \'([^\']*)\',\s*kicker: \'([^\']+)\',\s*word: \'([^\']*)\'~',
                $table, $kind . ' has no entry with a mark, a kicker and a word');

            preg_match('~\b' . $kind . ':\s*\{\s*mark: \'([^\']*)\',\s*kicker: \'([^\']+)\',\s*word: \'([^\']*)\'~',
                       $table, $m);
            if ($m[1] !== '') $marks[] = $m[1];
            if ($m[3] !== '') $words[] = $m[3];
        }

        // `admit` and `honour` deliberately share the word "Admit" — the steward's action
        // is identical and the star and the gold say the rest. Everything else is its own.
        $this->assertSame(count($marks), count(array_unique($marks)),
            'two states share a mark glyph, so the mark stops distinguishing them');

        $this->assertStringContainsString('el.word.textContent', $s, 'the verdict word is not written');
        $this->assertStringContainsString('el.mark.textContent', $s, 'the mark is not written');
    }

    /**
     * The three that say STOP AND LOOK shake; everything else rises.
     *
     * A shake is an interruption and it is spent on the states where the steward has to do
     * something about it. Spending it on an admit would make the ordinary case feel like a
     * fault.
     */
    public function test_each_verdict_gets_its_own_effect(): void
    {
        $s = $this->door();

        $this->assertMatchesRegularExpression(
            "~kind === 'refuse' \|\| kind === 'duplicate' \|\| kind === 'held' \|\| kind === 'slow'~", $s,
            'the shake is not spent on exactly the verdicts that need looking at');
        $this->assertStringContainsString("'dr-nudge .3s ease-in-out'", $s);
        $this->assertStringContainsString("'dr-rise .3s cubic-bezier(.22,.61,.36,1)'", $s);

        // Every verdict repaints the whole slab, so nothing can be left behind from the
        // last one — the fault `else fxStop()` used to guard against.
        $this->assertStringContainsString('function paint(v)', $s);
        $this->assertStringContainsString('el.party.innerHTML = \'\'', $s,
            'the party buttons from a previous verdict survive into the next');
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
