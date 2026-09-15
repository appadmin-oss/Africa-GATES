<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Which question applies, given what has already been said.
 *
 * ── THE PROBLEM ──────────────────────────────────────────────────────────────
 *
 * Every nominee got the same eleven questions in the same order. Fine for a form; wrong for
 * a conversation, and the cost lands unevenly.
 *
 * A nominee whose work stopped in 2019 was still asked how it is funded, in the present
 * tense. Somebody who had already said plainly that no independent person can vouch for them
 * was asked, three questions later, who outside their organisation could confirm it. And
 * somebody whose impact answer already contained "1,240 farmers across 8 states" was asked
 * the follow-up whose entire purpose is to extract a number they had just given.
 *
 * Each is a small insult, and together they say: this form is not reading your answers. A
 * questionnaire that visibly does not listen is one people stop answering honestly.
 *
 * ── WHY A VOCABULARY AND NOT AN EXPRESSION LANGUAGE ──────────────────────────
 *
 * A condition is one earlier slug plus one word. `answered`, `blank`, `no_number`, `yes`,
 * `no`, `is:<value>`.
 *
 * That is deliberately less than an operator might want, because the alternative is a
 * mini-language typed into an admin textarea that then runs on a nominee's page — where a
 * typo is not a validation error but a blank screen for somebody describing their life's
 * work. Six words cover every branch these questionnaires actually need, and an unknown
 * condition FAILS OPEN: the question is asked. Being asked something unnecessary is a minor
 * annoyance; silently never being asked about the thing your case rests on is not.
 */
final class QuestionnaireRules
{
    /**
     * Does this question apply?
     *
     * @param array<string,mixed>  $question
     * @param array<string,string> $answers  slug => what they said
     */
    public static function applies(array $question, array $answers): bool
    {
        $on = trim((string) ($question['show_if_slug'] ?? ''));
        if ($on === '') return true;

        $cond = strtolower(trim((string) ($question['show_if'] ?? '')));
        if ($cond === '') return true;

        // An unanswered dependency is not a decided one. Asking now would mean branching on
        // a silence, so the question waits — and if the dependency is never answered, the
        // fail-open below applies when the condition is unrecognised rather than here.
        if (!array_key_exists($on, $answers)) return $cond === 'blank';

        $said = trim((string) $answers[$on]);

        if (str_starts_with($cond, 'is:')) {
            return mb_strtolower($said) === mb_strtolower(trim(substr($cond, 3)));
        }

        return match ($cond) {
            'answered'  => $said !== '',
            'blank'     => $said === '',
            // The one that stops a nominee being asked for a figure they have already given.
            'no_number' => $said !== '' && !self::hasNumber($said),
            'has_number'=> self::hasNumber($said),
            // "yes" means NOT CLEARLY NO, and that asymmetry is deliberate. "We closed the
            // school in 2019 but the clinic is still running" is genuinely ambiguous, and the
            // honest answer to an ambiguous branch is to ask — so it shows the question, while
            // a plain "we closed it in 2019" hides it. Fail-open again: the cost of an extra
            // question is a moment; the cost of a missing one is a nomination.
            'yes'       => self::signal($said) !== 'no',
            'no'        => self::signal($said) === 'no',
            // FAIL OPEN. A condition nobody recognises must not silently delete a question:
            // being asked something unnecessary is an annoyance, never being asked about the
            // thing your case rests on is a lost nomination.
            default     => true,
        };
    }

    /**
     * Is there a figure in here?
     *
     * Words as well as digits, because "we trained four hundred teachers" is a number and a
     * questionnaire that could not see it would nag somebody who had just answered properly
     * — which is the exact failure this file exists to remove.
     */
    public static function hasNumber(string $text): bool
    {
        if (preg_match('/\d/', $text)) return true;
        return (bool) preg_match(
            '/\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|twenty|thirty|'
            . 'forty|fifty|sixty|seventy|eighty|ninety|hundred|hundreds|thousand|thousands|'
            . 'million|millions|dozen|dozens|half|quarter)\b/i',
            $text
        );
    }

    /**
     * Does this read as a yes, or as a no?
     *
     * Nobody answers "is it still running?" with "yes". They answer "still going, we added a
     * second centre last year" or "we closed it in 2021 when the funding stopped". So the
     * test is for the SHAPE of the sentence, and it is only consulted for a branch — never to
     * decide anything about the nomination itself.
     */
    public static function reads(string $text, bool $wantYes): bool
    {
        $sig = self::signal($text);
        return $wantYes ? $sig === 'yes' : $sig === 'no';
    }

    /**
     * `yes`, `no`, or `unclear`.
     *
     * Three answers rather than two, because the third is the common one and the interesting
     * one: "we closed the school in 2019 but the clinic is still running" is not a yes and not
     * a no, and a branch that had to pick one would pick wrong for a real person about half the
     * time. Callers decide what to do with `unclear`; {@see applies()} asks the question.
     */
    public static function signal(string $text): string
    {
        $t = ' ' . mb_strtolower(trim($text)) . ' ';
        if (trim($t) === '') return 'unclear';

        $no = (bool) preg_match(
            '/\b(no|none|nobody|not any|never|stopped|ended|closed|finished|wound up|'
            . "won't|will not|cannot|can't|do not have|don't have|dont have|paused|"
            . 'suspended|no longer|discontinued)\b/',
            $t
        );
        $yes = (bool) preg_match(
            '/\b(yes|still|ongoing|continuing|continues|running|active|currently|'
            . 'we do|i do|every (day|week|month|year)|growing|expanding)\b/',
            $t
        );

        // Both present is genuinely ambiguous and neither side may claim it.
        if ($yes && $no) return 'unclear';
        if ($yes) return 'yes';
        if ($no)  return 'no';
        return 'unclear';
    }

    /**
     * Drop the questions that do not apply, in order.
     *
     * Iterative rather than a single pass, because a condition can depend on a question that
     * a condition removed: if "still running?" is never asked, "how is it funded?" must not
     * branch on an answer that was never possible. Walking forward and carrying the surviving
     * answers gives each condition only the facts that actually exist by the time it is read.
     *
     * @param list<array<string,mixed>> $questions
     * @param array<string,string>      $answers
     * @return list<array<string,mixed>>
     */
    public static function filter(array $questions, array $answers): array
    {
        $out  = [];
        $seen = [];
        foreach ($questions as $q) {
            if (!self::applies($q, $seen)) continue;
            $out[] = $q;
            $slug = (string) ($q['slug'] ?? '');
            if ($slug !== '' && array_key_exists($slug, $answers)) $seen[$slug] = $answers[$slug];
        }
        return $out;
    }

    /**
     * Which questions were never put to this nominee.
     *
     * Kept so an operator can tell "not asked" from "asked and skipped". Two very different
     * silences, and a dossier that conflated them would let a panel read an absence as a
     * refusal to answer.
     *
     * @param list<array<string,mixed>> $questions
     * @param array<string,string>      $answers
     * @return list<string>
     */
    public static function notAsked(array $questions, array $answers): array
    {
        $shown = array_column(self::filter($questions, $answers), 'slug');
        $all   = array_column($questions, 'slug');
        return array_values(array_diff($all, $shown));
    }
}
