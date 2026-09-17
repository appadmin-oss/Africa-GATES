<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Support\AssetBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `assets:build` — collapse the fifteen global stylesheets into one minified bundle.
 *
 * Run it after any CSS edit and as the last step of a deploy. Until it runs (or after a
 * CSS file changes and before it runs again) the layout serves the individual files, so
 * forgetting it costs page speed and never correctness — see {@see AssetBundle::url()}.
 *
 * `--check` is the CI/deploy-verification mode: it reports whether the built bundle is
 * current and exits non-zero if it is not, without writing anything.
 */
#[AsCommand(name: 'assets:build', description: 'Bundle + minify the global CSS into one hashed file.')]
final class AssetsBuildCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE,
            'Report whether the bundle is current; write nothing. Non-zero exit when stale.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('check')) {
            $url = AssetBundle::url();
            if ($url === null) {
                $io->warning('No current CSS bundle. The site is serving '
                    . count(AssetBundle::STYLESHEETS) . ' separate stylesheets — run `assets:build`.');
                return Command::FAILURE;
            }
            $io->success('Bundle is current: ' . $url);
            return Command::SUCCESS;
        }

        $r = AssetBundle::build();

        if (!$r['ok']) {
            $io->error((string) $r['error']);
            return Command::FAILURE;
        }

        // A missing source is a warning, not a failure: the bundle is still valid and
        // still an improvement, and failing the deploy over a stylesheet somebody
        // deliberately deleted would be worse than saying so.
        foreach ($r['missing'] as $missing) {
            $io->warning('Listed in AssetBundle::STYLESHEETS but not on disk: ' . $missing);
        }

        $io->success(sprintf(
            '%s — %d files, %s → %s (%d%% smaller)',
            (string) $r['file'],
            (int) $r['sources'],
            self::kb((int) $r['raw']),
            self::kb((int) $r['min']),
            (int) $r['saved_pct']
        ));
        $io->writeln(sprintf(
            '  <info>%d</info> render-blocking stylesheet requests become <info>1</info>.',
            (int) $r['sources']
        ));
        $io->writeln('  ' . AssetBundle::notes());

        return Command::SUCCESS;
    }

    private static function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 1) . ' KiB';
    }
}
