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

        // ── THE STORES THAT ARE NOT THIS DATABASE ────────────────────────────
        //
        // A judging interview leaves the member's own words in two places an erasure had
        // been walking straight past: `gates_interview_guard_log`, which quotes the
        // sentence the guard refused, and the bot host, which holds the recording and the
        // transcript. The guard log auto-prunes at 180 days and the bucket has a lifecycle
        // rule, but erasure ON REQUEST is immediate or it is not erasure — and a DSAR
        // answered with "it will age out" is the complaint, not the reply.
        //
        // Members are not joined to interviews directly. The chain is
        // email → gates_profiles → gates_nominees.profile_id → gates_interviews.nominee_id,
        // which is the same best-effort resolution NomineeBroadcast uses; see the note in
        // HANDOFF.md about gates_nominees having no email of its own.
        $interviews = self::interviewsFor((string) $user->email);

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
            // AI decision rows carry the acting ADMIN's id, not the subject's, so
            // erasing a member does not touch them. What must go is the actor
            // linkage when the erased account was itself an operator.
            'gates_ai_decisions (as actor)' => fn() => DB::table('gates_ai_decisions')->where('actor_id', $id)->update([
                'actor_id' => null,
            ]),
            'gates_event_registrations (by user)' => fn() => DB::table('gates_event_registrations')->where('user_id', $id)->update([
                'name' => 'Deleted member', 'email' => $tombstoneEmail, 'phone' => null,
            ]),

            // The quoted sentence goes; the refusal itself stays. InterviewGuard::tally()
            // is how the corpus gets extended after a real judging round, and deleting the
            // rows outright would erase the evidence that a rule fired along with the
            // words that triggered it. Same shape as InterviewService::withdraw(): the
            // record survives, the person does not appear in it.
            'gates_interview_guard_log (quoted text)' => fn() => DB::table('gates_interview_guard_log')
                ->whereIn('interview_id', $interviews)
                ->update(['text' => null, 'note' => 'erased']),
        ];

        // Counts for the report (best-effort — a table absent in some envs is skipped).
        $counts = [
            'gates_comments (authored)'           => fn() => DB::table('gates_comments')->where('author_user_id', $id)->count(),
            'gates_threads (authored)'            => fn() => DB::table('gates_threads')->where('author_user_id', $id)->count(),
            'gates_ai_decisions (as actor)'       => fn() => DB::table('gates_ai_decisions')->where('actor_id', $id)->count(),
            'gates_event_registrations (by user)' => fn() => DB::table('gates_event_registrations')->where('user_id', $id)->count(),
            'gates_interview_guard_log (quoted text)' => fn() => DB::table('gates_interview_guard_log')
                ->whereIn('interview_id', $interviews)->whereNotNull('text')->count(),
        ];
        foreach ($counts as $label => $counter) {
            try { $io->writeln("  {$label}: " . $counter() . ' row(s)'); }
            catch (\Throwable $e) { $io->writeln("  {$label}: (skipped — " . $e->getMessage() . ')'); }
        }

        $bots = self::botsFor($interviews);
        $io->writeln('  Attendee (recording + transcript on the bot host): ' . count($bots) . ' bot(s)');

        if (!$commit) {
            $io->warning('Dry-run: re-run with --commit to anonymise the above. Points/ledger history is preserved; only PII is scrubbed.');
            return Command::SUCCESS;
        }

        foreach ($plan as $label => $write) {
            try { $write(); $io->writeln("  <info>scrubbed {$label}</info>"); }
            catch (\Throwable $e) { $io->writeln("  {$label}: skipped (" . $e->getMessage() . ')'); }
        }
        // ── THE HALF THAT LEAVES THIS MACHINE ────────────────────────────────
        //
        // Reported per bot rather than in aggregate, and never fatal. An erasure that
        // stops at the first unreachable host leaves the rest of the request undone; one
        // that stays silent about a failure lets somebody sign off a DSAR that did not
        // complete. So each result is printed and the run continues.
        foreach ($bots as $botId) {
            try {
                $r = \AfricaGates\Services\AttendeeBot::deleteData((string) $botId);
                if ($r['ok']) {
                    $io->writeln("  <info>erased Attendee data for {$botId}</info>");
                } else {
                    $io->writeln("  <comment>Attendee {$botId}: NOT erased — {$r['error']}</comment>");
                }
            } catch (\Throwable $e) {
                $io->writeln("  <comment>Attendee {$botId}: NOT erased — " . $e->getMessage() . '</comment>');
            }
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

    /**
     * Interview ids belonging to whoever holds this email address.
     *
     * Best-effort by design, and it fails CLOSED — an empty list scrubs nothing rather
     * than scrubbing somebody else's sitting. `gates_nominees` carries no email of its
     * own, so the join runs through `gates_profiles`, which does and holds it unique.
     *
     * @return list<int>
     */
    private static function interviewsFor(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || str_ends_with($email, '@deleted.invalid')) return [];

        try {
            $profiles = DB::table('gates_profiles')->whereRaw('LOWER(email) = ?', [$email])->pluck('id')->all();
            if ($profiles === []) return [];

            $nominees = DB::table('gates_nominees')->whereIn('profile_id', $profiles)->pluck('id')->all();
            if ($nominees === []) return [];

            return array_map('intval', DB::table('gates_interviews')->whereIn('nominee_id', $nominees)->pluck('id')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The bot ids for those sittings, so the recording and transcript held on the bot
     * host go with the rest.
     *
     * @param list<int> $interviews
     * @return list<string>
     */
    private static function botsFor(array $interviews): array
    {
        if ($interviews === []) return [];

        try {
            $ids = DB::table('gates_interviews')->whereIn('id', $interviews)
                ->whereNotNull('bot_id')->where('bot_id', '!=', '')->pluck('bot_id')->all();
            return array_values(array_filter(array_map(static fn ($b) => trim((string) $b), $ids)));
        } catch (\Throwable) {
            return [];
        }
    }
}
