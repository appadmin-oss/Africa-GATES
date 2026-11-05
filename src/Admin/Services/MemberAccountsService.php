<?php
declare(strict_types=1);
namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\Like;
use AfricaGates\Support\Paginator;
use AfricaGates\Admin\Support\DataRegistry;

/** Member read models. Permissions and HTTP responses remain the controller's responsibility. */
final class MemberAccountsService
{
    /** Search terms are values; SQL identifiers are fixed by this module. */
    public function directory(array $query): array
    {
        $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 160);
        $verification = (string) ($query['verification'] ?? '');
        if (!in_array($verification, ['verified', 'unverified'], true)) $verification = '';
        $base = DB::table('gates_users')->select('id', 'name', 'email', 'email_verified', 'points', 'created_at');
        if ($q !== '') $base->where(function ($w) use ($q) {
            $like = Like::contains($q);
            $w->whereRaw(Like::clause('name'), [$like])
              ->orWhereRaw(Like::clause('email'), [$like]);
        });
        if ($verification !== '') $base->where('email_verified', $verification === 'verified' ? 1 : 0);
        $pg = Paginator::paginate($base->orderByDesc('id'), max(1, (int) ($query['page'] ?? 1)), 30);
        return array_merge($pg, ['q' => $q, 'verification' => $verification,
            'query' => http_build_query(['q' => $q, 'verification' => $verification])]);
    }
    public function account(int $id, string $role): ?array
    {
        $member = DB::table('gates_users')->select('id', 'name', 'email', 'phone', 'email_verified', 'points', 'status', 'created_at', 'last_login_at')->where('id', $id)->first();
        if (!$member) return null;
        $ledger = DataRegistry::canRole('points', $role) && DB::schema()->hasTable('gates_points_ledger')
            ? DB::table('gates_points_ledger')->where('user_id', $id)->orderByDesc('id')->limit(20)->get() : [];
        return ['member' => $member, 'id' => $id, 'ledger' => $ledger];
    }
}
