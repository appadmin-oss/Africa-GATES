<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Reading the transcript: what the nominee actually said, sorted by what it bears on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THIS IS THE HALF A HUMAN PANEL IS WORST AT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A judge with fourteen nominees does not re-read fourteen forty-minute transcripts. They
 * remember the two people who interviewed well and the one who was hard to hear, and they
 * score from that. Everything said in minute thirty-one by somebody with a weak connection
 * is gone.
 *
 * So the model reads every word, and the output is organised by CRITERION — not as a
 * summary. A summary is a second opinion a judge has to either accept or ignore. A list of
 * what the nominee said that bears on Impact, in their own words, with the surrounding
 * line, is raw material a judge can score from without being told what to think.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THERE IS NO SUGGESTED SCORE HERE, AND THAT IS DELIBERATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It would be four lines of code and it is the single most damaging thing this file could
 * do. A judge shown "Impact: 7/10 (suggested)" scores 7. That is not a criticism of judges;
 * it is what an anchor does, and this platform already accepted the finding once: the
 * ballot used to be ordered by vote count, the number was never rendered, and the ORDER
 * alone was judged enough of an anchor to be worth removing — per judge, deterministically,
 * so position bias cancels across a panel instead of accumulating.
 *
 * A number carries far more weight than an ordering. And unlike the ordering, it would
 * arrive dressed as analysis, from a model that has read the transcript and therefore looks
 * better informed than the judge reading it. The 55% expert half of the CPI would in
 * practice be set by whichever model had a free tier.
 *
 * So: quotes, gaps, contradictions and coverage. No numbers, no verdict, no ranking, and
 * nothing that writes to `gates_judge_criteria_scores` — that table has exactly one class
 * of writer, and it is judges.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT FLAGS WITHOUT ANY MODEL AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two things, deterministically, and they are the two most useful:
 *
 *  • FIGURES THAT MOVED. The nomination claims 500 pupils; the nominee says fifty. That is
 *    the highest-value finding in the whole file and it is a string comparison, not
 *    intelligence. It is raised as a DISCREPANCY and never as dishonesty — a nominator
 *    inflating a number, a mishearing by the machine transcriber, and a nominee being
 *    careful all produce the identical signal, and only a person can tell them apart.
 *
 *  • QUESTIONS THAT WERE NEVER ANSWERED. The console records what was asked; the pack
 *    records what should have been. A criterion the panel meant to test and did not is a
 *    hole in the score, and it is invisible unless somebody counts.
 *
 * A machine transcript makes the first check less reliable, not more — a model mishears
 * proper nouns and numbers, which are precisely the load-bearing facts. That is why
 * `transcript_source` exists on the row, and why every discrepancy note says to check the
 * recording before treating it as a finding.
 */
final class InterviewReview
{
    public const JOB = 'interview.review';

    /** Transcript characters sent to a model. Beyond this we send the head and the tail. */
    private const MAX_CHARS = 12000;

    public static function queue(int $id): void
    {
        try {
            (new QueueService())->push(self::JOB, ['interview_id' => $id], 0, 'review:' . $id);
        } catch (\Throwable $e) {
            error_log('[interview] could not queue the review for ' . $id . ': ' . $e->getMessage());
        }
    }

    /** The stored review, or [] if none. */
    public static function forInterview(int $id): array
    {
        $raw = DB::table('gates_interviews')->where('id', $id)->value('review_json');
        $r = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($r) ? $r : [];
    }

    /**
     * The deterministic half, immediately, with no network call.
     *
     * Called inline by {@see InterviewService::publish()} so the operator who just pressed
     * publish SEES the figure check rather than an empty panel that fills in later — and so
     * that a platform whose queue is not draining still gets the two most valuable findings.
     * That is not hypothetical: the maintenance schedule was off by default on this
     * deployment until it was made self-healing, and the console already builds its question
     * pack inline for exactly the same reason.
     *
     * The queued {@see run()} overwrites this with the model's reading when one answers.
     */
    public static function quick(int $id): array
    {
        return self::run($id, null, true);
    }

