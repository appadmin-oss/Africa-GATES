<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\OptionalColumn;

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

    /** @var array<int,string>|null programme id → name, resolved once per instance */
    private ?array $channelNames = null;

    /**
     * A page of the feed, newest first.
     *
     * @param int|null $cursor Return posts with a LOWER id than this (null = start).
     * @param int|null $userId The signed-in member, for per-viewer state. Null for guests.
     * @param int|null $programmeId Restrict to one channel. Null = every channel.
     * @return array{items: list<array<string,mixed>>, next_cursor: int|null}
     */
    public function page(?int $cursor = null, int $limit = self::PAGE, ?int $userId = null, ?int $programmeId = null): array
    {
        $limit = max(1, min(30, $limit));

        $q = DB::table('gates_threads')->where('status', 'approved');
        if ($cursor !== null && $cursor > 0) $q->where('id', '<', $cursor);
        // Filtered in SQL, not in the browser. Filtering a loaded page client-side
        // makes "Education" show three posts because that is how many happened to
        // be in the first eight — and scrolling for more re-runs the unfiltered
        // query, so the channel silently leaks other channels back in.
        if ($programmeId !== null && $programmeId > 0) $q->where('programme_id', $programmeId);

        // Fetch one extra row: its existence is what tells us there is a next page,
        // without a second COUNT query over the whole table.
        $cols = ['id','slug','title','body','author_name','author_user_id',
                 'programme_id','cheer_count','reply_count','created_at'];
        // repost_count has been in the community schema from the start and read
        // zero for just as long. Selected optionally anyway: it is absent from
        // some very old installs, and one missing column must not 500 the feed.
        if (OptionalColumn::on('gates_threads', 'repost_count')) $cols[] = 'repost_count';
        // Selected only where they exist. A database between a deploy and
        // `db:migrate` has no media columns, and naming one in a SELECT is a hard
        // error — the feed would 500 rather than render text posts it can render
        // perfectly well. See database/migrations/2026_08_01_thread_media.php.
        foreach (['media_path','media_type','media_w','media_h'] as $c) {
            if (OptionalColumn::on('gates_threads', $c)) $cols[] = $c;
        }

        $rows = $q->orderByDesc('id')->limit($limit + 1)
            ->get($cols)
            ->map(fn($r) => (array) $r)->all();

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        if (!$rows) return ['items' => [], 'next_cursor' => null];

        $ids = array_map(static fn($r) => (int) $r['id'], $rows);

        $cheered  = $this->cheeredIds($ids, $userId);
        $saved    = $this->savedIds($ids, $userId);
        $comments = $this->previewComments($ids);
        // Batched, like every other decoration here: the action rail shows a
        // breakdown per post, and asking per post would be one query per card.
        $kinds     = $this->reactionKinds($ids);
        $myKind    = $this->myReactionKinds($ids, $userId);
        $reposted  = $this->repostedIds($ids, $userId);
        $channels  = $this->channelNames();

        $items = [];
        foreach ($rows as $r) {
            $id  = (int) $r['id'];
            $pid = (int) ($r['programme_id'] ?? 0);
            $items[] = [
                'id'           => $id,
                'slug'         => (string) $r['slug'],
                'title'        => (string) $r['title'],
                // The channel a post belongs to. Named here rather than joined,
                // because the same handful of programmes decorate every page and a
                // join would carry the name down the wire once per row.
                'programme_id' => $pid,
                'channel'      => $channels[$pid] ?? '',
                'body'         => (string) ($r['body'] ?? ''),
                'author_name'  => (string) ($r['author_name'] ?? 'A member'),
                'author_id'    => (int) ($r['author_user_id'] ?? 0),
                'created_at'   => (string) ($r['created_at'] ?? ''),
                // The stored counters are the source of truth for the number shown;
                // they are what toggleCheer and replyToThread maintain.
                'cheer_count'  => (int) ($r['cheer_count'] ?? 0),
                'reply_count'  => (int) ($r['reply_count'] ?? 0),
                'cheered'      => in_array($id, $cheered, true),
                // Which of the four this viewer holds, or null. The rail draws the
                // held one filled; `cheered` stays for every caller that predates
                // reactions and only wants the boolean.
                'my_reaction'  => $myKind[$id] ?? null,
                'reactions'    => $kinds[$id] ?? [],
                'repost_count' => (int) ($r['repost_count'] ?? 0),
                'reposted'     => in_array($id, $reposted, true),
                'saved'        => in_array($id, $saved, true),
                'is_mine'      => $userId !== null && $userId > 0 && (int) ($r['author_user_id'] ?? 0) === $userId,
                'comments'     => $comments[$id] ?? [],
                // Null for a text post, and for every post on a database that has
                // not been migrated yet. The renderer treats null as "no media".
                'media'        => $this->media($r),
            ];
        }

        return [
            'items'       => $items,
            'next_cursor' => $hasMore ? (int) end($ids) : null,
        ];
    }

    /**
     * The channels worth showing as filter chips: programmes people actually post in.
     *
     * Driven by the posts, not by the programme table. A chip for a channel with
     * nothing behind it is a promise the feed cannot keep — you press "Choral",
     * get an empty page, and conclude the filter is broken rather than that the
     * channel is quiet. Ordered by volume, so the busiest reads first.
     *
     * @return list<array{id:int, name:string, n:int}>
     */
    public function channels(int $limit = 8): array
    {
        try {
            $counts = DB::table('gates_threads')
                ->where('status', 'approved')->whereNotNull('programme_id')
                ->selectRaw('programme_id, COUNT(*) as n')
                ->groupBy('programme_id')->orderByDesc('n')->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            error_log('[pulse] channels unavailable: ' . $e->getMessage());
            return [];
        }

        $names = $this->channelNames();
        $out   = [];
        foreach ($counts as $c) {
            $id = (int) $c->programme_id;
            // A post pointing at a programme that has since been deleted keeps its
            // id but has no name. Chipping it as "" would render a blank button.
            if (!isset($names[$id])) continue;
            $out[] = ['id' => $id, 'name' => $names[$id], 'n' => (int) $c->n];
        }
        return $out;
    }

    /**
     * @return array<int,string> programme id → display name
     *
     * Cached on the INSTANCE, not statically. A static cache outlives the request
     * in a long-running worker and, more immediately, outlives a test — the next
     * one seeds different programmes and reads the previous test's names.
     */
    private function channelNames(): array
    {
        if ($this->channelNames !== null) return $this->channelNames;
        try {
            $this->channelNames = DB::table('gates_award_programmes')
                ->pluck('title', 'id')->map(fn($v) => (string) $v)->all();
        } catch (\Throwable) {
            $this->channelNames = [];   // no programme table on a partly-migrated database
        }
        return $this->channelNames;
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

    /**
     * The post's attachment, or null.
     *
     * `type` is normalised to the two values the renderer knows how to emit. A row
     * carrying anything else — a value from a future migration, or a hand-edited
     * database — is treated as having no media rather than rendered as a guess: an
     * <img> pointed at a video shows a broken icon, which looks like the upload
     * failed when it did not.
     *
     * @param array<string,mixed> $r
     * @return array{path:string,type:string,w:int,h:int}|null
     */
    private function media(array $r): ?array
    {
        $path = trim((string) ($r['media_path'] ?? ''));
        $type = strtolower(trim((string) ($r['media_type'] ?? '')));
        if ($path === '' || !in_array($type, ['image', 'video'], true)) return null;

        return [
            'path' => $path,
            'type' => $type,
            'w'    => (int) ($r['media_w'] ?? 0),
            'h'    => (int) ($r['media_h'] ?? 0),
        ];
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

    /**
     * Reaction counts per post, keyed by kind. One query for the whole page.
     *
     * @param list<int> $ids
     * @return array<int, array<string,int>>
     */
    private function reactionKinds(array $ids): array
    {
        if (!$ids) return [];
        // Pre-migration every reaction is a cheer, and `cheer_count` on the row
        // already says how many — so the rail draws correctly without this table
        // being asked for a column it does not have.
        if (!OptionalColumn::on('gates_cheers', 'kind')) return [];

        try {
            $rows = DB::table('gates_cheers')
                ->where('target_type', 'thread')->whereIn('target_id', $ids)
                ->selectRaw('target_id, kind, COUNT(*) as n')
                ->groupBy('target_id', 'kind')->get();
        } catch (\Throwable $e) {
            error_log('[pulse] reaction breakdown unavailable: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->target_id][(string) $r->kind] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Which reaction THIS viewer holds on each post.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function myReactionKinds(array $ids, ?int $userId): array
    {
        if (!$userId || $userId < 1 || !$ids) return [];
        if (!OptionalColumn::on('gates_cheers', 'kind')) {
            // Everything is a cheer on an unmigrated database, and saying so is
            // more useful to the rail than saying nothing at all.
            return array_fill_keys($this->cheeredIds($ids, $userId), 'cheer');
        }
        try {
            return DB::table('gates_cheers')
                ->where('target_type', 'thread')->whereIn('target_id', $ids)
                ->where('fp', 'u:' . $userId)
                ->pluck('kind', 'target_id')->map(fn($v) => (string) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param list<int> $ids @return list<int> */
    private function repostedIds(array $ids, ?int $userId): array
    {
        if (!$userId || $userId < 1 || !$ids) return [];
        try {
            // Keyed on user_id, not on the `u:<id>` fingerprint the cheers table
            // uses — gates_reposts predates that convention and has always been
            // members-only, so the account id IS the key.
            return DB::table('gates_reposts')->whereIn('thread_id', $ids)
                ->where('user_id', $userId)
                ->pluck('thread_id')->map(fn($v) => (int) $v)->all();
        } catch (\Throwable) {
            return [];   // table absent until db:migrate — nothing shows as held
        }
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
