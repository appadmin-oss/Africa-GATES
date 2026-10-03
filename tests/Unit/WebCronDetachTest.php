<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Maintenance must never run on a connection the visitor is still waiting on.
 *
 * WHAT HAPPENED. public/index.php ends with a shutdown handler that runs due
 * maintenance "after the response is flushed to the client", guarded by
 * `fastcgi_finish_request()`. That function is PHP-FPM's. On LiteSpeed — which is
 * what this site runs — it does not exist, so the guard was false on every request
 * and the maintenance run happened INLINE, with the browser still connected.
 *
 * The cost is not small. `Maintenance::run('auto')` reconciles stale pending payments
 * on EVERY tick, one gateway verify per order at a 15-second timeout, then sends
 * abandoned-checkout mail over SMTP. Twenty abandoned orders is five minutes of work
 * bolted onto whichever visitor arrived when the ~15-minute throttle expired. The
 * server kills the worker long before that finishes and, because the response was
 * already being written, the browser receives a truncated HTTP/2 stream:
 * ERR_HTTP2_PROTOCOL_ERROR — which reads as a network fault, not a timeout.
 *
 * It compounds, which is why it worsened instead of holding steady: a failing
 * checkout creates pending orders, pending orders lengthen reconciliation, longer
 * reconciliation kills more requests. The paid-vote POST was reported first because
 * it is already the slowest request on the site — it makes its own synchronous
 * gateway call — so it has the least headroom before the limit.
 *
 * WHY THIS TEST IS TEXTUAL. The subject is a shutdown handler in the front
 * controller: it cannot be imported, and PHPUnit cannot observe another SAPI's
 * detach. What can be pinned is the invariant, and this repo already asserts against
 * configuration text for the same reason (SecurityHeadersTest parses .htaccess). Two
 * things have to stay true, and both were violated by one missing `elseif`.
 */
class WebCronDetachTest extends TestCase
{
    private function bootstrap(): string
    {
        $path = dirname(__DIR__, 2) . '/public/index.php';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /** LiteSpeed's own function must be consulted — its absence WAS the bug. */
    public function test_litespeed_detach_is_checked(): void
    {
        $this->assertStringContainsString(
            'litespeed_finish_request',
            $this->bootstrap(),
            'public/index.php must check litespeed_finish_request(). fastcgi_finish_request() '
            . 'is PHP-FPM only, so on LiteSpeed the response is never detached and maintenance '
            . 'runs inline on a live visitor request.'
        );
    }

    /**
     * The invariant that actually protects a visitor: with no way to detach, the tick
     * must be skipped rather than run. Asserted by requiring a guard that returns
     * before Maintenance::tick is reached.
     */
    public function test_maintenance_is_skipped_when_the_response_cannot_be_detached(): void
    {
        $src = $this->bootstrap();

        $guardAt = strpos($src, 'if (!$detached)');
        $tickAt  = strpos($src, 'Maintenance::tick');

        $this->assertNotFalse($guardAt, 'No `if (!$detached)` guard — maintenance can run on a connection the visitor is waiting on.');
        $this->assertNotFalse($tickAt, 'Maintenance::tick call not found; this test needs updating with the bootstrap.');
        $this->assertLessThan(
            $tickAt,
            $guardAt,
            'The detach guard must come BEFORE Maintenance::tick, or it does not guard it.'
        );
        $this->assertMatchesRegularExpression(
            '/if \(!\$detached\)\s*\{[^}]*\breturn\b/s',
            $src,
            'The guard must RETURN when the response cannot be detached. Anything else lets a '
            . 'five-minute payment reconciliation run while the browser waits.'
        );
    }

    /**
     * Both detach functions are tried. Kept separate from the LiteSpeed assertion so a
     * failure names which half regressed.
     */
    public function test_both_sapis_are_covered(): void
    {
        $src = $this->bootstrap();
        foreach (['litespeed_finish_request', 'fastcgi_finish_request'] as $fn) {
            $this->assertMatchesRegularExpression(
                '/function_exists\(\s*\'' . preg_quote($fn, '/') . '\'\s*\)/',
                $src,
                "$fn must be probed with function_exists() before being called."
            );
        }
    }
}
