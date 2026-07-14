<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

class CommunityService
{
    public function __construct(
        private readonly SpamService $spam
    ) {}

    /**
     * Post a comment. AI-moderated before persistence.
     * @return array{ok:bool, id?:int, status?:string, message?:string}
     */
    public function postComment(string $targetType, int $targetId, array $data, string $ip = ''): array
    {
        $body = trim((string)($data['body'] ?? ''));
        $author = trim((string)($data['author_name'] ?? ''));
        $email = strtolower(trim((string)($data['author_email'] ?? '')));

        if ($body === '' || $author === '') {
            return ['ok' => false, 'message' => 'Name and message are required.'];
        }
        if (mb_strlen($body) > 2000) {
            return ['ok' => false, 'message' => 'Keep it under 2,000 characters.'];
        }
        if (!in_array($targetType, ['profile','legacy','thread','nominee'], true)) {
            return ['ok' => false, 'message' => 'Invalid target.'];
        }

        $verdict = $this->spam->evaluate($body, ['target' => $targetType]);
        $authorVerdict = $this->spam->evaluate($author, ['author' => true]);
        $finalScore = max($verdict['score'], $authorVerdict['score']);
        $decision = $verdict['decision'];
        if ($authorVerdict['decision'] === 'reject') $decision = 'reject';
        elseif ($authorVerdict['decision'] === 'quarantine' && $decision === 'allow') $decision = 'quarantine';

        if ($decision === 'reject') {
            return ['ok' => false, 'message' => 'Looks like spam. Please rephrase.'];
        }

        $status = $decision === 'allow' ? 'approved' : 'quarantined';
        $row = [
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'parent_id'   => isset($data['parent_id']) ? (int)$data['parent_id'] : null,
            'author_name' => $author,
            'author_email' => $email,
            'author_email_hash' => $email ? hash('sha256', $email) : null,
            'body'        => $body,
            'status'      => $status,
            'ai_score'    => $finalScore,
            'ai_reason'   => $verdict['reason'],
            'ip_hash'     => $ip ? hash('sha256', $ip) : null,
            'created_at'  => Carbon::now()->toDateTimeString(),
        ];
        // Member attribution (community v2: posting is members-only). Guarded so
        // a pre-migration deploy degrades to anonymous rows instead of a 500.
        if (!empty($data['author_user_id']) && self::hasAuthorColumn()) {
            $row['author_user_id'] = (int)$data['author_user_id'];
        }
        $id = (int)DB::table('gates_comments')->insertGetId($row);
        $this->spam->logDecision('comment', $id, $verdict);

        if ($status === 'approved') {
            $this->recordActivity('comment', $author, $targetType, $targetId, $this->resolveLabel($targetType, $targetId));
        }

        return ['ok' => true, 'id' => $id, 'status' => $status];
    }

    public function listComments(string $targetType, int $targetId, int $limit = 50): array
    {
        return DB::table('gates_comments')
            ->where('target_type', $targetType)->where('target_id', $targetId)
            ->where('status', 'approved')
            ->orderByDesc('id')->limit($limit)
            ->get(['id','parent_id','author_name','body','created_at'])
            ->map(fn($r) => (array)$r)->all();
    }

