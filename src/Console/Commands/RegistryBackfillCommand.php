<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\PartnerOrg;
use AfricaGates\Services\RegistryCheck;
use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bring CAC numbers written before normalisation into one spelling, and say what that
 * reveals.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * {@see PartnerOrg::checkCacInput()} normalises on write, so everything registered since
 * then is stored as `RC/1234567`. Rows written before it hold whatever was typed — `rc
 * 1234567`, `RC-1234567`, `12345`, a phone number. Two consequences, and the second is the
 * one that matters:
 *
 *   1. The vetting screen shows a number in whatever shape it arrived in.
 *   2. {@see PartnerOrg::cacOnFileElsewhere()} compares stored strings, so a legacy row and
 *      a new row carrying THE SAME REGISTRATION do not collide. The duplicate check — the
 *      cheapest fraud signal the platform has — is blind to exactly the pairs most worth
 *      catching, because one half of the pair predates the rule.
 *
 * ── SAFE BY DEFAULT ─────────────────────────────────────────────────────────
 *
 *   · **Dry-run unless `--commit`.** Without it nothing is written and the report is the
 *     whole output — same contract as `privacy:purge`.
 *   · **Malformed numbers are reported and never touched.** A legacy row may hold a shape
 *     this platform has not seen, and the admin write path is deliberately lenient for that
 *     reason. Rewriting one to a guess would destroy the only evidence of what was
 *     originally entered, on the record used to decide whether an organisation is real.
 *   · **Collisions are reported BEFORE they are created.** Normalising can make two rows
 *     that looked different suddenly identical. That is the point of the exercise rather
 *     than a hazard — but it is a finding a person has to see, so it is called out
 *     separately and loudly, and the rewrite still happens because the alternative is
 *     leaving the collision hidden.
 *
 * ── AND WHY THE REGISTRY CHECK IS OPT-IN ────────────────────────────────────
 *
 * `--queue-checks` pushes one {@see PartnerOrg::JOB_REGISTRY} job per organisation, which
 * re-derives the note — kind, duplicate, and whatever a verifier says. Separate flag,
 * because a configured verifier is a paid third party and queueing a job per row on a large
 * table is a cost decision somebody should make on purpose. Deduped, so running the command
 * twice does not queue twice.
 *
 * Typical use, once, after deploying the normalisation:
 *
 *   php bin/console registry:backfill                      # look
 *   php bin/console registry:backfill --commit             # normalise
 *   php bin/console registry:backfill --commit --queue-checks
 */
