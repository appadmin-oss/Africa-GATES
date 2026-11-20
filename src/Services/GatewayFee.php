<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the payment gateway takes from a charge, and what a donor must add to cover it.
 *
 * Owner, 3 Nov 2026 ("do the modern standards"), on who pays the processing fee on a gift:
 * the way GoFundMe, JustGiving and Givebutter do it. The fee comes out of the gift, the
 * recipient bears it, and the donor is OFFERED — never defaulted into — the exact amount
 * that makes the recipient whole.
 *
 * ── THE COVER WAS A PERCENTAGE OF THE WRONG NUMBER ──────────────────────────
 *
 * It was `gift × 1.5%`. Paystack's local schedule is 1.5% PLUS ₦100 (the ₦100 waived under
 * ₦2,500), capped at ₦2,000, and it is charged on the WHOLE transaction — the cover
 * included. So "100% of my donation reaches them" was false on every gift from ₦2,500 up
 * (short by the ₦100 and the fee on the cover), and on a large gift the donor paid a
 * percentage of a fee that the cap had already stopped. The cover is now the fixed point
 * `c = fee(gift + tip + c)`, so the recipient receives the gift to the naira.
 *
 * ── ONE SCHEDULE, SETTABLE ──────────────────────────────────────────────────
 *
 * Gateways change their prices, and there is no shell on production: the four numbers are
 * settings (`processing_fee_pct`, `_flat`, `_flat_from`, `_cap`), Paystack's published
 * Nigerian rates by default. The page's live total (giving.js) is handed the same four, so
 * the figure a donor reads and the one they are charged cannot disagree.
 */
final class GatewayFee
{
    public const DEFAULTS = ['pct' => 1.5, 'flat' => 100, 'flat_from' => 2500, 'cap' => 2000];

    /** Who bears the fee on a gift to an organisation: `organisation` (the standard) or `platform`. */
    public const BEARER_KEY = 'partner_fee_bearer';

    /** @var array{pct:float,flat:int,flat_from:int,cap:int}|null */
    private static ?array $memo = null;

    public static function forget(): void
    {
        self::$memo = null;
    }

    /** @return array{pct:float,flat:int,flat_from:int,cap:int} */
    public static function schedule(): array
    {
        if (self::$memo !== null) return self::$memo;
        $v = [];
        try {
            foreach (DB::table('gates_settings')->whereIn('key_name', ['processing_fee_pct', 'processing_fee_flat',
                         'processing_fee_flat_from', 'processing_fee_cap'])->get(['key_name', 'value']) as $r) {
                $v[(string) $r->key_name] = trim((string) $r->value);
            }
        } catch (\Throwable) {
        }
        $num = static fn (string $k, float|int $d) => isset($v[$k]) && is_numeric($v[$k]) ? $v[$k] + 0 : $d;
        // Clamped: a mistyped 150 must not multiply somebody's gift.
        return self::$memo = [
            'pct'       => max(0.0, min(10.0, (float) $num('processing_fee_pct', self::DEFAULTS['pct']))),
            'flat'      => max(0, min(1000, (int) $num('processing_fee_flat', self::DEFAULTS['flat']))),
            'flat_from' => max(0, (int) $num('processing_fee_flat_from', self::DEFAULTS['flat_from'])),
            'cap'       => max(0, (int) $num('processing_fee_cap', self::DEFAULTS['cap'])),
        ];
    }

    /** The gateway's fee on a charge, in whole naira, rounded up (it never undercharges). */
    public static function fee(int $chargeNaira): int
    {
        if ($chargeNaira <= 0) return 0;
        $s = self::schedule();
        $f = (int) ceil($chargeNaira * $s['pct'] / 100 + ($chargeNaira >= $s['flat_from'] ? $s['flat'] : 0));
        return $s['cap'] > 0 ? min($s['cap'], $f) : $f;
    }

    /**
     * What a donor adds so the recipient receives `$giftNaira` exactly, when the fee is
     * charged on the whole transaction (`$alsoNaira` is anything else in it, a tip).
     * The smallest `c` with `c >= fee(gift + also + c)`: iterated, because the ₦100 and the
     * cap both make the fee a step function and no closed form is right at the steps.
     */
    public static function cover(int $giftNaira, int $alsoNaira = 0): int
    {
        if ($giftNaira <= 0) return 0;
        $c = 0;
        for ($i = 0; $i < 12; $i++) {
            $need = self::fee($giftNaira + $alsoNaira + $c);
            if ($need <= $c) return $c;
            $c = $need;
        }
        return $c;
    }

    /** Does the ORGANISATION bear the fee on gifts to it? The standard, and the default. */
    public static function organisationBears(): bool
    {
        try {
            return trim((string) (DB::table('gates_settings')->where('key_name', self::BEARER_KEY)->value('value') ?? '')) !== 'platform';
        } catch (\Throwable) {
            return true;
        }
    }
}
