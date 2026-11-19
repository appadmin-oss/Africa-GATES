<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The public site on a phone, less crowded (owner, 2 Nov 2026: "the site tends to feel a
 * little cluttered on mobile"). Each was seen on a 390px screen:
 *   · Gee's round launcher sat over the right edge of every page at rest — a row's Vote
 *     button, a form's total — and came back at every pause while reading down;
 *   · the footer stacked twenty-nine links under every page, a second page of its own;
 *   · Community printed "0 threads, 0 replies" beside "No threads yet".
 */
final class PhoneDeclutterTest extends TestCase
{
    public function test_the_footer_columns_fold_on_a_phone_and_lose_no_link_without_script(): void
    {
        $t = ChromeRender::source('templates/partials/site-footer.twig');
        for ($h = 1; $h <= 3; $h++) {
            $this->assertMatchesRegularExpression('~<nav class="ag-foot__col" aria-labelledby="ag-foot-h' . $h . '">\s*<details class="ag-foot__fold" open data-ag-foot>\s*<summary class="ag-foot__h" id="ag-foot-h' . $h . '">~',
                $t, "column $h does not fold, or ships closed (a page without script would hide its links)");
        }
        $js = ChromeRender::code('public/assets/js/shell.js');
        $this->assertMatchesRegularExpression("~matchMedia\('\(min-width: 600px\)'\).*details\[data-ag-foot\].*\.open = wideFoot\.matches~s", $js, 'nothing closes the folds on a phone');

        $css = ChromeRender::code('public/assets/css/components/footer.css');
        $this->assertMatchesRegularExpression('~@media \(max-width:599px\)\{[^@]*\.ag-foot__h\{[^}]*min-height:52px~s', $css);
        $this->assertMatchesRegularExpression('~\.ag-foot__list \.ag-foot__a\{ display:block; padding-block:13px \}~', $css, 'a folded link under 44px tall');
    }

    public function test_gee_is_an_edge_tab_on_a_phone_and_stays_tucked_while_reading_down(): void
    {
        $css = ChromeRender::code('public/assets/css/components/gee.css');
        $this->assertMatchesRegularExpression('~@media \(max-width:599px\)\{\s*\.gee__fab\{ inset-inline-end:0; width:40px; height:44px~', $css, 'the phone launcher is the round button over the page again');

        $js = ChromeRender::code('public/assets/js/gee.js');
        $this->assertStringContainsString("window.matchMedia('(max-width: 599px)')", $js);
        $this->assertMatchesRegularExpression('~settle = setTimeout\(function \(\) \{\s*if \(!\(phone && phone\.matches && y > 80\)\) reading = false;~', $js, 'it comes back at every pause on a phone');
    }

    public function test_community_draws_no_counts_before_there_is_a_thread(): void
    {
        $t = ChromeRender::source('templates/pages/community/threads.twig');
        $gate = strpos($t, '{% if stats.threads > 0 %}');
        $this->assertNotFalse($gate, '"0 threads, 0 replies" beside an empty list');
        $this->assertLessThan(strpos($t, 'id="cm-pulse-h"'), $gate);
    }
}
