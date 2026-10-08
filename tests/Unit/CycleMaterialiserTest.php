<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Services\CycleMaterialiser;
use AfricaGates\Support\Clock;

/**
 * The single lifecycle engine. Two guarantees matter most here, and neither
 * existed before:
 *
 *  1. EXACTLY ONCE. The transitions ledger's UNIQUE (cycle_id, to_status) makes
 *     the INSERT the claim, so two concurrent runs cannot both fire a phase's
 *     side effects. CronGuard deliberately fails open, so overlap is possible
 *     by design and the ledger is what makes it safe.
 *  2. STALE BACKLOG SUPPRESSION. A scheduler dead for a month must correct the
 *     standings without emailing every winner about a competition that ended
 *     long ago. State is always repaired; notifications are withheld.
 */
class CycleMaterialiserTest extends TestCase
{
    private function seedCycle(int $id, string $status, array $dates): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert(array_merge(
            ['id' => $id, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => $status],
            $dates
        ));
    }

    private function storedStatus(int $id): string
    {
        return (string) DB::table('gates_award_cycles')->where('id', $id)->value('status');
    }

    public function test_a_phase_is_claimed_exactly_once_across_concurrent_runs(): void
    {
        $this->seedCycle(1, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2020-03-01 00:00:00',
            'voting_close'      => '2037-01-01 00:00:00',
        ]);

        // Two overlapping runs, as CronGuard's fail-open behaviour permits.
        $a = (new CycleMaterialiser())->run();
        $b = (new CycleMaterialiser())->run();

        // Each run advances exactly one phase; neither may re-claim the other's.
        $rows = DB::table('gates_cycle_transitions')->where('cycle_id', 1)
            ->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(['shortlisting', 'voting'], $rows, 'each phase claimed once, in order');
        $this->assertSame(1, $a['changed']);
        $this->assertSame(1, $b['changed']);
    }

    public function test_a_replayed_phase_is_refused_and_fires_no_side_effects(): void
    {
        $this->seedCycle(2, 'nominations', ['nominations_open' => '2020-01-01 00:00:00']);

        // Pre-claim the next phase, as a crashed earlier run would have.
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => 2, 'from_status' => 'nominations', 'to_status' => 'shortlisting',
            'reason' => 'pre-claimed', 'actor' => 'test',
        ]);
        DB::table('gates_award_cycles')->where('id', 2)->update([
            'nominations_close' => '2020-02-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame(0, $r['changed'], 'an already-claimed phase must not advance again');
        $this->assertSame('nominations', $this->storedStatus(2), 'and must not rewrite the column');
        $this->assertSame(1, DB::table('gates_cycle_transitions')->where('cycle_id', 2)->count(),
            'no duplicate ledger row');
    }

    public function test_the_ledger_records_the_declared_boundary_and_when_it_was_noticed(): void
    {
        // A computed phase change is not a write, so without this there is no
        // audit trail at all — and no way to tell "closed on time" from "closed
        // on time, but nobody looked for three weeks".
        $this->seedCycle(3, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2037-01-01 00:00:00',
        ]);

        (new CycleMaterialiser())->run();

        $row = DB::table('gates_cycle_transitions')->where('cycle_id', 3)->first();
        $this->assertNotNull($row);
        $this->assertSame('shortlisting', (string) $row->to_status);
        $this->assertStringStartsWith('2020-02-01', (string) $row->boundary_at, 'the declared date that caused it');
        $this->assertNotEmpty($row->observed_at, 'and when the system first noticed');
        $this->assertNotSame((string) $row->boundary_at, (string) $row->observed_at,
            'a long-overdue transition must show the gap, not hide it');
    }

    public function test_a_stale_transition_repairs_state_but_suppresses_announcements(): void
    {
        // Six years overdue: the platform must correct itself without
        // congratulating anyone about a competition that ended in 2020.
        $this->seedCycle(4, 'judging', [
            'voting_open'  => '2020-01-01 00:00:00',
            'voting_close' => '2020-02-01 00:00:00',
            'results_date' => '2020-03-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(4), 'state is still corrected');
        $this->assertSame(1, $r['suppressed'], 'but the announcement is withheld');

        $row = DB::table('gates_cycle_transitions')->where('cycle_id', 4)->first();
        $this->assertSame(0, (int) $row->notify, 'and the suppression is recorded, not silent');
        $this->assertStringContainsString('suppressed', (string) $row->reason);
    }

    public function test_a_timely_transition_still_announces(): void
    {
        $this->seedCycle(5, 'judging', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(5));
        $this->assertSame(0, $r['suppressed'], 'a result one day old is not stale');
        $this->assertSame(1, (int) DB::table('gates_cycle_transitions')->where('cycle_id', 5)->value('notify'));
    }

    public function test_the_grace_boundary_is_where_it_says_it_is(): void
    {
        $now = Carbon::parse('2026-07-26 12:00:00');
        $this->assertSame(0, CycleMaterialiser::daysLate('2026-07-27 12:00:00', $now), 'future is never late');
        $this->assertSame(0, CycleMaterialiser::daysLate(null, $now), 'absent is never late');
        $this->assertSame(7, CycleMaterialiser::daysLate('2026-07-19 12:00:00', $now));
        $this->assertSame(8, CycleMaterialiser::daysLate('2026-07-18 12:00:00', $now));
        $this->assertSame(7, CycleMaterialiser::ANNOUNCE_GRACE_DAYS, 'the documented grace window');
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedCycle(6, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2037-01-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser(true))->run();

        $this->assertSame(1, $r['changed'], 'it reports what it would do');
        $this->assertSame('nominations', $this->storedStatus(6), 'but changes nothing');
        $this->assertSame(0, DB::table('gates_cycle_transitions')->where('cycle_id', 6)->count());
    }

    public function test_a_cycle_is_never_regressed_by_the_materialiser(): void
    {
        // A mistyped results_date must not un-announce published winners.
        $this->seedCycle(7, 'results', [
            'voting_open'  => '2020-01-01 00:00:00',
            'voting_close' => '2037-01-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(7));
        $this->assertSame(0, $r['changed']);
    }

    public function test_a_cycle_with_no_date_windows_is_left_alone(): void
    {
        $this->seedCycle(8, 'nominations', []);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame(0, $r['checked'], 'nothing to derive from, so nothing to manage');
        $this->assertSame('nominations', $this->storedStatus(8));
    }

    public function test_the_process_timezone_is_pinned(): void
    {
        // Every process must agree on what time it is, or cron and web requests
        // compute different phases from identical rows — permanently.
        $this->assertSame('UTC', Clock::boot(), 'the default must be UTC');
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame(date_default_timezone_get(), Clock::timezone());
    }

    public function test_an_invalid_configured_timezone_falls_back_rather_than_breaking(): void
    {
        $prev = $_ENV['APP_TIMEZONE'] ?? null;
        $_ENV['APP_TIMEZONE'] = 'Not/AZone';
        $this->assertSame('UTC', Clock::boot(), 'a typo must not leave the process on an arbitrary zone');

        $_ENV['APP_TIMEZONE'] = 'Africa/Lagos';
        $this->assertSame('Africa/Lagos', Clock::boot(), 'a valid IANA identifier is honoured');

        if ($prev === null) { unset($_ENV['APP_TIMEZONE']); } else { $_ENV['APP_TIMEZONE'] = $prev; }
        Clock::boot();
    }

    public function test_the_next_boundary_is_materialised_so_the_sweep_is_indexable(): void
    {
        // A computed phase cannot be indexed, so "which cycles need attention?"
        // is only answerable cheaply if the next boundary is stored.
        $this->seedCycle(20, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2020-02-01 00:00:00',
            'voting_open'       => '2037-03-01 00:00:00',
            'voting_close'      => '2037-04-01 00:00:00',
        ]);

        (new CycleMaterialiser())->run();

        $at = (string) DB::table('gates_award_cycles')->where('id', 20)->value('next_boundary_at');
        $this->assertStringStartsWith('2037-03-01', $at, 'the soonest FUTURE boundary, not a passed one');
    }

    public function test_the_boundary_is_refreshed_even_when_the_phase_does_not_move(): void
    {
        // A cycle that is simply waiting must still get its boundary maintained,
        // or the sweep silently stops seeing it.
        $this->seedCycle(21, 'nominations', [
            'nominations_open'  => '2020-01-01 00:00:00',
            'nominations_close' => '2037-01-01 00:00:00',
        ]);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame(0, $r['changed'], 'nothing to advance');
        $this->assertStringStartsWith(
            '2037-01-01',
            (string) DB::table('gates_award_cycles')->where('id', 21)->value('next_boundary_at'),
            'but the boundary is still recorded'
        );
    }

    public function test_a_cycle_left_behind_by_the_materialiser_is_reported_by_the_sweep(): void
    {
        // gates_phase_drift only sees the vote/nominate paths, so a cycle nobody
        // interacts with could drift unnoticed. This sweep is traffic-independent.
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 1, 'slug' => 'p1', 'title' => 'P1']);
        DB::table('gates_award_cycles')->insert([
            'id' => 22, 'programme_id' => 1, 'year' => (int) date('Y'),
            'status'           => 'voting',                                     // never updated
            'voting_open'      => '2020-01-01 00:00:00',
            'voting_close'     => '2020-02-01 00:00:00',
            'next_boundary_at' => '2020-02-01 00:00:00',                        // long passed
        ]);

        $d = CycleMaterialiser::divergences();

        $this->assertCount(1, $d);
        $this->assertSame(22, $d[0]['cycle_id']);
        $this->assertSame('voting', $d[0]['stored_status']);
        $this->assertSame('judging', $d[0]['computed_phase']);
        $this->assertGreaterThan(86400, $d[0]['seconds_behind'], 'lag is measurable, not just boolean');
    }

    public function test_the_sweep_is_quiet_when_everything_is_in_step(): void
    {
        $this->seedCycle(23, 'voting', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        (new CycleMaterialiser())->run();

        $this->assertSame([], CycleMaterialiser::divergences(), 'no news is the normal case');
    }

    public function test_a_deduped_job_is_only_ever_queued_once(): void
    {
        // The outbox delivers at-least-once, so a phase side effect with a
        // user-visible result needs an idempotent enqueue.
        $q = new \AfricaGates\Services\QueueService();

        $first  = $q->push('phase.announce', ['cycle' => 9], 0, 'phase:9:results:announce');
        $second = $q->push('phase.announce', ['cycle' => 9], 0, 'phase:9:results:announce');

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'the duplicate is refused, not thrown');
        $this->assertSame(1, DB::table('gates_jobs')->where('type', 'phase.announce')->count());
    }

    public function test_jobs_without_a_dedupe_key_are_unaffected(): void
    {
        $q = new \AfricaGates\Services\QueueService();
        $q->push('plain.job', ['n' => 1]);
        $q->push('plain.job', ['n' => 2]);

        $this->assertSame(2, DB::table('gates_jobs')->where('type', 'plain.job')->count(),
            'many NULL dedupe_keys must coexist');
    }

    // ══ a seal claims an announcement, so it needs one ═══════════════════════

    /**
     * Give a cycle something to seal: one category, one nominee with support, a complete
     * panel at quorum. Without this the seal is skipped for having no rows and every
     * assertion below passes for the wrong reason.
     */
    private function scorable(int $cycleId): void
    {
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId, 'slug' => 'seal-cat-' . $cycleId, 'title' => 'Sealable',
        ]);
        $nom = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'Sealable nominee', 'country_code' => 'NG',
            'status' => 'approved', 'vote_count' => 40, 'organic_vote_count' => 40,
        ]);
        $crit = array_map('intval',
            DB::table('gates_judge_criteria')->where('is_active', 1)->pluck('id')->all());
        foreach ([81, 82] as $j) {
            DB::table('gates_judges')->insertOrIgnore([
                'id' => $j, 'name' => 'Seal judge ' . $j, 'email' => 'sealj' . $j . '@x.test',
                'is_active' => 1,
            ]);
            foreach ($crit as $cid) {
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nom, 'category_id' => $cat,
                    'criterion_id' => $cid, 'score' => 8,
                ]);
            }
        }
    }

    private function sealedRows(int $cycleId): int
    {
        return DB::table('gates_vote_snapshots')
            ->where('cycle_id', $cycleId)->where('capture_kind', 'release')->count();
    }

    /**
     * A SUPPRESSED ANNOUNCEMENT SEALS NOTHING.
     *
     * ══ THE FAULT ═══════════════════════════════════════════════════════════
     *
     * Entering `results` fired two side effects that never referred to each other: the
     * staleness rule withheld every announcement — correctly — and the seal recorded the
     * standing "as announced" anyway. So on a late cycle the platform told nobody and
     * froze the figures as the announcement in the same pass.
     *
     * What that cost is invisible on every screen. A programme whose results run late
     * passes `results_date` unattended with panels unfinished; the sweep seals whatever
     * the arithmetic gives at that minute, and {@see \AfricaGates\Services\PublicResults::category()}
     * lays it over every view afterwards. The published figures stop moving while scoring
     * continues, so a judge completing a scorecard changes nothing anybody can see. The
     * symptom reported is "the score is not changing", which names the scorer — the one
     * part of it that was working.
     */
    public function test_a_suppressed_announcement_seals_no_standing(): void
    {
        // Six years overdue, exactly as the suppression test above.
        $this->seedCycle(41, 'judging', [
            'voting_open'  => '2020-01-01 00:00:00',
            'voting_close' => '2020-02-01 00:00:00',
            'results_date' => '2020-03-01 00:00:00',
        ]);
        $this->scorable(41);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame('results', $this->storedStatus(41), 'the state is still corrected');
        $this->assertSame(1, $r['suppressed'], 'and the announcement is still withheld');
        $this->assertSame(0, $this->sealedRows(41),
            'so there is no announced standing, and nothing may be sealed as one');
    }

    /**
     * AND A TIMELY RELEASE STILL SEALS, WHICH IS THE HALF THAT MUST NOT REGRESS.
     *
     * The paired assertion. Without it the fix above is satisfiable by never sealing at
     * all, which would put back the fault sealing exists to prevent — a released page
     * recomputing its figures on every view, so an announced 693 becomes 885 across a
     * week of scoring changes with nothing edited.
     */
    public function test_a_timely_release_still_seals_the_standing(): void
    {
        $this->seedCycle(42, 'judging', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $this->scorable(42);

        $r = (new CycleMaterialiser())->run();

        $this->assertSame(0, $r['suppressed'], 'a result one day old is announced');
        $this->assertGreaterThan(0, $this->sealedRows(42),
            'and what was announced is sealed, as it always was');
    }

    // ══ releasing an edition as an act, not a date ═══════════════════════════

    /**
     * AN OPERATOR CAN RELEASE, AND THAT SEALS.
     *
     * ══ THE GAP THIS CLOSES ═════════════════════════════════════════════════
     *
     * Every route into `results` was this class's date sweep, and the sweep never
     * revisits a cycle: the ledger's UNIQUE (cycle_id, to_status) is its claim. Combined
     * with the staleness rule — which correctly withholds the seal when nothing was
     * announced — an edition that crossed its boundary late reached `results`, published
     * live figures labelled as recomputed, and had NO route to ever be sealed.
     *
     * `CycleService::manualTransitionError()` refuses a hand-set `results` on purpose, and
     * `release()` does not relax it: the operator asks for a release and the same
     * quorum-checked promotion and the same seal run as on the scheduled path.
     */
    public function test_an_operator_can_release_an_edition_and_it_seals(): void
    {
        $this->seedCycle(51, 'judging', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('+2 days')),
        ]);
        $this->scorable(51);

        $r = (new CycleMaterialiser())->release(51, 7);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertSame('results', $this->storedStatus(51));
        $this->assertGreaterThan(0, $this->sealedRows(51), 'the standing is sealed as announced');

        // On the same ledger the sweep writes to, and marked as announced — which is the
        // distinction the staleness rule could not make on its own.
        $row = DB::table('gates_cycle_transitions')->where('cycle_id', 51)
            ->where('to_status', 'results')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->notify, 'a person releasing IS the announcement');
        $this->assertStringContainsString('admin:7', (string) $row->actor,
            'and who did it, because this is the act that crowns somebody');
    }

    /**
     * AND IT REPAIRS THE CYCLE THE SWEEP LEFT UNSEALED.
     *
     * The reason `release()` accepts a cycle already in `results`. Without this the
     * suppressed-announcement fix would be a one-way door: honest, and permanent.
     */
    public function test_releasing_seals_an_edition_the_sweep_left_unsealed(): void
    {
        // Six years overdue, so the sweep suppresses and does not seal.
        $this->seedCycle(52, 'judging', [
            'voting_open'  => '2020-01-01 00:00:00',
            'voting_close' => '2020-02-01 00:00:00',
            'results_date' => '2020-03-01 00:00:00',
        ]);
        $this->scorable(52);

        (new CycleMaterialiser())->run();
        $this->assertSame('results', $this->storedStatus(52), 'the sweep advanced it');
        $this->assertSame(0, $this->sealedRows(52), 'and correctly sealed nothing');

        $r = (new CycleMaterialiser())->release(52, 7);

        $this->assertTrue($r['ok'], (string) $r['message']);
        $this->assertGreaterThan(0, $this->sealedRows(52),
            'an operator can now publish what the sweep would not claim to have announced');
    }

    /**
     * RELEASING TWICE SEALS ONCE.
     *
     * A double press, a retried POST, two operators at the same moment. The second call
     * must not write a second standing — a cycle with two seals has no announced result,
     * it has two.
     */
    public function test_releasing_twice_seals_once(): void
    {
        $this->seedCycle(53, 'judging', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $this->scorable(53);

        $a = (new CycleMaterialiser())->release(53, 7);
        $rows = $this->sealedRows(53);
        $b = (new CycleMaterialiser())->release(53, 9);

        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok'], 'the second press is not an error — the release happened');
        $this->assertSame(0, $b['sealed'], 'but it seals nothing new');
        $this->assertSame($rows, $this->sealedRows(53));
        $this->assertSame(1, DB::table('gates_cycle_transitions')->where('cycle_id', 53)
            ->where('to_status', 'results')->count(), 'and claims the phase once');
    }

    /**
     * AND IT REFUSES AN EDITION THE PANEL HAS NOT REACHED.
     *
     * Releasing out of `voting` would crown a field nobody has marked, and the promotion
     * would then decide the award on whoever happened to be judged. The phase is COMPUTED
     * rather than read off the status column, because that column is a materialised cache
     * and this is an authorisation question.
     */
    public function test_an_edition_before_judging_cannot_be_released(): void
    {
        $this->seedCycle(54, 'voting', [
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+20 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        $this->scorable(54);

        $r = (new CycleMaterialiser())->release(54, 7);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('has not marked', (string) $r['message']);
        $this->assertSame('voting', $this->storedStatus(54), 'and nothing moved');
        $this->assertSame(0, $this->sealedRows(54));
    }

    /** An edition that does not exist is a refusal, not a crash. */
    public function test_releasing_a_missing_edition_is_refused(): void
    {
        $r = (new CycleMaterialiser())->release(99999, 7);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('does not exist', (string) $r['message']);
    }

    /**
     * THE BUTTON EXISTS, AND IT IS THE ONLY WAY IN.
     *
     * `release()` with no route and no control is the shape this repo keeps paying for —
     * `manageUrl()` built a donor's stop link that no template ever contained. The
     * question is not "does it work?" but **who is ever handed this?**
     */
    public function test_the_release_screen_offers_the_action(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/result-release.twig');
        $this->assertStringContainsString('action="/admin/result-release/release"', $tpl);
        $this->assertStringContainsString('name="_token"', $tpl, 'the CSRF middleware reads _token');
        $this->assertStringContainsString('data-confirm', $tpl,
            'the admin CSP has no unsafe-inline, so confirmation goes through agConfirm');

        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertStringContainsString("'/result-release/release'", $routes);

        $ctl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/ResultReleaseController.php');
        $this->assertStringContainsString('results.release', $ctl, 'and it is audited');
    }
}
