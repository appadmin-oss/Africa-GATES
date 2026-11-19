<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * One order's history: what happened to it, who did it, and the notes staff left on it.
 *
 * The audit log records "admin 7 did order.fulfil to order 412", and nothing on the order screen
 * ever read it back — so "who cancelled this, and did the money go back?" was a question for
 * whoever happened to remember. This is the order's own timeline, written at the moment each
 * thing happens (refund asked for, refund settled, cancelled, delivery moved, collected) and
 * read on the order's page in order.
 *
 * Writing never throws. A history row is a convenience beside a state change that has already
 * happened; a failed insert must not be able to undo it — and before the migration has run the
 * table is absent, and every caller carries on as it did before.
 */
final class ShopOrderLog
{
    /** The kinds a row can be. A VARCHAR column, so this list is the constraint. */
    public const KINDS = [
        'note'      => 'Note',
        'status'    => 'Delivery',
        'refund'    => 'Refund',
        'cancel'    => 'Cancelled',
        'pickup'    => 'Collection',
        'mail'      => 'Email',
        'payment'   => 'Payment',
        'pos'       => 'In-person sale',
    ];

    public const NOTE_MAX = 1000;

    public static function add(int $orderId, string $kind, string $body, ?int $adminId = null): void
    {
        if ($orderId < 1 || !isset(self::KINDS[$kind])) return;
        if (!SchemaHas::table('gates_shop_order_events')) return;
        try {
            DB::table('gates_shop_order_events')->insert([
                'order_id'   => $orderId,
                'kind'       => $kind,
                'body'       => mb_substr(trim($body), 0, self::NOTE_MAX),
                // 0 is "nobody signed in" (a webhook, the sweep). Stored as NULL rather than 0,
                // the sentinel CLAUDE.md warns about on the audit log's foreign key.
                'admin_id'   => $adminId !== null && $adminId > 0 ? $adminId : null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            error_log('[shop] order history row not written for #' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * The order's history, oldest first, with the staff member's name where there is one.
     *
     * @return list<array{kind:string,label:string,body:string,by:string,at:string}>
     */
    public static function for(int $orderId): array
    {
        if ($orderId < 1 || !SchemaHas::table('gates_shop_order_events')) return [];
        try {
            $rows = DB::table('gates_shop_order_events as e')
                ->leftJoin('gates_admins as a', 'a.id', '=', 'e.admin_id')
                ->where('e.order_id', $orderId)
                ->orderBy('e.id')
                ->get(['e.kind', 'e.body', 'e.created_at', 'a.name as admin_name']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind'  => (string) $r->kind,
                'label' => self::KINDS[(string) $r->kind] ?? (string) $r->kind,
                'body'  => (string) ($r->body ?? ''),
                'by'    => (string) ($r->admin_name ?? '') ?: 'The platform',
                'at'    => (string) $r->created_at,
            ];
        }
        return $out;
    }
}
