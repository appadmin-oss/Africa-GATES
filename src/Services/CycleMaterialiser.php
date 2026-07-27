<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE cycle lifecycle engine. One implementation, called by both schedulers.
 *
 * There used to be two, and the weaker one was the one that actually ran:
 *
 *   Maintenance::advanceCycles()  — SCHEDULED every 15 min by cron/maintenance.php.
 *     Jumped straight to the target status with no forward-only guard, wrote no
 *     transitions ledger row, and — critically — never promoted winners. So a
 *     cycle reached 'results' and crowned nobody: no winner/runner_up statuses,
 *     no activity rows, no congratulations emails, `awards_given` stuck at zero.
 *
 *   CycleAdvanceCommand — documented in the README, covered by its own tests,
 *     and scheduled NOWHERE. It had the forward-only guard, the ledger and the
 *     quorum-checked winner promotion. All of it was dead code in production.
 *
 * The test suite drove the unscheduled engine, so three guarantees were asserted
 * green about code that never ran. Both entry points now delegate here.
 *
 * WHAT THIS IS AND IS NOT. Since the phase became a computed value
 * ({@see CyclePolicy}), this class is no longer the source of truth for whether
 * voting is open — {@see BallotGuard} is, on every request, with no scheduler
 * involved. This is the MATERIALISER: it writes the cached `status` column so
 * queries and admin lists stay accurate, appends the audit ledger, fires the
 * phase webhook, promotes winners and sends phase mail. If it never runs,
 * voting still closes on time; only the derived artefacts go stale.
 */
final class CycleMaterialiser
{
    /**
     * Announcements are SUPPRESSED when a cycle enters 'results' more than this
     * many days late. A platform whose scheduler has been dead for a month must
     * correct its standings without emailing every winner about a competition
     * that ended long ago. The promotion still happens and is still logged —
     * only the outbound notification is withheld, loudly.
     */
    public const ANNOUNCE_GRACE_DAYS = 7;

    /** @var list<string> human-readable log lines from the last run */
    private array $lines = [];

