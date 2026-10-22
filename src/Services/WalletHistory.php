<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * A member's full transaction history — /account/wallet/history, and the statement files.
 *
 * ── ONE FILTER, THREE READERS ────────────────────────────────────────────────
 *
 * The page, the CSV and the statement PDF all call {@see self::query()} with the same four
 * parameters, so a statement downloaded from a filtered page is exactly the rows the page
 * showed — never "the CSV of everything", which is a different document under the same
 * button. Every parameter is a GET value, so the page works without a script.
 *
 *   q      words in the title, the line under it, the reference or the transaction number
 *   month  YYYY-MM, or `all`; unset means the latest month the member has any movement in
 *          (a new month opening empty is not a reason to show somebody nothing)
 *   type   which kind of movement: referral, return, withdrawal, points, votes
 *   f      the chip: all, in, out, or what is still moving
 *
 * ── SETTLED MONEY ONLY IN THE SUMS ───────────────────────────────────────────
 *
 * The month's "money in" and "money out" count only received, paid and spent naira, as the
 * wallet did: a withdrawal still in review has not left, and a locked credit has not arrived.
 */
final class WalletHistory
{
    public const PER_PAGE = 50;

    /** The type select, in the order it is offered. */
    public const TYPES = [
        'all'        => 'All types',
        'referral'   => 'Referral commission',
        'return'     => 'Community return',
        'withdrawal' => 'Withdrawals',
        'points'     => 'Points',
        'votes'      => 'Votes from wallet',
    ];

    public const CHIPS = ['all' => 'All', 'in' => 'Money in', 'out' => 'Money out', 'pending' => 'In progress'];

    /** Which kind of movement a transaction is, from the prefix of its key. */
    public static function kind(string $key): string
    {
        $p = strstr($key, '-', true) ?: $key;
        return match ($p) {
            'rc'        => 'referral',
            'cr'        => 'return',
            'crw', 'rp' => 'withdrawal',
            'pt'        => 'points',
            'wv'        => 'votes',
            default     => 'other',
        };
    }

    /**
     * Normalise the four parameters, so every reader filters on the same values.
     *
     * @param  array<string,mixed>       $in  the query string
     * @param  list<array<string,mixed>> $all every transaction, newest first
     * @return array{q:string, month:string, type:string, f:string, page:int}
     */
    public static function params(array $in, array $all): array
    {
        $q = mb_substr(trim((string) ($in['q'] ?? '')), 0, 80);
        $month = (string) ($in['month'] ?? '');
        if ($month !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = $all !== [] ? substr((string) $all[0]['at'], 0, 7) : date('Y-m');
        }
        $type = (string) ($in['type'] ?? 'all');
        if (!isset(self::TYPES[$type])) $type = 'all';
        $f = (string) ($in['f'] ?? 'all');
        if (!isset(self::CHIPS[$f])) $f = 'all';
        $page = max(1, min(200, (int) ($in['page'] ?? 1)));
        return ['q' => $q, 'month' => $month, 'type' => $type, 'f' => $f, 'page' => $page];
    }

    /**
     * The rows the parameters select, and the month's figures over them.
     *
     * @param  list<array<string,mixed>> $all
     * @param  array{q:string, month:string, type:string, f:string, page:int} $p
     * @return array{rows:list<array<string,mixed>>, total:int, more:bool, in_kobo:int, out_kobo:int, all:list<array<string,mixed>>}
     */
    public static function query(array $all, array $p): array
    {
        $moving = [MemberWallet::ST_PENDING, MemberWallet::ST_PROCESSING, MemberWallet::ST_LOCKED,
                   MemberWallet::ST_AWAITING, MemberWallet::ST_HELD];
        $needle = mb_strtolower($p['q']);
        $rows = array_values(array_filter($all, static function (array $t) use ($p, $moving, $needle): bool {
            if ($p['month'] !== 'all' && !str_starts_with((string) $t['at'], $p['month'])) return false;
            if ($p['type'] !== 'all' && self::kind((string) $t['key']) !== $p['type']) return false;
            $ok = match ($p['f']) {
                'in'      => $t['dir'] === 'in',
                'out'     => $t['dir'] === 'out',
                'pending' => in_array($t['status'], $moving, true),
                default   => true,
            };
            if (!$ok) return false;
            if ($needle === '') return true;
            $hay = mb_strtolower(implode(' ', [$t['title'], $t['sub'], $t['ref'] ?? '', $t['key']]));
            return str_contains($hay, $needle);
        }));

        $in = 0; $out = 0;
        foreach ($rows as $t) {
            if (($t['unit'] ?? 'naira') !== 'naira') continue;
            if ($t['dir'] === 'in' && $t['status'] === MemberWallet::ST_RECEIVED) $in += (int) $t['amount_kobo'];
            if ($t['dir'] === 'out' && in_array($t['status'], [MemberWallet::ST_PAID, MemberWallet::ST_SPENT], true)) $out += (int) $t['amount_kobo'];
        }
        $shown = self::PER_PAGE * $p['page'];
        return ['rows' => array_slice($rows, 0, $shown), 'total' => count($rows), 'more' => count($rows) > $shown,
                'in_kobo' => $in, 'out_kobo' => $out, 'all' => $rows];
    }

    /**
     * The months the select offers: every month the member has a movement in, newest first,
     * and the selected one even if it has none (so the select never contradicts the page).
     *
     * @param  list<array<string,mixed>> $all
     * @return list<array{v:string, label:string}>
     */
    public static function months(array $all, string $selected): array
    {
        $seen = [];
        foreach ($all as $t) $seen[substr((string) $t['at'], 0, 7)] = true;
        if ($selected !== 'all') $seen[$selected] = true;
        krsort($seen);
        $out = [];
        foreach (array_keys($seen) as $m) $out[] = ['v' => $m, 'label' => self::monthLabel($m)];
        $out[] = ['v' => 'all', 'label' => 'All time'];
        return $out;
    }

    public static function monthLabel(string $month): string
    {
        if ($month === 'all') return 'All time';
        $ts = strtotime($month . '-01');
        return $ts ? date('F Y', $ts) : $month;
    }

    /**
     * The CSV: one row per movement, the member's own record in a format anyone can open.
     * Through Support\Csv, which quotes a formula-leading cell with an apostrophe: a title or
     * a bank name is text somebody else typed, and a spreadsheet would otherwise run it.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        \AfricaGates\Support\Csv::put($h, ['Date', 'Transaction number', 'Type', 'Description', 'Detail', 'Direction', 'Amount', 'Unit', 'Fee (NGN)', 'Status', 'Reference'], ',', '"', '');
        foreach ($rows as $t) {
            $pts = ($t['unit'] ?? 'naira') === 'pts';
            \AfricaGates\Support\Csv::put($h, [
                (string) $t['at'], (string) $t['key'], self::TYPES[self::kind((string) $t['key'])] ?? 'Other',
                (string) $t['title'], (string) $t['sub'], $t['dir'] === 'in' ? 'in' : 'out',
                $pts ? (string) (int) $t['amount_kobo'] : number_format(((int) $t['amount_kobo']) / 100, 2, '.', ''),
                $pts ? 'points' : 'NGN', number_format(((int) ($t['fee_kobo'] ?? 0)) / 100, 2, '.', ''),
                (string) $t['status_label'], (string) ($t['ref'] ?? ''),
            ], ',', '"', '');
        }
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);
        return $out;
    }
}
