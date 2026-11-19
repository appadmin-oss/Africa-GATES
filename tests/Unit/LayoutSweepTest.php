<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "Fix other layout issues" (owner, 11 Nov 2026), after the award page. A sweep of the public
 * pages on a phone and a desktop; each test is one thing it found.
 */
final class LayoutSweepTest extends TestCase
{
    /**
     * An organiser's description arrives as HTML from the event builder. Printed as text, the
     * page showed its own tags — "<p>Talks, workshops and a founders fair.</p>".
     */
    public function test_an_event_description_in_html_reads_as_text_and_nothing_runs(): void
    {
        DB::table('gates_site_events')->insert(['slug' => 'founders-fair', 'title' => 'Founders fair', 'status' => 'published',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+10 days')), 'created_at' => date('Y-m-d H:i:s'),
            'description' => '<p>Talks, <strong>workshops</strong> and a fair.</p><script>alert(1)</script>']);
        $html = ChromeRender::html('/events/founders-fair');
        $this->assertStringNotContainsString('&lt;p&gt;Talks', $html, 'the page printed the description’s own tags');
        $this->assertStringContainsString('<strong>workshops</strong>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html, 'an organiser’s script ran on a public page');

        DB::table('gates_site_events')->insert(['slug' => 'plain-day', 'title' => 'Plain day', 'status' => 'published',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+11 days')), 'created_at' => date('Y-m-d H:i:s'),
            'description' => "Line one\nLine two"]);
        $this->assertStringContainsString('Line one<br />', ChromeRender::html('/events/plain-day'), 'plain text lost its line breaks');
    }

    public function test_the_layout_fixes_hold(): void
    {
        $css = static fn (string $f): string => ChromeRender::code('public/assets/css/components/' . $f);
        // Discover: an empty holder for a corrected search cost two 30px gaps under the filters.
        $this->assertStringContainsString('.dv__panel > [data-dv-fix]:empty{ display:none }', $css('discover.css'));
        // A link standing on its own is a control: 44px, not a 17–21px line of text.
        foreach (['awards.css' => '.aw-sec__link', 'community.css' => '.cm-link', 'ads.css' => '.am-note .am-link',
                  'giving.css' => '.gv-meta a', 'results.css' => '.rs-method > a:only-child'] as $f => $sel) {
            $this->assertMatchesRegularExpression('~' . preg_quote($sel, '~') . '\{[^}]*min-height:44px~', $css($f), "$sel is a thin target again");
        }
        // Over a photo the date is white, on an overlay dark enough by the words' band.
        $this->assertStringContainsString('.ed-hero__date{ color:var(--ag-surface) }', $css('events.css'), 'the hero date is pale green on a pale cover again');
        // The notices leave room before the page begins.
        $this->assertStringContainsString('padding:12px var(--ag-gutter) 12px', $css('notice.css'));
    }
}
