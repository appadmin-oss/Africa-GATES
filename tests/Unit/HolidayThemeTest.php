<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\HolidayTheme;
use AfricaGates\Support\Phone;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * A member's seasonal greeting (HOLIDAY-THEMES, handoff 5 Oct 2026).
 *
 * Held here: the ten themes are the spec's table; a greeting shows only inside a typed
 * window, only to the country it is for (a Nigerian national day never reaches a member
 * whose phone is Kenyan, nor one whose country is unknown); the later-starting window wins;
 * a dismissal is per theme per year; the member's switch turns all of them off; Nigeria's
 * age is computed; the teachers' line names an award only while it takes nominations; only
 * member pages may carry it; and the account frame draws it with a working dismiss form.
 */
final class HolidayThemeTest extends TestCase
{
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        HolidayTheme::forget();
        $this->uid = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Chioma Obi', 'email' => 'h-' . bin2hex(random_bytes(4)) . '@example.test', 'phone_e164' => '+2348031234567',
            'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        HolidayTheme::forget();
        parent::tearDown();
    }

    private function window(string $slug, string $from, string $to, ?string $cc = null, ?int $pid = null): void
    {
        DB::table('gates_holidays')->insert(['slug' => $slug, 'starts_on' => $from, 'ends_on' => $to,
            'country_code' => $cc, 'cta_programme_id' => $pid, 'active' => 1]);
    }

    private function at(string $day): Carbon
    {
        return Carbon::parse($day . ' 12:00:00');
    }

    public function test_the_ten_themes_are_the_spec_table(): void
    {
        $this->assertSame(['independence-ng', 'teachers', 'christmas', 'new-year', 'eid', 'easter', 'africa-day',
                           'democracy-ng', 'womens-day', 'childrens-day-ng'], array_keys(HolidayTheme::THEMES));
        $want = ['independence-ng' => ['green', 'stripes', 'flag'], 'teachers' => ['gold', 'ruled', 'book'],
                 'christmas' => ['green', 'snow', 'tree'], 'new-year' => ['gold', 'confetti', 'spark'],
                 'eid' => ['info', 'lattice', 'moon'], 'easter' => ['gold', 'sun', 'sunrise'],
                 'africa-day' => ['gold', 'africa', 'globe'], 'democracy-ng' => ['green', 'stripes', 'flag'],
                 'womens-day' => ['live', 'petals', 'flower'], 'childrens-day-ng' => ['info', 'balloons', 'balloon']];
        foreach ($want as $slug => [$tone, $pat, $glyph]) {
            $t = HolidayTheme::THEMES[$slug];
            $this->assertSame([$tone, $pat], [$t[1], $t[2]], $slug);
            $this->assertArrayHasKey($glyph, HolidayTheme::GLYPHS, $slug);
        }
        foreach (HolidayTheme::SVG_PATTERNS as $p) {
            $svg = (string) HolidayTheme::patternSvg($p, 'gold');
            $this->assertStringStartsWith('<svg', $svg, $p);
            $this->assertStringContainsString('#c99a06', $svg, "$p takes the tone's line from Accent");
        }
        $this->assertNull(HolidayTheme::patternSvg('confetti', 'purple'));
        $this->assertNull(HolidayTheme::patternSvg('stripes', 'gold'), 'a CSS pattern has no tile');
    }

    public function test_a_greeting_shows_only_inside_its_window_and_the_later_one_wins(): void
    {
        $this->window('independence-ng', '2026-10-01', '2026-10-01', 'NG');
        $this->window('teachers', '2026-09-28', '2026-10-06');
        $this->assertNull(HolidayTheme::forMember($this->uid, null, $this->at('2026-09-27')));
        $h = HolidayTheme::forMember($this->uid, null, $this->at('2026-10-01'));
        $this->assertSame('independence-ng', $h['slug'], 'the day inside the season wins');
        $this->assertSame('Happy Independence Day, Chioma', $h['greeting']);
        $this->assertStringContainsString('Celebrating 66 years of Nigeria', $h['line'], '2026 − 1960, computed');
        $this->assertSame('teachers', HolidayTheme::forMember($this->uid, null, $this->at('2026-10-02'))['slug']);
        $this->assertNull(HolidayTheme::forMember($this->uid, null, $this->at('2026-10-07')));
    }

    public function test_a_national_day_reaches_only_that_country(): void
    {
        $this->window('independence-ng', '2026-10-01', '2026-10-01', 'NG');
        $this->assertNotNull(HolidayTheme::forMember($this->uid, null, $this->at('2026-10-01')));

        DB::table('gates_users')->where('id', $this->uid)->update(['phone_e164' => '+254712345678']);
        HolidayTheme::forget();
        $this->assertNull(HolidayTheme::forMember($this->uid, 'NG', $this->at('2026-10-01')),
            'the phone outranks the CDN: a Kenyan number is not greeted for Nigeria');

        DB::table('gates_users')->where('id', $this->uid)->update(['phone_e164' => null]);
        HolidayTheme::forget();
        $this->assertNull(HolidayTheme::forMember($this->uid, null, $this->at('2026-10-01')), 'unknown is not Nigerian');
        HolidayTheme::forget();
        $this->assertNotNull(HolidayTheme::forMember($this->uid, 'NG', $this->at('2026-10-01')), 'no phone: the CDN decides');

        $this->assertSame('NG', Phone::country('+2348031234567'));
        $this->assertNull(Phone::country('+14155550100'), '+1 is two countries, so neither');
    }

    public function test_dismissed_for_the_year_and_the_members_own_switch(): void
    {
        $this->window('christmas', '2026-12-24', '2026-12-26');
        $this->window('christmas', '2027-12-24', '2027-12-26');
        $this->assertTrue(HolidayTheme::dismiss($this->uid, 'christmas', $this->at('2026-12-24')));
        $this->assertNull(HolidayTheme::forMember($this->uid, null, $this->at('2026-12-25')));
        $this->assertNotNull(HolidayTheme::forMember($this->uid, null, $this->at('2027-12-25')), 'next year asks again');
        $this->assertFalse(HolidayTheme::dismiss($this->uid, 'not-a-theme'));

        $this->assertTrue(HolidayTheme::setGreetings($this->uid, false));
        $this->assertNull(HolidayTheme::forMember($this->uid, null, $this->at('2027-12-25')));
    }

    public function test_the_teachers_line_names_an_award_only_while_it_takes_nominations(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['title' => 'Alimosho Impactful Leadership Awards',
            'slug' => 'alimosho-' . bin2hex(random_bytes(2)), 'is_active' => 1, 'sort_order' => 1]);
        $plain = HolidayTheme::view('teachers', 'Chioma', 2026, $pid);
        $this->assertSame('Someone taught you to aim higher.', $plain['line'], 'no open cycle, no claim');
        $this->assertSame('/nominate', $plain['href']);

        DB::table('gates_award_cycles')->insert(['programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'nominations',
            'nominations_open' => date('Y-m-d H:i:s', time() - 86400), 'nominations_close' => date('Y-m-d H:i:s', time() + 86400 * 20)]);
        $open = HolidayTheme::view('teachers', 'Chioma', 2026, $pid);
        $this->assertStringContainsString('Nominations are open for the Alimosho Impactful Leadership Awards.', $open['line']);
        $this->assertStringStartsWith('/nominate/alimosho-', $open['href']);
    }

    public function test_only_member_pages_carry_it(): void
    {
        foreach (['/account', '/account/points', '/account?tab=settings', '/my-work', '/org'] as $p) {
            $this->assertTrue(HolidayTheme::surfaceAllows((string) parse_url($p, PHP_URL_PATH)), $p);
        }
        foreach (['/', '/vote', '/vote/x', '/events/x/checkout', '/admin', '/judge', '/door', '/account/login', '/org/login', '/accounts'] as $p) {
            $this->assertFalse(HolidayTheme::surfaceAllows($p), $p);
        }
    }

    public function test_the_account_frame_draws_it_with_a_dismiss_that_posts(): void
    {
        $this->window('womens-day', date('Y-m-d'), date('Y-m-d'));
        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = $this->uid;
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $c = $b->build()->get(\AfricaGates\Controllers\AccountController::class);
        $html = (string) $c->dashboard((new ServerRequestFactory())->createServerRequest('GET', '/account'), new Response())->getBody();

        $this->assertStringContainsString('role="region" aria-label="International Women’s Day greeting"', $html);
        $this->assertStringContainsString('Happy Women’s Day, Chioma', $html);
        $this->assertStringContainsString('action="/account/holiday/womens-day/dismiss"', $html);
        $this->assertStringContainsString('/assets/css/components/holiday.css', $html);
        $this->assertLessThan(strpos($html, 'class="acx"'), strpos($html, 'class="hb-line'), 'the line and banner sit above the frame');

        $res = $c->holidayDismiss((new ServerRequestFactory())->createServerRequest('POST', '/account/holiday/womens-day/dismiss')
            ->withHeader('Accept', 'application/json'), new Response(), ['slug' => 'womens-day']);
        $this->assertSame(200, $res->getStatusCode());
        HolidayTheme::forget();
        $again = (string) $c->dashboard((new ServerRequestFactory())->createServerRequest('GET', '/account'), new Response())->getBody();
        $this->assertStringNotContainsString('Happy Women’s Day', $again);
        $this->assertStringNotContainsString('holiday.css', $again, 'no greeting, no stylesheet');
    }
}
