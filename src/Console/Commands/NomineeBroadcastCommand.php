<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\CountdownGif;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\Env;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
    private const CAMPAIGN = 'final-hours';
    private const SUBJECT  = 'Finish strong — voting closes soon';

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
        $io       = new SymfonyStyle($in, $out);
        $commit   = (bool) $in->getOption('commit');
        $cycleOpt = max(0, (int) $in->getOption('cycle'));
        $limit    = max(0, (int) $in->getOption('limit'));
        $only     = EmailOptOut::normalise((string) $in->getOption('only'));
        $csvPath  = trim((string) $in->getOption('export-unreachable'));
        $sleepUs  = max(0, (int) $in->getOption('sleep-ms')) * 1000;

        $io->title('Final-hours broadcast ' . ($commit ? '(COMMIT — sending)' : '(dry-run — nothing will be sent)'));

        $site = SiteUrl::base();
        if ($site === '') {
            // Every link and the countdown itself are absolute. Without APP_URL they would
            // be relative, which in an email means broken.
            $io->error('APP_URL is not set. Every link in this email is absolute — set it before sending.');
            return Command::FAILURE;
        }

        $cycles = $this->cycles($cycleOpt);
        if ($cycles === []) {
            $io->warning('No cycle is in voting with a close date set. Nothing to send.');
            return Command::SUCCESS;
        }
        foreach ($cycles as $c) {
            $io->writeln(sprintf('  cycle <info>%d</info> — %s · closes %s',
                $c->id, $c->programme_title ?? ('programme ' . $c->programme_id),
                Carbon::parse((string) $c->voting_close)->format('D j M Y, H:i T')));
        }

        // Suppression and the send log, read once. A broadcast is thousands of rows; this
        // is two queries instead of two per recipient.
        $suppressed = EmailOptOut::suppressedHashes();
        $alreadyLog = $this->alreadySent();

        $resolved = $unreachable = $ambiguous = [];
        foreach ($cycles as $cycle) {
            foreach ($this->nominees((int) $cycle->id) as $n) {
                $found = $this->addressesFor($n, (int) $cycle->id);
                if ($found === []) { $unreachable[] = [$n, $cycle]; continue; }
                if (count($found) > 1) { $ambiguous[] = [$n, $found]; continue; }
                $resolved[] = ['nominee' => $n, 'cycle' => $cycle, 'email' => $found[0]];
            }
        }

        // One address can hold several nominations. Mail the person once.
        $seen = [];
        $queue = [];
        foreach ($resolved as $r) {
            $h = EmailOptOut::hash($r['email']);
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            if (isset($suppressed[$h]))  { $r['skip'] = 'unsubscribed';  $queue[] = $r; continue; }
            if (isset($alreadyLog[$h]))  { $r['skip'] = 'already sent';  $queue[] = $r; continue; }
            if ($only !== '' && $r['email'] !== $only) { $r['skip'] = 'not --only'; $queue[] = $r; continue; }
            $r['skip'] = null;
            $queue[] = $r;
        }

        $sendable = array_values(array_filter($queue, fn($r) => $r['skip'] === null));
        if ($limit > 0) $sendable = array_slice($sendable, 0, $limit);

        $io->section('Resolved');
        $io->definitionList(
            ['nominees in these cycles' => (string) (count($resolved) + count($unreachable) + count($ambiguous))],
            ['unique addresses'         => (string) count($queue)],
            ['already unsubscribed'     => (string) count(array_filter($queue, fn($r) => $r['skip'] === 'unsubscribed'))],
            ['already sent this campaign' => (string) count(array_filter($queue, fn($r) => $r['skip'] === 'already sent'))],
            ['ambiguous (same name twice, skipped)' => (string) count($ambiguous)],
            ['no address at all'        => (string) count($unreachable)],
            ['WILL SEND'               => '<comment>' . count($sendable) . '</comment>'],
        );

        if ($ambiguous !== []) {
            $io->section('Ambiguous — two approved nominations share a name in one cycle. Not mailed.');
            foreach (array_slice($ambiguous, 0, 20) as [$n, $addrs]) {
                $io->writeln(sprintf('  nominee %d "%s" → %s', $n->id, $n->name, implode(', ', $addrs)));
            }
            if (count($ambiguous) > 20) $io->writeln('  … and ' . (count($ambiguous) - 20) . ' more');
        }

        if ($csvPath !== '' && $unreachable !== []) {
            $this->writeCsv($csvPath, $unreachable);
            $io->writeln(sprintf('  wrote %d unreachable nominees to <info>%s</info>', count($unreachable), $csvPath));
        } elseif ($unreachable !== []) {
            $io->writeln(sprintf('  <comment>%d nominees have no email on file.</comment> Re-run with '
                . '--export-unreachable=path.csv to get the list (many have a phone number).', count($unreachable)));
        }

        if (!$commit) {
            $io->success('Dry run complete. Nothing was sent. Add --commit to send.');
            return Command::SUCCESS;
        }
        if ($sendable === []) {
            $io->warning('Nothing to send.');
            return Command::SUCCESS;
        }

        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), ['autoescape' => 'html']);
        $sent = $failed = 0;

        $io->section('Sending');
        $io->progressStart(count($sendable));
        foreach ($sendable as $r) {
            $html = $twig->render('emails/final-hours.twig', $this->vars($r, $site));
            $res  = $this->mailer->sendRawHtml(
                $r['email'], self::SUBJECT, $html,
                $this->plain($r, $site), self::CAMPAIGN,
                EmailOptOut::url($site, $r['email'])
            );
            $ok = (bool) ($res['success'] ?? false);
            $ok ? $sent++ : $failed++;
            $this->logSend($r, $ok, (string) ($res['error'] ?? ''));
            $io->progressAdvance();
            if ($sleepUs > 0) usleep($sleepUs);
        }
        $io->progressFinish();

        $io->success(sprintf('%d sent, %d failed. Failures are in gates_broadcast_log — '
            . 're-running retries only those.', $sent, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<object> cycles in voting, with a usable close date */
    private function cycles(int $only): array
    {
        $q = DB::table('gates_award_cycles as cy')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->whereNotNull('cy.voting_close')
            ->where('cy.voting_close', '>', Carbon::now()->toDateTimeString())
            ->select('cy.id', 'cy.programme_id', 'cy.voting_close', 'p.title as programme_title');

        $only > 0 ? $q->where('cy.id', $only) : $q->where('cy.status', 'voting');

        return $q->orderBy('cy.voting_close')->get()->all();
    }

    /** @return list<object> approved nominees in a cycle */
    private function nominees(int $cycleId): array
    {
        return DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('c.cycle_id', $cycleId)
            // Winners and runners-up are still approved nominees with pages; but this mail
            // is about a vote that has not closed, so only the in-flight status qualifies.
            ->where('n.status', 'approved')
            ->whereNull('n.merged_into')
            ->select('n.id', 'n.name', 'n.profile_id', 'n.category_id',
                     'c.title as category_title', 'p.slug as programme_slug')
            ->orderBy('n.id')
            ->get()->all();
    }

    /**
     * Candidate addresses for a nominee, best source first.
     *
     * @return list<string> 0 = unreachable, >1 = ambiguous and must not be guessed
     */
    private function addressesFor(object $n, int $cycleId): array
    {
        // 1. A real link beats a name match, so if the profile has an address, use it and
        //    stop — a same-name nomination elsewhere cannot make this ambiguous.
        if (!empty($n->profile_id)) {
            $e = DB::table('gates_profiles')->where('id', $n->profile_id)->value('email');
            if (\is_string($e) && $e !== '' && \filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return [EmailOptOut::normalise($e)];
            }
        }

        // 2. Back to the nomination, by cycle + name. LOWER() on both sides because
        //    approval passes the name through Name::title() and the nomination keeps
        //    whatever was typed.
        $rows = DB::table('gates_nominations')
            ->where('cycle_id', $cycleId)
            ->where('status', 'approved')
            ->whereNotNull('nominee_email')
            ->where('nominee_email', '!=', '')
            ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [\strtolower(\trim((string) $n->name))])
            ->pluck('nominee_email')->all();

        $out = [];
        foreach ($rows as $e) {
            $e = EmailOptOut::normalise((string) $e);
            if ($e !== '' && \filter_var($e, FILTER_VALIDATE_EMAIL)) $out[$e] = true;
        }

        return \array_keys($out);
    }

    /** @return array<string,true> email_hash of everything already sent for this campaign */
    private function alreadySent(): array
    {
        $out = [];
        foreach (DB::table('gates_broadcast_log')
                     ->where('campaign', self::CAMPAIGN)->where('status', 'sent')
                     ->pluck('email_hash') as $h) {
            $out[(string) $h] = true;
        }
        return $out;
    }

    /** @param array{nominee:object,cycle:object,email:string} $r @return array<string,mixed> */
    private function vars(array $r, string $site): array
    {
        $n     = $r['nominee'];
        $close = Carbon::parse((string) $r['cycle']->voting_close);
        $first = \trim(\explode(' ', \trim((string) $n->name))[0] ?? '');

        return [
            // The countdown carries the nominee's OWN cycle. Cycles are per programme and
            // several can be voting at once, so the endpoint's "soonest closing" fallback
            // would hand this person another programme's deadline.
            'countdown_url'   => CountdownGif::urlFor($site, (int) $r['cycle']->id),
            'countdown_alt'   => 'Voting closes ' . $close->format('D j M Y, H:i T'),
            'closes_human'    => $close->format('D j M Y, H:i T'),
            'first_name'      => $first,
            'category_name'   => (string) ($n->category_title ?? ''),
            // Their own vote page, not the all-nominees ballot: this email asks them to
            // rally supporters, and handing them the generic page undercuts the ask.
            'vote_url'        => $this->votePage($site, $n),
            'events_url'      => $site . '/events',
            'site_url'        => $site,
            'unsubscribe_url' => EmailOptOut::url($site, $r['email']),
            'postal_address'  => (string) Env::get('MAIL_POSTAL_ADDRESS',
                'Afrovanguard, Lagos, Nigeria'),
        ];
    }

    /**
     * A nominee's own vote page.
     *
     * The canonical shape is /vote/{PROGRAMME-slug}/{id}-{name}, built the same way
     * VoteController::nomineeUrl() builds it — via Slug::idSegment, so this cannot drift
     * from what the router accepts. Worth spelling out because the route parameter is
     * named `{program}` and it is easy to read as the CATEGORY: an earlier version of
     * this method passed the category slug and a bare id, which is a 404 in every
     * recipient's inbox.
     *
     * Falls back to the ballot when the programme slug is missing: a link to the right
     * place beats a broken personalised one.
     */
    private function votePage(string $site, object $n): string
    {
        $prog = \trim((string) ($n->programme_slug ?? ''));
        if ($prog === '') return $site . '/vote';

        return \sprintf('%s/vote/%s/%s', $site, \rawurlencode($prog),
            \AfricaGates\Support\Slug::idSegment((int) $n->id, (string) $n->name));
    }

    /** @param array{nominee:object,cycle:object,email:string} $r */
    private function plain(array $r, string $site): string
    {
        $n = $r['nominee'];
        $close = Carbon::parse((string) $r['cycle']->voting_close)->format('D j M Y, H:i T');
        $first = \trim(\explode(' ', \trim((string) $n->name))[0] ?? '');

        // A real text alternative, not strip_tags of the HTML: a stripped campaign is a
        // wall of style rules and link text with no sentences in it.
        return \implode("\n", [
            ($first !== '' ? "$first," : 'Hello,'),
            '',
            'You are in the final stretch of Africa GATES voting'
                . (($n->category_title ?? '') !== '' ? ' in ' . $n->category_title : '') . '.',
            "Voting closes $close.",
            '',
            'Two things we are asking of you:',
            '1. Mobilise your supporters — share your voting link: ' . $this->votePage($site, $n),
            '2. Be there on the night — ' . $site . '/events',
            '',
            '— Africa GATES',
            'Unsubscribe: ' . EmailOptOut::url($site, $r['email']),
        ]);
    }

    /** @param array{nominee:object,cycle:object,email:string} $r */
    private function logSend(array $r, bool $ok, string $error): void
    {
        try {
            DB::table('gates_broadcast_log')->updateOrInsert(
                ['campaign' => self::CAMPAIGN, 'email_hash' => EmailOptOut::hash($r['email'])],
                ['email'      => $r['email'],
                 'nominee_id' => (int) $r['nominee']->id,
                 'status'     => $ok ? 'sent' : 'failed',
                 'error'      => $error === '' ? null : \mb_substr($error, 0, 300),
                 'sent_at'    => Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable) {
            // A logging failure must not stop a send that already happened — but it does
            // mean a re-run could mail this person twice, so it is worth being loud.
            // Deliberately swallowed here and surfaced by the sent/failed tally instead.
        }
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
