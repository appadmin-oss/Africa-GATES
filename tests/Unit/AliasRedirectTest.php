<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The near-miss URL table in routes.php: every alias must lead somewhere real.
 *
 * ── THE FAILURE THIS EXISTS FOR ──────────────────────────────────────────────
 * Not "does a redirect fire" — that was verified against the running router when the
 * table was written. It is the SILENT one: somebody renames /events to /happenings a
 * year from now, and /event, /ticket, /tickets and /ceremony all keep 301-ing to a page
 * that no longer exists. A redirect to a 404 is worse than the original 404, because the
 * URL bar now shows a canonical-looking path and the visitor concludes the section was
 * deleted rather than that they mistyped.
 *
 * /tickets is exactly how this class of bug arrives: the supplied "final hours" email
 * design linked to it for "get them a seat", it was never a route on this platform, and
 * nothing failed — the link simply 404'd for every reader.
 *
 * Read from source rather than by booting the app: no other test here builds the full
 * router, and the thing worth pinning is the TABLE's agreement with the route
 * declarations, which is a static property of the file.
 */
class AliasRedirectTest extends TestCase
{
    private const ROUTES = __DIR__ . '/../../src/routes.php';

    /** @return array<string,string> alias => target, parsed out of the $aliases literal */
    private static function aliases(): array
    {
        $src = (string) file_get_contents(self::ROUTES);
        if (!preg_match('/\$aliases = \[(.*?)\n        \];/s', $src, $m)) {
            self::fail('Could not find the $aliases table in src/routes.php');
        }
        preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $m[1], $rows, PREG_SET_ORDER);

        $out = [];
        foreach ($rows as $r) $out[$r[1]] = $r[2];

        return $out;
    }

    private static function routeSource(): string
    {
        return (string) file_get_contents(self::ROUTES);
    }

    /** Is $path something the router actually declares? */
    private static function isDeclared(string $path, string $src): bool
    {
        // A literal top-level declaration.
        if (str_contains($src, "->get('" . $path . "'")) return true;

        // Or a path inside a group: '/account/login' = group '/account' + get '/login'.
        $cut = strrpos($path, '/');
        if ($cut !== false && $cut > 0) {
            $prefix = substr($path, 0, $cut);
            $rest   = substr($path, $cut);
            if (str_contains($src, "->group('" . $prefix . "'")
                && str_contains($src, "->get('" . $rest . "'")) {
                return true;
            }
        }

        return false;
    }

    public function test_the_table_is_present_and_has_not_been_gutted(): void
    {
        // A floor, not an exact count — entries will be added. Catches the table being
        // emptied or the regex silently matching nothing, which would make every other
        // assertion here pass vacuously.
        $this->assertGreaterThan(40, count(self::aliases()));
    }

    public function test_every_alias_target_is_a_real_route(): void
    {
        $src  = self::routeSource();
        $dead = [];
        foreach (self::aliases() as $from => $to) {
            if (!self::isDeclared($to, $src)) $dead[] = "$from -> $to";
        }

        $this->assertSame([], $dead,
            "These aliases 301 to a path the router does not declare. A redirect to a 404 "
            . "is worse than the 404 it replaced — fix the target or drop the alias:\n"
            . implode("\n", $dead));
    }

    public function test_no_alias_shadows_a_real_route(): void
    {
        // An alias that collides with a genuine page would redirect that page away —
        // the section becomes unreachable and the only symptom is a 301.
        $src = self::routeSource();
        // Strip the alias table itself, or every alias trivially "matches" its own entry.
        $withoutTable = (string) preg_replace('/\$aliases = \[.*?\n        \];/s', '', $src);

        $clashes = [];
        foreach (array_keys(self::aliases()) as $from) {
            // Top-level only: '/login' inside the /admin group is /admin/login and is fine.
            if (preg_match("~\\\$g->(?:get|post|any)\('" . preg_quote($from, '~') . "'~", $withoutTable) === 1) {
                $clashes[] = $from;
            }
        }

        $this->assertSame([], $clashes, 'These aliases shadow a real top-level route: ' . implode(', ', $clashes));
    }

    public function test_no_alias_redirects_to_another_alias(): void
    {
        // A chain costs an extra round trip and, if it ever loops, is an infinite redirect.
        $aliases = self::aliases();
        $chained = [];
        foreach ($aliases as $from => $to) {
            if (isset($aliases[$to])) $chained[] = "$from -> $to -> " . $aliases[$to];
        }

        $this->assertSame([], $chained, 'Alias chain: ' . implode('; ', $chained));
    }

    public function test_the_judge_portal_is_never_aliased(): void
    {
        // /judge is the judges' own console (a route group). Aliasing it to the public
        // /judges page would lock every judge out of scoring, and the only symptom would
        // be a 301 to a marketing page.
        $aliases = self::aliases();
        $this->assertArrayNotHasKey('/judge', $aliases);
        foreach (array_keys($aliases) as $from) {
            $this->assertStringStartsNotWith('/judge/', $from, "$from would shadow the judge portal");
        }
    }

    public function test_targets_are_absolute_and_aliases_are_single_segment(): void
    {
        foreach (self::aliases() as $from => $to) {
            $this->assertStringStartsWith('/', $from, "alias $from must be rooted");
            $this->assertStringStartsWith('/', $to, "target $to must be rooted");
            // A multi-segment alias would need its own pattern thinking; the table is
            // deliberately flat so it stays auditable at a glance.
            $this->assertSame(1, substr_count($from, '/'), "alias $from should be one segment");
        }
    }
}