    /** Toggle a cheer (idempotent). Returns ['cheered'=>bool, 'count'=>int]. */
    public function toggleCheer(string $targetType, int $targetId, string $fp): array
    {
        if (!in_array($targetType, ['profile','nominee','comment','thread'], true)) {
            return ['cheered' => false, 'count' => $this->cheerCount($targetType, $targetId)];
        }
        $row = DB::table('gates_cheers')->where('target_type',$targetType)->where('target_id',$targetId)->where('fp',$fp)->first();
        if ($row) {
            DB::table('gates_cheers')->where('id', $row->id)->delete();
            $count = $this->cheerCount($targetType, $targetId);
            $this->syncCheerCount($targetType, $targetId, $count);
            return ['cheered' => false, 'count' => $count];
        }
        DB::table('gates_cheers')->insert([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'fp' => $fp,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->recordActivity('cheer', 'a community member', $targetType, $targetId, $this->resolveLabel($targetType, $targetId));
        $count = $this->cheerCount($targetType, $targetId);
        $this->syncCheerCount($targetType, $targetId, $count);
        return ['cheered' => true, 'count' => $count];
    }

    /** Keep the denormalised gates_threads.cheer_count column in step with reality. */
    private function syncCheerCount(string $targetType, int $targetId, int $count): void
    {
        if ($targetType === 'thread') {
            DB::table('gates_threads')->where('id', $targetId)->update(['cheer_count' => $count]);
        }
    }

    public function cheerCount(string $targetType, int $targetId): int
    {
        return (int)DB::table('gates_cheers')->where('target_type',$targetType)->where('target_id',$targetId)->count();
    }

    public function activityFeed(int $limit = 30): array
    {
        return DB::table('gates_activity')->where('is_public', 1)
            ->orderByDesc('id')->limit($limit)
            ->get()->map(function ($r) {
                $arr = (array)$r;
                $arr['meta'] = $r->meta ? json_decode((string)$r->meta, true) : null;
                return $arr;
            })->all();
    }

    public function recordActivity(string $kind, ?string $actor, ?string $targetType, ?int $targetId, ?string $targetLabel, array $meta = []): void
    {
        try {
            DB::table('gates_activity')->insert([
                'kind' => $kind,
                'actor_label' => $actor,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_label' => $targetLabel,
                'meta' => $meta ? json_encode($meta) : null,
                'is_public' => 1,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {}
    }

    private function resolveLabel(string $targetType, int $targetId): string
    {
        try {
            return match ($targetType) {
                'profile'  => (string)DB::table('gates_profiles')->where('id',$targetId)->value('display_name'),
                'nominee'  => (string)DB::table('gates_nominees')->where('id',$targetId)->value('name'),
                'legacy'   => (string)DB::table('gates_legacy_events')->where('id',$targetId)->value('title'),
                'thread'   => (string)DB::table('gates_threads')->where('id',$targetId)->value('title'),
                default    => '',
            };
        } catch (\Throwable $e) { return ''; }
    }

    // ─── Forum threads ──────────────────────────────────────────
    public function listThreads(?int $programmeId = null, int $limit = 30, string $sort = 'latest'): array
    {
        $q = DB::table('gates_threads')->whereIn('status', ['approved', 'locked'])->orderByDesc('is_pinned');
        if ($sort === 'top') $q->orderByRaw('(reply_count + cheer_count) DESC');
        $q->orderByDesc('last_activity');
        if ($programmeId) $q->where('programme_id', $programmeId);
        return $q->limit($limit)->get()->map(fn($r) => (array)$r)->all();
    }

    public function getThread(string $slug, string $fp = ''): ?array
    {
        $t = DB::table('gates_threads')->where('slug',$slug)->whereIn('status',['approved','locked'])->first();
        if (!$t) return null;
        $replies = DB::table('gates_comments')->where('target_type','thread')->where('target_id',$t->id)
            ->where('status','approved')->orderBy('id')->get()->map(fn($r) => (array)$r)->all();
        return ['thread' => (array)$t, 'replies' => $replies, 'poll' => $this->getPoll('thread', (int)$t->id, $fp)];
    }

    public function postThread(array $data, string $ip = ''): array
    {
        $title = trim((string)($data['title'] ?? ''));
        $body  = trim((string)($data['body'] ?? ''));
        $author = trim((string)($data['author_name'] ?? ''));
        $email = strtolower(trim((string)($data['author_email'] ?? '')));
        $progId = isset($data['programme_id']) ? (int)$data['programme_id'] : null;
        if (!$title || !$body || !$author || !$email) return ['ok' => false, 'message' => 'All fields required.'];

        $verdict = $this->spam->evaluate($title . "\n\n" . $body);
        if ($verdict['decision'] === 'reject') return ['ok' => false, 'message' => 'Looks like spam. Please rephrase.'];
        $status = $verdict['decision'] === 'allow' ? 'approved' : 'quarantined';

        $slug = preg_replace('/[^a-z0-9-]+/i','-', strtolower($title));
        $slug = trim((string)$slug, '-');
        $base = $slug; $n = 1;
        while (DB::table('gates_threads')->where('slug', $slug)->exists()) { $slug = $base . '-' . ($n++); }

        $row = [
            'programme_id' => $progId,
            'slug' => $slug,
            'title' => $title,
            'body'  => $body,
            'author_name' => $author,
            'author_email_hash' => hash('sha256', $email),
            'status' => $status,
            'ai_score' => $verdict['score'],
            'reply_count' => 0,
            'cheer_count' => 0,
            'last_activity' => Carbon::now()->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
        if (!empty($data['author_user_id']) && self::hasAuthorColumn()) {
            $row['author_user_id'] = (int)$data['author_user_id'];
        }
        $id = (int)DB::table('gates_threads')->insertGetId($row);
        $this->spam->logDecision('thread', $id, $verdict);
        if ($status === 'approved') {
            $this->recordActivity('comment', $author, 'thread', $id, $title);
        }
        // Optional attached poll (question + 2–6 options).
        $pollQ = trim((string)($data['poll_question'] ?? ''));
        if ($pollQ !== '' && !empty($data['poll_options']) && is_array($data['poll_options'])) {
            $this->createPoll('thread', $id, $pollQ, $data['poll_options'], !empty($data['poll_multi']));
        }
        return ['ok' => true, 'id' => $id, 'slug' => $slug, 'status' => $status];
    }

    public function replyToThread(int $threadId, array $data, string $ip = ''): array
    {
        // Locked threads stay readable but take no new replies.
        $status = (string) DB::table('gates_threads')->where('id', $threadId)->value('status');
        if ($status === 'locked') {
            return ['ok' => false, 'message' => 'This thread has been locked by the moderators.'];
        }
        $r = $this->postComment('thread', $threadId, $data, $ip);
        if ($r['ok'] && ($r['status'] ?? '') === 'approved') {
            DB::table('gates_threads')->where('id', $threadId)
                ->update(['reply_count' => DB::raw('reply_count + 1'), 'last_activity' => Carbon::now()->toDateTimeString()]);
            $this->queueReplyNotification($threadId, trim((string)($data['author_name'] ?? '')));
        }
        return $r;
    }

    /**
     * Notify the thread author (members only — we know their account) that a
     * reply landed. Queued, so the posting request never waits on SMTP.
     */
    private function queueReplyNotification(int $threadId, string $replierName): void
    {
        try {
            if (!self::hasAuthorColumn()) return;
            $t = DB::table('gates_threads')->where('id', $threadId)->first(['author_user_id', 'title', 'slug']);
            if (!$t || empty($t->author_user_id)) return;
            (new QueueService())->push('community.reply_email', [
                'thread_id' => $threadId,
                'author_user_id' => (int) $t->author_user_id,
                'title' => (string) $t->title,
                'slug' => (string) $t->slug,
                'replier' => $replierName,
            ]);
        } catch (\Throwable) {}
    }

    /** Cached probe for the community-v2 attribution column (pre-migration safety). */
    private static ?bool $authorCol = null;
    private static function hasAuthorColumn(): bool
    {
        if (self::$authorCol === null) {
            try { self::$authorCol = DB::getSchemaBuilder()->hasColumn('gates_comments', 'author_user_id'); }
            catch (\Throwable) { self::$authorCol = false; }
        }
        return self::$authorCol;
    }

    // ─── Member reporting + own-post management (community v2) ──────────

    /** Distinct member reports before content auto-quarantines for review. */
    public const REPORT_THRESHOLD = 3;

    /**
     * A member reports content. One report per member per target (unique key);
     * at REPORT_THRESHOLD distinct reporters the content is quarantined and
     * lands in /admin/moderation. Reporting is deliberately quiet — reporters
     * are never revealed and repeat reports are idempotent.
     *
     * @return array{ok:bool, reported?:bool, quarantined?:bool, message?:string}
     */
    public function report(string $targetType, int $targetId, int $userId, string $reason = ''): array
    {
        if (!in_array($targetType, ['thread', 'comment'], true) || $targetId < 1 || $userId < 1) {
            return ['ok' => false, 'message' => 'Invalid report.'];
        }
        $table = $targetType === 'thread' ? 'gates_threads' : 'gates_comments';
        $row = DB::table($table)->where('id', $targetId)->first(['id', 'status']);
        if (!$row || in_array((string) $row->status, ['deleted', 'rejected'], true)) {
            return ['ok' => false, 'message' => 'That content is no longer available.'];
        }
        try {
            DB::table('gates_reports')->insertOrIgnore([
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'user_id'     => $userId,
                'reason'      => mb_substr(trim($reason), 0, 300),
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Could not record the report right now.'];
        }
        $count = (int) DB::table('gates_reports')->where('target_type', $targetType)->where('target_id', $targetId)->count();
        $quarantined = false;
        if ($count >= self::REPORT_THRESHOLD && (string) $row->status === 'approved') {
            DB::table($table)->where('id', $targetId)->update(['status' => 'quarantined']);
            $quarantined = true;
            // Same audit trail + moderation.flagged webhook as the AI pipeline.
            $this->spam->logDecision($targetType, $targetId, [
                'provider' => 'member-report',
                'decision' => 'quarantine',
                'score'    => 1.0,
                'reason'   => "reported by {$count} members",
            ]);
        }
        return ['ok' => true, 'reported' => true, 'quarantined' => $quarantined];
    }

    /**
     * A member removes their OWN thread or comment (soft delete — the row
     * stays for the audit trail, the public never sees it again).
     */
    public function deleteOwn(string $targetType, int $targetId, int $userId): array
    {
        if (!in_array($targetType, ['thread', 'comment'], true) || $userId < 1 || !self::hasAuthorColumn()) {
            return ['ok' => false, 'message' => 'Invalid request.'];
        }
        $table = $targetType === 'thread' ? 'gates_threads' : 'gates_comments';
        $row = DB::table($table)->where('id', $targetId)->first();
        if (!$row || (int) ($row->author_user_id ?? 0) !== $userId) {
            return ['ok' => false, 'message' => 'You can only remove your own posts.'];
        }
        if ((string) $row->status === 'deleted') return ['ok' => true];
        DB::table($table)->where('id', $targetId)->update(['status' => 'deleted']);
        if ($targetType === 'comment' && (string) $row->target_type === 'thread') {
            $count = (int) DB::table('gates_comments')->where('target_type', 'thread')->where('target_id', $row->target_id)->where('status', 'approved')->count();
            DB::table('gates_threads')->where('id', $row->target_id)->update(['reply_count' => $count]);
        }
        return ['ok' => true];
    }

    /** Operator controls: lock (readable, no replies) / pin a thread. */
    public function setThreadFlag(int $threadId, string $flag, bool $on): bool
    {
        if ($flag === 'locked') {
            $current = (string) DB::table('gates_threads')->where('id', $threadId)->value('status');
            if ($on && $current === 'approved')  return (bool) DB::table('gates_threads')->where('id', $threadId)->update(['status' => 'locked']);
            if (!$on && $current === 'locked')   return (bool) DB::table('gates_threads')->where('id', $threadId)->update(['status' => 'approved']);
            return false;
        }
        if ($flag === 'pinned') {
            return (bool) DB::table('gates_threads')->where('id', $threadId)->update(['is_pinned' => $on ? 1 : 0]);
        }
        return false;
    }

    // ─── Polls (attachable to a thread or a blog post) ──────────
    /** Attach a poll to a target (one per target). Options: 2–6 non-empty strings. multi = WhatsApp-style multiple answers. */
    public function createPoll(string $targetType, int $targetId, string $question, array $options, bool $multi = false): array
    {
        if (!in_array($targetType, ['thread', 'post'], true) || $targetId < 1) {
            return ['ok' => false, 'message' => 'Invalid poll target.'];
        }
        $question = trim($question);
        $opts = array_values(array_filter(array_map(fn($o) => trim((string)$o), $options), fn($o) => $o !== ''));
        $opts = array_slice($opts, 0, 6);
        if ($question === '' || count($opts) < 2) {
            return ['ok' => false, 'message' => 'A poll needs a question and at least two options.'];
        }
        if (DB::table('gates_polls')->where('target_type', $targetType)->where('target_id', $targetId)->exists()) {
            return ['ok' => false, 'message' => 'This item already has a poll.'];
        }
        $id = (int)DB::table('gates_polls')->insertGetId([
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'question'    => mb_substr($question, 0, 255),
            'options'     => json_encode(array_map(fn($o) => mb_substr($o, 0, 120), $opts)),
            'multi'       => $multi ? 1 : 0,
            'is_closed'   => 0,
            'created_at'  => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** Replace a target's poll (admin edit) — drops any existing poll first; empty question just clears it. */
    public function setPoll(string $targetType, int $targetId, string $question, array $options, bool $multi = false): array
    {
        $this->deletePoll($targetType, $targetId);
        if (trim($question) === '') return ['ok' => true, 'cleared' => true];
        return $this->createPoll($targetType, $targetId, $question, $options, $multi);
    }

    /** Remove a target's poll and all its votes. */
    public function deletePoll(string $targetType, int $targetId): void
    {
        $ids = DB::table('gates_polls')->where('target_type', $targetType)->where('target_id', $targetId)->pluck('id')->all();
        if ($ids) {
            DB::table('gates_poll_votes')->whereIn('poll_id', $ids)->delete();
            DB::table('gates_polls')->whereIn('id', $ids)->delete();
        }
    }

    /** A target's poll with per-option tallies, total voters, and the caller's vote(s). Null when none. */
    public function getPoll(string $targetType, int $targetId, string $fp = ''): ?array
    {
        $p = DB::table('gates_polls')->where('target_type', $targetType)->where('target_id', $targetId)->first();
        return $p ? $this->shapePoll($p, $fp) : null;
    }

    /** Shape a poll row → view/API payload. Percentages are per distinct voter (so multi-answer bars stay independent). */
    private function shapePoll(object $p, string $fp = ''): array
    {
        $options = json_decode((string)$p->options, true) ?: [];
        $counts = DB::table('gates_poll_votes')->where('poll_id', $p->id)
            ->selectRaw('option_index, COUNT(*) c')->groupBy('option_index')->pluck('c', 'option_index');
        $voters = (int)DB::table('gates_poll_votes')->where('poll_id', $p->id)->distinct()->count('fp');
        $tally = [];
        foreach ($options as $i => $label) {
            $c = (int)($counts[$i] ?? 0);
            $tally[] = ['index' => $i, 'label' => (string)$label, 'count' => $c, 'pct' => $voters > 0 ? (int)round($c * 100 / $voters) : 0];
        }
        $myVotes = [];
        if ($fp !== '') {
            $myVotes = DB::table('gates_poll_votes')->where('poll_id', $p->id)->where('fp', $fp)
                ->pluck('option_index')->map(fn($v) => (int)$v)->all();
        }
        return [
            'id' => (int)$p->id, 'target_type' => (string)$p->target_type, 'target_id' => (int)$p->target_id,
            'question' => (string)$p->question, 'options' => $tally, 'total' => $voters,
            'multi' => (int)$p->multi, 'is_closed' => (int)$p->is_closed,
            'my_votes' => $myVotes,
            'my_vote'  => $myVotes ? $myVotes[0] : null, // single-answer convenience
        ];
    }

    /** Cast a poll vote. Single-answer = replace; multi-answer = toggle the option. */
    public function votePoll(int $pollId, int $optionIndex, string $fp, ?int $userId = null): array
    {
        $poll = DB::table('gates_polls')->where('id', $pollId)->first();
        if (!$poll) return ['ok' => false, 'message' => 'Poll not found.'];
        if ((int)$poll->is_closed === 1) return ['ok' => false, 'message' => 'This poll is closed.'];
        $options = json_decode((string)$poll->options, true) ?: [];
        if ($optionIndex < 0 || $optionIndex >= count($options)) return ['ok' => false, 'message' => 'Invalid option.'];
        if ($fp === '') return ['ok' => false, 'message' => 'Could not identify voter.'];

        if ((int)$poll->multi === 1) {
            // Multi-answer: toggle this option for the voter.
            $existing = DB::table('gates_poll_votes')->where('poll_id', $pollId)->where('fp', $fp)->where('option_index', $optionIndex)->first();
            if ($existing) {
                DB::table('gates_poll_votes')->where('id', $existing->id)->delete();
            } else {
                DB::table('gates_poll_votes')->insert(['poll_id' => $pollId, 'option_index' => $optionIndex, 'fp' => $fp, 'user_id' => $userId, 'created_at' => Carbon::now()->toDateTimeString()]);
            }
        } else {
            // Single-answer: replace the voter's selection.
            DB::table('gates_poll_votes')->where('poll_id', $pollId)->where('fp', $fp)->delete();
            DB::table('gates_poll_votes')->insert(['poll_id' => $pollId, 'option_index' => $optionIndex, 'fp' => $fp, 'user_id' => $userId, 'created_at' => Carbon::now()->toDateTimeString()]);
        }
        return ['ok' => true] + $this->shapePoll($poll, $fp);
    }

    // ─── Follow / bookmark / repost (member-scoped) ─────────────
    /** Toggle a member follow of a programme / thread / member / nominee. */
    public function toggleFollow(int $userId, string $targetType, int $targetId): array
    {
        if ($userId < 1 || $targetId < 1 || !in_array($targetType, ['programme', 'thread', 'member', 'nominee'], true)) {
            return ['ok' => false, 'message' => 'Invalid follow target.'];
        }
        $row = DB::table('gates_follows')->where('user_id', $userId)->where('target_type', $targetType)->where('target_id', $targetId)->first();
        if ($row) {
            DB::table('gates_follows')->where('id', $row->id)->delete();
            return ['ok' => true, 'following' => false];
        }
        DB::table('gates_follows')->insert(['user_id' => $userId, 'target_type' => $targetType, 'target_id' => $targetId, 'created_at' => Carbon::now()->toDateTimeString()]);
        return ['ok' => true, 'following' => true];
    }

    public function toggleBookmark(int $userId, int $threadId): array
    {
        if ($userId < 1 || $threadId < 1) return ['ok' => false, 'message' => 'Invalid bookmark.'];
        $row = DB::table('gates_bookmarks')->where('user_id', $userId)->where('thread_id', $threadId)->first();
        if ($row) {
            DB::table('gates_bookmarks')->where('id', $row->id)->delete();
            return ['ok' => true, 'bookmarked' => false];
        }
        DB::table('gates_bookmarks')->insert(['user_id' => $userId, 'thread_id' => $threadId, 'created_at' => Carbon::now()->toDateTimeString()]);
        return ['ok' => true, 'bookmarked' => true];
    }

    public function toggleRepost(int $userId, int $threadId): array
    {
        if ($userId < 1 || $threadId < 1) return ['ok' => false, 'message' => 'Invalid repost.'];
        $row = DB::table('gates_reposts')->where('user_id', $userId)->where('thread_id', $threadId)->first();
        if ($row) {
            DB::table('gates_reposts')->where('id', $row->id)->delete();
            $reposted = false;
        } else {
            DB::table('gates_reposts')->insert(['user_id' => $userId, 'thread_id' => $threadId, 'created_at' => Carbon::now()->toDateTimeString()]);
            $reposted = true;
        }
        $count = (int)DB::table('gates_reposts')->where('thread_id', $threadId)->count();
        DB::table('gates_threads')->where('id', $threadId)->update(['repost_count' => $count]);
        return ['ok' => true, 'reposted' => $reposted, 'count' => $count];
    }

    public function isFollowing(int $userId, string $targetType, int $targetId): bool
    {
        return $userId > 0 && DB::table('gates_follows')->where('user_id', $userId)->where('target_type', $targetType)->where('target_id', $targetId)->exists();
    }

    public function isBookmarked(int $userId, int $threadId): bool
    {
        return $userId > 0 && DB::table('gates_bookmarks')->where('user_id', $userId)->where('thread_id', $threadId)->exists();
    }

    public function isReposted(int $userId, int $threadId): bool
    {
        return $userId > 0 && DB::table('gates_reposts')->where('user_id', $userId)->where('thread_id', $threadId)->exists();
    }

    /** Bulk per-member state for a thread list: which are bookmarked / reposted / followed. */
    public function memberThreadState(int $userId, array $threadIds): array
    {
        $out = ['bookmarked' => [], 'reposted' => [], 'following' => []];
        if ($userId < 1 || !$threadIds) return $out;
        $out['bookmarked'] = DB::table('gates_bookmarks')->where('user_id', $userId)->whereIn('thread_id', $threadIds)->pluck('thread_id')->map(fn($v) => (int)$v)->all();
        $out['reposted']   = DB::table('gates_reposts')->where('user_id', $userId)->whereIn('thread_id', $threadIds)->pluck('thread_id')->map(fn($v) => (int)$v)->all();
        $out['following']  = DB::table('gates_follows')->where('user_id', $userId)->where('target_type', 'thread')->whereIn('target_id', $threadIds)->pluck('target_id')->map(fn($v) => (int)$v)->all();
        return $out;
    }

    /** Threads a member has bookmarked (for the account dashboard). */
    public function bookmarkedThreads(int $userId, int $limit = 30): array
    {
        if ($userId < 1) return [];
        return DB::table('gates_bookmarks as b')
            ->join('gates_threads as t', 't.id', '=', 'b.thread_id')
            ->where('b.user_id', $userId)->where('t.status', 'approved')
            ->orderByDesc('b.id')->limit($limit)
            ->get(['t.slug', 't.title', 't.reply_count', 't.cheer_count', 'b.created_at as bookmarked_at'])
            ->map(fn($r) => (array)$r)->all();
    }
}
