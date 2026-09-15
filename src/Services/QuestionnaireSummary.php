<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A short summary of what a nominee said — for them to confirm, and for the panel to read.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE SUMMARY, BOTH STYLES, AND THAT IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The conversation had a "read it back" screen. The form had nothing — you typed eleven
 * answers over an evening, pressed send, and the next thing you knew was whether you had
 * won. Two ways of answering the same questions, and only one of them let you check that
 * you had been understood.
 *
 * So this reads {@see QuestionnaireService}'s answers, which is where BOTH styles end up:
 * the form writes them directly, the interview folds its ledger into the same map at
 * submission. One implementation, one shape on the judge's screen, and no reader anywhere
 * downstream that has to know which route an entry took.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT IS A SUMMARY, AND THE NOMINEE'S OWN WORDS REMAIN THE RECORD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The risk here is specific: a judge reads the summary instead of the answers, and the
 * entry is then judged on a paraphrase written by a model. So —
 *
 *  · It never scores, rates or recommends. Declared advisory; enforced by the gateway.
 *  · It is shown ABOVE the answers and labelled as a summary, so it directs reading rather
 *    than replacing it.
 *  · A malformed reply is discarded rather than half-shown. An empty summary on an entry
 *    that has been fully answered reads as "there is nothing here", which is a claim about
 *    the nominee that nobody made.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE CONFIRMED TEXT IS FROZEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The nominee is shown this before they press send, and pressing send is them agreeing it
 * represents them. A summary regenerated later — newer model, edited prompt, different day
 * — is not the one they agreed to, and the panel would be reading something the nominee had
 * never seen. So {@see confirm()} stamps it and nothing rewrites it afterwards.
 */
final class QuestionnaireSummary
{
    public const CAPABILITY = 'questionnaire.summary';

    /** Bumped when {@see system()} changes in a way that invalidates a cached summary. */
    public const PROMPT_VERSION = 'v1';

    /**
     * How much answer text is sent.
     *
     * A generous questionnaire runs to a few thousand words. Beyond this the summary stops
     * improving and the bill does not, and truncation is announced to the model so it says
     * what it did not see rather than quietly summarising a fragment.
     */
    public const MAX_CHARS = 9000;

