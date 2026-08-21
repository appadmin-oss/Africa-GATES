<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\NomineeBroadcast;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\SiteUrl;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The "final hours" email to nominees in a live voting cycle.
 *
 * ── DRY-RUN UNLESS --commit ──────────────────────────────────────────────────
 * Same contract as `registry:backfill` and `privacy:purge`. Without the flag this reads,
 * resolves and reports, and sends nothing. The report is the deliverable: it tells you
 * how many nominees are reachable before anything leaves the server.
 *
 * ── A NOMINEE'S ADDRESS IS NOT ON THEIR ROW ──────────────────────────────────
 * `gates_nominees` has no email column. It is the ballot row: name, story, photo,
 * category, votes. The address lives on the NOMINATION that produced it, is optional
 * there ("email or phone — at least one"), and there is no foreign key between the two
 * tables — approval copies the name across and sets `profile_id` only when a fuzzy match
 * on email-or-display-name hit exactly one profile. So resolution is best-effort by
 * design, in this order:
 *
 *   1. `gates_profiles.email` via `profile_id` — an actual link, so trusted first.
 *   2. `gates_nominations.nominee_email`, matched on cycle + normalised name.
 *
 * Resolution, rendering and the send log live in {@see NomineeBroadcast}, shared with the
 * token-gated /__setup/broadcast page — because a deployment with no SSH needs the same
 * send, and two copies of a recipient query drift into mailing somebody twice.
 *
 * ── AND AN AMBIGUOUS MATCH IS SKIPPED, NEVER GUESSED ─────────────────────────
 * Two approved nominations with the same name in one cycle give two candidate addresses
 * and no way to choose. Guessing means mailing one person's name and story to a
 * different person's inbox, so those are reported for a human and left alone. Same for
 * nominees with a phone number only: `--export-unreachable` writes them to CSV so they
 * can be reached another way, which is a better answer than pretending they got mail.
 */
#[AsCommand(
    name: 'nominees:broadcast',
    description: 'Email nominees in a live voting cycle the "final hours" message. Dry-run unless --commit.'
)]
final class NomineeBroadcastCommand extends Command
{
    public function __construct(private readonly OtpService $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('commit', null, InputOption::VALUE_NONE,
            'Actually send. Without this flag nothing leaves the server.');
        $this->addOption('cycle', null, InputOption::VALUE_REQUIRED,
            'Only this cycle id. Default: every cycle currently in voting.', '0');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED,
            'Stop after N recipients. Use for a small first batch.', '0');
        $this->addOption('only', null, InputOption::VALUE_REQUIRED,
            'Send to this address only, if it is in the resolved list. For a live test of one.', '');
        $this->addOption('export-unreachable', null, InputOption::VALUE_REQUIRED,
            'Write nominees with no usable address to this CSV path.', '');
        $this->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED,
            'Pause between sends, in milliseconds. Protects the SMTP rate limit.', '250');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io      = new SymfonyStyle($in, $out);
        $commit  = (bool) $in->getOption('commit');
        $cycle   = max(0, (int) $in->getOption('cycle'));
        $limit   = max(0, (int) $in->getOption('limit'));
        $only    = (string) $in->getOption('only');
        $csvPath = trim((string) $in->getOption('export-unreachable'));
        $sleepUs = max(0, (int) $in->getOption('sleep-ms')) * 1000;

        $io->title('Final-hours broadcast ' . ($commit ? '(COMMIT — sending)' : '(dry-run — nothing will be sent)'));

        $site = SiteUrl::base();
        if ($site === '') {
            // Every link and the countdown are absolute. Without APP_URL they would be
            // relative, which in an email means broken.
            $io->error('APP_URL is not set. Every link in this email is absolute — set it before sending.');
            return Command::FAILURE;
        }

        $svc  = new NomineeBroadcast();
        $plan = $svc->plan($cycle, $only);

        if ($plan['cycles'] === []) {
            $io->warning('No cycle is in voting with a close date set. Nothing to send.');
            return Command::SUCCESS;
        }
        foreach ($plan['cycles'] as $c) {
            $io->writeln(sprintf('  cycle <info>%d</info> — %s · closes %s',
                $c->id, $c->programme_title ?? ('programme ' . $c->programme_id),
                Carbon::parse((string) $c->voting_close)->format('D j M Y, H:i T')));
        }

        $n = $plan['counts'];
        $io->section('Resolved');
        $io->definitionList(
            ['nominees in these cycles'             => (string) $n['nominees']],
            ['unique addresses'                     => (string) $n['addresses']],
            ['already unsubscribed'                 => (string) $n['unsubscribed']],
            ['already sent this campaign'           => (string) $n['already']],
            ['ambiguous (same name twice, skipped)' => (string) $n['ambiguous']],
            ['no address at all'                    => (string) $n['unreachable']],
            ['WILL SEND'                            => '<comment>' . min($n['sendable'], $limit > 0 ? $limit : $n['sendable']) . '</comment>'],
        );

        if ($plan['ambiguous'] !== []) {
            $io->section('Ambiguous — two approved nominations share a name in one cycle. Not mailed.');
            foreach (array_slice($plan['ambiguous'], 0, 20) as [$nom, $addrs]) {
                $io->writeln(sprintf('  nominee %d "%s" → %s', $nom->id, $nom->name, implode(', ', $addrs)));
            }
            if (count($plan['ambiguous']) > 20) {
                $io->writeln('  … and ' . (count($plan['ambiguous']) - 20) . ' more');
            }
        }

        if ($csvPath !== '' && $plan['unreachable'] !== []) {
            $this->writeCsv($csvPath, $plan['unreachable']);
            $io->writeln(sprintf('  wrote %d unreachable nominees to <info>%s</info>', count($plan['unreachable']), $csvPath));
        } elseif ($plan['unreachable'] !== []) {
            $io->writeln(sprintf('  <comment>%d nominees have no email on file.</comment> Re-run with '
                . '--export-unreachable=path.csv to get the list (many have a phone number).', count($plan['unreachable'])));
        }

        if (!$commit) {
            $io->success('Dry run complete. Nothing was sent. Add --commit to send.');
            return Command::SUCCESS;
        }

        $sendable = $plan['sendable'];
        if ($limit > 0) $sendable = array_slice($sendable, 0, $limit);
        if ($sendable === []) {
            $io->warning('Nothing to send.');
            return Command::SUCCESS;
        }

        $sent = $failed = 0;
        $io->section('Sending');
        $io->progressStart(count($sendable));
        foreach ($sendable as $r) {
            $svc->sendOne($r, $site, $this->mailer)['ok'] ? $sent++ : $failed++;
            $io->progressAdvance();
            if ($sleepUs > 0) usleep($sleepUs);
        }
        $io->progressFinish();

        $io->success(sprintf('%d sent, %d failed. Failures are in gates_broadcast_log — '
            . 're-running retries only those.', $sent, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<array{0:object,1:object}> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $fh = @\fopen($path, 'w');
        if ($fh === false) return;
        \fputcsv($fh, ['nominee_id', 'name', 'category', 'cycle_id', 'why']);
        foreach ($rows as [$n, $cycle]) {
            \fputcsv($fh, [$n->id, $n->name, $n->category_title ?? '', $cycle->id,
                           'no email on file (check gates_nominations.nominee_phone)']);
        }
        \fclose($fh);
    }
}
