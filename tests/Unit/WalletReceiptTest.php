<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MemberWallet as W;
use AfricaGates\Services\WalletReceipt as R;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The wallet receipt as a file and as a page — Wallet Receipt.dc.html, revision 3.
 *
 * What these try to break: a whole account number or an email reaching a document that gets
 * forwarded, a file issued for money that has not moved, another member's receipt opening by
 * its key, and the page and the file drawing different facts.
 */
final class WalletReceiptTest extends TestCase
{
    private const T = ['key' => 'crw-12', 'dir' => 'out', 'title' => 'Withdrawal to GTBank', 'sub' => 'x', 'amount_kobo' => 200000,
        'unit' => 'naira', 'status' => 'paid', 'status_label' => 'Paid', 'at' => '2026-10-20 16:05:00', 'ref' => 'AGW-3KX91',
        'fee_kobo' => 2500, 'details' => [['From', 'Referral earnings']],
        'payee' => ['name' => 'CHIOMA ADAEZE OBI', 'bank' => 'GTBank', 'number' => '0123456789'],
        'session' => '100004261020160512341234'];

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['member_id'], $_SESSION['account_id']);
        parent::tearDown();
    }

    public function test_numbers_are_masked_before_anything_is_drawn(): void
    {
        $this->assertSame('012****789', R::maskAccount('0123456789'));
        $this->assertSame('1000042610201605****1234', R::maskSession('100004261020160512341234'));

        $f = R::facts(self::T, 'Chioma Obi chioma@example.com');
        $flat = json_encode($f, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('0123456789', $flat, 'never the whole NUBAN');
        $this->assertStringNotContainsString('51234', $flat, 'the four digits before the last four are gone');
        $this->assertStringNotContainsString('@', $flat, 'no email on a receipt');
        $this->assertStringContainsString('CHIOMA ADAEZE OBI', $flat, 'the account name stays whole');
        $this->assertSame('₦1,975.00', $f['rows'][3]['v'], 'amount received is after the fee');
        $this->assertStringStartsWith('Withdrawal to bank · ', $f['line']);
    }

    public function test_only_money_that_has_moved_gets_a_file(): void
    {
        $this->assertTrue(R::issuable(self::T));
        $this->assertTrue(R::issuable(['status' => W::ST_RECEIVED] + self::T));
        foreach ([W::ST_PENDING, W::ST_PROCESSING, W::ST_LOCKED, W::ST_REFUSED, W::ST_SPENT] as $st) {
            $this->assertFalse(R::issuable(['status' => $st] + self::T), $st);
        }
    }

    public function test_both_files_render_and_are_the_same_card(): void
    {
        $r = new R();
        $f = R::facts(self::T, 'Chioma Obi');
        $png = $r->png($f, 'example.test', '21 Oct 2026, 4:12 pm');
        $this->assertStringStartsWith("\x89PNG", $png);
        $size = getimagesizefromstring($png);
        $this->assertSame([R::W * 2, R::H * 2], [$size[0], $size[1]], '794×1123 at 2x');
        $this->assertStringStartsWith('%PDF', $r->pdf($f, 'example.test', '21 Oct 2026, 4:12 pm'));
    }

    public function test_the_file_is_the_owners_and_only_for_settled_money(): void
    {
        foreach ([7 => 'ada', 8 => 'bola'] as $id => $n) {
            DB::table('gates_users')->insert(['id' => $id, 'name' => ucfirst($n), 'email' => $n . '@example.com',
                'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => '2026-01-01 00:00:00']);
        }
        DB::table('gates_referral_codes')->insert(['user_id' => 7, 'code' => 'AG7', 'created_at' => '2026-01-01 00:00:00']);
        $base = ['code_id' => 1, 'user_id' => 7, 'source_type' => 'registration', 'event_id' => 1, 'paid_naira' => 10000,
                 'commission_naira' => 1000, 'rate_bps' => 1000, 'created_at' => '2026-10-01 10:00:00'];
        DB::table('gates_referral_credits')->insert(['id' => 1, 'registration_id' => 1, 'source_id' => 1, 'paid_out_at' => '2026-10-02 10:00:00'] + $base);
        DB::table('gates_referral_credits')->insert(['id' => 2, 'registration_id' => 2, 'source_id' => 2] + $base);   // locked

        $c = (new \DI\ContainerBuilder())->addDefinitions(dirname(__DIR__, 2) . '/config/container.php')->build()
            ->get(\AfricaGates\Controllers\AccountController::class);
        $get = function (string $key, string $ext) use ($c): Response {
            $req = (new ServerRequestFactory())->createServerRequest('GET', "/account/wallet/tx/$key.$ext");
            return $c->walletTxFile($req, new Response(), ['key' => $key, 'ext' => $ext]);
        };

        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = 7;
        $ok = $get('rc-1', 'png');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('image/png', $ok->getHeaderLine('Content-Type'));
        $this->assertSame(200, $get('rc-1', 'pdf')->getStatusCode());
        $this->assertSame(404, $get('rc-2', 'png')->getStatusCode(), 'a locked credit has no file');

        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = 8;
        $this->assertSame(404, $get('rc-1', 'png')->getStatusCode(), 'another member\'s receipt does not open by its key');

        // The page draws the same masked facts as the file.
        $_SESSION['user_id'] = $_SESSION['member_id'] = $_SESSION['account_id'] = 7;
        $page = (string) $c->walletTx((new ServerRequestFactory())->createServerRequest('GET', '/account/wallet/tx/rc-1'), new Response(), ['key' => 'rc-1'])->getBody();
        $this->assertStringContainsString('class="wl-ticket"', $page);
        $this->assertStringContainsString('href="/account/wallet/tx/rc-1.pdf"', $page);
        $locked = (string) $c->walletTx((new ServerRequestFactory())->createServerRequest('GET', '/account/wallet/tx/rc-2'), new Response(), ['key' => 'rc-2'])->getBody();
        $this->assertStringNotContainsString('rc-2.pdf', $locked, 'no download for money still locked');
    }
}
