<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Who else is in the review queue right now (AdminConsole v2, README §10b "Presence").
 *
 * One row per admin, overwritten on every view of the queue and by a heartbeat while the
 * page stays open. It only ever informs — "2 others here", "Tunde A. is viewing" — and
 * nothing reads it to refuse a decision: a lock left behind by a closed laptop would stop
 * the queue for everybody, and two people deciding one nomination is already settled by
 * whichever decision lands first.
 */
final class Presence
{
    /** Seen within this long counts as here. Twice the heartbeat, so one lost beat is not a departure. */
    public const WINDOW = 90;

    public static function touch(int $adminId, string $area, ?int $targetId = null): void
    {
        if ($adminId <= 0 || !SchemaHas::table('gates_admin_presence')) return;
        try {
            DB::table('gates_admin_presence')->updateOrInsert(
                ['admin_id' => $adminId],
                ['area' => mb_substr($area, 0, 40), 'target_id' => $targetId ?: null, 'seen_at' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {}
    }

    /**
     * The OTHER admins in an area, most recent first.
     *
     * @return list<array{id:int, name:string, short:string, initials:string, target_id:?int}>
     */
    public static function others(int $adminId, string $area): array
    {
        if (!SchemaHas::table('gates_admin_presence')) return [];
        try {
            $rows = DB::table('gates_admin_presence as p')->join('gates_admins as a', 'a.id', '=', 'p.admin_id')
                ->where('p.area', $area)->where('p.admin_id', '!=', $adminId)
                ->where('p.seen_at', '>=', date('Y-m-d H:i:s', time() - self::WINDOW))
                ->orderByDesc('p.seen_at')->limit(8)->get(['p.admin_id', 'p.target_id', 'a.name']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $parts = preg_split('/\s+/u', trim((string) $r->name)) ?: [''];
            $first = (string) ($parts[0] ?? '');
            $last = count($parts) > 1 ? (string) end($parts) : '';
            $out[] = [
                'id'        => (int) $r->admin_id,
                'name'      => (string) $r->name,
                'short'     => trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : '')),
                'initials'  => mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)) ?: '?',
                'target_id' => $r->target_id !== null ? (int) $r->target_id : null,
            ];
        }
        return $out;
    }
}
