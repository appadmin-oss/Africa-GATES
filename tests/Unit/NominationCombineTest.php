<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ReviewDesk;
use AfricaGates\Admin\Services\ReviewQueue;
use AfricaGates\Services\NominationCombine;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Duplicate nominations are resolved BEFORE review, automatically where the record is plain
 * (owner, 28 Oct 2026). What each test holds:
 *   · a repeat with nothing that tells it apart is combined into the earliest entry, leaves the
 *     queue, and stops flagging that entry red — so the best-supported nominee is one row, not
 *     three "Duplicate?" rows nobody can approve;
 *   · anything that DIFFERS (another email, a different category) is left for a person;
 *   · a person can combine two entries that are both still waiting, and can separate them again;
 *   · the combined entries take the decision made on the one they were folded into;
 *   · the rail badge and the queue count the same nominations.
 */
final class NominationCombineTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;
    private int $cat2 = 0;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'nc-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'a' . bin2hex(random_bytes(2)), 'title' => 'Teacher of the Year', 'sort_order' => 0]);
        $this->cat2 = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'b' . bin2hex(random_bytes(2)), 'title' => 'STEM Educator', 'sort_order' => 1]);
        DB::table('gates_settings')->where('key_name', 'nomination_eligibility_enabled')->delete();
    }

    private function nom(string $name, array $o = [], int $minutesAgo = 0): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($o + [
            'cycle_id' => $this->cycle, 'category_id' => $this->cat, 'nominee_name' => $name, 'country_code' => 'NG',
            'nominator_name' => 'Nominator ' . (++$this->seq), 'nominator_email' => 'n' . $this->seq . '@school.ng',
            'nominator_location' => 'Place ' . $this->seq, 'reason' => 'Runs a weekend reading club for forty pupils.',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s', time() - $minutesAgo * 60),
        ]);
    }

    /** @return list<int> the ids the pending tab shows */
    private function queue(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], ReviewQueue::build(ReviewQueue::params(['tab' => 'pending']))['rows']);
    }

    public function test_a_plain_repeat_is_combined_into_the_earliest_and_stops_flagging_it(): void
    {
        $first = $this->nom('Grace Mensah', [], 30);
        $second = $this->nom('  grace   MENSAH ', [], 10);
        $third = $this->nom('GRACE MENSAH', ['nominee_email' => 'grace@school.ng'], 5);

        $this->assertSame($first, NominationCombine::autoCombine($second), 'nothing tells them apart');
        $this->assertSame($first, NominationCombine::autoCombine($third), 'an email on one side only is not a difference');
        $this->assertSame([$first], array_values(array_intersect($this->queue(), [$first, $second, $third])), 'one nomination to review');

        $row = ReviewDesk::view($first);
        $this->assertNotContains('dup', $row['keys'], 'its repeats are its support now, not a red flag on it');
        $this->assertSame(2, $row['combined']);
        $this->assertCount(2, $row['supporters'], 'the reviewer sees who else nominated, and why');
        $this->assertStringContainsString('Combined automatically', (string) DB::table('gates_nomination_notes')->where('nomination_id', $first)->value('body'));
    }

    public function test_anything_that_differs_is_left_for_a_person(): void
    {
        $a = $this->nom('Tunde Bakare', ['nominee_email' => 'tunde@one.ng'], 30);
        $b = $this->nom('Tunde Bakare', ['nominee_email' => 'tbakare@two.ng'], 10);
        $this->assertNull(NominationCombine::autoCombine($b), 'two different inboxes: maybe two people');
        $this->assertContains('dup', ReviewDesk::view($b)['keys'], 'still flagged, for a person to decide');
        $this->assertTrue(ReviewDesk::view($b)['earlier']['combinable'], 'and the desk offers "Same person: combine" while both wait');

        $c = $this->nom('Ada Obi', [], 30);
        $d = $this->nom('Ada Obi', ['category_id' => $this->cat2], 10);
        $this->assertNull(NominationCombine::autoCombine($d), 'one person in two categories is two nominations');

        $this->assertTrue(NominationCombine::combine($b, $a, 1)['ok'], 'a person can combine two that both still wait');
        $this->assertNotContains($b, $this->queue());
        $this->assertTrue(NominationCombine::separate($b, 1));
        $this->assertContains($b, $this->queue(), 'separated, it is back on its own');
        $this->assertSame(1, (int) DB::table('gates_nominations')->where('id', $b)->value('not_duplicate'), 'and ruled a different person');
        $this->assertNull(NominationCombine::autoCombine($b), 'so the sweep never folds it back in');
    }

    public function test_combined_entries_follow_the_decision_and_the_counts_agree(): void
    {
        $a = $this->nom('Halima Bello', [], 30);
        $b = $this->nom('Halima Bello', [], 10);
        $this->assertSame(1, NominationCombine::sweep(), 'the tick resolves repeats that were waiting already');
        $this->assertSame((int) DB::table('gates_nominations')->where('id', $b)->value('merged_into'), $a);

        // The badge and the queue: one read rule, so one number.
        $q = DB::table('gates_nominations')->where('status', 'pending')->where('cycle_id', $this->cycle);
        NominationCombine::excludeCombined($q);
        $this->assertSame(1, $q->count());

        DB::table('gates_nominations')->where('id', $a)->update(['status' => 'approved']);
        $this->assertSame(1, NominationCombine::follow($a, 'approved', $this->cat));
        $this->assertSame('approved', DB::table('gates_nominations')->where('id', $b)->value('status'), 'approved with it, on the same ballot entry');
    }
}
