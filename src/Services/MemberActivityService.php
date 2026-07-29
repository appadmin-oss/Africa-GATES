<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Read-only activity aggregation for the member dashboard: votes cast,
 * nominations made, share links minted, community participation, profile
 * completeness and the onboarding checklist.
 *
 * Identity convention: votes and community content store sha256 of the
 * lowercased trimmed email (never the raw address), so a member's history is
 * resolved by hashing THEIR OWN email from their session. Everything here is
 * SELECT-only — the audited vote path is not touched.
 */
class MemberActivityService
{
    private static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Votes this member has cast (standard votes only — bonus/redeemed votes
     * use synthetic hashes and show in the points ledger instead).
     *
     * @return list<array{nominee:string,category:string,voted_at:string,nominee_id:int}>
     */
    public static function votesFor(string $email, int $limit = 20): array
    {
        try {
            $rows = DB::table('gates_votes as v')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'v.nominee_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'v.category_id')
                ->where('v.voter_email_hash', self::emailHash($email))
                ->where('v.vote_type', 'standard')
                ->orderByDesc('v.voted_at')
                ->limit(max(1, $limit))
                ->get(['v.nominee_id', 'v.voted_at', 'n.name as nominee', 'c.title as category']);
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn($r) => [
            'nominee_id' => (int) $r->nominee_id,
            'nominee'    => (string) ($r->nominee ?? 'Nominee #' . $r->nominee_id),
            'category'   => (string) ($r->category ?? ''),
            'voted_at'   => (string) $r->voted_at,
        ], $rows->all());
    }

    /**
     * Nominations this member has submitted, newest first.
     *
     * @return list<array{nominee:string,status:string,reference:string,created_at:string}>
     */
    public static function nominationsFor(string $email, int $limit = 20): array
    {
        try {
            $rows = DB::table('gates_nominations')
                ->whereRaw('LOWER(nominator_email) = ?', [strtolower(trim($email))])
                ->orderByDesc('created_at')
                ->limit(max(1, $limit))
                ->get(['id', 'nominee_name', 'status', 'reference', 'created_at']);
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn($r) => [
            'nominee'    => (string) $r->nominee_name,
            'status'     => (string) $r->status,
            'reference'  => (string) ($r->reference ?? ('NOM-' . $r->id)),
            'created_at' => (string) $r->created_at,
        ], $rows->all());
    }

    /**
     * Share links this member created (live ones first), with open counts.
     *
     * @return list<array{nominee:string,url:string,hits:int,expires_at:string,expired:bool}>
     */
    public static function shareLinksFor(int $userId, int $limit = 10): array
    {
        if ($userId < 1) return [];
        try {
            $rows = DB::table('gates_nomination_links')
                ->where('created_by', $userId)
                ->orderByDesc('created_at')
                ->limit(max(1, $limit))
                ->get(['token', 'payload', 'hits', 'expires_at']);
        } catch (\Throwable) {
            return [];
        }
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        return array_map(static function ($r) use ($base) {
            $p = json_decode((string) $r->payload, true);
            return [
                'nominee'    => (string) (is_array($p) ? ($p['nominee_name'] ?? '') : ''),
                'url'        => $base . '/nominate?share=' . $r->token,
                'hits'       => (int) $r->hits,
                'expires_at' => (string) ($r->expires_at ?? ''),
                'expired'    => $r->expires_at !== null && strtotime((string) $r->expires_at) < time(),
            ];
        }, $rows->all());
    }

    /** @return array{threads:int,comments:int} */
    public static function communityCountsFor(string $email): array
    {
        $h = self::emailHash($email);
        try {
            return [
                'threads'  => (int) DB::table('gates_threads')->where('author_email_hash', $h)->whereIn('status', ['approved', 'quarantined', 'locked'])->count(),
                'comments' => (int) DB::table('gates_comments')->where('author_email_hash', $h)->whereIn('status', ['approved', 'quarantined'])->count(),
            ];
        } catch (\Throwable) {
            return ['threads' => 0, 'comments' => 0];
        }
    }

    /**
     * Profile completeness: verified email 40%, phone 30%, password 30%.
     *
     * @return array{pct:int,missing:list<string>}
     */
    public static function completeness(object|array $user): array
    {
        $u = (object) $user;
        $pct = 0;
        $missing = [];
        if ((int) ($u->email_verified ?? 0) === 1) $pct += 40; else $missing[] = 'verify';
        if (trim((string) ($u->phone ?? '')) !== '') $pct += 30; else $missing[] = 'phone';
        if (trim((string) ($u->password_hash ?? '')) !== '') $pct += 30; else $missing[] = 'password';
        return ['pct' => $pct, 'missing' => $missing];
    }

    /**
     * Onboarding checklist for the dashboard — each step links somewhere
     * actionable so a fresh account always has something to DO next.
     *
     * @return list<array{key:string,label:string,done:bool,href:string}>
     */
    public static function checklist(object|array $user, array $votes, array $nominations, array $communityCounts): array
    {
        $u = (object) $user;
        $comp = self::completeness($u);
        return [
            ['key' => 'verify',   'label' => 'Verify your email',                 'done' => (int) ($u->email_verified ?? 0) === 1, 'href' => '/account/verify'],
            ['key' => 'profile',  'label' => 'Complete your profile',             'done' => $comp['pct'] === 100,                   'href' => '/account#profile'],
            ['key' => 'vote',     'label' => 'Cast your first verified vote',     'done' => $votes !== [],                          'href' => '/vote'],
            ['key' => 'nominate', 'label' => 'Nominate someone extraordinary',    'done' => $nominations !== [],                    'href' => '/nominate'],
            ['key' => 'community','label' => 'Join a community conversation',     'done' => ($communityCounts['threads'] ?? 0) + ($communityCounts['comments'] ?? 0) > 0, 'href' => '/community'],
        ];
    }
}
