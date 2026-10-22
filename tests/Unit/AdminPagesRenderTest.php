<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Every admin page a superadmin can open renders — none answers 500.
 *
 * Asked of the router, not of a list: every GET route under /admin, over the seeded sandbox
 * (real programmes, nominees, nominations, interviews), with a record page opened for the
 * first rows of the table its path names. A page that 500s only once it has data is the
 * shape this catches; a list of pages somebody remembered would not.
 *
 * What it cannot see, stated rather than implied: POSTs, pages keyed on something the
 * sandbox does not mint (those are counted, and the count may only fall), and anything that
 * fails only under MySQL — run it through scripts/mysql-parity.sh for that.
 *
 * Three things made the first draft of this lie, each worth knowing:
 *   · /admin/logout is a GET, and visiting it signs the test out — every page after it
 *     "passed" as a redirect to the login screen. Sign-in routes are skipped and the session
 *     is restored before every request.
 *   · `{id:[0-9]+}` contains a `[`, so a test for the optional-segment form must look for
 *     `[/`, not for `[` — or it skips every record page while reporting a clean pass.
 *   · A 302 is not a pass. The test asserts how many pages actually rendered.
 */
final class AdminPagesRenderTest extends TestCase
{
    /** Pages keyed on something the sandbox does not mint. May only fall. */
    private const UNREACHED_CEILING = 50;

    public function test_every_admin_page_renders_for_a_superadmin(): void
    {
        $id = (int) DB::table('gates_admins')->insertGetId(['email' => 'pages@render.test', 'name' => 'Render',
                                                           'role' => 'superadmin', 'is_active' => 1]);
        $signIn = static function () use ($id): void {
            $_SESSION['admin_id'] = $id; $_SESSION['admin_role'] = 'superadmin'; $_SESSION['admin_name'] = 'Render';
            $_SESSION['csrf_token'] = 'render';
        };
        $signIn();

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        DemoSeeder::seed($id);
        // A same-named second nominee, so the duplicate tooling has something to show.
        $n = DB::table('gates_nominees')->orderBy('id')->first();
        if ($n) DB::table('gates_nominees')->insert(['category_id' => $n->category_id, 'name' => $n->name, 'status' => 'approved']);

        $failed = []; $rendered = 0; $unreached = 0;
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (!in_array('GET', $r->getMethods(), true)) continue;
            $p = $r->getPattern();
            if (!str_starts_with($p, '/admin') || preg_match('~^/admin/(logout|login|magic)~', $p)) continue;

            $paths = [$p];
            if (str_contains($p, '{')) {
                if (preg_match_all('/\{[^}]+\}/', $p) !== 1 || preg_match('~\[/~', $p)
                    || !preg_match('~/([a-z_-]+)/\{(id|ref)[^}]*\}~', $p, $seg)) { $unreached++; continue; }
                $vals = $this->rowsFor($seg[1], $seg[2] === 'ref' ? 'reference' : 'id');
                if ($vals === []) { $unreached++; continue; }
                $paths = array_map(static fn ($v) => (string) preg_replace('/\{[^}]+\}/', (string) $v, $p), $vals);
            } elseif (str_contains($p, '[')) {
                $unreached++;
                continue;
            }

            foreach ($paths as $path) {
                $signIn();
                try {
                    $code = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode();
                } catch (\Throwable $e) {
                    $failed[] = $path . ' threw ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
                    continue;
                }
                if ($code >= 500) $failed[] = $path . ' answered ' . $code;
                if ($code === 200) $rendered++;
            }
        }

        $this->assertSame([], $failed, "admin pages that fail:\n" . implode("\n", $failed));
        $this->assertGreaterThan(80, $rendered, 'pages actually rendered — a redirect to the login screen is not a pass');
        $this->assertLessThanOrEqual(self::UNREACHED_CEILING, $unreached, 'record pages the sandbox cannot reach may only fall');
    }

    /** @return list<int|string> the first rows of the table a path segment names */
    private function rowsFor(string $segment, string $column): array
    {
        if ($segment === 'profiles') return DB::table('gates_nominees')->orderBy('id')->limit(3)->pluck('id')->all();
        $base = str_replace('-', '_', $segment);
        foreach (['gates_' . $base, 'gates_' . rtrim($base, 's'), 'gates_' . $base . 's'] as $t) {
            try {
                $v = DB::table($t)->orderBy('id')->limit(3)->pluck($column)->all();
                if ($v !== []) return $v;
            } catch (\Throwable) {}
        }
        return [];
    }
}
