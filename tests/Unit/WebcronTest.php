<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Maintenance;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * `/__cron/run` — the only way a host with no shell cron runs periodic work.
 *
 * ── WHAT WAS REPORTED ────────────────────────────────────────────────────────
 *
 *     "the cron page 500s even at the time the whole site was 200"
 *
 * It did, on every single request, and none of the obvious suspects were involved.
 * The handler was declared `static function`, Slim binds a route callable to the
 * container before invoking it, `Closure::bindTo()` returns NULL for a static
 * closure (there is no `$this` to rebind), and Slim types that method's return as
 * `callable`. So every request died with
 *
 *     TypeError: bindToContainer(): Return value must be of type callable, null returned
 *
 * before a line of the handler ran. One keyword, a guaranteed 500, and indifferent
 * to whether anything else on the site worked — which is exactly why it read as
 * unrelated to every other symptom.
 *
 * A grep for `static function` cannot be the guard: the next version of this
 * mistake might be a first-class callable, an array callable, or an invokable
 * class. So these tests DISPATCH the real route through a real Slim app with the
 * real container, which is the only thing that exercises CallableResolver at all.
 *
 * ── AND THE SECOND FAILURE UNDERNEATH IT ─────────────────────────────────────
 *
 * Three maintenance tasks had no error handling — `pruneCache()`, `advanceCycles()`,
 * `purgeExpiredOtp()` — and two run on every tick. One missing table therefore
 * stopped the job queue draining, the cycles advancing, the payments reconciling
 * and the receipts sending, and the route answered 500 with the word "failed" while
 * putting the reason in a log an operator with no SSH cannot open.
 */
class WebcronTest extends TestCase
{
    private const TOKEN = 'cron-token-for-tests-1234';

    protected function setUp(): void
    {
        parent::setUp();
        // Pinned in the ENVIRONMENT, not only in gates_settings. The guard prefers
        // CRON_TOKEN from the environment and only falls back to the settings row, so a
        // developer with a CRON_TOKEN in their own .env would otherwise silently shadow
        // the fixture and every dispatch here would 404 for a reason unrelated to the
        // thing under test. Env::get() reads $_SERVER and $_ENV live, so this is
        // authoritative for the duration of the test.
        $_ENV['CRON_TOKEN'] = $_SERVER['CRON_TOKEN'] = self::TOKEN;
        DB::table('gates_settings')->insert(['key_name' => 'cron_token', 'value' => self::TOKEN]);

        // The route's single-instance lock is held for the lifetime of the PROCESS, which
        // in production is one request or one cron run — but here is the whole test file.
        // Without this the first dispatch keeps the lock and every later one returns
        // `{"ok":true,"skipped":"another run in progress"}`: a 200 that looks like success
        // for a run that never happened, so an assertion about a failing task would pass
        // against nothing at all.
        \AfricaGates\Support\CronGuard::releaseAll();
    }

    protected function tearDown(): void
    {
        unset($_ENV['CRON_TOKEN'], $_SERVER['CRON_TOKEN']);
        \AfricaGates\Support\CronGuard::releaseAll();
        parent::tearDown();
    }

    /**
     * A real Slim app with the real container and the real routes file — the same
     * wiring public/index.php builds. Nothing here is a double: CallableResolver is
     * what the `static` bug lived in, so a stubbed dispatcher would prove nothing.
     *
     * @param array<string,string> $query
     * @return array{0:int, 1:string}
     */
    private function dispatch(string $path, array $query = [], string $method = 'GET'): array
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $container = $builder->build();

        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        // withQueryParams EXPLICITLY: Slim\Psr7's factory does not populate query params
        // from the URI's query string, so a `?token=` in the path alone leaves
        // getQueryParams() empty and every request 404s at the guard — which looks
        // exactly like the token being wrong.
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://afg.test' . $path . ($query ? '?' . http_build_query($query) : ''))
            ->withQueryParams($query);
        $res = $app->handle($req);

