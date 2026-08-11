<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The questionnaire as a conversation: one question at a time, with follow-ups.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE RULE THIS FILE WILL NOT BEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * WHAT IS STORED AS AN ANSWER IS THE NOMINEE'S OWN WORDS, VERBATIM.
 *
 * A model that tidied a halting sentence into confident prose would be authoring a record
 * that a judging panel reads as "supplied by the nominee", in a dossier whose most important
 * column is who is asserting what. It would also do it invisibly, and it would do it best for
 * the nominees who needed it least.
 *
 * So the division of labour is strict. The model decides:
 *
 *   - whether what was just said actually answers the question on the table
 *   - whether one follow-up is worth asking, and what it should be
 *   - how to phrase the next question for this particular person
 *
 * The model never decides what the answer SAYS. {@see record()} writes the turns the nominee
 * typed, joined, and nothing else. If the model vanishes mid-conversation the answers already
 * stored are unaffected, because they were never the model's to begin with.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT IS A REAL CONVERSATION WITH NO AI KEY
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The scripted path asks the questions one at a time, in order, with the same help text the
 * form shows — and it still probes, because the useful probes are mechanical: an impact answer
 * with no number in it, a reach answer with no place named, a claim with no source. Those are
 * regex checks, not intelligence, and they are most of the value.
 *
 * A model makes it sound like a person and lets it react to what was actually said. It is the
 * difference between good and better, not between working and broken — the distinction this
 * codebase has had to relearn twice.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT NEVER PRESSES SEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The conversation fills the same draft the form fills. Submitting is still a separate,
 * deliberate act by the nominee with their name typed — a chat that could send a nominee's
 * case to a judging panel because they said "yes" to something would be a chat that decides
 * an award entry, and the model does not get that.
 */
final class QuestionnaireChat
{
    /** Follow-ups per question. One is help; three is an interrogation. */
    public const MAX_PROBES = 1;

    /** Turns kept. A questionnaire is a dozen answers, not a support thread. */
    private const MAX_TURNS = 120;

    /** Below this, an answer is a shrug rather than an answer. */
    private const MIN_ANSWER_CHARS = 12;

    // ══ 1. reading the state ═════════════════════════════════════════════════

