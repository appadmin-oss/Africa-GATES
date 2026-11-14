<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Pdf;
use PHPUnit\Framework\TestCase;

/**
 * A face's fallback may itself name a fallback, and only the first link was asked: a
 * character the middle face lacked but the last one had was DROPPED from the document,
 * while the registration read as covering it. The naira sign is the case that matters
 * here — Playfair and DM Sans both lack ₦, AG Text has it — and an amount on a letter
 * printed without its currency is not a smaller amount, it is a different claim.
 */
final class PdfFallbackChainTest extends TestCase
{
    private static function runs(Pdf $pdf, string $s, string $alias): array
    {
        $m = new \ReflectionMethod(Pdf::class, 'runs');
        return $m->invoke($pdf, $s, $alias);
    }

    public function test_the_whole_fallback_chain_is_asked_before_a_character_is_dropped(): void
    {
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        $pdf = new Pdf();
        $this->assertTrue($pdf->font('text', $dir . 'AGText-Regular.ttf'));
        $this->assertTrue($pdf->font('sans', $dir . 'DMSans-Regular.ttf', 'text'));
        $this->assertTrue($pdf->font('display', $dir . 'PlayfairDisplay-Bold.ttf', 'sans'));

        $runs = self::runs($pdf, 'A₦B', 'display');
        $this->assertSame(['display', 'text', 'display'], array_column($runs, 0),
            'the naira sign was dropped: only the first fallback was asked');
        $this->assertCount(1, $runs[1][1]);
    }

    public function test_a_fallback_loop_ends_rather_than_spinning(): void
    {
        $dir = dirname(__DIR__, 2) . '/resources/fonts/';
        $pdf = new Pdf();
        $this->assertTrue($pdf->font('a', $dir . 'PlayfairDisplay-Bold.ttf', 'b'));
        $this->assertTrue($pdf->font('b', $dir . 'DMSans-Regular.ttf', 'a'));
        // Neither face has ₦: dropped, and the call returns.
        $this->assertSame([['a', self::runs($pdf, 'AB', 'a')[0][1]]], self::runs($pdf, 'A₦B', 'a'));
    }
}
