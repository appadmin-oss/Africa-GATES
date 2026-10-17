<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The withdrawal fee: one schedule for every way a member takes money out.
 *
 * ── BANDED, THE WAY A NIGERIAN WALLET CHARGES ────────────────────────────────
 *
 * OPay, Kuda and every bank app charge a transfer by band, not by percentage: a flat sum
 * for small transfers, a little more past a line. People already read fees that way, and
 * it is how the money actually leaves — Paystack's own transfer fee is ₦10 up to ₦5,000,
 * ₦25 up to ₦50,000 and ₦50 above, which is the house default here. An operator can change
 * the bands from Settings; they are a global rule, because a withdrawal draws on a member's
 * whole wallet and its fee cannot depend on which pocket it came from.
 *
 * ── SHOWN BEFORE, STORED ON THE REQUEST ──────────────────────────────────────
 *
 * The fee is computed when the member asks, shown on the review step, and frozen on the
 * request row, so a later change to the bands never restates what somebody agreed to. It
 * never exceeds the amount: a withdrawal whose fee would take all of it is refused rather
 * than paid as nothing.
 */
final class WalletFee
{
    /** [from naira, fee naira]. Paystack's transfer tiers. */
    public const DEFAULT_BANDS = [[0, 10], [5001, 25], [50001, 50]];

    public const MAX_BANDS = 8;

    /**
     * The bands in force, sorted, each [from_kobo, fee_kobo].
     *
     * @param  array<string,mixed>|null $eff
     * @return list<array{0:int,1:int}>
     */
    public static function bands(?array $eff = null): array
    {
        $eff ??= (new RuleEngine())->effective();
        $raw = $eff['wallet_fee_bands'] ?? self::DEFAULT_BANDS;
        if (!is_array($raw)) $raw = self::DEFAULT_BANDS;

        $bands = [];
        foreach ($raw as $b) {
            if (!is_array($b) || count($b) < 2) continue;
            $bands[(int) $b[0]] = [max(0, (int) $b[0]) * 100, max(0, (int) $b[1]) * 100];
        }
        ksort($bands);
        // An empty schedule is "no fee", said explicitly rather than by accident.
        return $bands === [] ? [[0, 0]] : array_values($bands);
    }

    /** The fee for withdrawing this amount, in kobo. Never more than the amount. */
    public static function forKobo(int $amountKobo, ?array $eff = null): int
    {
        if ($amountKobo <= 0) return 0;
        $fee = 0;
        foreach (self::bands($eff) as [$from, $f]) {
            if ($amountKobo >= $from) $fee = $f;
        }
        return min($fee, $amountKobo);
    }

    /**
     * Read the Settings field: one band per line, "5001: 25" — from ₦5,001, a ₦25 fee.
     *
     * @return array{ok:bool, bands:list<array{0:int,1:int}>, message:string}
     */
    public static function parse(string $text): array
    {
        $bands = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^₦?\s*([\d,]+)\s*[:=]\s*₦?\s*([\d,]+)$/u', $line, $m)) {
                return ['ok' => false, 'bands' => [], 'message' => 'Fee line ' . ($n + 1) . ' should read like “5001: 25” (from ₦5,001, a ₦25 fee).'];
            }
            $from = (int) str_replace(',', '', $m[1]);
            if (isset($bands[$from])) return ['ok' => false, 'bands' => [], 'message' => 'Two fee bands start at ₦' . number_format($from) . '.'];
            $bands[$from] = [$from, (int) str_replace(',', '', $m[2])];
        }
        if (count($bands) > self::MAX_BANDS) return ['ok' => false, 'bands' => [], 'message' => 'Keep the fee schedule to ' . self::MAX_BANDS . ' bands or fewer.'];
        ksort($bands);
        return ['ok' => true, 'bands' => array_values($bands), 'message' => ''];
    }

    /** The schedule as the Settings field shows it back. */
    public static function format(?array $eff = null): string
    {
        $lines = [];
        foreach (self::bands($eff) as [$from, $fee]) $lines[] = intdiv($from, 100) . ': ' . intdiv($fee, 100);
        return implode("\n", $lines);
    }

    /**
     * The schedule in a sentence, for the review step and /integrity:
     * "₦10 up to ₦5,000 · ₦25 up to ₦50,000 · ₦50 above".
     */
    public static function describe(?array $eff = null): string
    {
        $bands = self::bands($eff);
        if (count($bands) === 1 && $bands[0][1] === 0) return 'No fee';
        $parts = [];
        foreach ($bands as $i => [$from, $fee]) {
            $next = $bands[$i + 1][0] ?? null;
            $f = $fee === 0 ? 'free' : '₦' . number_format(intdiv($fee, 100));
            $parts[] = $next === null
                ? ($i === 0 ? $f . ' on every withdrawal' : $f . ' above ₦' . number_format(intdiv($from, 100) - 1))
                : $f . ' up to ₦' . number_format(intdiv($next, 100) - 1);
        }
        return implode(' · ', $parts);
    }
}
