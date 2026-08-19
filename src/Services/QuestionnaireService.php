<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The questionnaire a nominee answers about their own work, per award programme.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE MOST IMPORTANT THING IN A DOSSIER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A judge scores four criteria out of ten. Until the interview stage shipped, every word on
 * their ballot was written by somebody else — a nominator's paragraph, a category, a
 * photograph. The interview added what a nominee SAYS. This adds what they can SHOW: the
 * letter from the ministry, the report with the numbers in it, the photograph of the borehole,
 * the head teacher who will confirm it. Things that exist, that only they have, and that
 * nobody has ever been able to ask them for.
 *
 * `gates_nominee_evidence` has had `provenance = 'nominee_supplied'` as a first-class value
 * since the day it was created, and not one row has ever carried it, because the table has
 * never had a writer. {@see publishEvidence()} is that writer.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DEFAULT QUESTIONS ARE DERIVED FROM THE RUBRIC, NOT INVENTED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A platform running one programme must not have to design a questionnaire before it can send
 * one — that is how a feature ships and is never used. So the default set is generated from
 * `gates_judge_criteria`: one question per criterion, in the criterion's own words, plus the
 * questions that apply to any award. A programme that wants its own overrides by slug,
 * exactly as the rubric itself already supports per-programme overrides.
 *
 * Every default asks for something CHECKABLE. "Tell us about your impact" measures fluency;
 * "how many people, over what period, and who keeps that record?" measures the work. The same
 * principle {@see InterviewBrief} is built on, applied to writing rather than speech.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT REFUSES TO DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * MARK ANYTHING AS VERIFIED. Every row it writes lands with `verified = 0`, whatever the
 * nominee attached. Verification means a person outside this platform checked it, and a
 * self-uploaded PDF is not that. The dossier already distinguishes the two and a judge is
 * shown which is which — writing `verified = 1` here would launder a claim into a finding.
 *
 * WRITE A SCORE. Answers do not touch `gates_judge_criteria_scores`. They arrive as evidence
 * next to the nominator's case, labelled with who is asserting them, and a judge decides.
 *
 * ASK FOR MONEY, OR ANYTHING IRRELEVANT. No bank details, no payment, no ID numbers, no
 * date of birth. The one thing an awards scheme is most often impersonated for is a fee, and
 * the nominee's page says so in as many words.
 */
final class QuestionnaireService
{
    /** How many separate works a nominee may list. Enough for a career, short of a spreadsheet. */
    public const MAX_WORKS = 12;

    /** Files per submission, and the ceiling per file in megabytes. */
    public const MAX_FILES   = 8;
    public const MAX_FILE_MB = 10;

