<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Right-to-erasure for a single member (NDPR/GDPR). Anonymises the member's PII
 * in place rather than hard-deleting the row, so points/accounting history and
 * referential links stay intact while name/email/phone/IP are scrubbed.
 *
 * SAFE BY DEFAULT: dry-run unless `--commit`.
 *
 *   php bin/console privacy:erase-user someone@example.com          # preview
 *   php bin/console privacy:erase-user 42 --commit                  # apply (by id)
 */
#[AsCommand(name: 'privacy:erase-user', description: 'Anonymise a member\'s PII across the platform (NDPR/GDPR erasure). Dry-run unless --commit.')]
final class PrivacyEraseUserCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('user', InputArgument::REQUIRED, 'Member email address or numeric id.');
        $this->addOption('commit', null, InputOption::VALUE_NONE, 'Actually anonymise. Without this flag nothing is written.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $arg    = trim((string) $input->getArgument('user'));
        $commit = (bool) $input->getOption('commit');

        $user = ctype_digit($arg)
            ? DB::table('gates_users')->where('id', (int) $arg)->first()
            : DB::table('gates_users')->whereRaw('LOWER(email) = ?', [strtolower($arg)])->first();

        if (!$user) {
            $io->error("No member found for \"{$arg}\".");
            return Command::FAILURE;
        }
        $id = (int) $user->id;
        $io->title('Member erasure ' . ($commit ? '(COMMIT)' : '(dry-run — nothing will be written)'));
        $io->writeln("  Member #{$id} — {$user->name} <{$user->email}>");

        $tombstoneEmail = 'erased+' . $id . '@deleted.invalid';
        $now = Carbon::now()->toDateTimeString();

        // What will be scrubbed (table => human description + the write).
        $plan = [
            'gates_users (this account)' => fn() => DB::table('gates_users')->where('id', $id)->update([
                'name' => 'Deleted member', 'email' => $tombstoneEmail, 'phone' => null,
                'password_hash' => null, 'last_login_ip' => null, 'email_verified' => 0, 'status' => 'erased',
            ]),
            'gates_comments (authored)' => fn() => DB::table('gates_comments')->where('author_user_id', $id)->update([
                'author_name' => 'Deleted member', 'author_email' => null, 'author_email_hash' => null,
            ]),
            'gates_threads (authored)' => fn() => DB::table('gates_threads')->where('author_user_id', $id)->update([
                'author_name' => 'Deleted member', 'author_email_hash' => 'erased',
            ]),
            'gates_event_registrations (by user)' => fn() => DB::table('gates_event_registrations')->where('user_id', $id)->update([
                'name' => 'Deleted member', 'email' => $tombstoneEmail, 'phone' => null,
            ]),
        ];

        // Counts for the report (best-effort — a table absent in some envs is skipped).
        $counts = [
            'gates_comments (authored)'           => fn() => DB::table('gates_comments')->where('author_user_id', $id)->count(),
            'gates_threads (authored)'            => fn() => DB::table('gates_threads')->where('author_user_id', $id)->count(),
            'gates_event_registrations (by user)' => fn() => DB::table('gates_event_registrations')->where('user_id', $id)->count(),
        ];
        foreach ($counts as $label => $counter) {
            try { $io->writeln("  {$label}: " . $counter() . ' row(s)'); }
            catch (\Throwable $e) { $io->writeln("  {$label}: (skipped — " . $e->getMessage() . ')'); }
        }

        if (!$commit) {
            $io->warning('Dry-run: re-run with --commit to anonymise the above. Points/ledger history is preserved; only PII is scrubbed.');
            return Command::SUCCESS;
        }

        foreach ($plan as $label => $write) {
            try { $write(); $io->writeln("  <info>scrubbed {$label}</info>"); }
            catch (\Throwable $e) { $io->writeln("  {$label}: skipped (" . $e->getMessage() . ')'); }
        }
        // Leave an audit trail of the erasure itself (no PII in it).
        try {
            DB::table('gates_audit_log')->insert([
                'admin_id' => null, 'action' => 'privacy.erase_user', 'target_type' => 'user', 'target_id' => $id,
                'meta' => json_encode(['via' => 'cli']), 'created_at' => $now,
            ]);
        } catch (\Throwable $e) {}

        $io->success("Member #{$id} anonymised.");
        return Command::SUCCESS;
    }
}
