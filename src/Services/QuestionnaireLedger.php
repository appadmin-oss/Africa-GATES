<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What one nominee has actually evidenced, and the check that makes it trustworthy.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE LINE THIS WHOLE FEATURE RESTS ON
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see quoteFrom()} — a quote must be a real substring of a real nominee turn, or the row is
 * refused.
 *
 * Without it, "record_outcome" is an instruction a language model can satisfy by writing the
 * evidence itself, and the interview becomes a way to have a machine author somebody's award
 * entry in their name. With it, the model can decide WHICH sentence answers an outcome and it
 * cannot decide what the sentence says. It is one comparison and it is the difference between
 * this feature being usable by a judging panel and not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO LAYERS, AND ONLY ONE OF THEM IS THE NOMINEE'S
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The transcript is verbatim and append-only. This ledger is the model's reading of it, and
 * every screen that shows a value from here labels it machine-derived and shows the quote it
 * came from. A judge is therefore never asked to take a heading on trust: the nominee's own
 * sentence is on the same row, and the review screen sets it in the display face precisely
 * because it is the only part of that row a person actually said.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * PROGRESS COMES FROM HERE, NEVER FROM A TURN COUNT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see progress()} counts outcomes, so the rail can move by three in one turn — one good
 * paragraph often settles several — and can also sit still through a productive exchange that
 * has not yet landed anything. A percentage derived from messages sent would be a progress bar
 * that measures typing, which is the thing that makes a conversation feel like a form with
 * extra latency. There is deliberately no percentage anywhere in this file.
 */
final class QuestionnaireLedger
{
    public const UNMET   = 'unmet';
    public const PARTIAL = 'partial';
    public const MET     = 'met';

    /** A quote shorter than this proves nothing — "yes" appears in every transcript. */
    private const MIN_QUOTE_CHARS = 12;

    /** And one longer than this is not a quote, it is the transcript. */
    private const MAX_QUOTE_CHARS = 600;

    /**
     * The ledger for one submission, in the outcome order the programme declared.
     *
     * Every declared outcome appears, including the ones nothing has been recorded against —
     * an interface cannot show "3 of 7" from a list that only contains the 3.
     *
     * @return list<array{slug:string,label:string,description:string,status:string,
     *                    summary:string,quote:string,turn:?int,required:bool,
     *                    criterion_id:?int,edited:bool}>
     */
    public static function forSubmission(object $s): array
    {
        $declared = QuestionnaireStyle::outcomes(($s->programme_id ?? null) !== null
            ? (int) $s->programme_id : null);

        $rows = [];
        try {
            foreach (DB::table('gates_submission_outcomes')
                        ->where('submission_id', (int) $s->id)->get() as $r) {
                $rows[(string) $r->slug] = $r;
            }
        } catch (\Throwable) {}

        $out = [];
        foreach ($declared as $o) {
            $r = $rows[$o['slug']] ?? null;
            $out[] = [
                'slug'         => $o['slug'],
                'label'        => $o['label'],
                'description'  => $o['description'],
                'required'     => $o['required'],
                'criterion_id' => $o['criterion_id'],
                'status'       => self::cleanStatus((string) ($r->status ?? self::UNMET)),
                'summary'      => (string) ($r->summary ?? ''),
                'quote'        => (string) ($r->quote ?? ''),
                'turn'         => ($r->turn_index ?? null) !== null ? (int) $r->turn_index : null,
                'edited'       => (int) ($r->edited_by_nominee ?? 0) === 1,
            ];
        }
        return $out;
    }

    /**
     * How far along this is, counted in outcomes.
     *
     * `required_left` is the only number that gates finishing. `met`/`total` is what the rail
     * draws, and it includes optional outcomes because a rail that hid them would jump
     * unpredictably whenever one was settled in passing.
     *
     * @return array{met:int, partial:int, total:int, required:int, required_left:int, ready:bool}
     */
    public static function progress(object $s): array
    {
        $met = $partial = $required = $left = 0;
        $rows = self::forSubmission($s);

        foreach ($rows as $r) {
            if ($r['status'] === self::MET)     $met++;
            if ($r['status'] === self::PARTIAL) $partial++;
            if (!$r['required']) continue;
            $required++;
            // PARTIAL counts as enough to finish. An interview that refused to end until every
            // outcome was fully met would be an interview that punishes the nominee whose work
            // genuinely has no funder, no referee or no number — which is most of the people
            // these awards exist to find.
            if ($r['status'] === self::UNMET) $left++;
        }

        return ['met' => $met, 'partial' => $partial, 'total' => count($rows),
                'required' => $required, 'required_left' => $left, 'ready' => $left === 0];
    }

