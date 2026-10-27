<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Highlights: free promotion for a member's business, product, service or event — posted by
 * them, reviewed by a person, shown for a fixed time, always labelled as a member's highlight.
 *
 * The owner asked for promotion of goods and services, free and paid (27 Oct 2026); the plan
 * puts highlights first because no money moves (AUDIT-AND-PLAN §10). Paid placement builds on
 * this table later and is labelled "Promoted"; a free highlight never is.
 *
 * ── THE RULES, EACH ONE A REASON ─────────────────────────────────────────────
 *
 *   REVIEWED FIRST   nothing a member types reaches the public until a person approves it —
 *                    the same trust ladder the market's sellers start on.
 *   FIXED LIFE       LIVE_DAYS from approval, then it drops off by itself. A listing nobody
 *                    remembers to take down is an advert for a shop that has closed.
 *   A FEW EACH       MAX_OPEN per member, pending and live together, so the list is many
 *                    members rather than one member many times.
 *   NOT A NOMINEE    a highlight may not point at a ballot or a nominee page. Nominees are not
 *                    promoted, free or paid, while votes are cast (D15): the platform promotes
 *                    every nominee the same way, by equipping them (ShareKit), never by placing
 *                    one in front of the public.
 *   SAFE LINKS       https, or a path on this site; nothing a model or a member typed reaches
 *                    an href unchecked, here or on the way out.
 */
final class Highlights
{
    public const KINDS     = ['business', 'product', 'service', 'event'];
    public const LIVE_DAYS = 30;
    public const MAX_OPEN  = 3;

    public const TITLE_MIN = 4;
    public const TITLE_MAX = 80;
    public const BODY_MIN  = 20;
    public const BODY_MAX  = 400;

    public static function ready(): bool { return SchemaHas::table('gates_highlights'); }

