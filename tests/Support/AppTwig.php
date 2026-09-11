<?php
declare(strict_types=1);

namespace Tests\Support;

use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * THE APP'S TWIG VOCABULARY, FOR A TEST THAT BUILDS ITS OWN ENVIRONMENT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Several render tests build a bare `Environment` so they can chain an `ArrayLoader` of
 * fixture templates onto the real one. A bare environment has none of the functions,
 * filters or globals `config/container.php` registers — so the moment a SHIPPED screen
 * starts using one, those tests fail for a reason that has nothing to do with what they
 * assert.
 *
 * This repository has already paid for that once: `csrf_token` is a Twig GLOBAL, and
 * sixteen tests broke together the day a screen gained a form. The recorded lesson was
 * "mirror the app's globals in the test; do not default the token in the template" —
 * because defaulting it in the template ships an empty token and has the write rejected
 * in production, which is far worse than a red test.
 *
 * It happened again with a FUNCTION rather than a global. The award result page gained a
 * `{{ asset(...) }}` — the content-hash cache buster that exists because `?v=v1` was
 * pinned for ever on a host with no deploy step — and three test classes died with
 * `Unknown "asset" function`, naming a partial none of them had heard of.
 *
 * So the vocabulary lives in one place. A test that needs a hand-built environment calls
 * {@see equip} and gains whatever the application has; when a screen starts using a new
 * one, it is added HERE and every such test keeps working.
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────────
 * Not a stub layer. Every entry below calls the same production class the container
 * wires, so a test render exercises the real `Assets::url()` and the real date handling.
 * A fake `asset()` returning its argument would hide exactly the bug the real one exists
 * to prevent.
 */
final class AppTwig
{
    /**
     * Register the application's Twig functions, filters and globals on `$twig`.
     *
     * CALL THIS BEFORE THE ENVIRONMENT RENDERS ANYTHING. Twig freezes its extension set
     * the first time it is read, and every later `addFunction` throws "extensions have
     * already been initialized" — including the read performed by `getFunction()`, so
     * this cannot politely check whether a name is taken first. It adds unconditionally,
     * which is correct for the freshly-built environments it exists to serve.
     */
    public static function equip(Environment $twig, array $globals = []): void
    {
        foreach ([
            'asset'         => [\AfricaGates\Support\Assets::class, 'url'],
            'media_url'     => [\AfricaGates\Support\Media::class, 'url'],
            'tz_abbr'       => [\AfricaGates\Support\DisplayTime::class, 'abbr'],
        ] as $name => $callable) {
            $twig->addFunction(new TwigFunction($name, $callable));
        }

        foreach ([
            'media_url'   => [\AfricaGates\Support\Media::class, 'url'],
            'when'        => [\AfricaGates\Support\DisplayTime::class, 'show'],
            'when_zoned'  => [\AfricaGates\Support\DisplayTime::class, 'showZoned'],
            'when_input'  => [\AfricaGates\Support\DisplayTime::class, 'forInput'],
        ] as $name => $callable) {
            $twig->addFilter(new TwigFilter($name, $callable));
        }

        // The two every layout reads. Overridable, because a test asserting on escaping
        // wants to choose the nonce it looks for.
        foreach ($globals + ['csp_nonce' => 'test-nonce', 'csrf_token' => 'test-csrf'] as $k => $v) {
            $twig->addGlobal($k, $v);
        }
    }
}
