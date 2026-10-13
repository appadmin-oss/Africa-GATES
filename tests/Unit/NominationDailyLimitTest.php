<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NominationRules;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "The admin does not have control over the rate limit set for the daily nomination"
 * (owner, 13 Oct 2026). It was the literal 5 on both doors and inside the refusal sentence.
 */
final class NominationDailyLimitTest extends TestCase
{
    public function test_the_default_the_setting_and_the_bounds(): void
    {
        $this->assertSame(5, NominationRules::dailyLimit());
        DB::table('gates_settings')->updateOrInsert(['key_name' => NominationRules::DAILY_LIMIT_KEY], ['value' => '40']);
        $this->assertSame(40, NominationRules::dailyLimit());
        $this->assertSame(1, NominationRules::dailyLimit([NominationRules::DAILY_LIMIT_KEY => '0']), 'nought would refuse everybody');
        $this->assertSame(NominationRules::DAILY_LIMIT_MAX, NominationRules::dailyLimit([NominationRules::DAILY_LIMIT_KEY => '999999']));
        $this->assertSame(5, NominationRules::dailyLimit([NominationRules::DAILY_LIMIT_KEY => '']), 'a cleared field is the default');
    }

    /** Every door reads the resolver; no rate-limit call on the nominate bucket types a number. */
    public function test_no_door_carries_its_own_number(): void
    {
        $doors = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($f->getPathname());
            if (!preg_match_all("~->check\\(\\s*\\\$\\w+\\s*,\\s*'nominate'\\s*,\\s*([^,]+),~", $src, $m)) continue;
            foreach ($m[1] as $arg) {
                $doors++;
                $this->assertDoesNotMatchRegularExpression('~^\\s*\\d+\\s*$~', $arg,
                    $f->getFilename() . " limits nominations with a typed number ({$arg}) instead of NominationRules::dailyLimit()");
            }
        }
        $this->assertGreaterThanOrEqual(2, $doors, 'the form door and the API door');
    }

    public function test_the_settings_screen_offers_it(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');
        $this->assertStringContainsString('name="nomination_daily_limit"', $tpl);
        $this->assertMatchesRegularExpression('~name="nomination_daily_limit"[^>]*min="1"~', $tpl, 'the form never offers nought');
    }
}