    /**
     * A member's new highlight, pending review.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, id?:int, errors?:array<string,string>}
     */
    public static function submit(int $userId, array $in, ?string $imagePath = null): array
    {
        if (!self::ready() || $userId < 1) return ['ok' => false, 'errors' => ['form' => 'Highlights are not available right now.']];
        $v = self::validate($in);
        if ($v['errors'] !== []) return ['ok' => false, 'errors' => $v['errors']];
        if (self::openCount($userId) >= self::MAX_OPEN) {
            return ['ok' => false, 'errors' => ['form' => sprintf('You can have %d highlights waiting or live at once.', self::MAX_OPEN)]];
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_highlights')->insertGetId($v['row'] + [
            'user_id' => $userId, 'image_path' => $imagePath, 'status' => 'pending',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{row:array<string,mixed>, errors:array<string,string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $kind  = (string) ($in['kind'] ?? '');
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')));
        $body  = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['body'] ?? '')));
        $url   = trim((string) ($in['url'] ?? ''));
        $loc   = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['location'] ?? '')));

        if (!in_array($kind, self::KINDS, true)) $errors['kind'] = 'Choose what you are highlighting.';
        $tl = mb_strlen($title);
        if ($tl < self::TITLE_MIN || $tl > self::TITLE_MAX) $errors['title'] = sprintf('A title of %d to %d characters.', self::TITLE_MIN, self::TITLE_MAX);
        $bl = mb_strlen($body);
        if ($bl < self::BODY_MIN || $bl > self::BODY_MAX) $errors['body'] = sprintf('A description of %d to %d characters.', self::BODY_MIN, self::BODY_MAX);
        if ($url !== '') {
            $safe = self::safeUrl($url);
            if ($safe === null) $errors['url'] = 'Use a full https:// address.';
            elseif (self::pointsAtANominee($safe)) $errors['url'] = 'A highlight cannot link to a nominee or a ballot.';
            $url = (string) $safe;
        }
        if (mb_strlen($loc) > 60) $errors['location'] = 'Keep the place under 60 characters.';

        return ['row' => [
            'kind' => $kind, 'title' => $title, 'body' => $body,
            'url' => $url !== '' ? $url : null, 'location' => $loc !== '' ? $loc : null,
        ], 'errors' => $errors];
    }

    /** https://host/…, or a path on this site; nothing else. */
    public static function safeUrl(string $u): ?string
    {
        $u = trim($u);
        if (preg_match('{^/(?!/)[A-Za-z0-9/_.~%?=&+#-]*$}', $u) === 1) return substr($u, 0, 400);
        $p = parse_url($u);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') return null;
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) || isset($p['user']) || isset($p['pass'])) return null;
        return strlen($u) <= 400 ? $u : null;
    }

    /** A ballot, a nominee page or a claim, on this site or as a full address to it. */
    public static function pointsAtANominee(string $u): bool
    {
        $path = str_starts_with($u, '/') ? $u : (string) (parse_url($u, PHP_URL_PATH) ?? '');
        return preg_match('~^/(vote|claim|v)(/|$)~i', $path) === 1;
    }

    public static function openCount(int $userId): int
    {
        try {
            return (int) DB::table('gates_highlights')->where('user_id', $userId)
                ->where(static function ($q): void {
                    $q->where('status', 'pending')
                      ->orWhere(static fn ($w) => $w->where('status', 'live')->where('live_until', '>', date('Y-m-d H:i:s')));
                })->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** A reviewer's decision. Approving starts the fixed life; refusing needs a reason. */
    public static function decide(int $id, int $adminId, bool $approve, string $reason = ''): array
    {
        if (!self::ready()) return ['ok' => false, 'message' => 'Highlights are not available.'];
        $row = DB::table('gates_highlights')->where('id', $id)->first();
        if (!$row || $row->status !== 'pending') return ['ok' => false, 'message' => 'That highlight is not waiting for review.'];
        $reason = trim($reason);
        if (!$approve && $reason === '') return ['ok' => false, 'message' => 'Say why, so the member can fix it.'];
        $now = date('Y-m-d H:i:s');
        DB::table('gates_highlights')->where('id', $id)->where('status', 'pending')->update([
            'status' => $approve ? 'live' : 'rejected',
            'reject_reason' => $approve ? null : mb_substr($reason, 0, 300),
            'reviewed_by' => $adminId ?: null, 'reviewed_at' => $now,
            'live_until' => $approve ? date('Y-m-d H:i:s', time() + self::LIVE_DAYS * 86400) : null,
            'updated_at' => $now,
        ]);
        return ['ok' => true];
    }

    /** The member takes their own highlight down. */
    public static function withdraw(int $id, int $userId): bool
    {
        try {
            return DB::table('gates_highlights')->where('id', $id)->where('user_id', $userId)
                ->whereIn('status', ['pending', 'live'])
                ->update(['status' => 'withdrawn', 'updated_at' => date('Y-m-d H:i:s')]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * What the public sees: approved and still within its life, newest first. The link is
     * re-checked on the way out — a row can outlive the code that wrote it.
     *
     * @return list<array<string,mixed>>
     */
    public static function live(int $limit = 24, string $kind = ''): array
    {
        if (!self::ready()) return [];
        try {
            $q = DB::table('gates_highlights')->where('status', 'live')->where('live_until', '>', date('Y-m-d H:i:s'))
                ->orderByDesc('reviewed_at')->orderByDesc('id')->limit(max(1, min(100, $limit)));
            if (in_array($kind, self::KINDS, true)) $q->where('kind', $kind);
            return array_map(static function ($r): array {
                $url = $r->url ? self::safeUrl((string) $r->url) : null;
                if ($url !== null && self::pointsAtANominee($url)) $url = null;
                return [
                    'id' => (int) $r->id, 'kind' => (string) $r->kind, 'title' => (string) $r->title,
                    'body' => (string) $r->body, 'url' => $url, 'external' => $url !== null && !str_starts_with($url, '/'),
                    'image' => $r->image_path ? (string) $r->image_path : null, 'location' => $r->location ? (string) $r->location : null,
                ];
            }, $q->get()->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<object> a member's own highlights, every state, newest first */
    public static function mine(int $userId): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_highlights')->where('user_id', $userId)->orderByDesc('id')->limit(50)->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<object> waiting for a reviewer, oldest first */
    public static function queue(): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_highlights')->where('status', 'pending')->orderBy('id')->limit(200)->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