    /**
     * Where the conversation is: the turns so far, the question on the table, and how much
     * is left.
     *
     * @return array{ok:bool, turns:list<array<string,string>>, question:?array<string,mixed>,
     *               progress:array<string,int>, done:bool, source:string, message?:string}
     */
    public static function state(string $token): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'turns' => [], 'question' => null,
                         'progress' => [], 'done' => false, 'source' => '',
                         'message' => 'That link is not valid.'];

        $answers = self::answers($s);
        $next    = self::nextQuestion($s, $answers);

        return [
            'ok'       => true,
            'turns'    => self::turns($s),
            'question' => $next,
            'progress' => self::progress($s, $answers),
            'done'     => $next === null,
            'source'   => (string) ($s->chat_source ?? ''),
        ];
    }

    /**
     * The opening line, written once and stored, so a nominee returning to the page does not
     * get greeted from the beginning as though nothing had happened.
     *
     * @return array{ok:bool, turns:list<array<string,string>>, question:?array<string,mixed>,
     *               progress:array<string,int>, done:bool, source:string}
     */
    public static function start(string $token): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return self::state($token);

        if (self::turns($s) !== []) return self::state($token);

        $nominee = (string) (DB::table('gates_nominees')
            ->where('id', (int) $s->nominee_id)->value('name') ?? '');
        $first = (explode(' ', trim($nominee))[0] ?? '') ?: 'there';

        $answers = self::answers($s);
        $q = self::nextQuestion($s, $answers);

        self::push($s, 'ai',
            'Hello ' . $first . '. I am going to ask you a few questions about your work, one at '
            . 'a time, so the judges hear it from you rather than only from the person who '
            . 'nominated you.' . "\n\n"
            . 'Answer in as few or as many words as you like, in whatever English you are '
            . 'comfortable with — nobody is marking your grammar, and I will write down exactly '
            . 'what you say. You can stop and come back to this link any time, and nothing is '
            . 'sent to the judges until you press the button yourself.');

        if ($q !== null) self::push($s, 'ai', self::ask($q, true));

        return self::state($token);
    }

    // ══ 2. one turn ══════════════════════════════════════════════════════════

    /**
     * The nominee says something. Record it, decide whether to probe, move on.
     *
     * @return array{ok:bool, reply:list<string>, filled:string, question:?array<string,mixed>,
     *               progress:array<string,int>, done:bool, message?:string}
     */
    public static function say(string $token, string $message, ?AiGateway $gateway = null): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'reply' => [], 'filled' => '', 'question' => null,
                         'progress' => [], 'done' => false, 'message' => 'That link is not valid.'];
        if ($s->status === 'submitted') {
            return ['ok' => false, 'reply' => [], 'filled' => '', 'question' => null,
                    'progress' => [], 'done' => true,
                    'message' => 'This has already been sent to the judges.'];
        }

        $text = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if ($text === '') {
            return ['ok' => false, 'reply' => [], 'filled' => '', 'question' => null,
                    'progress' => [], 'done' => false, 'message' => 'Nothing was typed.'];
        }
        $text = mb_substr($text, 0, 4000);

        self::push($s, 'you', $text);
        $s = QuestionnaireService::byToken($token);          // re-read: push() wrote

        $answers = self::answers($s);
        $current = self::currentQuestion($s, $answers);

        // No question on the table — everything is answered. Say so rather than inventing one.
        if ($current === null) {
            $reply = ['That is everything I was going to ask. Have a look at what I have written '
                    . 'down, add anything you want to show the judges, then send it when you are '
                    . 'ready.'];
            self::pushMany($s, $reply);
            return self::result($token, '', $reply);
        }

        $slug   = (string) $current['slug'];
        $probes = (int) ($s->chat_probes ?? 0);

        // A skip is a legitimate answer to an optional question and must be taken at face
        // value. Pressing somebody who has said no is how a conversation becomes a demand.
        if (self::isSkip($text)) {
            if ((int) ($current['is_required'] ?? 0) === 1) {
                $reply = ['That one I do have to ask, I am afraid — it is one of the few the '
                        . 'judges need. Even one sentence is enough.'];
                self::pushMany($s, $reply);
                return self::result($token, '', $reply);
            }
            self::advance($s, $slug);
            $reply = self::nextPrompt($token, 'That is fine — we can leave that one.');
            return self::result($token, '', $reply);
        }

        // Too short to be an answer — but only for the questions where that is true.
        //
        // "Since 2021." is eleven characters and a complete answer to "when did it start?".
        // The first version applied one minimum to everything and nagged for more, then filed
        // whatever came next against the date question. A short-answer question gets a short
        // answer and that is the end of it; the nudge belongs to the ones that ask for prose.
        if (self::wantsProse($current) && mb_strlen($text) < self::MIN_ANSWER_CHARS
            && $probes < self::MAX_PROBES) {
            self::bumpProbe($s, $slug, $probes + 1);
            $reply = ['Could you say a little more? Even one full sentence helps the judges more '
                    . 'than a few words.'];
            self::pushMany($s, $reply);
            return self::result($token, '', $reply);
        }

        // Record FIRST, then decide whether to probe. An answer stored before the follow-up
        // survives a nominee who closes the tab rather than answering it — the opposite order
        // loses what they already said.
        self::record($s, $slug, $text);
        $s = QuestionnaireService::byToken($token);

        $probe = null;
        if ($probes < self::MAX_PROBES) {
            $probe = self::probeFor($current, $text);
            if ($probe === null) {
                $probe = self::modelProbe($gateway ?? new AiGateway(), $current, $text, (int) $s->nominee_id);
            }
        }

        if ($probe !== null) {
            self::bumpProbe($s, $slug, $probes + 1);
            self::pushMany($s, [$probe]);
            return self::result($token, $slug, [$probe]);
        }

        self::advance($s, $slug);
        $reply = self::nextPrompt($token, self::acknowledge($text));
        return self::result($token, $slug, $reply);
    }

    /** The lines that follow an accepted answer: a short acknowledgement, then the next question. */
    private static function nextPrompt(string $token, string $ack): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return [$ack];

        $answers = self::answers($s);
        $q = self::nextQuestion($s, $answers);

        $out = [$ack];
        if ($q === null) {
            $out[] = 'That is everything. Now the part that carries the most weight with a panel: '
                   . 'anything you can SHOW them. A report, a letter, a photograph of the work, a '
                   . 'news article, a register. Add those below — a judge trusts something they '
                   . 'can look at far more than a description of it.';
            $out[] = 'When you are ready, type your name at the bottom and press send. Nothing '
                   . 'goes to the judges until you do.';
        } else {
            $out[] = self::ask($q, false);
        }
        self::pushMany($s, $out === [] ? [] : array_slice($out, 0));
        return $out;
    }

    // ══ 3. the probes ════════════════════════════════════════════════════════

    /**
     * A mechanical follow-up, or null.
     *
     * These are the probes worth having and none of them needs a model: an impact answer with
     * no number, a reach answer with no place, a figure with no source behind it. A judge asked
     * to score Impact out of ten from "we have helped many people" is scoring a feeling.
     */
    public static function probeFor(array $question, string $answer): ?string
    {
        $slug = (string) ($question['slug'] ?? '');
        $crit = mb_strtolower((string) ($question['criterion'] ?? ''));
        $hasNumber = (bool) preg_match(
            '/\b\d{2,}\b|\b\d+%|\b(?:ten|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety'
            . '|hundred|hundreds|thousand|thousands|million|millions|dozens?)\b/iu', $answer);
        $hasSource = (bool) preg_match(
            '/\b(?:register|record|records|receipt|receipts|report|list|file|files|log|book|'
            . 'signed|署|certificate|ledger|attendance|database|spreadsheet|photograph|photos?)\b/iu',
            $answer);

        if (($slug === 'impact_numbers' || str_contains($crit, 'impact')) && !$hasNumber) {
            return 'Roughly how many people or places is that? An approximate number you can '
                 . 'stand behind is worth much more to a judge than "many" — and if you do not '
                 . 'know, saying so is a fine answer.';
        }
        if ($slug === 'impact_numbers' && $hasNumber && !$hasSource) {
            return 'And who keeps that count — is it written down anywhere, in a register, a '
                 . 'report or a file somebody signs?';
        }
        if (str_contains($crit, 'reach') && !preg_match('/\b(?:state|states|town|towns|city|cities|'
            . 'village|villages|school|schools|country|countries|region|lga|community|communities)\b/iu', $answer)) {
            return 'Which places, specifically? Naming them lets a judge picture the spread.';
        }
        if ($slug === 'referees' && !preg_match('/\b(?:@|\+?\d{7,}|head|teacher|director|chair|'
            . 'principal|officer|pastor|imam|doctor|chief|coordinator|manager)\b/iu', $answer)) {
            return 'Could you give a name and their role? We only contact them if the panel asks, '
                 . 'and never without telling you first.';
        }
        return null;
    }

    /**
     * A model's follow-up, when the mechanical checks found nothing to ask about.
     *
     * Advisory and skippable. It is asked for ONE short question and gets rejected if it
     * returns anything else — including anything that reads as praise or judgement, because a
     * nominee being told "excellent work!" by a machine while describing a feeding programme is
     * being patronised by the platform.
     */
    private static function modelProbe(AiGateway $gw, array $question, string $answer, int $nomineeId): ?string
    {
        if (mb_strlen($answer) < 40) return null;      // nothing there to probe usefully

        $res = $gw->run('questionnaire.chat', [
            'system' => "You are helping somebody answer a question about their own work, for an "
                . "African awards panel. You have just received their answer.\n\n"
                . "Decide whether ONE short follow-up question would materially help a judge. Ask "
                . "only for a specific: a number, a date, a place, a name, a document, or who keeps "
                . "a record. Never ask for an opinion, a feeling, or a self-assessment.\n\n"
                . "Reply with the question alone, under 25 words, or exactly NONE if their answer "
                . "already gives a judge something concrete.\n\n"
                . "Rules you must not break:\n"
                . "- Never praise, congratulate, evaluate or encourage. No 'well done', no "
                . "'that is impressive'. You are asking a question, not reacting.\n"
                . "- Never suggest what their answer should have said.\n"
                . "- Never ask about money, bank details, politics, religion, health or family.\n"
                . "- If their answer says they do not know, reply NONE. Do not press.",
            'trusted' => 'The question they were asked: ' . (string) ($question['label'] ?? ''),
            'user'    => "Their answer:\n" . mb_substr($answer, 0, 1500),
            'subject_type' => 'nominee',
            'subject_id'   => $nomineeId,
            'schema'  => static function (string $raw): ?string {
                $t = trim(strip_tags($raw));
                $t = trim((string) preg_replace('/^(?:follow[- ]?up|question)\s*:\s*/i', '', $t));
                $t = trim($t, " \t\n\"'“”");
                if ($t === '' || strcasecmp($t, 'NONE') === 0) return null;
                if (mb_strlen($t) > 200 || str_word_count($t) > 30) return null;
                if (!str_contains($t, '?')) return null;
                // A model that praises has ignored the brief, and the whole reply is dropped
                // rather than trimmed: a machine congratulating somebody on their own hardship
                // is worse than no follow-up at all.
                if (preg_match('/\b(?:well done|congratulat|impressive|amazing|excellent|wonderful|'
                             . 'inspiring|great work|fantastic)\b/i', $t)) return null;
                return $t;
            },
        ]);

        return $res->ok && is_string($res->value) && $res->value !== '' ? $res->value : null;
    }

    // ══ 4. wording ═══════════════════════════════════════════════════════════

    /** The question, with its help text the first time and without it on a repeat. */
    private static function ask(array $q, bool $first): string
    {
        $out = (string) ($q['label'] ?? '');
        $help = trim((string) ($q['help'] ?? ''));
        if ($help !== '') $out .= "\n\n" . $help;
        if ((int) ($q['is_required'] ?? 0) !== 1) {
            $out .= "\n\n" . 'If you would rather not answer this one, say "skip".';
        }
        return $out;
    }

    /**
     * A neutral acknowledgement.
     *
     * Deliberately flat. "Thank you — noted" is respectful; "That is wonderful!" from a machine,
     * to somebody describing nine years of unpaid work, is not. And a nominee reading praise
     * from the platform they have applied to would reasonably read it as a signal about their
     * chances, which this has no business giving.
     */
    private static function acknowledge(string $answer): string
    {
        return mb_strlen($answer) > 260
            ? 'Thank you — I have written all of that down.'
            : 'Thank you, that is written down.';
    }

    /**
     * Does this question expect more than a few words?
     *
     * A date, a number, a link or a name is complete in a handful of characters. Only the prose
     * questions are worth asking somebody to expand on.
     */
    private static function wantsProse(array $question): bool
    {
        return (string) ($question['kind'] ?? 'textarea') === 'textarea';
    }

    /** Did they decline this question? */
    public static function isSkip(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        return (bool) preg_match('/^(?:skip|pass|next|no|none|nothing|n\/?a|not applicable|'
                               . 'i (?:would rather not|prefer not|don\'?t want to)(?: answer)?|'
                               . 'move on|leave (?:it|that))\.?$/u', $t);
    }

    // ══ 5. state on the row ══════════════════════════════════════════════════

    /** @return array<string,string> */
    private static function answers(object $s): array
    {
        $a = json_decode((string) ($s->answers_json ?? '{}'), true);
        return is_array($a) ? array_map('strval', $a) : [];
    }

    /** @return list<array<string,string>> */
    public static function turns(object $s): array
    {
        $t = json_decode((string) ($s->chat_json ?? '[]'), true);
        return is_array($t) ? array_values(array_filter($t, 'is_array')) : [];
    }

    /**
     * The text of one AI turn, addressed by its position in this conversation.
     *
     * The whole reason the voice endpoint takes an INDEX rather than a string. A caller
     * cannot ask the platform to speak text of its own choosing: it can only point at
     * something this conversation already said, to this nominee. That closes an open
     * text-to-speech proxy on the operator's ElevenLabs invoice, and it bounds the total
     * possible spend per submission to "each of its own questions, once" — because the clip
     * cache is keyed by the text itself. {@see VoiceService} for the arithmetic.
     *
     * Returns null for the nominee's own turns as well as for a bad index. Reading a
     * person's own answer back to them in a synthetic voice is not a feature anybody asked
     * for, and it would double the bill to provide it.
     */
    public static function spokenTurn(string $token, int $index): ?string
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return null;

        $turns = self::turns($s);
        if (!isset($turns[$index])) return null;

        $t = $turns[$index];
        if ((string) ($t['who'] ?? '') !== 'ai') return null;

        $text = trim((string) ($t['text'] ?? ''));
        return $text !== '' ? $text : null;
    }

    /** The question the conversation is on: the one it was left on, else the next unanswered. */
    private static function currentQuestion(object $s, array $answers): ?array
    {
        $slug = trim((string) ($s->chat_slug ?? ''));
        if ($slug !== '') {
            foreach (QuestionnaireService::questions((int) ($s->programme_id ?? 0)) as $q) {
                if ((string) $q['slug'] === $slug) return $q;
            }
        }
        return self::nextQuestion($s, $answers);
    }

    /**
     * The next question worth asking: required ones first, then the rest.
     *
     * Required first because a conversation that runs out of the nominee's patience should have
     * spent it on the answers a judge cannot do without.
     */
    private static function nextQuestion(object $s, array $answers): ?array
    {
        $questions = QuestionnaireService::questions((int) ($s->programme_id ?? 0));
        $skipped   = self::skipped($s);

        foreach ([1, 0] as $wantRequired) {
            foreach ($questions as $q) {
                if ((int) ($q['is_required'] ?? 0) !== $wantRequired) continue;
                $slug = (string) $q['slug'];
                if (trim((string) ($answers[$slug] ?? '')) !== '') continue;
                if (in_array($slug, $skipped, true)) continue;
                return $q;
            }
        }
        return null;
    }

    /** Slugs the nominee has declined, kept in the chat document rather than a column. */
    private static function skipped(object $s): array
    {
        foreach (self::turns($s) as $t) {
            if (($t['who'] ?? '') === 'meta' && ($t['skip'] ?? '') !== '') {
                $out[] = (string) $t['skip'];
            }
        }
        return $out ?? [];
    }

    /** @return array<string,int> */
    public static function progress(object $s, array $answers): array
    {
        $questions = QuestionnaireService::questions((int) ($s->programme_id ?? 0));
        $req = $reqDone = $done = 0;
        foreach ($questions as $q) {
            $has = trim((string) ($answers[(string) $q['slug']] ?? '')) !== '';
            if ($has) $done++;
            if ((int) ($q['is_required'] ?? 0) === 1) {
                $req++;
                if ($has) $reqDone++;
            }
        }
        return ['total' => count($questions), 'answered' => $done,
                'required' => $req, 'required_answered' => $reqDone,
                'left' => max(0, count($questions) - $done)];
    }

    /**
     * Store an answer — the nominee's own words.
     *
     * Appended rather than replaced when they add to an answer after a follow-up, because both
     * halves are theirs and the second half is usually the part a judge needed.
     */
    private static function record(object $s, string $slug, string $text): void
    {
        $answers = self::answers($s);
        $existing = trim((string) ($answers[$slug] ?? ''));
        $answers[$slug] = $existing === '' ? $text : ($existing . "\n" . $text);

        // Through the same cleaner the form uses, so a chat cannot store an answer to a
        // question that does not exist or exceed a question's declared length.
        $r = QuestionnaireService::saveDraft((string) $s->invite_token, $answers, self::works($s));
        if (!($r['ok'] ?? false)) {
            error_log('[questionnaire] chat could not store an answer: ' . (string) $r['message']);
        }
        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
            'chat_mode'  => ((string) ($s->chat_mode ?? '')) === 'form' ? 'both' : 'chat',
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private static function works(object $s): array
    {
        $w = json_decode((string) ($s->works_json ?? '[]'), true);
        return is_array($w) ? array_values(array_filter($w, 'is_array')) : [];
    }

    private static function advance(object $s, string $slug): void
    {
        $answers = self::answers($s);
        $declined = trim((string) ($answers[$slug] ?? '')) === '';

        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
            'chat_slug'   => null,
            'chat_probes' => 0,
            'updated_at'  => Carbon::now()->toDateTimeString(),
        ]);

        // A declined question is remembered, or the next turn asks it again for ever.
        if ($declined) self::pushMeta($s, $slug);
    }

    private static function bumpProbe(object $s, string $slug, int $probes): void
    {
        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
            'chat_slug'   => $slug,
            'chat_probes' => $probes,
            'updated_at'  => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ══ 6. the transcript ════════════════════════════════════════════════════

    /**
     * Append one turn.
     *
     * RE-READS THE ROW FIRST. The version of this that trusted the `$s` it was handed lost a
     * turn every time it was called twice in a row: start() pushed a greeting and then a
     * question, the second call read `chat_json` from the stale object it still held — which
     * had no turns in it — and wrote the question over the greeting. Every nominee's
     * conversation opened with a question and no explanation of what was happening.
     */
    private static function push(object $s, string $who, string $text): void
    {
        $fresh = QuestionnaireService::byId((int) $s->id) ?? $s;
        $turns = self::turns($fresh);
        $turns[] = ['who' => $who === 'you' ? 'you' : 'ai',
                    'text' => mb_substr($text, 0, 4000),
                    'at' => Carbon::now()->toDateTimeString()];
        self::store($fresh, $turns);
    }

    /** @param list<string> $lines */
    private static function pushMany(object $s, array $lines): void
    {
        $fresh = QuestionnaireService::byId((int) $s->id);
        if (!$fresh) return;
        $turns = self::turns($fresh);
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '') continue;
            $turns[] = ['who' => 'ai', 'text' => mb_substr($l, 0, 4000),
                        'at' => Carbon::now()->toDateTimeString()];
        }
        self::store($fresh, $turns);
    }

    private static function pushMeta(object $s, string $slug): void
    {
        $fresh = QuestionnaireService::byId((int) $s->id);
        if (!$fresh) return;
        $turns = self::turns($fresh);
        $turns[] = ['who' => 'meta', 'skip' => $slug, 'text' => '',
                    'at' => Carbon::now()->toDateTimeString()];
        self::store($fresh, $turns);
    }

    /** @param list<array<string,string>> $turns */
    private static function store(object $s, array $turns): void
    {
        if (count($turns) > self::MAX_TURNS) $turns = array_slice($turns, -self::MAX_TURNS);
        try {
            DB::table('gates_nominee_submissions')->where('id', (int) $s->id)->update([
                'chat_json'  => json_encode(array_values($turns)),
                'started_at' => (string) ($s->started_at ?? Carbon::now()->toDateTimeString()),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[questionnaire] could not store the conversation: ' . $e->getMessage());
        }
    }

    /**
     * @param list<string> $reply
     * @return array{ok:bool, reply:list<string>, filled:string, question:?array<string,mixed>,
     *               progress:array<string,int>, done:bool}
     */
    private static function result(string $token, string $filled, array $reply): array
    {
        $st = self::state($token);
        return ['ok' => true, 'reply' => $reply, 'filled' => $filled,
                'question' => $st['question'], 'progress' => $st['progress'],
                'done' => $st['done']];
    }

    /** Record which engine answered, so the operator screen can say so. */
    public static function noteSource(string $token, string $source): void
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return;
        $now = (string) ($s->chat_source ?? '');
        // Once a model has contributed, 'ai' stands: it shaped part of the record.
        if ($now === 'ai') return;
        DB::table('gates_nominee_submissions')->where('id', (int) $s->id)
            ->update(['chat_source' => in_array($source, ['ai', 'rules'], true) ? $source : 'rules']);
    }

    // ══ 7. the readiness check ═══════════════════════════════════════════════

    /**
     * What a judge will find thin, told to the nominee BEFORE they send.
     *
     * Framed as help and never as a score. It is the same set of mechanical checks the probes
     * use, run across everything at once — so somebody who answered "many people" in one place
     * gets a chance to fix it rather than discovering afterwards that the panel could not use
     * their strongest claim.
     *
     * @return array{ready:bool, missing:list<string>, thin:list<array{q:string,why:string}>,
     *               works:int, files:int}
     */
    public static function readiness(string $token): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ready' => false, 'missing' => [], 'thin' => [], 'works' => 0, 'files' => 0];

        $answers = self::answers($s);
        $missing = [];
        $thin    = [];

        foreach (QuestionnaireService::questions((int) ($s->programme_id ?? 0)) as $q) {
            $v = trim((string) ($answers[(string) $q['slug']] ?? ''));
            if ($v === '') {
                if ((int) ($q['is_required'] ?? 0) === 1) $missing[] = (string) $q['label'];
                continue;
            }
            $probe = self::probeFor($q, $v);
            if ($probe !== null) {
                $thin[] = ['q' => (string) $q['label'], 'why' => $probe];
            }
        }

        $works = self::works($s);
        $files = 0;
        foreach ($works as $w) {
            if (trim((string) ($w['file'] ?? '')) !== '') $files++;
        }

        return ['ready' => $missing === [], 'missing' => $missing, 'thin' => $thin,
                'works' => count($works), 'files' => $files];
    }
}
