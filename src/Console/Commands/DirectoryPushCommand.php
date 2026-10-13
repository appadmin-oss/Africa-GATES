<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\DirectoryPush;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Push this platform's account list to CACENTRE's person directory.
 *
 * The hourly maintenance tick sends one page and takes as many ticks as the
 * member roster needs, which is right for keeping up and wrong for starting.
 * This walks the whole thing in one go — the first sweep, or after a long
 * outage.
 *
 *     bin/console directory:push --dry
 *     bin/console directory:push
 *
 * Safe to run twice and safe to interrupt: every row upserts on its source
 * key over there.
 */
#[AsCommand(name: 'directory:push', description: 'Push the account list to CACENTRE\'s person directory.')]
class DirectoryPushCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry', null, InputOption::VALUE_NONE, 'Show what would be sent, send nothing.');
        $this->addOption('max', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry');
        $max = (int) ($input->getOption('max') ?? 0);

        if (!$dry && !DirectoryPush::ready()) {
            $io->error('No push secret is set. Put a 32+ character value in Settings as '
                . '`dir_push_secret` (or DIR_PUSH_SECRET_GATES in .env), and the SAME value '
                . 'in CACENTRE as DIR_PUSH_SECRET_GATES.');

            return Command::FAILURE;
        }

        $io->writeln(($dry ? 'Would push' : 'Pushing') . ' to ' . DirectoryPush::endpoint());

        $sent = $written = $refused = $batches = $skipped = 0;

        /* The staff tables first. They are small, so if the key or the URL
           is wrong this is where it shows — after three rows rather than
           three thousand. */
        foreach (DirectoryPush::staffBatches() as $chunk) {
            $batches++;
            $sent += count($chunk);
            if ($dry) {
                $io->writeln('  staff      ' . count($chunk) . ' account(s)');
                continue;
            }
            $res = DirectoryPush::send($chunk);
            $written += (int) ($res['written'] ?? 0);
            $refused += (int) ($res['refused'] ?? 0);
            $io->writeln('  staff      ' . self::report($res));
            if (empty($res['ok'])) {
                return self::stop($io);
            }
        }

        $cursor = 0;
        while (true) {
            $b = DirectoryPush::memberBatch($cursor);
            $skipped += $b['skipped'];
            if ($b['rows'] === [] && $b['wrapped']) {
                break;
            }

            if ($b['rows'] !== []) {
                $batches++;
                $sent += count($b['rows']);
                if ($dry) {
                    $io->writeln('  members    ' . count($b['rows']) . ' account(s)');
                } else {
                    $res = DirectoryPush::send($b['rows']);
                    $written += (int) ($res['written'] ?? 0);
                    $refused += (int) ($res['refused'] ?? 0);
                    $io->writeln('  members    ' . self::report($res));
                    if (empty($res['ok'])) {
                        return self::stop($io);
                    }
                }
            }

            $cursor = $b['cursor'];
            if ($b['wrapped']) {
                break;
            }
            if ($max > 0 && $batches >= $max) {
                $io->writeln('  stopped at --max');
                break;
            }
        }

        if ($skipped > 0) {
            /* Said out loud rather than silently dropped: an operator who
               has just seeded the sandbox should see why the count is short,
               and not go looking for a bug in the sender. */
            $io->writeln(sprintf('  %d sandbox account(s) at a reserved domain were not sent.', $skipped));
        }

        $io->success(sprintf('%s %d account(s) in %d batch(es); %d written, %d refused.',
            $dry ? 'Would send' : 'Sent', $sent, $batches, $written, $refused));

        return Command::SUCCESS;
    }

    private static function report(array $r): string
    {
        if (!empty($r['ok'])) {
            return (int) ($r['written'] ?? 0) . ' written';
        }

        /* CACENTRE names which rows it refused and why. Printing them is the
           difference between fixing a shaper in five minutes and guessing. */
        $out = 'REFUSED: ' . (string) ($r['error'] ?? $r['why'] ?? 'unknown');
        foreach (array_slice((array) ($r['details'] ?? []), 0, 5) as $d) {
            $out .= "\n               " . (string) ($d['local_id'] ?? '?') . ' — ' . (string) ($d['why'] ?? '?');
        }

        return $out;
    }

    private static function stop(SymfonyStyle $io): int
    {
        $io->error('Stopped on a refusal. Nothing already sent is undone — every row upserts, '
            . 'so fix the cause and run this again.');

        return Command::FAILURE;
    }
}
