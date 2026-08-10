<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * GET /__setup/payments — "the status is wrong, or the transaction is not there at all".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO FAULTS THAT LOOK IDENTICAL FROM OUTSIDE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Reported from a live site, and it is really two reports:
 *
 *   STATUS WRONG   the row exists and says pending for a payment that succeeded.
 *                  Nothing ever re-checked it: the browser callback is lost every
 *                  time somebody pays inside a wallet app and never comes back, and
 *                  the sweep that exists to catch exactly that runs only from the
 *                  maintenance schedule. If that schedule never runs, every dropped
 *                  payment stays wrong for ever.
 *
 *   NO ROW AT ALL  money at Paystack this platform never recorded. Nothing starting
 *                  from our own tables can find it, because there is nothing to
 *                  iterate — it needs the comparison run from the GATEWAY's side.
 *
 * The fixes are unrelated, so guessing between them wastes the time of whoever is
 * out of pocket. This page decides it from facts: the local half needs no network
 * call and usually answers it outright, and the gateway pull is there for when it
 * does not.
 */
final class PaymentsDiagnosticTest extends TestCase
{
    private const TOKEN = 'paymentsdiagtoken1234';

    private function get(string $qs): \Psr\Http\Message\ResponseInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/__setup/payments' . $qs)
        );
    }

    private function body(string $qs = ''): string
    {
        return (string) $this->get('?token=' . self::TOKEN . $qs)->getBody();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['SETUP_TOKEN'] = self::TOKEN;
    }

    protected function tearDown(): void
    {
        unset($_ENV['SETUP_TOKEN']);
        parent::tearDown();
    }

    public function test_it_is_invisible_without_the_token(): void
    {
        $this->assertSame(404, $this->get('')->getStatusCode());
        $this->assertSame(404, $this->get('?token=wrong')->getStatusCode());
    }

    // ── the local half, which answers it without a network call ─────────────

    /**
     * A pending row six days old is the "status is wrong" fault, and the page must
     * say so rather than just counting it. A count is a fact; naming the fault is
     * what stops somebody looking in the wrong place.
     */
    public function test_a_long_stuck_pending_row_is_named_as_the_wrong_status_fault(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Stale', 'donor_email' => 's@example.test', 'amount_naira' => 1000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'payment_ref' => 'AFG-PVOTE-stale00001', 'status' => 'pending',
            'created_at' => Carbon::now()->subDays(6)->toDateTimeString(),
        ]);

        $html = $this->body();

        $this->assertStringContainsString('Stuck as pending', $html);
        $this->assertStringContainsString('144h old', $html,
            'the age must be a whole number of hours — Carbon 3 returns a float and '
            . '"144.00020017861h" reads as a bug in the page');
        $this->assertStringContainsString('never re-checked', $html);
    }

    /** A fresh pending row is a checkout in progress, and must NOT read as a fault. */
    public function test_a_fresh_pending_row_is_not_reported_as_a_problem(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Live', 'donor_email' => 'l@example.test', 'amount_naira' => 1000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'payment_ref' => 'AFG-PVOTE-fresh00001', 'status' => 'pending',
            'created_at' => Carbon::now()->subMinutes(3)->toDateTimeString(),
        ]);

        $html = $this->body();

        $this->assertStringContainsString('checkout in progress', $html);
        $this->assertStringNotContainsString('never re-checked', $html,
            'a three-minute-old checkout is being reported as a stale payment');
    }

    /**
     * Money settled and no votes credited is the worst bucket the platform has, and
     * it is invisible in a status count — the row says `confirmed`, which reads fine.
     */
    public function test_confirmed_but_never_minted_is_surfaced(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Owed', 'donor_email' => 'o@example.test', 'amount_naira' => 2000,
            'tier' => 'paid-vote', 'bonus_votes' => 12, 'votes_used' => 0,
            'intent_nominee_id' => 1, 'payment_ref' => 'AFG-PVOTE-owed00001',
            'status' => 'confirmed', 'created_at' => Carbon::now()->subDays(2)->toDateTimeString(),
        ]);

        $html = $this->body();

        $this->assertStringContainsString('Paid but no votes credited', $html);
        $this->assertStringContainsString('1 order(s)', $html);
    }

    /**
     * And it points at the sweep, because "nothing re-checks payments" is the single
     * most likely reason a status is wrong on a host where cron was never set up.
     */
    public function test_it_reports_whether_the_re_check_sweep_runs_at_all(): void
    {
        $html = $this->body();

        $this->assertStringContainsString('The re-check sweep', $html);
        $this->assertStringContainsString('/__setup/assistant', $html,
            'it must hand off to the page that says whether scheduled work happens');
    }

    // ── the gateway half ────────────────────────────────────────────────────

    /**
     * The pull costs a series of live API calls and is the only part that could be
     * slow, so it happens only when asked for — a URL gets prefetched and retried.
     */
    public function test_the_gateway_comparison_is_opt_in(): void
    {
        $this->assertStringContainsString('&amp;pull=1', $this->body(),
            'the page must say how to run the comparison');
        $this->assertStringNotContainsString('Both sides agree', $this->body(),
            'the comparison ran without being asked for');
    }

    /**
     * With no Paystack key — the case in tests, and on any site not yet configured —
     * it says so plainly instead of rendering an empty comparison that reads as
     * "nothing is wrong".
     */
    public function test_with_no_gateway_configured_it_says_so_rather_than_showing_nothing(): void
    {
        $html = $this->body('&pull=1');

        $this->assertStringContainsString('Paystack configured here', $html);
        $this->assertStringContainsString('nothing can be compared', $html);
    }

    /** Each bucket must come with what to DO about it, not just a number. */
    public function test_it_says_what_to_do_with_each_answer(): void
    {
        $html = $this->body();

        $this->assertStringContainsString('What to do with each answer', $html);
        // The one nothing can fix automatically, and the one most likely to be
        // misread as a bug in this platform.
        $this->assertStringContainsString('Payment Page', $html,
            'a charge with no record here is usually a payment taken outside this '
            . 'checkout, and the page must say so or somebody hunts for a bug that is not there');
        $this->assertStringContainsString('invent the order', $html,
            'and that no automatic sweep can conjure a missing order — only a person can attach it');
    }

    /** READ-ONLY, asserted rather than assumed: this is a page about money. */
    public function test_it_changes_nothing(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Untouched', 'donor_email' => 'u@example.test', 'amount_naira' => 1000,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'payment_ref' => 'AFG-PVOTE-readonly01', 'status' => 'pending',
            'created_at' => Carbon::now()->subDays(3)->toDateTimeString(),
        ]);
        $before = [
            'rows'   => DB::table('gates_donations')->count(),
            'status' => DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-readonly01')->value('status'),
            'jobs'   => DB::table('gates_jobs')->count(),
        ];

        $this->body('&pull=1');

        $this->assertSame($before['rows'], DB::table('gates_donations')->count());
        $this->assertSame($before['status'],
            DB::table('gates_donations')->where('payment_ref', 'AFG-PVOTE-readonly01')->value('status'),
            'looking at a payment changed its status');
        $this->assertSame($before['jobs'], DB::table('gates_jobs')->count());
    }
}
