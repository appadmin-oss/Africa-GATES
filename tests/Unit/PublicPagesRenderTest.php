<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;
use Twig\Error\LoaderError;

/**
 * Every public page renders — none answers 500 — signed out AND signed in.
 *
 * The admin console has the same guard (AdminPagesRenderTest). This one walks every GET
 * route outside /admin over the seeded sandbox, twice: as a visitor and as a member, because
 * a page that reads the member and is never opened signed-in in a test is exactly where a
 * null slips through. Record pages are opened for real rows: a placeholder is filled from
 * the table its path segment names, an id or a slug.
 *
 * It lets the exception through rather than reading a 500 back, so a failure says WHAT broke.
 *
 * ONE CAUSE IS NOT A FAULT, and it is separated rather than excused. On 3 Oct 2026 the owner
 * destroyed the old public templates (docs/handoff/DESTROYED.md: "those pages stop working
 * until each phase rebuilds them"), so a route whose template is gone answers 500 by
 * decision. Those are counted apart, by the template's own name, under a ceiling that may
 * only fall as phases land. Anything else that answers 500 — a null, a bad query, a missing
 * column, a template that exists and does not compile — fails here by name.
 */
final class PublicPagesRenderTest extends TestCase
{
    /** Routes keyed on something the sandbox does not mint. May only fall. */
    private const UNREACHED_CEILING = 150;

    /** Distinct destroyed templates a public route still renders. May only fall, as phases rebuild. */
    private const AWAITING_REBUILD_CEILING = 10;

    public function test_every_public_page_renders_signed_out_and_signed_in(): void
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();

        DemoSeeder::seed(0);
        $member = (int) DB::table('gates_users')->insertGetId(['name' => 'Render Member', 'email' => 'render@member.test',
                                                              'status' => 'active', 'email_verified' => 1]);

        $failed = []; $rebuild = []; $rendered = 0; $unreached = 0;
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (!in_array('GET', $r->getMethods(), true)) continue;
            $p = $r->getPattern();
            if (preg_match('~^/(?:admin|judge|api|__|hooks|cron)~', $p) || preg_match('~logout|/sign-?out~', $p)) continue;

            $paths = [$p];
            if (str_contains($p, '{')) {
                $paths = $this->fill($p);
                if ($paths === []) { $unreached++; continue; }
            } elseif (str_contains($p, '[')) {
                $paths = [(string) preg_replace('~\[.*\]~', '', $p)];
            }

            foreach ($paths as $path) {
                foreach ([null, $member] as $who) {
                    $_SESSION = $who === null ? [] : ['user_id' => $who, 'user_name' => 'Render Member', 'user_email' => 'render@member.test'];
                    $_SESSION['csrf_token'] = 'render';
                    $who_ = $who ? ' (member)' : '';
                    try {
                        $code = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode();
                    } catch (HttpException $e) {
                        $code = $e->getCode();
                    } catch (\Throwable $e) {
                        $gone = self::destroyedTemplate($e);
                        if ($gone !== null) { $rebuild[$gone][] = $p; continue; }
                        $failed[] = $path . $who_ . ' threw ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300)
                                  . ' @ ' . str_replace(dirname(__DIR__, 2) . '/', '', $e->getFile()) . ':' . $e->getLine();
                        continue;
                    }
                    if ($code >= 500) $failed[] = $path . $who_ . ' answered ' . $code;
                    if ($code === 200) $rendered++;
                }
            }
        }
        $_SESSION = [];

        $this->assertSame([], $failed, "public pages that fail:\n  " . implode("\n  ", $failed));
        $this->assertGreaterThanOrEqual(110, $rendered, 'pages actually rendered — a floor that may only rise as phases rebuild');
        $this->assertLessThanOrEqual(self::UNREACHED_CEILING, $unreached, 'routes the sandbox cannot reach may only fall');
        ksort($rebuild);
        $this->assertLessThanOrEqual(self::AWAITING_REBUILD_CEILING, count($rebuild),
            "destroyed templates still rendered by a public route may only fall:\n  " . implode("\n  ", array_keys($rebuild)));
    }

    /**
     * The template a destroyed page tried to render, or null when this is some other fault.
     * Only a LoaderError naming a template that is NOT on disk counts: a template that exists
     * and fails to compile, or one a handler names by a typo, is a fault — and the typo case
     * is told apart by git, which knows the file once existed.
     */
    private static function destroyedTemplate(\Throwable $e): ?string
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if (!$e instanceof LoaderError) continue;
            if (!preg_match('/Unable to find template "([^"]+)"/', $e->getMessage(), $m)) return null;
            $root = dirname(__DIR__, 2);
            if (is_file($root . '/templates/' . $m[1])) return null;
            static $history = null;
            $history ??= (string) shell_exec('git -C ' . escapeshellarg($root) . ' log --diff-filter=D --name-only --format= -- templates 2>/dev/null');
            return str_contains($history, 'templates/' . $m[1]) ? $m[1] : null;
        }
        return null;
    }

    /** @return list<string> the path with its one placeholder filled from real rows */
    private function fill(string $p): array
    {
        if (preg_match_all('/\{([a-zA-Z_]+)(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/', $p, $m) !== 1) return [];
        if (preg_match('~\[/~', $p)) $p = (string) preg_replace('~\[/.*\]$~', '', $p);
        $name = $m[1][0];
        if (!preg_match('~/([a-z_-]+)/\{' . preg_quote($name, '~') . '~', $p, $seg)) return [];
        $col = match (true) {
            str_contains($name, 'slug') => 'slug',
            in_array($name, ['ref', 'reference', 'code'], true) => 'reference',
            str_contains($name, 'token') => 'token',
            default => 'id',
        };
        $base = str_replace('-', '_', $seg[1]);
        $tables = ['gates_' . $base, 'gates_' . rtrim($base, 's'), 'gates_' . $base . 's'];
        if (in_array($seg[1], ['nominee', 'nominees', 'profile', 'n', 'ballot'], true)) array_unshift($tables, 'gates_nominees');
        if (in_array($seg[1], ['registry', 'profiles', 'p'], true)) array_unshift($tables, 'gates_profiles');
        if (in_array($seg[1], ['awards', 'programme', 'programmes'], true)) array_unshift($tables, 'gates_award_programmes');
        if (in_array($seg[1], ['category', 'categories', 'vote'], true)) array_unshift($tables, 'gates_award_categories');
        if (in_array($seg[1], ['results', 'edition', 'editions', 'cycle'], true)) array_unshift($tables, 'gates_award_cycles');
        foreach ($tables as $t) {
            try {
                $v = DB::table($t)->orderBy('id')->limit(2)->pluck($col)->filter()->all();
                if ($v !== []) {
                    return array_map(static fn ($x) => (string) preg_replace('/\{[^{}]+(?:\{[^{}]*\}[^{}]*)*\}/', rawurlencode((string) $x), $p, 1), $v);
                }
            } catch (\Throwable) {}
        }
        return [];
    }
}
