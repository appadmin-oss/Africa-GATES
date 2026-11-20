<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Admin\Support\TimeRange;
use AfricaGates\Admin\Support\TimeSeries;
use AfricaGates\Services\CyclePolicy;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AdminConsole v2's Home, block by block (README §10b): the brief, Needs attention with
 * its area tabs, the running editions, Performance, and the side column's This week,
 * System and Recent.
 *
 * Every block is built from a record this platform already keeps, and each has a calm
 * empty state rather than disappearing — an operator has to be able to see that a count
 * is zero (README §10, thin data). Nothing here invents a figure: where a design shows a
 * number the platform does not record, the block says less rather than guessing.
 *
 * The jobs come from {@see HomeBoard::board()} and the rail's counts from
 * {@see HomeBoard::counts()}, so Home, the rail and the tab bar agree by construction.
 */
final class HomeBlocks
{
    /** Needs attention's tabs, in order. A job in no tab's area shows under All only. */
    public const AREAS = ['awards' => 'Awards', 'money' => 'Money', 'content' => 'Content'];

    /** The five stages an edition's tracker draws, in order. */
    private const STAGES = ['nominations' => 'Nominations', 'shortlisting' => 'Shortlisting',
                            'voting' => 'Voting', 'judging' => 'Judging', 'results' => 'Results'];

    // ══ Needs attention ═══════════════════════════════════════════════════════

    /**
     * The board's jobs, each with the area tab it belongs to and how long the oldest has
     * waited, where that is recorded.
     *
     * @return list<array<string,mixed>>
     */
    public static function attention(string $role): array
    {
        $out = [];
        foreach (HomeBoard::board($role) as $j) {
            $j['area'] = self::areaOf((string) $j['key'], (string) $j['href']);
            $j['age']  = null;
            if ($j['key'] === 'review') {
                try {
                    $oldest = (string) DB::table('gates_nominations')->where('status', 'pending')->min('created_at');
                    $j['age'] = $oldest !== '' ? $oldest : null;
                } catch (\Throwable) {}
            }
            $j['age_said'] = $j['age'] !== null ? self::waited($j['age']) : null;
            $j['late']     = self::late($j['age']);
            $out[] = $j;
        }
        return $out;
    }

    private static function areaOf(string $key, string $href): string
    {
        return match (true) {
            in_array($key, ['owed', 'unasked'], true), str_starts_with($href, '/admin/payments'),
            str_starts_with($href, '/admin/refunds'), str_starts_with($href, '/admin/payouts') => 'money',
            str_starts_with($href, '/admin/moderation'), str_starts_with($href, '/admin/support'),
            str_starts_with($href, '/admin/posts') => 'content',
            $key === 'alerts' => 'system',
            str_starts_with($href, '/admin/partners') => 'engage',
            default => 'awards',
        };
    }

    // ══ the brief ═════════════════════════════════════════════════════════════

    /**
     * Up to three sentences on the state of the day, most serious first — each one a fact
     * the platform recorded, never a forecast. Red for something broken or owed, gold for
     * something waiting, green for something that moved the right way.
     *
     * @param list<array<string,mixed>> $jobs from {@see attention()}
     * @return list<array{tone:string, text:string}>
     */
    public static function brief(string $role, array $jobs): array
    {
        $by = [];
        foreach ($jobs as $j) $by[(string) $j['key']] = $j;
        $lines = [];

        if (isset($by['alerts'])) {
            $high = ConsoleAlerts::high();
            $first = $high[0] ?? null;
            if ($first !== null) {
                $more = count($high) - 1;
                $lines[] = ['tone' => 'red', 'text' => rtrim((string) $first['title'], '.') . '.'
                    . ($more > 0 ? ' ' . $more . ' more high alert' . ($more === 1 ? ' is' : 's are') . ' open.' : '')];
            }
        }
        if (isset($by['owed'])) {
            $n = (int) $by['owed']['count'];
            $lines[] = ['tone' => 'red', 'text' => $n . ' paid order' . ($n === 1 ? ' has' : 's have')
                . ' no votes and no refund. Each is somebody who paid and got nothing.'];
        }
        if (isset($by['review'])) {
            $n = (int) $by['review']['count'];
            $age = $by['review']['age'] ?? null;
            $lines[] = ['tone' => 'gold', 'text' => $n . ' nomination' . ($n === 1 ? ' is' : 's are') . ' waiting for a decision'
                . ($age ? '; the oldest has waited ' . self::waited($age) . '.' : '.')];
        }
        if (isset($by['quorum'])) {
            $lines[] = ['tone' => 'gold', 'text' => ucfirst((string) $by['quorum']['count'] . ' ' . $by['quorum']['label']) . '.'];
        }

        // One movement, measured: votes in the last day against the day before.
        try {
            $now = time();
            $today = (int) DB::table('gates_votes')->where('voted_at', '>=', date('Y-m-d H:i:s', $now - 86400))->count();
            $before = (int) DB::table('gates_votes')->whereBetween('voted_at', [date('Y-m-d H:i:s', $now - 2 * 86400), date('Y-m-d H:i:s', $now - 86400)])->count();
            if ($today > 0) {
                $text = number_format($today) . ' vote' . ($today === 1 ? '' : 's') . ' in the last 24 hours';
                if ($before > 0) {
                    $d = (int) round(($today - $before) * 100 / $before);
                    $text .= $d === 0 ? ', the same as the day before' : ', ' . ($d > 0 ? 'up ' : 'down ') . abs($d) . '% on the day before';
                }
                $lines[] = ['tone' => 'green', 'text' => $text . '.'];
            }
        } catch (\Throwable) {}

        // Built in severity order — broken, owed, waiting, then the one movement — so the
        // first three are always the three most serious.
        return array_slice($lines, 0, 3);
    }

