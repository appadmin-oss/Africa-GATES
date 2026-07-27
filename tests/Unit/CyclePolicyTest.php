<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Services\CyclePhase;
use AfricaGates\Services\CyclePolicy;
use Illuminate\Support\Carbon;

/**
 * The lifecycle invariant: phase is a pure function of the date windows and the
 * current time. No database, no cron, no stored status involved.
 *
 * The headline case is test_voting_phase_is_never_skipped_when_there_is_a_
 * shortlisting_gap — the bug that made voting unreachable for any cycle with a
 * gap between nominations closing and voting opening (i.e. the normal design).
 */
class CyclePolicyTest extends TestCase
{
    /** A cycle with a shortlisting gap: nominations close 14 days before voting opens. */
    private function gapCycle(string $status = 'nominations'): object
    {
        return (object) [
            'status'            => $status,
            'year'              => 2026,
            'nominations_open'  => '2026-06-01 00:00:00',
            'nominations_close' => '2026-07-01 00:00:00',
            'voting_open'       => '2026-07-15 00:00:00',
            'voting_close'      => '2026-08-15 00:00:00',
            'results_date'      => '2026-09-01 00:00:00',
        ];
    }

    private function at(string $when): Carbon
    {
        return Carbon::parse($when);
    }

    public function test_voting_phase_is_never_skipped_when_there_is_a_shortlisting_gap(): void
    {
        $c = $this->gapCycle();

        // The whole point: voting is open EXACTLY inside its own window, and at
        // no other time. The old engine opened it 13 days early for one cron
        // tick and then refused to reopen it for the real window.
        $cases = [
            '2026-06-15 12:00:00' => CyclePhase::Nominations,
            '2026-06-30 23:59:59' => CyclePhase::Nominations,
            '2026-07-01 00:00:00' => CyclePhase::Shortlisting, // nominations just closed
            '2026-07-02 12:00:00' => CyclePhase::Shortlisting, // was wrongly 'voting'
            '2026-07-14 23:59:59' => CyclePhase::Shortlisting,
            '2026-07-15 00:00:00' => CyclePhase::Voting,       // was wrongly 'judging' forever
            '2026-08-01 12:00:00' => CyclePhase::Voting,
            '2026-08-14 23:59:59' => CyclePhase::Voting,
            '2026-08-15 00:00:00' => CyclePhase::Judging,      // half-open: close is exclusive
            '2026-08-20 12:00:00' => CyclePhase::Judging,
            '2026-09-01 00:00:00' => CyclePhase::Results,
            '2026-12-25 12:00:00' => CyclePhase::Results,
        ];

        foreach ($cases as $when => $expected) {
            $this->assertSame(
                $expected,
                CyclePolicy::phaseFor($c, $this->at($when)),
                "at $when the cycle must be {$expected->value}"
            );
        }
    }

    public function test_voting_is_open_for_every_instant_inside_the_voting_window(): void
    {
        $c = $this->gapCycle();
        // Walk the entire cycle in 6-hour steps: voting must be open on every
        // step inside the window and closed on every step outside it.
        $cursor = $this->at('2026-06-01 00:00:00');
        $end    = $this->at('2026-09-05 00:00:00');
        $open   = $this->at('2026-07-15 00:00:00');
        $close  = $this->at('2026-08-15 00:00:00');
        $steps  = 0;

        while ($cursor->lt($end)) {
            $inside = $cursor->gte($open) && $cursor->lt($close);
            $this->assertSame(
                $inside,
                CyclePolicy::phaseFor($c, $cursor->copy())->isVotingOpen(),
                'voting openness at ' . $cursor->toDateTimeString() . ' must be ' . var_export($inside, true)
            );
            $cursor->addHours(6);
            $steps++;
        }
        $this->assertGreaterThan(300, $steps, 'the walk must actually cover the cycle');
    }

