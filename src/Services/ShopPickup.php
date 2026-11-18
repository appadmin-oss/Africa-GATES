<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Collection points: an order collected in person instead of delivered.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS ITS OWN FLOW AND NOT A ₦0 DELIVERY ZONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A parcel goes to an address and its proof is the courier's. A collection is handed across a
 * table to whoever turns up, and the only proof that person is the buyer is something only the
 * buyer has. So a collection order gets a short code when it is paid (in the receipt and the
 * "ready" email, never on the packing slip — the slip is in the parcel the stranger is asking
 * for), and "Collected" needs that code typed back. A person who cannot produce it can still be
 * served — a buyer with a dead phone is real — but only with a reason, written on the order
 * with the staff member's name.
 *
 * States: paid → `ready` (Ready to collect, emails the buyer with where, when and the code) →
 * `collected`. The code is six digits from random_int(), stored as text so a leading zero
 * survives (MySQL would drop it from an INT, and 012345 would never match).
 */
final class ShopPickup
{
    public const CODE_LEN = 6;
    /** A reason for collecting without the code must say at least this much. */
    public const OVERRIDE_MIN = 8;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_shop_pickup_points') && SchemaHas::column('gates_orders', 'pickup_code');
    }

    /** @return list<array<string,mixed>> collection points, active first */
    public static function points(bool $activeOnly = false): array
    {
        if (!SchemaHas::table('gates_shop_pickup_points')) return [];
        $q = DB::table('gates_shop_pickup_points');
        if ($activeOnly) $q->where('is_active', 1);
        return $q->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name')->get()
            ->map(static fn ($r): array => (array) $r)->all();
    }

    public static function point(int $id): ?object
    {
        if ($id < 1 || !SchemaHas::table('gates_shop_pickup_points')) return null;
        return DB::table('gates_shop_pickup_points')->where('id', $id)->first();
    }

    /** An ACTIVE point, or null — what checkout may accept. */
    public static function usable(int $id): ?object
    {
        $p = self::point($id);
        return $p && (int) $p->is_active === 1 ? $p : null;
    }

    /**
     * Give a paid collection order its code. Idempotent: an order that already has one keeps
     * it (a receipt and a "ready" email must quote the same number).
     */
    public static function issueCode(int $orderId): ?string
    {
        if (!self::ready()) return null;
        $o = DB::table('gates_orders')->where('id', $orderId)->first(['pickup_point_id', 'pickup_code']);
        if (!$o || (int) ($o->pickup_point_id ?? 0) < 1) return null;
        if (trim((string) ($o->pickup_code ?? '')) !== '') return (string) $o->pickup_code;

        $code = str_pad((string) random_int(0, 10 ** self::CODE_LEN - 1), self::CODE_LEN, '0', STR_PAD_LEFT);
        DB::table('gates_orders')->where('id', $orderId)->whereNull('pickup_code')->update(['pickup_code' => $code]);
        return (string) DB::table('gates_orders')->where('id', $orderId)->value('pickup_code');
    }

    /** Is this a collection order? */
    public static function isCollection(object $order): bool
    {
        return (int) ($order->pickup_point_id ?? 0) > 0;
    }

    /**
     * Ready to collect: the state moves and the buyer is told where, when, and their code.
     *
     * @return array{ok:bool,message:string}
     */
    public static function markReady(int $orderId, int $adminId, ?OtpService $mailer): array
    {
        $o = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$o) return ['ok' => false, 'message' => 'That order could not be found.'];
        if (!self::isCollection($o)) return ['ok' => false, 'message' => (string) $o->reference . ' is a delivery, not a collection.'];
        if ((string) $o->status !== 'paid') return ['ok' => false, 'message' => (string) $o->reference . ' is not paid.'];
        if (in_array((string) ($o->fulfilment ?? ''), ['collected', 'cancelled'], true)) {
            return ['ok' => false, 'message' => (string) $o->reference . ' is already ' . $o->fulfilment . '.'];
        }
        $code = self::issueCode($orderId);
        $n = DB::table('gates_orders')->where('id', $orderId)->where(static function ($w): void {
            $w->whereNull('fulfilment')->orWhere('fulfilment', '!=', 'ready');
        })->update(['fulfilment' => 'ready']);
        if ($n === 0) return ['ok' => true, 'message' => (string) $o->reference . ' was already ready to collect.'];

        $p = self::point((int) $o->pickup_point_id);
        ShopOrderLog::add($orderId, 'pickup', 'Ready to collect' . ($p ? ' at ' . $p->name : '') . '.', $adminId);
        ShopMail::send($mailer, $orderId, 'ready', [
            'point' => $p ? (string) $p->name : '', 'address' => $p ? (string) ($p->address ?? '') : '',
            'hours' => $p ? (string) ($p->hours ?? '') : '', 'code' => (string) $code,
        ]);
        return ['ok' => true, 'message' => (string) $o->reference . ' is ready to collect; the buyer has been emailed their code.'];
    }

    /**
     * Collected: the code typed back, or — without it — a reason, recorded with the name.
     *
     * The comparison is hash_equals on the digits only (spaces and dashes typed off a phone are
     * not a wrong code). Wrong codes are refused; there is no lock-out because the person typing
     * is staff at a table, not an anonymous form, and the override is the escape hatch.
     *
     * @return array{ok:bool,message:string}
     */
    public static function collect(int $orderId, string $code, string $override, int $adminId, ?OtpService $mailer = null): array
    {
        $o = DB::table('gates_orders')->where('id', $orderId)->first();
        if (!$o) return ['ok' => false, 'message' => 'That order could not be found.'];
        if (!self::isCollection($o)) return ['ok' => false, 'message' => (string) $o->reference . ' is a delivery, not a collection.'];
        if ((string) $o->status !== 'paid') return ['ok' => false, 'message' => (string) $o->reference . ' is not paid — do not hand it over.'];
        if ((string) ($o->fulfilment ?? '') === 'collected') return ['ok' => false, 'message' => (string) $o->reference . ' was already collected on ' . (string) ($o->collected_at ?? '') . '.'];
        if ((string) ($o->fulfilment ?? '') === 'cancelled') return ['ok' => false, 'message' => (string) $o->reference . ' was cancelled — do not hand it over.'];

        $typed    = (string) preg_replace('/\D+/', '', $code);
        $expected = (string) ($o->pickup_code ?? '');
        $override = trim($override);
        $byCode   = $typed !== '' && $expected !== '' && hash_equals($expected, $typed);

        if (!$byCode) {
            if ($typed !== '') return ['ok' => false, 'message' => 'That code does not match this order. Check it with the buyer, or hand it over with a reason instead.'];
            if (mb_strlen($override) < self::OVERRIDE_MIN) {
                return ['ok' => false, 'message' => 'Type the buyer\'s collection code, or give a reason for handing it over without one (what ID you saw, who vouched).'];
            }
        }

        $now = Carbon::now()->toDateTimeString();
        $n = DB::table('gates_orders')->where('id', $orderId)->where('status', 'paid')
            ->where(static function ($w): void { $w->whereNull('fulfilment')->orWhere('fulfilment', '!=', 'collected'); })
            ->update(\AfricaGates\Support\OptionalColumn::filter('gates_orders', [
                'fulfilment' => 'collected', 'collected_at' => $now, 'fulfilled_at' => $now, 'stock_short' => 0,
                'collected_by' => $adminId > 0 ? $adminId : null,
                'collect_override' => $byCode ? null : mb_substr($override, 0, 300),
            ], ['collected_at', 'fulfilled_at', 'stock_short', 'collected_by', 'collect_override']));
        if ($n === 0) return ['ok' => false, 'message' => 'That order changed a moment ago. Reload and look again.'];

        ShopOrderLog::add($orderId, 'pickup', $byCode ? 'Collected — code checked.' : 'Collected WITHOUT the code — ' . $override, $adminId);
        ShopMail::send($mailer, $orderId, 'collected');
        return ['ok' => true, 'message' => (string) $o->reference . ' handed over' . ($byCode ? '.' : ' without the code; your reason is on the order.')];
    }
}
