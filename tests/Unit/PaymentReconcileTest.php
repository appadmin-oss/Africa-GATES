<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Console\Commands\PaymentReconcileCommand;
use AfricaGates\Services\PaymentService;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Backstop for dropped browser callbacks. `payments:reconcile` re-verifies stale
 * PENDING rows server-to-server and confirms only the genuinely-paid ones, with
 * the same amount-parity + idempotency guarantees as the live confirm path —
 * without touching the audited controllers. A scripted PaymentService stub stands
 * in for the gateway so no network is touched.
 */
class PaymentReconcileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_URL'] = 'https://afg.local';
        $_ENV['PAYSTACK_SECRET_KEY'] = 'sk_test_x';
        unset($_ENV['FLUTTERWAVE_SECRET_KEY']);
        $this->createShopTables();
    }

    /**
     * gates_products + gates_orders live only in the shop migration, not the base
     * sqlite schema the harness loads — create them here (verbatim from the
     * migration's SQLite DDL) so the order paths are exercisable.
     *
     * Guarded on absence rather than run unconditionally. The MySQL parity harness
     * applies the dated migrations, so both tables already exist there with their
     * REAL production shape — and this SQLite DDL would be a 1064 on MySQL, which is
     * what previously forced the whole file to be skipped in that mode. Skipping when
     * the tables are already correct is strictly better than skipping the tests.
     */
    private function createShopTables(): void
    {
        if (DB::schema()->hasTable('gates_products') && DB::schema()->hasTable('gates_orders')) {
            return;
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS gates_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Apparel',
            description TEXT,
            price_naira INTEGER NOT NULL DEFAULT 0,
            cover_path TEXT,
            tag TEXT,
            stock INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS gates_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            phone TEXT,
            address TEXT,
            items_json TEXT NOT NULL,
            subtotal_naira INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'pending',
            provider TEXT,
            provider_ref TEXT,
            ip_hash TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at TEXT
        )");
    }

    /** A PaymentService whose network boundary is scripted (no live gateway). */
    private function stubPayments(array $verify): PaymentService
    {
        return new class($verify) extends PaymentService {
            public function __construct(private array $v) { parent::__construct(null); }
            public function isEnabled(string $p): bool { return in_array($p, ['paystack', 'flutterwave'], true); }
            public function isKnownProvider(string $p): bool { return in_array($p, ['paystack', 'flutterwave'], true); }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $provider, string $reference): array { return $this->v; }
        };
    }

    private function runCmd(PaymentService $svc, array $opts = []): void
    {
        (new CommandTester(new PaymentReconcileCommand($svc, null)))->execute($opts);
    }

    private function seedOrder(string $ref, int $subtotal, array $lines, string $created = '2000-01-01 00:00:00'): void
    {
        DB::table('gates_orders')->insert([
            'reference' => $ref, 'email' => 'a@b.io', 'name' => 'Buyer', 'address' => 'Lagos',
            'items_json' => json_encode($lines), 'subtotal_naira' => $subtotal,
            'status' => 'pending', 'provider' => 'paystack', 'created_at' => $created,
        ]);
    }

    private function seedProduct(string $slug, int $price, ?int $stock): void
    {
        DB::table('gates_products')->insert([
            'slug' => $slug, 'name' => ucfirst($slug), 'price_naira' => $price,
            'stock' => $stock, 'is_active' => 1, 'created_at' => '2000-01-01 00:00:00',
        ]);
    }

    private function success(int $amount): array
    {
        return ['ok' => true, 'status' => 'success', 'amount' => $amount, 'currency' => 'NGN', 'meta' => []];
    }

    public function test_reconciles_stale_paid_order_and_decrements_stock(): void
    {
        $this->seedProduct('tee', 5000, 10);
        $this->seedOrder('AFG-SHP-a', 10000, [['slug' => 'tee', 'name' => 'Tee', 'qty' => 2, 'line_total' => 10000]]);

        $this->runCmd($this->stubPayments($this->success(10000)));

        $this->assertSame('paid', DB::table('gates_orders')->where('reference', 'AFG-SHP-a')->value('status'));
        $this->assertSame(8, (int) DB::table('gates_products')->where('slug', 'tee')->value('stock'));
    }

    public function test_refuses_confirm_on_amount_mismatch(): void
    {
        $this->seedOrder('AFG-SHP-mm', 10000, [['slug' => 'tee', 'qty' => 1]]);
        $this->runCmd($this->stubPayments($this->success(1))); // buyer paid less than the order
        $this->assertSame('pending', DB::table('gates_orders')->where('reference', 'AFG-SHP-mm')->value('status'));
    }

    public function test_marks_failed_on_failed_verify(): void
    {
        $this->seedOrder('AFG-SHP-f', 10000, []);
        $this->runCmd($this->stubPayments(['ok' => true, 'status' => 'failed', 'amount' => 0, 'currency' => 'NGN', 'meta' => []]));
        $this->assertSame('failed', DB::table('gates_orders')->where('reference', 'AFG-SHP-f')->value('status'));
    }

    public function test_leaves_pending_when_gateway_still_pending(): void
    {
        $this->seedOrder('AFG-SHP-p', 10000, []);
        $this->runCmd($this->stubPayments(['ok' => true, 'status' => 'pending', 'amount' => 0, 'currency' => 'NGN', 'meta' => []]));
        $this->assertSame('pending', DB::table('gates_orders')->where('reference', 'AFG-SHP-p')->value('status'));
    }

    public function test_skips_fresh_orders_within_the_safety_window(): void
    {
        // Created just now → inside the default 15-minute window → left for the live callback.
        $this->seedOrder('AFG-SHP-fresh', 10000, [], Carbon::now()->toDateTimeString());
        $this->runCmd($this->stubPayments($this->success(10000)));
        $this->assertSame('pending', DB::table('gates_orders')->where('reference', 'AFG-SHP-fresh')->value('status'));
    }

    public function test_is_idempotent_no_double_decrement(): void
    {
        $this->seedProduct('cap', 2000, 5);
        $this->seedOrder('AFG-SHP-idem', 2000, [['slug' => 'cap', 'qty' => 1, 'line_total' => 2000]]);
        $svc = $this->stubPayments($this->success(2000));
        $this->runCmd($svc);
        $this->runCmd($svc); // a second pass must not re-fulfil
        $this->assertSame('paid', DB::table('gates_orders')->where('reference', 'AFG-SHP-idem')->value('status'));
        $this->assertSame(4, (int) DB::table('gates_products')->where('slug', 'cap')->value('stock'));
    }

    public function test_reconciles_stale_pending_donation(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'D', 'donor_email' => 'd@e.io', 'amount_naira' => 5000,
            'tier' => 'donation', 'bonus_votes' => 0, 'votes_used' => 0,
            'payment_ref' => 'AFG-GIVE-x', 'status' => 'pending', 'created_at' => '2000-01-01 00:00:00',
        ]);
        $this->runCmd($this->stubPayments($this->success(5000)));
        $this->assertSame('confirmed', DB::table('gates_donations')->where('payment_ref', 'AFG-GIVE-x')->value('status'));
    }
}