    public function test_stored_status_never_keeps_voting_open_past_the_close_date(): void
    {
        // The reported production symptom: the column still says 'voting' long
        // after voting_close because no scheduler ran. The computed phase must
        // ignore it.
        $c = $this->gapCycle('voting');
        $phase = CyclePolicy::phaseFor($c, $this->at('2026-08-30 12:00:00'));

        $this->assertSame(CyclePhase::Judging, $phase);
        $this->assertFalse($phase->isVotingOpen(), 'a stale status column must not hold voting open');
    }

    public function test_stale_stored_status_is_reported_as_drift(): void
    {
        $state = CyclePolicy::stateFor($this->gapCycle('voting'), $this->at('2026-08-30 12:00:00'));

        $this->assertTrue($state['drifted'], 'computed judging vs stored voting must be flagged');
        $this->assertSame('voting', $state['stored_status']);
        $this->assertSame('judging', $state['phase']);
    }

    public function test_no_drift_when_the_column_agrees(): void
    {
        $state = CyclePolicy::stateFor($this->gapCycle('voting'), $this->at('2026-08-01 12:00:00'));

        $this->assertFalse($state['drifted']);
        $this->assertTrue($state['is_voting_open']);
    }

    public function test_every_phase_round_trips_through_the_stored_column(): void
    {
        // The column was widened to carry 'shortlisting'. If it ever collapses
        // again, a one-step advance out of nominations writes 'judging' and
        // voting becomes a backward step that can never be taken — the original
        // bug, reappearing in the materialised column.
        foreach (CyclePhase::cases() as $phase) {
            $this->assertSame($phase->value, $phase->storedValue(), $phase->value . ' must round-trip');
            $this->assertSame($phase, CyclePhase::fromStored($phase->storedValue()));
        }
    }

    public function test_shortlisting_is_reported_as_drift_against_a_legacy_judging_column(): void
    {
        // A row written before the widen still says 'judging' during the gap.
        $state = CyclePolicy::stateFor($this->gapCycle('judging'), $this->at('2026-07-05 12:00:00'));

        $this->assertSame('shortlisting', $state['phase']);
        $this->assertTrue($state['drifted'], 'a legacy judging row during shortlisting is now visible drift');
    }

    public function test_a_missing_voting_window_never_invents_one(): void
    {
        $c = (object) [
            'status'            => 'nominations',
            'nominations_open'  => '2026-06-01 00:00:00',
            'nominations_close' => '2026-07-01 00:00:00',
            'voting_open'       => null,
            'voting_close'      => null,
            'results_date'      => '2026-09-01 00:00:00',
        ];

        // Straight to the jury — and never votable at any instant.
        $this->assertSame(CyclePhase::Nominations,  CyclePolicy::phaseFor($c, $this->at('2026-06-15 00:00:00')));
        $this->assertSame(CyclePhase::Judging,      CyclePolicy::phaseFor($c, $this->at('2026-07-02 00:00:00')));
        $this->assertSame(CyclePhase::Judging,      CyclePolicy::phaseFor($c, $this->at('2026-08-20 00:00:00')));
        $this->assertSame(CyclePhase::Results,      CyclePolicy::phaseFor($c, $this->at('2026-09-02 00:00:00')));
    }

    public function test_voting_open_without_a_close_date_stays_open(): void
    {
        // An explicit open instruction with no close is honoured (and the admin
        // editor is what should warn about it) — but it is not invented.
        $c = (object) ['status' => 'voting', 'voting_open' => '2026-07-01 00:00:00'];
        $this->assertSame(CyclePhase::Voting, CyclePolicy::phaseFor($c, $this->at('2030-01-01 00:00:00')));

        $state = CyclePolicy::stateFor($c, $this->at('2026-07-05 00:00:00'));
        $this->assertNull($state['closes_at']);
        $this->assertNull($state['seconds_left']);
        $this->assertFalse($state['closing_soon'], 'no close date must not render a countdown');
    }

    public function test_a_cycle_with_no_windows_falls_back_to_the_stored_column(): void
    {
        foreach (['upcoming', 'nominations', 'voting', 'judging', 'results'] as $stored) {
            $c = (object) ['status' => $stored];
            $this->assertSame(
                $stored,
                CyclePolicy::phaseFor($c, $this->at('2026-07-01 00:00:00'))->value,
                "a window-less cycle must keep its stored status ($stored)"
            );
        }
    }