    /**
     * Read the transcript and store the findings.
     *
     * @param bool $rulesOnly skip the model entirely — see {@see quick()}
     * @return array{ok:bool, source:string, message:string}
     */
    public static function run(int $id, ?AiGateway $gateway = null, bool $rulesOnly = false): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['ok' => false, 'source' => '', 'message' => 'That interview could not be found.'];

        $transcript = InterviewService::transcriptOf($id);
        if (trim($transcript) === '') {
            return ['ok' => false, 'source' => '',
                    'message' => 'There is no published transcript to read.'];
        }

        $criteria = self::criteria($id);
        $pack     = InterviewBrief::forInterview($id);
        $answers  = json_decode((string) ($iv->answers_json ?? '[]'), true);
        $answers  = is_array($answers) ? $answers : [];

        // The two deterministic checks first, so a model failure cannot cost them.
        $review = [
            'read_at'        => Carbon::now()->toDateTimeString(),
            'source'         => 'rules',
            'words'          => str_word_count(strip_tags($transcript)),
            'machine'        => self::isMachine($id),
            'figures'        => self::figureCheck($id, $transcript),
            'unanswered'     => self::unanswered($pack, $answers),
            'criteria'       => self::rulesByCriterion($criteria, $transcript, self::nomineeName($id)),
            'panel_notes'    => self::panelNotes($answers),
        ];

        $ai = $rulesOnly
            ? []
            : self::fromModel($gateway ?? new AiGateway(), $criteria, $transcript, (int) $iv->nominee_id);
        if ($ai !== []) {
            // The model's per-criterion evidence REPLACES the keyword pass, which is a
            // crude stand-in for exactly this. The deterministic checks above are kept
            // whatever happens: they answer different questions.
            $review['criteria'] = $ai['criteria'] ?? $review['criteria'];
            $review['gaps']     = $ai['gaps'] ?? [];
            $review['source']   = 'ai';
        }

        DB::table('gates_interviews')->where('id', $id)->update([
            'review_json'   => json_encode($review),
            'review_at'     => Carbon::now()->toDateTimeString(),
            'review_source' => (string) $review['source'],
            'updated_at'    => Carbon::now()->toDateTimeString(),
        ]);

