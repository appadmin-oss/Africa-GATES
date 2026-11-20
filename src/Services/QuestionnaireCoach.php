<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Help while an answer is being written, instead of a verdict after it is finished.
 *
 * ── WHY THE TIMING IS THE WHOLE FEATURE ──────────────────────────────────────
 *
 * {@see QuestionnaireChat::readiness()} already tells a nominee which of their answers a
 * judge will find thin. It does it at the END — after eleven questions, when they are ready
 * to be done — and at that moment being sent back to four of them does not read as help. It
 * reads as rejection, and the likeliest response is to send it anyway.
 *
 * The same observation offered beside the box they are typing in is the opposite experience:
 * "a number here would make this much stronger" is a suggestion somebody acts on in ten
 * seconds, while they still have the thought in their head.
 *
 * ── WHAT IT WILL NEVER DO ────────────────────────────────────────────────────
 *
 * SCORE ANYTHING. There is no mark, no grade, no percentage and no traffic light with a
 * number behind it. `strength` is a word this class uses to pick which sentence to show, it
 * is never shown as a value, and it is never written to the row a judge reads — a nominee
 * being told "your answer is 40% good" by the platform running the award is the platform
 * pre-judging the entry.
 *
 * WRITE THEIR ANSWER. It says what is missing, in the second person, and stops. A model that
 * offered a rewritten paragraph would be authoring the record — and it would write best for
 * the nominees who needed it least, which is how a well-meant assistant becomes a way of
 * sorting people by fluency.
 *
 * ── AND IT WORKS WITH NO AI KEY ──────────────────────────────────────────────
 *
 * Every check that matters is mechanical: an impact claim with no figure, an answer of four
 * words, a reach answer that names no place, a superlative with nothing behind it. The model
 * is an upgrade that phrases one suggestion more specifically — it is never the thing that
 * decides whether there is something to say.
 */
final class QuestionnaireCoach
{
    /** Below this an answer is a shrug rather than an answer, unless the question is a date. */
    private const DEFAULT_MIN_WORDS = 12;

    /**
     * Read one answer and say the single most useful thing about it.
     *
     * ONE suggestion, not a list. Three bullets under a textarea is a marking scheme, and the
     * nominee's job is to describe their work rather than to satisfy a rubric.
     *
     * @param array<string,mixed> $question
     * @return array{state:string, note:string, nudge:string}
     *         state: `empty` · `thin` · `no_number` · `vague` · `good`
     *         note:  what is true about the answer, in the second person
     *         nudge: the one thing to do about it, or '' when there is nothing to say
     */
    public static function read(array $question, string $answer, ?AiGateway $gateway = null): array
    {
        $answer = trim($answer);
        $words  = $answer === '' ? 0 : count(preg_split('/\s+/u', $answer) ?: []);

        if ($answer === '') {
            return ['state' => 'empty', 'note' => '', 'nudge' => ''];
        }

        $label = (string) ($question['label'] ?? '');
        $crit  = strtolower((string) ($question['criterion'] ?? ''));
        $kind  = (string) ($question['kind'] ?? 'textarea');

        // A date, a link or a short text field has no opinion about length. Applying a prose
        // minimum to "when did it start?" would nag somebody for answering it correctly —
        // "Since 2021." is eleven characters and a complete answer.
        $prose = $kind === 'textarea';
        $min   = ($question['min_words'] ?? null) !== null
            ? max(0, (int) $question['min_words'])
            : ($prose ? self::DEFAULT_MIN_WORDS : 0);

        if ($min > 0 && $words < $min) {
            return ['state' => 'thin',
                    'note'  => 'That is quite short.',
                    'nudge' => 'A judge reads this next to forty others. One more sentence — what '
                             . 'exactly you did, or who it was for — is usually the difference.'];
        }

        // Does this question want a figure? Declared per question, because the answer differs:
        // an impact claim without a number cannot be weighed, and a question about what went
        // wrong does not want one at all.
        // Impact only, in the fallback. A reach question is about PLACES — its own check runs
        // just below and is the more useful one — and asking for a figure first would mean a
        // nominee naming five states was told their answer had no number in it.
        $wantsNumber = ($question['wants_number'] ?? null) !== null
            ? (int) $question['wants_number'] === 1
            : str_contains($crit, 'impact');

        if ($wantsNumber && !QuestionnaireRules::hasNumber($answer)) {
            return ['state' => 'no_number',
                    'note'  => 'There is no figure in this yet.',
                    'nudge' => 'How many people, over what period? An estimate you can stand behind '
                             . 'beats a round number you cannot — and "about 300 since 2021" is '
                             . 'something a panel can weigh.'];
        }

        if (str_contains($crit, 'reach') && !self::namesAPlace($answer)) {
            return ['state' => 'vague',
                    'note'  => 'This does not name anywhere yet.',
                    'nudge' => 'Name the towns, states or countries. "Across Nigeria" and "in Kano, '
                             . 'Kaduna and Zamfara" are read very differently.'];
        }

        if (self::onlySuperlatives($answer)) {
            return ['state' => 'vague',
                    'note'  => 'This is mostly description rather than fact.',
                    'nudge' => 'Swap one adjective for one thing that happened. "The best programme '
                             . 'in the region" tells a judge nothing; "the state adopted our syllabus '
                             . 'in 2023" tells them everything.'];
        }

        // Nothing mechanical to say. A model may still find the one specific thing a rule
        // cannot — and if it cannot either, the answer is left alone rather than padded with
        // encouragement nobody needs.
        $note = 'That reads well.';
        $nudge = self::fromModel($question, $answer, $gateway);

        return ['state' => 'good', 'note' => $note, 'nudge' => $nudge];
    }

