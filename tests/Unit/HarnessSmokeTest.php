<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

class HarnessSmokeTest extends TestCase
{
    public function test_core_tables_exist_and_are_writable(): void
    {
        DB::table('gates_profiles')->insert([
            'slug' => 'x', 'display_name' => 'X', 'email' => 'x@x.io',
            'country_code' => 'NG', 'status' => 'approved',
        ]);
        $this->assertSame(1, DB::table('gates_profiles')->count());

        // A couple more tables the later suites rely on.
        $this->assertSame(0, DB::table('gates_otp_tokens')->count());
        $this->assertSame(0, DB::table('gates_votes')->count());
    }

    // ══ the per-process memos TestCase::setUp() drops ════════════════════════

    /**
     * Resolve one cycle's edition name the way a results page does.
     *
     * Through the private resolver on purpose: the public entry points need a programme,
     * a category and a scored field before they will name anything, and none of that is
     * the subject. What is being held is one line of `TestCase::setUp()`.
     */
    private function editionName(int $cycleId): string
    {
        $m = new \ReflectionMethod(\AfricaGates\Services\PublicResults::class, 'edition_');
        $m->setAccessible(true);

        return $m->invoke(null, (object) ['cycle_id' => $cycleId]);
    }

    /**
     * TWO TESTS, ONE CYCLE ID — and neither may be served the other's answer.
     *
     * `PublicResults` memoises an edition's name per cycle id, which is free in production
     * (one request, and a cycle cannot be renamed underneath the page drawing it) and is a
     * trap here: a static lives for the PROCESS, and the suite is one process. The harness
     * rebuilds the schema and rewinds the auto-increment counters between tests, so two
     * tests routinely hold DIFFERENT cycles under the same id.
     *
     * It cost three guards — `EditionPageTest` and two in `PublicResultsTest` — each
     * asserting "2026 edition" and being handed "1st Edition · 2019" from a fixture that
     * was not theirs. All three passed in isolation and all three failed in the full run,
     * which is the expensive shape: the failure names a page that is working.
     *
     * THE PAIR IS THE TEST, and it is written symmetrically so that it does not depend on
     * which of the two runs first. Whichever is second would read the first one's name if
     * `TestCase::setUp()` stopped dropping the memo — so the order may change, be
     * randomised, or be filtered down to one, and this never passes by luck.
     */
    public function test_an_edition_name_does_not_outlive_the_test_that_asked_a(): void
    {
        DB::table('gates_award_cycles')->insert([
            'id' => 4242, 'programme_id' => 1, 'year' => 2019,
            'edition_number' => 1, 'status' => 'results',
        ]);

        $this->assertSame('1st Edition · 2019', $this->editionName(4242),
            'this cycle is a 2019 first edition — a later name here came from another test');
    }

    /** The other half of the pair. {@see self::test_an_edition_name_does_not_outlive_the_test_that_asked_a()} */
    public function test_an_edition_name_does_not_outlive_the_test_that_asked_b(): void
    {
        DB::table('gates_award_cycles')->insert([
            'id' => 4242, 'programme_id' => 1, 'year' => 2026,
            'edition_number' => 8, 'edition_label' => '2026 edition', 'status' => 'results',
        ]);

        $this->assertSame('2026 edition', $this->editionName(4242),
            'this cycle calls itself "2026 edition" — a 2019 name here came from another test');
    }
}
