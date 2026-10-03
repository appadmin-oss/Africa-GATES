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
}
