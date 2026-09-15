<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GatewayLedger;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The last comparison with Paystack, kept so the triage screen can report it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GAP THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Triage starts from OUR rows and asks the gateway about them. It therefore cannot — by
 * construction, not by oversight — find a charge that exists at Paystack with no row here at
 * all: there is nothing local to iterate. That is the last reason "the ledger saw the misses
 * and the triage did not" stayed true after triage was taught to re-ask its own written-off
 * payments.
 *
 * Reaching that bucket means walking Paystack's list, which is up to twenty outbound calls
 * and cannot happen on page load. So the RESULT is stored and the triage screen reports it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO PROPERTIES THAT MATTER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. NEVER-COMPARED IS ITS OWN ANSWER. A platform that has never compared its books does
 *      not have a clean window; it has an unexamined one, and a row of zeroes would read
 *      exactly like a clean bill.
 *   2. NO CUSTOMER ROWS ARE STORED. Counts only. They contain email addresses, they go stale
 *      within minutes, and a screen presenting a week-old list as current would be worse than
 *      one that admits it needs a fresh look.
 */
final class GatewayLedgerLastRunTest extends TestCase
{
    private function pulled(array $counts, array $naira = [], int $days = 30): array
    {
        return ['ok' => true, 'days' => $days, 'truncated' => false,
                'counts' => $counts, 'naira' => $naira,
                'groups' => ['agreed' => [], 'mismatch' => [], 'theirs' => [], 'ours' => []]];
    }

    public function test_never_compared_is_null_rather_than_a_row_of_zeroes(): void
    {
        // THE property. Zeroes read as a clean bill; null forces the caller to say "this has
        // never been looked at", which is the honest description of the state that made the
        // missing money invisible in the first place.
        $this->assertNull(GatewayLedger::lastRun());
    }

    public function test_a_comparison_is_remembered_with_its_counts(): void
    {
        GatewayLedger::remember($this->pulled(
            ['gateway' => 42, 'agreed' => 39, 'mismatch' => 1, 'theirs' => 2, 'ours' => 0],
            ['theirs' => 37000]
        ));

        $last = GatewayLedger::lastRun();
        $this->assertNotNull($last);
        $this->assertSame(2, $last['counts']['theirs']);
        $this->assertSame(37000, $last['naira']['theirs']);
        $this->assertSame(30, $last['days']);
        $this->assertNotSame('', $last['at']);
        $this->assertFalse($last['stale']);
    }

    public function test_a_failed_pull_is_not_remembered_as_a_clean_bill(): void
    {
        // Paystack could not be read. Writing that down as a comparison would turn an outage
        // into a reassurance — and this is the screen somebody checks when money is missing.
        GatewayLedger::remember(['ok' => false, 'message' => 'Invalid key', 'counts' => [], 'naira' => []]);
        $this->assertNull(GatewayLedger::lastRun());
    }

    public function test_a_week_old_comparison_says_it_is_stale(): void
    {
        // A stale clean bill reads exactly like a current one, so the difference is stated
        // rather than left for somebody to work out from a timestamp.
        GatewayLedger::remember($this->pulled(['gateway' => 5, 'theirs' => 0]));
        DB::table('gates_settings')->where('key_name', 'gateway_ledger_last')->update([
            'value' => json_encode(['at' => date('Y-m-d H:i:s', time() - 9 * 86400),
                                    'days' => 30, 'counts' => ['theirs' => 0], 'naira' => []]),
        ]);

        $this->assertTrue(GatewayLedger::lastRun()['stale']);
    }

    public function test_the_second_comparison_replaces_the_first(): void
    {
        GatewayLedger::remember($this->pulled(['gateway' => 1, 'theirs' => 5]));
        GatewayLedger::remember($this->pulled(['gateway' => 9, 'theirs' => 0]));

        $this->assertSame(0, GatewayLedger::lastRun()['counts']['theirs']);
        $this->assertSame(1, DB::table('gates_settings')->where('key_name', 'gateway_ledger_last')->count(),
            'each comparison added a row instead of replacing one');
    }

    public function test_no_customer_details_are_stored(): void
    {
        // Counts only. The rows carry email addresses and go stale within minutes.
        GatewayLedger::remember(['ok' => true, 'days' => 7, 'truncated' => false,
            'counts' => ['gateway' => 1, 'theirs' => 1], 'naira' => ['theirs' => 500],
            'groups' => ['theirs' => [['gateway' => ['reference' => 'PSK-1',
                'customer' => ['email' => 'buyer@example.test']]]],
                'agreed' => [], 'mismatch' => [], 'ours' => []]]);

        $stored = (string) DB::table('gates_settings')->where('key_name', 'gateway_ledger_last')->value('value');
        $this->assertStringNotContainsString('buyer@example.test', $stored);
        $this->assertStringNotContainsString('PSK-1', $stored);
    }

    public function test_a_corrupt_stored_value_reads_as_never_compared(): void
    {
        // Fail towards "unexamined" rather than towards a reassurance nobody can justify.
        DB::table('gates_settings')->insert(['key_name' => 'gateway_ledger_last', 'value' => 'not json']);
        $this->assertNull(GatewayLedger::lastRun());
    }
}
