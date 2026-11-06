<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE ONE POST A RESULT MAKES, AND THE ONE PLACE PEOPLE REPLY TO IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A THREAD AND NOT A NEW KIND OF COMMENT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A public result page needs replies. The obvious move is a fifth `target_type` on
 * `gates_comments` — and it would have been silently fatal on production, which is the
 * whole reason this class is shaped the way it is:
 *
 *   target_type ENUM('profile','legacy','thread','nominee') NOT NULL
 *
 * MySQL answers a value outside an ENUM with `Data truncated`, not an error anybody sees,
 * and SQLite's CHECK constraint is enforced only where the harness has not turned it off.
 * So the reply box would have worked in development and dropped every reply on the floor
 * in production, on the one page whose replies are the platform's public record of an
 * award. That is not a hypothetical shape of bug here; it has shipped twice.
 *
 * So the result posts a THREAD, and replies are `thread` comments — a target type that has
 * existed since the first schema. Nothing about the reply path is new: the member gate, the
 * rate limit, the spam verdict, the quarantine queue, the reply notification and the
 * moderation screens are the ones already in service.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT IS HOW THE RESULT REACHES THE PULSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Pulse feed IS `gates_threads` ({@see PulseFeedService}). A result that posts a thread
 * is in the feed by construction — with cheers, reposts and replies — rather than by a
 * second feed-shaped listing that would have to be kept in step with the first. The most
 * significant thing this platform does was the one thing its feed never carried.
 */
final class ResultThread
{
    /**
     * Slug prefix. Deterministic per category, which is what makes minting idempotent
     * without a `result_id` column: the announcement runs once per NOMINEE (winner, then
     * runner-up) and reruns on a repaired backlog, so "post the announcement" has to be
     * safe to call four times for the same award.
     */
    public const SLUG = 'result-';

    /** The byline. A result is published by the platform, not by a person. */
    public const AUTHOR = 'Africa GATES';

    /**
     * MySQL's `gates_threads.programme_id` is TINYINT UNSIGNED — it caps at 255, and an id
     * above that would be CLAMPED rather than refused. Clamped it either lands the post in
     * some other programme's channel or breaks the foreign key; both are silent in SQLite.
     * Above the cap the post goes to the open channel instead, which is wrong in a small
     * visible way rather than in a large invisible one.
     */
    private const MAX_CHANNEL = 255;

    /**
     * The announcement thread for this category, or null if there is not one.
     *
     * @return array{id:int, slug:string, reply_count:int, cheer_count:int}|null
     */
    public static function forCategory(int $categoryId): ?array
    {
        try {
            $t = DB::table('gates_threads')->where('slug', self::SLUG . $categoryId)
                ->first(['id', 'slug', 'reply_count', 'cheer_count']);
        } catch (\Throwable) {
            return null;
        }

        return $t === null ? null : [
            'id' => (int) $t->id, 'slug' => (string) $t->slug,
            'reply_count' => (int) $t->reply_count, 'cheer_count' => (int) $t->cheer_count,
        ];
    }

    /**
     * Post the announcement if it is not already posted. Returns the thread id, or null.
     *
     * REFUSES A RESULT THE PUBLIC PAGE WILL NOT SHOW. {@see PublicResults::heldReason()}
     * decides, and it is asked here rather than re-tested, because a held result that
     * nevertheless announced itself to the whole feed would be the worst of both: the page
     * saying "still being verified" and the Pulse saying who won.
     *
     * Best-effort throughout. A failed insert may never leave a winner un-promoted — this
     * is called from the promotion path, where the standing correction is the important
     * half and the post is the celebration.
     */
    public static function ensure(int $categoryId): ?int
    {
        $existing = self::forCategory($categoryId);
        if ($existing !== null) return $existing['id'];

        $r = PublicResults::category($categoryId);
        if ($r === null || $r['held'] !== null || empty($r['winner'])) return null;

        $winner = $r['winner'];
        $title  = self::title($r);

        try {
            $pid = (int) DB::table('gates_award_cycles')->where('id', (int) $r['cycle_id'])
                ->value('programme_id');
        } catch (\Throwable) {
            $pid = 0;
        }

        $row = [
            'programme_id' => ($pid > 0 && $pid <= self::MAX_CHANNEL) ? $pid : null,
            'slug'         => self::SLUG . $categoryId,
            'title'        => $title,
            'body'         => self::body($r, $winner),
            'author_name'  => self::AUTHOR,
            // NOT NULL, and there is no person behind this post. A constant marker rather
            // than any real address: an operator's email must never enter a row the public
            // feed reads, even hashed.
            'author_email_hash' => hash('sha256', 'africa-gates:results'),
            'status'       => 'approved',
            'reply_count'  => 0,
            'cheer_count'  => 0,
            'last_activity' => Carbon::now()->toDateTimeString(),
            'created_at'   => Carbon::now()->toDateTimeString(),
        ];

        try {
            return (int) DB::table('gates_threads')->insertGetId($row);
        } catch (\Throwable) {
            // A concurrent announcement won the unique slug. Its row is the right answer.
            return self::forCategory($categoryId)['id'] ?? null;
        }
    }

