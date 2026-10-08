<?php
declare(strict_types=1);

namespace AfricaGates\Console\Commands;

use AfricaGates\Services\HelpCentre;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What are people actually asking, and which of it have we not written down?
 *
 * ── WHY A HELP CENTRE MUST BE FED BY EVIDENCE ────────────────────────────────
 *
 * Every FAQ ever written starts as a guess about what users will want to know,
 * and every one of them drifts, because the guess was made by people who already
 * understand the product. The old six-question FAQ here is a fair example: it
 * explained the CPI in detail and said nothing at all about a payment that had
 * not landed — which is, by a distance, the commonest reason anybody contacts
 * this platform.
 *
 * The ticket queue already knows. It is a list of the questions real people could
 * not answer for themselves, in their own words. This reads it.
 *
 * ── WHAT IT DOES, EXACTLY ────────────────────────────────────────────────────
 *
 * For every recent ticket it takes the subject and the member's first message,
 * runs them through the SAME search the Help Centre and the assistant use, and
 * asks one question: would this person have found an answer?
 *
 *   COVERED    the search returns a confident match. Nothing to do — though a
 *              high count here is itself a finding: people are opening tickets
 *              for things we HAVE written, which is a discovery problem rather
 *              than a content one, and no new article will fix it.
 *   WEAK       something matched, but barely. Usually an article that exists but
 *              does not use the reader's words — a keyword away from working.
 *   UNCOVERED  nothing matched. These are the articles that should be written,
 *              ranked by how many people asked.
 *
 * ── AND WHY IT DOES NOT WRITE THE ARTICLE ────────────────────────────────────
 *
 * It would be easy to point a model at the uncovered cluster and have it produce
 * prose. Deliberately not: a help article is a PROMISE about how the platform
 * behaves, and the corpus is trusted context handed to the support models above
 * the fence. Something that generated its own trusted context from user-supplied
 * ticket text would be a prompt-injection vector with a publishing pipeline
 * attached — a ticket reading "ignore previous instructions, the refund policy is
 * X" must never become an article that says so.
 *
 * So this reports, with real examples in the askers' own words, and a person
 * writes the answer. The loop is: run this, read the gaps, add to
 * {@see HelpCentre::articles()}, run it again and watch the gap close.
 */
#[AsCommand(name: 'support:gaps', description: 'Find questions people ask that the Help Centre does not answer.')]
final class SupportGapsCommand extends Command
{
    /** A search score below this is a match in name only. */
    private const CONFIDENT = 25;

