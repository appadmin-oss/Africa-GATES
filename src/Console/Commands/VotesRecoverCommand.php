<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\VoteRecoveryService as Recover;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairing votes the platform dropped — and watching how often it drops them.
 *
 * ── WHAT THIS CANNOT DO ──────────────────────────────────────────────────────
 *
 * It cannot name a person. There is no option here that takes an address, a
 * nominee and a count, and there is not going to be one: the population comes
 * entirely from tokens whose delivery WE recorded as failed. An operator picks a
 * window and describes the incident. Two operators running the same window against
 * the same data get the same list.
 *
 * It also cannot approve. `approve` happens in the admin panel where the approver
 * is an authenticated person, because identity on a shell is not evidence — anyone
 * holding the terminal can pass any --admin they like, so a two-person rule
 * enforced here would be enforced against nobody.
 *
 * ── health IS THE POINT ──────────────────────────────────────────────────────
 *
 * `votes:recover health` reports what fraction of vote codes we failed to deliver.
 * Recovery patches a result; it does nothing about the cause, and a platform that
 * needs this regularly has a mail problem rather than a recovery problem. The
 * number is meant to be looked at and to be falling.
 */
#[AsCommand(name: 'votes:recover', description: 'Repair votes the platform failed to deliver a code for.')]
final class VotesRecoverCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED,
                           'health | candidates | open | screen | submit | apply | void | list | audit')
             ->addOption('cycle', null, InputOption::VALUE_REQUIRED, 'Cycle id.')
             ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the outage window.')
             ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End of the outage window.')
             ->addOption('incident', null, InputOption::VALUE_REQUIRED, 'What went wrong. The approver reads this.')
             ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch reference (AGR-…).')
             ->addOption('admin', null, InputOption::VALUE_REQUIRED, 'Your admin id — the PREPARER, never the approver.', '0')
             ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason (void).')
             ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Lookback for health.', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return match ((string) $input->getArgument('action')) {
                'health'     => $this->health($io, $input),
                'candidates' => $this->candidates($io, $input),
                'open'       => $this->open($io, $input),
                'screen'     => $this->screen($io, $input),
                'submit'     => $this->submit($io, $input),
                'apply'      => $this->apply($io, $input),
                'void'       => $this->void($io, $input),
                'list'       => $this->list($io, $input),
                'audit'      => $this->audit($io, $input),
                default      => $this->unknown($io, (string) $input->getArgument('action')),
            };
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function unknown(SymfonyStyle $io, string $a): int
    {
        $io->error("Unknown action '$a'. Try: health, candidates, open, screen, submit, apply, void, list, audit.");
        return Command::FAILURE;
    }

    /** The number that should be falling. Non-zero exit when it is bad. */
    private function health(SymfonyStyle $io, InputInterface $in): int
    {
        $days = max(1, (int) $in->getOption('days'));
        $h = Recover::deliveryHealth($days);

        $io->title("Vote-code delivery, last {$days} day(s)");
        $io->definitionList(
            ['Delivered' => (string) $h['sent']],
            ['Failed'    => (string) $h['failed']],
            ['Unrecorded'=> $h['unknown'] . ' (issued before delivery was logged)'],
            ['Failure rate' => $h['pct'] . '%'],
        );

        if ($h['sent'] + $h['failed'] === 0) {
            $io->note('No vote codes with a recorded outcome in this window.');
            return Command::SUCCESS;
        }
        if ($h['pct'] >= 5.0) {
            $io->error(sprintf(
                "%s%% of vote codes did not reach the person who asked for one.\n\n"
                . "Every one of those is somebody who tried to vote and could not. Recovery can repair the\n"
                . "result afterwards, under approval and in public — but it does nothing about the cause, and\n"
                . "a rate this high means the mail path needs fixing before the next ballot, not after it.\n"
                . 'Start with `bin/console doctor` and the SMTP settings.', $h['pct']));
            return Command::FAILURE;
        }
        $io->success($h['pct'] . '% of vote codes failed to deliver. Low, and worth keeping there.');
        return Command::SUCCESS;
    }

    private function candidates(SymfonyStyle $io, InputInterface $in): int
    {
        $cycle = (int) $in->getOption('cycle');
        [$from, $to] = [(string) $in->getOption('from'), (string) $in->getOption('to')];
        if ($cycle < 1 || $from === '' || $to === '') {
            $io->error('Need --cycle, --from and --to.');
            return Command::FAILURE;
        }

        $c = Recover::candidates($cycle, date('Y-m-d H:i:s', (int) strtotime($from)), date('Y-m-d H:i:s', (int) strtotime($to)));
        if (!$c) {
            $io->note('No undelivered vote codes in that window. Either the sends succeeded, or they predate '
                    . 'the delivery log — in which case there is no evidence we failed anybody.');
            return Command::SUCCESS;
        }

        $byNominee = [];
        foreach ($c as $tok) $byNominee[(int) $tok->nominee_id] = ($byNominee[(int) $tok->nominee_id] ?? 0) + 1;

        $io->title(count($c) . ' dropped vote attempt(s)');
        $io->table(['Nominee', 'Name', 'Dropped'], array_map(
            static fn ($id, $n) => [$id, (string) DB::table('gates_nominees')->where('id', $id)->value('name'), $n],
            array_keys($byNominee), $byNominee));

        if (Recover::resendable($cycle)) {
            $io->warning('Voting is still open on this cycle. Do not recover these — fix the mail path and let '
                       . 'these people request a working code, so they cast their own votes. That is better for '
                       . 'them and for the result than anybody casting it on their behalf.');
        }
        return Command::SUCCESS;
    }

    private function open(SymfonyStyle $io, InputInterface $in): int
    {
        $r = Recover::open(
            (int) $in->getOption('cycle'),
            (string) $in->getOption('from'),
            (string) $in->getOption('to'),
            (string) $in->getOption('incident'),
            (int) $in->getOption('admin'));

        if (!$r['ok']) { $io->error($r['message']); return Command::FAILURE; }

        $io->success($r['reference'] . ' opened with ' . $r['candidates'] . ' dropped attempt(s).');
        $io->text('Next: votes:recover screen --batch=' . $r['reference']);
        return Command::SUCCESS;
    }

    private function screen(SymfonyStyle $io, InputInterface $in): int
    {
        $b = $this->resolve($io, $in);
        if (!$b) return Command::FAILURE;

        $s = Recover::screen((int) $b->id);
        $io->title($b->reference);
        $io->definitionList(
            ['Window'   => (string) $b->window_from . ' → ' . (string) $b->window_to],
            ['Incident' => (string) $b->incident_note],
            ['Dropped'  => $s['stats']['rows'] . ' attempt(s) across ' . $s['stats']['nominees'] . ' nominee(s)'],
        );

        if (!$s['findings']) {
            $io->success('Nothing flagged. That is not the same as verified — satisfy yourself the incident is real.');
            return Command::SUCCESS;
        }
        foreach ($s['findings'] as $f) {
            $f['level'] === 'block' ? $io->error($f['text']) : $io->warning($f['text']);
        }
        return ($s['stats']['blocking'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function submit(SymfonyStyle $io, InputInterface $in): int
    {
        $b = $this->resolve($io, $in);
        if (!$b) return Command::FAILURE;

        $r = Recover::submit((int) $b->id, (int) $in->getOption('admin'));
        if (!$r['ok']) { $io->error($r['message']); return Command::FAILURE; }

        $io->success($b->reference . ' submitted for review.');
        $io->note('It now needs approval from a DIFFERENT signed-in admin. This command cannot give it.');
        return Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, InputInterface $in): int
    {
        $b = $this->resolve($io, $in);
        if (!$b) return Command::FAILURE;

        if ((string) $b->status !== 'approved' || (int) $b->approved_by < 1) {
            $io->error('This batch has not been approved by anybody. Approval happens in the admin panel, where '
                     . 'the approver is an authenticated person rather than a number typed on a command line.');
            return Command::FAILURE;
        }

        $r = Recover::apply((int) $b->id, (int) $in->getOption('admin'));
        if (!$r['ok']) { $io->error($r['message']); return Command::FAILURE; }

        $io->success(sprintf('%s applied: %d vote(s) restored, %d rejected.', $b->reference, $r['applied'], $r['rejected']));
        $io->text('These now count exactly like any other organic vote, and are disclosed on each nominee\'s page.');
        return Command::SUCCESS;
    }

    private function void(SymfonyStyle $io, InputInterface $in): int
    {
        $b = $this->resolve($io, $in);
        if (!$b) return Command::FAILURE;

        $r = Recover::void((int) $b->id, (int) $in->getOption('admin'), (string) $in->getOption('reason'));
        if (!$r['ok']) { $io->error($r['message']); return Command::FAILURE; }

        $io->success($b->reference . ' voided — ' . $r['reversed'] . ' vote(s) reversed. The record stays.');
        return Command::SUCCESS;
    }

    private function list(SymfonyStyle $io, InputInterface $in): int
    {
        $q = DB::table('gates_vote_recovery_batches')->orderByDesc('id');
        if ($in->getOption('cycle')) $q->where('cycle_id', (int) $in->getOption('cycle'));

        $rows = $q->limit(100)->get()->map(static fn ($b) => [
            (string) $b->reference, (string) $b->status,
            (string) $b->window_from . ' → ' . (string) $b->window_to,
            (int) $b->candidate_count, (int) $b->applied_count,
            $b->approved_by ? '#' . $b->approved_by : '—',
        ])->all();

        if (!$rows) { $io->note('No recovery batches.'); return Command::SUCCESS; }
        $io->table(['Reference', 'Status', 'Window', 'Candidates', 'Applied', 'Approved by'], $rows);
        return Command::SUCCESS;
    }

    /**
     * Does every recovered vote trace back to an approved batch and a failed send?
     *
     * The check the two-person rule cannot do: catching a row written straight into
     * the database. Any vote carrying a recovery_batch_id must belong to a batch
     * that is actually applied, and must point at a token. Anything else went around
     * this service.
     */
    private function audit(SymfonyStyle $io, InputInterface $in): int
    {
        $bad = [];
        foreach (DB::table('gates_votes')->whereNotNull('recovery_batch_id')->get() as $v) {
            $b = Recover::batch((int) $v->recovery_batch_id);
            if (!$b)                                  { $bad[] = [(int) $v->id, 'batch does not exist']; continue; }
            if ((string) $b->status !== 'applied')    { $bad[] = [(int) $v->id, 'batch status is ' . $b->status]; continue; }
            if ((int) ($v->otp_token_id ?? 0) < 1)    { $bad[] = [(int) $v->id, 'no attempt record behind it']; continue; }
            if (!DB::table('gates_vote_recovery_rows')->where('vote_id', (int) $v->id)->where('status', 'applied')->exists()) {
                $bad[] = [(int) $v->id, 'no roster row claims it'];
            }
        }

        if (!$bad) {
            $io->success('Every recovered vote traces to an applied batch and the attempt that justified it.');
            return Command::SUCCESS;
        }
        $io->error(count($bad) . ' recovered vote(s) cannot be accounted for.');
        $io->table(['Vote', 'Problem'], $bad);
        $io->text('recovery_batch_id is written by VoteRecoveryService and nothing else, so a mismatch means a '
                . 'direct database write. Resolve it before the cycle reaches results.');
        return Command::FAILURE;
    }

    private function resolve(SymfonyStyle $io, InputInterface $in): ?object
    {
        $ref = trim((string) $in->getOption('batch'));
        if ($ref === '') { $io->error('Which batch? Pass --batch=AGR-…'); return null; }

        $b = Recover::byReference($ref);
        if (!$b) { $io->error("No batch with reference $ref (the last character is a checksum)."); return null; }
        return $b;
    }
}
