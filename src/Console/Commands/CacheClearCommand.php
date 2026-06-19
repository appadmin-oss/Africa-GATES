<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cache:clear', description: 'Wipe DB cache + Twig compiled templates.')]
class CacheClearCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = 0;
        try { $count = DB::table('gates_cache')->count(); DB::table('gates_cache')->truncate(); } catch (\Throwable $e) {}
        $io->writeln("Cleared $count cached entries.");
        $twigCache = dirname(__DIR__, 3) . '/var/cache/twig';
        if (is_dir($twigCache)) {
            $files = glob($twigCache . '/*.php') ?: [];
            foreach ($files as $f) @unlink($f);
            $io->writeln('Cleared ' . count($files) . ' compiled Twig templates.');
        }
        $io->success('Cache cleared.');
        return Command::SUCCESS;
    }
}
