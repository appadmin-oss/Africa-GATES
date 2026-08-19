<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The member's own page — the parts of it a person depends on.
 *
 * The rebuild turned one column of ten cards into six sections behind a rail, and the risk in
 * that shape is a section that is unreachable rather than a section that looks wrong. So these
 * are about reachability: every section is in the document, the switch works without a
 * framework, and the two states that are easy to forget — a brand-new account and a member's
 * export of their own record — behave.
 */
class AccountDashboardTest extends TestCase
{
    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    private function controller(): \AfricaGates\Controllers\AccountController
    {
        return $this->container()->get(\AfricaGates\Controllers\AccountController::class);
    }

    private function member(array $over = []): int
    {
        return (int) DB::table('gates_users')->insertGetId($over + [
            'name' => 'Adaeze Okonkwo', 'email' => 'a-' . bin2hex(random_bytes(4)) . '@example.test',
            'phone' => '08031234567', 'points' => 0, 'status' => 'active', 'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime('-13 months')),
        ]);
    }

    private function signIn(int $uid): void
    {
        $_SESSION['user_id'] = $uid;
        $_SESSION['member_id'] = $uid;
        $_SESSION['account_id'] = $uid;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        parent::tearDown();
    }

    private function page(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/account');
        return (string) $this->controller()->dashboard($req, new Response())->getBody();
    }

    private function earn(int $uid, int $delta, string $when, string $reason = 'earn.shop_order'): void
    {
        $bal = (int) DB::table('gates_points_ledger')->where('user_id', $uid)->orderByDesc('id')->value('balance_after');
        DB::table('gates_points_ledger')->insert([
            'user_id' => $uid, 'delta' => $delta, 'reason' => $reason,
            'balance_after' => $bal + $delta, 'created_at' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
        DB::table('gates_users')->where('id', $uid)->update(['points' => $bal + $delta]);
    }

    // ────────────────────────────────────────────────────────────────────────

    public function test_every_section_is_in_the_document(): void
    {
        // The rail can only reach what is rendered. A section behind a condition that never
        // fires is a menu entry that leads to a blank pane.
        $uid = $this->member();
        $this->signIn($uid);
        $this->earn($uid, 500, '-20 days');

        $html = $this->page();
        foreach (['me-overview', 'me-points', 'me-purchases', 'me-activity', 'me-settings'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, $id . ' is missing');
        }
    }

    public function test_the_section_switch_needs_no_framework_and_no_javascript(): void
    {
        $uid = $this->member();
        $this->signIn($uid);
        $html = $this->page();

        // The attribute the head script sets, and the rules keyed off it. Together they are
        // the whole mechanism — no Alpine, so the page works on a lite render too.
        $this->assertStringContainsString('data-me', $html);
        $this->assertMatchesRegularExpression('~\[data-me\]\s*\.me-sec\{\s*display:none~', $html);
        $this->assertMatchesRegularExpression('~\[data-me="points"\]\s*#me-points~', $html);

        // And EVERY hide is conditional on that attribute. An unconditional
        // `.me-sec{display:none}` would leave a reader with scripting off looking at one
        // section with no way to reach the other five.
        $this->assertSame(
            substr_count($html, '.me-sec{ display:none'),
            substr_count($html, '[data-me] .me-sec{ display:none'),
            'a hide that does not depend on the attribute is a hide a no-script reader cannot undo'
        );
    }

    public function test_a_brand_new_account_gets_a_welcome_and_not_six_empty_panels(): void
    {
        $uid = $this->member();
        $this->signIn($uid);

        $html = $this->page();
        $this->assertStringContainsString('Welcome, Adaeze', $html);
        $this->assertStringNotContainsString('Balance, last 90 days', $html,
            'there is nothing to chart, and a graph of nothing is the worst first screen there is');
    }

    public function test_a_member_with_history_gets_the_chart_and_the_table_behind_it(): void
    {
        $uid = $this->member();
        $this->signIn($uid);
        $this->earn($uid, 600, '-60 days');
        $this->earn($uid, 400, '-20 days');

        $html = $this->page();
        $this->assertStringContainsString('Balance, last 90 days', $html);
        $this->assertStringContainsString('class="me-chart__line"', $html);
        // Every value the hover shows is also in a table. A number only reachable by
        // pointing at it is a number some people cannot reach.
        $this->assertStringContainsString('id="meChartTable"', $html);
        $this->assertStringContainsString('Show as a table', $html);
        // And the chart itself is reachable from a keyboard.
        $this->assertMatchesRegularExpression('~id="meChartBox"[^>]*tabindex="0"~', $html);
    }

    public function test_the_chart_is_drawn_on_the_server(): void
    {
        // Not "there is a canvas and a script will fill it": the path is in the HTML, so it
        // prints, it survives a script that never loads, and it is in the document for a
        // reader that does not run one.
        $uid = $this->member();
        $this->signIn($uid);
        $this->earn($uid, 300, '-40 days');
        $this->earn($uid, 300, '-10 days');

        $this->assertMatchesRegularExpression('~class="me-chart__line" d="M[\d. L]+"~', $this->page());
    }

    public function test_the_points_export_is_the_owners_and_only_the_owners(): void
    {
        $uid = $this->member();
        $this->signIn($uid);
        $this->earn($uid, 250, '-5 days', 'earn.donation');

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/account/points.csv');
        $res = $this->controller()->pointsCsv($req, new Response());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringStartsWith('text/csv', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment', $res->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'),
            'it is somebody\'s own record; nothing in front of it should keep a copy');

        $csv = (string) $res->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'without the BOM Excel mangles African names');
        $this->assertStringContainsString('earn.donation', $csv);
        $this->assertStringContainsString('250', $csv);

        // There is no id in the path to tamper with, so signed out is the whole of the
        // authorization story — and it must be a redirect, not an empty file.
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        $out = $this->controller()->pointsCsv($req, new Response());
        $this->assertSame(302, $out->getStatusCode());
        $this->assertStringContainsString('/account/login', $out->getHeaderLine('Location'));
    }

    public function test_the_export_carries_one_row_per_ledger_entry(): void
    {
        $uid = $this->member();
        $this->signIn($uid);
        foreach ([[-3, 100], [-2, 200], [-1, -150]] as [$d, $delta]) {
            $this->earn($uid, $delta, $d . ' days');
        }

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/account/points.csv');
        $csv  = (string) $this->controller()->pointsCsv($req, new Response())->getBody();
        $rows = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(4, $rows, 'a header and three entries');
    }

    public function test_the_page_carries_no_emoji_as_interface_iconography(): void
    {
        // The old page used 🛍️ 🎟️ ♥ ✓ as its icons. Beyond reading as unfinished, a good
        // number of them are simply absent from shipped emoji fonts and render as tofu —
        // measured elsewhere in this repo on U+1F6CD.
        $uid = $this->member();
        $this->signIn($uid);
        $this->earn($uid, 100, '-2 days');

        $html = $this->page();
        $at   = strpos($html, '<div class="me"');
        $mine = $at !== false ? substr($html, $at) : $html;
        $this->assertSame(0, preg_match('~[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]~u', $mine),
            'the account page draws its icons as SVG');
    }
}
