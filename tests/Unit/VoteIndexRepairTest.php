<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\VoteIndexRepair;
use AfricaGates\Support\SchemaIndex;

/**
 * The repair for indexes four catch-up migrations failed to create on MySQL.
 *
 * The property that matters most here is RE-RUNNABILITY. A migration runs exactly
 * once — the ledger sees to that — and the UNIQUE per-voter idempotency constraint
 * legitimately cannot be created while duplicate rows exist. Without a second door
 * the sequence would be: deploy, "1 duplicate group, resolve it", operator resolves
 * it, and the constraint is never created because the migration is marked done.
 * That is the same silent gap the repair exists to close, which is why the logic is
 * a service with a console command in front of it rather than only a migration.
 */
class VoteIndexRepairTest extends TestCase
{
    /** Names this test creates or removes, restored after each case (DDL is not transactional). */
    private const TOUCHED = ['idx_votes_device', 'idx_votes_donation', 'uq_votes_idem', 'idx_votes_idem'];

    protected function tearDown(): void
    {
        // Leave the schema as found. A leaked UNIQUE index on gates_votes broke nine
        // assertions in an unrelated file the last time DDL escaped a test.
        try {
            foreach (self::TOUCHED as $i) SchemaIndex::drop('gates_votes', $i);
            SchemaIndex::ensure('gates_votes', 'idx_votes_idem', ['voter_email_hash', 'idempotency_key'], true);
        } catch (\Throwable) {}
        parent::tearDown();
    }

    private function clearIndexes(): void
    {
        foreach (self::TOUCHED as $i) SchemaIndex::drop('gates_votes', $i);
    }

    private function vote(string $voter, ?string $key, int $categoryId): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 1, 'category_id' => $categoryId, 'voter_email_hash' => $voter,
            'idempotency_key' => $key, 'voted_at' => '2024-01-01 00:00:00',
        ]);
    }

    public function test_it_creates_the_missing_indexes(): void
    {
        $this->clearIndexes();

        $r = VoteIndexRepair::run();

        $this->assertTrue($r['complete']);
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_votes_device'));
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_votes_donation'),
            'declared in NEITHER base schema, so it was missing everywhere on MySQL');
    }

    public function test_running_twice_reports_already_present_not_an_error(): void
    {
        $this->clearIndexes();
        VoteIndexRepair::run();

        $r = VoteIndexRepair::run();

        $this->assertTrue($r['complete']);
        $this->assertStringContainsString('=', implode("\n", $r['lines']));
        $this->assertStringNotContainsString('!', implode("\n", $r['lines']));
    }

    public function test_either_historical_name_satisfies_the_idempotency_constraint(): void
    {
        // schema.sql calls it uq_votes_idem; sqlite-schema.sql calls it
        // idx_votes_idem. Creating the second over the same columns would double the
        // write cost of every vote to enforce something already enforced.
        $this->clearIndexes();
        SchemaIndex::ensure('gates_votes', 'uq_votes_idem', ['voter_email_hash', 'idempotency_key'], true);

        $r = VoteIndexRepair::run();

        $this->assertTrue($r['complete']);
        $this->assertStringContainsString('present as uq_votes_idem', implode("\n", $r['lines']));
        $this->assertFalse(SchemaIndex::exists('gates_votes', 'idx_votes_idem'),
            'the second name must not be created alongside the first');
    }

    public function test_duplicates_block_the_constraint_and_are_reported_actionably(): void
    {
        // The one outcome needing a human. A bare exception message is what let the
        // original defect hide, so this must name the count and give the query.
        $this->clearIndexes();
        $this->vote('same-voter', 'k1', 1);
        $this->vote('same-voter', 'k1', 2);

        $r = VoteIndexRepair::run();

        $this->assertFalse($r['complete']);
        $this->assertSame(1, $r['duplicates']);
        $body = implode("\n", $r['lines']);
        $this->assertStringContainsString('NOT created', $body);
        $this->assertStringContainsString('counted twice', $body, 'the consequence, not just the error');
        $this->assertStringContainsString('GROUP BY voter_email_hash', $body, 'a query they can run');
        $this->assertStringContainsString('db:repair-indexes', $body, 'and how to retry');
    }

    public function test_the_plain_indexes_are_still_created_when_the_unique_one_is_blocked(): void
    {
        // A half-repaired schema is better than none, and aborting would leave the
        // performance indexes missing for a data problem unrelated to them.
        $this->clearIndexes();
        $this->vote('same-voter', 'k1', 1);
        $this->vote('same-voter', 'k1', 2);

        VoteIndexRepair::run();

        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_votes_device'));
        $this->assertTrue(SchemaIndex::exists('gates_votes', 'idx_votes_donation'));
    }

    public function test_it_succeeds_on_the_retry_after_duplicates_are_resolved(): void
    {
        // THE POINT OF THE SERVICE. The migration would never reach this state,
        // because the ledger marks it done on the blocked run.
        $this->clearIndexes();
        $this->vote('same-voter', 'k1', 1);
        $this->vote('same-voter', 'k1', 2);
        $this->assertFalse(VoteIndexRepair::run()['complete']);

        DB::table('gates_votes')->where('category_id', 2)->delete();

        $r = VoteIndexRepair::run();

        $this->assertTrue($r['complete']);
        $this->assertSame(0, $r['duplicates']);
    }

    public function test_null_keys_are_not_counted_as_duplicates(): void
    {
        // Key-less votes are the normal case and multiple NULLs are legal in a
        // unique index on both engines. Counting them would report a data problem
        // that does not exist and block the constraint forever.
        $this->clearIndexes();
        $this->vote('voter-a', null, 1);
        $this->vote('voter-b', null, 2);
        $this->vote('voter-c', null, 3);

        $this->assertSame(0, VoteIndexRepair::duplicateGroups());
        $this->assertTrue(VoteIndexRepair::run()['complete']);
    }

    public function test_the_same_key_from_two_different_voters_is_not_a_duplicate(): void
    {
        // The whole reason the constraint is per-voter: a shared or buggy client key
        // must not let one voter block another.
        $this->clearIndexes();
        $this->vote('voter-a', 'shared', 1);
        $this->vote('voter-b', 'shared', 2);

        $this->assertSame(0, VoteIndexRepair::duplicateGroups());
        $this->assertTrue(VoteIndexRepair::run()['complete']);
    }
}
