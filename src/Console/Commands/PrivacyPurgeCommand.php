<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Data-retention / right-to-erasure purge (NDPR/GDPR). SAFE BY DEFAULT:
 *
 *   - **Dry-run unless `--commit`** — without `--commit` it only reports what it
 *     *would* delete and changes nothing.
 *   - **Opt-in per table** — a table is purged only when its retention window
 *     (days) is configured via the env var below, except transient drafts which
 *     carry a sane built-in default.
 *   - **Records are never auto-purged** — donations (legal/audit), approved/public
 *     profiles, votes, and tamper-evident snapshots are deliberately NOT listed.
 *
 * Schedule (after configuring windows) e.g. daily:
 *   php bin/console privacy:purge --commit
 *
 * See docs/SECURITY-HARDENING-V3.md for the retention policy + erasure runbook.
 */
#[AsCommand(name: 'privacy:purge', description: 'Delete PII past its configured retention window (NDPR/GDPR). Dry-run unless --commit.')]
final class PrivacyPurgeCommand extends Command
{
    /**
     * [table, date column, retention env var, built-in default days (0 = disabled
     * unless the env var is set), optional eligibility filter].
     * Filter forms: ['col','value'] (equals) or ['__notnull','col'].
     */
    private const RULES = [
        ['gates_nomination_drafts',   'updated_at',      'RETAIN_DRAFT_DAYS',                  30, null],
        ['gates_nominations',         'created_at',      'RETAIN_REJECTED_NOMINATION_DAYS',     0, ['status', 'rejected']],
        ['gates_partner_enquiries',   'created_at',      'RETAIN_CLOSED_ENQUIRY_DAYS',          0, ['status', 'closed']],
        ['gates_event_registrations', 'created_at',      'RETAIN_EVENT_REGISTRATION_DAYS',      0, null],
        ['gates_newsletter',          'unsubscribed_at', 'RETAIN_UNSUBSCRIBED_NEWSLETTER_DAYS', 0, ['__notnull', 'unsubscribed_at']],
        // The AI log grows one row per model call. It holds no prompt text —
        // only a hash — but it does hold subject ids, so it is in scope for
        // retention alongside everything else that points at a person.
        ['gates_ai_calls',            'created_at',      'RETAIN_AI_CALL_DAYS',                 0, null],
        // Decisions are the accountability + evaluation record, so the default
        // window is deliberately longer than the call log's.
        ['gates_ai_decisions',        'created_at',      'RETAIN_AI_DECISION_DAYS',             0, null],
    ];

    protected function configure(): void
    {
        $this->addOption('commit', null, InputOption::VALUE_NONE,
            'Actually delete. Without this flag the command is a dry-run and deletes nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $commit = (bool) $input->getOption('commit');
        $io->title('PII retention purge ' . ($commit ? '(COMMIT — deleting)' : '(dry-run — nothing will be deleted)'));

        $total = 0;
        foreach (self::RULES as [$table, $col, $env, $default, $filter]) {
            $days = (int) (($_ENV[$env] ?? getenv($env) ?: '') ?: $default);
            if ($days <= 0) {
                $io->writeln("  skip <comment>$table</comment> — $env not set");
                continue;
            }
            $cutoff = Carbon::now()->subDays($days)->toDateTimeString();
            try {
                $q = DB::table($table)->where($col, '<', $cutoff);
                if (is_array($filter)) {
                    $filter[0] === '__notnull' ? $q->whereNotNull($filter[1]) : $q->where($filter[0], $filter[1]);
                }
                $n = (clone $q)->count();
                if ($n === 0) {
                    $io->writeln("  $table: 0 rows older than {$days}d");
                    continue;
                }
                if ($commit) {
                    $q->delete();
                    $io->writeln("  <info>$table: deleted $n row(s)</info> older than {$days}d");
                } else {
                    $io->writeln("  $table: would delete $n row(s) older than {$days}d");
                }
                $total += $n;
            } catch (\Throwable $e) {
                // A missing table (e.g. a migration-only table absent in some envs)
                // must never abort the whole purge.
                $io->writeln("  $table: skipped (" . $e->getMessage() . ')');
            }
        }

        $io->success(($commit ? 'Purged' : '[dry-run] would purge') . " {$total} row(s).");
        return Command::SUCCESS;
    }
}