        return ['ok' => true, 'source' => (string) $review['source'],
                'message' => 'Transcript read (' . $review['source'] . ').'];
    }

    // ══ the deterministic checks ══════════════════════════════════════════════

    /**
     * Every figure claimed in the nomination, and whether it survived the interview.
     *
     * @return list<array{claim:string, figure:string, said:string, status:string, note:string}>
     */
    public static function figureCheck(int $id, string $transcript): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return [];

        $spoken = self::numbersIn($transcript);
        $out    = [];
        $seen   = [];

        foreach (self::nominationTexts((int) $iv->nominee_id) as $text) {
            foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $s) {
                $s = trim(preg_replace('/\s+/u', ' ', (string) $s) ?? '');
                if ($s === '' || mb_strlen($s) < 20) continue;
                if (!preg_match_all('/\b(\d[\d,\.]*)\b\s*([a-z]+)?/iu', $s, $m, PREG_SET_ORDER)) continue;

                foreach ($m as $hit) {
                    $n = self::toNumber($hit[1]);
                    if ($n === null || $n < 10) continue;
                    if (self::isYear($n, $hit[1])) continue;

                    // A DURATION is not a quantity of anything. "She has taught for 11 years"
                    // was being compared against "30 pupils" in the transcript and reported as
                    // a figure that had changed — a finding manufactured out of two unrelated
                    // numbers, and the sort of thing that makes an operator stop trusting
                    // every other row on the screen.
                    if (self::isDuration($hit[2] ?? '')) continue;

                    // One row per figure. The nominee's story and the nomination reason state
                    // the same claim twice, and two identical rows read as two problems.
                    if (isset($seen[$n])) continue;
                    $seen[$n] = true;

                    [$status, $said, $note] = self::verdictFor($n, $spoken);

                    $out[] = ['claim' => mb_substr($s, 0, 300), 'figure' => (string) $n,
                              'said' => $said, 'status' => $status, 'note' => $note];
                    if (count($out) >= 10) return $out;
                }
            }
        }
        return $out;
    }

    /**
     * What the transcript did with one claimed figure.
     *
     * @param list<int> $spoken
     * @return array{0:string, 1:string, 2:string}
     */
    private static function verdictFor(int $n, array $spoken): array
    {
        if (in_array($n, $spoken, true)) {
            return ['confirmed', (string) $n, 'The same figure was said in the interview.'];
        }

        // Within a sixth either way is the same claim rounded — "640" said as "about 600".
        // Reporting that as a discrepancy trains an operator to ignore the whole list.
        $near = null;
        $far  = null;
        foreach ($spoken as $sp) {
            if ($sp <= 0) continue;
            $ratio = $sp > $n ? $sp / $n : $n / $sp;
            if ($ratio <= 1.17) { $near = $sp; break; }
            if ($far === null && $ratio <= 20) $far = $sp;
        }

        if ($near !== null) {
            return ['confirmed', (string) $near,
                    'Said as ' . $near . ' in the interview — the same claim, rounded.'];
        }
        if ($far !== null) {
            return ['differs', (string) $far,
                    'The nomination says ' . $n . '; the closest figure in the interview is '
                    . $far . '. This may be a correction by the nominee, an inflated nomination, '
                    . 'or the transcriber mishearing a number — check the recording before '
                    . 'treating it as a finding.'];
        }
        return ['unmentioned', '',
                'This figure was never mentioned in the interview. It was not necessarily '
                . 'asked about.'];
    }

    /** A bare 1900–2100 is a year, not a quantity. A comma ("2,019 pupils") makes it one. */
    private static function isYear(int $n, string $raw): bool
    {
        return $n >= 1900 && $n <= 2100 && !str_contains($raw, ',');
    }

    private static function isDuration(string $unit): bool
    {
        return in_array(mb_strtolower(trim($unit)), [
            'year', 'years', 'month', 'months', 'week', 'weeks', 'day', 'days',
            'decade', 'decades', 'hour', 'hours', 'minute', 'minutes',
        ], true);
    }

    /**
     * Questions in the pack that the console never recorded an answer for.
     *
     * @return list<array{criterion:string, q:string}>
     */
    public static function unanswered(array $pack, array $answers): array
    {
        $done = [];
        foreach ($answers as $a) {
            $note = trim((string) ($a['note'] ?? ''));
            // An empty note is not an answer. A panel that opened a question and typed
            // nothing has covered nothing, and counting it would hide the gap.
            if ($note !== '') $done[(string) ($a['key'] ?? '')] = true;
        }

        $out = [];
        foreach (($pack['questions'] ?? []) as $q) {
            if (isset($done[(string) ($q['key'] ?? '')])) continue;
            $out[] = ['criterion' => (string) ($q['criterion'] ?? ''),
                      'q'         => (string) ($q['q'] ?? '')];
        }
        return $out;
    }

    /**
     * A keyword pass per criterion, used when no model answered.
     *
     * Crude on purpose and labelled as such on the screen. It finds the lines a judge would
     * find with ctrl-F, which is more than the nothing they have today, and it never claims
     * to have understood anything.
     *
     * @param list<array<string,mixed>> $criteria
     * @return list<array<string,mixed>>
     */
    private static function rulesByCriterion(array $criteria, string $transcript, string $nominee = ''): array
    {
        $lines = self::lines($transcript, $nominee);
        $out   = [];

        foreach ($criteria as $c) {
            $slug  = (string) ($c['slug'] ?? '');
            $words = self::keywords($slug, (string) ($c['label'] ?? ''), (string) ($c['description'] ?? ''));
            $hits  = [];

            foreach ($lines as $line) {
                $low = mb_strtolower($line);
                foreach ($words as $w) {
                    if ($w !== '' && str_contains($low, $w)) {
                        $hits[] = ['quote' => mb_substr($line, 0, 400), 'note' => ''];
                        break;
                    }
                }
                if (count($hits) >= 4) break;
            }

            // The caveat about wording-matching belongs on the section heading, not repeated
            // under every criterion — printed four times it stopped being a caveat and
            // became wallpaper the operator scrolls past.
            $out[] = [
                'criterion'    => (string) ($c['label'] ?? ''),
                'criterion_id' => isset($c['id']) ? (int) $c['id'] : null,
                'found'        => $hits !== [],
                'quotes'       => $hits,
                'summary'      => $hits === []
                    ? 'Nothing in the transcript obviously touches this criterion. That may mean it '
                    . 'was not asked about rather than that there is nothing to say.'
                    : '',
            ];
        }
        return $out;
    }

    /** What the panel typed in the room, kept beside the transcript. */
    private static function panelNotes(array $answers): array
    {
        $out = [];
        foreach ($answers as $a) {
            $note = trim((string) ($a['note'] ?? ''));
            if ($note === '') continue;
            $out[] = [
                'q'    => mb_substr((string) ($a['question'] ?? ''), 0, 300),
                'note' => mb_substr($note, 0, 2000),
                'flag' => (string) ($a['flag'] ?? ''),
                'at'   => (string) ($a['at'] ?? ''),
            ];
        }
        return $out;
    }

    // ══ the model path ═══════════════════════════════════════════════════════

    /**
     * Ask a model to sort the transcript by criterion, quoting rather than paraphrasing.
     *
     * @param list<array<string,mixed>> $criteria
     * @return array{criteria?:list<array<string,mixed>>, gaps?:list<string>}
     */
    private static function fromModel(AiGateway $gw, array $criteria, string $transcript, int $nomineeId): array
    {
        if ($criteria === []) return [];

        $labels = array_values(array_filter(array_map(
            static fn (array $c): string => trim((string) ($c['label'] ?? '')), $criteria)));
        if ($labels === []) return [];

        $rubric = [];
        foreach ($criteria as $c) {
            $rubric[] = '- ' . (string) ($c['label'] ?? '') . ': ' . trim((string) ($c['description'] ?? ''));
        }

        $system = "You read an interview transcript for an awards panel and sort what the nominee said "
                . "by which scoring criterion it bears on.\n\n"
                . "ABSOLUTE RULES:\n"
                . "- QUOTE the nominee. Do not paraphrase, summarise or improve their words. If a "
                . "sentence is broken or unclear, quote it broken.\n"
                . "- Give NO score, rating, grade, band, percentage or ranking, and no opinion about "
                . "how strong or weak an answer was. You are indexing evidence, not judging it. A "
                . "panel of humans decides.\n"
                . "- Never invent a quote. If nothing in the transcript bears on a criterion, say so "
                . "for that criterion and give no quotes.\n"
                . "- Do not repair the nominee's grammar or make them sound more impressive. A "
                . "transcript is a chain of interpretations already.\n"
                . "- Note where an answer was vague or where a follow-up was clearly needed, as a "
                . "FACT about the conversation ('no timeframe was given'), never as a criticism of "
                . "the person.\n\n"
                . "Return ONLY JSON:\n"
                . "{\"criteria\":[{\"criterion\":\"<exact rubric label>\",\"found\":true,"
                . "\"quotes\":[{\"quote\":\"<their words>\",\"note\":\"<what it establishes, one "
                . "sentence>\"}],\"summary\":\"<what the transcript contains on this criterion, "
                . "factual, no evaluation>\"}],\"gaps\":[\"<something the panel did not establish>\"]}";

        $trusted = "Criteria (use these labels exactly):\n" . implode("\n", $rubric);

        $res = $gw->run('interview.review', [
            'system'       => $system,
            'trusted'      => $trusted,
            'user'         => "Transcript:\n" . self::trim($transcript),
            'json'         => true,
            'subject_type' => 'nominee',
            'subject_id'   => $nomineeId,
            'schema'       => static function (string $raw) use ($criteria): ?array {
                $data = json_decode(self::unfence($raw), true);
                if (!is_array($data) || !is_array($data['criteria'] ?? null)) return null;

                $byLabel = [];
                foreach ($criteria as $c) {
                    $byLabel[mb_strtolower(trim((string) ($c['label'] ?? '')))] = $c;
                }

                $out = [];
                foreach ($data['criteria'] as $row) {
                    if (!is_array($row)) continue;
                    $crit = $byLabel[mb_strtolower(trim((string) ($row['criterion'] ?? '')))] ?? null;
                    if ($crit === null) continue;

                    $quotes = [];
                    foreach ((array) ($row['quotes'] ?? []) as $q) {
                        $text = trim((string) (is_array($q) ? ($q['quote'] ?? '') : $q));
                        if ($text === '' || mb_strlen($text) > 600) continue;
                        $quotes[] = ['quote' => $text,
                                     'note'  => mb_substr(trim((string) (is_array($q) ? ($q['note'] ?? '') : '')), 0, 300)];
                        if (count($quotes) >= 6) break;
                    }

                    // A model that ignored the no-scoring rule loses the whole row rather
                    // than having a number quietly stripped: if it scored here it may have
                    // reasoned toward a verdict everywhere, and the panel should see the
                    // keyword pass instead of a laundered opinion.
                    $summary = mb_substr(trim((string) ($row['summary'] ?? '')), 0, 800);
                    if (self::looksLikeAScore($summary)) return null;

                    $out[] = [
                        'criterion'    => (string) ($crit['label'] ?? ''),
                        'criterion_id' => isset($crit['id']) ? (int) $crit['id'] : null,
                        'found'        => $quotes !== [],
                        'quotes'       => $quotes,
                        'summary'      => $summary,
                    ];
                }
                if ($out === []) return null;

                $gaps = [];
                foreach ((array) ($data['gaps'] ?? []) as $g) {
                    $g = trim((string) $g);
                    if ($g !== '' && mb_strlen($g) <= 300) $gaps[] = $g;
                    if (count($gaps) >= 8) break;
                }

                return ['criteria' => $out, 'gaps' => $gaps];
            },
        ]);

        return $res->ok && is_array($res->value) ? $res->value : [];
    }

    /**
     * Does this text contain a score after we asked for none?
     *
     * Catches "7/10", "8 out of 10", "scores well", "rating: high", "band 3". Deliberately
     * eager: a false positive costs the keyword fallback, a false negative anchors a judge.
     */
    public static function looksLikeAScore(string $text): bool
    {
        if ($text === '') return false;
        return (bool) preg_match(
            '~\b\d{1,2}\s*(?:/|out of)\s*10\b'
            . '|\b(?:score|scores|scored|scoring|rating|rated|grade|graded|band)\b'
            . '|\b\d{1,3}\s*%|\b(?:strong|weak|excellent|poor)\s+(?:candidate|nominee|answer)\b~i',
            $text
        );
    }

    // ══ plumbing ═════════════════════════════════════════════════════════════

    /**
     * Every number spoken in the transcript, EXCLUDING years.
     *
     * Excluding them matters more than it sounds. "2019" was being offered as the closest
     * figure to a claim of 640 pupils — 2019/640 is a ratio of 3.15, which passed the
     * order-of-magnitude test — so a transcript that said "we started in 2019" produced a
     * discrepancy against a number it has nothing to do with.
     *
     * @return list<int>
     */
    private static function numbersIn(string $text): array
    {
        $out = [];
        if (preg_match_all('/\b(\d[\d,\.]*)\b\s*([a-z]+)?/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $n = self::toNumber($hit[1]);
                if ($n === null) continue;
                if (self::isYear($n, $hit[1])) continue;
                if (self::isDuration($hit[2] ?? '')) continue;
                $out[] = $n;
            }
        }
        // Spoken numbers, COMPOSED rather than matched word by word. See wordNumbers().
        foreach (self::wordNumbers($text) as $n) $out[] = $n;

        return array_values(array_unique($out));
    }

    /**
     * Numbers written out in words, composed into their actual value.
     *
     * ── WHY THIS IS NOT A LOOKUP TABLE ───────────────────────────────────────
     *
     * It was. Each number word mapped to a value, and any word present in the transcript put
     * its value in the pool. Run against a real captured interview, a nominee who said
     * "about three hundred and twenty girls, across six schools" contributed 100 and 20 to
     * the pool — and the figure check then reported the nomination's 320 as a DISCREPANCY,
     * because 20 was the closest thing it could find.
     *
     * A false "this figure changed" against a nominee is the most damaging output this file
     * can produce: it goes in front of the panel deciding their award, and it is precisely
     * the row an operator would take seriously. So the words are parsed the way they are
     * spoken — "three hundred and twenty" is one number, and it is 320.
     *
     * @return list<int>
     */
    public static function wordNumbers(string $text): array
    {
        $units = [
            'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
            'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
            'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fourty' => 40,
            'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
        ];
        $mult = ['hundred' => 100, 'thousand' => 1000, 'million' => 1000000];

        $out     = [];
        $total   = 0;      // accumulated thousands/millions
        $current = 0;      // the group being built
        $seen    = false;

        $flush = static function () use (&$out, &$total, &$current, &$seen): void {
            if ($seen) {
                $n = $total + $current;
                if ($n > 0) $out[] = $n;
            }
            $total = 0; $current = 0; $seen = false;
        };

        foreach (preg_split('/[^a-z]+/i', mb_strtolower($text)) ?: [] as $w) {
            if ($w === '') continue;
            if ($w === 'and' && $seen) continue;                 // "three hundred and twenty"
            if (isset($units[$w])) { $current += $units[$w]; $seen = true; continue; }
            if (isset($mult[$w])) {
                $m = $mult[$w];
                if ($m === 100) {
                    $current = max($current, 1) * 100;
                } else {
                    $total += max($total + $current, 1) === 1 && $current === 0
                        ? $m                                     // "a thousand"
                        : max($current, 1) * $m;
                    $current = 0;
                }
                $seen = true;
                continue;
            }
            $flush();
        }
        $flush();

        return array_values(array_unique($out));
    }

    private static function toNumber(string $raw): ?int
    {
        $clean = str_replace(',', '', $raw);
        if (!is_numeric($clean)) return null;
        $n = (int) round((float) $clean);
        return $n > 0 ? $n : null;
    }

    /** @return list<string> */
    private static function nominationTexts(int $nomineeId): array
    {
        $out = [];
        try {
            $n = DB::table('gates_nominees')->where('id', $nomineeId)->first(['name', 'category_id', 'story']);
            if (!$n) return [];
            $story = trim((string) ($n->story ?? ''));
            if ($story !== '') $out[] = $story;

            $q = DB::table('gates_nominations')->where('status', 'approved')
                ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [mb_strtolower(trim((string) $n->name))]);
            if (($n->category_id ?? null) !== null) $q->where('category_id', (int) $n->category_id);
            foreach ($q->get(['reason']) as $r) {
                $reason = trim((string) ($r->reason ?? ''));
                if ($reason !== '') $out[] = $reason;
            }
        } catch (\Throwable $e) {
            error_log('[interview] could not read nomination text: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * The transcript as lines, with anything said by somebody OTHER than the nominee dropped.
     *
     * This is not tidying. The keyword pass was surfacing "Interviewer: What is different
     * about how you teach it?" as evidence under Originality — a judge reading that sees the
     * panel's own question presented as the nominee's answer, and the one thing this whole
     * feature exists to put in front of a judge is the nominee's own words.
     *
     * A speaker label is only trusted when there IS one: Google's transcripts label every
     * participant, a hand-typed one often labels nobody. So a leading `Name:` is dropped when
     * it does not match the nominee, and an unlabelled transcript is left whole rather than
     * being guessed at.
     *
     * @return list<string>
     */
    private static function lines(string $transcript, string $nominee = ''): array
    {
        // Words from the nominee's name, so "Chidera:", "Chidera Nwosu:" and "Ms Nwosu:" all
        // read as the nominee.
        $own = [];
        foreach (preg_split('/\s+/u', mb_strtolower(trim($nominee))) ?: [] as $w) {
            if (mb_strlen($w) >= 3) $own[] = $w;
        }

        $out = [];
        foreach (preg_split('/\r?\n|(?<=[.!?])\s+/u', $transcript) ?: [] as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', (string) $l) ?? '');
            if (mb_strlen($l) < 15) continue;

            // A leading label of at most four words, then a colon.
            if ($own !== [] && preg_match('/^([^:]{1,40}):\s*(.+)$/u', $l, $m)
                && count(preg_split('/\s+/u', trim($m[1])) ?: []) <= 4) {
                $label = mb_strtolower($m[1]);
                $mine  = false;
                foreach ($own as $w) {
                    if (str_contains($label, $w)) { $mine = true; break; }
                }
                if (!$mine) continue;                     // somebody else was talking
                $l = trim($m[2]);                         // and drop the label from the quote
                if (mb_strlen($l) < 15) continue;
            }
            $out[] = $l;
        }
        return $out;
    }

    /** @return list<string> */
    private static function keywords(string $slug, string $label, string $description): array
    {
        // Widened after reading a real transcript against the first version of these lists.
        // A nominee who said "we build things from scrap instead of using kits" matched
        // nothing under Originality, and "I told the parents myself and we restarted"
        // matched nothing under Integrity — so the two criteria a panel most often has to
        // dig for were the two reported as untouched. Recall matters more than precision
        // here: a wrong line costs a judge four seconds, a missing one costs the evidence.
        $base = match ($slug) {
            'impact' => ['changed', 'change', 'helped', 'improved', 'better', 'pupils', 'students',
                         'children', 'people', 'result', 'because of', 'passed', 'now study', 'went on to'],
            'originality' => ['first', 'new', 'different', 'differently', 'invented', 'designed', 'idea',
                              'nobody', 'instead', 'rather than', 'our own', 'ourselves', 'nobody else',
                              'we build', 'we made', 'we started doing'],
            'reach' => ['state', 'states', 'country', 'countries', 'school', 'schools', 'communities',
                        'across', 'spread', 'other', 'branches', 'villages', 'towns', 'region'],
            'integrity' => ['own money', 'my own', 'salary', 'pocket', 'volunteer', 'unpaid', 'record',
                            'register', 'receipt', 'receipts', 'audit', 'account', 'honest', 'wrong',
                            'mistake', 'failed', 'lost', 'told the', 'admitted', 'apolog', 'signed',
                            'donated', 'donation'],
            default => [],
        };
        // Words from the criterion's own label and description, so a programme with its own
        // rubric is not left with an empty keyword list.
        foreach (preg_split('/[^a-z]+/i', mb_strtolower($label . ' ' . $description)) ?: [] as $w) {
            if (mb_strlen($w) >= 5) $base[] = $w;
        }
        return array_values(array_unique(array_filter($base)));
    }

    /** @return list<array<string,mixed>> */
    private static function criteria(int $interviewId): array
    {
        $iv = InterviewService::byId($interviewId);
        if (!$iv) return [];
        try {
            $p = DB::table('gates_award_categories as c')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->where('c.id', (int) ($iv->category_id ?? 0))->value('cy.programme_id');
            return (new \AfricaGates\Judge\Services\JudgeService())->criteria($p ? (int) $p : 0);
        } catch (\Throwable $e) {
            error_log('[interview] could not read the rubric for review: ' . $e->getMessage());
            return [];
        }
    }

    /** The nominee's name, so their own lines can be told from the panel's. */
    private static function nomineeName(int $id): string
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return '';
        return (string) (DB::table('gates_nominees')->where('id', (int) $iv->nominee_id)->value('name') ?? '');
    }

    private static function isMachine(int $id): bool
    {
        $iv = InterviewService::byId($id);
        if (!$iv || empty($iv->transcript_id)) return false;
        $src = DB::table('gates_nominee_interviews')->where('id', (int) $iv->transcript_id)
            ->value('transcript_source');
        return in_array((string) $src, ['machine', 'hybrid'], true);
    }

    /**
     * Head and tail when a transcript is longer than the budget.
     *
     * The middle is what gets dropped because the opening (framing, the first substantive
     * answer) and the close (what they wanted to add) carry the most, and because a model
     * given a truncated head alone reports the interview ended early.
     */
    private static function trim(string $t): string
    {
        if (mb_strlen($t) <= self::MAX_CHARS) return $t;
        $half = (int) floor(self::MAX_CHARS / 2) - 40;
        return mb_substr($t, 0, $half)
             . "\n\n[… the middle of this transcript was too long to send and was left out …]\n\n"
             . mb_substr($t, -$half);
    }

    private static function unfence(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw);
        }
        return trim($raw);
    }
}
