<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The ladder models of the community return: flat, tiered and custom.
 *
 * ── ONE ENGINE, THREE MODELS ─────────────────────────────────────────────────
 *
 * A ladder is a list of steps — "from ₦X raised, the share is Y%" — read against how much
 * has been raised BEFORE a contribution. Each of the three models is a ladder:
 *
 *   flat     one step from ₦0: the same share on every eligible contribution
 *   tiered   the house ladder (or the edition's own), rising with what the NOMINEE has
 *            raised, so a growing community earns a growing share
 *   custom   any ladder the operator writes, measured on the nominee's own total or on the
 *            whole EDITION's, and optionally held LOCKED until the edition's milestone is
 *            confirmed (the two-stage model's release rule, on a ladder of the operator's
 *            choosing)
 *
 * The threshold model (nothing until qualifying support, then one rate) and the two-stage
 * milestone model keep their own code in CommunityReturnService: they carry rules a ladder
 * does not express — a per-supporter cap, and a rate that switches at a confirmation.
 *
 * ── PRICED AT THE CONTRIBUTION'S OWN MOMENT ──────────────────────────────────
 *
 * "Raised before" is counted strictly before the contribution (by time, then by id), from
 * eligible money only: confirmed, unrefunded, not refund-requested, not fraud-flagged. So a
 * re-run accrual prices a contribution exactly as the first run did, and a later refund
 * never re-prices anything already on the ledger — the same prospective rule the threshold
 * model has always held.
 */
final class CommunityReturnLadder
{
    public const MODEL_FLAT   = 'flat';
    public const MODEL_TIERED = 'tiered';
    public const MODEL_CUSTOM = 'custom';
    public const MODELS = [self::MODEL_FLAT, self::MODEL_TIERED, self::MODEL_CUSTOM];

    public const BASIS_NOMINEE = 'nominee';
    public const BASIS_EDITION = 'edition';

    /** The house tiered ladder: [from naira, basis points]. */
    public const DEFAULT_TIERED = [[0, 1000], [50000, 3000], [200000, 5000]];

    /** A ladder longer than this is a pricing table nobody can publish legibly. */
    public const MAX_STEPS = 10;

    public static function isLadder(string $model): bool
    {
        return in_array($model, self::MODELS, true);
    }

    /**
     * The steps in force, sorted, each [from_kobo, bps].
     *
     * A ladder that does not start at ₦0 earns nothing below its first step, which is a
     * legitimate rule ("nothing until ₦20,000, then 40%") and the custom model's way of
     * expressing a threshold in money.
     *
     * @param  array<string,mixed> $rules
     * @return list<array{0:int,1:int}>
     */
    public static function steps(array $rules, string $model): array
    {
        if ($model === self::MODEL_FLAT) {
            return [[0, max(0, min(10000, (int) ($rules['community_return_bps'] ?? 0)))]];
        }
        $raw = $rules['community_return_ladder'] ?? [];
        if (!is_array($raw) || $raw === []) {
            $raw = $model === self::MODEL_TIERED ? self::DEFAULT_TIERED : [];
        }
        $steps = [];
        foreach ($raw as $s) {
            if (!is_array($s) || count($s) < 2) continue;
            $steps[(int) $s[0]] = [max(0, (int) $s[0]) * 100, max(0, min(10000, (int) $s[1]))];
        }
        ksort($steps);
        return array_values($steps);
    }

    /** What the ladder is measured against. Flat and tiered are always the nominee's own. */
    public static function basis(array $rules, string $model): string
    {
        return $model === self::MODEL_CUSTOM
            && (string) ($rules['community_return_ladder_basis'] ?? '') === self::BASIS_EDITION
            ? self::BASIS_EDITION : self::BASIS_NOMINEE;
    }

    /** Does this ladder hold its credits locked until the edition's milestone is confirmed? */
    public static function locked(array $rules, string $model): bool
    {
        return $model === self::MODEL_CUSTOM && !empty($rules['community_return_ladder_locked']);
    }

    /** The share for a given amount already raised. */
    public static function rateFor(array $steps, int $raisedKobo): int
    {
        $bps = 0;
        foreach ($steps as [$from, $rate]) {
            if ($raisedKobo >= $from) $bps = $rate;
        }
        return $bps;
    }

    /**
     * Eligible money raised strictly before this contribution, in kobo.
     *
     * Through the category, never a join on gates_award_cycles (CLAUDE.md, edition scale).
     */
    public static function raisedBefore(string $basis, int $nomineeId, ?int $cycleId, object $donation): int
    {
        $at = (string) ($donation->created_at ?? '');
        $id = (int) ($donation->id ?? 0);
        try {
            $q = DB::table('gates_donations as d')
                ->where('d.tier', 'paid-vote')->where('d.status', 'confirmed')
                ->whereNull('d.refunded_at')->whereNull('d.refund_requested_at')
                ->where(static function ($w) use ($at, $id) {
                    $w->where('d.created_at', '<', $at)
                      ->orWhere(static fn ($x) => $x->where('d.created_at', '=', $at)->where('d.id', '<', $id));
                })
                ->whereNotExists(static function ($q) {
                    $q->select(DB::raw(1))->from('gates_votes as v')
                      ->whereColumn('v.donation_id', 'd.id')->where('v.fraud_flag', 1);
                });
            if ($basis === self::BASIS_EDITION) {
                if ($cycleId === null || $cycleId < 1) return 0;
                $q->join('gates_nominees as n', 'n.id', '=', 'd.intent_nominee_id')
                  ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                  ->where('c.cycle_id', $cycleId);
            } else {
                $q->where('d.intent_nominee_id', $nomineeId);
            }
            return (int) round(((float) $q->sum('d.amount_naira')) * 100);
        } catch (\Throwable) {
            // Unreadable history prices the contribution as the FIRST one, which on every
            // sane ladder is the lowest step: understate, never overpay.
            return 0;
        }
    }

    /**
     * Read an operator's ladder from the edition form: one step per line, "50000: 30".
     *
     * Accepts "₦50,000 = 30%" too, because that is how people write it. Refuses rather than
     * guesses: a ladder is money, and a line the parser half-understood is a rate nobody
     * chose.
     *
     * @return array{ok:bool, steps:list<array{0:int,1:int}>, message:string}
     */
    public static function parse(string $text): array
    {
        $steps = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^₦?\s*([\d,]+)\s*[:=]\s*([\d.]+)\s*%?$/u', $line, $m)) {
                return ['ok' => false, 'steps' => [], 'message' => 'Line ' . ($n + 1) . ' should read like “50000: 30” (from ₦50,000 raised, 30%).'];
            }
            $from = (int) str_replace(',', '', $m[1]);
            $pct  = (float) $m[2];
            if ($pct > 100) return ['ok' => false, 'steps' => [], 'message' => 'Line ' . ($n + 1) . ': a share cannot be more than 100%.'];
            if (isset($steps[$from])) return ['ok' => false, 'steps' => [], 'message' => 'Two steps start at ₦' . number_format($from) . '.'];
            $steps[$from] = [$from, (int) round($pct * 100)];
        }
        if ($steps === []) return ['ok' => false, 'steps' => [], 'message' => 'Write at least one step, like “0: 20”.'];
        if (count($steps) > self::MAX_STEPS) return ['ok' => false, 'steps' => [], 'message' => 'Keep the ladder to ' . self::MAX_STEPS . ' steps or fewer.'];
        ksort($steps);
        return ['ok' => true, 'steps' => array_values($steps), 'message' => ''];
    }

    /** The ladder as the edition form shows it back, one step per line. */
    public static function format(array $rules, string $model): string
    {
        $lines = [];
        foreach (self::steps($rules, $model) as [$from, $bps]) {
            $lines[] = intdiv($from, 100) . ': ' . self::pct($bps);
        }
        return implode("\n", $lines);
    }

    /** A sentence for the public page and the console, from the rules in force. */
    public static function describe(array $rules, string $model): string
    {
        $steps = self::steps($rules, $model);
        if ($steps === []) return 'No ladder set.';
        $who = self::basis($rules, $model) === self::BASIS_EDITION ? 'the whole edition' : 'the nominee';

        $parts = [];
        foreach ($steps as [$from, $bps]) {
            $parts[] = $from === 0 ? self::pct($bps) . '% from the first naira'
                                   : self::pct($bps) . '% once ' . $who . ' has raised ₦' . number_format(intdiv($from, 100));
        }
        $s = $model === self::MODEL_FLAT
            ? self::pct($steps[0][1]) . '% of every eligible contribution'
            : ucfirst(implode(', ', $parts));
        if (self::locked($rules, $model)) $s .= '; held locked until the edition’s milestone is confirmed';
        return $s . '.';
    }

    public static function pct(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.') ?: '0';
    }
}
