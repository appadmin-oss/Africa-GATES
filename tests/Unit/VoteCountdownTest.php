<?php
declare(strict_types=1);

namespace Tests\Unit;

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
 *
 * ── WHAT IS LEFT HERE, AND WHY ───────────────────────────────────────────────
 *
 * `partials/vote-countdown.twig` was destroyed on 3 Oct 2026 as an orphan of the old public pages
 * (docs/handoff/DESTROYED.md). The methods that rendered or read it went with it, and
 * their rules are in docs/handoff/inventory/_partials.md, which the rebuild re-asserts. What
 * remains tests code that survived.
 */
final class VoteCountdownTest extends TestCase
{
    // ══ the visitor's clock is never consulted ═══════════════════════════════

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
}
