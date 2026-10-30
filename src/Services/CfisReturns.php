<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Tells CACENTRE finance the community return on each paid vote, so revenue sharing takes it
 * off the top before anything is split.
 *
 * The return cannot travel with the money: it is accrued when the payment is CONFIRMED and
 * reversed if the payment is refunded, both after Paystack has the metadata. So the figure is
 * reported by payment reference — the reference finance also reads off the settlement — and
 * finance totals the latest report for the payments behind each income line.
 *
 * What is reported is the ledger's net per payment: the accrual plus any reversal, so a
 * refunded contribution reports 0 rather than disappearing. Only payments whose ledger rows
 * changed since the last push are sent: the watermark is the highest ledger id finance has
 * accepted, and it moves only on a 200, so a failed push is simply sent again next hour.
 *
 * AND WHERE IT WENT. Under the target pool (CommunityReturnPool) an edition's returns go to the
 * nominees who reached the target — or, when nobody did, to the reserves (owner, 30 Oct 2026).
 * The pool's settlement writes no ledger row per payment, so a second watermark watches the
 * settlements: when one lands, every payment in that edition is reported again, `to: reserves`
 * where nobody reached the target, so finance gives that money to the reserves whole instead of
 * treating it as community returns.
 */
final class CfisReturns
{
    private const WATERMARK = 'cfis_returns_pushed_id';
    private const POOL_WATERMARK = 'cfis_returns_pools_pushed_id';
    private const BATCH = 500;

    /** Swapped by tests: fn(string $url, string $token): ?array. */
    public static $transport = null;

    /**
     * Push what changed. Returns how many payments were reported; 0 when there was nothing to
     * send or finance is not configured. Throws when finance refused, so the sweep says so.
     */
    public static function push(): int
    {
        $cfis = CfisCatalogue::credentials();
        if ($cfis === null) return 0;

        $from = (int) (self::setting(self::WATERMARK) ?? 0);
        $top = (int) DB::table('gates_community_returns')->whereNotNull('donation_id')->max('id');
        $poolFrom = (int) (self::setting(self::POOL_WATERMARK) ?? 0);
        $poolTop = self::poolsTop();
        if ($top <= $from && $poolTop <= $poolFrom) return 0;

        $changed = DB::table('gates_community_returns')->whereNotNull('donation_id')
            ->where('id', '>', $from)->where('id', '<=', $top)
            ->distinct()->pluck('donation_id')->map(fn ($v) => (int) $v)->all();
        if ($poolTop > $poolFrom) {
            // Every payment in an edition whose pool has settled since the last push.
            $cycles = DB::table('gates_return_pools')->where('id', '>', $poolFrom)->where('id', '<=', $poolTop)->pluck('cycle_id')->all();
            $changed = array_values(array_unique(array_merge($changed, DB::table('gates_community_returns')
                ->whereNotNull('donation_id')->whereIn('cycle_id', $cycles)
                ->distinct()->pluck('donation_id')->map(fn ($v) => (int) $v)->all())));
        }

        $sent = 0;
        foreach (array_chunk($changed, self::BATCH) as $ids) {
            $rows = self::rows($ids);
            if ($rows === []) continue;
            $token = CfisCatalogue::sign($cfis['secret'], ['returns' => $rows]);
            $res = self::send($cfis['url'] . '/api/returns/' . rawurlencode($cfis['source']), $token);
            if (!is_array($res) || ($res['ok'] ?? false) !== true) {
                throw new \RuntimeException('Finance refused the community-return report: '
                    . (is_array($res) ? (string) ($res['error'] ?? 'no reason given') : 'no answer'));
            }
            $sent += count($rows);
        }

        foreach ([self::WATERMARK => $top, self::POOL_WATERMARK => $poolTop] as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k],
                ['value' => (string) $v, 'updated_at' => Carbon::now()->toDateTimeString()]);
        }

        return $sent;
    }

    private static function setting(string $key): ?string
    {
        $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
        return $v === null ? null : (string) $v;
    }

    /** The newest pool settlement, or 0 where the pool table does not exist yet. */
    private static function poolsTop(): int
    {
        try {
            return (int) DB::table('gates_return_pools')->max('id');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The net return per payment reference, in kobo, and where it went: `reserves` for an
     * edition whose pool settled with nobody at the target, `returns` otherwise.
     *
     * @param  list<int>  $donationIds
     * @return list<array{reference:string, amount_minor:int, to:string}>
     */
    public static function rows(array $donationIds): array
    {
        if ($donationIds === []) return [];
        $q = DB::table('gates_community_returns as r')
            ->join('gates_donations as d', 'd.id', '=', 'r.donation_id')
            ->whereIn('r.donation_id', $donationIds)
            ->whereIn('r.entry_type', ['accrual', 'reversal'])
            ->groupBy('d.payment_ref')
            ->selectRaw('d.payment_ref as reference, SUM(r.amount_kobo) as kobo, MAX(r.cycle_id) as cycle_id')->get();

        $cycles = $q->pluck('cycle_id')->filter()->unique()->values()->all();
        $toReserves = [];
        if ($cycles !== []) {
            try {
                $toReserves = array_flip(DB::table('gates_return_pools')->whereIn('cycle_id', $cycles)
                    ->where('winners', 0)->pluck('cycle_id')->map(fn ($v) => (int) $v)->all());
            } catch (\Throwable) {
                $toReserves = [];
            }
        }

        $out = [];
        foreach ($q as $r) {
            if ((string) $r->reference === '') continue;
            $out[] = ['reference' => (string) $r->reference, 'amount_minor' => max(0, (int) $r->kobo),
                      'to' => isset($toReserves[(int) $r->cycle_id]) ? 'reserves' : 'returns'];
        }
        return $out;
    }

    private static function send(string $url, string $token): ?array
    {
        if (is_callable(self::$transport)) return (self::$transport)($url, $token);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-CFIS-Token: ' . $token],
            CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        $json = json_decode($body, true);

        return is_array($json) ? $json : null;
    }
}
