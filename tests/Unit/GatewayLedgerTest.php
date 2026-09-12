<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{GatewayLedger, PaymentService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Reading the money from Paystack's side, which nothing here could do before.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FILE IS DEFENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every existing money path — verify, refund, triage, the reconciler — begins
 * with a row of ours. That is a closed loop: it can find an order we started and
 * mishandled, and it is structurally incapable of finding a charge that exists
 * only at the gateway. If the insert failed, or the money came through a Payment
 * Page this codebase never knew about, there is nothing on our side to iterate
 * and the payment is invisible. Not under-reported — absent.
 *
 * {@see GatewayLedger} is the only thing that looks the other way round, so the
 * tests that matter most here are the ones that prove:
 *
 *   1. a gateway charge with NO local row lands in `theirs`, not silently nowhere
 *   2. a shop order is NOT reported as an orphan — a reconciler that cries wolf on
 *      its first run is a reconciler nobody opens again, and the one real orphan
 *      in the same list dies with it
 *   3. a truncated walk SAYS it was truncated, because "no orphans" over a window
 *      that was never finished is a false all-clear
 *
 * The gateway is a double throughout: `api.paystack.co` is unreachable from CI
 * and a test that needed it would either be skipped or be a live-money call.
 */
final class GatewayLedgerTest extends TestCase
{
    /**
     * A Paystack we control.
     *
     * It substitutes at {@see PaymentService::request()} rather than at
     * `listTransactions()`, so the pagination walk, the kobo→naira division and
     * the response-envelope parsing are all really executed. Stubbing the public
     * method instead would test the double.
     *
     * @param list<list<array>> $pages one entry per page of `data`
     */
    private function gateway(array $pages, bool $reportPageCount = true, ?int $failAtPage = null): PaymentService
    {
        return new class($pages, $reportPageCount, $failAtPage) extends PaymentService {
            public int $calls = 0;

            public function __construct(
                private array $pages,
                private bool $reportPageCount,
                private ?int $failAtPage,
            ) {}

            public function isEnabled(string $provider): bool { return $provider === 'paystack'; }

            protected function request(string $method, string $url, ?array $jsonBody, array $headers): array
            {
                $this->calls++;
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $page = (int) ($q['page'] ?? 1);

                if ($this->failAtPage !== null && $page >= $this->failAtPage) {
                    return ['ok' => false, 'code' => 500,
                            'json' => ['status' => false, 'message' => 'Paystack is having a moment.'], 'raw' => ''];
                }

                $data = $this->pages[$page - 1] ?? [];
                $meta = ['perPage' => (int) ($q['perPage'] ?? 100)];
                if ($this->reportPageCount) $meta['pageCount'] = count($this->pages);

                return ['ok' => true, 'code' => 200,
                        'json' => ['status' => true, 'message' => 'ok', 'data' => $data, 'meta' => $meta],
                        'raw'  => ''];
            }
        };
    }

    /** One Paystack transaction object, in Paystack's own shape (amount in KOBO). */
    private function tx(string $ref, int $naira, string $status = 'success', string $email = 'buyer@example.test'): array
    {
        return [
            'id' => abs(crc32($ref)), 'reference' => $ref, 'status' => $status,
            'amount' => $naira * 100, 'fees' => 150 * 100, 'currency' => 'NGN', 'channel' => 'card',
            'customer' => ['email' => $email, 'first_name' => 'Ada', 'last_name' => 'Buyer'],
            'paid_at' => date('c'), 'created_at' => date('c'), 'gateway_response' => 'Successful',
        ];
    }

    private function donation(string $ref, int $naira, string $status = 'confirmed'): int
    {
        return (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => 'Ada Buyer', 'donor_email' => 'buyer@example.test',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'payment_ref' => $ref, 'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── listTransactions: the wire layer ─────────────────────────────────────

    public function test_kobo_becomes_naira_and_the_customer_is_flattened(): void
    {
        $g = $this->gateway([[$this->tx('AFG-1', 2500)]]);
        $r = $g->listTransactions('paystack');

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['transactions']);
        $t = $r['transactions'][0];
        $this->assertSame(2500, $t['amount'], 'amount must arrive in whole naira, not kobo');
        $this->assertSame(150, $t['fees']);
        $this->assertSame('buyer@example.test', $t['email']);
        $this->assertSame('Ada Buyer', $t['name']);
        $this->assertTrue($t['paid']);
    }

    public function test_it_walks_every_page(): void
    {
        $g = $this->gateway([
            [$this->tx('AFG-1', 1000), $this->tx('AFG-2', 1000)],
            [$this->tx('AFG-3', 1000)],
        ]);
        $r = $g->listTransactions('paystack');

        $this->assertSame(3, count($r['transactions']));
        $this->assertSame(2, $r['pages']);
        $this->assertFalse($r['truncated']);
    }

    /**
     * Some accounts return `meta` without `pageCount`. The walk must still end,
     * and it must end because the page was SHORT — not by guessing.
     */
    public function test_it_ends_on_a_short_page_when_the_account_reports_no_page_count(): void
    {
        $full = [];
        for ($i = 0; $i < 100; $i++) $full[] = $this->tx('AFG-F' . $i, 100);

        $g = $this->gateway([$full, [$this->tx('AFG-LAST', 100)]], reportPageCount: false);
        $r = $g->listTransactions('paystack');

        $this->assertSame(101, count($r['transactions']));
        $this->assertFalse($r['truncated']);
    }

    /** A reference repeated across a page boundary must not be counted twice. */
    public function test_a_duplicate_across_pages_is_counted_once(): void
    {
        $g = $this->gateway([
            [$this->tx('AFG-DUP', 5000)],
            [$this->tx('AFG-DUP', 5000), $this->tx('AFG-OTHER', 1000)],
        ]);
        $r = $g->listTransactions('paystack');

        $this->assertSame(2, count($r['transactions']));
    }

    /** Four good pages must not be thrown away because the fifth failed. */
    public function test_a_failure_mid_walk_keeps_what_it_read_and_says_it_is_partial(): void
    {
        $g = $this->gateway([
            [$this->tx('AFG-1', 1000)], [$this->tx('AFG-2', 1000)], [$this->tx('AFG-3', 1000)],
        ], failAtPage: 3);
        $r = $g->listTransactions('paystack');

        $this->assertTrue($r['ok']);
        $this->assertSame(2, count($r['transactions']));
        $this->assertTrue($r['truncated'], 'a partial answer must know it is partial');
    }

    public function test_a_failure_on_the_first_page_is_a_failure(): void
    {
        $r = $this->gateway([[$this->tx('AFG-1', 1000)]], failAtPage: 1)->listTransactions('paystack');

        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['transactions']);
    }

    /** Guessing Flutterwave's envelope from Paystack's would reconcile against nothing. */
    public function test_flutterwave_is_refused_rather_than_guessed(): void
    {
        $r = (new GatewayLedgerTestFlutterwaveEnabled())->listTransactions('flutterwave');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Paystack', $r['message']);
    }

    // ── the reconciliation itself ────────────────────────────────────────────

    /** THE POINT OF THE WHOLE CLASS: a charge with no row here becomes visible. */
    public function test_a_charge_with_no_local_row_lands_in_theirs(): void
    {
        $this->donation('AFG-KNOWN', 5000);

        $r = (new GatewayLedger($this->gateway([[
            $this->tx('AFG-KNOWN', 5000),
            $this->tx('AFG-STRANGER', 12000, email: 'lost@example.test'),
        ]])))->pull(30);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['counts']['agreed']);
        $this->assertSame(1, $r['counts']['theirs']);
        $this->assertSame(12000, $r['naira']['theirs']);
        $this->assertSame('lost@example.test', $r['groups']['theirs'][0]['gateway']['email']);
    }

    /**
     * A shop order is not an orphan. One Paystack account collects for several
     * ledgers here, and filing every shop payment under "money we never recorded"
     * would make the page cry wolf on its first run.
     */
    public function test_a_shop_order_is_matched_and_not_reported_as_an_orphan(): void
    {
        if (!DB::schema()->hasTable('gates_orders')) {
            $this->markTestSkipped('gates_orders is not present in this schema build.');
        }

        DB::table('gates_orders')->insert([
            'reference' => 'SHOP-77', 'email' => 'shopper@example.test', 'name' => 'Shopper',
            'items_json' => '[]', 'subtotal_naira' => 7500, 'status' => 'paid',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $r = (new GatewayLedger($this->gateway([[$this->tx('SHOP-77', 7500)]])))->pull(30);

        $this->assertSame(0, $r['counts']['theirs'], 'a shop order must not read as an unrecorded charge');
        $this->assertSame(1, $r['counts']['agreed']);
        $this->assertSame('Shop order', $r['groups']['agreed'][0]['local']['ledger']);
    }

    /** Paid at the gateway, still pending here: the repairable disagreement. */
    public function test_a_pending_row_against_a_successful_charge_is_a_mismatch(): void
    {
        $this->donation('AFG-STUCK', 5000, 'pending');

        $r = (new GatewayLedger($this->gateway([[$this->tx('AFG-STUCK', 5000)]])))->pull(30);

        $this->assertSame(0, $r['counts']['agreed']);
        $this->assertSame(1, $r['counts']['mismatch']);
        $this->assertStringContainsString('pending', $r['groups']['mismatch'][0]['why'][0]);
        // And it is NOT also filed as an orphan — the row does exist.
        $this->assertSame(0, $r['counts']['theirs']);
    }

    /** A short payment must be flagged, never quietly agreed with. */
    public function test_a_short_payment_is_a_mismatch_with_both_figures_named(): void
    {
        $this->donation('AFG-SHORT', 50000);

        $r = (new GatewayLedger($this->gateway([[$this->tx('AFG-SHORT', 1000)]])))->pull(30);

        $this->assertSame(1, $r['counts']['mismatch']);
        $why = implode(' ', $r['groups']['mismatch'][0]['why']);
        $this->assertStringContainsString('1,000', $why);
        $this->assertStringContainsString('50,000', $why);
    }

    /** Confirmed here, absent from the gateway's list — the other direction. */
    public function test_a_confirmed_row_the_gateway_does_not_list_lands_in_ours(): void
    {
        $this->donation('AFG-BY-HAND', 9000);

        $r = (new GatewayLedger($this->gateway([[]])))->pull(30);

        $this->assertSame(0, $r['counts']['gateway']);
        $this->assertSame(1, $r['counts']['ours']);
        $this->assertSame(9000, $r['naira']['ours']);
        $this->assertSame('AFG-BY-HAND', $r['groups']['ours'][0]['local']['reference']);
    }

    /**
     * A pending row is the ordinary end of an abandoned checkout. Listing those
     * under "we say paid, they do not" would drown the column that matters.
     */
    public function test_a_pending_row_is_not_reported_as_ours_only(): void
    {
        $this->donation('AFG-ABANDONED', 5000, 'pending');

        $r = (new GatewayLedger($this->gateway([[]])))->pull(30);

        $this->assertSame(0, $r['counts']['ours']);
    }

    /** A window read only halfway must never render as a clean window. */
    public function test_truncation_is_carried_through_to_the_result(): void
    {
        $g = $this->gateway([[$this->tx('AFG-1', 1000)], [$this->tx('AFG-2', 1000)]], failAtPage: 2);
        $r = (new GatewayLedger($g))->pull(30);

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['truncated']);
    }

    /** The window is clamped, not trusted — a caller asking for ten years is a bug. */
    public function test_the_window_is_clamped_to_the_maximum(): void
    {
        $r = (new GatewayLedger($this->gateway([[]])))->pull(9999);

        $this->assertSame(GatewayLedger::MAX_DAYS, $r['days']);
    }

    /** Only successful charges are pulled: a failed attempt is not a discrepancy. */
    public function test_only_successful_transactions_are_requested(): void
    {
        $g = new class extends PaymentService {
            public array $urls = [];
            public function isEnabled(string $provider): bool { return true; }
            protected function request(string $m, string $url, ?array $b, array $h): array
            {
                $this->urls[] = $url;
                return ['ok' => true, 'code' => 200,
                        'json' => ['status' => true, 'data' => [], 'meta' => ['pageCount' => 1]], 'raw' => ''];
            }
        };

        (new GatewayLedger($g))->pull(7);

        $this->assertStringContainsString('status=success', $g->urls[0]);
        $this->assertStringContainsString('from=', $g->urls[0]);
        $this->assertStringContainsString('to=', $g->urls[0]);
    }

    /** Nothing on this path writes. Reconciling and repairing are separate acts. */
    public function test_pulling_changes_nothing_in_the_database(): void
    {
        $id = $this->donation('AFG-STUCK', 5000, 'pending');

        (new GatewayLedger($this->gateway([[$this->tx('AFG-STUCK', 5000)]])))->pull(30);

        $this->assertSame('pending', (string) DB::table('gates_donations')->where('id', $id)->first()->status);
    }
}

/** A PaymentService that claims Flutterwave works, to prove listing still refuses it. */
final class GatewayLedgerTestFlutterwaveEnabled extends PaymentService
{
    public function isEnabled(string $provider): bool { return true; }
}