    /**
     * Record one outcome, if the quote is real.
     *
     * ── WHY THE TURN INDEX IS FOUND RATHER THAN TRUSTED ──────────────────────
     *
     * The model is not asked which turn its quote came from, and would not be believed if it
     * said. The index is the one this method FINDS, which is what makes "see it in the
     * conversation" on the review screen land on the sentence rather than near it.
     *
     * @param list<array{role:string,text:string}> $turns the verbatim transcript
     * @return array{ok:bool, reason:string}
     */
    public static function record(object $s, string $slug, string $status, string $summary,
                                  string $quote, array $turns): array
    {
        $slug = QuestionnaireStyle::slug($slug);
        $set  = QuestionnaireStyle::outcomeSet(($s->programme_id ?? null) !== null
            ? (int) $s->programme_id : null);

        // An undeclared slug is DROPPED, not created. A model that could add outcomes could
        // add one it had already answered, and the rail would fill itself.
        if ($slug === '' || !isset($set[$slug])) {
            return ['ok' => false, 'reason' => 'unknown outcome'];
        }

        $status = self::cleanStatus($status);
        if ($status === self::UNMET) {
            // Nothing to record. Recording an unmet outcome would let a model walk the ledger
            // backwards, undoing evidence a nominee had already given.
            return ['ok' => false, 'reason' => 'nothing recorded'];
        }

        $found = self::quoteFrom($quote, $turns);
        if ($found === null) {
            return ['ok' => false, 'reason' => 'quote is not in the transcript'];
        }
        [$matched, $turnIndex] = $found;

        $now = Carbon::now()->toDateTimeString();
        $row = [
            'status'       => $status,
            // Trimmed hard: this is a heading, and a "summary" that runs to a paragraph is the
            // model writing the answer through the one field it is allowed to author.
            'summary'      => mb_substr(trim($summary), 0, 220),
            'quote'        => $matched,
            'turn_index'   => $turnIndex,
            'criterion_id' => $set[$slug]['criterion_id'],
            'updated_at'   => $now,
        ];

        try {
            $existing = DB::table('gates_submission_outcomes')
                ->where('submission_id', (int) $s->id)->where('slug', $slug)->first();

            if ($existing) {
                // A nominee's own correction is not overwritten by the model. They edited this
                // row on the review screen precisely because the machine got it wrong, and a
                // later turn quietly restoring the machine's version would be the worst kind of
                // bug: invisible, and only in the record a panel reads.
                if ((int) ($existing->edited_by_nominee ?? 0) === 1) {
                    return ['ok' => false, 'reason' => 'the nominee has corrected this one'];
                }
                DB::table('gates_submission_outcomes')->where('id', (int) $existing->id)->update($row);
            } else {
                DB::table('gates_submission_outcomes')->insert($row + [
                    'submission_id' => (int) $s->id, 'slug' => $slug, 'created_at' => $now,
                ]);
            }
            return ['ok' => true, 'reason' => ''];
        } catch (\Throwable $e) {
            error_log('[questionnaire-ledger] could not record: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'could not save'];
        }
    }

    /**
     * The nominee correcting one of the machine's rows.
     *
     * Their text replaces the QUOTE, because on this screen the quote is the claim — and it is
     * marked as edited so nothing later restores the machine's reading and so a judge can see
     * which headings a person accepted and which they rewrote.
     */
    public static function correct(object $s, string $slug, string $text): array
    {
        $slug = QuestionnaireStyle::slug($slug);
        $text = trim($text);
        if ($slug === '') return ['ok' => false, 'reason' => 'unknown outcome'];
        if ($text === '') return ['ok' => false, 'reason' => 'nothing typed'];

        $now = Carbon::now()->toDateTimeString();
        try {
            $existing = DB::table('gates_submission_outcomes')
                ->where('submission_id', (int) $s->id)->where('slug', $slug)->first();
            $row = ['quote' => mb_substr($text, 0, self::MAX_QUOTE_CHARS),
                    'status' => self::MET, 'edited_by_nominee' => 1, 'updated_at' => $now];
            if ($existing) {
                DB::table('gates_submission_outcomes')->where('id', (int) $existing->id)->update($row);
            } else {
                $set = QuestionnaireStyle::outcomeSet(($s->programme_id ?? null) !== null
                    ? (int) $s->programme_id : null);
                if (!isset($set[$slug])) return ['ok' => false, 'reason' => 'unknown outcome'];
                DB::table('gates_submission_outcomes')->insert($row + [
                    'submission_id' => (int) $s->id, 'slug' => $slug,
                    'criterion_id' => $set[$slug]['criterion_id'], 'created_at' => $now,
                ]);
            }
            return ['ok' => true, 'reason' => ''];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'could not save'];
        }
    }

