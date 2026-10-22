<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MemberWallet as W;
use AfricaGates\Services\WalletHistory as H;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The full transaction history — /account/wallet/history and its two files.
 *
 * What these try to break: a filter the page applies and the statement does not (the file
 * would then be a different document under the same button), a month total counting money
 * still in review, a CSV cell a spreadsheet would run, another member's rows reaching the
 * page or a file, and the wallet page going back to listing everything.
 */
final class WalletHistoryTest extends TestCase
{
    private function controller(): \AfricaGates\Controllers\AccountController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Controllers\AccountController::class);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        parent::tearDown();
    }

    private function as(int $uid): void
    {
        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = $uid;
    }

    private function get(string $method, string $uri): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        return $this->controller()->{$method}($req->withQueryParams($q), new Response());
    }

    /** @return array<string,mixed> */
    private function tx(string $key, string $dir, string $status, int $kobo, string $at, string $title = 'Referral commission', string $sub = ''): array
    {
        return ['key' => $key, 'dir' => $dir, 'title' => $title, 'sub' => $sub, 'amount_kobo' => $kobo, 'unit' => 'naira',
                'status' => $status, 'status_label' => W::STATUS_LABELS[$status], 'at' => $at, 'ref' => '', 'fee_kobo' => 0];
    }

    /** Two members, each with referral commission in two months. */
    private function seed(): void
    {
        foreach ([7 => 'ada', 8 => 'bola'] as $id => $n) {
            DB::table('gates_users')->insert(['id' => $id, 'name' => ucfirst($n) . ' Obi', 'email' => $n . '@example.com',
                'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => '2026-01-01 00:00:00']);
            DB::table('gates_referral_codes')->insert(['user_id' => $id, 'code' => 'AG' . $id, 'created_at' => '2026-01-01 00:00:00']);
        }
        $rows = [[7, '2026-09-03 10:00:00', 700], [7, '2026-10-04 10:00:00', 900], [7, '2026-10-05 10:00:00', 1100],
                 [8, '2026-10-06 10:00:00', 5555]];
        foreach ($rows as $i => [$uid, $at, $naira]) {
            DB::table('gates_referral_credits')->insert([
                'code_id' => $uid === 7 ? 1 : 2, 'user_id' => $uid, 'registration_id' => $i + 1, 'source_type' => 'registration',
                'source_id' => $i + 1, 'event_id' => 1, 'paid_naira' => $naira * 10,
                'commission_naira' => $naira, 'rate_bps' => 1000, 'created_at' => $at,
                // Paid out, so received: a locked credit is not money in (the sum test holds that).
                'paid_out_at' => $at,
            ]);
        }
    }

    public function test_every_filter_narrows_and_the_sums_count_settled_money_only(): void
    {
        $all = [
            $this->tx('rc-3', 'in', W::ST_RECEIVED, 50000, '2026-10-05 09:00:00', 'Referral commission', 'A ticket for Gala'),
            $this->tx('rc-2', 'in', W::ST_LOCKED, 30000, '2026-10-04 09:00:00'),
            $this->tx('crw-1', 'out', W::ST_PENDING, 20000, '2026-10-03 09:00:00', 'Withdrawal to GTBank'),
            $this->tx('wv-1', 'out', W::ST_SPENT, 10000, '2026-10-02 09:00:00', 'Spent on votes'),
            $this->tx('rp-1', 'out', W::ST_PAID, 7000, '2026-09-30 09:00:00', 'Withdrawal to Access'),
        ];
        $p = H::params([], $all);
        $this->assertSame('2026-10', $p['month'], 'unset means the latest month with a movement');

        $r = H::query($all, $p);
        $this->assertSame(['rc-3', 'rc-2', 'crw-1', 'wv-1'], array_column($r['rows'], 'key'));
        $this->assertSame(50000, $r['in_kobo'], 'a locked credit has not arrived');
        $this->assertSame(10000, $r['out_kobo'], 'a withdrawal in review has not left; money spent on votes has');

        $keys = static fn (array $q) => array_column(H::query($all, H::params($q, $all))['rows'], 'key');
        $this->assertSame(['rc-3', 'rc-2', 'crw-1', 'wv-1', 'rp-1'], $keys(['month' => 'all']));
        $this->assertSame(['rp-1'], $keys(['month' => '2026-09']));
        $this->assertSame(['crw-1', 'rp-1'], $keys(['month' => 'all', 'type' => 'withdrawal']));
        $this->assertSame(['wv-1'], $keys(['month' => 'all', 'type' => 'votes']));
        $this->assertSame(['crw-1', 'wv-1', 'rp-1'], $keys(['month' => 'all', 'f' => 'out']));
        $this->assertSame(['rc-2', 'crw-1'], $keys(['month' => 'all', 'f' => 'pending']));
        $this->assertSame(['rc-3'], $keys(['month' => 'all', 'q' => 'gala']), 'search reads the line under the title');
        $this->assertSame(['rp-1'], $keys(['month' => 'all', 'q' => 'RP-1']), 'and the transaction number');

        $bad = H::params(['month' => '2026-13', 'type' => 'x', 'f' => 'y', 'page' => '-4'], $all);
        $this->assertSame(['2026-10', 'all', 'all', 1], [$bad['month'], $bad['type'], $bad['f'], $bad['page']]);
    }

    public function test_the_list_pages_and_says_when_there_is_more(): void
    {
        $all = [];
        for ($i = 0; $i < H::PER_PAGE + 3; $i++) $all[] = $this->tx('rc-' . $i, 'in', W::ST_RECEIVED, 100, '2026-10-01 09:00:00');
        $one = H::query($all, H::params([], $all));
        $this->assertCount(H::PER_PAGE, $one['rows']);
        $this->assertTrue($one['more']);
        $this->assertSame(H::PER_PAGE + 3, $one['total']);
        $two = H::query($all, H::params(['page' => 2], $all));
        $this->assertCount(H::PER_PAGE + 3, $two['rows']);
        $this->assertFalse($two['more']);
        $this->assertCount(H::PER_PAGE + 3, $one['all'], 'the files carry every matching row, not one page');
    }

    public function test_a_csv_cell_somebody_typed_cannot_run_as_a_formula(): void
    {
        $csv = H::csv([$this->tx('rc-1', 'in', W::ST_RECEIVED, 12345, '2026-10-01 09:00:00', '=HYPERLINK("x")', '+cmd')]);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $csv);
        $this->assertStringContainsString(",'+cmd,", $csv);
        $this->assertStringContainsString(',123.45,NGN,', $csv);
        $this->assertStringStartsWith('Date,"Transaction number",Type', $csv);
    }

    public function test_the_page_and_both_files_are_the_members_own_rows_and_agree(): void
    {
        $this->seed();
        $this->as(7);

        $html = (string) $this->get('walletHistory', '/account/wallet/history')->getBody();
        $this->assertStringContainsString('October 2026', $html, 'the latest month by default');
        $this->assertSame(2, substr_count($html, 'class="wl-tx"'), 'October only');
        $this->assertStringContainsString('₦2,000.00', $html, 'October in: ₦900 + ₦1,100');
        $this->assertStringNotContainsString('₦5,555', $html, 'another member\'s money');
        $this->assertStringContainsString('href="/account/wallet/history.pdf?month=2026-10"', $html);

        $all = (string) $this->get('walletHistory', '/account/wallet/history?month=all')->getBody();
        $this->assertSame(3, substr_count($all, 'class="wl-tx"'));
        $this->assertStringContainsString('That’s everything since you joined.', $all);

        $none = (string) $this->get('walletHistory', '/account/wallet/history?month=2026-10&q=nothing-like-this')->getBody();
        $this->assertStringContainsString('Nothing matches', $none);
        $this->assertStringContainsString('href="/account/wallet/history?month=all">Clear filters', $none);

        $csvRes = $this->get('walletHistoryCsv', '/account/wallet/history.csv?month=2026-10');
        $csv = (string) $csvRes->getBody();
        $this->assertStringContainsString('text/csv', $csvRes->getHeaderLine('Content-Type'));
        $this->assertSame(3, count(array_filter(explode("\n", trim($csv)))), 'header and the two October rows');
        $this->assertStringNotContainsString('5555', $csv);

        $pdfRes = $this->get('walletHistoryPdf', '/account/wallet/history.pdf?month=2026-10');
        $pdf = (string) $pdfRes->getBody();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame('application/pdf', $pdfRes->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('private', $pdfRes->getHeaderLine('Cache-Control'));
    }

    public function test_signed_out_there_is_nothing_to_read(): void
    {
        foreach (['walletHistory', 'walletHistoryCsv', 'walletHistoryPdf'] as $m) {
            $res = $this->get($m, '/account/wallet/history');
            $this->assertSame(302, $res->getStatusCode(), $m);
            $this->assertSame('/account/login', $res->getHeaderLine('Location'), $m);
        }
    }

    public function test_the_wallet_shows_the_last_four_and_a_way_to_the_rest(): void
    {
        $this->seed();
        for ($i = 0; $i < 4; $i++) {
            DB::table('gates_referral_credits')->insert([
                'code_id' => 1, 'user_id' => 7, 'registration_id' => 100 + $i, 'source_type' => 'registration',
                'source_id' => 100 + $i, 'event_id' => 1, 'paid_naira' => 1000, 'commission_naira' => 100,
                'rate_bps' => 1000, 'created_at' => '2026-10-1' . $i . ' 10:00:00',
            ]);
        }
        $this->as(7);
        $html = (string) $this->get('wallet', '/account/wallet')->getBody();
        $this->assertSame(4, substr_count($html, 'class="wl-tx"'), 'seven movements, four shown');
        $this->assertStringContainsString('<a class="wl-more" href="/account/wallet/history">See all', $html);
        $this->assertSame(1, substr_count($html, 'id="wl-pockets"'), 'one pockets section to land on');
        $this->assertStringContainsString('class="wl-card"', $html);

        $old = $this->get('wallet', '/account/wallet?show=out');
        $this->assertSame(302, $old->getStatusCode(), 'the old filter links land on the history');
        $this->assertSame('/account/wallet/history?month=all&f=out', $old->getHeaderLine('Location'));
    }

    public function test_a_vote_bought_from_the_wallet_has_a_receipt_address(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertMatchesRegularExpression('~/wallet/tx/\{key:\(\?:[a-z|]*\bwv\b[a-z|]*\)-\[0-9\]\+\}~', $routes,
            'the wallet lists wv- rows; a receipt route that does not match them is a dead link on every one');
        $this->assertSame('votes', H::kind('wv-12'));
        $this->assertSame('withdrawal', H::kind('crw-3'));
        $this->assertSame('return', H::kind('cr-3'));
    }
}
