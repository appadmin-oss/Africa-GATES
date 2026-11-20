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
        // 'shortlisting' now sits between nominations and voting, so
        // nominations → voting is TWO steps and is (correctly) refused.
        $this->assertNull(CycleService::manualTransitionError('nominations', 'shortlisting'));
        $this->assertNull(CycleService::manualTransitionError('shortlisting', 'voting'));
        $this->assertNull(CycleService::manualTransitionError('voting', 'judging'));
        $this->assertNull(CycleService::manualTransitionError('results', 'archived'));

        $this->assertNotNull(CycleService::manualTransitionError('nominations', 'voting'),
            'skipping shortlisting is still a skip');
    }

    public function test_only_reachable_statuses_are_offered_to_the_admin(): void
    {
        // The dropdown used to list all six values including 'results', which is
        // refused unconditionally — the admin picked it, round-tripped, and got a
        // red flash. Every offered option must actually be accepted.
        foreach ([null, 'upcoming', 'nominations', 'shortlisting', 'voting', 'judging'] as $from) {
            foreach (CycleService::selectableFrom($from) as $to) {
                $this->assertNull(
                    CycleService::manualTransitionError($from, $to),
                    "offered $from -> $to must be accepted"
                );
            }
        }
        $this->assertNotContains('results', CycleService::selectableFrom('judging'),
            'results is never settable by hand');
    }

    public function test_incoherent_date_windows_are_refused(): void
    {
        $this->assertNotNull(CycleService::windowError([
            'nominations_open' => '2026-07-01 00:00', 'nominations_close' => '2026-06-01 00:00',
        ]), 'nominations closing before they open');

        $this->assertNotNull(CycleService::windowError([
            'voting_open' => '2026-08-01 00:00', 'voting_close' => '2026-07-01 00:00',
        ]), 'voting closing before it opens');

        $this->assertNotNull(CycleService::windowError([
            'nominations_close' => '2026-08-01 00:00', 'voting_open' => '2026-07-01 00:00',
        ]), 'voting opening before nominations close');

        $this->assertNotNull(CycleService::windowError([
            'voting_close' => '2026-08-01 00:00', 'results_date' => '2026-07-01 00:00',
        ]), 'results before voting closes');
    }

    public function test_a_coherent_window_set_passes(): void
    {
        $this->assertNull(CycleService::windowError([
            'nominations_open'  => '2026-06-01 00:00',
            'nominations_close' => '2026-07-01 00:00',
            'voting_open'       => '2026-07-15 00:00',
            'voting_close'      => '2026-08-15 00:00',
            'results_date'      => '2026-09-01 00:00',
        ]));
        $this->assertNull(CycleService::windowError([]), 'an empty set is not incoherent');
    }

    public function test_risky_but_legal_orderings_are_warned_about_not_blocked(): void
    {
        // A close date with no open date is savable, but it is the one
        // configuration that reaches the branch where a stale status column can
        // affect authorization — so the admin is told.
        $w = CycleService::windowWarning(['voting_close' => '2026-08-15 00:00']);
        $this->assertNotNull($w);
        $this->assertStringContainsString('no OPEN date', $w);

        $w2 = CycleService::windowWarning(['voting_open' => '2026-07-15 00:00']);
        $this->assertNotNull($w2);
        $this->assertStringContainsString('indefinitely', $w2, 'an unbounded ballot must be flagged');

        $this->assertNotNull(CycleService::windowWarning([]), 'no windows at all means no automation');
        $this->assertNull(CycleService::windowWarning([
            'voting_open' => '2026-07-15 00:00', 'voting_close' => '2026-08-15 00:00',
        ]), 'a complete window needs no caution');
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
