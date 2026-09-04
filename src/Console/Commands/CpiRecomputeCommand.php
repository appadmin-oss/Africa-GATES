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
                if (empty($pid)) continue;

                // ── A PROVISIONAL SCORE IS NOT A PUBLIC STANDING ─────────────
                //
                // Below quorum the judge half is scored ZERO rather than renormalised
                // away — deliberately, so an unjudged nominee cannot top a board on
                // popularity alone. `scoreCategory()` says so in as many words and hands
                // out a `provisional` flag whose entire stated purpose is that "a
                // community-only score sits in the same column as a full CPI and reads as
                // one — the figures are not comparable and nothing said so".
                //
                // This rollup was the one caller that most needed the flag and was the one
                // that dropped it. The consequence is not internal: `cpi_score` and
                // `cpi_tier` are printed on the vote page, the leaderboard, the registry
                // index and a person's own public profile, with a gold star beside them.
                // So a nominee no judge has yet opened was published at 450/1000 — which
                // `tierFor()` calls GOLD — on the strength of the votes alone, and the
                // understatement the zero was chosen for became a verdict the moment it
                // was averaged into somebody's standing.
                //
                // Left out entirely rather than counted low. A profile whose nominations
                // are all still unjudged falls through to the baseline below, which is the
                // score it carried the day before it was nominated — the honest answer to
                // "what is their standing?" being "we do not have a judged one yet".
                if (!empty($s['provisional'])) continue;

                $byProf[$pid][] = $s['cpi_score'];
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
            $was    = $p->cpi_score === null ? null : (int) $p->cpi_score;

            DB::table('gates_profiles')->where('id', $p->id)->update([
                'cpi_score'         => $final,
                'cpi_tier'          => $tier,
                'cpi_last_computed' => Carbon::now()->toDateTimeString(),
            ]);

            // ── A HISTORY OF MOVEMENTS, NOT A LOG OF TICKS ───────────────────
            //
            // This ran every six hours and wrote a row per approved profile whatever
            // happened, so the table was four rows a day each, forever, of which
            // essentially all were identical to the one before — two indexes maintained on
            // every insert of a value nobody had changed. `cpi_last_computed` above already
            // answers "did the recompute run"; this column set can only usefully answer
            // "did their standing MOVE, and when", which is the question its own
            // profile_id/computed_at indexes were cut for, and which a wall of duplicates
            // buries. The value between two rows is the earlier row's, so nothing is lost.
            //
            // A profile's first computed score is a movement too — `cpi_score` starts at
            // 0, so the first run that produces anything writes the opening row. One that
            // has only ever been zero has no rows at all, which is the right answer: it has
            // never moved.
            if ($was !== $final) {
                try {
                    DB::table('gates_cpi_history')->insert([
                        'profile_id'  => $p->id,
                        'cpi_score'   => $final,
                        'cpi_tier'    => $tier,
                        'computed_at' => Carbon::now()->toDateTimeString(),
                    ]);
                } catch (\Throwable $e) {}
            }
            $count++;
        }
        $io->success("Recomputed CPI for $count profiles.");
        return Command::SUCCESS;
    }
}
