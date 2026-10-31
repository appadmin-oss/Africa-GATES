<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SpamService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Admin-configurable moderation thresholds: settings override the defaults,
 * clamps guarantee moderation can never be switched off by a typo, and the
 * per-process cache resets between requests/tests.
 */
final class SpamThresholdTest extends TestCase
{
    private function set(string $k, string $v): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        SpamService::resetThresholdCache();
    }

    public function test_defaults(): void
    {
        $t = SpamService::thresholds();
        $this->assertSame(0.30, $t['quarantine']);
        $this->assertSame(0.65, $t['reject']);
    }

    public function test_settings_override(): void
    {
        $this->set('mod_threshold_quarantine', '0.45');
        $this->set('mod_threshold_reject', '0.85');
        $t = SpamService::thresholds();
        $this->assertSame(0.45, $t['quarantine']);
        $this->assertSame(0.85, $t['reject']);
    }

    public function test_clamps_prevent_disabling_moderation(): void
    {
        // Absurd values: quarantine above the ceiling, reject BELOW quarantine.
        $this->set('mod_threshold_quarantine', '5');
        $this->set('mod_threshold_reject', '0.01');
        $t = SpamService::thresholds();
        $this->assertEqualsWithDelta(0.90, $t['quarantine'], 0.0001, 'quarantine capped at 0.90');
        $this->assertEqualsWithDelta(0.95, $t['reject'], 0.0001, 'reject forced above quarantine');

        $this->set('mod_threshold_quarantine', '-3');
        $this->set('mod_threshold_reject', '2');
        $t = SpamService::thresholds();
        $this->assertEqualsWithDelta(0.05, $t['quarantine'], 0.0001, 'quarantine floored at 0.05');
        $this->assertEqualsWithDelta(0.99, $t['reject'], 0.0001, 'reject capped at 0.99');
    }

    public function test_non_numeric_settings_fall_back_to_defaults(): void
    {
        $this->set('mod_threshold_quarantine', 'banana');
        $t = SpamService::thresholds();
        $this->assertSame(0.30, $t['quarantine']);
    }
}
