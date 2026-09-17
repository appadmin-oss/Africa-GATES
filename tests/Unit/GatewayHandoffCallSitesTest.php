<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GatewayHandoff;
use Tests\TestCase;

/**
 * Every payment handoff calls a method that exists.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `EventsController::redirect()` called `GatewayHandoff::render($view, $req, $res, '/events')`.
 * There is no `render()` on that class and there never was — the method was invented at the
 * call site, and because the line was never once executed in a test, PHP raised an
 * undefined-method fatal the first time a real buyer came back from the payment gateway.
 *
 * Which is to say: on every paid ticket the events feature has ever been asked to sell. The
 * buyer sees a 500 at the exact moment their money has left, which reads as "my payment has
 * vanished" — and it is the one moment in the whole platform where an error page costs the most
 * trust. Reported from production, not found here, because nothing was looking.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A REFLECTION TEST AND NOT JUST A FEATURE TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A feature test would have caught this one instance. This catches the CLASS of mistake, which
 * is what matters, because the shape recurs: a controller written against an imagined API,
 * where the imagined name is plausible enough that nobody reads it twice, on a path that only
 * executes after a real payment. There are four handoff call sites and any of them could drift
 * the same way — a method renamed on the service, a fifth flow copied from a stale example.
 *
 * PHP will not tell you until the line runs. So the source is read and every
 * `GatewayHandoff::something()` in it is checked against the real class. It is a cheap test for
 * a failure whose cheapest other detection is a customer complaint.
 */
final class GatewayHandoffCallSitesTest extends TestCase
{
    /** @return list<string> repo-relative PHP files under src/ */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $out  = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function test_every_static_call_on_the_handoff_names_a_real_method(): void
    {
        $real = get_class_methods(GatewayHandoff::class);
        $this->assertNotSame([], $real, 'reflection found no methods — the test is broken');

        $bad = [];
        $seen = 0;
        foreach ($this->sources() as $path) {
            $body = (string) file_get_contents($path);
            if (!str_contains($body, 'GatewayHandoff::')) continue;

            // COMMENTS STRIPPED FIRST. The fix's own docblock names the method that used to be
            // called — as the explanation of what went wrong — and the first version of this
            // test flagged that as a live call site. A comment is bytes in a file like any
            // other, which is the second time that has bitten in this session; SlugTest strips
            // them for exactly the same reason.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $body);

            preg_match_all('/GatewayHandoff::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $m);
            foreach ($m[1] as $method) {
                $seen++;
                if (!in_array($method, $real, true)) {
                    $bad[] = str_replace(dirname(__DIR__, 2) . '/', '', $path) . ' → ::' . $method . '()';
                }
            }
        }

        // A sweep that matches nothing passes forever. There are several handoff call sites; if
        // this count collapses, the regex broke rather than the problem going away.
        $this->assertGreaterThanOrEqual(8, $seen,
            'found almost no GatewayHandoff calls — the scan is broken, so this proves nothing');

        sort($bad);
        $this->assertSame([], $bad,
            "A payment handoff calls a method that does not exist. PHP will not tell you until "
            . "the line runs — and the line only runs after somebody has paid, so the first "
            . "person to find out is a buyer looking at a 500 with their money gone.\n"
            . 'Real methods: ' . implode(', ', $real));
    }

    public function test_the_three_methods_a_handoff_needs_are_all_present(): void
    {
        // Named individually, so a rename on the service fails here with the reason rather than
        // somewhere downstream with a fatal.
        foreach (['reference', 'take', 'page', 'remember', 'providerLabel'] as $needed) {
            $this->assertTrue(method_exists(GatewayHandoff::class, $needed),
                "GatewayHandoff::{$needed}() has gone — every checkout flow depends on it");
        }
    }

    public function test_the_events_flow_bounces_rather_than_erroring_without_a_url(): void
    {
        // The behaviour the fix restored, asserted at the source level because instantiating the
        // controller needs Twig and a container. What matters is that the no-URL branch is a
        // redirect and not an exception: a stale tab, an expired session or a link somebody kept
        // overnight is ordinary, and an error page at that moment reads as "my money is gone".
        $raw  = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/EventsController.php');
        // Stripped, for the same reason: a docblock that DESCRIBES the right shape would
        // otherwise satisfy these assertions while the code below it did nothing.
        $body = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $raw);

        $this->assertMatchesRegularExpression(
            '/function redirect\(.*?GatewayHandoff::take\(/s', $body,
            'the events handoff no longer reads the stored checkout URL');
        $this->assertMatchesRegularExpression(
            '/function redirect\(.*?\$url === null.*?goTo\(/s', $body,
            'the events handoff must redirect when there is no URL, not throw');
        $this->assertMatchesRegularExpression(
            '/function redirect\(.*?GatewayHandoff::page\(/s', $body,
            'the events handoff no longer renders the interstitial');
    }
}