    public function __construct(
        private readonly bool $dryRun = false,
        private readonly ?CacheService $cache = null,
    ) {}

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    private function log(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * Advance every non-archived cycle at most ONE phase toward its computed
     * phase, forward only.
     *
     * @return array{changed:int, checked:int, promoted:int, suppressed:int, message:string}
     */
    public function run(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $changed = $checked = $promoted = $suppressed = 0;

        try {
            $cycles = DB::table('gates_award_cycles')->where('status', '!=', 'archived')->get();
        } catch (\Throwable $e) {
            $this->log('! could not read cycles: ' . $e->getMessage());
            return ['changed' => 0, 'checked' => 0, 'promoted' => 0, 'suppressed' => 0,
                    'message' => 'cycle read failed: ' . $e->getMessage()];
        }

        foreach ($cycles as $c) {
            // Only manage cycles with at least one date window. A window-less
            // cycle has nothing to derive from, so leave it entirely alone.
            $hasWindows = $c->nominations_open || $c->nominations_close
                || $c->voting_open || $c->voting_close || $c->results_date;
            if (!$hasWindows) continue;
            $checked++;

            $target  = CyclePolicy::phaseFor($c, $now);
            $current = CyclePhase::fromStored($c->status ?? null);

            // Keep the indexed boundary fresh on EVERY pass, not only when the
            // phase moves. The sweep below is only trustworthy if this column is
            // maintained for cycles that are simply waiting.
            if (!$this->dryRun) $this->refreshBoundary($c, $now);

            if ($target->ordinal() === $current->ordinal()) continue;

            // FORWARD ONLY. A date-driven materialiser must never regress a
            // cycle — a mistyped results_date would otherwise un-announce
            // published winners with no ledger row recording it. A genuine
            // backward move stays a deliberate manual action.
            if ($target->ordinal() < $current->ordinal()) {
                $this->log(sprintf('  cycle #%d: skipped backward %s -> %s (manual only)',
                    (int) $c->id, $current->value, $target->value));
                continue;
            }

            // ONE PHASE PER RUN so no phase is ever skipped — critically
            // 'voting'. A first run landing after voting_close would otherwise
            // jump a cycle straight to judging, leaving it unvotable.
            $want = $current->next();
            if ($want === null) continue;

            $this->log(sprintf('  cycle #%d (programme %d, %d): %s -> %s%s',
                (int) $c->id, (int) $c->programme_id, (int) $c->year,
                $current->value, $want->value,
                $want->ordinal() !== $target->ordinal() ? ' (stepping toward ' . $target->value . ')' : ''));

            if ($this->dryRun) { $changed++; continue; }

            // CLAIM FIRST. The ledger's UNIQUE (cycle_id, to_status) makes this
            // INSERT the mutex: exactly one caller wins and fires the side
            // effects, everyone else conflicts and does nothing. This is what
            // makes concurrent runs safe — CronGuard deliberately fails OPEN,
            // so two schedulers CAN overlap by design.
            $boundary = self::boundaryFor($want, $c);
            $late     = self::daysLate($boundary, $now);
            $announce = $late <= self::ANNOUNCE_GRACE_DAYS;

            if (!$this->claim((int) $c->id, $current->value, $want->value, $boundary, $announce, $now)) {
                $this->log(sprintf('  cycle #%d: %s already claimed by another run — skipping side effects',
                    (int) $c->id, $want->value));
                continue;
            }

            try {
                DB::table('gates_award_cycles')->where('id', $c->id)
                    ->update(['status' => $want->storedValue()]);
            } catch (\Throwable $e) {
                $this->log('  ! update failed for cycle #' . (int) $c->id . ': ' . $e->getMessage());
                continue;
            }

            WebhookService::dispatch('cycle.status_changed', [
                'cycle_id'     => (int) $c->id,
                'programme_id' => (int) $c->programme_id,
                'year'         => (int) $c->year,
                'from'         => $current->value,
                'to'           => $want->value,
            ]);

            if (!$announce) {
                $suppressed++;
                $this->log(sprintf(
                    '  ! cycle #%d entered %s %d days late — state corrected, ANNOUNCEMENTS SUPPRESSED '
                    . '(a months-old result must not email congratulations now)',
                    (int) $c->id, $want->value, $late));
            }
            if ($want === CyclePhase::Results) {
                // Durable derived data (winner promotion) is ALWAYS replayed —
                // it is correctness. Only the outbound notification is withheld.
                $promoted += $this->promoteWinners((int) $c->id, $announce);
            }

            $changed++;
        }

        if ($changed > 0 && !$this->dryRun) {
            $this->bustAwardViews();
        }

        $msg = ($this->dryRun ? '[dry-run] ' : '') . "checked $checked, advanced $changed cycle(s)"
            . ($promoted ? ", promoted $promoted winner(s)" : '')
            . ($suppressed ? ", $suppressed announcement(s) suppressed as stale" : '');
        $this->log($msg);

        return ['changed' => $changed, 'checked' => $checked, 'promoted' => $promoted,
                'suppressed' => $suppressed, 'message' => $msg];
    }

    /** Keep the indexed next_boundary_at column in step with the windows. */
    private function refreshBoundary(object $c, Carbon $now): void
    {
        try {
            $at = CyclePolicy::nextBoundaryFor($c, $now);
            if (($c->next_boundary_at ?? null) !== $at) {
                DB::table('gates_award_cycles')->where('id', $c->id)->update(['next_boundary_at' => $at]);
            }
        } catch (\Throwable) { /* column may predate the migration */ }
    }

    /**
     * Cycles whose declared boundary has passed but whose materialised status
     * has not caught up — i.e. the materialiser is behind.
     *
     * This is the traffic-INDEPENDENT half of divergence detection.
     * gates_phase_drift only records divergences observed on the vote and
     * nominate paths, so a cycle nobody happens to interact with can drift
     * unnoticed. This is one indexed range scan; alarm when it returns anything,
     * and treat max(now - next_boundary_at) as the lag metric.
     *
     * @return list<array{cycle_id:int, stored_status:string, computed_phase:string, boundary_at:?string, seconds_behind:int}>
     */
    public static function divergences(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $out = [];
        try {
            $rows = DB::table('gates_award_cycles')
                ->where('status', '!=', 'archived')
                ->whereNotNull('next_boundary_at')
                ->where('next_boundary_at', '<=', $now->toDateTimeString())
                ->get();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $c) {
            $computed = CyclePolicy::phaseFor($c, $now);
            $stored   = CyclePhase::fromStored($c->status ?? null);
            if ($computed === $stored) continue;
            $ts = strtotime((string) $c->next_boundary_at);
            $out[] = [
                'cycle_id'       => (int) $c->id,
                'stored_status'  => $stored->value,
                'computed_phase' => $computed->value,
                'boundary_at'    => (string) $c->next_boundary_at,
                'seconds_behind' => $ts === false ? 0 : max(0, $now->getTimestamp() - $ts),
            ];
        }
        return $out;
    }

    /** How many whole days past $when we are (0 when $when is future/absent). */
    public static function daysLate(mixed $when, Carbon $now): int
    {
        if (!$when) return 0;
        $ts = strtotime((string) $when);
        if ($ts === false) return 0;
        return max(0, (int) floor(($now->getTimestamp() - $ts) / 86400));
    }

    /**
     * For a cycle that just entered 'results', mark the top-ranked nominee in
     * each category 'winner' and the next 'runner_up'.
     *
     * Ranks by the FULL Cultural Power Index (config-weighted community votes +
     * expert judges) via the shared scorer, NOT raw vote_count, so the award
     * reflects the published methodology. Only nominees meeting the judge quorum
     * are winner-eligible: a lone judge, or a category the panel never reached,
     * must not decide an award — such categories are skipped for manual review,
     * and under-quorum nominees are excluded from the ranking entirely rather
     * than scored 0 and pushed to loser. Idempotent.
     *
     * $announce=false promotes silently (stale backlog — see ANNOUNCE_GRACE_DAYS).
     */
    private function promoteWinners(int $cycleId, bool $announce = true): int
    {
        $scoring  = new NomineeScoringService();
        $promoted = 0;

        try {
            $catIds = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->pluck('id');
        } catch (\Throwable $e) {
            $this->log('    ! could not read categories: ' . $e->getMessage());
            return 0;
        }

        foreach ($catIds as $catId) {
            $scores = $scoring->scoreCategory((int) $catId);
            if (!$scores) continue;

            $eligibleIds = array_keys(array_filter($scores, static fn ($s) => !empty($s['eligible'])));
            if (!$eligibleIds) {
                $this->log(sprintf('    ! category %d: no nominee meets the judge quorum — skipping promotion (manual review)', (int) $catId));
                continue;
            }

            $ranked = DB::table('gates_nominees')->whereIn('id', $eligibleIds)->get()
                ->map(function ($n) use ($scores) {
                    $n->cpi = (int) ($scores[$n->id]['cpi_score'] ?? 0);
                    return $n;
                })
                ->filter(fn ($n) => $n->cpi > 0 || (int) $n->vote_count > 0)
                ->sort(fn ($a, $b) => [$b->cpi, $b->vote_count, $a->id] <=> [$a->cpi, $a->vote_count, $b->id])
                ->values();
            if ($ranked->isEmpty()) continue;

            $winner   = $ranked->shift();
            $runnerUp = $ranked->shift();

            // Demote any prior winners/runners in this category that aren't the new picks.
            DB::table('gates_nominees')->where('category_id', (int) $catId)
                ->whereIn('status', ['winner', 'runner_up'])
                ->whereNotIn('id', array_filter([$winner?->id, $runnerUp?->id]))
                ->update(['status' => 'approved']);

            foreach ([[$winner, 'winner', '*'], [$runnerUp, 'runner_up', '-']] as [$nom, $kind, $glyph]) {
                if (!$nom || $nom->status === $kind) continue;
                DB::table('gates_nominees')->where('id', $nom->id)->update(['status' => $kind]);
                CycleAnnouncer::record((int) $nom->id, $kind, $announce);
                $this->log(sprintf('    %s %s: %s (cat %d, CPI %d, %d votes)%s',
                    $glyph, $kind, (string) $nom->name, (int) $catId,
                    (int) $nom->cpi, (int) $nom->vote_count, $announce ? '' : ' [silent]'));
                $promoted++;
            }
        }

        return $promoted;
    }

    /**
     * Claim a phase entry by inserting the ledger row. Returns true only for
     * the caller that won.
     *
     * `boundary_at` is the DECLARED date that caused the transition;
     * `observed_at` is when the system first noticed. Recording both restores
     * the audit trail a computed phase would otherwise destroy — a phase change
     * driven by the passage of time is not a write, so without this there is no
     * row, no actor and no evidence anyone noticed. It also distinguishes
     * "closed on time" from "closed on time, but nobody looked for three weeks".
     */
    private function claim(int $cycleId, string $from, string $to, ?string $boundary, bool $notify, Carbon $now): bool
    {
        try {
            DB::table('gates_cycle_transitions')->insert([
                'cycle_id'    => $cycleId,
                'from_status' => $from,
                'to_status'   => $to,
                'reason'      => 'auto: date window' . ($notify ? '' : ' (stale — notifications suppressed)'),
                'actor'       => 'cron',
                'boundary_at' => $boundary,
                'observed_at' => $now->toDateTimeString(),
                'notify'      => $notify ? 1 : 0,
                'created_at'  => $now->toDateTimeString(),
            ]);
            return true;
        } catch (\Throwable $e) {
            // A UNIQUE violation means another run already claimed this phase —
            // the correct, expected outcome, not an error.
            return false;
        }
    }

    /** The declared date that causes entry into $phase, if there is one. */
    private static function boundaryFor(CyclePhase $phase, object $c): ?string
    {
        $v = match ($phase) {
            CyclePhase::Nominations  => $c->nominations_open  ?? null,
            CyclePhase::Shortlisting => $c->nominations_close ?? null,
            CyclePhase::Voting       => $c->voting_open       ?? null,
            CyclePhase::Judging      => $c->voting_close      ?? ($c->nominations_close ?? null),
            CyclePhase::Results      => $c->results_date      ?? null,
            default                  => null,
        };
        return $v ? (string) $v : null;
    }

    /**
     * Invalidate every cached view derived from a cycle's phase.
     *
     * The old engines each cleared `awards:%` (and one also `%leaderboard%`),
     * which missed FOUR of the six award keys — including `vote:hub`, the one
     * the /vote hub reads, and `award:prog:*`, which the LIKE pattern does not
     * match at all (no trailing 's'). So after a phase change /vote kept
     * advertising "Voting open" for up to 10 minutes and /awards/{slug} for 30.
     */
    private function bustAwardViews(): void
    {
        if ($this->cache) { $this->cache->forgetAwardViews(); return; }
        (new CacheService())->forgetAwardViews();
    }
}
