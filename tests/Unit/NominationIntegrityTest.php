<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\AwardService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A nomination lands in the award it was filed under, and nowhere else.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HOLE THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `submitNomination()` resolves the CYCLE from `programme_id` — carefully, through
 * `BallotGuard::currentCycleForProgramme()`, with a comment explaining why a year match
 * was not good enough. It then writes `category_id` straight from the request:
 *
 *     'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
 *
 * Nothing checks that the category belongs to that cycle, and `gates_nominations` carries
 * a foreign key on `cycle_id` and **none on `category_id`** — so any integer lands.
 *
 * What that costs is not a tidy-ness problem. `POST /api/nominations` is a public,
 * unauthenticated endpoint, so changing one number in a request files a nomination into
 * a category belonging to a DIFFERENT AWARD. The row's own `cycle_id` says one award and
 * its `category_id` says another; the review desk reads the category, and approval mints
 * a nominee under it. Somebody ends up standing in an award nobody nominated them for,
 * with every figure on the row internally consistent.
 *
 * It is also silent in the ordinary direction: a category id from a PAST edition of the
 * same programme is accepted, which files a live nomination into a closed cycle.
 */
final class NominationIntegrityTest extends TestCase
{
    /**
     * Two programmes, both assigned by the database.
     *
     * These were literals — 211 and 212 — which happen to fit `TINYINT UNSIGNED`'s 255
     * and so passed the parity run that caught their siblings at 311 and 411. That is
     * luck rather than correctness: the ceiling is invisible from this file, the next
     * number somebody picks is as likely to be over it as under, and a literal can also
     * collide with a programme the schema or the demo seeder already wrote.
     *
     * An `AUTO_INCREMENT` id can do neither, so nothing here needs to know the width.
     */
    private int $alpha = 0;
    private int $beta  = 0;

    private const C_ALPHA = 2110;
    private const C_BETA  = 2120;
    private const CAT_ALPHA = 21101;
    private const CAT_BETA  = 21201;

    protected function setUp(): void
    {
        parent::setUp();

        $open = date('Y-m-d H:i:s', strtotime('-1 day'));
        $shut = date('Y-m-d H:i:s', strtotime('+30 days'));

        $this->alpha = (int) DB::table('gates_award_programmes')->insertGetId(
            ['slug' => 'ni-alpha', 'title' => 'Alpha Awards', 'is_active' => 1]);
        $this->beta  = (int) DB::table('gates_award_programmes')->insertGetId(
            ['slug' => 'ni-beta',  'title' => 'Beta Awards',  'is_active' => 1]);
        DB::table('gates_award_cycles')->insert([
            ['id' => self::C_ALPHA, 'programme_id' => $this->alpha, 'year' => 2026, 'status' => 'nominations',
             'nominations_open' => $open, 'nominations_close' => $shut],
            ['id' => self::C_BETA,  'programme_id' => $this->beta,  'year' => 2026, 'status' => 'nominations',
             'nominations_open' => $open, 'nominations_close' => $shut],
        ]);
        DB::table('gates_award_categories')->insert([
            ['id' => self::CAT_ALPHA, 'cycle_id' => self::C_ALPHA, 'slug' => 'ni-a1', 'title' => 'Alpha Category'],
            ['id' => self::CAT_BETA,  'cycle_id' => self::C_BETA,  'slug' => 'ni-b1', 'title' => 'Beta Category'],
        ]);
    }

    /** @param array<string,mixed> $over */
    private function nominate(array $over = []): int
    {
        $svc = new AwardService();

        return $svc->submitNomination($over + [
            'programme_id'    => $this->alpha,
            'nominee_name'    => 'Ada Lovelace',
            'country_code'    => 'NG',
            'reason'          => 'A specific and verifiable reason for this nomination, well past forty characters.',
            'nominator_name'  => 'Grace Hopper',
            'nominator_email' => 'grace@example.com',
            'nominee_email'   => 'ada@example.com',
        ], '127.0.0.1');
    }

    public function test_a_category_from_another_award_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/categor/i');

        $this->nominate(['category_id' => self::CAT_BETA]);
    }

    public function test_the_stored_category_always_belongs_to_the_stored_cycle(): void
    {
        // The invariant itself, stated as the thing a reader can check on any row:
        // whatever ends up in `category_id` must name a category of `cycle_id`. A
        // refusal that merely throws is not enough — the row is what everything
        // downstream reads.
        $id  = $this->nominate(['category_id' => self::CAT_ALPHA]);
        $row = DB::table('gates_nominations')->where('id', $id)->first();

        $this->assertSame(self::C_ALPHA, (int) $row->cycle_id);
        $this->assertSame(self::CAT_ALPHA, (int) $row->category_id);

        $cat = DB::table('gates_award_categories')->where('id', $row->category_id)->first();
        $this->assertSame((int) $row->cycle_id, (int) $cat->cycle_id,
            'the row names a category belonging to a different cycle');
    }

    public function test_a_category_that_does_not_exist_is_refused(): void
    {
        // There is no foreign key on this column, so an arbitrary integer is written
        // without complaint and the review desk then shows a nomination whose category
        // resolves to nothing.
        $this->expectException(\RuntimeException::class);

        $this->nominate(['category_id' => 999_999]);
    }

    public function test_no_category_at_all_is_still_allowed(): void
    {
        // Deliberate: an award may run without categories, and a nominator may be
        // unsure. The desk files it. Refusing here would break every such award.
        $id = $this->nominate([]);
        $row = DB::table('gates_nominations')->where('id', $id)->first();

        $this->assertNull($row->category_id);
    }
}
