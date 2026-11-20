<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\StandCall;
use AfricaGates\Services\StandPreset;
use AfricaGates\Services\StandType;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The priced stand catalogue.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO PROPERTIES THAT MATTER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. A SIZE IN FEET STAYS IN FEET. Centimetres do the arithmetic — 183 × 183 makes a floor
 *    plan sum exact — but a vendor promised a 6 × 6 ft pitch cannot recognise "1.83 × 1.83 m"
 *    as the thing they applied for, and the size is a published term: it goes on the
 *    application, the acceptance and the plan. So the unit is stored and every label uses it.
 *
 * 2. APPLYING A PRESET IS A COPY. A preset repriced next year must not rewrite the terms of a
 *    call that already ran. Same one-way door StandCall::open() closes on the criteria.
 */
final class StandPresetTest extends TestCase
{
    private int $eventId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The seed runs in the migration, which the harness applies. Start from a known
        // catalogue instead of whatever the seed happens to hold, so a change to the seed
        // does not silently change what these tests assert.
        DB::table('gates_stand_presets')->delete();

        // `gates_site_events` is the public events table; `gates_events` is the activity
        // log. Unique slug per call, per the note in VendorStandTest about MySQL isolation.
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Market Day', 'slug' => 'market-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+60 days')), 'status' => 'published',
        ]);
    }

    private function preset(array $over = []): int
    {
        $r = StandPreset::save($over + [
            'name' => '6 × 6 ft stand', 'unit' => 'ft',
            'width_ft' => 6, 'depth_ft' => 6, 'price_naira' => '10k',
        ], 0, 7);

        $this->assertTrue($r['ok'], (string) $r['message']);
        return (int) $r['id'];
    }

    // ══ feet ═════════════════════════════════════════════════════════════════

    /** THE ONE THAT MATTERS. 6ft in, 6ft out — through centimetre storage. */
    public function test_a_size_entered_in_feet_reads_back_in_feet(): void
    {
        $p = StandPreset::find($this->preset());

        $this->assertSame(183, (int) $p->width_cm, '6ft is 182.88cm, stored to the centimetre');
        $this->assertSame('ft', (string) $p->unit);
        $this->assertSame('6 × 6 ft', StandPreset::label($p),
            'a vendor promised 6ft must not be shown 1.83 m');
    }

    public function test_the_two_sizes_the_operator_priced(): void
    {
        $six = StandPreset::find($this->preset());
        $twelve = StandPreset::find($this->preset([
            'name' => '12 × 6 ft stand', 'width_ft' => 12, 'depth_ft' => 6, 'price_naira' => '35k',
        ]));

        $this->assertSame('6 × 6 ft', StandPreset::label($six));
        $this->assertSame(10_000, (int) $six->price_naira);
        $this->assertSame(3.35, StandPreset::areaSqm($six));

        $this->assertSame('12 × 6 ft', StandPreset::label($twelve));
        $this->assertSame(35_000, (int) $twelve->price_naira);
        $this->assertSame(6.7, StandPreset::areaSqm($twelve));
    }

    public function test_metric_presets_still_read_metric(): void
    {
        $id = $this->preset(['name' => 'Standard gazebo', 'unit' => 'm', 'width_m' => 3, 'depth_m' => 3]);
        $p  = StandPreset::find($id);

        $this->assertSame(300, (int) $p->width_cm);
        $this->assertSame('3 × 3 m', StandPreset::label($p), 'and no trailing .00');
    }

    /** Feet round-trip through centimetres without accumulating error. */
    public function test_feet_survive_the_round_trip(): void
    {
        foreach ([1, 3, 6, 6.5, 12, 20] as $ft) {
            $cm = StandPreset::feetToCm((float) $ft);
            $this->assertSame((float) $ft, StandPreset::cmToFeet($cm), "{$ft}ft did not round-trip");
        }
    }

    /**
     * The unit boxes are separate fields, because a shared pair changes meaning the moment
     * somebody flips the unit — "6 × 6" as feet then switched to metres is a 36m² pitch.
     */
    public function test_the_boxes_for_the_unpicked_unit_are_ignored(): void
    {
        $id = $this->preset([
            'unit' => 'ft', 'width_ft' => 6, 'depth_ft' => 6,
            'width_m' => 99, 'depth_m' => 99,          // stale values from the other pair
        ]);

        $this->assertSame(183, (int) StandPreset::find($id)->width_cm);
    }

    // ══ prices as people write them ══════════════════════════════════════════

    public function test_prices_are_read_the_way_a_price_list_is_written(): void
    {
        foreach ([['10k', 10_000], ['₦35,000', 35_000], ['35000', 35_000],
                  ['12.5k', 12_500], ['', 0]] as [$in, $want]) {
            $id = $this->preset(['name' => 'P' . $in . $want, 'price_naira' => $in]);
            $this->assertSame($want, (int) StandPreset::find($id)->price_naira, "failed for '{$in}'");
        }
    }

    public function test_a_deposit_above_the_price_is_refused(): void
    {
        $r = StandPreset::save([
            'name' => 'Bad', 'unit' => 'ft', 'width_ft' => 6, 'depth_ft' => 6,
            'price_naira' => '10k', 'deposit_naira' => '20k',
        ], 0, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('deposit cannot be more', $r['message']);
    }

    // ══ refusals ═════════════════════════════════════════════════════════════

    public function test_a_preset_with_no_size_is_refused(): void
    {
        $r = StandPreset::save(['name' => 'Sizeless', 'unit' => 'ft'], 0, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('width and a depth', $r['message']);
    }

    /** Feet typed into the metres boxes: 6 × 6 m is a 36m² pitch nobody is given. */
    public function test_an_implausible_size_is_refused_with_the_unit_named(): void
    {
        $r = StandPreset::save([
            'name' => 'Enormous', 'unit' => 'm', 'width_m' => 200, 'depth_m' => 200,
        ], 0, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Check the unit', $r['message']);
    }

    public function test_two_presets_cannot_share_a_key(): void
    {
        $this->preset();

        // The same name slugs to the same key, so it clashes.
        $again = StandPreset::save([
            'name' => '6 × 6 ft stand', 'unit' => 'ft', 'width_ft' => 6, 'depth_ft' => 6,
        ], 0, 7);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already exists', $again['message']);

        // A distinct key is fine — two 6 × 6 pitches at different prices is a real market.
        $ok = StandPreset::save([
            'name' => '6 × 6 ft stand (premium row)',
            'unit' => 'ft', 'width_ft' => 6, 'depth_ft' => 6, 'price_naira' => '14k',
        ], 0, 7);
        $this->assertTrue($ok['ok'], (string) $ok['message']);
    }

    // ══ retiring, not deleting ═══════════════════════════════════════════════

    public function test_retiring_hides_a_preset_without_destroying_it(): void
    {
        $id = $this->preset();

        $this->assertCount(1, StandPreset::all());
        $this->assertTrue(StandPreset::archive($id)['ok']);

        $this->assertCount(0, StandPreset::all(), 'retired presets are not offerable');
        $this->assertCount(1, StandPreset::all(true), 'but the row survives — an event names it');

        StandPreset::restore($id);
        $this->assertCount(1, StandPreset::all());
    }

    // ══ applying one ═════════════════════════════════════════════════════════

    /**
     * THE SECOND ONE THAT MATTERS. The stand type carries the preset's terms and a quota the
     * organiser gave — and 183cm, not the 300cm default.
     */
    public function test_applying_a_preset_copies_its_terms_and_takes_a_quota(): void
    {
        $id = $this->preset(['default_quota' => 20]);

        $r = StandPreset::applyTo($this->eventId, $id, 24, 7);
        $this->assertTrue($r['ok'], (string) $r['message']);

        $t = StandType::find((int) $r['id']);
        $this->assertSame(183, (int) $t->width_cm,
            'StandType::readSize used to fall past exact centimetres to its 3 × 3 m default');
        $this->assertSame(183, (int) $t->depth_cm);
        $this->assertSame(10_000, (int) $t->price_naira);
        $this->assertSame(24, (int) $t->quota, 'the quota is the organiser\'s, not the preset\'s');
        $this->assertStringContainsString('6 × 6 ft', (string) $t->name,
            'the published name carries the size in the unit it was sold in');
    }

    public function test_a_preset_applied_twice_makes_two_distinct_rows(): void
    {
        $id = $this->preset();

        $a = StandPreset::applyTo($this->eventId, $id, 10, 7);
        $b = StandPreset::applyTo($this->eventId, $id, 6, 7);

        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok'], 'two rows of the same pitch at different prices is a real market');
        $this->assertCount(2, StandType::forEvent($this->eventId));
    }

    public function test_applying_without_a_quota_is_refused(): void
    {
        $r = StandPreset::applyTo($this->eventId, $this->preset(), 0, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('How many', $r['message']);
        $this->assertSame([], StandType::forEvent($this->eventId));
    }

    /**
     * The lock is the point of the whole stands design: once a call is open, what is on offer
     * cannot change. A preset that wrote past it would let somebody add a cheaper pitch after
     * seeing who applied for the expensive one.
     */
    public function test_a_preset_cannot_be_added_while_the_call_is_open(): void
    {
        $id = $this->preset();
        StandCall::save($this->eventId, ['closes_at' => '2026-11-01 23:59:00']);
        StandPreset::applyTo($this->eventId, $id, 10, 7);
        StandCall::open((int) StandCall::forEvent($this->eventId)->id, 7);

        $r = StandPreset::applyTo($this->eventId, $id, 10, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('locked', $r['message']);
    }

    /** A repriced preset must not rewrite what a vendor already applied for. */
    public function test_repricing_a_preset_does_not_touch_an_event_already_using_it(): void
    {
        $id = $this->preset();
        $applied = StandPreset::applyTo($this->eventId, $id, 10, 7);

        StandPreset::save([
            'name' => '6 × 6 ft stand', 'unit' => 'ft', 'width_ft' => 6, 'depth_ft' => 6,
            'price_naira' => '18k',
        ], $id, 7);

        $this->assertSame(18_000, (int) StandPreset::find($id)->price_naira);
        $this->assertSame(10_000, (int) StandType::find((int) $applied['id'])->price_naira,
            'the terms a vendor applied under are fixed at the moment they were published');
    }

    public function test_applying_an_unknown_preset_is_refused(): void
    {
        $this->assertFalse(StandPreset::applyTo($this->eventId, 99999, 10, 7)['ok']);
    }

    // ══ the label on an existing stand type ══════════════════════════════════

    /**
     * Stand types predate the unit column, so a copied foot-based row would otherwise lose
     * the feet it was created in. The unit is inferred from the number instead.
     */
    public function test_a_stand_type_in_feet_is_labelled_in_feet_without_a_unit_column(): void
    {
        $ft = StandType::find((int) StandPreset::applyTo($this->eventId, $this->preset(), 10, 7)['id']);
        $this->assertSame('6 × 6 ft', StandPreset::labelForType($ft));

        // And a metric one is left alone. 300cm is a round metric number and 9.8ft.
        $m = StandType::find((int) StandType::save($this->eventId, [
            'name' => 'Gazebo', 'quota' => 4, 'size_preset' => '3x3',
        ])['id']);
        $this->assertSame('3 × 3 m', StandPreset::labelForType($m));
    }

    public function test_a_missing_size_labels_as_a_dash_rather_than_zero(): void
    {
        $this->assertSame('—', StandPreset::label((object) ['width_cm' => 0, 'depth_cm' => 0]));
        $this->assertSame('—', StandPreset::labelForType((object) ['width_cm' => 0, 'depth_cm' => 0]));
    }
}
