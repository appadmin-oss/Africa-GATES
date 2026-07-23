<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\CpiService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cultural Power Index — final weighting (per latest guidance):
 *   45% — Community votes (cohort-normalised raw count)
 *   55% — Expert panel score (judges' weighted criteria average)
 *
 * Both expressed as 0..1 then scaled to a 0..1000 final score.
 *
 * Profile-level CPI rolls up each nominee a profile is attached to:
 *   profile.cpi = average of (linked nominees' final scores)
 *   if no nominees yet → falls back to a baseline using verification +
 *   profile completeness so the leaderboard isn't empty before the
 *   first cycle.
 */
#[AsCommand(name: 'cpi:recompute', description: 'Recompute CPI (45% community + 55% judges).')]
class CpiRecomputeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $cpi = new CpiService();

        // ── Nominee-level scores via the shared scorer ───────────────────
        // One source of truth (config-weighted, cohort-normalised over APPROVED
        // nominees per category) shared with winner selection + snapshots.
        $scoring = new \AfricaGates\Services\NomineeScoringService();
        $byProf = [];   // profile_id → array of linked nominee final scores
        $scored = 0;
        $catIds = DB::table('gates_nominees')->select('category_id')->distinct()->pluck('category_id');
        foreach ($catIds as $catId) {
            $scores = $scoring->scoreCategory((int) $catId);
            if (!$scores) continue;
            $profByNom = DB::table('gates_nominees')->where('category_id', (int) $catId)
                ->whereIn('id', array_keys($scores))->pluck('profile_id', 'id');
            foreach ($scores as $nomId => $s) {
                $scored++;
                $pid = $profByNom[$nomId] ?? null;
                if (!empty($pid)) $byProf[$pid][] = $s['cpi_score'];
            }
        }
        $io->writeln('Computed ' . $scored . ' nominee scores.');

        // ── Profile rollup ───────────────────────────────────────────────
        $count = 0;
        $profiles = \AfricaGates\Services\ProfileMergeService::notMerged(DB::table('gates_profiles')->where('status', 'approved'))->get();
        foreach ($profiles as $p) {
            $linked = $byProf[$p->id] ?? [];
            $final  = $cpi->profileRollup($linked)
                ?? $cpi->baselineScore($p->verification_tier ?? null, (float)$p->completeness_pct, (int)$p->view_count);
            $tier   = $cpi->tierFor($final);
            DB::table('gates_profiles')->where('id', $p->id)->update([
                'cpi_score'         => $final,
                'cpi_tier'          => $tier,
                'cpi_last_computed' => Carbon::now()->toDateTimeString(),
            ]);
            try {
                DB::table('gates_cpi_history')->insert([
                    'profile_id'  => $p->id,
                    'cpi_score'   => $final,
                    'cpi_tier'    => $tier,
                    'computed_at' => Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable $e) {}
            $count++;
        }
        $io->success("Recomputed CPI for $count profiles.");
        return Command::SUCCESS;
    }
}
