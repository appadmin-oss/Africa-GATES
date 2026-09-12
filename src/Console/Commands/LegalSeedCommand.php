<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\LegalSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Install the Terms of Participation and the Privacy Policy.
 *
 * A thin front end. The documents and the install behaviour live in
 * {@see LegalSeeder}, because this platform deploys to shared cPanel hosting where
 * there is frequently no shell — so the same operation has to be reachable from a
 * browser too, at the token-gated `GET /__setup/legal`. Both call the same service.
 *
 * Documents that already exist are SKIPPED unless `--force`. See LegalSeeder for
 * why that matters, why the prose contains no percentages, and why none of it has
 * been through counsel.
 */
#[AsCommand(
    name: 'legal:seed',
    description: 'Install the Terms of Participation and Privacy Policy into gates_legal_docs.'
)]
final class LegalSeedCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Replace documents that already exist (they are skipped by default).')
            ->addOption('only', null, InputOption::VALUE_REQUIRED,
                'Install just one document: terms|privacy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $only  = strtolower(trim((string) $input->getOption('only')));

        if ($only !== '' && !isset(LegalSeeder::documents()[$only])) {
            $io->error("Unknown document '{$only}'. Use terms or privacy.");
            return Command::FAILURE;
        }

        $io->title('Legal documents');
        $r = LegalSeeder::install($force, $only === '' ? null : $only);

        foreach ($r['kept'] as $slug) {
            $io->writeln("  = <comment>{$slug}</comment> already exists — kept (pass --force to replace it)");
        }
        foreach ($r['written'] as $slug) {
            $body = LegalSeeder::documents()[$slug]['body'];
            $io->writeln('  + <info>' . $slug . '</info> installed — '
                . number_format(strlen($body)) . ' bytes, ' . substr_count($body, '<h2') . ' sections');
        }
        foreach ($r['failed'] as $slug => $msg) {
            $io->error("  ! {$slug} could not be written: {$msg}");
        }

        if ($r['failed'] !== []) return Command::FAILURE;

        $io->newLine();
        $io->writeln(sprintf('%d written, %d kept.', count($r['written']), count($r['kept'])));
        $io->note([
            'Edit these at /admin/legal from now on — this will not touch them again without --force.',
            'The documents state no percentages on purpose: they point at /integrity, which reads the',
            'live weights from RuleEngine. Do not paste figures into them.',
            'NOT legal advice. Have counsel review before relying on this wording.',
        ]);

        return Command::SUCCESS;
    }
}
