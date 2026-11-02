<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Was this page asked for IN ADVANCE — a prefetch or prerender — rather than opened?
 *
 * The public site prefetches a link the moment a pointer rests on it or a finger lands on
 * it (partials/instant-nav.twig), so most links are now fetched by somebody who may never
 * open them. A prefetch is a real GET with the visitor's cookies, so anything a GET
 * REMEMBERS happens on a page nobody saw. Measured by walking every prefetchable public URL
 * against a real session (2 Nov 2026): the database moved only in the page cache, which is
 * welcome, but the SESSION did not — `/account/login?next=…` and every page that bounces a
 * guest to sign in writes `login_next`, so on the sign-in page itself, hovering the nav sent
 * somebody who had come to book an event to the volunteer form once they signed in. Code
 * the walk could not reach because the seed has none: a profile's `view_count`, which the
 * CPI baseline reads, and an ad's per-visitor frequency cap and the slot inventory.
 *
 * The rule: a GET may RENDER for a speculative request, never REMEMBER. One answer, read by
 * every such writer and by VisitTracker, so the browsers' four spellings are listed once.
 * A request with no headers (a console command, a test without them) is not speculative.
 */
final class Speculative
{
    private const HEADERS = ['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'];

    public static function is(?Request $request = null): bool
    {
        foreach (self::HEADERS as $h) {
            $v = $request !== null ? $request->getHeaderLine($h)
                : (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '');
            $v = strtolower(trim($v));
            if ($v !== '' && (str_contains($v, 'prefetch') || str_contains($v, 'prerender') || str_contains($v, 'preview'))) return true;
        }
        return false;
    }
}
