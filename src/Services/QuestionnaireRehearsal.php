<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Rehearsing an interview before a nominee meets it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE SCREEN THAT MATTERS MOST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The brief is prose an administrator writes into a textarea. There is no compiler for it and
 * no test that can read it. The only way to find out that "press for a figure" makes the
 * interviewer hound a nominee whose work has no figures is to be that nominee for ten minutes.
 *
 * Without this screen the first person to discover a bad brief is a real nominee, on a
 * deadline, describing their life's work to something that is not listening properly — and
 * they will never tell anybody. They will simply stop.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT RUNS THE REAL THING, NOT A SIMULATION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A rehearsal is an ordinary test submission ({@see QuestionnaireService::openTest()}) with
 * the interview style stamped on it, driven through the same endpoints a nominee uses. Same
 * prompt assembly, same tools, same quote validation, same ledger, same refusals.
 *
 * A separate "preview mode" would be a second implementation of the one thing this feature
 * cannot afford to have two of, and it would be the copy that never gets the fix.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE THINGS TO DO WITH WHAT YOU FIND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   • CAPTURE A RULE. A turn that went wrong becomes a line appended to the brief, with the
 *     turn kept beside it. A note in a document changes nothing; a rule changes the next
 *     conversation.
 *   • SAVE A CASE. The nominee half of a rehearsal, kept so the same run can be replayed after
 *     the brief changes. This is what stops the fix for one problem quietly creating another.
 *   • BE SOMEBODY DIFFICULT. Canned openings for the nominees who break interviews: the
 *     one-word answerer, the one who talks around it, the one with no numbers, and the one who
 *     asks the AI to write it for them. A regression suite made only of cooperative nominees
 *     proves nothing about the population this will meet.
 */
final class QuestionnaireRehearsal
{
    /**
     * The nominees who break interviews.
     *
     * Canned rather than generated, and that is deliberate. A model asked to "play a difficult
     * nominee" plays a fluent one having a bad day — it writes in full sentences, stays on
     * topic and eventually cooperates. The four below are the actual failure modes, they cost
     * nothing to run, and being fixed strings makes a rehearsal reproducible.
     *
     * @return array<string,array{label:string,note:string,lines:list<string>}>
     */
    public static function personas(): array
    {
        return [
            'short' => [
                'label' => 'Answers in three words',
                'note'  => 'The commonest one. Tests whether the interviewer can open somebody '
                         . 'up without interrogating them.',
                'lines' => ['Farming.', 'Since 2019.', 'Yes.', 'Some people.', 'I do not know.'],
            ],
            'around' => [
                'label' => 'Talks around the question',
                'note'  => 'Warm, fluent, and never answers what was asked. Tests whether the '
                         . 'interviewer notices, and whether it presses more than once.',
                'lines' => [
                    'Well, you know, this work is really about the community and what we owe '
                  . 'each other. My father always said a person is a person through others.',
                    'It has been a journey. Many people have helped and I am grateful to all '
                  . 'of them, especially the women who started it with me.',
                    'That is a good question. I would say the most important thing is that we '
                  . 'never gave up, even in the hard years.',
                ],
            ],
            'nonumbers' => [
                'label' => 'Has no figures at all',
                'note'  => 'Real work with no register, no funder and no paperwork. Tests '
                         . 'whether a nominee is punished for that.',
                'lines' => [
                    'I have never counted them. People come to the compound and we teach them, '
                    . 'that is all.',
                    'There is no register. Nobody funds us — I pay for the seeds myself from '
                    . 'my trading.',
                    'I cannot give you a number. I am sorry.',
                ],
            ],
            'writeit' => [
                'label' => 'Asks the AI to write it',
                'note'  => 'Tests the one refusal the whole feature rests on — and that it is '
                         . 'declined once, warmly, without a lecture and without repeating.',
                'lines' => [
                    'Can you just write it for me? You know what the judges want better than I do.',
                    'Please make it sound better. My English is not good.',
                    'Just put whatever is best.',
                ],
            ],
        ];
    }

    /**
     * The rehearsal for one programme: reuse the open one, or start a new one.
     *
     * Reused rather than created per visit, because an administrator who refreshes the page
     * mid-rehearsal wants the conversation they were having, and because a screen that made a
     * new test row on every load would fill the submissions table with abandoned ones.
     *
     * @return array{ok:bool, token?:string, id?:int, message:string}
     */
    public static function open(?int $programmeId, ?int $adminId = null): array
    {
        $existing = null;
        try {
            $q = DB::table('gates_nominee_submissions')
                ->where('is_test', 1)->where('status', 'draft')
                ->where('style', QuestionnaireStyle::INTERVIEW);
            $programmeId === null ? $q->whereNull('programme_id') : $q->where('programme_id', $programmeId);
            $existing = $q->orderByDesc('id')->first();
        } catch (\Throwable) {}

        if ($existing) {
            return ['ok' => true, 'id' => (int) $existing->id,
                    'token' => (string) $existing->invite_token, 'message' => ''];
        }

        $r = QuestionnaireService::openTest($programmeId, $adminId, 'Rehearsal');
        if (!($r['ok'] ?? false)) return $r;

        // Stamped directly rather than left to open(): a rehearsal is always an interview,
        // even on a programme still set to the form — which is the whole point of rehearsing
        // before switching one over.
        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $r['id'])->update([
                'style' => QuestionnaireStyle::INTERVIEW,
                'interview_phase' => 'talk',
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {}

        QuestionnaireInterview::open((string) $r['token']);
        return ['ok' => true, 'id' => (int) $r['id'], 'token' => (string) $r['token'], 'message' => ''];
    }

    /** Throw the current rehearsal away and start again. */
    public static function reset(?int $programmeId, ?int $adminId = null): array
    {
        try {
            $q = DB::table('gates_nominee_submissions')->where('is_test', 1);
            $programmeId === null ? $q->whereNull('programme_id') : $q->where('programme_id', $programmeId);
            foreach ($q->where('style', QuestionnaireStyle::INTERVIEW)->get() as $row) {
                QuestionnaireService::deleteTest((int) $row->id);
            }
        } catch (\Throwable) {}
        return self::open($programmeId, $adminId);
    }

    /**
     * Keep the nominee half of a rehearsal so it can be run again.
     *
     * Only the nominee's turns. The interviewer's are what changes when the brief changes, and
     * storing them would make a case that compares a new run against an old model's wording
     * rather than against what the run actually achieved.
     */
    public static function saveCase(?int $programmeId, string $token, string $title,
                                    string $persona = '', ?int $adminId = null): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'message' => 'That rehearsal could not be found.'];