    // ══ Editions ══════════════════════════════════════════════════════════════

    /**
     * Every edition between its first window and its results, with its stage on the
     * five-step tracker and the next date it is waiting on. An edition whose results were
     * published more than a month ago has finished, and leaves the list.
     *
     * @return list<array<string,mixed>>
     */
    public static function editions(string $role): array
    {
        if (!Permissions::canOpen($role, '/admin/programmes')) return [];
        try {
            $rows = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)
                ->where('c.status', '!=', 'archived')
                ->orderByDesc('c.year')->orderByDesc('c.id')->limit(24)
                ->get(['c.*', 'p.title as programme']);
        } catch (\Throwable) {
            return [];
        }

        $keys = array_keys(self::STAGES);
        $out = [];
        foreach ($rows as $c) {
            $st = CyclePolicy::stateFor($c);
            $phase = (string) $st['phase'];
            $at = array_search($phase, $keys, true);
            if ($at === false) continue;          // upcoming or archived: not running
            if ($phase === 'results') {
                $done = strtotime((string) ($c->results_date ?? '')) ?: 0;
                if ($done > 0 && $done < time() - 30 * 86400) continue;
            }
            $stages = [];
            foreach ($keys as $i => $k) $stages[] = ['label' => self::STAGES[$k], 'state' => $i < $at ? 'done' : ($i === $at ? 'now' : 'next')];

            $out[] = [
                'id'      => (int) $c->id,
                'name'    => (string) $c->programme,
                'edition' => EditionName::full($c),
                'stage'   => self::STAGES[$phase],
                'step'    => $at + 1,
                'stages'  => $stages,
                'next'    => self::nextMilestone($phase, $st['closes_at'] ?? null, $c),
                'href'    => '/admin/programmes/' . (int) $c->programme_id,
            ];
        }
        return $out;
    }

    private static function nextMilestone(string $phase, ?string $closes, object $c): string
    {
        $day = static fn (?string $v): string => $v ? TimeRange::show((int) strtotime($v), 'j M') : '';
        return match ($phase) {
            'nominations'  => $closes ? 'Nominations close ' . $day($closes) : 'No close date set',
            'shortlisting' => $closes ? 'Voting opens ' . $day($closes) : 'No voting date set',
            'voting'       => $closes ? 'Voting closes ' . $day($closes) : 'No close date set',
            'judging'      => $closes ? 'Results due ' . $day($closes) : 'No results date set',
            'results'      => !empty($c->results_date) ? 'Results published ' . $day((string) $c->results_date) : 'Results published',
            default        => '',
        };
    }

    // ══ Performance ═══════════════════════════════════════════════════════════

    /** What a KPI tile can measure: [label, unit-one, unit-many, money?]. */
    public const KPIS = [
        'votes'       => ['Votes', 'vote', 'votes', false],
        'nominations' => ['Nominations', 'nomination', 'nominations', false],
        'raised'      => ['Raised', 'naira', 'naira', true],
        'tickets'     => ['Tickets', 'ticket', 'tickets', false],
    ];

    /**
     * The four tiles over one window, each with the same window before it for its change,
     * and a sparkline from its own bars; the chosen one also carries its bars for the chart.
     * A metric whose table this database does not have is left out rather than drawn as 0.
     *
     * @return list<array<string,mixed>>
     */
    public static function kpis(TimeRange $range, string $chosen): array
    {
        if (!isset(self::KPIS[$chosen])) $chosen = 'votes';
        $out = [];
        foreach (self::KPIS as $key => [$label, $one, $many, $money]) {
            $m = self::measure($key, $range);
            if ($m === null) continue;
            $prev = self::measure($key, $range->previous());
            $total = $m['total'];
            $before = $prev['total'] ?? null;
            $delta = ($before !== null && $before > 0) ? (int) round(($total - $before) * 100 / $before) : null;
            $out[] = [
                'key'    => $key,
                'label'  => $label,
                'unit'   => $total == 1 ? $one : $many,
                'money'  => $money,
                'total'  => $total,
                'value'  => $money ? self::naira($total) : number_format((float) $total),
                'delta'  => $delta,
                'spark'  => self::spark($m['series']),
                'series' => $m['series'],
                'on'     => $key === $chosen,
            ];
        }
        return $out;
    }

    /** @return array{total:int|float, series:array}|null */
    private static function measure(string $key, TimeRange $range): ?array
    {
        [$lo, $hi] = [$range->fromSql(), $range->toSql()];
        try {
            switch ($key) {
                case 'votes':
                    $stamps = DB::table('gates_votes')->whereBetween('voted_at', [$lo, $hi])->orderByDesc('voted_at')->limit(50000)->pluck('voted_at')->all();
                    $total = (int) DB::table('gates_votes')->whereBetween('voted_at', [$lo, $hi])->count();
                    return ['total' => $total, 'series' => TimeSeries::bars($stamps, $range)];
                case 'nominations':
                    $stamps = DB::table('gates_nominations')->whereBetween('created_at', [$lo, $hi])->orderByDesc('created_at')->limit(50000)->pluck('created_at')->all();
                    return ['total' => count($stamps), 'series' => TimeSeries::bars($stamps, $range)];
                case 'raised':
                    // Money that cleared and was not given back — the one definition of it
                    // (PartnerOrg's countable scope), across every kind of payment.
                    $q = DB::table('gates_donations')->where('status', 'confirmed')->whereBetween('created_at', [$lo, $hi]);
                    if (SchemaHas::column('gates_donations', 'refunded_at')) $q->whereNull('refunded_at');
                    $rows = $q->limit(50000)->get(['created_at', 'amount_naira']);
                    $pairs = [];
                    $total = 0;
                    foreach ($rows as $r) { $pairs[] = [(string) $r->created_at, (int) $r->amount_naira]; $total += (int) $r->amount_naira; }
                    return ['total' => $total, 'series' => TimeSeries::weighted($pairs, $range)];
                case 'tickets':
                    $q = DB::table('gates_event_registrations')->whereBetween('created_at', [$lo, $hi]);
                    if (SchemaHas::column('gates_event_registrations', 'status')) $q->where('status', 'confirmed');
                    $stamps = $q->limit(50000)->pluck('created_at')->all();
                    return ['total' => count($stamps), 'series' => TimeSeries::bars($stamps, $range)];
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /** "₦4.2m", "₦18,500" — the tile's headline money figure. */
    public static function naira(int|float $n): string
    {
        $n = (float) $n;
        if ($n >= 1_000_000) return '₦' . rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'm';
        return '₦' . number_format($n);
    }

    /**
     * A sparkline's two paths in a 96×30 box: the line and the area under it.
     *
     * @return array{line:string, area:string}
     */
    private static function spark(array $series): array
    {
        $vals = array_map(static fn (array $b): float => (float) $b['n'], $series['bars'] ?? []);
        $n = count($vals);
        if ($n < 2) return ['line' => '', 'area' => ''];
        $max = max($vals) ?: 1.0;
        $pts = [];
        foreach ($vals as $i => $v) {
            $pts[] = sprintf('%.1f,%.1f', $i * 96 / ($n - 1), 28 - ($v / $max) * 26);
        }
        $line = 'M' . implode(' L', $pts);
        return ['line' => $line, 'area' => $line . ' L96,30 L0,30 Z'];
    }

    // ══ This week, System, Recent ═════════════════════════════════════════════

    /** @return list<array<string,mixed>> the next seven days, from {@see ConsoleCalendar}. */
    public static function week(string $role): array
    {
        $from = (int) strtotime('today', time());
        return array_slice(ConsoleCalendar::between($from, $from + 7 * 86400 - 1, $role), 0, 6);
    }

    /**
     * The status page's components from its last snapshot, or null when the check has not
     * run recently — said as such, never as an old all-clear.
     *
     * @return array{rows:list<array<string,string>>, taken_at:string}|null
     */
    public static function system(string $role): ?array
    {
        if (!Permissions::canOpen($role, '/admin/alerts')) return null;
        $s = \AfricaGates\Services\SystemStatus::snapshot();
        if ($s === null) return ['rows' => [], 'taken_at' => ''];
        return ['rows' => $s['components'], 'taken_at' => $s['taken_at']];
    }

    /**
     * The last changes made in this console, as sentences: who, what, to which record.
     *
     * @return list<array{who:string, initials:string, what:string, at:string, href:?string}>
     */
    public static function recent(string $role, AuditService $audit, int $n = 6): array
    {
        if (!Permissions::canOpen($role, '/admin/audit')) return [];
        $out = [];
        foreach ($audit->recent(40) as $r) {
            // Recent CHANGES: signing in or out and opening a record change nothing.
            $action = (string) ($r['action'] ?? '');
            if (preg_match('~^(login|logout|magic)|\.(view|login|logout)$~', $action)) continue;
            if (count($out) >= $n) break;
            $who = trim((string) ($r['admin_name'] ?? ''));
            if ($who === '') $who = ((int) ($r['admin_id'] ?? 0)) > 0 ? 'An admin' : 'The platform';
            $out[] = [
                'who'      => $who,
                'initials' => self::initials($who),
                'what'     => self::phrase($r),
                'at'       => (string) $r['created_at'],
                'href'     => $r['target_href'] ?? null,
            ];
        }
        return $out;
    }

    /** The verbs the log uses most, as a person would say them. */
    private const VERBS = [
        'create' => 'created', 'update' => 'updated', 'save' => 'saved', 'delete' => 'deleted',
        'publish' => 'published', 'approve' => 'approved', 'reject' => 'rejected', 'send' => 'sent',
        'withdraw' => 'withdrew', 'reopen' => 'reopened', 'close' => 'closed', 'open' => 'opened',
        'retire' => 'retired', 'restore' => 'restored', 'invite' => 'invited', 'cancel' => 'cancelled',
        'disqualify' => 'disqualified', 'merge' => 'merged', 'release' => 'released', 'refund' => 'refunded',
        'suspend' => 'suspended', 'reinstate' => 'reinstated', 'pin' => 'pinned', 'unpin' => 'unpinned',
        'export' => 'exported', 'check_in' => 'checked in', 'reschedule' => 'rescheduled', 'run' => 'ran',
    ];

    /**
     * "approved nomination: Jane Doe". An action the table does not know is said as its
     * own words ("stand call · open") rather than guessed at.
     *
     * @param array<string,mixed> $r
     */
    private static function phrase(array $r): string
    {
        $action = (string) ($r['action'] ?? '');
        $verbKey = str_contains($action, '.') ? substr($action, (int) strrpos($action, '.') + 1) : $action;
        $area = str_contains($action, '.') ? strstr($action, '.', true) : '';
        $thing = strtolower((string) ($r['target_label'] ?? '')) ?: str_replace('_', ' ', (string) $area);
        $name = trim((string) ($r['target_name'] ?? ''));
        $obj = trim($thing . ($name !== '' ? ': ' . $name : ''));

        if (isset(self::VERBS[$verbKey])) return trim(self::VERBS[$verbKey] . ' ' . ($obj !== '' ? $obj : 'a record'));
        return trim(str_replace('_', ' ', (string) $area) . ' · ' . str_replace('_', ' ', $verbKey) . ($name !== '' ? ': ' . $name : ''));
    }

    /** "40 minutes", "5 hours", "2 days" — how long something has been waiting. */
    public static function waited(string $at, ?int $now = null): string
    {
        $t = strtotime(str_replace('T', ' ', $at));
        if ($t === false) return '';
        $d = max(0, ($now ?? time()) - $t);
        [$n, $u] = match (true) {
            $d < 3600  => [max(1, intdiv($d, 60)), 'minute'],
            $d < 86400 => [intdiv($d, 3600), 'hour'],
            default    => [intdiv($d, 86400), 'day'],
        };
        return $n . ' ' . $u . ($n === 1 ? '' : 's');
    }

    /** Whether the oldest waiting job is past the four-hour review target (§4.1). */
    public static function late(?string $at): bool
    {
        $t = $at ? strtotime(str_replace('T', ' ', $at)) : false;
        return $t !== false && $t < time() - 4 * 3600;
    }

    private static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('~\s+~', trim($name)) ?: []));
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
        return $out !== '' ? $out : '·';
    }
}
