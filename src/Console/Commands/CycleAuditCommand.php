<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\PhaseAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What the broken lifecycle already did to the data — read-only.
 *
 * Run this BEFORE arming strict phase enforcement in production. The new guard
 * refuses writes outside a cycle's window, but it says nothing about the rows
 * written across the years when nothing closed, and three of those questions
 * carry money or a published result:
 *
 *   bin/console cycles:audit            # human report
 *   bin/console cycles:audit --json     # for a ticket or a spreadsheet
 *   bin/console cycles:audit --strict   # non-zero exit if anything was found
 *
 * --strict makes it usable as a deploy gate: green means the historic data is
 * consistent with the windows the operators declared, so strict enforcement will
 * not surprise anyone. It writes nothing in any mode.
 */
#[AsCommand(name: 'cycles:audit', description: 'Report votes, nominations and payments recorded outside their cycle windows (read-only).')]
class CycleAuditCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit the raw report as JSON.')
             ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero when any finding is present.')
             ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max detail rows per money section.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = PhaseAuditService::run();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $this->verdict($input, $report);
        }

        $io    = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $io->title('Cycle audit — ' . $report['generated_at']);

        // ── Clock first. Every finding below is a timestamp comparison, so a
        // skewed clock means some findings are timezone artefacts, not offences.
        $clock = $report['clock'];
        $io->section('Clock');
        $io->definitionList(
            ['driver' => $clock['driver']],
            ['PHP timezone' => $clock['php_timezone']],
            ['PHP now' => $clock['php_now']],
            ['DB CURRENT_TIMESTAMP' => (string) ($clock['db_now'] ?? '—')],
            ['skew' => $clock['skew_seconds'] === null
                ? 'unknown'
                : sprintf('%+ds (%+.2fh)', $clock['skew_seconds'], $clock['skew_hours'])],
            ['session timezone' => $clock['session_offset'] === null
                ? 'n/a (no session timezone on this driver)'
                : ($clock['session_aligned'] ? 'aligned to ' . $clock['session_offset'] : 'NOT ALIGNED')
                  . ($clock['session_was'] !== null ? ' (was ' . $clock['session_was'] . ')' : '')],
        );
        if ($clock['session_offset'] !== null && !$clock['session_aligned']) {
            $io->error(
                'Could not align the database session timezone. This schema stores cycle boundaries as '
                . 'DATETIME and ballot timestamps as TIMESTAMP, and MySQL converts only the latter into the '
                . 'session timezone — so every finding below is shifted by the session offset. A vote 30 '
                . 'minutes before a deadline reads as late under +01:00. Do not act on these numbers until '
                . 'the connection can SET time_zone, or run with a session already in ' . $clock['session_offset'] . '.'
            );
        }
        if ($clock['suspicious']) {
            $io->warning(
                'The database and PHP disagree about the current time by more than a minute. '
                . 'Rows written by a DB-side CURRENT_TIMESTAMP default are NOT comparable to '
                . 'boundaries written by PHP, so treat the findings below as suspect until this '
                . 'is resolved. A whole-hour skew means a timezone mismatch, not a slow clock.'
            );
        }

        // ── Cycles
        $io->section('Cycles');
        $rows = array_map(fn ($c) => [
            $c['cycle_id'], $c['programme'], $c['year'], $c['stored_status'], $c['computed_phase'],
            $c['drifted'] ? strtoupper($c['direction']) : 'ok',
            $c['next_boundary'] ?? '—',
        ], $report['cycles']);
        if ($rows === []) {
            $io->text('No cycles.');
        } else {
            $io->table(['#', 'programme', 'year', 'stored', 'computed', 'drift', 'next boundary'], $rows);
            $io->text('"BEHIND" = the engine never caught up (the bug). "AHEAD" = an operator advanced it by hand (legitimate).');
        }

        if ($report['undated'] !== []) {
            $io->section('Cycles with undeclared boundaries');
            $io->text('These could not be audited: with no closing date there is no instant at which anything became late.');
            $io->table(['#', 'year', 'stored', 'missing'], array_map(
                fn ($c) => [$c['cycle_id'], $c['year'], $c['stored_status'], implode(', ', $c['missing'])],
                $report['undated']
            ));
        }

        // ── Ballots
        $this->windowSection($io, 'Votes cast AFTER voting closed', $report['votes_after_close'],
            ['#', 'year', 'type', 'votes', 'weight', 'closed at', 'first late', 'last late'],
            fn ($r) => [$r['cycle_id'], $r['year'], $r['vote_type'], $r['votes'], $r['weight'],
                        $r['boundary_at'], $r['first_at'], $r['last_at']]);

        $this->windowSection($io, 'Votes cast BEFORE voting opened', $report['votes_before_open'],
            ['#', 'year', 'type', 'votes', 'weight', 'opened at', 'first', 'last'],
            fn ($r) => [$r['cycle_id'], $r['year'], $r['vote_type'], $r['votes'], $r['weight'],
                        $r['boundary_at'], $r['first_at'], $r['last_at']]);

        $this->windowSection($io, 'Nominations taken after nominations closed', $report['nominations_outside_window'],
            ['#', 'year', 'status', 'count', 'closed at', 'first late', 'last late'],
            fn ($r) => [$r['cycle_id'], $r['year'], $r['status'], $r['nominations'],
                        $r['boundary_at'], $r['first_at'], $r['last_at']]);

        // ── Money: paid but never delivered
        $un = $report['paid_unminted'];
        $io->section('Paid-vote orders confirmed but never minted');
        if ((int) $un['orders'] === 0) {
            $io->text('None — every confirmed paid-vote order delivered its votes.');
        } else {
            $io->warning(sprintf('%d order(s), ₦%s, %d vote(s) paid for and not delivered.',
                $un['orders'], number_format((int) $un['naira']), $un['votes']));
            $io->table(['donation', 'ref', '₦', 'votes', 'nominee', 'paid at', 'remedy'],
                array_map(fn ($r) => [$r['donation_id'], $r['payment_ref'], number_format((int) $r['naira']),
                    $r['votes'], $r['nominee'], $r['created_at'], $r['remedy']],
                    array_slice($un['rows'], 0, $limit)));
            $this->noteTruncation($io, count($un['rows']), $limit);
            $io->text([
                're-mint     — the target category is votable again; PaidVoteService::mint() will deliver.',
                'refund      — voting is closed; reverse it with  bin/console payments:clawback <id> --commit',
                'investigate — the order points at no live nominee; needs a human before either.',
            ]);
        }

        // ── Money: delivered too late
        $late = $report['paid_minted_late'];
        $io->section('Paid votes minted AFTER voting closed');
        if ((int) $late['orders'] === 0) {
            $io->text('None — no purchased vote landed in a closed cycle.');
        } else {
            $io->warning(sprintf('%d vote row(s), %d total weight, ₦%s — money kept and a closed tally moved.',
                $late['orders'], $late['weight'], number_format((int) $late['naira'])));
            $io->table(['vote', 'donation', 'year', 'nominee', 'weight', 'closed at', 'voted at', 'days late', 'refunded'],
                array_map(fn ($r) => [$r['vote_id'], $r['donation_id'] ?: '—', $r['year'], $r['nominee_id'],
                    $r['weight'], $r['closed_at'], $r['voted_at'], $r['days_late'], $r['refunded'] ? 'yes' : 'no'],
                    array_slice($late['rows'], 0, $limit)));
            $this->noteTruncation($io, count($late['rows']), $limit);
            $io->text('There is no clean remedy: voiding changes a published standing, keeping it means a closed result was bought afterwards. Decide deliberately.');
        }

        // ── The uncrowned
        $io->section('Finished categories with no winner');
        if ($report['results_backlog'] === []) {
            $io->text('None — every finished category with eligible nominees has a winner.');
        } else {
            $io->table(['cycle', 'year', 'category', 'eligible', 'closed at', 'days late'],
                array_map(fn ($r) => [$r['cycle_id'], $r['year'], $r['category'], $r['eligible'],
                    $r['closed_at'] ?? '—', $r['days_late']], $report['results_backlog']));
            $io->text('Promotion happens when the materialiser claims the results transition; for these it never did. Winners past the announcement grace window are promoted silently — see CycleMaterialiser::ANNOUNCE_GRACE_DAYS.');
        }

        // ── Verdict
        $io->section('Summary');
        $io->table(['finding', 'count'], array_map(
            fn ($k, $v) => [str_replace('_', ' ', $k), $v],
            array_keys($report['totals']), array_values($report['totals'])
        ));

        if (PhaseAuditService::isClean($report)) {
            $io->success('Nothing outside a declared window. Strict phase enforcement will not surprise anyone.');
        } else {
            $io->warning('Findings above predate the phase guard. Resolve or consciously accept them before arming strict enforcement against production data.');
        }

        return $this->verdict($input, $report);
    }

    /** @param callable(array<string,mixed>):array<int,mixed> $row */
    private function windowSection(SymfonyStyle $io, string $title, array $findings, array $head, callable $row): void
    {
        $io->section($title);
        if ($findings === []) {
            $io->text('None.');
            return;
        }
        $io->table($head, array_map($row, $findings));
    }

    private function noteTruncation(SymfonyStyle $io, int $total, int $limit): void
    {
        if ($total > $limit) {
            $io->text(sprintf('… %d more; raise --limit or use --json for the full list.', $total - $limit));
        }
    }

    private function verdict(InputInterface $input, array $report): int
    {
        return $input->getOption('strict') && !PhaseAuditService::isClean($report)
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
