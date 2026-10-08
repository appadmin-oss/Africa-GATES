<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\SettingsController;
use AfricaGates\Admin\Services\{AuditService, SettingsService};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * "Run maintenance now" and "Reconcile payments" take the scheduler's lock.
 *
 * Both buttons ran the orchestrator with no CronGuard at all, so pressed during a tick
 * two passes went side by side over every sweep that reads "not done yet" and then acts —
 * the same reminder mailed twice, the same refund asked for twice. The CLI cron and
 * /__cron/run both take `maintenance`; this was the third door into the same work.
 */
final class SettingsRunCronLockTest extends TestCase
{
    private string $lock = '';
    /** @var resource|null */
    private $held = null;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['admin_id' => 1];
        $dir = dirname(__DIR__, 2) . '/var/data';
        @mkdir($dir, 0775, true);
        $this->lock = $dir . '/.gates-maintenance.lock';
        // An earlier test in the same process (the webcron tick) may still hold it through
        // CronGuard's static handles; this test needs to be the one holding it.
        \AfricaGates\Support\CronGuard::releaseAll();
        // Another run holds it — a separate open file description, exactly as a second
        // process would, so flock() refuses it within this process too.
        $this->held = fopen($this->lock, 'c');
        $this->assertTrue(flock($this->held, LOCK_EX | LOCK_NB), 'could not take the lock to set this test up');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->held)) { flock($this->held, LOCK_UN); fclose($this->held); }
        // Left behind, this file makes MaintenanceTest fail in the full suite only.
        @unlink($this->lock);
        \AfricaGates\Support\CronGuard::releaseAll();
        parent::tearDown();
    }

    private function ctrl(): SettingsController
    {
        return new SettingsController($this->createStub(\Slim\Views\Twig::class), new SettingsService(), new AuditService());
    }

    private function req(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody([]);
    }

    public function test_run_now_does_not_start_a_second_pass_beside_a_running_one(): void
    {
        $before = (int) DB::table('gates_cron_log')->count();

        $this->ctrl()->runCron($this->req('/admin/settings/run-cron'), new Response());

        $this->assertStringContainsString('already running', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame($before, (int) DB::table('gates_cron_log')->count(), 'a maintenance pass ran beside the held lock');
    }

    public function test_reconcile_does_not_start_beside_a_running_pass(): void
    {
        $before = (int) DB::table('gates_cron_log')->count();

        $this->ctrl()->reconcilePayments($this->req('/admin/settings/reconcile'), new Response());

        $this->assertStringContainsString('running right now', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame($before, (int) DB::table('gates_cron_log')->count());
    }
}
