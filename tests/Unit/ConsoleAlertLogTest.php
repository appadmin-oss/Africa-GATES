<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ConsoleAlertLog;
use AfricaGates\Admin\Services\ConsoleAlerts;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The console's alert log (AdminConsole v2, 20 Oct 2026): first seen, resolved, reopened,
 * owner, timeline — and the two rules that make it trustworthy:
 *
 *   · RESOLVED IS WRITTEN BY THE CHECK, NEVER BY A PERSON. An alert leaves the open list
 *     only when its check passes; there is no close button to lie with.
 *   · A CHECK THAT COULD NOT RUN IS NOT A CHECK THAT PASSED. A probe that throws is
 *     unknown, and the log must not record its alert as fixed.
 */
final class ConsoleAlertLogTest extends TestCase
{
    private function alert(string $key, string $impact = 'affects'): array
    {
        return ['key' => $key, 'severity' => 'high', 'impact' => $impact, 'title' => "The $key check fails",
                'source' => 'Platform', 'raised' => null];
    }

    private function row(string $key): ?object
    {
        return DB::table('gates_console_alerts')->where('alert_key', $key)->first();
    }

    public function test_an_alert_is_raised_resolved_and_reopened_by_its_check(): void
    {
        $this->assertTrue(ConsoleAlertLog::ready());
        ConsoleAlertLog::sync([$this->alert('mail')], [], '2026-10-20 09:00:00');
        $this->assertSame('2026-10-20 09:00:00', $this->row('mail')->first_seen_at);
        $this->assertNull($this->row('mail')->resolved_at);

        ConsoleAlertLog::sync([], [], '2026-10-20 10:00:00');
        $this->assertSame('2026-10-20 10:00:00', $this->row('mail')->resolved_at, 'its check passed, so it resolved');

        ConsoleAlertLog::sync([$this->alert('mail')], [], '2026-10-20 11:00:00');
        $this->assertNull($this->row('mail')->resolved_at);
        $this->assertSame(1, (int) $this->row('mail')->reopened_count, 'coming back is counted, not hidden');

        $this->assertSame(['raised', 'resolved', 'reopened'], array_column(ConsoleAlertLog::timeline('mail'), 'kind'));
    }

    public function test_a_check_that_could_not_run_does_not_resolve_its_alert(): void
    {
        ConsoleAlertLog::sync([$this->alert('cron')], [], '2026-10-20 09:00:00');
        ConsoleAlertLog::sync([], ['cron'], '2026-10-20 10:00:00');
        $this->assertNull($this->row('cron')->resolved_at, 'an unknown is never written down as fixed');
    }

    public function test_resolved_lists_the_last_seven_days_only(): void
    {
        $old = date('Y-m-d H:i:s', time() - 9 * 86400);
        $recent = date('Y-m-d H:i:s', time() - 3600);
        ConsoleAlertLog::sync([$this->alert('mail'), $this->alert('webhooks', '')], [], $old);
        ConsoleAlertLog::sync([$this->alert('webhooks', '')], [], $old);     // mail resolves, nine days ago
        ConsoleAlertLog::sync([], [], $recent);                              // webhooks resolves, an hour ago
        $this->assertSame(['webhooks'], array_column(ConsoleAlertLog::resolvedSince(7), 'key'));
    }

    public function test_assign_to_me_takes_an_open_alert_and_puts_it_on_the_timeline(): void
    {
        $admin = (int) DB::table('gates_admins')->insertGetId([
            'email' => 'tunde@demo.invalid', 'name' => 'Tunde Akin', 'password_hash' => 'x', 'role' => 'admin', 'is_active' => 1,
        ]);
        ConsoleAlertLog::sync([$this->alert('callbacks')], []);
        $this->assertTrue(ConsoleAlertLog::assign('callbacks', $admin));
        $a = ConsoleAlertLog::annotate([$this->alert('callbacks')])[0];
        $this->assertSame('Tunde Akin', $a['owner']);
        $this->assertSame('assigned', array_column(ConsoleAlertLog::timeline('callbacks'), 'kind')[1]);
        $this->assertSame('Tunde Akin', ConsoleAlertLog::timeline('callbacks')[1]['who']);

        ConsoleAlertLog::sync([], []);
        $this->assertFalse(ConsoleAlertLog::assign('callbacks', $admin), 'a resolved alert has nothing to take');
    }

    public function test_every_alert_says_its_production_impact_and_what_to_do(): void
    {
        DB::table('gates_support_tickets')->insert(['reference' => 'T9', 'subject' => 'Chargeback on AFG-9', 'status' => 'open']);
        ConsoleAlerts::reset();
        $open = ConsoleAlerts::open();
        $this->assertNotEmpty($open);
        foreach ($open as $a) {
            $this->assertContains($a['impact'], ['affects', 'may', null], $a['key']);
            if ($a['impact'] !== null) $this->assertNotSame('', $a['who'], $a['key'] . ' affects people but does not say who');
            $this->assertNotEmpty($a['steps'], $a['key'] . ' says what is wrong but not what to do');
            $this->assertContains($a['key'], ConsoleAlerts::keys());
        }
    }
}
