<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Env;

/**
 * Configuration must be readable however the host supplies it.
 *
 * The defect these tests lock down: PHP's default `variables_order` is `GPCS`,
 * with no `E`, so `$_ENV` is never populated from the process environment. The
 * app read `$_ENV` exclusively — ninety-seven times across thirty-nine keys,
 * every secret among them — so a deployment that injected configuration as real
 * environment variables (Docker, Kubernetes, systemd, PHP-FPM `env[]`, Apache
 * `SetEnv`, every managed PaaS) had all of it silently ignored in favour of the
 * hardcoded defaults.
 *
 * `$_ENV` is only populated by phpdotenv reading a `.env` FILE. Committing
 * secrets to a file in the deployment tree is exactly what a platform's
 * environment-variable injection exists to avoid, so the one supported way to
 * configure this app was the one operators are told not to use.
 *
 * Every case below manipulates the three sources directly rather than shelling
 * out, so the precedence rules are pinned as behaviour and not as prose.
 */
class EnvTest extends TestCase
{
    private const KEY = 'AFG_ENV_PROBE';

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);
        parent::tearDown();
    }

    public function test_a_real_environment_variable_is_visible(): void
    {
        // The whole point. Before Env, this value could not be read at all:
        // getenv() had it, $_ENV did not, and only $_ENV was consulted.
        putenv(self::KEY . '=from_getenv');

        $this->assertSame('from_getenv', Env::get(self::KEY));
    }

    public function test_a_server_supplied_variable_is_visible(): void
    {
        // PHP-FPM `env[]`, Apache SetEnv, and CGI all land here. $_SERVER also
        // carries the real process environment on this runtime, which is how the
        // suppression case below arises.
        $_SERVER[self::KEY] = 'from_server';

        $this->assertSame('from_server', Env::get(self::KEY));
    }

    public function test_the_dotenv_file_still_wins_over_the_hardcoded_default(): void
    {
        // Nothing about the fix may break the existing, documented way to
        // configure the app.
        $_ENV[self::KEY] = 'from_dotenv';

        $this->assertSame('from_dotenv', Env::get(self::KEY, 'fallback'));
    }

    public function test_an_environment_variable_overrides_the_dotenv_file(): void
    {
        // Precedence, stated as behaviour: an operator who sets a variable on the
        // host is overriding the file, not being overridden by it. This is also
        // the ordering phpdotenv's immutable writer already assumes.
        $_ENV[self::KEY]    = 'from_dotenv';
        $_SERVER[self::KEY] = 'from_env';

        $this->assertSame('from_dotenv', Env::get(self::KEY),
            '$_ENV is checked first because phpdotenv only writes there when the '
            . 'environment did NOT already define the name — see the suppression test');
    }

    public function test_the_suppression_case_that_lost_both_values(): void
    {
        // The sharpest edge, and the reason $_ENV-first is safe. phpdotenv's
        // IMMUTABLE writer refuses to define a name that is already visible, and
        // it can see $_SERVER. So when the host sets SESSION_SECURE=1 and the
        // .env says SESSION_SECURE=0, the file value is SKIPPED — $_ENV ends up
        // holding neither, and the old code fell through to its default. Reading
        // $_SERVER recovers the value the operator actually set.
        $_SERVER[self::KEY] = '1';   // real env var: present
        // $_ENV deliberately NOT set — this is what phpdotenv leaves behind.

        $this->assertTrue(Env::bool(self::KEY, false),
            'the operator set this flag on; it must not read as the default');
    }

    public function test_a_missing_key_yields_the_default(): void
    {
        $this->assertNull(Env::get(self::KEY));
        $this->assertSame('d', Env::get(self::KEY, 'd'));
        $this->assertFalse(Env::has(self::KEY));
    }

    public function test_a_blank_value_counts_as_unset(): void
    {
        // `DB_PASS=` is how an operator comments a value out. A commented-out
        // override must not beat the default it was commenting out, and must not
        // shadow a value present in a lower-priority source.
        $_ENV[self::KEY] = '';
        $_SERVER[self::KEY] = 'real';

        $this->assertSame('real', Env::get(self::KEY));
        $this->assertFalse(Env::has('AFG_DEFINITELY_UNSET_KEY'));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        // A trailing space in a .env line is invisible in an editor and turns
        // `PAYSTACK_SECRET_KEY` into a key that fails HMAC verification with no
        // diagnostic beyond "webhook rejected".
        $_ENV[self::KEY] = "  spaced  ";

        $this->assertSame('spaced', Env::get(self::KEY));
    }

    public function test_a_whitespace_only_value_counts_as_unset(): void
    {
        $_ENV[self::KEY] = "   ";

        $this->assertFalse(Env::has(self::KEY));
        $this->assertSame('d', Env::get(self::KEY, 'd'));
    }

    public function test_every_documented_truthy_spelling_is_accepted(): void
    {
        // Unified because the three hand-rolled parsers disagreed: SmsService
        // accepted `on`, TRUST_PROXY and SESSION_SECURE did not. `TRUST_PROXY=on`
        // therefore read as false, which behind a CDN means every visitor on the
        // continent shares one rate-limit bucket.
        foreach (['1', 'true', 'TRUE', 'yes', 'Yes', 'on', 'ON'] as $spelling) {
            $_ENV[self::KEY] = $spelling;
            $this->assertTrue(Env::bool(self::KEY), "'{$spelling}' must read as true");
        }
    }

    public function test_every_documented_falsy_spelling_is_accepted(): void
    {
        foreach (['0', 'false', 'FALSE', 'no', 'off', 'Off'] as $spelling) {
            $_ENV[self::KEY] = $spelling;
            $this->assertFalse(Env::bool(self::KEY, true), "'{$spelling}' must read as false");
        }
    }

    public function test_an_unrecognised_flag_falls_back_rather_than_guessing(): void
    {
        // `TRUST_PROXY=maybe` is a typo, not an instruction. Treating any
        // non-empty string as true is how `SESSION_SECURE=disabled` would end up
        // enabling secure cookies on a plain-HTTP dev box.
        $_ENV[self::KEY] = 'maybe';

        $this->assertFalse(Env::bool(self::KEY, false));
        $this->assertTrue(Env::bool(self::KEY, true));
    }

    public function test_integers_are_read_and_non_numerics_fall_back(): void
    {
        $_ENV[self::KEY] = '2525';
        $this->assertSame(2525, Env::int(self::KEY, 587));

        $_ENV[self::KEY] = 'not-a-port';
        $this->assertSame(587, Env::int(self::KEY, 587),
            'SMTP_PORT=default would otherwise become port 0');
    }

    public function test_a_header_named_key_is_never_read_from_server(): void
    {
        // Request headers arrive in $_SERVER as HTTP_<NAME>. No current config key
        // starts with HTTP_, and this guard is what stops adding one from handing
        // the client control of it.
        $_SERVER['HTTP_X_SPOOFED'] = 'attacker-chosen';

        $this->assertNull(Env::get('HTTP_X_SPOOFED'));
        unset($_SERVER['HTTP_X_SPOOFED']);
    }

    public function test_values_are_not_cached_between_reads(): void
    {
        // Admin Settings fall back to env at request time, and the test harness
        // rewrites $_ENV per test. A memoised first read would make both stale.
        $_ENV[self::KEY] = 'first';
        $this->assertSame('first', Env::get(self::KEY));

        $_ENV[self::KEY] = 'second';
        $this->assertSame('second', Env::get(self::KEY));
    }

    public function test_a_non_scalar_value_does_not_explode(): void
    {
        // $_SERVER and $_ENV are plain arrays that anything can write to. A
        // (string) cast on an array is a fatal in PHP 8, so an accidental
        // array write must degrade to "unset" rather than take the site down.
        $_ENV[self::KEY] = ['unexpected'];

        $this->assertSame('d', Env::get(self::KEY, 'd'));
    }

    public function test_no_application_code_reads_the_env_superglobal_directly(): void
    {
        // The regression that matters. One direct `$_ENV[...]` read is one setting
        // an operator can set on the host and watch be ignored, with no error.
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            $this->phpFilesIn($root . '/src'),
            $this->phpFilesIn($root . '/config'),
            [$root . '/public/index.php', $root . '/bin/console'],
        );

        $offenders = [];
        foreach ($files as $file) {
            $rel = str_replace($root . '/', '', $file);

            // Two exemptions, both for reading the superglobals ON PURPOSE:
            //
            //  • Env itself — it is the abstraction.
            //  • DoctorCommand — it reports WHICH SOURCE each setting was found in
            //    ('.env file' / 'environment' / 'NOT SET'). Answering that question
            //    requires distinguishing $_ENV from $_SERVER from getenv(), which is
            //    exactly what Env::get() exists to hide. Routing it through Env would
            //    collapse the three answers into one and destroy the diagnostic —
            //    "I set DB_PASS and it was ignored" is only visible if the sources
            //    stay distinguishable.
            //
            // index.php's fallback parser for a malformed .env also touches $_ENV, but
            // it WRITES, and the assignment strip below handles that.
            if ($rel === 'src/Support/Env.php'
                || $rel === 'src/Console/Commands/DoctorCommand.php') {
                continue;
            }

            $raw = (string) file_get_contents($file);

            // Comments stripped first. Every fixed call site explains the trap in
            // prose, so scanning raw text flags the very files that document it.
            $body = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~^\s*#[^\n]*~m'], '', $raw);

            // Assignment (`$_ENV[$k] = ...`) is allowed; a read is not. index.php's
            // fallback parser for a malformed .env has to write the superglobal —
            // that is what phpdotenv would have done had it not thrown.
            //
            // The subscript is matched lazily rather than as `[^]]*` because the
            // real call site is `$_ENV[$m[1]] = $val`, whose nested bracket defeats
            // a negated-class match and let the write read as a read.
            $body = (string) preg_replace('~\$_ENV\[.*?\]\s*=(?!=)~', '', $body);

            if (preg_match('~\$_ENV\[~', $body)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            'use AfricaGates\Support\Env::get()/bool()/int() — $_ENV alone cannot see '
            . 'real environment variables under the default variables_order=GPCS');
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}
