<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * WHERE A SESSION LIVES, AND FOR HOW LONG — set before `session_start()`.
 *
 * The cookie has always lasted seven days (`public/index.php`). The session DATA behind it
 * did not: PHP's server-side `session.gc_maxlifetime` defaults to 1440 seconds, nothing here
 * raised it, and the files sat in the host's shared session directory — where every other
 * site on the machine runs garbage collection with ITS lifetime, and Debian-family hosts
 * run a cron that deletes anything older than php.ini's value whatever this script says at
 * runtime. So about twenty-four idle minutes in, the cookie still named a session whose
 * data had been deleted, the next request minted a new `csrf_token`, and every form already
 * on screen was refused with "CSRF validation failed". People reported it as the site
 * telling them their request was invalid and leaving them there: a nomination written on a
 * phone between other work, a checkout left open while somebody found their card.
 *
 * Fixed at the cause, and both halves are needed:
 *   · the lifetime is the cookie's own, so the two cannot disagree again;
 *   · the files live in `var/sessions`, a directory nothing else on the host collects
 *     (and `var/.htaccess` denies to the web on a host whose document root is wrong).
 * Where that directory cannot be made or written, the host's default is kept — a session
 * that ends early is a nuisance, a session that cannot start is an outage.
 */
final class SessionStore
{
    /** Seconds. The session cookie's lifetime, and so the data's. */
    public const LIFETIME = 86400 * 7;

    /**
     * Apply the lifetime and, where possible, the private directory. Returns the directory
     * used, or null when the host's default was kept.
     */
    public static function configure(string $root): ?string
    {
        @ini_set('session.gc_maxlifetime', (string) self::LIFETIME);

        $dir = rtrim($root, '/') . '/var/sessions';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        if (!is_writable($dir)) return null;

        session_save_path($dir);
        // A private directory is collected by nobody but us, so collection has to be on:
        // one request in a thousand sweeps files older than LIFETIME.
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '1000');

        return $dir;
    }
}
