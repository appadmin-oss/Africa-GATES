<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\SnapshotService;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walk the standings hash chain and say — out loud — whether the record holds.
 *
 * ── WHY THIS COMMAND HAD TO EXIST ────────────────────────────────────────────
 *
 * gates_vote_snapshots has been hash-chained since it was built, and
 * SnapshotService::verify() has been able to check it the whole time. Nothing
 * called it. Not the cron, not the doctor, not an admin screen — the only callers
 * in the entire tree were unit tests.
 *
 * Tamper evidence that is never read is not tamper evidence. It is a cost paid on
 * every capture for a reassurance nobody collects: the record would have been
 * altered, the chain would have registered it exactly as designed, and the finding
 * would have sat in a column no query ever selected. The mechanism was sound and
 * the loop was open at the end.
 *
 * So: a command an operator can run and quote, and a scheduled task that fails the
 * maintenance run when the answer is no (see Support\Maintenance::verifyChain).
 *
 * ── IT IS BUILT TO BE ABLE TO SAY NO ─────────────────────────────────────────
 *
 * Same discipline as votes:proof. The exit code is non-zero when the chain is
 * broken, so this belongs in cron rather than in somebody's memory. And it reports
 * `unchained` separately: rows written before the prev_hash column existed are
 * outside the chain and cannot be vouched for by anything. Folding them into a
 * "verified" count would make the headline number a lie in the one place where the
 * number is the entire point.
 *
 * ── READING A FAILURE ────────────────────────────────────────────────────────
 *
 * `broken_at` is the FIRST row that does not follow from the one before it, not
 * necessarily the row that was edited — every link after a change fails too. The
 * likeliest cause by far is not tampering: it is two concurrent captures forking
 * the chain, which is why UNIQUE(prev_hash) now forbids that at the database. On a
 * database that forked before that index landed, the break is permanent and
 * honest; the rows are still true readings, they just stop being one line.
 */
#[AsCommand(name: 'standings:verify', description: 'Re-walk the tamper-evident standings chain and prove it intact.')]
final class StandingsVerifyCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('chunk', null, InputOption::VALUE_REQUIRED,
                         'Rows per batch while walking (memory vs. round-trips).', '1000')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable, for a dashboard or a cron.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if (!DB::schema()->hasTable('gates_vote_snapshots')) {
                $io->error('gates_vote_snapshots does not exist — run db:migrate first.');
                return Command::FAILURE;
            }
            $r = (new SnapshotService())->verify(max(1, (int) $input->getOption('chunk')));
        } catch (\Throwable $e) {
            $io->error('Could not walk the chain: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $r['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Standings chain');

        if ($r['unchained'] > 0) {
            $io->warning(sprintf(
                "%d row(s) at the top of the archive predate the hash chain (they were captured before\n"
                . "prev_hash existed) and carry no hash. They are NOT verified and nothing can verify them.\n"
                . "The walk anchors after them.", $r['unchained']));
        }

        if (!$r['ok']) {
            $io->error(sprintf(
                "THE CHAIN IS BROKEN at snapshot row #%d.\n\n"
                . "%d row(s) before it verify exactly. From that row on, the record no longer follows from\n"
                . "itself, so nothing after it can be relied on as evidence of how the standings moved.\n\n"
                . "Before treating this as tampering, rule out the ordinary cause: two captures running at\n"
                . "the same time used to be able to fork the chain. Compare the snapshot_at values around\n"
                . "row #%d — two rows sharing a timestamp and a prev_hash is a fork, not an edit.",
                (int) $r['broken_at'], $r['checked'], (int) $r['broken_at']));
            return Command::FAILURE;
        }

        if ($r['checked'] === 0) {
            $io->note('No chained snapshots yet — nothing to verify. This is normal on a new installation.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d snapshot row(s) verified end to end. Every recorded standing follows from the one before '
            . 'it, so the history of how these standings moved has not been altered, reordered or trimmed.',
            $r['checked']));
        return Command::SUCCESS;
    }
}
