<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AiService;
use AfricaGates\Services\NominationTriageService as Triage;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Review-at-scale triage: deterministic duplicate detection, insight storage
 * that works WITHOUT AI (advisory score stays null), the queued job + backfill
 * plumbing, and the fair-FIFO review-desk queue helpers.
 */
final class NominationTriageServiceTest extends TestCase
{
    private function nom(array $over = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId(array_merge([
            'cycle_id' => 1, 'nominee_name' => 'Ada Obi', 'nominee_email' => 'ada@x.io',
            'reason' => 'She built three rural libraries and trained 400 teachers.',
            'nominator_name' => 'A Person', 'nominator_email' => 'p@x.io',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ], $over));
    }

    public function test_duplicates_match_same_cycle_case_insensitively(): void
    {
        $a = $this->nom();
        $this->nom(['nominee_name' => '  ADA   obi ', 'nominator_email' => 'other@x.io']); // same person, messy casing/spacing... note: norm collapses whitespace
        $this->nom(['nominee_name' => 'Ada Obi', 'cycle_id' => 2]); // other cycle — must NOT match
        $this->nom(['nominee_name' => 'Chidi Okeke']);              // different person

        $d = Triage::duplicatesFor(DB::table('gates_nominations')->find($a));
        $this->assertCount(1, $d['same_cycle']);
        $this->assertSame('other@x.io', $d['same_cycle'][0]['nominator_email']);
        $this->assertNull($d['live_nominee']);
    }

    public function test_fuzzy_similar_spellings_are_flagged(): void
    {
        $a = $this->nom(['nominee_name' => 'Mohammed Bello']);
        $this->nom(['nominee_name' => 'Muhammed Bello']);   // edit distance 1 → similar
        $this->nom(['nominee_name' => 'Mohammed Bello']);   // exact → same_cycle, NOT similar
        $this->nom(['nominee_name' => 'Grace Adeyemi']);    // unrelated

        $d = Triage::duplicatesFor(DB::table('gates_nominations')->find($a));
        $this->assertCount(1, $d['same_cycle']);
        $this->assertCount(1, $d['similar']);
        $this->assertSame('Muhammed Bello', $d['similar'][0]['name']);
    }

    public function test_short_names_skip_fuzzy_matching(): void
    {
        $a = $this->nom(['nominee_name' => 'Ada O']);
        $this->nom(['nominee_name' => 'Adá Q']);
        $d = Triage::duplicatesFor(DB::table('gates_nominations')->find($a));
        $this->assertSame([], $d['similar'], 'names under 6 normalised chars are too noisy for fuzzy matching');
    }

    public function test_duplicates_flag_already_live_nominee(): void
    {
        DB::table('gates_nominees')->insert(['name' => 'Ada Obi', 'category_id' => 2, 'status' => 'approved']);
        $a = $this->nom();
        $d = Triage::duplicatesFor(DB::table('gates_nominations')->find($a));
        $this->assertNotNull($d['live_nominee']);
        $this->assertSame('approved', $d['live_nominee']['status']);
    }

    public function test_generate_without_ai_stores_duplicates_and_null_score(): void
    {
        $a = $this->nom();
        $this->nom(['nominator_email' => 'second@x.io']);
        $row = Triage::generate($a, new AiService()); // unconfigured AI — inert
        $this->assertNull($row['quality_score']);
        $stored = Triage::insight($a);
        $this->assertNotNull($stored);
        $dupes = json_decode((string) $stored['duplicates_json'], true);
        $this->assertCount(1, $dupes['same_cycle']);
        // Re-run is a safe upsert, not a duplicate row.
        Triage::generate($a, new AiService());
        $this->assertSame(1, DB::table('gates_nomination_insights')->where('nomination_id', $a)->count());
    }

    public function test_enqueue_and_backfill_queue_triage_jobs(): void
    {
        $a = $this->nom();
        Triage::enqueue($a);
        $this->assertSame(1, DB::table('gates_jobs')->where('type', Triage::JOB_TRIAGE)->count());

        // Backfill queues pending nominations WITHOUT an insight row, skipping ones that have one.
        $b = $this->nom(['nominee_name' => 'Chidi Okeke']);
        $c = $this->nom(['nominee_name' => 'Ngozi Eze']);
        Triage::generate($b, new AiService());
        $queued = Triage::backfill(50);
        $this->assertSame(2, $queued, 'nomination A (no insight) + C queued; B already has an insight');
        $this->assertGreaterThanOrEqual((int) 3, DB::table('gates_jobs')->where('type', Triage::JOB_TRIAGE)->count());
        $this->assertSame($c, $c); // silence unused warning
    }

    public function test_review_desk_queue_is_fair_fifo(): void
    {
        $a = $this->nom(['nominee_name' => 'First In']);
        $b = $this->nom(['nominee_name' => 'Second In']);
        $c = $this->nom(['nominee_name' => 'Third In', 'status' => 'approved']); // not pending — skipped
        $d = $this->nom(['nominee_name' => 'Fourth In']);

        $this->assertSame($a, (int) Triage::nextPending()->id, 'desk starts at the oldest pending');
        $this->assertSame($b, (int) Triage::nextPending($a)->id);
        $this->assertSame($d, (int) Triage::nextPending($b)->id, 'non-pending rows are never served');
        $this->assertNull(Triage::nextPending($d), 'end of queue');

        $pos = Triage::queuePosition($b);
        $this->assertSame(2, $pos['position']);
        $this->assertSame(3, $pos['total']);
        $this->assertSame($c, $c);
    }
}
