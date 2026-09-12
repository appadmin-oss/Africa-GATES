<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\DemoSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Build or remove the rehearsal sandbox.
 *
 * A thin front end; the behaviour and every containment decision live in
 * {@see DemoSeeder}. Same split as `legal:seed`, and for the same reason: the operation
 * also has to be reachable from a browser at `/admin/sandbox`, because this platform
 * deploys to shared cPanel hosting where there is frequently no shell.
 */
#[AsCommand(
    name: 'demo:seed',
    description: 'Build a hidden rehearsal programme with a nominee exercisable in every screen.'
)]
final class DemoSeedCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('remove', null, InputOption::VALUE_NONE,
            'Remove the sandbox and everything under it instead of building one.');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);

        if ($in->getOption('remove')) {
            $r = DemoSeeder::purge();
            $io->success((string) $r['message']);
            return Command::SUCCESS;
        }

        if (DemoSeeder::exists()) {
            $io->warning('A sandbox already exists. Rebuilding removes it first, including any '
                       . 'scores added to it.');
        }

        $r = DemoSeeder::seed();
        if (!($r['ok'] ?? false)) {
            $io->error((string) ($r['message'] ?? 'The sandbox could not be built.'));
            return Command::FAILURE;
        }

        $io->success((string) $r['message']);
        $io->section('Where to click');
        foreach ((array) ($r['links'] ?? []) as $l) {
            $io->writeln('  ' . str_pad((string) $l['label'], 34) . (string) $l['href']);
        }
        $io->newLine();
        $io->writeln('  Judge portal sign-in: judge@demo.invalid');
        $io->writeln('  Remove it again with: bin/console demo:seed --remove');

        return Command::SUCCESS;
    }
}
