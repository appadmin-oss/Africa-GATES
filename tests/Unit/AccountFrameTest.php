<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AccountRail;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The member account's frame — AccountPage.dc.html (handoff 5 Oct 2026).
 *
 * The owner reported (8 Oct 2026) that the account "displays in mobile style" on a desktop.
 * It did: Phase 8 drew one 720px column at every width. Held here: every account page draws
 * the rail with its own section current; every section is an address; the anchors the
 * controller's redirects land on are on the section that holds them; the phone keeps a way
 * into every section; and the stylesheet has a rail-and-content grid from 600 and no
 * single-column cap left over from the old page.
 */
final class AccountFrameTest extends TestCase
{
    private function controller(): \AfricaGates\Controllers\AccountController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Controllers\AccountController::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $uid = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Chioma Obi', 'email' => 'c-' . bin2hex(random_bytes(4)) . '@example.test',
            'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = $uid;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        parent::tearDown();
    }

    private function get(string $method, string $uri): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        $res = $this->controller()->{$method}($req->withQueryParams($q), new Response());
        $this->assertSame(200, $res->getStatusCode(), $uri);

        return (string) $res->getBody();
    }

    public function test_every_account_page_draws_the_rail_with_its_own_section_current(): void
    {
        $pages = [
            ['dashboard', '/account', 'overview'],
            ['dashboard', '/account?tab=referral', 'referral'],
            ['dashboard', '/account?tab=security', 'security'],
            ['dashboard', '/account?tab=settings', 'settings'],
            ['points', '/account/points', 'points'],
            ['notifications', '/account/notifications', 'activity'],
            ['display', '/account/display', 'settings'],
        ];
        foreach ($pages as [$m, $uri, $key]) {
            $html = $this->get($m, $uri);
            $this->assertStringContainsString('<aside class="acx-rail"', $html, $uri);
            $href = htmlspecialchars(AccountRail::SECTIONS[$key][1], ENT_QUOTES);
            $this->assertMatchesRegularExpression('~<a class="acx-tab" href="' . preg_quote($href, '~') . '" aria-current="page">~', $html, "$uri marks $key");
            $this->assertSame(1, substr_count($html, 'aria-current="page">'), "$uri: one current section");
            foreach (AccountRail::SECTIONS as [, $h]) {
                $this->assertStringContainsString('href="' . htmlspecialchars($h, ENT_QUOTES) . '"', $html, "$uri reaches $h");
            }
        }
    }

    public function test_the_anchors_the_redirects_land_on_are_on_their_section(): void
    {
        $this->assertStringContainsString('id="me-referral"', $this->get('dashboard', '/account?tab=referral'));
        $this->assertStringContainsString('id="me-security"', $this->get('dashboard', '/account?tab=security'));
        $this->assertStringContainsString('id="profile"', $this->get('dashboard', '/account?tab=settings'));

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AccountController.php');
        $this->assertDoesNotMatchRegularExpression("~'/account#~", $src, 'a redirect to /account#x lands on the overview, where x is not');
        $this->assertStringContainsString("'/account?tab=referral#me-referral'", $src);
    }

    public function test_an_unknown_tab_is_the_overview_and_the_phone_can_reach_every_section(): void
    {
        $html = $this->get('dashboard', '/account?tab=../../etc');
        $this->assertStringContainsString('aria-label="Quick actions"', $html);
        $this->assertStringContainsString('class="ag-list acx-rows"', $html, 'the phone\'s section list');
        foreach (AccountRail::SECTIONS as $k => [, $h]) {
            if ($k === 'overview') continue;
            $this->assertMatchesRegularExpression('~acx-rows.*href="' . preg_quote(htmlspecialchars($h, ENT_QUOTES), '~') . '"~s', $html, $k);
        }
    }

    public function test_the_desktop_is_a_rail_and_content_never_the_phone_column(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/account.css');
        $this->assertMatchesRegularExpression('~@media \(min-width:600px\)\{[^@]*\.acx\{ grid-template-columns:210px minmax\(0, 1fr\)~', $css);
        $this->assertMatchesRegularExpression('~@media \(min-width:1024px\)\{[^@]*\.acx\{ grid-template-columns:250px minmax\(0, 1fr\) \}~', $css);
        $this->assertMatchesRegularExpression('~\.acx\{[^}]*max-width:1200px~', $css);
        $this->assertDoesNotMatchRegularExpression('~max-width:720px~', $css, 'the Phase 8 single column is gone');
        foreach (['dashboard', 'points', 'notifications', 'display'] as $t) {
            $this->assertStringContainsString("{% extends 'pages/account/_frame.twig' %}",
                (string) file_get_contents(dirname(__DIR__, 2) . "/templates/pages/account/$t.twig"), $t);
        }
    }
}