    /** Drop one row entirely — the nominee saying this is not evidence of anything. */
    public static function drop(object $s, string $slug): bool
    {
        try {
            return DB::table('gates_submission_outcomes')
                ->where('submission_id', (int) $s->id)
                ->where('slug', QuestionnaireStyle::slug($slug))->delete() > 0;
        } catch (\Throwable) { return false; }
    }

    /**
     * Is this quote real, and where did it come from?
     *
     * ── EVERY DEGREE OF FREEDOM HERE IS ONE A MODEL COULD USE ────────────────
     *
     * The comparison is deliberately narrow. Whitespace is normalised and the curly quotation
     * marks a model tends to produce are folded to straight ones, because those two differences
     * are typography rather than content and refusing over them would reject correct quotes all
     * day. Nothing else is forgiven — not paraphrase, not a changed word, not "roughly what
     * they said". A fuzzy match would be a fuzzy version of the only guarantee this feature
     * makes.
     *
     * Matching is against the NOMINEE's turns only. The model quoting its own question back
     * and filing it as the nominee's evidence is the exact failure this prevents.
     *
     * The returned string is the span AS STORED, not as the model typed it, so what a judge
     * reads is the transcript's own text.
     *
     * @param list<array{role:string,text:string}> $turns
     * @return array{0:string,1:int}|null the matched text and the turn it is in
     */
    public static function quoteFrom(string $quote, array $turns): ?array
    {
        $needle = self::fold($quote);
        if (mb_strlen($needle) < self::MIN_QUOTE_CHARS) return null;
        if (mb_strlen($needle) > self::MAX_QUOTE_CHARS) return null;

        foreach ($turns as $i => $t) {
            if ((string) ($t['role'] ?? '') !== 'nominee') continue;
            $hayRaw = (string) ($t['text'] ?? '');
            $hay    = self::fold($hayRaw);
            if ($hay === '' || !str_contains($hay, $needle)) continue;

            // Give back the ORIGINAL span where it can be located exactly, so the record keeps
            // the nominee's own punctuation. Where folding moved the offsets — a double space,
            // a curly apostrophe — the folded text is honest and still theirs.
            $at = mb_strpos($hayRaw, $quote);
            // The turn's OWN index when it carries one. The caller's list is a window over the
            // transcript with withdrawn turns skipped, so its array position is not the
            // position a judge's "see it in the conversation" link needs.
            return [$at !== false ? mb_substr($hayRaw, $at, mb_strlen($quote)) : $needle,
                    (int) ($t['i'] ?? $i)];
        }
        return null;
    }

    /** Typography folded away; content untouched. */
    private static function fold(string $s): string
    {
        $s = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}"],
            ["'", "'", '"', '"', '-', '-', ' '],
            $s
        );
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Anything not one of the three states is 'unmet' — the safe reading. */
    private static function cleanStatus(string $s): string
    {
        return match (strtolower(trim($s))) {
            self::MET     => self::MET,
            self::PARTIAL => self::PARTIAL,
            default       => self::UNMET,
        };
    }

    /**
     * The ledger as the answers the rest of the platform already understands.
     *
     * ── WHY THIS EXISTS RATHER THAN A SECOND EVIDENCE PATH ───────────────────
     *
     * `QuestionnaireService::publishEvidence()`, `submit()`, the dossier and the judge's screen
     * all read `answers_json` keyed by slug. Writing a parallel path for interview submissions
     * would mean every one of those learning about a second shape, and the first one nobody
     * updated would show a judge an empty questionnaire next to a nominee who had answered
     * everything.
     *
     * So an interview ends by writing exactly the same map a form would have written, from the
     * quotes. The heading the model produced is NOT included — only the nominee's own words go
     * into the answer, which is the same rule the guided chat has always followed.
     *
     * @return array<string,string>
     */
    public static function asAnswers(object $s): array
    {
        $out = [];
        foreach (self::forSubmission($s) as $r) {
            if ($r['status'] === self::UNMET || trim($r['quote']) === '') continue;
            $out[$r['slug']] = $r['quote'];
        }
        return $out;
    }
}
