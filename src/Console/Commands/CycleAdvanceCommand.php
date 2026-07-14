<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Console\CronLog;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Auto-advances each award cycle's `status` from its date windows so the
 * platform runs its own lifecycle (upcoming → nominations → voting → judging
 * → results) instead of relying on an admin to flip a dropdown. This is what
 * keeps the public site from "going dark" between phases.
 *
 * Schedule hourly:  bin/console cycles:advance
 */
#[AsCommand(name: 'cycles:advance', description: 'Advance award cycle statuses based on their date windows.')]
class CycleAdvanceCommand extends Command
{
    /** Lifecycle ordinal — enforces forward-only auto transitions. */
    private const ORDER = ['upcoming' => 0, 'nominations' => 1, 'voting' => 2, 'judging' => 3, 'results' => 4, 'archived' => 5];

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing.');
    }

    /** Lifecycle status name for an ordinal (inverse of self::ORDER). */
    private static function statusName(int $ord): string
    {
        $name = array_search($ord, self::ORDER, true);
        return $name !== false ? $name : 'archived';
    }

    /** Derive the correct status from the date windows (later phases override). */
    public static function statusFor(object $c, Carbon $now): string
    {
        $at = static fn($v) => $v ? Carbon::parse((string)$v) : null;
        $nomOpen  = $at($c->nominations_open ?? null);
        $nomClose = $at($c->nominations_close ?? null);
        $voteOpen = $at($c->voting_open ?? null);
        $voteClose= $at($c->voting_close ?? null);
        $results  = $at($c->results_date ?? null);

        $status = 'upcoming';
        if ($nomOpen  && $now->gte($nomOpen))   $status = 'nominations';
        if ($nomClose && $now->gt($nomClose))   $status = 'judging';   // shortlisting gap
        if ($voteOpen && $now->gte($voteOpen))  $status = 'voting';
        if ($voteClose&& $now->gt($voteClose))  $status = 'judging';   // final judging
        if ($results  && $now->gte($results))   $status = 'results';
        return $status;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool)$input->getOption('dry-run');
        $now = Carbon::now();

        $result = CronLog::run('cycles:advance', function () use ($io, $dry, $now) {
            // Only manage cycles that have at least one date window set and are
            // not archived (archive stays a manual, deliberate action).
            $cycles = DB::table('gates_award_cycles')->where('status', '!=', 'archived')->get();
            $changed = 0; $checked = 0; $promoted = 0;
            foreach ($cycles as $c) {
                $hasWindows = $c->nominations_open || $c->nominations_close || $c->voting_open || $c->voting_close || $c->results_date;
                if (!$hasWindows) continue;
                $checked++;
                $target    = self::statusFor($c, $now);
                $curOrd    = self::ORDER[$c->status] ?? 0;
                $targetOrd = self::ORDER[$target] ?? 0;
                if ($targetOrd === $curOrd) continue;
                // Forward-only: the date-driven cron must never auto-REGRESS a cycle
                // to an earlier phase (e.g. mis-edited dates un-announcing winners).
                // A backward move is left to a deliberate manual/admin action.
                if ($targetOrd < $curOrd) {
                    $io->writeln(sprintf('  cycle #%d: skipped backward %s → %s (manual only)', $c->id, $c->status, $target));
                    continue;
                }
                // Advance at most ONE phase per run so no phase is ever skipped —
                // critically 'voting'. A first run that lands after voting_close
                // would otherwise jump straight to 'judging', leaving the cycle
                // unvotable and (forward-only) unrecoverable.
                $want = self::statusName($curOrd + 1);
                $io->writeln(sprintf('  cycle #%d (programme %d, %d): %s → %s%s',
                    $c->id, $c->programme_id, $c->year, $c->status, $want,
                    $want !== $target ? " (stepping toward $target)" : ''));
                if (!$dry) {
                    DB::table('gates_award_cycles')->where('id', $c->id)->update(['status' => $want]);
                    $this->logTransition((int)$c->id, (string)$c->status, $want, 'auto: date window', $io);
                    // When a cycle hits 'results', auto-promote the top nominee per
                    // category to 'winner' and the next to 'runner_up' so the public
                    // site can announce them without a manual step. Idempotent.
                    if ($want === 'results') {
                        $promoted += $this->promoteWinners((int)$c->id, $io);
                    }
                }
                $changed++;
            }
            // Invalidate the cached active-programmes view so the change shows immediately.
            if ($changed > 0 && !$dry) {
                try { DB::table('gates_cache')->where('cache_key', 'like', 'awards:%')->delete(); } catch (\Throwable $e) {}
                try { DB::table('gates_cache')->where('cache_key', 'like', '%leaderboard%')->delete(); } catch (\Throwable $e) {}
            }
            return ['message' => ($dry ? '[dry-run] ' : '') . "checked $checked, advanced $changed cycle(s)"
                . ($promoted ? ", promoted $promoted winner(s)" : '')];
        });

        $io->success(($dry ? '[dry-run] ' : '') . $result['message']);
        return Command::SUCCESS;
    }

    /**
     * For a cycle that just entered 'results', mark the top vote-getter in
     * each category as 'winner' and the runner-up as 'runner_up'. Idempotent:
     * if no nominees received votes the category is skipped; if a category
     * already has a winner of equal score we leave it alone. Records a public
     * activity entry and best-effort emails the linked profile.
     */
    private function promoteWinners(int $cycleId, SymfonyStyle $io): int
    {
        $scoring = new \AfricaGates\Services\NomineeScoringService();
        $promoted = 0;
        $catIds = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->pluck('id');
        foreach ($catIds as $catId) {
            // Rank by the FULL Cultural Power Index (config-weighted community +
            // expert judges) via the shared scorer — NOT raw vote_count — so the
            // award reflects the published, per-cycle-configurable methodology.
            $scores = $scoring->scoreCategory((int) $catId);
            if (!$scores) continue;
            // Only nominees meeting the judge quorum (≥ min_judges_per_nominee
            // COMPLETE scorecards) are winner-eligible. A lone judge — or a
            // category the panel never reached — must not decide an award; skip +
            // alert for manual review. Under-quorum nominees are excluded from the
            // ranking entirely (never scored 0/10 and pushed to loser).
            $eligibleIds = array_keys(array_filter($scores, static fn ($s) => !empty($s['eligible'])));
            if (!$eligibleIds) {
                $io->writeln(sprintf('    ! category %d: no nominee meets the judge quorum — skipping promotion (manual review)', (int) $catId));
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
            DB::table('gates_nominees')->where('category_id', (int)$catId)
                ->whereIn('status', ['winner', 'runner_up'])
                ->whereNotIn('id', array_filter([$winner?->id, $runnerUp?->id]))
                ->update(['status' => 'approved']);

            if ($winner && $winner->status !== 'winner') {
                DB::table('gates_nominees')->where('id', $winner->id)->update(['status' => 'winner']);
                $this->announceWinner((int)$winner->id, 'winner');
                $io->writeln(sprintf('    ★ winner: %s (cat %d, CPI %d, %d votes)', $winner->name, (int)$catId, (int)$winner->cpi, (int)$winner->vote_count));
                $promoted++;
            }
            if ($runnerUp && $runnerUp->status !== 'runner_up') {
                DB::table('gates_nominees')->where('id', $runnerUp->id)->update(['status' => 'runner_up']);
                $this->announceWinner((int)$runnerUp->id, 'runner_up');
                $io->writeln(sprintf('    ☆ runner-up: %s (cat %d, CPI %d, %d votes)', $runnerUp->name, (int)$catId, (int)$runnerUp->cpi, (int)$runnerUp->vote_count));
                $promoted++;
            }
        }
        return $promoted;
    }

    /** Append an auditable record of a cycle phase change. */
    private function logTransition(int $cycleId, string $from, string $to, string $reason, SymfonyStyle $io): void
    {
        try {
            DB::table('gates_cycle_transitions')->insert([
                'cycle_id'    => $cycleId,
                'from_status' => $from,
                'to_status'   => $to,
                'reason'      => $reason,
                'actor'       => 'cron',
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // Never block a transition because the audit insert failed — but surface it.
            $io->writeln('    ! transition log failed: ' . $e->getMessage());
        }
    }

    /** Record the result publicly + best-effort email the linked profile. */
    private function announceWinner(int $nomineeId, string $kind): void
    {
        $n = DB::table('gates_nominees as n')
            ->leftJoin('gates_profiles as p', 'p.id', '=', 'n.profile_id')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $nomineeId)
            ->select(['n.name','n.category_id','c.title as category','p.email as profile_email','p.display_name as profile_name'])
            ->first();
        if (!$n) return;

        try {
            DB::table('gates_activity')->insert([
                'kind' => 'winner',
                'actor_label' => 'Africa GATES',
                'target_type' => 'nominee',
                'target_id'   => $nomineeId,
                'target_label'=> $n->name,
                'meta'        => json_encode(['kind' => $kind, 'category' => $n->category]),
                'is_public'   => 1,
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {}

        // Email the linked profile if we have one (best-effort, no DI in console).
        if (!empty($n->profile_email) && filter_var($n->profile_email, FILTER_VALIDATE_EMAIL)) {
            try {
                $smtp = [
                    'host' => $_ENV['SMTP_HOST'] ?? 'smtp-relay.brevo.com',
                    'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
                    'username' => $_ENV['SMTP_USER'] ?? '',
                    'password' => $_ENV['SMTP_PASS'] ?? '',
                    'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@afrovanguard.org.ng',
                    'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'Africa GATES',
                ];
                $mailer = new \AfricaGates\Services\OtpService($smtp);
                $base = rtrim((string)($_ENV['APP_URL'] ?? 'https://afg.afrovanguard.org.ng'), '/');
                $headline = $kind === 'winner' ? 'Congratulations — you won.' : 'Congratulations — you are a runner-up.';
                $nm   = htmlspecialchars((string)$n->profile_name, ENT_QUOTES, 'UTF-8');
                $catN = htmlspecialchars((string)$n->category, ENT_QUOTES, 'UTF-8');
                $year = date('Y');
                $htmlBody = "<p>Hi <strong>{$nm}</strong>,</p><p style=\"font-size:17px;font-weight:700;color:#10292C\">{$headline}</p>"
                    . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:14px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:12px 16px\"><tr><td style=\"font-size:14px;color:#166534;line-height:1.7\">Category: <strong>{$catN}</strong><br>Cycle: <strong>{$year}</strong></td></tr></table>"
                    . "<p>The full results are now live — and your profile carries a permanent record on the leaderboard.</p>"
                    . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$base}/leaderboard\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">See the results →</a></p>";
                $plain = "Hi {$n->profile_name},\n\n$headline\n\nCategory: {$n->category}\nCycle: {$year}\n\nThe full results are now on {$base}/leaderboard — and your profile carries a permanent record.\n\n— Africa GATES";
                $mailer->sendBranded((string)$n->profile_email, $headline . ' — Africa GATES', $htmlBody, $plain, 'Results', $base . '/assets/img/illustrations/illo-trophy.jpg');
            } catch (\Throwable $e) { /* best-effort */ }
        }
    }
}
