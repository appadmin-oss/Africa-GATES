<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What happened to YOUR things while you were away.
 *
 * ── WHY THERE IS NO NOTIFICATIONS TABLE ──────────────────────────────────────
 *
 * The obvious build is a `gates_notifications` table written to on every cheer,
 * reply, repost and save. It is also the wrong one here, for three reasons that
 * all bite in production:
 *
 *   • It is a SECOND write on the hot path of every social action, on shared
 *     cPanel MySQL. A cheer currently costs one upsert and one counter update.
 *   • It has to be kept in step by hand. Un-cheer, delete a comment, moderate a
 *     post — each needs a matching delete, and the day one is forgotten the feed
 *     tells somebody about a reaction that no longer exists.
 *   • Everything it would contain is ALREADY STORED, with timestamps and
 *     indexes: gates_cheers, gates_comments, gates_reposts, gates_bookmarks,
 *     gates_follows, gates_vote_milestones.
 *
 * So alerts are DERIVED on read. The cost is a handful of indexed, LIMIT-bounded
 * queries per open; the benefit is that an alert cannot outlive the thing it is
 * about, and there is no backfill for the posts that already exist.
 *
 * ── THE ONE THING THAT MUST BE STORED ────────────────────────────────────────
 *
 * How far you have read. That is a single timestamp per member
 * (`gates_users.alerts_read_at`), and it is the only new state this feature
 * introduces.
 *
 * ── GROUPING IS THE FEATURE ──────────────────────────────────────────────────
 *
 * Forty-three separate "X cheered your post" rows is not a notifications screen,
 * it is a punishment. Events are grouped by (kind, post) and rendered as
 * "Amaka and 42 others cheered your post", newest first — which is also why this
 * pulls a bounded slice and folds in PHP rather than trying to GROUP BY in a way
 * MySQL 5.7 would need a window function for.
 */
final class AlertService
{
    /** Rows pulled per source before grouping. Bounds the work regardless of popularity. */
    private const PER_SOURCE = 60;

    /** Groups returned to the client. */
    public const PAGE = 30;

    /**
     * The alert list for one member, newest first.
     *
     * @return list<array{kind:string, text:string, at:string, href:string, unread:bool, actors:int}>
     */
    public function forMember(int $userId, string $email, int $limit = self::PAGE): array
    {
        if ($userId < 1) return [];

        $since  = $this->readAt($userId);
        $mine   = $this->myThreads($userId);
        $events = array_merge(
            $this->reactions($mine, $userId),
            $this->replies($mine, $userId),
            $this->reposts($mine, $userId),
            $this->saves($mine, $userId),
            $this->follows($email),
            $this->milestones($email),
        );

        // Newest first BEFORE grouping, so each group keeps its most recent
        // timestamp and the first actor named is the most recent one.
        usort($events, static fn($a, $b) => strcmp($b['at'], $a['at']));

        $groups = [];
        foreach ($events as $e) {
            $key = $e['kind'] . ':' . $e['subject_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = $e + ['actors' => 0, 'names' => []];
            }
            $groups[$key]['actors']++;
            if (count($groups[$key]['names']) < 1 && $e['actor'] !== '') {
                $groups[$key]['names'][] = $e['actor'];
            }
        }

        $out = [];
        foreach (array_slice(array_values($groups), 0, $limit) as $g) {
            $out[] = [
                'kind'   => $g['kind'],
                'text'   => $this->sentence($g),
                'at'     => $g['at'],
                'href'   => $g['href'],
                'actors' => $g['actors'],
                // Strict >: an event stamped in the same second as the read
                // watermark was on screen when you read it.
                'unread' => $since === null || strcmp($g['at'], $since) > 0,
            ];
        }
        return $out;
    }

    /**
     * How many groups are unread. Drives the dot on the bell.
     *
     * Deliberately the same code path as the list rather than a cheaper COUNT:
     * a badge that says 3 over a screen showing 2 is worse than no badge, and
     * the only way to guarantee they agree is to count what the screen shows.
     */
    public function unreadFor(int $userId, string $email): int
    {
        $n = 0;
        foreach ($this->forMember($userId, $email) as $a) {
            if ($a['unread']) $n++;
        }
        return $n;
    }

    /** Mark everything up to now as read. */
    public function markRead(int $userId): bool
    {
        if ($userId < 1) return false;
        try {
            $row = OptionalColumn::filter('gates_users',
                ['alerts_read_at' => date('Y-m-d H:i:s')], ['alerts_read_at']);
            if (!$row) {
                // Pre-migration. Failing loudly here would break the page; the
                // degradation is that everything stays unread, which is visible.
                error_log('[alerts] gates_users.alerts_read_at absent — run db:migrate.');
                return false;
            }
            DB::table('gates_users')->where('id', $userId)->update($row);
            return true;
        } catch (\Throwable $e) {
            error_log('[alerts] could not mark read: ' . $e->getMessage());
            return false;
        }
    }