#[AsCommand(
    name: 'registry:backfill',
    description: 'Normalise CAC numbers stored before the write-time rule, and report duplicates it reveals. Dry-run unless --commit.'
)]
final class RegistryBackfillCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('commit', null, InputOption::VALUE_NONE,
            'Actually write. Without this flag nothing is changed.');
        $this->addOption('queue-checks', null, InputOption::VALUE_NONE,
            'Also queue a background registry check per organisation. Deduped.');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED,
            'Only look at the first N organisations, oldest first.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $commit  = (bool) $input->getOption('commit');
        $queue   = (bool) $input->getOption('queue-checks');
        $limit   = max(0, (int) $input->getOption('limit'));

        $io->title('CAC number backfill ' . ($commit ? '(COMMIT — writing)' : '(dry-run — nothing will be written)'));

        try {
            $q = DB::table('gates_partner_orgs')
                ->whereNotNull('cac_number')->where('cac_number', '!=', '')
                ->orderBy('id')
                ->select('id', 'name', 'cac_number', 'kind', 'entity_type');
            if ($limit > 0) $q->limit($limit);
            $rows = $q->get()->all();
        } catch (\Throwable $e) {
            $io->error('Could not read gates_partner_orgs: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($rows === []) {
            $io->success('No organisation has a CAC number on file. Nothing to do.');
            return Command::SUCCESS;
        }

        // ── PASS ONE · decide, and build the map that finds collisions ───────
        //
        // Nothing is written in this pass. The collision report has to be complete before
        // the first UPDATE, because a rewrite performed halfway through would change what
        // the rest of the pass is comparing against.
        $rewrite   = [];   // id => [name, from, to]
        $malformed = [];   // id => [name, value, why]
        $already   = 0;
        /** @var array<string, list<array{id:int,name:string}>> $byNumber */
        $byNumber  = [];

        foreach ($rows as $r) {
            $id      = (int) $r->id;
            $name    = (string) ($r->name ?? '');
            $current = trim((string) $r->cac_number);

            $f = RegistryCheck::cacFormat($current);
            if (!$f['ok']) {
                // Left exactly as it is. See the class note: a guess written over the only
                // record of what was entered is worse than an odd-looking number.
                $malformed[$id] = [$name, $current, $f['message']];
                $byNumber[strtoupper($current)][] = ['id' => $id, 'name' => $name];
                continue;
            }

            $target = $f['normalised'];
            $byNumber[$target][] = ['id' => $id, 'name' => $name];

            if ($target === $current) { $already++; continue; }
            $rewrite[$id] = [$name, $current, $target];
        }

        // Every number that ends up on more than one organisation once this has run.
        $collisions = array_filter($byNumber, static fn(array $orgs): bool => count($orgs) > 1);

        // ── THE REPORT ───────────────────────────────────────────────────────
        $io->section('What is on file');
        $io->writeln(sprintf('  %d organisation(s) with a CAC number', count($rows)));
        $io->writeln(sprintf('  %d already in the stored form', $already));
        $io->writeln(sprintf('  <info>%d to normalise</info>', count($rewrite)));
        $io->writeln(sprintf('  %d not a recognised shape (left untouched)', count($malformed)));

        if ($rewrite !== []) {
            $io->section('To normalise');
            $io->table(['#', 'Organisation', 'On file', 'Becomes'],
                array_map(
                    static fn(int $id, array $r): array => [$id, $r[0], $r[1], $r[2]],
                    array_keys($rewrite), $rewrite
                ));
        }

        if ($malformed !== []) {
            $io->section('Not a recognised shape — nothing written, look at these by hand');
            $io->table(['#', 'Organisation', 'On file', 'Why'],
                array_map(
                    static fn(int $id, array $r): array => [$id, $r[0], $r[1], $r[2]],
                    array_keys($malformed), $malformed
                ));
        }

        // The finding the whole command exists to surface. Loud, and separate, because it
        // is the one line here that someone has to act on rather than merely read.
        if ($collisions !== []) {
            $io->section('SAME NUMBER, MORE THAN ONE ORGANISATION');
            foreach ($collisions as $number => $orgs) {
                $io->writeln(sprintf('  <comment>%s</comment> — %s', $number, implode(', ',
                    array_map(static fn(array $o): string => $o['name'] . ' (#' . $o['id'] . ')', $orgs))));
            }
            $io->writeln('');
            $io->writeln('  One of each pair is wrong, and which one is a question for a person.');
            $io->writeln('  Nothing is blocked by this — it is recorded so somebody decides it.');
        }

        // ── PASS TWO · write ─────────────────────────────────────────────────
        if (!$commit) {
            $io->success(sprintf('[dry-run] would rewrite %d number(s)%s. Re-run with --commit.',
                count($rewrite), $queue ? ' and queue ' . count($rows) . ' check(s)' : ''));
            return Command::SUCCESS;
        }

        $written = 0;
        $failed  = 0;
        foreach ($rewrite as $id => [$name, $from, $to]) {
            try {
                DB::table('gates_partner_orgs')->where('id', $id)->update(['cac_number' => $to]);
                $written++;
            } catch (\Throwable $e) {
                // One bad row must not abort the pass. A backfill that stops halfway through
                // is worse than one that finishes and reports what it could not do.
                $failed++;
                $io->writeln(sprintf('  <error>#%d %s: %s</error>', $id, $name, $e->getMessage()));
            }
        }

        $queued = 0;
        if ($queue) {
            // Every organisation with a number, not only the rewritten ones: the check also
            // derives the duplicate note and the registration kind, and a row that was
            // already in the right shape has never had either written.
            foreach ($rows as $r) {
                PartnerOrg::queueRegistryCheck((int) $r->id);
                $queued++;
            }
        }

        $io->success(sprintf(
            'Rewrote %d number(s)%s%s.',
            $written,
            $failed > 0 ? sprintf(', %d failed', $failed) : '',
            $queued > 0 ? sprintf(', queued %d registry check(s)', $queued) : ''
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