    /**
     * The default questionnaire, for a programme that has not written its own.
     *
     * Each entry: slug, kind, label, help, required, max_len, evidence_kind, criterion slug.
     * The criterion slug is resolved to an id at read time, so a programme with its own
     * rubric still gets its answers filed against the right criteria.
     *
     * @return list<array<string,mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['slug' => 'summary', 'kind' => 'textarea', 'criterion' => '',
             'label' => 'In your own words, what is the work you are being recognised for?',
             'help'  => 'Two or three sentences. Write it as you would explain it to a neighbour, '
                      . 'not as an application.',
             'required' => 1, 'max_len' => 900, 'evidence_kind' => 'note'],

            // min_words 0: "Since 2021." is a complete answer, and nagging somebody for
            // answering correctly is how a questionnaire loses their trust on question two.
            ['slug' => 'started', 'kind' => 'text', 'criterion' => '',
             'min_words' => 0,
             'label' => 'When did it start, and is it still running?',
             'help'  => 'A month and year is enough. If it has stopped, say when and why — a '
                      . 'finished piece of work is not a lesser one.',
             'required' => 1, 'max_len' => 200, 'evidence_kind' => 'note'],

            // wants_number: an impact claim without a figure cannot be weighed, and the coach
            // says so beside the box rather than on the last screen.
            ['slug' => 'impact_numbers', 'kind' => 'textarea', 'criterion' => 'impact',
             'wants_number' => 1,
             'label' => 'How many people has it reached, over what period — and who keeps that record?',
             'help'  => 'Give the number you can stand behind rather than the largest one. Say how '
                      . 'it is counted and who holds the register, list or file. A figure with a '
                      . 'source behind it is worth far more to a judge than a bigger figure without one.',
             'required' => 1, 'max_len' => 1200, 'evidence_kind' => 'note'],

            // Asked ONLY when the figures answer had no number in it. Somebody who has already
            // written "1,240 farmers across 8 states" has given a panel what it needs; asking
            // them to also produce an anecdote is the form failing to read their answer.
            ['slug' => 'impact_one', 'kind' => 'textarea', 'criterion' => 'impact',
             'show_if_slug' => 'impact_numbers', 'show_if' => 'no_number',
             'label' => 'Tell us about one person or one place that is different because of this work.',
             'help'  => 'One story, with what changed and how you know. You may leave out names if '
                      . 'they would rather not be named.',
             'required' => 0, 'max_len' => 1200, 'evidence_kind' => 'note'],

            ['slug' => 'originality', 'kind' => 'textarea', 'criterion' => 'originality',
             'label' => 'What did you do differently from how it was being done before you started?',
             'help'  => 'Including what you tried first that did not work. Judges read that as '
                      . 'strength, not weakness.',
             'required' => 0, 'max_len' => 1200, 'evidence_kind' => 'note'],

            ['slug' => 'reach', 'kind' => 'textarea', 'criterion' => 'reach',
             'wants_number' => 0,
             'label' => 'Where has this spread beyond where it began? Name the places.',
             'help'  => 'Towns, states, countries, or other organisations that copied it. Say who '
                      . 'runs it in each place now — if it continues without you, say so.',
             'required' => 0, 'max_len' => 1200, 'evidence_kind' => 'note'],

            // Skipped when they have said the work has ended. Asking how a closed project is
            // funded, in the present tense, is the clearest signal a form is not listening —
            // and `reads()` deliberately treats an ambiguous answer as "ask", so a nominee
            // whose school closed but whose clinic continues still gets the question.
            ['slug' => 'integrity', 'kind' => 'textarea', 'criterion' => 'integrity',
             'show_if_slug' => 'started', 'show_if' => 'yes',
             'label' => 'Who holds you accountable, and how is the work funded?',
             'help'  => 'A board, a community committee, a school, a partner, or simply your own '
                      . 'records. If you fund it yourself, say that — most people in this position do.',
             'required' => 0, 'max_len' => 1200, 'evidence_kind' => 'note'],

            ['slug' => 'setback', 'kind' => 'textarea', 'criterion' => 'integrity',
             'label' => 'Tell us about a time this work went wrong, or cost you something.',
             'help'  => 'What you did about it, and who you told. Nobody has ever lost an award '
                      . 'for answering this honestly.',
             'required' => 0, 'max_len' => 1200, 'evidence_kind' => 'note'],

            ['slug' => 'referees', 'kind' => 'textarea', 'criterion' => 'integrity',
             'label' => 'Who outside your own organisation could confirm this work?',
             'help'  => 'One or two people, with their role and how to reach them. We contact them '
                      . 'only if the panel asks, and never without telling you first.',
             'required' => 0, 'max_len' => 600, 'evidence_kind' => 'note'],

            ['slug' => 'coverage', 'kind' => 'url', 'criterion' => 'reach',
             'label' => 'A link to the work, or to coverage of it (optional)',
             'help'  => 'A website, a news article, a video, a social media page, a published '
                      . 'report. Add more of these below under “your works”.',
             'required' => 0, 'max_len' => 500, 'evidence_kind' => 'link'],

            ['slug' => 'anything_else', 'kind' => 'textarea', 'criterion' => '',
             'label' => 'Anything the panel should know that we have not asked about?',
             'help'  => 'Optional, and it is read.',
             'required' => 0, 'max_len' => 1200, 'evidence_kind' => 'note'],
        ];
    }

    // ══ 1. the questions ═════════════════════════════════════════════════════

    /**
     * The questionnaire for a programme: its own questions where it has them, the defaults
     * otherwise, and never a mixture that asks the same thing twice.
     *
     * @return list<array<string,mixed>>
     */
    public static function questions(int $programmeId): array
    {
        $criteria = self::criteriaBySlug($programmeId);

        $rows = [];
        try {
            $rows = DB::table('gates_programme_questions')
                ->where('is_active', 1)
                ->where(function ($q) use ($programmeId) {
                    $q->where('programme_id', $programmeId)->orWhereNull('programme_id');
                })
                ->orderBy('sort_order')->orderBy('id')
                ->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not read questions: ' . $e->getMessage());
        }

        // Programme-specific beats global on the same slug — the rule gates_judge_criteria
        // already uses for the rubric, so an operator only has to learn it once.
        $bySlug = [];
        foreach ($rows as $r) {
            $slug = (string) ($r['slug'] ?? '');
            if ($slug === '') continue;
            if (!isset($bySlug[$slug]) || !empty($r['programme_id'])) $bySlug[$slug] = $r;
        }

        if ($bySlug !== []) {
            // The criterion's LABEL and the select options resolved here rather than in the
            // template. Twig has no json_decode filter, and adding one so a template could
            // parse a database column would put parsing in the layer least able to say
            // anything useful when the value is malformed.
            $byId = [];
            foreach ($criteria as $c) $byId[(int) $c['id']] = (string) $c['label'];
            foreach ($bySlug as $slug => $r) {
                $opts = json_decode((string) ($r['options_json'] ?? ''), true);
                $bySlug[$slug]['options']   = is_array($opts)
                    ? array_values(array_map('strval', $opts)) : [];
                $bySlug[$slug]['criterion'] = $byId[(int) ($r['criterion_id'] ?? 0)] ?? '';
            }
            return array_values($bySlug);
        }

        // Nothing stored: the defaults, resolved against this programme's rubric. Returned
        // rather than inserted, so a platform that never opens the editor still has a
        // working questionnaire and no rows nobody asked for.
        $out = [];
        foreach (self::defaults() as $i => $d) {
            $critSlug = (string) $d['criterion'];
            $out[] = [
                'id'            => null,
                'slug'          => (string) $d['slug'],
                'kind'          => (string) $d['kind'],
                'label'         => (string) $d['label'],
                'help'          => (string) $d['help'],
                'placeholder'   => '',
                'options_json'  => null,
                'criterion_id'  => $critSlug !== '' ? ($criteria[$critSlug]['id'] ?? null) : null,
                'criterion'     => $critSlug !== '' ? (string) ($criteria[$critSlug]['label'] ?? '') : '',
                'evidence_kind' => (string) $d['evidence_kind'],
                'is_required'   => (int) $d['required'],
                'max_len'       => (int) $d['max_len'],
                'sort_order'    => $i + 1,
                'options'       => [],
                // Branching and coaching hints travel with the defaults, so a platform that
                // never opens the question editor still gets an adaptive questionnaire rather
                // than the flat eleven. See QuestionnaireRules for the vocabulary.
                'show_if_slug'  => $d['show_if_slug'] ?? null,
                'show_if'       => $d['show_if'] ?? null,
                'min_words'     => $d['min_words'] ?? null,
                'wants_number'  => $d['wants_number'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * The questions that actually apply to ONE submission, given what it already says.
     *
     * ── WHY EVERY CALLER MUST GO THROUGH HERE ────────────────────────────────
     *
     * Branching is only safe if it is applied consistently. The chat asks the next question,
     * the form renders them all, `submit()` decides which are required, `progress()` counts
     * them and `publishEvidence()` files them — and if any one of those used the unfiltered
     * list, the questionnaire would contradict itself in the worst possible place:
     *
     *   • submit() unfiltered → a required question that was never ASKED blocks sending, and
     *     the nominee is told to answer something no screen will show them. A dead end with
     *     no way out except support.
     *   • progress() unfiltered → "4 of 11" that can never reach 11, so the bar promises a
     *     finish line that does not exist.
     *
     * So the filtering lives in one method and the callers take a submission rather than a
     * programme id. {@see QuestionnaireRules} holds the vocabulary.
     *
     * @return list<array<string,mixed>>
     */
    public static function questionsFor(?object $s): array
    {
        if ($s === null) return [];
        $all = self::questions((int) ($s->programme_id ?? 0));

        $answers = json_decode((string) ($s->answers_json ?? '{}'), true);
        $answers = is_array($answers) ? array_map('strval', $answers) : [];

        return QuestionnaireRules::filter($all, $answers);
    }

    /**
     * Write the defaults into the table for one programme, so they can be edited.
     *
     * Only ever called from the admin editor, and only when that programme has nothing —
     * seeding on read would fill the table on the first page view of a platform that may
     * never want to change a word.
     */
    public static function seedDefaults(int $programmeId): int
    {
        $existing = DB::table('gates_programme_questions')->where('programme_id', $programmeId)->count();
        if ($existing > 0) return 0;

        $criteria = self::criteriaBySlug($programmeId);
        $now = Carbon::now()->toDateTimeString();
        $n = 0;
        foreach (self::defaults() as $i => $d) {
            $critSlug = (string) $d['criterion'];
            DB::table('gates_programme_questions')->insert([
                'programme_id'  => $programmeId,
                'slug'          => (string) $d['slug'],
                'kind'          => (string) $d['kind'],
                'label'         => (string) $d['label'],
                'help'          => (string) $d['help'],
                'criterion_id'  => $critSlug !== '' ? ($criteria[$critSlug]['id'] ?? null) : null,
                'evidence_kind' => (string) $d['evidence_kind'],
                'is_required'   => (int) $d['required'],
                'max_len'       => (int) $d['max_len'],
                'sort_order'    => $i + 1,
                'is_active'     => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
                // ── THE BRANCHING TRAVELS WITH THEM ──────────────────────────
                //
                // It did not, and the consequence was invisible. The defaults branch; the
                // stored copy did not; and copying them in is the ONLY way to change a single
                // word of any question. So the first operator to fix a typo silently turned an
                // adaptive questionnaire into a flat one, and from then on a nominee who had
                // said their project closed in 2019 was asked, in the present tense, how it is
                // funded — the exact failure the adaptive work was done to remove.
                'show_if_slug'  => $d['show_if_slug'] ?? null,
                'show_if'       => $d['show_if'] ?? null,
                'min_words'     => $d['min_words'] ?? null,
                'wants_number'  => (int) ($d['wants_number'] ?? 0),
            ]);
            $n++;
        }
        return $n;
    }

    /** @return array<string, array{id:int,label:string}> */
    private static function criteriaBySlug(int $programmeId): array
    {
        $out = [];
        try {
            foreach ((new \AfricaGates\Judge\Services\JudgeService())->criteria($programmeId) as $c) {
                $slug = (string) ($c['slug'] ?? '');
                if ($slug !== '') {
                    $out[$slug] = ['id' => (int) ($c['id'] ?? 0), 'label' => (string) ($c['label'] ?? '')];
                }
            }
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not read the rubric: ' . $e->getMessage());
        }
        return $out;
    }

    // ══ 2. opening a submission ══════════════════════════════════════════════

    /**
     * Open (or find) a nominee's submission and return its id and token.
     *
     * @return array{ok:bool, id?:int, token?:string, message:string}
     */
    public static function open(int $nomineeId, ?int $adminId = null): array
    {
        $n = DB::table('gates_nominees as n')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->where('n.id', $nomineeId)
            ->select('n.id', 'n.name', 'n.merged_into', 'c.cycle_id', 'cy.programme_id')
            ->first();
        if (!$n) return ['ok' => false, 'message' => 'That nominee could not be found.'];
        if (!empty($n->merged_into)) {
            return ['ok' => false, 'message' => 'That nominee has been merged into another profile.'];
        }

        $existing = DB::table('gates_nominee_submissions')
            ->where('nominee_id', $nomineeId)
            ->where('cycle_id', $n->cycle_id)->first();
        if ($existing) {
            return ['ok' => true, 'id' => (int) $existing->id,
                    'token' => (string) $existing->invite_token,
                    'message' => 'This nominee already has a questionnaire (#' . $existing->id . ').'];
        }

        $now = Carbon::now()->toDateTimeString();
        try {
            $id = (int) DB::table('gates_nominee_submissions')->insertGetId([
                'nominee_id'   => $nomineeId,
                'programme_id' => $n->programme_id !== null ? (int) $n->programme_id : null,
                'cycle_id'     => $n->cycle_id !== null ? (int) $n->cycle_id : null,
                'invite_token' => bin2hex(random_bytes(16)),
                'status'       => 'draft',
                'created_by'   => $adminId,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not open a submission for ' . $nomineeId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The questionnaire could not be created just now.'];
        }

        return ['ok' => true, 'id' => $id, 'token' => (string) self::tokenFor($id),
                'message' => 'Questionnaire #' . $id . ' opened for ' . $n->name . '.'];
    }

    /**
     * A questionnaire an administrator answers themselves, to see what a nominee sees.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * Before it, the only way to find out what this thing actually feels like was to open one
     * against a real nominee: a live token, a row in the summary, a person the queue now shows
     * as asked, and on submit a set of evidence rows written into that person's judging
     * dossier. The only way to rehearse was to contaminate the record you were rehearsing for
     * — so nobody rehearsed, and the first person to meet a confusing question was a nominee.
     *
     * A test behaves like the real thing in every way that is worth testing. It renders the
     * same page, runs the same conversation, reads the same questions for the chosen
     * programme, takes files, and can be submitted. Three things it cannot do:
     *
     *   • reach a judge — {@see publishEvidence()} refuses, so submitting writes nothing;
     *   • be invited — there is no nominee, and {@see invite()} says so rather than mailing
     *     somebody's address from the last thing an admin was looking at;
     *   • be counted — {@see summary()} keeps it out of every real figure.
     *
     * @return array{ok:bool, id?:int, token?:string, message:string}
     */
    public static function openTest(?int $programmeId = null, ?int $adminId = null,
                                    string $label = ''): array
    {
        $label = trim($label);
        if ($label === '') $label = 'Test nominee';

        $programme = null;
        if ($programmeId !== null && $programmeId > 0) {
            $exists = DB::table('gates_award_programmes')->where('id', $programmeId)->exists();
            if (!$exists) return ['ok' => false, 'message' => 'That award programme could not be found.'];
            $programme = $programmeId;
        }

        $now = Carbon::now()->toDateTimeString();
        try {
            $id = (int) DB::table('gates_nominee_submissions')->insertGetId([
                // Zero, never a real id: the column is NOT NULL and auto-increment starts at
                // one, so every reader that joins on it finds nothing. A test cannot attach
                // itself to a person.
                'nominee_id'   => 0,
                'programme_id' => $programme,
                // NULL so the UNIQUE (nominee_id, cycle_id) that protects real submissions does
                // not have to be weakened to allow several tests: NULLs compare as distinct in
                // a unique index on both drivers.
                'cycle_id'     => null,
                'invite_token' => bin2hex(random_bytes(16)),
                'status'       => 'draft',
                'is_test'      => 1,
                'test_label'   => mb_substr($label, 0, 120),
                'created_by'   => $adminId,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not open a test: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The test questionnaire could not be created just now. '
                                              . 'If this deployment has not run the latest migration, '
                                              . 'open /__setup/migrate first.'];
        }

        return ['ok' => true, 'id' => $id, 'token' => (string) self::tokenFor($id),
                'message' => 'Test questionnaire #' . $id . ' created. Open the link and answer it as '
                           . 'a nominee would — nothing you write in it reaches a judge.'];
    }

    /** True for a rehearsal. The one question every guard in here asks. */
    public static function isTest(?object $s): bool
    {
        return $s !== null && (int) ($s->is_test ?? 0) === 1;
    }

    /**
     * Delete a test outright, rows and all.
     *
     * Deletable in a way a real submission is not, and that asymmetry is the point: a real
     * submission is somebody's account of their own work and is re-opened rather than
     * destroyed, whereas a rehearsal left lying around is clutter on a screen whose whole job
     * is to show what needs a person's attention.
     *
     * Refuses anything that is not a test, so a mistyped id cannot delete a nominee's answers.
     *
     * @return array{ok:bool, message:string}
     */
    public static function deleteTest(int $id): array
    {
        $s = self::byId($id);
        if (!$s) return ['ok' => false, 'message' => 'That questionnaire could not be found.'];
        if (!self::isTest($s)) {
            return ['ok' => false, 'message' => 'That is a real nominee\'s questionnaire, not a test. '
                                              . 'Re-open it if it needs changing; it is not deleted.'];
        }

        try {
            DB::table('gates_nominee_submissions')->where('id', $id)->where('is_test', 1)->delete();
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not delete test ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That test could not be deleted just now.'];
        }
        return ['ok' => true, 'message' => 'Test questionnaire #' . $id . ' deleted.'];
    }

    public static function byId(int $id): ?object
    {
        try { return DB::table('gates_nominee_submissions')->where('id', $id)->first() ?: null; }
        catch (\Throwable) { return null; }
    }

    public static function byToken(string $token): ?object
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        try { return DB::table('gates_nominee_submissions')->where('invite_token', $token)->first() ?: null; }
        catch (\Throwable) { return null; }
    }

    public static function tokenFor(int $id): ?string
    {
        $t = DB::table('gates_nominee_submissions')->where('id', $id)->value('invite_token');
        return $t ? (string) $t : null;
    }

    public static function url(int $id, string $base = ''): string
    {
        $base = rtrim($base !== '' ? $base : SiteUrl::base(), '/');
        $t = self::tokenFor($id);
        return $t ? $base . '/my-work/' . $t : $base . '/my-work';
    }

    // ══ 3. the nominee's form ════════════════════════════════════════════════

    /**
     * Everything the nominee's page needs, from the token alone.
     *
     * Carries no other nominee, no scores, no judge, and no contact details beyond the name
     * of the person whose page it is.
     */
    public static function formFor(string $token): ?array
    {
        $s = self::byToken($token);
        if (!$s) return null;
        $n = DB::table('gates_nominees as n')
            ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', (int) $s->nominee_id)
            ->select('n.name', 'n.organisation', 'c.title as category')
            ->first();

        $programme = '';
        if (!empty($s->programme_id)) {
            $programme = (string) (DB::table('gates_award_programmes')
                ->where('id', (int) $s->programme_id)->value('title') ?? '');
        }

        $answers = json_decode((string) ($s->answers_json ?? '{}'), true);
        $works   = json_decode((string) ($s->works_json ?? '[]'), true);

        return [
            'id'         => (int) $s->id,
            'token'      => (string) $s->invite_token,
            'nominee'    => self::isTest($s)
                ? (trim((string) ($s->test_label ?? '')) ?: 'Test nominee')
                : (string) ($n->name ?? 'Nominee'),
            // Rendered as a banner on the nominee's page, so that if a test link is ever pasted
            // into a message by mistake the person who opens it is told what it is instead of
            // being asked to describe work nobody will read.
            'is_test'    => self::isTest($s),
            'category'   => (string) ($n->category ?? ''),
            'programme'  => $programme,
            // The id as well as the title: the brief is per programme, and a template cannot
            // look one up from a name.
            'programme_id' => (int) ($s->programme_id ?? 0),
            'status'     => (string) $s->status,
            'submitted'  => $s->status === 'submitted',
            'submitted_at' => (string) ($s->submitted_at ?? ''),
            'declared_name' => (string) ($s->declared_name ?? ''),
            'questions'  => self::questionsFor($s),
            'answers'    => is_array($answers) ? $answers : [],
            'works'      => is_array($works) ? array_values(array_filter($works, 'is_array')) : [],
            'max_works'  => self::MAX_WORKS,
            'max_files'  => self::MAX_FILES,
            'max_file_mb' => self::MAX_FILE_MB,
            'deadline'   => self::deadline((int) ($s->cycle_id ?? 0)),
        ];
    }

    /**
     * When the answers stop being useful: the close of judging for this cycle.
     *
     * Shown to the nominee because "there is no rush" and "this closes on Friday" produce
     * very different behaviour, and only one of them is true.
     */
    public static function deadline(int $cycleId): string
    {
        if ($cycleId <= 0) return '';
        try {
            $c = DB::table('gates_award_cycles')->where('id', $cycleId)
                ->first(['results_date', 'voting_close', 'status']);
            if (!$c) return '';
            $raw = trim((string) ($c->results_date ?? $c->voting_close ?? ''));
            if ($raw === '') return '';
            // "15 October 2026", not "2026-10-15 18:00:00". A nominee reading a date with
            // seconds on it is reading a database column, and the seconds are noise on a
            // deadline that is really about which day.
            try { return Carbon::parse($raw)->format('j F Y'); }
            catch (\Throwable) { return $raw; }
        } catch (\Throwable) { return ''; }
    }

    /**
     * Save a draft. Never validates required fields — that is {@see submit()}'s job.
     *
     * A form that refuses to save half an answer is a form people abandon. The population
     * here is filling this in on a phone, between other work, over several days.
     *
     * @param array<string,mixed> $answers
     * @param list<array<string,mixed>> $works
     * @return array{ok:bool, message:string}
     */
    public static function saveDraft(string $token, array $answers, array $works): array
    {
        $s = self::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];
        if ($s->status === 'submitted') {
            return ['ok' => false, 'message' => 'This has already been sent to the panel. '
                                             . 'Write to us if something needs changing.'];
        }
        if ($s->status === 'withdrawn') {
            return ['ok' => false, 'message' => 'This questionnaire has been closed.'];
        }

        $clean = self::cleanAnswers((int) ($s->programme_id ?? 0), $answers);
        $keptWorks = self::cleanWorks($works, is_array($w = json_decode((string) ($s->works_json ?? '[]'), true)) ? $w : []);

        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
            'answers_json' => json_encode($clean),
            'works_json'   => json_encode($keptWorks),
            'started_at'   => (string) ($s->started_at ?? Carbon::now()->toDateTimeString()),
            'updated_at'   => Carbon::now()->toDateTimeString(),
        ]);

        return ['ok' => true, 'message' => 'Saved. You can come back to this link any time.'];
    }

    /**
     * Send it to the panel: validate, store, and write the evidence rows.
     *
     * @return array{ok:bool, message:string, missing?:list<string>, evidence?:int}
     */
    public static function submit(string $token, string $declaredName, string $ip = ''): array
    {
        $s = self::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];
        if ($s->status === 'submitted') {
            return ['ok' => true, 'message' => 'This was already sent to the panel.'];
        }

        // ── AN INTERVIEW ARRIVES HERE AS ANSWERS, LIKE EVERYTHING ELSE ──────
        //
        // The conversation stores what it learned in a ledger of outcomes, not in
        // `answers_json`. Every reader downstream — this validation, `publishEvidence()`, the
        // dossier, the judge's screen — reads `answers_json` keyed by slug, and teaching each
        // of them about a second shape would mean the first one nobody updated showing a judge
        // an empty questionnaire beside a nominee who had answered everything.
        //
        // So the ledger is folded into the same map at the moment of submission. Only the
        // nominee's own QUOTES go in; the model's headings do not, which is the same rule the
        // guided chat has always followed and the reason a panel can read either style as
        // "supplied by the nominee" without qualification.
        //
        // The form's own answers win where both exist. Somebody who left the conversation for
        // the form and typed there meant the newer one.
        self::foldInterviewAnswers($s);
        $s = self::byToken($token) ?? $s;

        $answers = json_decode((string) ($s->answers_json ?? '{}'), true);
        $answers = is_array($answers) ? $answers : [];

        $missing = [];
        if (QuestionnaireInterview::styleOf($s) === QuestionnaireStyle::INTERVIEW) {
            // An interview's contract is its OUTCOMES, not the question list. A programme that
            // authored its own outcome slugs has no question with those slugs at all, so
            // checking the questions would refuse every interview submission on the platform
            // with a list of things the nominee was never asked.
            //
            // PARTIAL counts as enough, the same rule propose_complete uses. Refusing to send
            // until every outcome is fully met would punish the nominee whose work genuinely
            // has no funder, no referee or no figure — which is most of the people these
            // awards exist to find.
            foreach (QuestionnaireLedger::forSubmission($s) as $o) {
                if ($o['required'] && $o['status'] === QuestionnaireLedger::UNMET) {
                    $missing[] = $o['label'];
                }
            }
        } else {
            // The FILTERED set. A required question that branching never showed them would
            // otherwise block sending with an instruction to answer something no screen
            // displays — a dead end whose only exit is support.
            foreach (self::questionsFor($s) as $q) {
                if ((int) ($q['is_required'] ?? 0) !== 1) continue;
                $v = trim((string) ($answers[(string) $q['slug']] ?? ''));
                if ($v === '') $missing[] = (string) $q['label'];
            }
        }
        if ($missing !== []) {
            return ['ok' => false, 'missing' => $missing,
                    'message' => 'A few answers are still needed before this can be sent.'];
        }

        $name = trim($declaredName);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Please type your name to confirm these are your own words.'];
        }

        $now = Carbon::now()->toDateTimeString();
        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
            'status'        => 'submitted',
            'submitted_at'  => $now,
            'submitted_ip'  => mb_substr($ip, 0, 45),
            'declared_name' => mb_substr($name, 0, 160),
            'updated_at'    => $now,
        ]);

        $written = self::publishEvidence((int) $s->id);

        // A test is submittable on purpose — the send step, the typed name and the "what
        // happens next" screen are among the things most worth rehearsing — but it says what
        // it did rather than claiming to have reached a panel that never saw it.
        if (self::isTest($s)) {
            return ['ok' => true, 'evidence' => 0,
                    'message' => 'Submitted. This is a test questionnaire, so nothing was sent to a '
                               . 'judging panel and no evidence was written.'];
        }

        return ['ok' => true, 'evidence' => $written,
                'message' => 'Thank you — this has gone to the judging panel.'];
    }

    /**
     * The evidence rows an interview produces, or an empty list for a form.
     *
     * `$order` is passed by reference so the rows that follow keep numbering from where these
     * stopped — a dossier whose sort keys collide reorders itself between page loads.
     *
     * @return list<array<string,mixed>>
     */
    private static function interviewEvidence(object $s, int $nomineeId, string $now, int &$order): array
    {
        if (QuestionnaireInterview::styleOf($s) !== QuestionnaireStyle::INTERVIEW) return [];

        $labels = [];
        try {
            foreach (DB::table('gates_judge_criteria')->get() as $c) {
                $labels[(int) $c->id] = (string) $c->label;
            }
        } catch (\Throwable) {}

        $rows = [];
        foreach (QuestionnaireLedger::forSubmission($s) as $o) {
            $val = trim((string) $o['quote']);
            if ($o['status'] === QuestionnaireLedger::UNMET || $val === '') continue;

            $crit = $o['criterion_id'] !== null ? ($labels[$o['criterion_id']] ?? '') : '';
            $rows[] = [
                'nominee_id'   => $nomineeId,
                'kind'         => 'note',
                'title'        => mb_substr($o['label'], 0, 250),
                'body'         => mb_substr($val, 0, 8000),
                // The panel is told HOW this was collected, because a sentence taken from a
                // conversation and a sentence typed into a box are not quite the same kind of
                // claim, and a judge weighing them is entitled to know which they are reading.
                'source_label' => 'The nominee\'s own interview'
                                . ($crit !== '' ? ' · ' . $crit : '')
                                . ($o['edited'] ? ' · corrected by the nominee' : ''),
                'source_url'   => null,
                'provenance'   => 'nominee_supplied',
                'verified'     => 0,
                'visible_to_judges' => 1,
                'sort_order'   => $order++,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        return $rows;
    }

    /**
     * Write an interview's quotes into `answers_json`, without disturbing a form's.
     *
     * A no-op for a guided-form submission, and a no-op when the interview recorded nothing —
     * in both cases the map it would write is empty and the existing one is already right.
     */
    private static function foldInterviewAnswers(object $s): void
    {
        if (QuestionnaireInterview::styleOf($s) !== QuestionnaireStyle::INTERVIEW) return;

        $carried = QuestionnaireLedger::asAnswers($s);
        if ($carried === []) return;

        $stored = json_decode((string) ($s->answers_json ?? '{}'), true);
        $stored = is_array($stored) ? $stored : [];

        $merged = $carried;
        foreach ($stored as $k => $v) {
            if (trim((string) $v) !== '') $merged[(string) $k] = (string) $v;
        }

        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
                ->update(['answers_json' => (string) json_encode($merged),
                          'updated_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not fold interview answers: ' . $e->getMessage());
        }
    }

    /** Re-open a submitted questionnaire so a nominee can correct or add to it. */
    public static function reopen(int $id, string $note = ''): array
    {
        $s = self::byId($id);
        if (!$s) return ['ok' => false, 'message' => 'That questionnaire could not be found.'];
        DB::table('gates_nominee_submissions')->where('id', $id)->update([
            'status'      => 'draft',
            'review_note' => $note !== '' ? mb_substr(trim($note), 0, 500) : null,
            'updated_at'  => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'message' => 'Re-opened. The nominee can use their original link again.'];
    }

    // ══ 4. into the judges' dossier ══════════════════════════════════════════

    /**
     * Turn a submitted questionnaire into evidence rows a judge can read.
     *
     * ── WHY IT REPLACES RATHER THAN APPENDS ──────────────────────────────────
     *
     * A nominee who corrects an answer and re-submits must not leave the panel reading two
     * versions of the same claim, and a nominee whose file is re-published three times must
     * not appear to have three times the evidence. So every row this function previously
     * wrote for this nominee is removed first — identified by `provenance = 'nominee_supplied'`
     * — and nothing else is touched. Staff notes, verified records and the nomination's own
     * case are all somebody else's rows and survive untouched.
     *
     * ── AND WHY NOTHING IS EVER MARKED VERIFIED ──────────────────────────────
     *
     * `verified = 0` on every row, whatever was attached. Verification means somebody outside
     * this platform checked it; a self-uploaded document is a claim, and the dossier is built
     * to show a judge the difference. Writing `verified = 1` here would quietly promote every
     * nominee's own assertion to the same standing as an independently checked record.
     */
    public static function publishEvidence(int $id): int
    {
        $s = self::byId($id);
        if (!$s || $s->status !== 'submitted') return 0;

        // A rehearsal never reaches a judge. This is the guard the whole test-questionnaire
        // feature rests on, and it is HERE rather than in the controller for the reason every
        // consent gate on this platform lives in the writer: a screen can be bypassed, and the
        // next caller added six months from now will not remember to check.
        if (self::isTest($s)) return 0;

        $nomineeId = (int) $s->nominee_id;
        $answers   = json_decode((string) ($s->answers_json ?? '{}'), true);
        $works     = json_decode((string) ($s->works_json ?? '[]'), true);
        $answers   = is_array($answers) ? $answers : [];
        $works     = is_array($works) ? $works : [];
        $now       = Carbon::now()->toDateTimeString();

        try {
            DB::table('gates_nominee_evidence')
                ->where('nominee_id', $nomineeId)
                ->where('provenance', 'nominee_supplied')
                ->delete();
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not clear old nominee evidence: ' . $e->getMessage());
        }

        $rows  = [];
        $order = 10;

        // ── AN INTERVIEW FILES ITS OUTCOMES, A FORM FILES ITS QUESTIONS ─────
        //
        // Same rows, same table, same provenance, same criteria — only the list differs, and
        // it has to: a programme with its own outcome slugs has no question carrying them, so
        // walking the questions would produce an empty dossier next to a nominee who had
        // answered everything.
        //
        // What a judge reads is identical either way: a heading, the nominee's own words
        // beneath it, and the criterion it speaks to. The heading is the outcome's LABEL —
        // written by an administrator — never the model's summary, so nothing a machine
        // composed reaches a panel as though a person had written it.
        foreach (self::interviewEvidence($s, $nomineeId, $now, $order) as $row) {
            $rows[] = $row;
        }

        // Filtered too: filing an unasked question as "not answered" would let a panel read
        // a question the platform chose not to ask as a nominee declining to answer it.
        foreach (($rows === [] ? self::questionsFor($s) : []) as $q) {
            $slug = (string) $q['slug'];
            $val  = trim((string) ($answers[$slug] ?? ''));
            if ($val === '') continue;

            $isLink = (string) $q['kind'] === 'url' || (string) $q['evidence_kind'] === 'link';
            $rows[] = [
                'nominee_id'   => $nomineeId,
                'kind'         => $isLink ? 'link' : (string) $q['evidence_kind'],
                // The QUESTION is the title, so a judge reads the answer as an answer rather
                // than as a paragraph that appeared from nowhere.
                'title'        => mb_substr((string) $q['label'], 0, 250),
                'body'         => $isLink ? null : mb_substr($val, 0, 8000),
                'source_label' => 'The nominee\'s own questionnaire'
                                . ((string) ($q['criterion'] ?? '') !== '' ? ' · ' . $q['criterion'] : ''),
                'source_url'   => $isLink ? mb_substr($val, 0, 600) : null,
                'provenance'   => 'nominee_supplied',
                'verified'     => 0,
                'visible_to_judges' => 1,
                'sort_order'   => $order++,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        // ── THE SPOKEN INTRODUCTION ──────────────────────────────────────────
        //
        // First in the dossier, before the answers, because it is the one item in it that is
        // unmistakably the person: a dossier otherwise contains a nominator's paragraph, a
        // category and some typed prose, none of which proves who wrote it.
        //
        // Refused without consent — {@see QuestionnaireIntro::forDossier()} returns null —
        // and the refusal is in that method rather than here so no future caller can route
        // around it. Transcript in the body so a judge with no headphones can read it; the
        // audio as the source, so one with headphones can hear it.
        $intro = QuestionnaireIntro::forDossier($s);
        if ($intro !== null) {
            $mins = $intro['seconds'] > 0
                ? ' (' . ($intro['seconds'] >= 60
                    ? intdiv($intro['seconds'], 60) . 'm ' . ($intro['seconds'] % 60) . 's'
                    : $intro['seconds'] . ' seconds') . ')'
                : '';
            $rows[] = [
                'nominee_id'   => $nomineeId,
                // 'media', not 'audio'. `kind` is a MySQL ENUM and 'audio' is not one of its
                // values — under strict mode that insert fails and under a relaxed sql_mode it
                // silently stores an empty string, which is how a whole class of evidence
                // would have disappeared from a ballot with nothing in the logs.
                'kind'         => 'media',
                'title'        => 'The nominee introducing themselves, in their own voice' . $mins,
                'body'         => $intro['transcript'] !== ''
                    ? mb_substr($intro['transcript'], 0, 8000)
                    : 'A recording with no transcript — no transcription service was configured '
                    . 'when it was made. Play it rather than skipping it.',
                'source_label' => 'Recorded by the nominee' . ($intro['transcript'] !== ''
                    ? ' · transcript produced automatically and not corrected by them'
                    : ''),
                // The gated stream, not a public path. The recording lives outside the web
                // root on purpose.
                'source_url'   => '/my-work/' . (string) $s->invite_token . '/intro.audio',
                'provenance'   => 'nominee_supplied',
                'verified'     => 0,
                'visible_to_judges' => 1,
                // Deliberately BEFORE the answers, which start at 10.
                'sort_order'   => 5,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        foreach ($works as $w) {
            if (!is_array($w)) continue;
            $title = trim((string) ($w['title'] ?? ''));
            if ($title === '') continue;
            $link = trim((string) ($w['link'] ?? ''));
            $file = trim((string) ($w['file'] ?? ''));
            $body = trim((string) ($w['description'] ?? ''));
            $when = trim((string) ($w['year'] ?? ''));
            $who  = trim((string) ($w['confirm'] ?? ''));

            $note = [];
            if ($body !== '') $note[] = $body;
            if ($when !== '') $note[] = 'When: ' . $when;
            if ($who !== '')  $note[] = 'Who can confirm it: ' . $who;

            $rows[] = [
                'nominee_id'   => $nomineeId,
                'kind'         => self::workKind((string) ($w['kind'] ?? ''), $link, $file),
                'title'        => mb_substr($title, 0, 250),
                'body'         => $note !== [] ? mb_substr(implode("\n", $note), 0, 8000) : null,
                'source_label' => 'Submitted by the nominee',
                'source_url'   => $file !== '' ? mb_substr($file, 0, 600)
                                              : ($link !== '' ? mb_substr($link, 0, 600) : null),
                'provenance'   => 'nominee_supplied',
                'verified'     => 0,
                'visible_to_judges' => 1,
                'sort_order'   => $order++,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        $written = 0;
        foreach ($rows as $r) {
            try { DB::table('gates_nominee_evidence')->insert($r); $written++; }
            catch (\Throwable $e) {
                error_log('[questionnaire] could not write evidence: ' . $e->getMessage());
            }
        }

        DB::table('gates_nominee_submissions')->where('id', $id)->update([
            'evidence_count' => $written,
            'updated_at'     => $now,
        ]);

        return $written;
    }

    /** Which evidence kind a listed work is. */
    private static function workKind(string $declared, string $link, string $file): string
    {
        $ok = ['document', 'link', 'media', 'award', 'press', 'note'];
        if (in_array($declared, $ok, true)) return $declared;
        if ($file !== '') {
            return preg_match('/\.(jpe?g|png|webp|gif)$/i', $file) ? 'media' : 'document';
        }
        return $link !== '' ? 'link' : 'note';
    }

    // ══ 5. cleaning what arrives ═════════════════════════════════════════════

    /**
     * Keep only answers to questions that exist, trimmed to their declared length.
     *
     * A crafted POST cannot invent a question, and cannot store a megabyte against one that
     * declares 1,200 characters. Unknown keys are dropped silently rather than rejected: a
     * form that 400s because a browser sent an extra field helps nobody.
     *
     * @param array<string,mixed> $answers
     * @return array<string,string>
     */
    private static function cleanAnswers(int $programmeId, array $answers): array
    {
        $out = [];
        foreach (self::questions($programmeId) as $q) {
            $slug = (string) $q['slug'];
            if (!array_key_exists($slug, $answers)) continue;
            $v = is_scalar($answers[$slug]) ? (string) $answers[$slug] : '';
            $v = trim(str_replace(["\r\n", "\r"], "\n", $v));
            if ($v === '') continue;

            if ((string) $q['kind'] === 'url') {
                if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
                if (!filter_var($v, FILTER_VALIDATE_URL)) continue;
            }
            $out[$slug] = mb_substr($v, 0, max(50, (int) $q['max_len']));
        }
        return $out;
    }

    /**
     * Normalise the listed works, keeping any file already attached to a row.
     *
     * The file path is NOT accepted from the form. It is carried over from what is already
     * stored for that row, because a field holding a server path is a field somebody can
     * type into — and pointing an evidence row at an arbitrary path on the server is not a
     * mistake worth making possible.
     *
     * @param list<array<string,mixed>> $works
     * @param list<array<string,mixed>> $stored
     * @return list<array<string,mixed>>
     */
    private static function cleanWorks(array $works, array $stored): array
    {
        $files = [];
        foreach ($stored as $i => $w) {
            if (is_array($w) && trim((string) ($w['file'] ?? '')) !== '') {
                $files[(string) ($w['uid'] ?? $i)] = (string) $w['file'];
            }
        }

        $out = [];
        foreach ($works as $i => $w) {
            if (!is_array($w)) continue;
            $title = trim((string) ($w['title'] ?? ''));
            $link  = trim((string) ($w['link'] ?? ''));
            $desc  = trim((string) ($w['description'] ?? ''));
            $uid   = preg_replace('/[^a-z0-9]/i', '', (string) ($w['uid'] ?? '')) ?: ('w' . ($i + 1));

            // A row with nothing in it is not an omission to complain about.
            if ($title === '' && $link === '' && $desc === '' && !isset($files[$uid])) continue;

            if ($link !== '') {
                if (!preg_match('~^https?://~i', $link)) $link = 'https://' . $link;
                if (!filter_var($link, FILTER_VALIDATE_URL)) $link = '';
            }

            $out[] = [
                'uid'         => mb_substr($uid, 0, 20),
                'title'       => mb_substr($title, 0, 200),
                'kind'        => in_array((string) ($w['kind'] ?? ''), ['document', 'link', 'media', 'award', 'press'], true)
                                    ? (string) $w['kind'] : '',
                'year'        => mb_substr(trim((string) ($w['year'] ?? '')), 0, 40),
                'link'        => mb_substr($link, 0, 600),
                'description' => mb_substr($desc, 0, 1500),
                'confirm'     => mb_substr(trim((string) ($w['confirm'] ?? '')), 0, 300),
                'file'        => $files[$uid] ?? '',
                'file_name'   => mb_substr(trim((string) ($w['file_name'] ?? '')), 0, 200),
            ];
            if (count($out) >= self::MAX_WORKS) break;
        }
        return $out;
    }

    /**
     * Attach an uploaded file to one work row.
     *
     * Kept apart from saveDraft because an upload can fail on its own — a file too large, a
     * type the platform will not store — and losing a page of typing to a rejected
     * attachment is the kind of thing that stops somebody finishing.
     *
     * @return array{ok:bool, message:string}
     */
    public static function attachFile(string $token, string $uid, \Psr\Http\Message\UploadedFileInterface $file): array
    {
        $s = self::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That link is not valid.'];
        if ($s->status === 'submitted') {
            return ['ok' => false, 'message' => 'This has already been sent to the panel.'];
        }

        $works = json_decode((string) ($s->works_json ?? '[]'), true);
        $works = is_array($works) ? $works : [];

        $attached = 0;
        foreach ($works as $w) {
            if (is_array($w) && trim((string) ($w['file'] ?? '')) !== '') $attached++;
        }
        if ($attached >= self::MAX_FILES) {
            return ['ok' => false, 'message' => 'That is the most files we can take ('
                                             . self::MAX_FILES . '). Use links for the rest.'];
        }

        try {
            // The same validated public path the nomination form's evidence upload uses: it
            // reads the bytes rather than trusting the client's MIME type.
            $up = (new \AfricaGates\Admin\Services\UploadService())
                ->uploadDocument($file, 'nominee-evidence', self::MAX_FILE_MB, null, 'public',
                                 'nominee', (int) $s->nominee_id);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'That file could not be accepted: ' . $e->getMessage()];
        }

        $uid   = preg_replace('/[^a-z0-9]/i', '', $uid) ?: 'w1';
        $found = false;
        foreach ($works as $i => $w) {
            if (is_array($w) && (string) ($w['uid'] ?? '') === $uid) {
                $works[$i]['file']      = (string) ($up['path'] ?? '');
                $works[$i]['file_name'] = mb_substr((string) $file->getClientFilename(), 0, 200);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $works[] = ['uid' => $uid, 'title' => mb_substr((string) $file->getClientFilename(), 0, 200),
                        'kind' => '', 'year' => '', 'link' => '', 'description' => '', 'confirm' => '',
                        'file' => (string) ($up['path'] ?? ''),
                        'file_name' => mb_substr((string) $file->getClientFilename(), 0, 200)];
        }

        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
            'works_json' => json_encode(array_values($works)),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        return ['ok' => true, 'message' => 'File attached.'];
    }

    // ══ 6. telling the nominee ═══════════════════════════════════════════════

    /**
     * Send the questionnaire to the nominee.
     *
     * The address comes from their approved nomination, never from the caller — the rule the
     * claim path and the interview invitation both follow, for the same reason: an endpoint
     * that accepted a destination would let anybody submit a case as anybody.
     *
     * @return array{ok:bool, message:string, sent?:list<string>}
     */
    public static function invite(int $id, ?OtpService $mailer = null, ?SmsService $sms = null): array
    {
        $s = self::byId($id);
        if (!$s) return ['ok' => false, 'message' => 'That questionnaire could not be found.'];
        if (self::isTest($s)) {
            // There is no nominee behind a test, so there is no address to send to — and a
            // "send" that quietly picked one up from somewhere would be the worst possible
            // outcome of pressing a button on a rehearsal.
            return ['ok' => false, 'message' => 'That is a test questionnaire, so there is nobody to '
                                              . 'send it to. Open its link yourself and answer it as a '
                                              . 'nominee would.'];
        }

        $nominee = (string) (DB::table('gates_nominees')->where('id', (int) $s->nominee_id)->value('name') ?? '');
        $link    = self::url($id);
        $sent    = [];

        $deadline = self::deadline((int) ($s->cycle_id ?? 0));
        $body = "Dear " . ($nominee !== '' ? $nominee : 'Nominee') . ",\n\n"
              . "You have been nominated for an Africa GATES award. The judges currently have only "
              . "what the person who nominated you wrote about you — so we would like to hear about "
              . "the work from you, and to see anything you can show us.\n\n"
              . "YOUR PAGE: " . $link . "\n\n"
              . "It is a short questionnaire, and you can add your own works: links, documents, "
              . "photographs, reports, letters, press coverage — whatever exists. You do not have to "
              . "finish it in one sitting; the page saves as you go and the link keeps working.\n\n"
              . ($deadline !== '' ? 'Please send it before ' . $deadline . ".\n\n" : '')
              . "Two things worth saying plainly:\n"
              . "  - Nothing here costs money. We will never ask you to pay for a nomination, an "
              . "interview, a result or an award. If anybody does, it is not us.\n"
              . "  - Answering honestly about what has NOT worked has never cost anybody an award. "
              . "The judges are looking for real work, not a perfect record.\n";

        try {
            foreach (ClaimIndependence::contactsFor((int) $s->nominee_id) as $c) {
                if (($c['channel'] ?? '') !== 'email') continue;
                $to = (string) $c['value'];
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
                if ($mailer) {
                    $mailer->sendCustom($to, 'Tell the Africa GATES judges about your work', $body);
                    $sent[] = $to;
                }
                break;
            }
            foreach (ClaimIndependence::contactsFor((int) $s->nominee_id) as $c) {
                if (($c['channel'] ?? '') !== 'phone' || $sms === null || !$sms->configured()) continue;
                $e164 = \AfricaGates\Support\Phone::normalize((string) $c['value'], (string) ($c['country'] ?? 'NG'));
                if ($e164 === null) continue;
                if ($sms->sendSms($e164, 'Africa GATES: the judges would like to hear about your work '
                                       . 'in your own words. ' . $link . ' Nothing to pay.', 'questionnaire')) {
                    $sent[] = $e164;
                }
                break;
            }
        } catch (\Throwable $e) {
            error_log('[questionnaire] invite failed for ' . $id . ': ' . $e->getMessage());
        }

        DB::table('gates_nominee_submissions')->where('id', $id)->update([
            'invited_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);

        if ($sent === []) {
            return ['ok' => true, 'sent' => [],
                    'message' => 'Marked as invited, but nothing could be sent — no contact is on '
                               . 'the nomination, or no mail transport is configured. Send this link '
                               . 'yourself: ' . $link];
        }
        return ['ok' => true, 'sent' => $sent,
                'message' => 'Sent to ' . count($sent) . ' recipient(s).'];
    }

    // ══ 7. reading, for the admin screens ════════════════════════════════════

    /** @return list<array<string,mixed>> */
    public static function queue(int $limit = 200): array
    {
        try {
            $rows = DB::table('gates_nominee_submissions as s')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 's.nominee_id')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 's.programme_id')
                ->orderByDesc('s.id')->limit($limit)
                ->select('s.*', 'n.name as nominee', 'p.title as programme')
                ->get();
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not read the queue: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $answers = json_decode((string) ($r->answers_json ?? '{}'), true);
            $works   = json_decode((string) ($r->works_json ?? '[]'), true);
            $isTest = (int) ($r->is_test ?? 0) === 1;
            $out[] = [
                'id'         => (int) $r->id,
                'nominee_id' => (int) $r->nominee_id,
                // A test has no nominee to join to, so the row would read "Unknown" — which
                // looks like a broken record rather than a rehearsal somebody made on purpose.
                'nominee'    => $isTest
                    ? (trim((string) ($r->test_label ?? '')) ?: 'Test nominee')
                    : (string) ($r->nominee ?? 'Unknown'),
                'is_test'    => $isTest,
                'programme'  => (string) ($r->programme ?? ''),
                'status'     => (string) $r->status,
                'invited'    => !empty($r->invited_at),
                'invited_at' => (string) ($r->invited_at ?? ''),
                'submitted_at' => (string) ($r->submitted_at ?? ''),
                'answers'    => is_array($answers) ? count($answers) : 0,
                'works'      => is_array($works) ? count($works) : 0,
                'evidence'   => (int) $r->evidence_count,
                'started'    => !empty($r->started_at),
                'link'       => self::url((int) $r->id),
            ];
        }
        return $out;
    }

    /**
     * The counts on the queue screen.
     *
     * Tests are counted SEPARATELY and in nothing else. "Nine submitted" that silently
     * includes an administrator's own rehearsal is the kind of number somebody plans a
     * judging round around, and it would be wrong by one in the direction that matters.
     *
     * @return array<string,int>
     */
    public static function summary(): array
    {
        $s = ['total' => 0, 'invited' => 0, 'started' => 0, 'submitted' => 0,
              'not_invited' => 0, 'silent' => 0, 'tests' => 0];
        // The column is read through OptionalColumn because a deployment that has uploaded this
        // code but not yet run /__setup/migrate would otherwise get an SQL error on the queue
        // screen — on this host the two are separate acts minutes apart, so "the new column is
        // not there yet" is an ordinary state and not a fault.
        $hasFlag = \AfricaGates\Support\OptionalColumn::on('gates_nominee_submissions', 'is_test');
        try {
            foreach (DB::table('gates_nominee_submissions')
                        ->select(array_merge(['status', 'invited_at', 'started_at'],
                                             $hasFlag ? ['is_test'] : []))->get() as $r) {
                if ((int) ($r->is_test ?? 0) === 1) { $s['tests']++; continue; }
                $s['total']++;
                if ((string) $r->status === 'submitted') { $s['submitted']++; continue; }
                if (empty($r->invited_at)) { $s['not_invited']++; continue; }
                $s['invited']++;
                if (!empty($r->started_at)) $s['started']++;
                else $s['silent']++;
            }
        } catch (\Throwable) {}
        return $s;
    }

    /** Approved nominees who have no questionnaire yet. */
    public static function candidates(int $limit = 400): array
    {
        try {
            $has = DB::table('gates_nominee_submissions')->pluck('nominee_id')
                ->map(fn ($v) => (int) $v)->all();
            $q = DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->whereNull('n.merged_into')
                ->orderBy('n.name')->limit($limit)
                ->select('n.id', 'n.name', 'c.title as category');
            if ($has) $q->whereNotIn('n.id', $has);
            return $q->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }
}
