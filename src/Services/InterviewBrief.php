<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The question pack: what to ask this nominee, and which part of the rubric it tests.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE QUESTIONS ARE PER-NOMINEE AND NOT A FIXED LIST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A panel with one standard list asks everybody "tell us about your impact", and gets
 * back the fluency of the speaker. The nominee who has rehearsed does well; the teacher
 * who has run a feeding programme for nine years and never been interviewed does badly.
 * That is a measurement of confidence dressed up as a measurement of work.
 *
 * The questions that actually separate them are the ones built from what is already on
 * file. "Your nomination says you reached 500 pupils across three states — how was that
 * counted, and who else could confirm it?" cannot be answered well by a fluent stranger
 * and can be answered easily by the person who did it. So every claim carrying a NUMBER
 * is quoted back verbatim and made into a question, deterministically, before any model
 * is involved.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT WORKS WITH NO AI KEY, AND THAT IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The platform has been here before. Twenty-four working support tools sat behind a check
 * for an AI provider key, so a site with no key had a support desk that could do nothing
 * but apologise. The same mistake here would mean a panel opening the console the morning
 * of an interview and finding an empty page.
 *
 * So the rules path is the real path: the rubric supplies the criteria, the dossier
 * supplies the claims, and a pack of grounded questions comes out with no network call at
 * all. A model, when one is configured, adds questions the rules cannot invent and
 * sharpens the phrasing — and `brief_source` records which one the panel is holding,
 * because they are entitled to know.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE MODEL IS NOT SHOWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Vote counts, rank, CPI, and any other judge's score. The dossier arrives through
 * {@see EvidenceService}, which strips {@see EvidenceService::FORBIDDEN_FIELDS} at the
 * boundary rather than merely not rendering them.
 *
 * This is not decoration. Interview questions shaped by popularity would carry the
 * community's 45% into the panel's 55% while looking like independent expert enquiry —
 * the same collapse the ballot's shuffled ordering exists to prevent, arriving through a
 * door nobody was watching.
 *
 * Contact details are stripped by {@see AiPrivacy} before anything is sent, as with every
 * other capability that touches public text.
 */
final class InterviewBrief
{
    public const JOB = 'interview.brief';

    /** A sitting is 30 minutes. More than this many questions is a script nobody finishes. */
    public const MAX_QUESTIONS = 12;

    /** Questions of each kind, so one loud claim cannot crowd out a whole criterion. */
    private const MAX_CLAIM_QUESTIONS = 4;

    public static function queue(int $id): void
    {
        try {
            (new QueueService())->push(self::JOB, ['interview_id' => $id], 0, 'brief:' . $id);
        } catch (\Throwable $e) {
            error_log('[interview] could not queue the brief for ' . $id . ': ' . $e->getMessage());
        }
    }

    /** The stored pack, or [] when none has been built yet. */
    public static function forInterview(int $id): array
    {
        $raw = DB::table('gates_interviews')->where('id', $id)->value('brief_json');
        $pack = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($pack) ? $pack : [];
    }

    /**
     * The themes it is fair to tell the nominee in advance.
     *
     * The themes, never the questions. A nominee handed the exact wording is interviewed
     * on their rehearsal; a nominee told nothing walks into an ambush and the panel
     * measures composure. What is fair to share is what the conversation is ABOUT — which
     * is the rubric, and the rubric is already published.
     *
     * @return list<string>
     */
    public static function themes(int $id): array
    {
        $pack = self::forInterview($id);
        $out  = [];
        foreach (($pack['questions'] ?? []) as $q) {
            $label = trim((string) ($q['criterion'] ?? ''));
            if ($label !== '' && !in_array($label, $out, true)) $out[] = $label;
        }
        if ($out !== []) return $out;

        // No pack yet: the rubric is still the honest answer.
        $iv = InterviewService::byId($id);
        if (!$iv) return [];
        return array_values(array_filter(array_map(
            static fn (array $c): string => trim((string) ($c['label'] ?? '')),
            self::criteria(self::programmeOf((int) ($iv->category_id ?? 0)))
        )));
    }