    public static function system(): string
    {
        return <<<'TXT'
        You are writing a short, plain summary of what somebody said about their own work, for
        an African awards programme. Two people read it: the person themselves, checking that
        they have been understood before they send it, and later a judge, deciding where to
        start reading.

        Write:
        - `summary`: three or four sentences, plain English, in the third person. What the work
          is, who it serves, and what has actually happened. No preamble.
        - `points`: up to five short lines, each a specific fact they gave — a number, a date,
          a place, an organisation, a named record. Quote figures exactly as written.

        Rules you must not break:
        - Never score, rate, rank, praise or recommend. Do not say the work is impressive,
          strong, promising or worthy. You are describing, not assessing.
        - Never add anything they did not say. If they did not give a number, there is no
          number. Do not estimate, round, convert or infer.
        - Never repair or improve their case. If something is vague, summarise it vaguely.
        - Do not name private individuals. Organisations and official bodies are fine.
        - Write about the work, not about the questionnaire. Never mention questions,
          answers, forms or fields.

        Answer in JSON with exactly these keys: summary (string), points (array of strings).
        TXT;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // READING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The stored summary for a submission, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function forSubmission(int $submissionId, string $hash = '', bool $confirmedOnly = false): ?array
    {
        try {
            $q = DB::table('gates_questionnaire_summaries')
                ->where('submission_id', $submissionId)
                ->where('status', 'ok')
                ->where('prompt_version', self::PROMPT_VERSION);

            if ($hash !== '')     $q->where('content_hash', $hash);
            if ($confirmedOnly)   $q->whereNotNull('confirmed_at');

            $row = $q->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }

        return $row ? self::hydrate($row) : null;
    }

    /**
     * What a judge sees: the summary the nominee actually confirmed.
     *
     * Confirmed only, and never the newest. A draft summary the nominee never read is not
     * something they agreed represents them, and putting one at the top of their entry would
     * be the platform speaking for them to a panel.
     *
     * @return array<int,array<string,mixed>> keyed by nominee id
     */
    public static function forNominees(array $nomineeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $nomineeIds))));
        if ($ids === []) return [];

        try {
            $rows = DB::table('gates_questionnaire_summaries')
                ->whereIn('nominee_id', $ids)
                ->where('status', 'ok')
                ->whereNotNull('confirmed_at')
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) $out[(int) $r->nominee_id] = self::hydrate($r);

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Produce the summary for a submission, or return the cached one.
     *
     * @return array{ok:bool, summary:array<string,mixed>|null, message:string}
     */
    public static function build(string $token, bool $force = false): array
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return ['ok' => false, 'summary' => null, 'message' => 'That link is not valid.'];

        $text = self::answersText($s);
        if (trim($text['body']) === '') {
            return ['ok' => false, 'summary' => null,
                    'message' => 'There is nothing written down yet to summarise.'];
        }

        if (!$force) {
            $hit = self::forSubmission((int) $s->id, $text['hash']);
            if ($hit !== null) return ['ok' => true, 'summary' => $hit, 'message' => ''];
        }

        if (!AiGateway::available(self::CAPABILITY)) {
            return ['ok' => false, 'summary' => null, 'message' => self::whyUnavailable()];
        }

        $cap = AiCapability::find(self::CAPABILITY);

        $r = (new AiGateway())->run(self::CAPABILITY, [
            'system'       => self::system(),
            'user'         => $text['body'],
            'subject_type' => 'nominee',
            'subject_id'   => (int) $s->nominee_id,
            'json'         => true,
            'temperature'  => 0.2,
            'schema'       => [self::class, 'parse'],
        ]);

        if (!$r->ok) {
            self::store($s, $text['hash'], [], 'failed', (string) $r->message, '');
            return ['ok' => false, 'summary' => null,
                    'message' => 'The summary could not be written just now. Your answers are '
                               . 'saved and you can still send them.'];
        }

        self::store($s, $text['hash'], (array) $r->value, 'ok', '',
                    (string) ($r->model ?? ($cap->model ?? '')));

        return ['ok' => true, 'summary' => self::forSubmission((int) $s->id, $text['hash']),
                'message' => ''];
    }

    /**
     * Mark the summary the nominee read as the one they agreed to.
     *
     * Called at submission. If there is no summary — the model was unavailable, or they
     * never opened the confirmation — nothing is stamped and the panel simply reads the
     * answers, which is the record anyway. A missing summary must never block a send.
     */
    public static function confirm(string $token): void
    {
        $s = QuestionnaireService::byToken($token);
        if (!$s) return;

        try {
            $hash = self::answersText($s)['hash'];

            $row = DB::table('gates_questionnaire_summaries')
                ->where('submission_id', (int) $s->id)
                ->where('status', 'ok')
                ->where('content_hash', $hash)
                ->orderByDesc('id')
                ->first(['id']);

            // Only a summary of THESE answers. One written before a late edit describes text
            // the nominee then changed, and freezing it would put a stale description at the
            // top of their entry.
            if (!$row) return;

            DB::table('gates_questionnaire_summaries')->where('id', $row->id)
                ->update(['confirmed_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable $e) {
            error_log('[q-summary] could not confirm for submission ' . (int) $s->id . ': '
                    . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE PAYLOAD
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Every answer as prose, plus a hash of it.
     *
     * Labels included, because "18 months" under a question about duration means something
     * and on its own means nothing. Both styles arrive here identically — the form writes
     * `answers_json` and the interview folds into it — so there is no branch on style
     * anywhere in this file.
     *
     * @return array{body:string, hash:string}
     */
    public static function answersText(object $s): array
    {
        $answers = json_decode((string) ($s->answers_json ?? '{}'), true);
        $answers = is_array($answers) ? $answers : [];

        // An interview mid-flight has not folded yet, so its ledger is read directly. Without
        // this, a nominee in the conversation would be offered a summary of nothing.
        if ($answers === []) {
            try { $answers = QuestionnaireLedger::asAnswers($s); }
            catch (\Throwable) { $answers = []; }
        }

        $labels = [];
        foreach (QuestionnaireService::questionsFor($s) as $q) {
            $labels[(string) $q['slug']] = (string) ($q['label'] ?? $q['slug']);
        }

        $parts = [];
        foreach ($answers as $slug => $value) {
            $v = trim((string) $value);
            if ($v === '') continue;
            $parts[] = ($labels[(string) $slug] ?? (string) $slug) . "\n" . $v;
        }

        $full = trim(implode("\n\n", $parts));
        $body = $full;

        if (mb_strlen($body) > self::MAX_CHARS) {
            // Announced rather than silent: a summary of two-thirds of an entry that does not
            // say so is one the nominee will read as wrong about them.
            $body = mb_substr($body, 0, self::MAX_CHARS)
                  . "\n\n[The rest was too long to include. Summarise only what is above.]";
        }

        return ['body' => $body, 'hash' => $full === '' ? '' : hash('sha256', $full)];
    }

    /**
     * Validate the reply, or return null so the gateway discards it.
     *
     * @return array<string,mixed>|null
     */
    public static function parse(string $raw): ?array
    {
        $d = json_decode(trim($raw), true);
        if (!is_array($d) && preg_match('/\{.*\}/s', $raw, $m)) $d = json_decode($m[0], true);
        if (!is_array($d)) return null;

        $summary = trim((string) ($d['summary'] ?? ''));
        if ($summary === '') return null;

        $points = [];
        foreach ((array) ($d['points'] ?? []) as $p) {
            if (!is_scalar($p)) continue;
            $t = trim((string) $p);
            if ($t !== '') $points[] = mb_substr($t, 0, 240);
            if (count($points) >= 5) break;
        }

        return ['summary' => mb_substr($summary, 0, 1200), 'points' => $points];
    }

    /** @param array<string,mixed> $map */
    private static function store(object $s, string $hash, array $map,
                                  string $status, string $error, string $model): void
    {
        try {
            DB::table('gates_questionnaire_summaries')->insert([
                'submission_id'  => (int) $s->id,
                'nominee_id'     => (int) $s->nominee_id,
                'content_hash'   => $hash !== '' ? $hash : null,
                'summary'        => isset($map['summary']) ? mb_substr((string) $map['summary'], 0, 1200) : null,
                'points_json'    => json_encode($map['points'] ?? []),
                'model'          => $model !== '' ? mb_substr($model, 0, 80) : null,
                'prompt_version' => self::PROMPT_VERSION,
                'status'         => $status,
                'error'          => $error !== '' ? mb_substr($error, 0, 300) : null,
                'created_at'     => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[q-summary] could not store for submission ' . (int) $s->id . ': '
                    . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private static function hydrate(object $r): array
    {
        $pts = json_decode((string) ($r->points_json ?? '[]'), true);

        return [
            'id'            => (int) $r->id,
            'submission_id' => (int) $r->submission_id,
            'nominee_id'    => (int) $r->nominee_id,
            'summary'       => (string) ($r->summary ?? ''),
            'points'        => is_array($pts) ? $pts : [],
            'model'         => (string) ($r->model ?? ''),
            'confirmed'     => !empty($r->confirmed_at),
            'at'            => (string) ($r->created_at ?? ''),
        ];
    }

    private static function whyUnavailable(): string
    {
        if (!AiGateway::globallyEnabled())                   return 'AI is switched off for this platform.';
        if (!AiGateway::capabilityEnabled(self::CAPABILITY)) return 'Summaries are switched off.';

        return 'The summary is not available right now. Your answers are saved either way.';
    }
}
