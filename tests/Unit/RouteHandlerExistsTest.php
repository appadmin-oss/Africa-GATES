<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every `Controller::class . ':method'` route points at a method that exists.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A route, a form and a button for `/admin/interviews/{id}/guests` were all written and
 * committed — and the controller method they pointed at never was. The route resolved, the
 * page rendered, the button looked live, and pressing it was a 500. Everything that would
 * normally catch a missing method was absent by construction:
 *
 *   · PHP does not resolve `'Controller:method'` until the route is DISPATCHED, so nothing
 *     is wrong at parse time and `php -l` is silent.
 *   · The unit tests for the feature exercised the SERVICE, which was complete and correct.
 *     The gap was in the one layer nothing was covering.
 *   · A human reading the diff sees a route line, a form and a service method, and the
 *     shape is right. What is missing is invisible: an absence between two present things.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A STRING SCAN RATHER THAN DISPATCHING THE APP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Dispatching every route would need a session, a role, a database fixture and a body for
 * each one — hundreds of them, most requiring setup unrelated to the property being
 * checked. The property here is narrow and syntactic: a name written in `routes.php` names
 * a method that exists. Reflection answers that exactly, for every route, in milliseconds.
 *
 * It is not a substitute for testing what the handler DOES. It is the floor: the thing
 * nobody should have to discover by clicking a button in production.
 */
final class RouteHandlerExistsTest extends TestCase
{
    public function test_every_routed_controller_method_exists(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/routes.php');

        // `Foo\Bar::class . ':method'` — the only handler form this file uses. Written with
        // any amount of whitespace around the dot, because it is formatted for alignment.
        preg_match_all(
            '~([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class\s*\.\s*[\'"]:([A-Za-z_][A-Za-z0-9_]*)[\'"]~',
            $src,
            $m,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($m, 'no routes were found — the pattern has stopped matching');

        // Short class names in routes.php come from `use` aliases at the top of the file.
        $aliases = $this->aliases($src);

        $missing = [];
        $seen    = [];

        foreach ($m as [$whole, $class, $method]) {
            $fqcn = $this->resolve($class, $aliases);
            if ($fqcn === null) continue;   // unresolvable alias — reported below, once

            $key = $fqcn . '::' . $method;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            if (!class_exists($fqcn)) {
                $missing[] = $key . '  →  the CLASS does not exist';
                continue;
            }
            if (!method_exists($fqcn, $method)) {
                $missing[] = $key . '  →  the method does not exist';
                continue;
            }
            // A private or protected handler resolves as "exists" and still 500s on
            // dispatch, which is the same failure wearing a different hat.
            $r = new \ReflectionMethod($fqcn, $method);
            if (!$r->isPublic()) {
                $missing[] = $key . '  →  the method is ' . ($r->isPrivate() ? 'private' : 'protected');
            }
        }

        $this->assertSame([], $missing,
            "These routes point at handlers that cannot be called:\n  " . implode("\n  ", $missing));
    }

    /**
     * The `use` map at the top of routes.php, including grouped and aliased imports.
     *
     * @return array<string,string> short name => fully-qualified
     */
    private function aliases(string $src): array
    {
        $out = [];

        // Grouped: `use A\B\{C, D as E,\n F};`
        preg_match_all('~use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\\\\\{([^}]+)\}~s', $src, $groups, PREG_SET_ORDER);
        foreach ($groups as [$whole, $prefix, $body]) {
            foreach (explode(',', $body) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                if (preg_match('~^([A-Za-z0-9_\\\\]+)\s+as\s+([A-Za-z0-9_]+)$~i', $part, $as)) {
                    $out[$as[2]] = $prefix . '\\' . $as[1];
                } else {
                    $out[substr($part, (int) strrpos('\\' . $part, '\\'))] = $prefix . '\\' . $part;
                }
            }
        }

        // Plain: `use A\B\C;` and `use A\B\C as D;`
        preg_match_all('~use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;~i',
                       $src, $plain, PREG_SET_ORDER);
        foreach ($plain as $p) {
            $fq    = $p[1];
            $short = $p[2] ?? substr($fq, (int) strrpos('\\' . $fq, '\\'));
            $out[$short] = $fq;
        }

        return $out;
    }

    /** @param array<string,string> $aliases */
    private function resolve(string $class, array $aliases): ?string
    {
        // Already fully qualified.
        if (str_starts_with($class, '\\')) return ltrim($class, '\\');
        if (str_contains($class, '\\'))    return $class;

        return $aliases[$class] ?? null;
    }
}
