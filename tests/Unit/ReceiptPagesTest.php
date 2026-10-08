<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The pages somebody lands on after paying or nominating — rendered through the real router.
 *
 * All seven were destroyed on 3 Oct 2026 and every one is where a gateway or a submitted form
 * SENDS a person, so for those days a confirmed payment was answered "500 · Reference …".
 * The destroyed tests' rules (inventory: pages--vote-paid-success.md, pages--pay-success.md,
 * pages--vote-verify.md) are re-asserted here against the rebuilt markup:
 *
 *   · a minted paid vote is celebrated; a confirmed-but-unminted one never is, never says
 *     its votes were counted, says a refund is owed and shows the reference;
 *   · an unknown reference renders the pending state, not the refund one;
 *   · every unconfirmed receipt hands its reference to the re-check (`ref=` + `ask=1`), so
 *     the reader never retypes it;
 *   · the proof page states what was DELIVERED from the vote rows, and its one action on a
 *     broken order RUNS the repair;
 *   · a receipt never shares its own URL — it carries a bearer token for the proof page.
 *
 * Not re-asserted, deliberately: the old receipt marked a per-device "already voted" tracker
 * (`afg_voted_prog_`). Its reader was destroyed with the old vote hub and the rebuilt one
 * reads nothing of the kind (CookieRegistry lists the key as a destroyed writer), so writing
 * it would be storage with no purpose — the thing CookieRegistryTest exists to refuse.
 */
