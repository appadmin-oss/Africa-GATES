<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CyclePolicy;
use DI\ContainerBuilder;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * The clock a voter sees in the last hours.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE REMAINING SECONDS COME FROM THE SERVER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious build parses the closing timestamp in the browser and subtracts `Date.now()`.
 * That makes the number a function of the VISITOR'S system clock — and a phone an hour out,
 * or a year out, which is ordinary on cheap Android after a flat battery, then shows a
 * confident wrong answer about when its vote stops counting. On the ballot page that answer
 * decides whether somebody bothers.
 *
 * So the server sends the seconds it computed and the browser only decrements. The first
 * test below is the one that keeps that true: a template that starts emitting the absolute
 * timestamp for JavaScript to subtract from would pass every visual check and reintroduce
 * the whole fault.
 *
 * ── AND WHY IT ESCALATES INSTEAD OF SHOUTING THROUGHOUT ──────────────────────
 *
 * A ticking clock three weeks out is decoration, and decoration in an urgent register is
 * how people learn to ignore the urgent register. `closing_soon` draws the line server-side
 * at 48 hours, so the escalation cannot disagree with the phase logic that gates the vote.
 */
final class VoteCountdownTest extends TestCase
{
    private Twig $twig;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['csrf_token' => 'tok'];
        $builder  = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $this->twig = $builder->build()->get(Twig::class);
    }

    /** The view-model a cycle closing in $seconds produces, as CyclePolicy would build it. */
    private function phase(int $seconds): array
    {
        return [
            'phase'        => 'voting',
            'label'        => 'Voting open',
            'closes_at'    => date('Y-m-d H:i:s', time() + $seconds),
            'seconds_left' => $seconds,
            'closing_soon' => $seconds <= CyclePolicy::CLOSING_SOON_SECONDS,
        ];
    }

    private function render(array $phase, string $variant = ''): string
    {
        return $this->twig->fetch('partials/vote-countdown.twig',
            ['phase' => $phase] + ($variant !== '' ? ['variant' => $variant] : []));
    }

    // ══ the visitor's clock is never consulted ═══════════════════════════════

    public function test_the_remaining_seconds_are_server_computed(): void
    {
        $html = $this->render($this->phase(7200));

        $this->assertStringContainsString('data-vc-left="7200"', $html,
            'the browser must be handed a duration to count down, not a date to subtract from');
    }

    /**
     * The ticker script must not read the clock either.
     *
     * The template could be right and the script wrong: one `Date.now()` in the decrementer
     * would put the visitor's clock back in the arithmetic with nothing on screen to show it.
     */
    public function test_the_ticker_does_not_read_the_system_clock(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/main.js');
        $countdown = substr($js, (int) strpos($js, 'LIVE VOTE COUNTDOWN'));
        // The block runs to the end of its own IIFE; taking 4KB is comfortably past it and
        // well short of anything that would make this assertion about other code.
        $countdown = substr($countdown, 0, 4000);

        foreach (['Date.now', 'new Date'] as $needle) {
            $this->assertStringNotContainsString($needle, $countdown,
                'the countdown must decrement the server\'s number, never re-derive it');
        }
    }

    // ══ it escalates, rather than ticking for three weeks ════════════════════

    public function test_a_distant_deadline_is_one_quiet_line(): void
    {
        $html = $this->render($this->phase(21 * 86400));

        $this->assertStringNotContainsString('vc__clock', $html,
            'a clock ticking three weeks out trains people to ignore clocks');
        $this->assertStringContainsString('Voting closes', $html);
    }

    public function test_inside_the_closing_window_it_becomes_a_live_clock(): void
    {
        $html = $this->render($this->phase(6 * 3600));

        $this->assertStringContainsString('vc--soon', $html);
        $this->assertStringContainsString('vc__clock', $html);
        $this->assertStringContainsString('Closing soon', $html);
    }

    /** A cycle with no close date must not render a countdown to nothing. */
    public function test_no_deadline_renders_nothing(): void
    {
        $html = $this->render(['phase' => 'voting', 'closes_at' => null, 'seconds_left' => null]);

        $this->assertSame('', trim($html));
    }

    /** Nor must a deadline that has already passed. */
    public function test_an_expired_deadline_renders_nothing(): void
    {
        $html = $this->render([
            'closes_at' => date('Y-m-d H:i:s', time() - 60), 'seconds_left' => 0,
            'closing_soon' => false,
        ]);

        $this->assertSame('', trim($html));
    }

    // ══ the digits are not announced, the deadline is ════════════════════════

    /**
     * A screen reader announcing a new value every second is unusable.
     *
     * The fix is not to withhold the clock — sighted urgency is information too — but to
     * make the group's accessible name the fixed deadline and hide the digits. This is the
     * assertion the nominee ballot's static date was standing in for before it had one.
     */
    public function test_the_digits_are_hidden_from_assistive_tech_and_the_deadline_is_not(): void
    {
        $html = $this->render($this->phase(3600));

        $this->assertMatchesRegularExpression('/<div class="vc[^"]*"\s+data-vc/', $html);
        $this->assertStringContainsString('aria-label="Voting closes', $html);
        $this->assertStringContainsString('<div class="vc__clock" aria-hidden="true">', $html);
    }

    // ══ one partial, two grounds ═════════════════════════════════════════════

    /**
     * The nominee ballot's header is dark, and a second copy of this markup was the wrong
     * way to serve it: the reasoning behind the aria treatment above is not obvious from
     * reading the markup, so a copy would lose it within one edit.
     */
    public function test_the_dark_variant_is_the_same_markup(): void
    {
        $light = $this->render($this->phase(3600));
        $dark  = $this->render($this->phase(3600), 'dark');

        $this->assertStringContainsString('vc--dark', $dark);
        $this->assertStringNotContainsString('vc--dark', $light);
        // Same accessible contract on both grounds.
        $this->assertStringContainsString('aria-label="Voting closes', $dark);
        $this->assertStringContainsString('aria-hidden="true"', $dark);
    }

    /** And the dark palette actually exists, or the clock is white-on-white. */
    public function test_the_dark_variant_is_styled(): void
    {
        $css = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/assets/css/components/nav.css');

        $this->assertStringContainsString('.vc--dark', $css);
        $this->assertStringContainsString('.vc--dark .vc__cell b', $css,
            'the digits need a colour of their own on a dark ground');
    }

    // ══ it reaches the page where the vote is cast ═══════════════════════════

    /**
     * The ballot page is the point of the feature.
     *
     * The hub at /vote had the ticker and the nominee ballot did not, so the page a
     * "closing soon" share link actually lands on — and the only page where the deadline
     * changes what somebody does in the next minute — showed a static date.
     */
    public function test_the_nominee_ballot_includes_the_countdown(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');

        $this->assertStringContainsString("include 'partials/vote-countdown.twig'", $tpl);
        $this->assertStringContainsString("variant: 'dark'", $tpl);
    }

    /** And it is still on the hub. */
    public function test_the_vote_hub_still_includes_the_countdown(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote.twig');

        $this->assertStringContainsString("include 'partials/vote-countdown.twig'", $tpl);
    }

    /**
     * The whole ballot page renders with a live deadline in it.
     *
     * A partial that is correct in isolation and throws inside the page it was added to is
     * the failure this codebase has already had twice with `{% set %}` inside a `{% block %}`:
     * the include uses `only`, so anything the partial needs and is not handed arrives as
     * null and renders as an empty attribute rather than an error.
     */
    public function test_the_ballot_page_renders_with_a_ticking_deadline(): void
    {
        $html = $this->twig->fetch('pages/vote-nominee.twig', [
            'n' => ['name' => 'Ada Obi', 'category' => 'Innovation', 'tagline' => 'A leader',
                    'vote_count' => 5, 'programme_title' => 'STEM'],
            'firstName'   => 'Ada',
            'others'      => [],
            'AV'          => [['#eee', '#333']],
            'flag'        => '🇳🇬',
            'ctry'        => 'Nigeria',
            'voting_open' => true,
            'phase'       => $this->phase(5 * 3600),
        ]);

        $this->assertStringContainsString('data-vc-left="18000"', $html);
        $this->assertStringContainsString('vc--dark', $html);
        $this->assertStringContainsString('vc__clock', $html);
    }
}
