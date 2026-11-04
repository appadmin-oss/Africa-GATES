<?php
declare(strict_types=1);
namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\Like;
use AfricaGates\Support\Paginator;

/** A bounded, searchable staff directory with an explicit safe field list. */
final class StaffDirectoryService
{
    /** Search terms are values; SQL identifiers are fixed by this module. */
    public function directory(array $query): array
    {
        $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 160);
        $base = DB::table('gates_admins')->select('id', 'name', 'email', 'role', 'last_login_at', 'is_active');
        if ($q !== '') $base->where(function ($w) use ($q) {
            $like = Like::contains($q);
            $w->whereRaw(Like::clause('name'), [$like])->orWhereRaw(Like::clause('email'), [$like]);
        });
        $pg = Paginator::paginate($base->orderByDesc('id'), max(1, (int) ($query['page'] ?? 1)), 30);
        $rows = $pg['rows']->map(fn($r) => (array) $r)->all();
        return ['rows' => $rows, 'q' => $q, 'page' => $pg['page'], 'pages' => $pg['pages'],
            'total' => $pg['total'], 'query' => http_build_query(['q' => $q])];
    }
}
