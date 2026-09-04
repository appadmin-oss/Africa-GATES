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
     * The body: who won, and the arithmetic that decided it.
     *
     * The SPLIT is in the post, not just the index. A feed card reading "won with 812" is a
     * number to take on trust; one reading "812 — 371 community, 441 judges" is a claim
     * somebody can check, and the page it links to shows every step. That difference is the
     * platform's entire argument about itself, and it belongs in the first thing anybody
     * reads rather than two clicks away.
     */
    private static function body(array $r, array $w): string
    {
        $lines = [];
        $lines[] = (string) $w['name'] . ' takes ' . (string) ($r['category']->title ?? 'the award') . '.';
        $lines[] = '';
        $lines[] = 'Cultural Power Index ' . (int) $w['cpi'] . ' of 1000 — '
                 . (int) $w['community_points'] . ' from the community, '
                 . (int) $w['judge_points'] . ' from the panel.';

        if (!empty($r['runner_up'])) {
            $lines[] = 'Runner-up: ' . (string) $r['runner_up']['name']
                     . ' on ' . (int) $r['runner_up']['cpi'] . '.';
        }

        // Named in the post rather than left for the page. A margin of one point and a
        // margin of two hundred are different results, and the one people should look at
        // hardest is the one the feed is least likely to make them click through for.
        if (!empty($r['dead_heat'])) {
            $lines[] = 'This one was a dead heat on the index and on community support — '
                     . 'the full standing explains how it was separated.';
        } elseif (!empty($r['tie_broken_by_votes'])) {
            $lines[] = 'The index tied. Community support broke it, which is the point of '
                     . 'keeping purchased votes out of the ranking.';
        } elseif (($r['margin'] ?? null) !== null && (int) $r['margin'] <= 10) {
            $lines[] = 'Decided by ' . (int) $r['margin'] . ' point'
                     . ((int) $r['margin'] === 1 ? '' : 's') . ' on a thousand-point index.';
        }

        $lines[] = '';
        // ABSOLUTE. The feed card renders a body as text and links what looks like a URL;
        // a bare `/results/12-…` is not one, so the single line whose job is to carry the
        // reader to the working would have rendered as unclickable text on the one surface
        // this post exists for. {@see SiteUrl::base()} needs no request — it is APP_URL
        // first, and this runs from cron where there is none.
        $lines[] = 'Every score, every weight and every denominator: '
                 . \AfricaGates\Support\SiteUrl::base() . (string) $r['url'];

        return implode("\n", $lines);
    }
}
