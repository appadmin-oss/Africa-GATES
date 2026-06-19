<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

class AuditService
{
    public function record(?int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, array $meta = []): void
    {
        try {
            DB::table('gates_audit_log')->insert([
                'admin_id'    => $adminId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'meta'        => $meta ? json_encode($meta) : null,
                'ip_hash'     => isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', (string)$_SERVER['REMOTE_ADDR']) : null,
                'ua'          => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
                'created_at'  => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // Audit failures must never break the app.
        }
    }

    public function recent(int $limit = 50): array
    {
        return DB::table('gates_audit_log as a')
            ->leftJoin('gates_admins as ad', 'ad.id', '=', 'a.admin_id')
            ->select(['a.id','a.action','a.target_type','a.target_id','a.meta','a.created_at','ad.name as admin_name','ad.email as admin_email'])
            ->orderByDesc('a.id')->limit($limit)->get()->map(function ($r) {
                $meta = $r->meta ? json_decode((string)$r->meta, true) : null;
                return [
                    'id' => (int)$r->id,
                    'action' => $r->action,
                    'target_type' => $r->target_type,
                    'target_id' => $r->target_id,
                    'meta' => $meta,
                    'admin_name' => $r->admin_name,
                    'admin_email' => $r->admin_email,
                    'created_at' => $r->created_at,
                ];
            })->values()->all();
    }
}
