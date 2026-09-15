<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\PromoCode;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Discount codes for the shop.
 *
 * ── WHAT IS SHARED WITH EVENT CODES, AND WHAT IS NOT ─────────────────────────
 *
 * The rules about a code — switched on, inside its window, not exhausted, what it takes off a
 * total — are identical to an event's, and they live in {@see \AfricaGates\Support\PromoCode}
 * so there is one copy. Two copies would not stay identical: one would gain a clamp the other
 * did not, and the failure would be invisible until a code went negative in public.
 *
 * What differs is the TARGET. An event code names ticket tiers. A shop code names products and
 * categories — and two things an event has no use for:
 *
 *   • `min_spend_naira`. "Spend ₦20,000 and get 10% off" is the most common promotion there
 *     is, and without it the shop cannot express any promotion that asks for anything back.
 *   • `free_shipping`. Buyers understand free delivery better than a percentage, and it costs
 *     the seller a known amount rather than a share of every order.
 *
 * ── THE DISCOUNT APPLIES TO THE ELIGIBLE PART, NOT THE BASKET ────────────────
 *
 * A code for Apparel in a basket of a shirt and a mug takes its percentage off the shirt. The
 * obvious implementation — percentage of the whole basket — gives away money on items the
 * promotion never mentioned, and it is the mistake that only shows up in a month's revenue.
 *
 * ── AND IT IS PRICED SERVER-SIDE, ALWAYS ─────────────────────────────────────
 *
 * The browser gets a preview so somebody can see the number before they commit, but
 * {@see ShopCheckoutController::priceCart()} runs this again from the product rows. A total
 * decided in the browser is a total anybody can edit — and confirmation refuses a payment that
 * does not equal the order, so a forged discount would not undercharge, it would make the
 * order unconfirmable and the money unmatched.
 */
final class ShopDiscount
{
    /**
     * Price a code against a basket.
     *
     * @param list<array<string,mixed>> $lines priced cart lines: product_id, category, line_total
     * @return array{ok:bool, message:string, id?:int, code?:string, off?:int,
     *                free_shipping?:bool, eligible?:int}
     */
    public static function apply(string $raw, array $lines, string $email): array
    {
        $code = PromoCode::normalise($raw);
        if ($code === '') return ['ok' => false, 'message' => ''];

        $row = self::find($code);
        if (!$row) return ['ok' => false, 'message' => 'That code is not recognised.'];

        $no = PromoCode::refusal($row);
        if ($no !== '') return ['ok' => false, 'message' => $no];

        $perEmail = max(1, (int) ($row->max_per_email ?? 1));
        if (self::timesUsedBy($code, $email) >= $perEmail) {
            return ['ok' => false, 'message' => PromoCode::perPersonRefusal($perEmail)];
        }

        // Which lines this code is about. See the docblock: the percentage comes off the
        // ELIGIBLE part, or a code for Apparel would discount the mugs too.
        $eligible = 0;
        $basket   = 0;
        foreach ($lines as $l) {
            $total   = (int) ($l['line_total'] ?? 0);
            $basket += $total;
            if (self::covers($row, $l)) $eligible += $total;
        }

        $min = ($row->min_spend_naira ?? null) !== null ? (int) $row->min_spend_naira : 0;
        if ($min > 0 && $basket < $min) {
            return ['ok' => false, 'message' => 'That code needs an order of at least ₦'
                . number_format($min) . '. You are ₦' . number_format($min - $basket) . ' short.'];
        }

        $freeShip = (int) ($row->free_shipping ?? 0) === 1;

        if ($eligible <= 0) {
            // A free-shipping code with nothing else to give still applies: the basket
            // qualified, the code just does not name any of the goods.
            if ($freeShip) {
                return ['ok' => true, 'id' => (int) $row->id, 'code' => $code, 'off' => 0,
                        'free_shipping' => true, 'eligible' => 0,
                        'message' => self::says($row, 0, true)];
            }
            return ['ok' => false, 'message' => 'That code does not apply to anything in your basket.'];
        }

        $off = PromoCode::amountOff($row, $eligible);
        if ($off <= 0 && !$freeShip) {
            return ['ok' => false, 'message' => 'That code takes nothing off this order.'];
        }

        return ['ok' => true, 'id' => (int) $row->id, 'code' => $code, 'off' => $off,
                'free_shipping' => $freeShip, 'eligible' => $eligible,
                'message' => self::says($row, $off, $freeShip)];
    }

