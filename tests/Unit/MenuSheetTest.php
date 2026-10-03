<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SystemStatus;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The phone Menu (REFERENCE §7.4, MobileMenu.dc.html), rebuilt in Phase 2 — the rules that
 * are about what it SAYS, rendered.
 *
 *  · The Explore list is §7.4's seven, in order. The DC draws Leaderboard where the phase
 *    file says Blog; the phase file outranks the DC.
 *  · Status says what was MEASURED or nothing. The DC types "Working"; a typed all-clear
 *    is the cached status that outlives the outage it missed. The row reads the last
 *    recorded check (SystemStatus::light()) and is silent when that record is stale.
 *  · The Cookies row is the hook the consent preferences re-open through
 *    (`data-ag-do="consent-open"` → `/cookies#choices`), and Sign out is a POST carrying
 *    the token — never a link a prefetch can follow.
 */
final class MenuSheetTest extends TestCase
{
    private const MEMBER = ['user_id' => 1, 'user_name' => 'Chioma Obi'];

    private function menu(array $session = []): string
    {
        $html = ChromeRender::html('/_dev/ui', $session);
        $at = (int) strpos($html, 'data-ag-menu-sheet');
        $this->assertGreaterThan(0, $at, 'the Menu is not on the page');

        return substr($html, $at, (int) strpos($html, 'data-ag-quick-sheet', $at) - $at);
    }

    public function test_the_explore_list_is_the_phase_files_seven(): void
    {
        $menu = $this->menu();
        $explore = substr($menu, (int) strpos($menu, 'id="ag-menu-h-explore"'));
        $explore = substr($explore, 0, (int) strpos($explore, '</section>'));
        preg_match_all('~<a class="ag-list__row" href="([^"]+)"~', $explore, $m);
        $this->assertSame(['/discover', '/pulse', '/giving', '/shop', '/legacy', '/blog', '/account/register'], $m[1]);
    }

    public function test_status_says_the_recorded_state_and_nothing_when_it_is_stale(): void
    {
        $this->assertStringNotContainsString('ag-menu__live', $this->menu(), 'no record, yet a state was drawn');

        DB::table('gates_status_log')->insert([
            'taken_at' => Carbon::now()->subMinutes(5)->toDateTimeString(),
            'overall' => SystemStatus::OK, 'components_json' => '[]',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->assertMatchesRegularExpression('~ag-menu__live--operational"><span class="ag-menu__dot" aria-hidden="true"></span>Working</span>~',
            $this->menu(), 'a fresh record of "operational" must read "Working", in words');

        DB::table('gates_status_log')->update(['taken_at' => Carbon::now()->subHours(3)->toDateTimeString()]);
        $this->assertStringNotContainsString('ag-menu__live', $this->menu(),
            'a three-hour-old "Working" is the cached all-clear that outlives an outage');
    }

    public function test_cookies_is_the_consent_hook_and_sign_out_is_a_post(): void
    {
        $this->assertMatchesRegularExpression('~<a href="/cookies#choices" data-ag-do="consent-open">~', $this->menu());

        $in = $this->menu(self::MEMBER);
        $this->assertMatchesRegularExpression('~<form method="post" action="/account/logout"[^>]*>\s*<input type="hidden" name="_token" value="test-token">~', $in);
        $this->assertStringNotContainsString('Sign out', $this->menu(), 'a signed-out visitor is offered Sign out');
    }
}
