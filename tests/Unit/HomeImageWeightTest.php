<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The homepage's image weight on a phone (10 Nov 2026). Measured on a 390px, 3× phone: the
 * "Who do you want to honour?" tiles are drawn 145px wide but declared `sizes="46vw"` (the list
 * item, not the image inside its padding), so each chose the 960 file — and the "who" cards had
 * no srcset at all. Images went 525KB → 259KB and the page 951KB → 686KB once `sizes` said
 * the width each image is actually drawn at (measured at 360–1600px).
 */
final class HomeImageWeightTest extends TestCase
{
    public function test_each_image_declares_the_width_it_is_drawn_at(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/home.twig');
        $this->assertStringContainsString('sizes="(min-width: 1200px) 160px, (min-width: 1024px) calc(33vw - 41px), (min-width: 600px) 180px, calc(46vw - 35px)"', $tpl);
        $this->assertMatchesRegularExpression('/class="hm-whoc__img"[^>]*srcset="[^"]*480w[^"]*960w"[^>]*sizes="\(min-width: 600px\) 270px, calc\(67vw - 5px\)"/s', $tpl);
        $this->assertStringContainsString('class="ag-foot__mark"', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/site-footer.twig'));
        $this->assertMatchesRegularExpression('/class="ag-foot__mark"[^>]*loading="lazy"/', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/site-footer.twig'), 'the footer is the last thing anyone reaches');
    }
}