    /**
     * Build (or rebuild) the pack and store it.
     *
     * @return array{ok:bool, source:string, questions:int, message:string}
     */
    public static function build(int $id, ?AiGateway $gateway = null): array
    {
        $iv = InterviewService::byId($id);
        if (!$iv) return ['ok' => false, 'source' => '', 'questions' => 0,
                          'message' => 'That interview could not be found.'];

        $nomineeId = (int) $iv->nominee_id;
        $nominee   = DB::table('gates_nominees')->where('id', $nomineeId)->first();
        if (!$nominee) return ['ok' => false, 'source' => '', 'questions' => 0,
                               'message' => 'That nominee could not be found.'];

        $programmeId = self::programmeOf((int) ($iv->category_id ?? 0));
        $criteria    = self::criteria($programmeId);
        $facts       = self::facts($nomineeId);

        $questions = self::fromRules($criteria, $facts, (string) $nominee->name);
        $source    = 'rules';

        // The model adds what the rules cannot invent: a question about the SPECIFIC thing
        // this person did, in the words they used. It never replaces the claim questions —
        // those are quoted from the record and are the ones that cannot be bluffed.
        $added = self::fromModel($gateway ?? new AiGateway(), $criteria, $facts,
                                 (string) $nominee->name, $nomineeId);
        if ($added !== []) {
            $questions = self::merge($questions, $added);
            $source    = 'ai';
        }

        $questions = array_slice($questions, 0, self::MAX_QUESTIONS);

        $pack = [
            'built_at'  => Carbon::now()->toDateTimeString(),
            'source'    => $source,
            'opening'   => self::opening((string) $nominee->name, !empty($iv->consent_at)),
            'questions' => $questions,
            'closing'   => 'Is there anything the panel has not asked about that we should know? '
                         . 'And is there anyone else who could speak to this work?',
            'coverage'  => $facts['coverage'],
            'warnings'  => self::warnings($facts, $criteria, $questions),
        ];

        DB::table('gates_interviews')->where('id', $id)->update([
            'brief_json'   => json_encode($pack),
            'brief_at'     => Carbon::now()->toDateTimeString(),
            'brief_source' => $source,
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);

        return ['ok' => true, 'source' => $source, 'questions' => count($questions),
                'message' => count($questions) . ' questions prepared (' . $source . ').'];
    }

    // ══ the rules path ═══════════════════════════════════════════════════════

    /**
     * Grounded questions, no network.
     *
     * Order matters: the claim questions come first. They are the ones worth the time if
     * the call runs short, and a panel that works down the list in order should hit them
     * before the general rubric questions.
     *
     * @param list<array<string,mixed>> $criteria
     * @return list<array<string,mixed>>
     */
    private static function fromRules(array $criteria, array $facts, string $name): array
    {
        $out = [];

        // 1. Every claim with a number in it, quoted back.
        $n = 0;
        foreach ($facts['claims'] as $claim) {
            if ($n >= self::MAX_CLAIM_QUESTIONS) break;
            $crit = self::criterionFor($criteria, $claim['hint']);
            $out[] = [
                'key'          => 'claim-' . (++$n),
                'criterion'    => (string) ($crit['label'] ?? 'Impact'),
                'criterion_id' => isset($crit['id']) ? (int) $crit['id'] : null,
                'q'            => 'The nomination says: "' . $claim['text'] . '" '
                                . 'How was that counted, and who else could confirm it?',
                'why'          => 'A figure from the nomination, tested against the person who '
                                . 'supposedly produced it. Somebody who did the work can say how they '
                                . 'counted; somebody repeating a claim cannot.',
                'probe'        => ['Over what period?',
                                   'Is it written down anywhere we could see?',
                                   'Who kept the record?'],
                'source'       => 'claim',
            ];
        }

        // 2. One question per criterion, so no part of the rubric is scored on nothing.
        foreach ($criteria as $c) {
            $slug  = (string) ($c['slug'] ?? '');
            $label = (string) ($c['label'] ?? 'Criterion');
            $out[] = [
                'key'          => 'crit-' . ($slug !== '' ? $slug : (string) ($c['id'] ?? '')),
                'criterion'    => $label,
                'criterion_id' => isset($c['id']) ? (int) $c['id'] : null,
                'q'            => self::rubricQuestion($slug, $label, (string) ($c['description'] ?? '')),
                'why'          => 'This is the ' . $label . ' criterion, worth '
                                . (int) ($c['weight'] ?? 25) . '% of the panel score. '
                                . trim((string) ($c['description'] ?? '')),
                'probe'        => self::rubricProbes($slug),
                'source'       => 'rubric',
            ];
        }

        // 3. What the dossier is MISSING. A gap is a question, not a deduction — the
        //    nominee may simply never have been asked for the document.
        if (!$facts['coverage']['has_verified']) {
            $out[] = [
                'key'          => 'gap-verify',
                'criterion'    => self::labelOfSlug($criteria, 'integrity', 'Integrity'),
                'criterion_id' => self::idOfSlug($criteria, 'integrity'),
                'q'            => 'Nothing in your file has been independently checked yet. What could '
                                . 'you send us that somebody outside your organisation could confirm — '
                                . 'a letter, a report, a published mention, a record from a school or '
                                . 'ministry?',
                'why'          => 'The dossier carries only the nominator\'s written case. This asks for '
                                . 'something checkable rather than penalising the nominee for a gap '
                                . 'they were never asked to fill.',
                'probe'        => ['Who signed it?', 'Could we contact them?'],
                'source'       => 'gap',
            ];
        }

        return $out;
    }

    /** A rubric question that is answerable by a person who did the work. */
    private static function rubricQuestion(string $slug, string $label, string $description): string
    {
        return match ($slug) {
            'impact' =>
                'Think of one person or one place that is different because of your work. '
                . 'Tell us what changed for them, and how you know.',
            'originality' =>
                'What did you do differently from how it was being done before you started — '
                . 'and what made you try it that way?',
            'reach' =>
                'Where has this actually gone beyond where you started? Name the places, and say '
                . 'who is running it there now.',
            'integrity' =>
                'Tell us about a time this work went wrong, or cost you something. What did you do, '
                . 'and who was told?',
            default =>
                'On ' . $label . ' — ' . rtrim($description, '. ') . '. '
                . 'What is the strongest example you can give, and how could it be checked?',
        };
    }

    /** @return list<string> */
    private static function rubricProbes(string $slug): array
    {
        return match ($slug) {
            'impact'      => ['How many others like them?', 'What would have happened otherwise?'],
            'originality' => ['Who else does it this way now?', 'What did you try first that failed?'],
            'reach'       => ['Is it still running there without you?', 'How did it get there?'],
            'integrity'   => ['Who holds you to account?', 'What would you not do, even if it worked?'],
            default       => ['Can you give a specific example?', 'How could that be checked?'],
        };
    }

    // ══ the model path ═══════════════════════════════════════════════════════

    /**
     * Ask a model for questions the rules could not invent.
     *
     * Advisory and skippable by design: on refusal, budget exhaustion, a bad shape or no
     * provider at all, the rules pack stands and the panel is never told to wait.
     *
     * @param list<array<string,mixed>> $criteria
     * @return list<array<string,mixed>>
     */
    private static function fromModel(AiGateway $gw, array $criteria, array $facts,
                                      string $name, int $nomineeId): array
    {
        if ($criteria === []) return [];

        $rubric = [];
        foreach ($criteria as $c) {
            $rubric[] = '- ' . (string) ($c['label'] ?? '') . ': ' . trim((string) ($c['description'] ?? ''));
        }

        $system = "You prepare interview questions for an African awards panel interviewing a nominee "
                . "over video.\n\n"
                . "The panel must be able to tell the person who DID the work from a person who can "
                . "describe it well. So every question must be answerable easily by the doer and badly "
                . "by an impostor: ask for specifics, names, numbers, dates, failures and records — "
                . "never for opinions, self-assessment, or how they feel about their achievements.\n\n"
                . "Rules:\n"
                . "- Write for someone who may be nervous and speaking their second or third language. "
                . "Short sentences. No jargon, no compound questions.\n"
                . "- Never ask anything that could not be answered in under two minutes.\n"
                . "- Never ask about money, votes, popularity, politics, religion, health or family.\n"
                . "- Do not congratulate, flatter or evaluate. You are writing questions, not praise.\n\n"
                . "Return ONLY JSON: {\"questions\":[{\"criterion\":\"<one of the rubric labels, exactly>\","
                . "\"q\":\"<the question>\",\"why\":\"<what it tests, one sentence, for the panel>\","
                . "\"probe\":[\"<short follow-up>\",\"<short follow-up>\"]}]} — at most 6 questions.";

        $trusted = "Award criteria (use these labels exactly):\n" . implode("\n", $rubric);

        // The nominee's own file. Fenced as untrusted, because a nomination reason is text
        // a member of the public wrote and this is the injection-exposed half of the call.
        $untrusted = "Nominee: " . $name . "\n"
                   . ($facts['organisation'] !== '' ? 'Organisation: ' . $facts['organisation'] . "\n" : '')
                   . ($facts['country'] !== '' ? 'Country: ' . $facts['country'] . "\n" : '')
                   . "\nWhat their file says:\n" . $facts['digest'];

        $res = $gw->run('interview.brief', [
            'system'       => $system,
            'trusted'      => $trusted,
            'user'         => $untrusted,
            'json'         => true,
            'subject_type' => 'nominee',
            'subject_id'   => $nomineeId,
            'schema'       => static function (string $raw) use ($criteria): ?array {
                $data = json_decode(self::unfence($raw), true);
                if (!is_array($data) || !is_array($data['questions'] ?? null)) return null;

                $byLabel = [];
                foreach ($criteria as $c) {
                    $byLabel[mb_strtolower(trim((string) ($c['label'] ?? '')))] = $c;
                }

                $out = [];
                foreach ($data['questions'] as $i => $q) {
                    if (!is_array($q)) continue;
                    $text = trim((string) ($q['q'] ?? ''));
                    if (mb_strlen($text) < 12 || mb_strlen($text) > 400) continue;

                    // A criterion the rubric does not have is dropped, not guessed at: a
                    // question filed under an invented heading would show the panel a
                    // criterion that does not exist and cannot be scored.
                    $crit = $byLabel[mb_strtolower(trim((string) ($q['criterion'] ?? '')))] ?? null;
                    if ($crit === null) continue;

                    $probe = [];
                    foreach ((array) ($q['probe'] ?? []) as $p) {
                        $p = trim((string) $p);
                        if ($p !== '' && mb_strlen($p) <= 160) $probe[] = $p;
                        if (count($probe) >= 3) break;
                    }

                    $out[] = [
                        'key'          => 'ai-' . ($i + 1),
                        'criterion'    => (string) ($crit['label'] ?? ''),
                        'criterion_id' => isset($crit['id']) ? (int) $crit['id'] : null,
                        'q'            => $text,
                        'why'          => mb_substr(trim((string) ($q['why'] ?? '')), 0, 300),
                        'probe'        => $probe,
                        'source'       => 'ai',
                    ];
                    if (count($out) >= 6) break;
                }
                return $out !== [] ? $out : null;
            },
        ]);

        return $res->ok && is_array($res->value) ? $res->value : [];
    }

    /**
     * Interleave: claim questions first, then one AI question per criterion, then the
     * rubric fallbacks for criteria the model did not cover.
     *
     * A criterion covered by a specific AI question does not also need the generic rubric
     * one — asking both is how a 30-minute call becomes an hour and the panel stops
     * listening.
     *
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $ai
     * @return list<array<string,mixed>>
     */
    private static function merge(array $rules, array $ai): array
    {
        $covered = [];
        foreach ($ai as $q) {
            $covered[mb_strtolower((string) ($q['criterion'] ?? ''))] = true;
        }

        $claims = $gaps = $rubric = [];
        foreach ($rules as $q) {
            $src = (string) ($q['source'] ?? '');
            if ($src === 'claim') { $claims[] = $q; continue; }
            if ($src === 'gap')   { $gaps[] = $q;   continue; }
            if (isset($covered[mb_strtolower((string) ($q['criterion'] ?? ''))])) continue;
            $rubric[] = $q;
        }
        return array_merge($claims, $ai, $rubric, $gaps);
    }

    // ══ the dossier ══════════════════════════════════════════════════════════

    /**
     * What is on file about this nominee, popularity stripped.
     *
     * @return array{claims:list<array{text:string,hint:string}>, digest:string,
     *               organisation:string, country:string, coverage:array<string,mixed>}
     */
    private static function facts(int $nomineeId): array
    {
        $n = DB::table('gates_nominees')->where('id', $nomineeId)
            ->first(['name', 'organisation', 'country_code', 'story', 'tagline']);

        $dossier = ['items' => [], 'coverage' => ['has_verified' => false, 'has_interview' => false, 'items' => 0]];
        try {
            $dossier = (new EvidenceService())->forJudge($nomineeId);
        } catch (\Throwable $e) {
            error_log('[interview] could not read the dossier for ' . $nomineeId . ': ' . $e->getMessage());
        }

        $texts = [];
        foreach (($dossier['items'] ?? []) as $item) {
            $body = trim((string) ($item['body'] ?? ''));
            if ($body !== '') $texts[] = $body;
        }
        $story = trim((string) ($n->story ?? ''));
        if ($story !== '' && !in_array($story, $texts, true)) $texts[] = $story;

        $digest = mb_substr(implode("\n\n", $texts), 0, 4000);

        return [
            'claims'       => self::claims($texts),
            'digest'       => $digest !== '' ? $digest : '(nothing beyond a name is on file)',
            'organisation' => (string) ($n->organisation ?? ''),
            'country'      => strtoupper((string) ($n->country_code ?? '')),
            'coverage'     => is_array($dossier['coverage'] ?? null)
                                ? $dossier['coverage']
                                : ['has_verified' => false, 'has_interview' => false, 'items' => 0],
        ];
    }

    /**
     * Sentences carrying a checkable number, in the order they appear.
     *
     * Deliberately narrow. A sentence with a figure in it is a claim somebody can be asked
     * to stand behind; a sentence of praise is not, and quoting praise back at a nominee
     * asks them to agree with their own compliment.
     *
     * @param list<string> $texts
     * @return list<array{text:string,hint:string}>
     */
    private static function claims(array $texts): array
    {
        $out  = [];
        $seen = [];
        foreach ($texts as $text) {
            // Split on sentence ends, keeping it simple: this runs on prose typed into a
            // web form, not on structured input.
            foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $s) {
                $s = trim(preg_replace('/\s+/u', ' ', (string) $s) ?? '');
                if ($s === '' || mb_strlen($s) < 25 || mb_strlen($s) > 300) continue;

                // A digit run of 2+, a written quantity, or a percentage. A lone "1" or a
                // year on its own is not a claim worth quoting back.
                if (!preg_match('/\b\d{2,}\b|\b\d+%|\b(?:hundreds?|thousands?|millions?|dozens?)\b/iu', $s)) continue;
                // Years alone ("since 2019") are context, not a countable claim.
                if (preg_match('/^\D*\b(?:19|20)\d{2}\b\D*$/u', $s)) continue;

                // A DURATION is not a quantity of anything. "She has taught for 11 years"
                // read back as "how was that counted, and who else could confirm it?" is a
                // question that makes the panel look foolish and the nominee defensive —
                // and it was the first question in the pack until this ran for real.
                if (self::onlyDuration($s)) continue;

                // Dedup on the FIGURES rather than the wording. The nominee's story and the
                // nomination reason routinely restate the same claim in different words, and
                // asking the same number twice wastes the most valuable minutes of a sitting.
                $figures = [];
                if (preg_match_all('/\b\d[\d,]*\b/u', $s, $fm)) {
                    foreach ($fm[0] as $f) {
                        $f = str_replace(',', '', $f);
                        // A YEAR is dropped from the key. "640 pupils across 14 schools" and
                        // "640 pupils across 14 schools since 2019" are one claim told twice,
                        // and keying on the year kept them as two — which is exactly what
                        // the story and the nomination reason do to each other in practice.
                        if (preg_match('/^(?:19|20)\d{2}$/', $f)) continue;
                        $figures[] = $f;
                    }
                    $figures = array_unique($figures);
                    sort($figures);
                }
                $key = $figures !== []
                    ? 'fig:' . implode('-', $figures)
                    : mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $s));
                if ($key === '' || isset($seen[$key])) continue;
                $seen[$key] = true;

                $out[] = ['text' => $s, 'hint' => self::hintOf($s)];
                if (count($out) >= 12) break 2;
            }
        }
        return $out;
    }

    /**
     * True when every figure in the sentence is a length of time.
     *
     * "She has taught for 11 years" and "the club ran for 18 months" describe duration, and
     * "how was that counted?" is not a question anybody can answer about a duration. A
     * sentence that ALSO carries a count ("in 11 years she has taught 640 pupils") is kept,
     * because the count is the claim.
     */
    private static function onlyDuration(string $sentence): bool
    {
        if (!preg_match_all('/\b(\d[\d,]*)\b(?:\s*(?:\+|plus))?\s*([a-z]+)?/iu', $sentence, $m, PREG_SET_ORDER)) {
            return false;
        }
        $units = ['year', 'years', 'month', 'months', 'week', 'weeks', 'day', 'days',
                  'decade', 'decades', 'hour', 'hours'];
        $sawFigure = false;
        foreach ($m as $hit) {
            $n = (int) str_replace(',', '', $hit[1]);
            if ($n < 10) continue;                          // too small to be a claim anyway
            $sawFigure = true;
            if (!in_array(mb_strtolower($hit[2] ?? ''), $units, true)) return false;
        }
        return $sawFigure;
    }

    /** Which criterion a claim most plausibly belongs to, from its own words. */
    private static function hintOf(string $sentence): string
    {
        $s = mb_strtolower($sentence);
        if (preg_match('/\b(states?|countries|nationwide|africa|regions?|communities|schools?|branch(es)?|chapters?)\b/u', $s)) {
            return 'reach';
        }
        if (preg_match('/\b(first|invented|designed|created|developed|pioneer|new (?:approach|method|way)|novel)\b/u', $s)) {
            return 'originality';
        }
        if (preg_match('/\b(audit|transparen|account|volunteer|unpaid|own (?:money|pocket)|report(ed|s)?)\b/u', $s)) {
            return 'integrity';
        }
        return 'impact';
    }

    /** @param list<array<string,mixed>> $criteria */
    private static function criterionFor(array $criteria, string $slug): array
    {
        foreach ($criteria as $c) {
            if ((string) ($c['slug'] ?? '') === $slug) return $c;
        }
        return $criteria[0] ?? [];
    }

    private static function labelOfSlug(array $criteria, string $slug, string $fallback): string
    {
        foreach ($criteria as $c) {
            if ((string) ($c['slug'] ?? '') === $slug) return (string) ($c['label'] ?? $fallback);
        }
        return $fallback;
    }

    private static function idOfSlug(array $criteria, string $slug): ?int
    {
        foreach ($criteria as $c) {
            if ((string) ($c['slug'] ?? '') === $slug) return isset($c['id']) ? (int) $c['id'] : null;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private static function criteria(int $programmeId): array
    {
        try {
            return (new \AfricaGates\Judge\Services\JudgeService())->criteria($programmeId);
        } catch (\Throwable $e) {
            error_log('[interview] could not read the rubric: ' . $e->getMessage());
            return [];
        }
    }

    private static function programmeOf(int $categoryId): int
    {
        if ($categoryId <= 0) return 0;
        try {
            $p = DB::table('gates_award_categories as c')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->where('c.id', $categoryId)->value('cy.programme_id');
            return $p ? (int) $p : 0;
        } catch (\Throwable) { return 0; }
    }

    /**
     * What the panel says first — and it is mostly about consent.
     *
     * A nominee who pressed a button on a web page three days ago has not necessarily
     * understood that a machine will write down what they say and that a panel will read
     * it. Saying it out loud, in the room, costs fifteen seconds.
     */
    private static function opening(string $name, bool $consented): string
    {
        return 'Welcome, ' . $name . '. This is a conversation, not a test — we want to hear about '
             . 'your work from you rather than only from the person who nominated you. There are no '
             . 'trick questions and nothing you say can lose you the nomination.'
             . ($consented
                ? ' You have given us permission to record and transcribe this, and the panel will '
                . 'read the transcript. Tell us at any point if you would rather something was not '
                . 'written down.'
                : ' WE DO NOT HAVE YOUR WRITTEN PERMISSION TO RECORD THIS, so ask for it now if you '
                . 'want a transcript kept — otherwise nothing from this call will be written down '
                . 'for the panel beyond our own notes.');
    }

    /** Honest limits of the pack, printed on the console rather than left to be discovered. */
    private static function warnings(array $facts, array $criteria, array $questions): array
    {
        $w = [];
        if ($criteria === []) {
            $w[] = 'No scoring criteria are set up for this programme, so these questions are not '
                 . 'mapped to anything the panel will score.';
        }
        if ($facts['claims'] === []) {
            $w[] = 'Nothing on file carries a checkable figure, so none of these questions can be '
                 . 'tested against the record. The dossier is a written case and nothing more.';
        }
        // Judged on the text the pack was actually BUILT from, not on the dossier's own item
        // count. Those differ more often than they should: EvidenceService hides a
        // nomination whose `reason_status` is not 'approved', which is every row written
        // before that column existed — so a nominee with a paragraph of detail in the
        // record was being announced to the panel as having nothing on file at all, beside
        // a question quoting that very paragraph.
        if (($facts['digest'] ?? '') === '' || str_starts_with((string) $facts['digest'], '(nothing')) {
            $w[] = 'This nominee has no text on file at all — no approved nomination reason and no '
                 . 'evidence. Treat every answer as new information.';
        } elseif (($facts['coverage']['items'] ?? 0) === 0) {
            $w[] = 'The panel\'s dossier for this nominee is empty even though there is text on '
                 . 'file — usually a nomination whose reason has not been approved for display. '
                 . 'Judges will see less than these questions assume.';
        }
        if (count($questions) < 4) {
            $w[] = 'Only ' . count($questions) . ' questions could be prepared. There is very little '
                 . 'on file to build from.';
        }
        return $w;
    }

    /**
     * A model asked for JSON sometimes returns it inside a code fence, and json_decode
     * refuses the whole thing. Handled here rather than at each schema.
     */
    private static function unfence(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $raw);
        }
        return trim($raw);
    }
}