    /**
     * Does this code name this line?
     *
     * Product ids and categories are OR-ed, because an organiser who lists both means "the
     * tote, and anything in Apparel" — reading it as an intersection would produce a code that
     * matches nothing and no message explaining why.
     *
     * @param array<string,mixed> $line
     */
    private static function covers(object $row, array $line): bool
    {
        $ids  = json_decode((string) ($row->product_ids ?? ''), true);
        $cats = json_decode((string) ($row->categories ?? ''), true);

        $hasIds  = is_array($ids)  && $ids  !== [];
        $hasCats = is_array($cats) && $cats !== [];

        // Neither named: everything.
        if (!$hasIds && !$hasCats) return true;

        if ($hasIds && in_array((int) ($line['product_id'] ?? 0), array_map('intval', $ids), true)) {
            return true;
        }
        if ($hasCats) {
            $cat = (string) ($line['category'] ?? '');
            foreach ($cats as $c) {
                if (strcasecmp((string) $c, $cat) === 0) return true;
            }
        }
        return false;
    }

    private static function says(object $row, int $off, bool $freeShip): string
    {
        $label = trim((string) ($row->label ?? ''));
        $bits  = [];
        if ($off > 0)   $bits[] = '₦' . number_format($off) . ' off';
        if ($freeShip)  $bits[] = 'free delivery';
        return ($label !== '' ? $label . ': ' : '') . ucfirst(implode(' and ', $bits)) . '.';
    }

    public static function find(string $code): ?object
    {
        $code = PromoCode::normalise($code);
        if ($code === '') return null;
        try {
            return DB::table('gates_shop_codes')->whereRaw('UPPER(code) = ?', [$code])->first() ?: null;
        } catch (\Throwable) { return null; }
    }

    /**
     * How many times this email has already used the code.
     *
     * Counted from the ORDERS, not from a per-person counter, because the orders are the
     * record — and a failed payment does not count, or somebody whose card was declined would
     * be locked out of their own discount.
     */
    public static function timesUsedBy(string $code, string $email): int
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return 0;
        try {
            return (int) DB::table('gates_orders')
                ->whereRaw('UPPER(discount_code) = ?', [PromoCode::normalise($code)])
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereIn('status', ['paid', 'pending'])
                ->count();
        } catch (\Throwable) { return 0; }
    }

    /**
     * Count a use, once an order actually exists against it.
     *
     * Called when the pending order is written rather than when somebody types the code into
     * the preview box: a code counted on a look would exhaust itself on window shoppers, and
     * `max_uses` is what that number is checked against.
     */
    public static function countUse(int $codeId): void
    {
        try {
            DB::table('gates_shop_codes')->where('id', $codeId)
                ->update(['used_count' => DB::raw('COALESCE(used_count, 0) + 1')]);
        } catch (\Throwable) {}
    }

    /** Give a use back when an order never got paid. */
    public static function releaseUse(?string $code): void
    {
        $code = PromoCode::normalise((string) $code);
        if ($code === '') return;
        try {
            $row = self::find($code);
            if (!$row) return;
            DB::table('gates_shop_codes')->where('id', (int) $row->id)
                ->where('used_count', '>', 0)
                ->update(['used_count' => DB::raw('used_count - 1')]);
        } catch (\Throwable) {}
    }

    /** @return list<array<string,mixed>> every code, newest first, with its lists decoded. */
    public static function all(): array
    {
        try {
            return DB::table('gates_shop_codes')->orderByDesc('id')->get()
                ->map(static function ($r): array {
                    $a = (array) $r;
                    // Decoded here, not in Twig, which has no json_decode — and the editor
                    // needs each list twice (to tick the boxes and to name them in the table),
                    // so decoding once is the only way the two cannot disagree.
                    $ids  = json_decode((string) ($r->product_ids ?? ''), true);
                    $cats = json_decode((string) ($r->categories ?? ''), true);
                    $a['product_list']  = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
                    $a['category_list'] = is_array($cats) ? array_values(array_map('strval', $cats)) : [];
                    $a['left'] = $r->max_uses !== null
                        ? max(0, (int) $r->max_uses - (int) $r->used_count) : null;
                    return $a;
                })->all();
        } catch (\Throwable) { return []; }
    }
}
