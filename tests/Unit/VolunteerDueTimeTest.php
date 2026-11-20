<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Volunteer\Workspace;
use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A volunteer task's due date is shown in Nigerian time (19 Nov 2026).
 *
 * `gates_vol_tasks.due_at` is stored UTC (Tasks::due() goes through DisplayTime::toStored()),
 * and every screen and mail printed it with `Workspace::day()`, which formats in the process
 * zone — UTC. So a task set due at 00:30 on Saturday read "due Fri" to the volunteer, in the
 * reminder and on the staff list, an hour and a day out from what the lead typed.
 *
 * `Workspace::day()` must stay unconverted: meetings store the wall clock as typed, and the
 * same conversion there would move every meeting by an hour. So the sweep below asks the
 * rule — no stored due instant is formatted by anything but the display-zone helpers — rather
 * than the list of call sites fixed today.
 */
final class VolunteerDueTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DisplayTime::forget();
        DB::table('gates_settings')->where('key_name', 'display_timezone')->delete();
    }

    protected function tearDown(): void
    {
        DisplayTime::forget();
        parent::tearDown();
    }

    public function test_a_due_date_is_the_nigerian_day_not_the_utc_one(): void
    {
        // 23:30 UTC on Friday is 00:30 WAT on Saturday.
        $this->assertSame('Sat 21 Nov', Workspace::due('2026-11-20 23:30:00'));
        $this->assertSame('Sat 21 Nov, 00:30 WAT', Workspace::due('2026-11-20 23:30:00', true));
        $this->assertSame('', Workspace::due(null));
        $this->assertSame('', Workspace::due(''));
    }

    public function test_it_follows_the_configured_display_zone(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'display_timezone', 'value' => 'Africa/Nairobi']);
        DisplayTime::forget();
        $this->assertSame('Sat 21 Nov, 02:30 EAT', Workspace::due('2026-11-20 23:30:00', true));
    }

    public function test_the_wall_clock_helper_is_left_alone_for_meetings(): void
    {
        // Meetings store what was typed; converting them would move every meeting an hour.
        $this->assertSame('Fri 20 Nov', Workspace::day('2026-11-20 23:30:00'));
    }

    public function test_no_due_instant_is_formatted_in_the_process_zone(): void
    {
        $root = dirname(__DIR__, 2);
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            foreach (file($f->getPathname()) as $i => $line) {
                // Volunteer-task due instants: `Workspace::day(...due_at...)` or a bare date() of one.
                if (preg_match('/Workspace::(day|span)\([^;]*\b(t|k|r|task|\$r\[\'task\'\])->due_at/', $line)
                    || preg_match('/\bdate\(\s*\'[^\']*\'\s*,\s*\(int\)\s*strtotime\(\s*\(string\)\s*\$(t|k|r)->due_at/', $line)) {
                    $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . ($i + 1);
                }
            }
        }
        foreach (glob($root . '/templates/admin/volunteers*.twig') ?: [] as $f) {
            foreach (file($f) as $i => $line) {
                if (preg_match('/\b(due_at|handed_in_at)\|date\(/', $line)) $bad[] = substr($f, strlen($root) + 1) . ':' . ($i + 1);
            }
        }
        $this->assertSame([], $bad, 'A stored UTC due date is printed without converting it to the display zone.');
    }
}
