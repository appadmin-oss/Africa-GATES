<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\FinanceService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The money figures.
 *
 * WHY THIS FILE IS MOSTLY ABOUT WHAT MUST *NOT* BE COUNTED. Adding up a column is
 * easy; the errors that matter here are all inclusions:
 *
 *   • a pending row counted as revenue inflates the total with a checkout nobody
 *     completed;
 *   • a refunded row counted as revenue reports money the organisation no longer has;
 *   • a free RSVP counted as a transaction turns "412 payments" into a wrong number
 *     rather than a generous one.
 *
 * Each of those produces a plausible, larger figure — which is precisely why nothing
 * catches them until someone reconciles against a bank statement, and that is the one
 * moment the number must already be right.
 */
final class FinanceServiceTest extends TestCase
{
    /** Mirrors the SQLite branch of database/migrations/2026_06_22_shop.php. */
    private const ORDERS_DDL = 'CREATE TABLE IF NOT EXISTS gates_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reference TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL,
        name TEXT NOT NULL,
        phone TEXT,
        address TEXT,
        items_json TEXT NOT NULL,
        subtotal_naira INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT \'pending\',
        provider TEXT,
        provider_ref TEXT,
        ip_hash TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at TEXT
    )';

    protected function setUp(): void
    {
        parent::setUp();

        // `gates_orders` is created by a dated migration, not by the base schema files,
        // and the SQLite harness loads only the base files — the MySQL parity run is the
        // one that also applies migrations. So the shop table is simply absent here.
        //
        // Created directly rather than by calling MigrationRunner: the runner
        // re-bootstraps its own connection and lands on the on-disk dev database instead
        // of this suite's in-memory one, so every table the test needs then disappears.
        // {@see test_the_orders_fixture_matches_the_real_migration} guards the drift this
        // hand-written DDL would otherwise allow.
        if (!DB::schema()->hasTable('gates_orders')) {
            DB::connection()->getPdo()->exec(self::ORDERS_DDL);
        }

        DB::table('gates_donations')->delete();
        try { DB::table('gates_orders')->delete(); } catch (\Throwable) {}
        try { DB::table('gates_event_registrations')->delete(); } catch (\Throwable) {}
    }

    /** @param array<string,mixed> $over */
    private function donation(array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId($over + [
            'donor_name'   => 'A Supporter',
            'donor_email'  => 'a@example.test',
            'amount_naira' => 5000,
            'tier'         => 'donation',
            'status'       => 'confirmed',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    // ── The classifier ───────────────────────────────────────────────────────

    /**
     * The `tier` column holds two shapes: a bare word from DonationController and
     * PaidVoteController, and "{purpose}:{tier}" from PaymentController. Both have to
     * land in the right bucket, and anything unrecognised has to land in `other`
     * rather than vanish — a total that silently omits a row is worse than a bucket
     * called "other" that prompts someone to look.
     */
    public function test_the_tier_column_is_classified_in_both_of_its_shapes(): void
    {
        $this->assertSame('donation',  FinanceService::sourceForTier('donation'));
        $this->assertSame('paid-vote', FinanceService::sourceForTier('paid-vote'));
        $this->assertSame('shop',      FinanceService::sourceForTier('shop:standard'));
        $this->assertSame('paid-vote', FinanceService::sourceForTier('paid-vote:gold'));
        $this->assertSame('event',     FinanceService::sourceForTier('ticket:early'));
        $this->assertSame('other',     FinanceService::sourceForTier('something-new'));
        $this->assertSame('other',     FinanceService::sourceForTier(null));
        $this->assertSame('other',     FinanceService::sourceForTier(''));
    }

    // ── What counts ──────────────────────────────────────────────────────────

    public function test_only_confirmed_unrefunded_money_is_revenue(): void
    {
        $this->donation(['amount_naira' => 10000]);                            // counts
        $this->donation(['amount_naira' => 7000, 'status' => 'pending']);      // does not
        $this->donation(['amount_naira' => 3000, 'status' => 'failed']);       // does not
        $this->donation(['amount_naira' => 9000, 'refunded_at' => date('Y-m-d H:i:s')]); // does not

        $t = FinanceService::totals();

        $this->assertSame(10000, $t['confirmed'], 'pending, failed and refunded must all be excluded');
        $this->assertSame(1, $t['transactions']);
        $this->assertSame(7000, $t['pending']);
        $this->assertSame(1, $t['failed_count']);
        $this->assertSame(9000, $t['refunded']);
    }

    /**
     * A refunded row is still `status = confirmed` — the refund is recorded by
     * `refunded_at`, not by moving the status. Reading the status alone therefore
     * counts money the organisation has given back, which is the subtle version of
     * this bug and the one that survives a casual review.
     */
    public function test_a_refund_does_not_change_the_status_column(): void
    {
        $id = $this->donation(['amount_naira' => 9000, 'refunded_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('confirmed', (string) DB::table('gates_donations')->where('id', $id)->value('status'),
            'the fixture must reproduce the real shape, or this test proves nothing');
        $this->assertSame(0, FinanceService::totals()['confirmed']);
    }

    public function test_revenue_is_split_by_where_it_came_from(): void
    {
        $this->donation(['amount_naira' => 10000, 'tier' => 'donation']);
        $this->donation(['amount_naira' => 2500,  'tier' => 'paid-vote']);
        $this->donation(['amount_naira' => 1500,  'tier' => 'paid-vote']);

        $by = FinanceService::bySource();

        $this->assertSame(10000, $by['donation']['gross']);
        $this->assertSame(1,     $by['donation']['count']);
        $this->assertSame(4000,  $by['paid-vote']['gross']);
        $this->assertSame(2,     $by['paid-vote']['count']);
        $this->assertSame(0,     $by['shop']['gross']);
    }

    /** Every source appears in the map even at zero, so a caller can index it blind. */
    public function test_every_source_is_present_even_with_no_data(): void
    {
        $by = FinanceService::bySource();

        foreach (FinanceService::SOURCES as $s) {
            $this->assertArrayHasKey($s, $by);
            $this->assertSame(['gross' => 0, 'count' => 0], $by[$s]);
        }
    }

    // ── The other two tables ─────────────────────────────────────────────────

    /** The shop says 'paid' where everything else says 'confirmed'. */
    public function test_shop_orders_are_read_with_their_own_status_word(): void
    {
        DB::table('gates_orders')->insert([
            'reference' => 'r1', 'email' => 'b@example.test', 'name' => 'Buyer',
            'items_json' => '[]', 'subtotal_naira' => 12000, 'status' => 'paid',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('gates_orders')->insert([
            'reference' => 'r2', 'email' => 'c@example.test', 'name' => 'Browser',
            'items_json' => '[]', 'subtotal_naira' => 8000, 'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(12000, FinanceService::bySource()['shop']['gross']);
        $this->assertSame(8000,  FinanceService::totals()['pending']);
    }

    /**
     * Event registrations have NO status column — a row is the payment. So the only
     * way to exclude a free RSVP is the amount, and failing to do that reports every
     * free attendee as a transaction.
     */
    public function test_free_rsvps_are_not_counted_as_payments(): void
    {
        foreach ([[0, 'free@example.test'], [0, 'free2@example.test'], [15000, 'paid@example.test']] as [$amt, $email]) {
            DB::table('gates_event_registrations')->insert([
                'event_id' => 1, 'name' => 'Attendee', 'email' => $email,
                'amount_naira' => $amt, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $by = FinanceService::bySource();

        $this->assertSame(15000, $by['event']['gross']);
        $this->assertSame(1, $by['event']['count'], 'two free RSVPs must not appear as payments');
    }

    // ── The chart ────────────────────────────────────────────────────────────

    /**
     * GAP-FILLED. A GROUP BY only returns days that had a payment, and a chart drawn
     * straight from that closes the gaps — three sales on three separate weeks render
     * as a continuous rising line.
     */
    public function test_the_daily_series_includes_days_with_no_revenue(): void
    {
        $this->donation(['amount_naira' => 4000, 'created_at' => date('Y-m-d H:i:s')]);

        $daily = FinanceService::daily(7);

        $this->assertCount(7, $daily);
        $this->assertSame(date('Y-m-d'), $daily[6]['date'], 'today is last');
        $this->assertSame(4000, $daily[6]['naira']);
        $this->assertSame(0, $daily[0]['naira'], 'a quiet day is zero, not absent');
    }

    // ── The queues that need chasing ─────────────────────────────────────────

    public function test_a_fresh_pending_row_is_not_yet_suspicious(): void
    {
        $this->donation(['status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], FinanceService::uncredited(60),
            'someone mid-checkout is not a reconciliation problem');
    }

    public function test_a_pending_row_left_overnight_is_surfaced(): void
    {
        $this->donation([
            'status' => 'pending', 'amount_naira' => 6000,
            'created_at' => date('Y-m-d H:i:s', strtotime('-9 hours')),
        ]);

        $rows = FinanceService::uncredited(60);

        $this->assertCount(1, $rows);
        $this->assertSame(6000, $rows[0]['naira']);
        $this->assertGreaterThanOrEqual(8, $rows[0]['age_h']);
    }

    /**
     * Money taken for votes that were never minted. `votes_used = 0` on a confirmed
     * paid-vote row is the platform's existing "paid but never delivered" signal, and
     * each such row is a refund the organisation owes.
     */
    public function test_paid_votes_that_never_minted_are_reported_as_owed(): void
    {
        $this->donation(['tier' => 'paid-vote', 'amount_naira' => 2500, 'votes_used' => 0, 'bonus_votes' => 5]);
        $this->donation(['tier' => 'paid-vote', 'amount_naira' => 2500, 'votes_used' => 5, 'bonus_votes' => 5]);

        $owed = FinanceService::owedRefunds();

        $this->assertSame(1, $owed['count']);
        $this->assertSame(2500, $owed['naira']);
    }

    /** An already-refunded failure is not still owed — it has been settled. */
    public function test_an_owed_order_that_was_refunded_drops_off_the_queue(): void
    {
        $this->donation([
            'tier' => 'paid-vote', 'amount_naira' => 2500, 'votes_used' => 0,
            'refunded_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(0, FinanceService::owedRefunds()['count']);
    }

    // ── The ledger ───────────────────────────────────────────────────────────

    /**
     * The three tables use three status vocabularies. They are normalised to one so a
     * single set of chips covers the whole table — otherwise the shop's 'paid' rows
     * render with the styling of an unrecognised status.
     */
    public function test_the_transaction_list_normalises_status_across_sources(): void
    {
        $this->donation(['amount_naira' => 1000]);
        DB::table('gates_orders')->insert([
            'reference' => 'r9', 'email' => 'd@example.test', 'name' => 'Buyer',
            'items_json' => '[]', 'subtotal_naira' => 2000, 'status' => 'paid',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $rows = FinanceService::recent(10);

        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('confirmed', $r['status'], 'the shop\'s "paid" must read as "confirmed"');
        }
    }

    /** A refunded row must say so in the ledger rather than reading as a good payment. */
    public function test_a_refunded_row_is_labelled_refunded_in_the_ledger(): void
    {
        $this->donation(['refunded_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('refunded', FinanceService::recent(5)[0]['status']);
    }

    public function test_the_ledger_is_newest_first_across_every_source(): void
    {
        $this->donation(['amount_naira' => 100, 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
        DB::table('gates_orders')->insert([
            'reference' => 'r5', 'email' => 'e@example.test', 'name' => 'Recent Buyer',
            'items_json' => '[]', 'subtotal_naira' => 200, 'status' => 'paid',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $rows = FinanceService::recent(10);

        $this->assertSame('shop', $rows[0]['source'], 'the newer shop order sorts above the older donation');
        $this->assertSame('donation', $rows[1]['source']);
    }

    // ── The window ───────────────────────────────────────────────────────────

    public function test_a_date_window_excludes_what_falls_outside_it(): void
    {
        $this->donation(['amount_naira' => 5000, 'created_at' => date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $this->donation(['amount_naira' => 3000, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(8000, FinanceService::totals()['confirmed'], 'no window means everything');
        $this->assertSame(3000, FinanceService::totals(date('Y-m-d', strtotime('-7 days')))['confirmed']);
    }

    /** The window is INCLUSIVE of both ends — a payment made today is in "today". */
    public function test_the_window_includes_a_payment_made_on_the_boundary_day(): void
    {
        $this->donation(['amount_naira' => 3000, 'created_at' => date('Y-m-d') . ' 23:41:00']);

        $this->assertSame(3000, FinanceService::totals(date('Y-m-d'), date('Y-m-d'))['confirmed']);
    }

    // ── The fixture itself ───────────────────────────────────────────────────

    /**
     * The gates_orders DDL above is hand-written, so it can drift from the migration
     * that actually creates the table — and a fixture that has drifted lets every test
     * in this file keep passing against a shape production does not have, which is
     * worse than having no test at all.
     *
     * So: every column the fixture declares must appear in the migration, and every
     * column FinanceService reads must appear in the fixture.
     */
    public function test_the_orders_fixture_matches_the_real_migration(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/2026_06_22_shop.php'
        );

        preg_match_all('/^\s{8}(\w+)\s/m', self::ORDERS_DDL, $m);
        $this->assertNotEmpty($m[1], 'the DDL should declare columns');

        foreach ($m[1] as $col) {
            $this->assertMatchesRegularExpression('/\b' . preg_quote($col, '/') . '\b/', $migration,
                "the fixture declares `{$col}`, which the shop migration does not");
        }

        // And the columns the service actually selects.
        foreach (['reference', 'email', 'name', 'subtotal_naira', 'status', 'provider', 'created_at'] as $col) {
            $this->assertTrue(DB::schema()->hasColumn('gates_orders', $col),
                "FinanceService reads `{$col}` from gates_orders");
        }
    }
}
