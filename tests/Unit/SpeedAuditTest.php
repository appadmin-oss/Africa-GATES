<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\HomeFront;
use AfricaGates\Support\Assets;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The speed set-up's own effects on the people using it (audit, 2 Nov 2026), and the second
 * round of weight taken off. Each was measured in a browser before it was fixed:
 *   · a page prefetched before a change made by script (a vote cast in place, a reaction)
 *     was served as it was — the rules are reset after any script request that writes;
 *   · prefetching spent data where the browser had been told to save it;
 *   · on the homepage three photographs were downloaded twice, once under a bare path from a
 *     srcset and once under `asset()`'s versioned one;
 *   · the globe's geometry (37K) and its 15,000-dot sampling ran on arrival, far above the
 *     band; the logo was a 192px PNG (13.7K) drawn at 30–34px on every page;
 *   · Gee lay over the right end of the homepage's second call to action before anybody
 *     scrolled, and Pulse offered a guest "Sign in to post" under "Sign in or join".
 */
final class SpeedAuditTest extends TestCase
{
    public function test_a_script_write_resets_the_prefetch_rules_and_save_data_turns_them_off(): void
    {
        $t = ChromeRender::source('templates/partials/instant-nav.twig');
        $this->assertStringContainsString('<script type="speculationrules" nonce="{{ csp_nonce }}" data-ag-nav>', $t);
        $this->assertMatchesRegularExpression("~if \(m !== 'GET' && m !== 'HEAD'\) p\.then\(reset, reset\);~", $t, 'a page fetched before a write is served after it');
        $this->assertMatchesRegularExpression('~var c = s\.cloneNode\(true\);\s*s\.remove\(\);\s*document\.head\.appendChild\(c\);~', $t, 'removed and not replaced, nothing is prefetched again');
        $this->assertMatchesRegularExpression("~conn\.saveData \|\| /\(\^\|-\)2g\\$/\.test\(conn\.effectiveType~", $t, 'prefetching where data is dear');
    }

    public function test_every_homepage_photo_is_one_url(): void
    {
        foreach (HomeFront::honourTiles() as $t) {
            foreach (explode(',', $t['srcset']) as $c) {
                $u = trim(explode(' ', trim($c))[0]);
                $this->assertSame(Assets::url(preg_replace('~\?.*$~', '', $u)), $u, 'a srcset entry that is not the URL asset() gives — downloaded twice beside a versioned src');
            }
        }
    }

    public function test_the_globe_waits_for_its_band_and_the_logo_is_its_size(): void
    {
        $js = ChromeRender::code('public/assets/js/we-are-africa.js');
        $this->assertMatchesRegularExpression("~new IntersectionObserver\(.*?io\.disconnect\(\);\s*load\(\);.*?rootMargin: '800px 0px'~s", $js, 'the geometry is fetched on arrival again');
        foreach (['templates/partials/site-header.twig', 'templates/partials/app-bar.twig', 'templates/partials/site-footer.twig'] as $f) {
            $this->assertStringContainsString("asset('/assets/img/logo-mark-96.webp')", ChromeRender::source($f), "$f draws the 192px mark at 30px");
        }
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/img/logo-mark-96.webp');
        $this->assertLessThan(4096, filesize(dirname(__DIR__, 2) . '/public/assets/img/logo-mark-96.webp'));
    }

    public function test_gee_never_rests_on_a_control_and_pulse_asks_a_guest_once(): void
    {
        $js = ChromeRender::code('public/assets/js/gee.js');
        $this->assertStringContainsString('function overControl()', $js);
        $this->assertMatchesRegularExpression('~document\.elementsFromPoint\(xs\[i\], ys\[j\]\)~', $js);
        $this->assertMatchesRegularExpression('~if \(\(reading \|\| blocked\) && document\.activeElement !== fab\) fab\.setAttribute\(\'data-tuck\'~', $js, 'it never leaves while it has the focus');
        $this->assertMatchesRegularExpression("~requestAnimationFrame\(look\)~", $js, 'the page at rest is never asked');

        $p = ChromeRender::source('templates/pages/pulse.twig');
        $this->assertStringNotContainsString('Sign in to post', $p, 'a second sign-in under the guest banner');
        $this->assertStringContainsString('.pl-empty:has(.pl-empty__t) .pl-go{ align-self:center }', ChromeRender::code('public/assets/css/components/pulse.css'));
    }
}
