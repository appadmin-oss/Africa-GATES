<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{StandCall, StandFloorPlan, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Stand sizes, and the arithmetic that says whether the market fits in the hall.
 *
 * ── THE FAILURE BEING PREVENTED ──────────────────────────────────────────────
 *
 * Forty pitches at 3 × 3 and twelve at 6 × 3 is 576m² of stands. In a 500m² hall with a third
 * of the floor given to aisles, that is not tight — it is impossible, and by build morning
 * every remaining option is a broken promise. The sum takes one line and nobody does it,
 * because it lives in a different head from the one typing quotas.
 *
 * So most of what is asserted here is that the numbers are RIGHT, and that the screen refuses
 * to be quietly optimistic when they are not: pitches that do not fit are counted, not
 * dropped; an overshoot is stated in square metres; and the preview never claims to be a site
 * plan.
 */
class StandFloorPlanTest extends TestCase
{
    private function makeEvent(): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'market-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+60 days')), 'status' => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    private function type(int $eventId, array $in): int
    {
        $r = StandType::save($eventId, $in + ['name' => 'Pitch ' . bin2hex(random_bytes(3)), 'quota' => '1']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        return $r['id'];
    }

    /** A call must exist before the venue can be recorded against it. */
    private function draftCall(int $eventId): void
    {
        $this->assertTrue(StandCall::save($eventId, [
            'closes_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ])['ok']);
    }

    // ─────────────────────────────────── sizes ──────────────────────────────

    public function test_a_preset_sets_the_dimensions(): void
    {
        $e  = $this->makeEvent();
        $id = $this->type((int) $e->id, ['size_preset' => '6x3']);

        $t = StandType::find($id);
        $this->assertSame(600, (int) $t->width_cm);
        $this->assertSame(300, (int) $t->depth_cm);
        $this->assertSame('6 × 3 m', StandType::sizeLabel($t));
        $this->assertSame(18.0, StandType::areaSqm($t));
    }

    /** Metres in, centimetres stored — so 1.8m and 2.5m are expressible at all. */
    public function test_a_custom_size_is_stored_in_centimetres(): void
    {
        $e  = $this->makeEvent();
        $id = $this->type((int) $e->id, ['size_preset' => 'custom', 'width_m' => '2.5', 'depth_m' => '1.8']);

        $t = StandType::find($id);
        $this->assertSame(250, (int) $t->width_cm);
        $this->assertSame(180, (int) $t->depth_cm);
        $this->assertSame('2.5 × 1.8 m', StandType::sizeLabel($t));
        $this->assertSame('custom', StandType::presetFor($t));
    }

    /**
     * A chosen preset beats stale numbers in the custom boxes.
     *
     * The preset is the control the organiser actually operated. Honouring whatever was left
     * in the number fields is how a 3 × 3 silently becomes something else.
     */
    public function test_a_preset_wins_over_the_custom_boxes(): void
    {
        $e  = $this->makeEvent();
        $id = $this->type((int) $e->id, ['size_preset' => '3x3', 'width_m' => '9', 'depth_m' => '9']);

        $t = StandType::find($id);
        $this->assertSame(300, (int) $t->width_cm);
        $this->assertSame('3x3', StandType::presetFor($t));
    }

    /** Editing anything else must not silently resize the pitch. */
    public function test_an_edit_that_omits_the_size_keeps_it(): void
    {
        $e  = $this->makeEvent();
        $id = $this->type((int) $e->id, ['size_preset' => '6x6']);

        StandType::save((int) $e->id, ['name' => 'Renamed', 'quota' => '4'], $id);

        $t = StandType::find($id);
        $this->assertSame(600, (int) $t->width_cm);
        $this->assertSame(600, (int) $t->depth_cm);
    }

    /** Whole metres read as whole metres. "3 × 3 m", not "3.00 × 3.00 m". */
    public function test_metres_are_written_the_way_people_read_them(): void
    {
        $this->assertSame('3', StandType::metres(300));
        $this->assertSame('2.5', StandType::metres(250));
        $this->assertSame('0.75', StandType::metres(75));
    }

    // ────────────────────────────── the floor budget ────────────────────────

    public function test_the_committed_area_is_the_sum_of_quota_times_size(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => '3x3', 'quota' => '40']);   // 9 m² × 40 = 360
        $this->type((int) $e->id, ['size_preset' => '6x3', 'quota' => '12']);   // 18 m² × 12 = 216

        StandCall::savePlan((int) $e->id, [
            'floor_width_m' => '25', 'floor_depth_m' => '20', 'aisle_pct' => '35',
        ]);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertSame(576.0, $plan['committed_sqm']);
        $this->assertSame(500.0, $plan['gross_sqm']);
        $this->assertSame(325.0, $plan['usable_sqm']);   // 500 less 35%
        $this->assertSame(52, $plan['pitches']);
    }

    /**
     * The whole point: it says no, in square metres, before the call opens.
     */
    public function test_a_market_that_does_not_fit_says_so_and_by_how_much(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => '3x3', 'quota' => '40']);
        $this->type((int) $e->id, ['size_preset' => '6x3', 'quota' => '12']);
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '25', 'floor_depth_m' => '20']);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertFalse($plan['fits']);
        $this->assertSame(251.0, $plan['over_sqm']);
        $this->assertGreaterThan(100, $plan['used_pct']);
    }

    public function test_a_market_that_fits_says_that_too(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => '3x3', 'quota' => '20']);   // 180 m²
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '30', 'floor_depth_m' => '20']);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertTrue($plan['fits']);
        $this->assertSame(0.0, $plan['over_sqm']);
        $this->assertGreaterThan(0, $plan['free_sqm']);
    }

    /**
     * With no venue measured, the screen must say "unmeasured" rather than "fits".
     *
     * A plan reporting a comfortable fit against a hall of zero metres is the single most
     * dangerous thing this feature could do.
     */
    public function test_an_unmeasured_venue_is_reported_as_unmeasured(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => '3x3', 'quota' => '40']);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertFalse($plan['measured']);
        $this->assertSame(0.0, $plan['gross_sqm']);
        $this->assertSame([], $plan['blocks'], 'Nothing can be laid out in a hall nobody measured.');
        // The committed total is still real — it does not depend on the venue.
        $this->assertSame(360.0, $plan['committed_sqm']);
    }

    /** An aisle allowance of 100% would leave no sellable floor and divide by zero. */
    public function test_the_aisle_allowance_is_clamped(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        StandCall::savePlan((int) $e->id, [
            'floor_width_m' => '20', 'floor_depth_m' => '20', 'aisle_pct' => '999',
        ]);

        $this->assertSame(80, StandFloorPlan::forEvent((int) $e->id)['aisle_pct']);
    }

    public function test_half_a_measurement_is_refused(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);

        $r = StandCall::savePlan((int) $e->id, ['floor_width_m' => '20', 'floor_depth_m' => '0']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not a floor area', $r['message']);
    }

    // ───────────────────────────── the block layout ─────────────────────────

    /**
     * Pitches that ran off the end of the hall are COUNTED, never dropped.
     *
     * A preview that silently discards what it could not place is a preview that says
     * everything is fine.
     */
    public function test_pitches_that_do_not_fit_are_counted_not_hidden(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => '3x3', 'quota' => '30']);
        // A 10 × 10 hall holds nine 3×3 pitches at best, and fewer once gaps are allowed for.
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '10', 'floor_depth_m' => '10']);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertSame(30, $plan['placed'] + $plan['unplaced']);
        $this->assertGreaterThan(0, $plan['unplaced']);
    }

    /** A pitch wider than the hall never fits, in any row. */
    public function test_a_pitch_wider_than_the_hall_is_never_placed(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => 'custom', 'width_m' => '12', 'depth_m' => '3', 'quota' => '2']);
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '10', 'floor_depth_m' => '40']);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertSame(0, $plan['placed']);
        $this->assertSame(2, $plan['unplaced']);
    }

    /** Every placed block must be inside the hall, or the picture is a lie. */
    public function test_every_placed_block_is_inside_the_hall(): void
    {
        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['size_preset' => '3x3', 'quota' => '8']);
        $this->type((int) $e->id, ['size_preset' => '6x3', 'quota' => '3']);
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '20', 'floor_depth_m' => '20']);

        $plan = StandFloorPlan::forEvent((int) $e->id);
        $this->assertNotEmpty($plan['blocks']);
        foreach ($plan['blocks'] as $b) {
            $this->assertLessThanOrEqual($plan['floor_w_cm'], $b['x'] + $b['w']);
            $this->assertLessThanOrEqual($plan['floor_d_cm'], $b['y'] + $b['d']);
        }
    }

    // ──────────────────────────────── the screen ────────────────────────────

    private function ctrl(): \AfricaGates\Admin\Controllers\StandsController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(\AfricaGates\Admin\Controllers\StandsController::class);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'],
              $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    public function test_the_screen_draws_the_plan_and_never_claims_it_is_a_site_plan(): void
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';

        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['name' => 'Food pitch', 'category' => 'food',
                                   'size_preset' => '3x3', 'quota' => '6']);
        $this->type((int) $e->id, ['name' => 'Craft table', 'category' => 'craft',
                                   'size_preset' => 'table', 'quota' => '4']);
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '20', 'floor_depth_m' => '15']);

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $html = (string) $this->ctrl()->index($req, new Response(), ['id' => (int) $e->id])->getBody();

        $this->assertStringContainsString('<svg', $html, 'The visuals must actually render.');
        $this->assertStringContainsString('3 × 3 m', $html);
        $this->assertStringContainsString('m² sellable', $html);
        // The caveat is not optional. A diagram that looks like a fire-safety document while
        // knowing nothing about fire is worse than no diagram, because somebody forwards it.
        $this->assertStringContainsString('not a site plan', $html);
        $this->assertStringContainsString('fire exits', $html);
        // Identity is never colour alone: the legend names each type beside its swatch.
        $this->assertStringContainsString('viz__legend', $html);
        $this->assertStringContainsString('Craft table', $html);
    }

    public function test_the_screen_shouts_when_the_market_does_not_fit(): void
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';

        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);
        $this->type((int) $e->id, ['name' => 'Food pitch', 'size_preset' => '6x3', 'quota' => '40']);
        StandCall::savePlan((int) $e->id, ['floor_width_m' => '20', 'floor_depth_m' => '15']);

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $html = (string) $this->ctrl()->index($req, new Response(), ['id' => (int) $e->id])->getBody();

        $this->assertStringContainsString('Over by', $html);
        $this->assertStringContainsString('broken promise', $html);
    }

    /**
     * The venue stays editable after the lock, and the quotas do not.
     *
     * The lock stops the RULES changing once you know who applied. How wide the hall is, is a
     * fact somebody may measure better next week, and refusing a better measurement would
     * protect nobody — it would only keep the plan wrong.
     */
    public function test_the_venue_can_be_corrected_after_the_call_locks(): void
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';

        $e = $this->makeEvent();
        $this->type((int) $e->id, ['name' => 'Food pitch', 'size_preset' => '3x3', 'quota' => '6']);
        $this->draftCall((int) $e->id);
        $call = StandCall::forEvent((int) $e->id);
        $this->assertTrue(StandCall::open((int) $call->id, 1)['ok']);

        // The rules are shut.
        $this->assertFalse(StandType::save((int) $e->id, ['name' => 'Another', 'quota' => '2'])['ok']);
        $this->assertFalse(StandCall::save((int) $e->id, ['closes_at' => '2030-01-01 00:00:00'])['ok']);

        // The tape measure is not.
        $r = StandCall::savePlan((int) $e->id, ['floor_width_m' => '22', 'floor_depth_m' => '18']);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(2200, StandFloorPlan::forEvent((int) $e->id)['floor_w_cm']);
    }

    public function test_the_size_is_in_the_locked_snapshot(): void
    {
        $e = $this->makeEvent();
        $this->type((int) $e->id, ['name' => 'Double gazebo', 'size_preset' => '6x3', 'quota' => '4']);
        $this->draftCall((int) $e->id);
        StandCall::open((int) StandCall::forEvent((int) $e->id)->id, 1);

        $snap = StandCall::criteria(StandCall::forEvent((int) $e->id));
        $this->assertSame(600, $snap['types'][0]['width_cm']);
        $this->assertSame('6 × 3 m', $snap['types'][0]['size'],
            'A vendor who applied for 6 × 3 and arrives to find 3 × 3 was sold something else.');
    }

    /** A moderator can read the venue and must not be able to change it. */
    public function test_a_moderator_cannot_record_the_venue(): void
    {
        $_SESSION['admin_id'] = 2; $_SESSION['admin_role'] = 'moderator';

        $e = $this->makeEvent();
        $this->draftCall((int) $e->id);

        $req = (new ServerRequestFactory())->createServerRequest('POST', '/x')
            ->withParsedBody(['floor_width_m' => '30', 'floor_depth_m' => '30']);
        $this->ctrl()->savePlan($req, new Response(), ['id' => (int) $e->id]);

        $this->assertFalse(StandFloorPlan::forEvent((int) $e->id)['measured']);
        $this->assertStringContainsString('Only an admin', (string) $_SESSION['flash_error']);
    }
}