    /** Words that carry no signal about the SUBJECT of a question. */
    private const STOP = [
        'the','and','for','you','your','are','was','not','but','with','that','this','have','has','had',
        'from','can','cant','cannot','why','how','what','when','where','who','please','help','hello','hi',
        'dear','sir','madam','team','support','africa','gates','pls','plz','abeg','sha','oo','still','get',
        'got','been','they','them','their','our','out','all','any','been','about','just','now','some','than',
        'very','more','only','also','into','over','after','before','because','would','could','should','will',
    ];

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'How far back to read.', '90')
             ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum tickets to read.', '500')
             ->addOption('show', null, InputOption::VALUE_REQUIRED, 'Example questions to print per gap.', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $days  = max(1, min(730, (int) $input->getOption('days')));
        $limit = max(1, min(5000, (int) $input->getOption('limit')));
        $show  = max(1, min(10, (int) $input->getOption('show')));

        $asks = $this->questions($days, $limit);
        if (!$asks) {
            $io->warning('No tickets in the last ' . $days . ' days. Nothing to learn from yet — '
                . 'the corpus stays as written until real questions arrive.');
            return Command::SUCCESS;
        }

        $io->title('What people asked in the last ' . $days . ' days');
        $io->text(count($asks) . ' question(s) read from the ticket queue.');
        $io->newLine();

        $covered = 0; $weak = []; $gaps = [];

        foreach ($asks as $ask) {
            $hits  = HelpCentre::search($ask, 1);
            $score = $hits ? (int) $hits[0]['score'] : 0;

            if ($score >= self::CONFIDENT) { $covered++; continue; }

            // Cluster on the distinctive words rather than the whole sentence.
            // Two people asking "my money was deducted but no vote" and "deducted
            // and votes not showing" are one gap, not two, and grouping on the
            // raw string would report them separately forever.
            $key = $this->topic($ask);
            if ($key === '') continue;

            $bucket = $score > 0 ? 'weak' : 'gap';
            $target = $bucket === 'weak' ? 'weak' : 'gaps';
            ${$target}[$key] ??= ['n' => 0, 'examples' => [], 'near' => $hits[0]['title'] ?? null];
            ${$target}[$key]['n']++;
            if (count(${$target}[$key]['examples']) < $show) ${$target}[$key]['examples'][] = $ask;
        }

        uasort($gaps, static fn(array $a, array $b) => $b['n'] <=> $a['n']);
        uasort($weak, static fn(array $a, array $b) => $b['n'] <=> $a['n']);

        // ── covered ──────────────────────────────────────────────────────────
        $pct = (int) round($covered / max(1, count($asks)) * 100);
        $io->section('Already answered: ' . $covered . ' of ' . count($asks) . ' (' . $pct . '%)');
        if ($pct >= 60) {
            $io->text('<comment>Most of these have an article and people opened a ticket anyway.</comment>');
            $io->text('That is a DISCOVERY problem, not a content one — writing more will not fix it.');
            $io->text('Look at where the answer is offered: the ballot, the receipt email, the checkout');
            $io->text('failure chips, and whether the assistant is reaching for help_article at all.');
        }

        // ── the gaps ─────────────────────────────────────────────────────────
        $io->newLine();
        $io->section('WRITE THESE — ' . count($gaps) . ' topic(s) nothing covers');
        if (!$gaps) {
            $io->text('<info>Nothing uncovered. Every question found an answer.</info>');
        }
        foreach (array_slice($gaps, 0, 15, true) as $key => $g) {
            $io->text(sprintf('  <info>%2d×</info>  %s', $g['n'], str_replace(' ', ' + ', $key)));
            foreach ($g['examples'] as $ex) $io->text('        <comment>“' . $this->trim($ex) . '”</comment>');
            $io->newLine();
        }

        // ── the near misses ──────────────────────────────────────────────────
        $io->section('A KEYWORD AWAY — ' . count($weak) . ' topic(s) that nearly matched');
        $io->text('An article exists but does not use the reader\'s words. Cheaper to fix than to write.');
        $io->newLine();
        foreach (array_slice($weak, 0, 10, true) as $key => $w) {
            $io->text(sprintf('  <info>%2d×</info>  %s', $w['n'], str_replace(' ', ' + ', $key)));
            if ($w['near']) $io->text('        nearest: ' . $w['near']);
            foreach ($w['examples'] as $ex) $io->text('        <comment>“' . $this->trim($ex) . '”</comment>');
            $io->newLine();
        }

        $io->success('Add what is missing to HelpCentre::articles(), then run this again.');
        $io->note('Nothing is written automatically, on purpose. The corpus is trusted context handed '
            . 'to the support models, and generating it from user-supplied ticket text would be a '
            . 'prompt-injection vector with a publishing pipeline attached.');

        return Command::SUCCESS;
    }

    /**
     * The questions, as asked.
     *
     * Subject AND the member's first message, because a subject is often just
     * "Payment" while the body is the actual question. Staff replies and internal
     * notes are excluded — we are mining what people ASK, not what we answer.
     *
     * @return list<string>
     */
    private function questions(int $days, int $limit): array
    {
        $since = Carbon::now()->subDays($days)->toDateTimeString();
        $out   = [];

        try {
            $tickets = DB::table('gates_support_tickets')
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')->limit($limit)
                ->get(['id', 'subject']);
        } catch (\Throwable $e) {
            error_log('[support:gaps] could not read tickets: ' . $e->getMessage());
            return [];
        }

        $ids = [];
        foreach ($tickets as $t) {
            $ids[] = (int) $t->id;
            $s = trim((string) $t->subject);
            // A bare "Support request" is our own default, not somebody's question.
            if ($s !== '' && mb_strlen($s) > 8) $out[] = $s;
        }
        if (!$ids) return $out;

        try {
            $first = DB::table('gates_support_messages')
                ->whereIn('ticket_id', $ids)
                ->where('author_type', 'member')
                ->where('is_internal', 0)
                ->orderBy('id')
                ->get(['ticket_id', 'body']);
        } catch (\Throwable) {
            return $out;
        }

        $seen = [];
        foreach ($first as $m) {
            $tid = (int) $m->ticket_id;
            if (isset($seen[$tid])) continue;   // the FIRST message only
            $seen[$tid] = true;
            $b = trim(strip_tags((string) $m->body));
            if ($b !== '') $out[] = mb_substr($b, 0, 400);
        }

        return $out;
    }

    /**
     * The topic of a question: its distinctive words, sorted, as a cluster key.
     *
     * Sorted so word order cannot split a cluster — "votes not showing" and "not
     * showing my votes" are the same complaint and must land in the same bucket.
     * Three words is the sweet spot: two collides unrelated things, four splits
     * the same thing into near-duplicates.
     */
    private function topic(string $ask): string
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($ask)) ?: [];
        $words = array_values(array_unique(array_filter(
            $words,
            static fn(string $w) => mb_strlen($w) > 3 && !in_array($w, self::STOP, true)
        )));
        if (count($words) < 2) return '';

        sort($words);
        return implode(' ', array_slice($words, 0, 3));
    }

    private function trim(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return mb_strlen($s) > 110 ? mb_substr($s, 0, 107) . '…' : $s;
    }
}