        return [$res->getStatusCode(), (string) $res->getBody()];
    }

    // ── The reported failure ─────────────────────────────────────────────────

    /**
     * THE REGRESSION. A route handler Slim cannot bind is a 500 before any of its
     * own code runs, so this must be dispatched rather than inspected.
     */
    public function test_the_cron_route_is_dispatchable_at_all(): void
    {
        [$status, $body] = $this->dispatch('/__cron/run', ['token' => self::TOKEN]);

        $this->assertNotSame(500, $status,
            "the handler could not be invoked. A `static function` route callable does this: "
            . "Slim binds route callables to the container and Closure::bindTo() returns null "
            . "for a static closure, which fails CallableResolver's `callable` return type.");
        $this->assertSame(200, $status);
        $this->assertJson($body);
    }

    public function test_the_same_handler_works_on_post(): void
    {
        // Registered for both verbs, and a webcron service may use either.
        [$status] = $this->dispatch('/__cron/run', ['token' => self::TOKEN], 'POST');
        $this->assertSame(200, $status);
    }

    /** Invisible without the secret — a 404, not a 401, so the path itself is not confirmed. */
    public function test_it_is_invisible_without_the_token(): void
    {
        $this->assertSame(404, $this->dispatch('/__cron/run')[0]);
        $this->assertSame(404, $this->dispatch('/__cron/run', ['token' => 'wrong'])[0]);
        // Short tokens are refused outright, so a blank setting can never unlock it.
        $this->assertSame(404, $this->dispatch('/__cron/run', ['token' => 'abc'])[0]);
    }

    // ── One broken task must not stop the rest ───────────────────────────────

    /**
     * The all-or-nothing failure, pinned.
     *
     * A cache prune is the least consequential thing in the run. It used to be able
     * to stop the job queue, the cycle lifecycle, the payment reconciliation and the
     * receipts — every piece of periodic work on the platform — because it happened
     * to be third in an unguarded list.
     */
    public function test_a_broken_task_does_not_stop_the_others(): void
    {
        DB::statement('DROP TABLE gates_cache');

        $r = (new Maintenance(null, false))->run('auto');

        $ran = [];
        foreach ($r['ran'] as [$name, $result]) $ran[$name] = $result;

        $this->assertSame(Maintenance::TASK_FAILED, $ran['cache'] ?? null, 'cache should report failure');
        foreach (['queue', 'cycles', 'payments', 'checkout-mail'] as $task) {
            $this->assertArrayHasKey($task, $ran, $task . ' never ran — one broken task aborted the run');
            $this->assertNotSame(Maintenance::TASK_FAILED, $ran[$task], $task . ' should have been unaffected');
        }
    }

    /**
     * A failure that reports 0 is a failure nobody sees. Almost every task returns 0
     * on a normal run ("nothing to do"), so a crash collapsing into 0 is precisely
     * how a broken cron looks healthy for weeks.
     */
    public function test_a_failure_is_distinguishable_from_nothing_to_do(): void
    {
        DB::statement('DROP TABLE gates_cache');
        $r = (new Maintenance(null, false))->run('cache');

        $this->assertNotSame(0, $r['ran'][0][1]);
        $this->assertSame(Maintenance::TASK_FAILED, $r['ran'][0][1]);
    }

    public function test_the_reason_is_reported_not_swallowed(): void
    {
        DB::statement('DROP TABLE gates_cache');
        $r = (new Maintenance(null, false))->run('cache');

        $this->assertArrayHasKey('cache', $r['failures']);
        $this->assertStringContainsStringIgnoringCase('gates_cache', $r['failures']['cache'],
            'the message must name what is actually missing — an operator with no SSH has '
            . 'only this response to work from');
    }

    /** A clean run says so, with the key present rather than absent. */
    public function test_a_healthy_run_reports_no_failures(): void
    {
        $r = (new Maintenance(null, false))->run('auto');

        $this->assertSame([], $r['failures'],
            'present and empty, so a caller never has to test whether the key exists');
    }

    // ── What the endpoint tells the operator ─────────────────────────────────

    /**
     * A PARTIAL run is a 200, deliberately.
     *
     * A webcron service that sees a persistent 500 backs off or disables the job — so
     * the previous behaviour meant one broken task eventually stopped the tasks that
     * still worked. The run completed; the body says what failed.
     */
    public function test_a_partial_run_answers_200_with_ok_false_and_the_reasons(): void
    {
        DB::statement('DROP TABLE gates_cache');

        [$status, $body] = $this->dispatch('/__cron/run', ['token' => self::TOKEN]);
        $json = json_decode($body, true);

        $this->assertSame(200, $status, 'a 200 keeps the scheduler firing for the healthy tasks');
        $this->assertFalse($json['ok'], 'but it must not claim success');
        $this->assertArrayHasKey('cache', $json['failures'] ?? []);
        $this->assertStringContainsStringIgnoringCase('gates_cache', $json['failures']['cache']);
    }

    /**
     * The cron log must not report success for a run that partly failed — the admin
     * console reads that column, so a hardcoded 'success' was a false all-clear.
     */
    public function test_the_cron_log_records_a_partial_run_as_an_error(): void
    {
        DB::statement('DROP TABLE gates_cache');
        (new Maintenance(null, false))->run('auto');

        $row = DB::table('gates_cron_log')->orderByDesc('id')->first();
        $this->assertSame('error', (string) $row->status);
        $this->assertStringContainsString('failures', (string) $row->message);
    }

    public function test_a_healthy_run_is_logged_as_success(): void
    {
        (new Maintenance(null, false))->run('auto');

        $row = DB::table('gates_cron_log')->orderByDesc('id')->first();
        $this->assertSame('success', (string) $row->status);
    }

    // ── Every task named in the route's documentation actually exists ────────

    /**
     * `?task=` is documented in routes.php and in the deployment guides, and an
     * unrecognised name silently does NOTHING but still answers 200 — so a typo in a
     * cPanel cron entry looks like a working schedule forever.
     */
    public function test_every_documented_named_task_is_recognised(): void
    {
        foreach (['cycles', 'cpi', 'cache', 'queue', 'otp', 'magic', 'collusion', 'payments', 'checkout-mail', 'digest'] as $task) {
            $r = (new Maintenance(null, false))->run($task);
            $this->assertNotSame([], $r['ran'],
                "task '{$task}' is documented but ran nothing — check the match() arm exists");
        }
    }

    public function test_an_unknown_task_is_reported_rather_than_silently_ignored(): void
    {
        $r = (new Maintenance(null, false))->run('not-a-task');

        $this->assertSame([], $r['ran']);
        $this->assertNotEmpty(
            array_filter($r['lines'], static fn ($l) => str_contains($l, 'Unknown task')),
            'a mistyped task name must leave a trace, or a broken cron entry passes as healthy'
        );
    }
}