    /**
     * One model-written suggestion, or nothing.
     *
     * Heavily constrained on purpose. It is given the question and the answer and asked for a
     * single short sentence naming what is MISSING — never a rewrite, never praise, never a
     * judgement of the work. Anything that comes back looking like a score, a rewrite or an
     * opinion about the nominee is discarded: a suggestion is worth having, and a model with
     * opinions about candidates on a page the candidate is reading is not.
     */
    private static function fromModel(array $question, string $answer, ?AiGateway $gateway): string
    {
        $gw = $gateway ?? new AiGateway();
        if (!AiGateway::available('questionnaire.coach')) return '';

        $r = $gw->run('questionnaire.coach', [
            'system' => 'You help somebody describe their own work for an awards panel. Reply with '
                      . 'ONE short sentence naming the single most useful FACT that is missing from '
                      . 'their answer — a number, a date, a place, or who could confirm it. Address '
                      . 'them as "you". Never rewrite their answer, never praise it, never judge the '
                      . 'work, never mention scores. If nothing important is missing, reply with the '
                      . 'single word NOTHING.',
            'trusted' => 'The question was: ' . (string) ($question['label'] ?? ''),
            'user'    => $answer,
            'subject_type' => 'questionnaire_answer',
        ]);

        if (!$r->ok) return '';
        $out = trim((string) $r->value);
        if ($out === '' || strtoupper($out) === 'NOTHING') return '';

        // A model asked for one sentence sometimes returns five. Take the first, and only if
        // the whole reply is short enough to have been made in good faith.
        if (mb_strlen($out) > 400) return '';
        $out = trim((string) preg_split('/(?<=[.!?])\s+/u', $out)[0]);

        // Anything that reads as a mark, a rewrite or a verdict on the person is dropped
        // rather than cleaned up: the failure mode is worse than the missing suggestion.
        if (preg_match('/\b(\d{1,3}\s*%|score|scored|rating|rate this|out of \d|grade|weak|strong '
                     . 'candidate|poor|excellent answer|rewrite|here is a better|suggested version)\b/i', $out)) {
            return '';
        }
        return $out;
    }

    /** Does this name a real place, rather than gesturing at one? */
    private static function namesAPlace(string $text): bool
    {
        // A capitalised word that is not the first in a sentence is the cheapest usable signal
        // for a proper noun, and it is checked ALONGSIDE an explicit vocabulary so that
        // "kano and kaduna" typed in lower case still counts — plenty of people type that way
        // on a phone, and punishing them for it would be punishing the keyboard.
        if (preg_match('/\b(state|states|town|towns|city|cities|village|villages|region|regions|'
                     . 'country|countries|lga|province|district|nationwide|continent)\b/i', $text)) {
            return true;
        }
        return (bool) preg_match('/(?<!^)(?<![.!?]\s)\b[A-Z][a-z]{2,}\b/', $text);
    }

    /**
     * Is this only adjectives?
     *
     * Superlatives with no fact behind them are the commonest shape of a weak answer, and the
     * people who write them are usually the ones who have been told an application should
     * sound impressive. So the note says what to swap rather than telling them off.
     */
    private static function onlySuperlatives(string $text): bool
    {
        $hits = preg_match_all(
            '/\b(best|greatest|leading|foremost|world[- ]class|award[- ]winning|renowned|'
            . 'outstanding|excellent|passionate|dedicated|innovative|revolutionary|'
            . 'unparalleled|prestigious|visionary)\b/i',
            $text
        );
        if ($hits < 2) return false;

        // Two superlatives in an answer that also contains a figure, a year or a place is
        // somebody writing with enthusiasm about something real. It is only a problem when
        // there is nothing else in there.
        return !QuestionnaireRules::hasNumber($text) && !self::namesAPlace($text);
    }

    /**
     * The one-line "what a judge is looking for here", shown under the question itself.
     *
     * Static per question rather than generated: it is the same for everybody, it must be
     * there with no AI key and no network, and it is the kind of copy an operator should be
     * able to read and disagree with.
     *
     * @param array<string,mixed> $question
     */
    public static function lookingFor(array $question): string
    {
        $crit = strtolower((string) ($question['criterion'] ?? ''));

        return match (true) {
            str_contains($crit, 'impact')      => 'A judge is looking for how many, over how long, and who keeps that record.',
            str_contains($crit, 'reach')       => 'A judge is looking for named places, and whether it spread beyond where it began.',
            str_contains($crit, 'originality') => 'A judge is looking for what you did differently from how it was done before.',
            str_contains($crit, 'integrity')   => 'A judge is looking for who holds you accountable, and how honest you are about what went wrong.',
            default                            => 'A judge is looking for specifics: what happened, when, and who else saw it.',
        };
    }
}