    public function test_archived_is_sticky_regardless_of_dates(): void
    {
        $phase = CyclePolicy::phaseFor($this->gapCycle('archived'), $this->at('2026-08-01 12:00:00'));

        $this->assertSame(CyclePhase::Archived, $phase);
        $this->assertFalse($phase->isVotingOpen(), 'an archived cycle is never votable');
    }

    public function test_unknown_or_null_stored_status_degrades_to_upcoming(): void
    {
        $this->assertSame(CyclePhase::Upcoming, CyclePhase::fromStored(null));
        $this->assertSame(CyclePhase::Upcoming, CyclePhase::fromStored(''));
        $this->assertSame(CyclePhase::Upcoming, CyclePhase::fromStored('nonsense'));
        $this->assertSame(CyclePhase::Voting,   CyclePhase::fromStored('  VOTING '));
    }

    public function test_phase_is_total_over_every_combination_of_present_windows(): void
    {
        // 2^5 window combinations × 4 instants must always yield a phase and
        // never throw. This is the property the old forward-override chain broke.
        $keys = ['nominations_open', 'nominations_close', 'voting_open', 'voting_close', 'results_date'];
        $dates = [
            'nominations_open'  => '2026-06-01 00:00:00',
            'nominations_close' => '2026-07-01 00:00:00',
            'voting_open'       => '2026-07-15 00:00:00',
            'voting_close'      => '2026-08-15 00:00:00',
            'results_date'      => '2026-09-01 00:00:00',
        ];
        $instants = ['2026-05-01 00:00:00', '2026-07-05 00:00:00', '2026-08-01 00:00:00', '2026-10-01 00:00:00'];
        $checked = 0;

        for ($mask = 0; $mask < 32; $mask++) {
            $row = ['status' => 'upcoming'];
            foreach ($keys as $i => $k) {
                $row[$k] = ($mask & (1 << $i)) ? $dates[$k] : null;
            }
            foreach ($instants as $when) {
                $phase = CyclePolicy::phaseFor((object) $row, $this->at($when));
                $this->assertInstanceOf(CyclePhase::class, $phase, "mask $mask at $when");
                // Voting may only be reported when the operator declared a
                // voting window — an open date, or a close date (which implies
                // one). Never from nothing.
                if ($phase->isVotingOpen()) {
                    $this->assertTrue(
                        $row['voting_open'] !== null || $row['voting_close'] !== null,
                        "mask $mask must not report voting with no voting window at all"
                    );
                }
                $checked++;
            }
        }
        $this->assertSame(128, $checked);
    }

    public function test_phase_is_monotonic_as_time_advances(): void
    {
        // Walking forward in time may never move the phase backwards.
        $c = $this->gapCycle();
        $cursor = $this->at('2026-05-01 00:00:00');
        $last = -1;
        while ($cursor->lt($this->at('2026-10-01 00:00:00'))) {
            $ord = CyclePolicy::phaseFor($c, $cursor->copy())->ordinal();
            $this->assertGreaterThanOrEqual($last, $ord, 'phase regressed at ' . $cursor->toDateTimeString());
            $last = $ord;
            $cursor->addHours(12);
        }
        $this->assertSame(CyclePhase::Results->ordinal(), $last);
    }

    public function test_closing_soon_and_remaining_copy_track_the_same_bound(): void
    {
        $c = $this->gapCycle();

        $far = CyclePolicy::stateFor($c, $this->at('2026-07-20 00:00:00'));
        $this->assertTrue($far['is_voting_open']);
        $this->assertFalse($far['closing_soon']);
        $this->assertStringContainsString('Voting closes in 26 days', $far['detail']);

        $near = CyclePolicy::stateFor($c, $this->at('2026-08-14 06:00:00'));
        $this->assertTrue($near['closing_soon'], 'inside 48h must read as closing soon');
        $this->assertStringContainsString('in 18 hours', $near['detail']);

        $after = CyclePolicy::stateFor($c, $this->at('2026-08-16 00:00:00'));
        $this->assertFalse($after['is_voting_open']);
        $this->assertStringNotContainsString('closes', $after['detail'], 'a closed cycle must not advertise a future close');
    }

