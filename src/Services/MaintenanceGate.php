<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * "Close the public site while it runs": the planned-work window with its door shut.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 * The error page has carried a 503 "We're doing planned work" since the Phase 9 rebuild,
 * and NOTHING on the platform could produce one. An operator about to migrate the vote
 * tables had two choices: leave the site open and let somebody vote into a table halfway
 * through a change, or take it down at the host and show every visitor the host's own
 * error. Neither tells anybody when to come back.
 *
 * Settable from /admin/settings beside the window itself, because there is no shell on
 * production — a maintenance switch read from a file is one nobody can flip.
 *
 * ── WHAT STAYS OPEN, AND WHY EACH ONE ────────────────────────────────────────
 * Closing the site must not close the machinery that is mid-way through somebody's money
 * or the only route an operator has to open it again:
 *   · staff (an admin session) see the whole site, with a notice saying it is closed;
 *   · the console, the judges' panel, setup, and the one sign-in an operator uses to reach
 *     the console (`/account/login` and its code steps);
 *   · every payment CALLBACK and WEBHOOK — a gateway confirming a charge taken a minute
 *     before the window must still be credited, and a webhook refused with 503 is retried
 *     for days or abandoned;
 *   · the webcron (`/__cron/run`), mail and SMS hooks, and /status — the page a closed site
 *     sends people to;
 *   · static files.
 * Everything else answers the planned-work page with `503` and `Retry-After`, which is
 * what a crawler needs to keep the pages it has rather than dropping them as broken.
 */
final class MaintenanceGate
{
    /** Paths that stay open while the site is closed. Prefixes, matched at a segment boundary. */
    public const OPEN = [
        '/admin', '/judge', '/__setup', '/__cron', '/hooks', '/status', '/status.json',
        '/account/login', '/account/logout', '/session/token.json', '/pay/webhook',
        '/assets', '/uploads', '/media', '/favicon.ico', '/robots.txt',
    ];

    /** Is the public site closed right now? The window, from its one reader. */
    public static function closed(?array $settings = null): ?array
    {
        $p = PlannedWork::current($settings);
        return $p !== null && $p['close'] && $p['started'] ? $p : null;
    }

    /** Does this path stay open while the site is closed? */
    public static function open(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::OPEN as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) return true;
        }
        // A gateway returning somebody from a payment, or calling us about one, wherever the
        // product put the route (/vote/paid/callback, /shop/callback, …/bot/webhook).
        if (preg_match('~/(?:callback|webhook)$~', $path)) return true;
        // A static file the host did not serve directly (the dev server, a misrouted asset).
        return (bool) preg_match('~\.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|mp4|webm|vtt|webmanifest)$~i', $path);
    }

    /** Somebody running the platform: an open admin session. */
    public static function isStaff(): bool
    {
        return (int) ($_SESSION['admin_id'] ?? 0) > 0;
    }
}