        $said = [];
        foreach (QuestionnaireInterview::state($token)['turns'] as $t) {
            if (($t['role'] ?? '') === 'nominee') $said[] = (string) $t['text'];
        }
        if ($said === []) return ['ok' => false, 'message' => 'Say something first — a case with no answers in it proves nothing.'];

        $expect = [];
        foreach (QuestionnaireLedger::forSubmission($s) as $o) {
            if ($o['status'] !== QuestionnaireLedger::UNMET) $expect[] = $o['slug'];
        }

        try {
            DB::table('gates_questionnaire_cases')->insert([
                'programme_id' => $programmeId,
                'title' => mb_substr(trim($title) ?: 'Rehearsal', 0, 160),
                'persona' => mb_substr($persona, 0, 60),
                'transcript_json' => (string) json_encode($said),
                // What THIS run reached, stored as the bar the next run has to clear. Not a
                // hand-written expectation: an administrator asked to predict which outcomes a
                // conversation should reach writes an aspiration, and a suite of aspirations
                // fails on the day it is created and is then ignored.
                'expect_json' => (string) json_encode($expect),
                'created_by' => $adminId,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[rehearsal] could not save a case: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That case could not be saved.'];
        }

        return ['ok' => true, 'message' => 'Saved. Run it again after you change the brief — '
                                         . count($expect) . ' outcome(s) is the bar to clear.'];
    }

    /**
     * Replay a saved case against the current brief and report what it reached.
     *
     * ── WHAT A PASS AND A FAIL MEAN HERE ─────────────────────────────────────
     *
     * Reaching FEWER outcomes than last time is the signal worth having: the brief change that
     * fixed one thing has cost something else. Reaching more is not automatically better and
     * is not reported as a pass — an interviewer that records more from the same words may
     * simply have become less careful about what counts.
     *
     * The replay is real: the same turns through the same engine, costing the same tokens. A
     * cheaper simulation would be testing the simulation.
     */
    public static function runCase(int $caseId, ?int $adminId = null): array
    {
        $c = null;
        try { $c = DB::table('gates_questionnaire_cases')->where('id', $caseId)->first(); }
        catch (\Throwable) {}
        if (!$c) return ['ok' => false, 'message' => 'That case could not be found.'];

        $said = json_decode((string) ($c->transcript_json ?? '[]'), true);
        $said = is_array($said) ? $said : [];
        if ($said === []) return ['ok' => false, 'message' => 'That case has nothing in it.'];

        $pid = ($c->programme_id ?? null) !== null ? (int) $c->programme_id : null;
        $r   = QuestionnaireService::openTest($pid, $adminId, 'Case replay');
        if (!($r['ok'] ?? false)) return ['ok' => false, 'message' => (string) $r['message']];

        $token = (string) $r['token'];
        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $r['id'])
                ->update(['style' => QuestionnaireStyle::INTERVIEW]);
        } catch (\Throwable) {}
        QuestionnaireInterview::open($token);

        foreach ($said as $line) {
            $out = QuestionnaireInterview::say($token, (string) $line);
            if (!($out['ok'] ?? false)) break;
        }

        $s   = QuestionnaireService::byToken($token);
        $got = [];
        foreach (QuestionnaireLedger::forSubmission($s) as $o) {
            if ($o['status'] !== QuestionnaireLedger::UNMET) $got[] = $o['slug'];
        }

        $want = json_decode((string) ($c->expect_json ?? '[]'), true);
        $want = is_array($want) ? $want : [];
        $lost = array_values(array_diff($want, $got));
        $new  = array_values(array_diff($got, $want));

        $verdict = $lost === []
            ? ($new === [] ? 'Same as before.' : 'Same as before, plus ' . implode(', ', $new) . '.')
            : 'LOST: ' . implode(', ', $lost) . '.';

        try {
            DB::table('gates_questionnaire_cases')->where('id', $caseId)->update([
                'last_run_at' => Carbon::now()->toDateTimeString(),
                'last_result' => mb_substr($verdict, 0, 500),
                'updated_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {}

        // The replay row is deleted: it is a rehearsal of a rehearsal, and leaving one behind
        // per run would fill the table with rows nobody will ever open.
        QuestionnaireService::deleteTest((int) $r['id']);

        return ['ok' => true, 'lost' => $lost, 'gained' => $new, 'reached' => $got,
                'message' => $verdict];
    }

    /** Saved cases for a programme, newest first. */
    public static function cases(?int $programmeId): array
    {
        try {
            $q = DB::table('gates_questionnaire_cases');
            $programmeId === null ? $q->whereNull('programme_id') : $q->where('programme_id', $programmeId);
            return $q->orderByDesc('id')->limit(40)->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    /** Delete a saved case. */
    public static function dropCase(int $caseId): bool
    {
        try { return DB::table('gates_questionnaire_cases')->where('id', $caseId)->delete() > 0; }
        catch (\Throwable) { return false; }
    }
}