    // ── the sentence ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $g */
    private function sentence(array $g): string
    {
        $who    = $g['names'][0] ?? 'Someone';
        $others = max(0, ((int) $g['actors']) - 1);
        $lead   = $others > 0
            ? $who . ' and ' . $others . ' other' . ($others === 1 ? '' : 's')
            : $who;

        return match ($g['kind']) {
            'reaction'  => $lead . ' reacted to your post',
            'reply'     => $others > 0 ? $lead . ' replied to your post' : $who . ' replied to your post',
            'repost'    => $lead . ' reposted your post',
            'save'      => $lead . ' saved your post',
            'follow'    => $lead . ' started following you',
            'milestone' => $g['label'],
            default     => $lead,
        };
    }

    // ── sources ──────────────────────────────────────────────────────────────

    /** @return array<int,string> thread id → slug */
    private function myThreads(int $userId): array
    {
        try {
            return DB::table('gates_threads')
                ->where('author_user_id', $userId)->where('status', 'approved')
                ->orderByDesc('id')->limit(120)
                ->pluck('slug', 'id')->map(fn($v) => (string) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int,string> $mine @return list<array<string,mixed>> */
    private function reactions(array $mine, int $userId): array
    {
        if (!$mine || !OptionalColumn::on('gates_cheers', 'created_at')) return [];
        try {
            $rows = DB::table('gates_cheers')
                ->where('target_type', 'thread')->whereIn('target_id', array_keys($mine))
                ->where('fp', '!=', 'u:' . $userId)          // your own reaction is not news
                ->orderByDesc('id')->limit(self::PER_SOURCE)
                ->get(['target_id', 'fp', 'created_at']);
        } catch (\Throwable) { return []; }

        $names = $this->namesForFingerprints($rows->pluck('fp')->all());
        return $rows->map(fn($r) => [
            'kind' => 'reaction', 'subject_id' => (int) $r->target_id,
            'actor' => $names[(string) $r->fp] ?? 'Someone',
            'at' => (string) $r->created_at,
            'href' => '/community/' . ($mine[(int) $r->target_id] ?? ''),
        ])->all();
    }

    /** @param array<int,string> $mine @return list<array<string,mixed>> */
    private function replies(array $mine, int $userId): array
    {
        if (!$mine) return [];
        try {
            $rows = DB::table('gates_comments')
                ->where('target_type', 'thread')->whereIn('target_id', array_keys($mine))
                ->where('status', 'approved')
                ->where(function ($q) use ($userId) {
                    $q->whereNull('author_user_id')->orWhere('author_user_id', '!=', $userId);
                })
                ->orderByDesc('id')->limit(self::PER_SOURCE)
                ->get(['target_id', 'author_name', 'created_at']);
        } catch (\Throwable) { return []; }

        return $rows->map(fn($r) => [
            'kind' => 'reply', 'subject_id' => (int) $r->target_id,
            'actor' => (string) ($r->author_name ?: 'Someone'),
            'at' => (string) $r->created_at,
            'href' => '/community/' . ($mine[(int) $r->target_id] ?? ''),
        ])->all();
    }

    /** @param array<int,string> $mine @return list<array<string,mixed>> */
    private function reposts(array $mine, int $userId): array
    {
        if (!$mine) return [];
        try {
            $rows = DB::table('gates_reposts')
                ->whereIn('thread_id', array_keys($mine))->where('user_id', '!=', $userId)
                ->orderByDesc('id')->limit(self::PER_SOURCE)
                ->get(['thread_id', 'user_id', 'created_at']);
        } catch (\Throwable) { return []; }

        $names = $this->namesForIds($rows->pluck('user_id')->all());
        return $rows->map(fn($r) => [
            'kind' => 'repost', 'subject_id' => (int) $r->thread_id,
            'actor' => $names[(int) $r->user_id] ?? 'Someone',
            'at' => (string) $r->created_at,
            'href' => '/community/' . ($mine[(int) $r->thread_id] ?? ''),
        ])->all();
    }

    /** @param array<int,string> $mine @return list<array<string,mixed>> */
    private function saves(array $mine, int $userId): array
    {
        if (!$mine) return [];
        try {
            $rows = DB::table('gates_bookmarks')
                ->whereIn('thread_id', array_keys($mine))->where('user_id', '!=', $userId)
                ->orderByDesc('id')->limit(self::PER_SOURCE)
                ->get(['thread_id', 'user_id', 'created_at']);
        } catch (\Throwable) { return []; }

        $names = $this->namesForIds($rows->pluck('user_id')->all());
        return $rows->map(fn($r) => [
            'kind' => 'save', 'subject_id' => (int) $r->thread_id,
            'actor' => $names[(int) $r->user_id] ?? 'Someone',
            'at' => (string) $r->created_at,
            'href' => '/community/' . ($mine[(int) $r->thread_id] ?? ''),
        ])->all();
    }

    /**
     * People following YOUR profile.
     *
     * gates_follows points at a profile, not an account, so this needs the
     * profile that belongs to this address. Somebody with no public profile
     * simply has no follow alerts, which is correct rather than empty.
     *
     * @return list<array<string,mixed>>
     */
    private function follows(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') return [];
        try {
            $profileId = (int) (DB::table('gates_profiles')
                ->whereRaw('LOWER(email) = ?', [$email])->value('id') ?? 0);
            if ($profileId < 1) return [];

            $rows = DB::table('gates_follows')
                ->where('target_type', 'profile')->where('target_id', $profileId)
                ->orderByDesc('id')->limit(self::PER_SOURCE)
                ->get(['user_id', 'created_at']);
        } catch (\Throwable) { return []; }

        $names = $this->namesForIds($rows->pluck('user_id')->all());
        // subject_id is the FOLLOWER, not the target: grouping every follow
        // under one profile id would collapse fifty different people into one
        // line reading "Amaka and 49 others started following you" forever.
        return $rows->map(fn($r) => [
            'kind' => 'follow', 'subject_id' => (int) $r->user_id,
            'actor' => $names[(int) $r->user_id] ?? 'Someone',
            'at' => (string) $r->created_at, 'href' => '/community',
        ])->all();
    }

    /**
     * Vote milestones on a nominee linked to your profile.
     *
     * The one alert that is genuinely good news, so it is worth the extra join.
     *
     * Routed through `gates_nominees.profile_id` — the table has no email and no
     * slug of its own. Matching on a column that does not exist would have thrown
     * into the catch below and returned an empty list forever, which is the exact
     * silent-degradation shape this codebase keeps getting bitten by.
     *
     * The link goes to the registry profile rather than the ballot: a nominee URL
     * is /vote/{programme}/{slug} and needs two more joins to build, while the
     * profile is already here and is where somebody celebrating wants to land.
     *
     * @return list<array<string,mixed>>
     */
    private function milestones(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') return [];
        try {
            $rows = DB::table('gates_vote_milestones as m')
                ->join('gates_nominees as n', 'n.id', '=', 'm.nominee_id')
                ->join('gates_profiles as p', 'p.id', '=', 'n.profile_id')
                ->whereRaw('LOWER(p.email) = ?', [$email])
                ->orderByDesc('m.id')->limit(20)
                ->get(['m.id', 'm.milestone', 'm.achieved_at', 'n.name', 'p.slug']);
        } catch (\Throwable $e) {
            error_log('[alerts] milestone lookup unavailable: ' . $e->getMessage());
            return [];
        }

        return $rows->map(fn($r) => [
            'kind' => 'milestone', 'subject_id' => (int) $r->id, 'actor' => '',
            'label' => number_format((int) $r->milestone) . ' votes for ' . (string) $r->name
                     . ' — milestone reached',
            'at' => (string) $r->achieved_at,
            'href' => '/registry/' . (string) ($r->slug ?? ''),
        ])->all();
    }

    // ── names ────────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $fps `u:<id>` fingerprints
     * @return array<string,string>
     */
    private function namesForFingerprints(array $fps): array
    {
        $ids = [];
        foreach ($fps as $fp) {
            if (preg_match('/^u:(\d+)$/', (string) $fp, $m)) $ids[] = (int) $m[1];
        }
        $names = $this->namesForIds($ids);

        $out = [];
        foreach ($names as $id => $name) $out['u:' . $id] = $name;
        return $out;
    }

    /**
     * @param list<mixed> $ids
     * @return array<int,string> first names — a notification is a sentence, not a directory
     */
    private function namesForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        try {
            return DB::table('gates_users')->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->map(fn($v) => trim(explode(' ', trim((string) $v))[0]) ?: 'Someone')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** The read watermark, or null when nothing has ever been read. */
    private function readAt(int $userId): ?string
    {
        if (!OptionalColumn::on('gates_users', 'alerts_read_at')) return null;
        try {
            $v = DB::table('gates_users')->where('id', $userId)->value('alerts_read_at');
            return $v !== null && $v !== '' ? (string) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
