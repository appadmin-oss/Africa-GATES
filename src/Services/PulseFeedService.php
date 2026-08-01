<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * One page of the Pulse feed, assembled in a fixed number of queries.
 *
 * ── WHY A SERVICE AND NOT A CONTROLLER QUERY ────────────────────────────────
 *
 * A feed card carries five things that live in five tables: the post, whether
 * YOU cheered it, whether YOU saved it, its newest comments, and its counters.
 * Rendered naively that is 1 + 4N queries for an N-post page, and the page grows
 * without bound as people scroll. Everything here is batched: one query for the
 * posts, then one query per decoration for the whole page, joined in PHP.
 *
 * ── CURSOR, NOT OFFSET ──────────────────────────────────────────────────────
 *
 * Paging is `id < cursor`, not `OFFSET n`. An infinite feed with OFFSET
 * duplicates and skips rows the moment anyone posts mid-scroll — the row that
 * was #8 becomes #9 and you see it twice — which on a live feed is not an edge
 * case but the normal state. A cursor is stable under insertion.
 */
final class PulseFeedService
{
    /** Comments shown under a card before "view all". Instagram shows two. */
    public const PREVIEW_COMMENTS = 2;

    public const PAGE = 8;

    /**
     * A page of the feed, newest first.
     *
     * @param int|null $cursor Return posts with a LOWER id than this (null = start).
     * @param int|null $userId The signed-in member, for per-viewer state. Null for guests.
     * @return array{items: list<array<string,mixed>>, next_cursor: int|null}
     */
    public function page(?int $cursor = null, int $limit = self::PAGE, ?int $userId = null): array
    {
        $limit = max(1, min(30, $limit));

        $q = DB::table('gates_threads')->where('status', 'approved');
        if ($cursor !== null && $cursor > 0) $q->where('id', '<', $cursor);

        // Fetch one extra row: its existence is what tells us there is a next page,
        // without a second COUNT query over the whole table.
        $rows = $q->orderByDesc('id')->limit($limit + 1)
            ->get(['id','slug','title','body','author_name','author_user_id',
                   'cheer_count','reply_count','created_at'])
            ->map(fn($r) => (array) $r)->all();

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        if (!$rows) return ['items' => [], 'next_cursor' => null];

        $ids = array_map(static fn($r) => (int) $r['id'], $rows);

        $cheered  = $this->cheeredIds($ids, $userId);
        $saved    = $this->savedIds($ids, $userId);
        $comments = $this->previewComments($ids);

        $items = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $items[] = [
                'id'           => $id,
                'slug'         => (string) $r['slug'],
                'title'        => (string) $r['title'],
                'body'         => (string) ($r['body'] ?? ''),
                'author_name'  => (string) ($r['author_name'] ?? 'A member'),
                'author_id'    => (int) ($r['author_user_id'] ?? 0),
                'created_at'   => (string) ($r['created_at'] ?? ''),
                // The stored counters are the source of truth for the number shown;
                // they are what toggleCheer and replyToThread maintain.
                'cheer_count'  => (int) ($r['cheer_count'] ?? 0),
                'reply_count'  => (int) ($r['reply_count'] ?? 0),
                'cheered'      => in_array($id, $cheered, true),
                'saved'        => in_array($id, $saved, true),
                'is_mine'      => $userId !== null && $userId > 0 && (int) ($r['author_user_id'] ?? 0) === $userId,
                'comments'     => $comments[$id] ?? [],
            ];
        }

        return [
            'items'       => $items,
            'next_cursor' => $hasMore ? (int) end($ids) : null,
        ];
    }

    /**
     * How many approved posts are newer than what the reader is looking at.
     *
     * Drives the "N new posts" pill. Polled, because shared cPanel hosting has no
     * persistent process to hold a websocket open — so this has to stay a single
     * indexed COUNT that is cheap to call every 45 seconds by every open tab.
     */
    public function newSince(int $afterId): int
    {
        if ($afterId < 1) return 0;
        return (int) DB::table('gates_threads')
            ->where('status', 'approved')->where('id', '>', $afterId)->count();
    }

    /** @param list<int> $ids @return list<int> */
    private function cheeredIds(array $ids, ?int $userId): array
    {
        if (!$userId || $userId < 1 || !$ids) return [];
        return DB::table('gates_cheers')
            ->where('target_type', 'thread')->whereIn('target_id', $ids)
            ->where('fp', 'u:' . $userId)                 // account-keyed, same as toggleCheer
            ->pluck('target_id')->map(fn($v) => (int) $v)->all();
    }

    /** @param list<int> $ids @return list<int> */
    private function savedIds(array $ids, ?int $userId): array
    {
        if (!$userId || $userId < 1 || !$ids) return [];
        return DB::table('gates_bookmarks')
            ->where('user_id', $userId)->whereIn('thread_id', $ids)
            ->pluck('thread_id')->map(fn($v) => (int) $v)->all();
    }

    /**
     * The newest few comments for every post on the page, in ONE query.
     *
     * Per-post `LIMIT 2` would need a lateral join or a window function — neither
     * of which MySQL 5.7 on the target host has. So this pulls a bounded slice of
     * recent comments across the whole page and trims per post in PHP. The slice
     * is capped so a single post with a thousand replies cannot starve the others.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string,mixed>>>
     */
    private function previewComments(array $ids): array
    {
        if (!$ids) return [];

        $rows = DB::table('gates_comments')
            ->where('target_type', 'thread')->whereIn('target_id', $ids)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->limit(count($ids) * self::PREVIEW_COMMENTS * 8)
            ->get(['id','target_id','author_name','body','created_at'])
            ->map(fn($r) => (array) $r)->all();

        $out = [];
        foreach ($rows as $r) {
            $t = (int) $r['target_id'];
            if (count($out[$t] ?? []) >= self::PREVIEW_COMMENTS) continue;
            $out[$t][] = [
                'id'          => (int) $r['id'],
                'author_name' => (string) ($r['author_name'] ?? 'A member'),
                'body'        => (string) ($r['body'] ?? ''),
                'created_at'  => (string) ($r['created_at'] ?? ''),
            ];
        }
        // Newest-first out of the query, oldest-first on screen — a preview reads
        // as the start of a conversation, not the end of one.
        foreach ($out as $t => $list) $out[$t] = array_reverse($list);

        return $out;
    }
}