    /** "Leadership — 2026: the result". */
    private static function title(array $r): string
    {
        $cat     = (string) ($r['category']->title ?? 'Result');
        $edition = trim((string) ($r['edition'] ?? ''));

        return mb_substr($cat . ($edition !== '' ? ' — ' . $edition : '') . ': the result', 0, 240);
    }

    /**
     * How much of the body a Pulse card can actually show.
     *
     * ── MEASURED AGAINST THE CARD, NOT CHOSEN ────────────────────────────────
     *
     * `.pf__canvas p` is `-webkit-line-clamp: 7` at a display face around 1.85rem in a
     * column near 415px — roughly thirty characters a line. The first version of this post
     * ran to four paragraphs and the feed cut it mid-sentence, at "Runner-up: Ajayi
     * Temitope…", with the line carrying the address of the page this post exists to reach
     * clamped away entirely. Nothing errored; the post simply stopped saying the thing it
     * was written to say.
     *
     * Two blank lines cost two of the seven, which is what made a body of only 250
     * characters overflow. So the body is one paragraph, and it is built to a budget.
     */
    public const PULSE_CHARS = 200;

    /**
     * The body: who won, and the arithmetic that decided it.
     *
     * The SPLIT is in the post, not just the index. A feed card reading "won with 849" is a
     * number to take on trust; one reading "849 — 354 community, 495 judges" is a claim
     * somebody can check, and the page it links to shows every step. That difference is the
     * platform's entire argument about itself, and it belongs in the first thing anybody
     * reads rather than two clicks away.
     *
     * ── AND NO URL IN THE TEXT ───────────────────────────────────────────────
     *
     * The address is ninety characters of display type — three of the seven lines, for a
     * link the card already carries as its own action ({@see PulseFeedService}, which turns
     * a `result-` slug into the result page). Printing it here would push the split out of
     * the card to save nothing.
     *
     * Sentences are appended in priority order and stop when the budget is spent, rather
     * than being truncated: a clause cut mid-word reads as a fault, and the one most likely
     * to be dropped is the least important. A long enough winner's name legitimately leaves
     * no room for the runner-up, and the page has them.
     */
    private static function body(array $r, array $w): string
    {
        $cat  = (string) ($r['category']->title ?? 'the award');
        $lead = (string) $w['name'] . ' takes ' . $cat . '.';

        $rest = ['Index ' . (int) $w['cpi'] . ' of 1000 — ' . (int) $w['community_points']
                 . ' community, ' . (int) $w['judge_points'] . ' judges.'];

        // Named in the post rather than left for the page. A margin of one point and a
        // margin of two hundred are different results, and the one people should look at
        // hardest is the one the feed is least likely to make them click through for. Ahead
        // of the runner-up in priority for that reason.
        if (!empty($r['dead_heat'])) {
            $rest[] = 'A dead heat, separated by nominee id.';
        } elseif (!empty($r['tie_broken_by_votes'])) {
            $rest[] = 'The index tied; community support broke it.';
        } elseif (($r['margin'] ?? null) !== null && (int) $r['margin'] <= 10) {
            $rest[] = 'Decided by ' . (int) $r['margin'] . ' point'
                    . ((int) $r['margin'] === 1 ? '' : 's') . '.';
        }

        if (!empty($r['runner_up'])) {
            $rest[] = 'Runner-up: ' . (string) $r['runner_up']['name']
                    . ' on ' . (int) $r['runner_up']['cpi'] . '.';
        }

        $body = $lead;
        foreach ($rest as $part) {
            if (mb_strlen($body) + 1 + mb_strlen($part) > self::PULSE_CHARS) break;
            $body .= ' ' . $part;
        }

        return $body;
    }
}
