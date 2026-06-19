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
        $id = (int)DB::table('gates_comments')->insertGetId([
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
        ]);
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
    public function listThreads(?int $programmeId = null, int $limit = 30): array
    {
        $q = DB::table('gates_threads')->where('status', 'approved')->orderByDesc('is_pinned')->orderByDesc('last_activity');
        if ($programmeId) $q->where('programme_id', $programmeId);
        return $q->limit($limit)->get()->map(fn($r) => (array)$r)->all();
    }

    public function getThread(string $slug): ?array
    {
        $t = DB::table('gates_threads')->where('slug',$slug)->where('status','approved')->first();
        if (!$t) return null;
        $replies = DB::table('gates_comments')->where('target_type','thread')->where('target_id',$t->id)
            ->where('status','approved')->orderBy('id')->get()->map(fn($r) => (array)$r)->all();
        return ['thread' => (array)$t, 'replies' => $replies];
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

        $id = (int)DB::table('gates_threads')->insertGetId([
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
        ]);
        $this->spam->logDecision('thread', $id, $verdict);
        if ($status === 'approved') {
            $this->recordActivity('comment', $author, 'thread', $id, $title);
        }
        return ['ok' => true, 'id' => $id, 'slug' => $slug, 'status' => $status];
    }

    public function replyToThread(int $threadId, array $data, string $ip = ''): array
    {
        $r = $this->postComment('thread', $threadId, $data, $ip);
        if ($r['ok'] && ($r['status'] ?? '') === 'approved') {
            DB::table('gates_threads')->where('id', $threadId)
                ->update(['reply_count' => DB::raw('reply_count + 1'), 'last_activity' => Carbon::now()->toDateTimeString()]);
        }
        return $r;
    }
}
