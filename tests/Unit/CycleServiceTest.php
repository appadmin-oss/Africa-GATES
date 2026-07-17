<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\CycleService;

/**
 * The manual (admin) cycle-transition policy: forward-only, one phase at a time,
 * and never a hand-jump to 'results' (winners promote through the quorum-checked
 * date-driven path).
 */
class CycleServiceTest extends TestCase
{
    public function test_allows_single_step_forward(): void
    {
        $this->assertNull(CycleService::manualTransitionError('nominations', 'voting'));
        $this->assertNull(CycleService::manualTransitionError('voting', 'judging'));
        $this->assertNull(CycleService::manualTransitionError('results', 'archived'));
    }

    public function test_allows_no_status_change(): void
    {
        // Editing dates/labels without moving the phase always passes.
        $this->assertNull(CycleService::manualTransitionError('voting', 'voting'));
    }

    public function test_refuses_manual_jump_to_results(): void
    {
        $this->assertNotNull(CycleService::manualTransitionError('judging', 'results'));
        $this->assertNotNull(CycleService::manualTransitionError('nominations', 'results'));
    }

    public function test_refuses_backward_regression(): void
    {
        $this->assertNotNull(CycleService::manualTransitionError('results', 'voting'));
        $this->assertNotNull(CycleService::manualTransitionError('judging', 'nominations'));
    }

    public function test_refuses_phase_skip(): void
    {
        $this->assertNotNull(CycleService::manualTransitionError('nominations', 'judging'));
        $this->assertNotNull(CycleService::manualTransitionError('upcoming', 'voting'));
    }

    public function test_new_cycle_may_start_up_to_judging_but_not_results_or_archived(): void
    {
        $this->assertNull(CycleService::manualTransitionError(null, 'upcoming'));
        $this->assertNull(CycleService::manualTransitionError(null, 'nominations'));
        $this->assertNull(CycleService::manualTransitionError(null, 'judging'));
        $this->assertNotNull(CycleService::manualTransitionError(null, 'results'));
        $this->assertNotNull(CycleService::manualTransitionError(null, 'archived'));
    }

    public function test_unknown_status_is_refused(): void
    {
        $this->assertNotNull(CycleService::manualTransitionError('voting', 'bogus'));
    }
}