    public function test_remaining_copy_never_reads_as_zero_days_left(): void
    {
        foreach ([30, 119, 3599, 7199, 86399, 172799, 400000] as $seconds) {
            $out = CyclePolicy::humanRemaining($seconds);
            $this->assertStringNotContainsString('0 days', $out, "$seconds must not render '0 days'");
            $this->assertStringNotContainsString('0 hours', $out, "$seconds must not render '0 hours'");
            $this->assertStringNotContainsString('0 minutes', $out, "$seconds must not render '0 minutes'");
        }
        $this->assertSame('in 4 days', CyclePolicy::humanRemaining(4 * 86400));
        $this->assertSame('tomorrow', CyclePolicy::humanRemaining(90000));
        $this->assertSame('in under a minute', CyclePolicy::humanRemaining(5));
    }

    public function test_every_phase_has_a_label_and_a_detail_line(): void
    {
        // Locks the view-model down so labels cannot silently drift or go blank.
        $c = $this->gapCycle();
        $instants = [
            'upcoming'     => '2026-05-01 00:00:00',
            'nominations'  => '2026-06-15 00:00:00',
            'shortlisting' => '2026-07-05 00:00:00',
            'voting'       => '2026-08-01 00:00:00',
            'judging'      => '2026-08-20 00:00:00',
            'results'      => '2026-09-05 00:00:00',
        ];
        foreach ($instants as $expected => $when) {
            $s = CyclePolicy::stateFor($c, $this->at($when));
            $this->assertSame($expected, $s['phase']);
            $this->assertNotSame('', $s['label'], "$expected needs a label");
            $this->assertNotSame('', $s['detail'], "$expected needs a detail line");
        }
    }

    public function test_ordinals_are_unique_contiguous_and_forward_only(): void
    {
        $ords = array_map(fn(CyclePhase $p) => $p->ordinal(), CyclePhase::cases());
        sort($ords);
        $this->assertSame(range(0, count(CyclePhase::cases()) - 1), $ords, 'ordinals must be 0..n contiguous');

        // Shortlisting MUST sort before Voting — this is what makes forward-only
        // advancement and "never skip voting" compatible.
        $this->assertLessThan(CyclePhase::Voting->ordinal(), CyclePhase::Shortlisting->ordinal());
        $this->assertGreaterThan(CyclePhase::Nominations->ordinal(), CyclePhase::Shortlisting->ordinal());

        $this->assertSame(CyclePhase::Shortlisting, CyclePhase::Nominations->next());
        $this->assertSame(CyclePhase::Voting, CyclePhase::Shortlisting->next());
        $this->assertNull(CyclePhase::Archived->next());
    }

    public function test_only_one_phase_opens_voting_and_only_one_opens_nominations(): void
    {
        $votable   = array_filter(CyclePhase::cases(), fn($p) => $p->isVotingOpen());
        $nominable = array_filter(CyclePhase::cases(), fn($p) => $p->isNominationsOpen());

        $this->assertCount(1, $votable);
        $this->assertCount(1, $nominable);
        $this->assertSame(CyclePhase::Voting, reset($votable));
        $this->assertSame(CyclePhase::Nominations, reset($nominable));
    }

    public function test_malformed_dates_are_treated_as_absent_not_fatal(): void
    {
        $c = (object) [
            'status'            => 'nominations',
            'nominations_open'  => '0000-00-00 00:00:00',
            'nominations_close' => '',
            'voting_open'       => 'not a date at all',
            'voting_close'      => null,
            'results_date'      => null,
        ];
        // Every window is unusable, so it degrades to the stored column.
        $this->assertSame(CyclePhase::Nominations, CyclePolicy::phaseFor($c, $this->at('2026-07-01 00:00:00')));
    }
}