final class ReceiptPagesTest extends TestCase
{
    private static function app(): App
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        return $app;
    }

    private static function get(string $path): array
    {
        $_SESSION = $_SESSION ?? [];
        $_SESSION['csrf_token'] = 'receipt';
        $r = self::app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        return [$r->getStatusCode(), (string) $r->getBody()];
    }

    private static function order(string $ref, array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name' => 'Buyer', 'donor_email' => 'buyer@receipt.test', 'amount_naira' => 2000,
            'payment_ref' => $ref, 'tier' => 'paid-vote', 'status' => 'confirmed',
            'bonus_votes' => 10, 'votes_used' => 0, 'created_at' => '2026-10-01 09:00:00',
        ]);
    }

    public function test_a_minted_order_is_celebrated_and_says_its_votes_were_counted(): void
    {
        self::order('AFG-PVOTE-MINTED01', ['votes_used' => 10]);
        [$code, $html] = self::get('/vote/paid/success?ref=AFG-PVOTE-MINTED01');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('ag-cel--vote', $html, 'a minted order got no celebration');
        $this->assertStringContainsString('10 votes counted', $html);
        $this->assertSame(1, substr_count($html, '<h1'), 'one h1 — the celebration IS the heading');
        $this->assertStringContainsString('data-rc-msg', $html, 'the message box is offered where votes landed');
        $this->assertStringContainsString('/vote/verify?ref=AFG-PVOTE-MINTED01', $html, 'and the proof is one tap away');
    }

    public function test_a_confirmed_but_unminted_order_is_never_reported_as_counted(): void
    {
        self::order('AFG-PVOTE-UNMINTED');
        [$code, $html] = self::get('/vote/paid/success?ref=AFG-PVOTE-UNMINTED');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('ag-cel', $html, 'a payment that minted no votes was celebrated');
        $this->assertStringNotContainsString('votes counted', $html);
        $this->assertStringNotContainsString('in the public tally.', $html);
        $this->assertStringNotContainsString('data-rc-msg', $html, 'no message box beneath "no votes were counted"');
        $this->assertStringContainsString('refundable', $html, 'the unminted state says a refund is owed');
        $this->assertStringContainsString('AFG-PVOTE-UNMINTED', $html, 'and shows the reference');
        $this->assertStringContainsString('ref=AFG-PVOTE-UNMINTED&amp;ask=1', $html, 'and a route to act on it');
    }

    public function test_an_unknown_reference_still_renders_the_pending_state(): void
    {
        [$code, $html] = self::get('/vote/paid/success?ref=AFG-PVOTE-NOSUCHREF');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('We could not confirm that payment yet', $html);
        $this->assertStringNotContainsString('refundable', $html,
            'an unconfirmed payment is not a refund case — nothing was charged that we know of');
    }

    /** The reader never retypes the reference, on any receipt that is not yet confirmed. */
    public function test_every_unconfirmed_receipt_hands_its_reference_to_the_recheck(): void
    {
        foreach (['/pay/success', '/giving/success', '/shop/success', '/vote/paid/success'] as $p) {
            [$code, $html] = self::get($p . '?ref=AFG-UNCONFIRMED-9');
            $this->assertSame(200, $code, $p);
            $this->assertStringContainsString('ref=AFG-UNCONFIRMED-9&amp;ask=1', $html, $p . ' drops the reference');
            $this->assertStringNotContainsString('ag-cel', $html, $p . ' celebrates money nobody confirmed');
        }
    }

    public function test_confirmed_receipts_render_and_a_confirmed_gift_is_celebrated(): void
    {
        DB::table('gates_donations')->insert(['donor_name' => 'Donor', 'donor_email' => 'd@receipt.test',
            'amount_naira' => 5000, 'payment_ref' => 'AFG-GIVE-OK01', 'tier' => 'general', 'status' => 'confirmed']);
        [$code, $html] = self::get('/giving/success?ref=AFG-GIVE-OK01');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('ag-cel--give', $html);
        $this->assertStringContainsString('₦5,000', $html);

        [$code, $html] = self::get('/pay/success?ref=AFG-GIVE-OK01');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Payment confirmed', $html);
        $this->assertStringNotContainsString('ag-cel', $html, 'a contribution is not a moment §7.8 names');
    }

    /**
     * A receipt's URL carries a payment reference: sharing it hands a stranger the order.
     * This found the child app bar's own Share button on its first run — it shares
     * `location.href` by default — so every receipt passes `share: false` to the bar.
     */
    public function test_a_receipt_shares_the_ballot_and_never_its_own_url(): void
    {
        self::order('AFG-PVOTE-SHARE001', ['votes_used' => 10]);
        [, $html] = self::get('/vote/paid/success?ref=AFG-PVOTE-SHARE001');
        preg_match_all('~<[^>]*\bdata-ag-share\b[^>]*>~', $html, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $tag) {
            $this->assertMatchesRegularExpression('~data-ag-share-url="[^"]+"~', $tag, 'a share button with no URL shares the receipt');
            $this->assertStringNotContainsString('SHARE001', $tag);
        }
        $this->assertStringContainsString("getAttribute('data-ag-share-url')",
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/chrome.js'), 'and the handler reads it');
    }

    public function test_the_proof_page_states_what_was_delivered_from_the_rows(): void
    {
        $id = self::order('AFG-PVOTE-PROOF001', ['votes_used' => 10]);
        DB::table('gates_votes')->insert(['donation_id' => $id, 'weight' => 10, 'vote_type' => 'paid',
            'nominee_id' => 0, 'category_id' => 0, 'voter_email_hash' => 'h', 'voted_at' => '2026-10-01 09:01:00']);
        [$code, $html] = self::get('/vote/verify?ref=AFG-PVOTE-PROOF001');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Delivered — 10 votes are on the tally', $html);
        $this->assertStringContainsString('recorded 2026-10-01 09:01', $html, 'the entries themselves, with their times');
        $this->assertStringNotContainsString('buyer@receipt.test', $html, 'no payer details on a bearer-token page');
        $this->assertStringNotContainsString('ask=1', $html, 'a delivered order owes nothing, so offers no repair');
    }

    public function test_the_proof_page_runs_the_repair_on_a_broken_order(): void
    {
        self::order('AFG-PVOTE-BROKEN01');   // paid, confirmed, no rows: awaiting delivery
        [$code, $html] = self::get('/vote/verify?ref=AFG-PVOTE-BROKEN01');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Paid, and the votes are not there', $html);
        $this->assertStringContainsString('rc__verdict--bad', $html, 'a failing state is as loud as the good one');
        $this->assertStringContainsString('/support/assistant?ref=AFG-PVOTE-BROKEN01&amp;ask=1', $html,
            'the one action must RUN the repair, not open an empty chat box');
    }

    public function test_the_nomination_receipt_celebrates_once_and_survives_a_refresh(): void
    {
        $_SESSION = ['nom_done' => ['ref' => 'NOM-7Q2K', 'nominee' => 'Ngozi Adichie', 'cat' => 'Literature']];
        [$code, $html] = self::get('/nominate/success');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('ag-cel--nominate', $html, 'a nomination is drawn as a nomination, never a win');
        $this->assertStringContainsString('Ngozi Adichie has been put forward.', $html);
        $this->assertStringContainsString('NOM-7Q2K', $html);
        $this->assertSame(1, substr_count($html, '<h1'));

        [$code, $html] = self::get('/nominate/success');   // the flash is spent
        $this->assertSame(200, $code, 'a refresh is a page, not an error');
        $this->assertStringNotContainsString('ag-cel', $html, 'and not a second celebration');
        $this->assertStringContainsString('Your nomination was received', $html);
    }

    public function test_the_partner_receipt_promises_no_window_it_cannot_keep(): void
    {
        [$code, $html] = self::get('/partner/success');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('working days', $html, 'a typed reply window with nothing behind it');
    }
}
