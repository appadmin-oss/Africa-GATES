<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What the console remembers about its alerts: when each was first seen, when it last
 * resolved, whether it came back, who took it, and the timeline of all of that
 * (AdminConsole v2, README §10b; `2027_03_26_console_alert_state.php`).
 *
 * The alerts themselves stay derived ({@see ConsoleAlerts}); this only RECORDS what the
 * derived list has looked like. {@see sync()} compares the open alerts with the log:
 *
 *   · an alert not in the log is RAISED;
 *   · an alert whose row is resolved has REOPENED — the count says how many times, which
 *     is the difference between a blip and a fault that keeps coming back;
 *   · a row still open whose alert is gone has RESOLVED — its check passed;
 *   · a check that THREW is neither: it is unknown, and an unknown is never written down
 *     as fixed ({@see ConsoleAlerts::errored()}).
 *
 * There is no "close" button by design: an alert closed by hand while its check still
 * fails is the console reporting that something broken is fine. It is synced when the
 * Alerts page is opened and on every maintenance tick, so the history has a row even for
 * a fault that came and went while nobody was looking.
 */
final class ConsoleAlertLog
{
    /** Whether the tables exist — a deployment that has not migrated still renders. */
    public static function ready(): bool
    {
        return SchemaHas::table('gates_console_alerts') && SchemaHas::table('gates_console_alert_events');
    }

    /**
     * Bring the log in line with what is open now. Returns how many rows changed state.
     *
     * @param list<array<string,mixed>>|null $open defaults to {@see ConsoleAlerts::open()}
     * @param list<string>|null $errored defaults to {@see ConsoleAlerts::errored()}
     */
    public static function sync(?array $open = null, ?array $errored = null, ?string $now = null): int
    {
        if (!self::ready()) return 0;
        $open ??= ConsoleAlerts::open();
        $errored ??= ConsoleAlerts::errored();
        $now ??= date('Y-m-d H:i:s');
        $changed = 0;

        try {
            $rows = [];
            foreach (DB::table('gates_console_alerts')->get() as $r) $rows[(string) $r->alert_key] = $r;

            $seen = [];
            foreach ($open as $a) {
                $key = (string) $a['key'];
                $seen[$key] = true;
                $fields = ['severity' => (string) $a['severity'], 'impact' => $a['impact'] ?? null,
                           'title' => mb_substr((string) $a['title'], 0, 255), 'source' => mb_substr((string) $a['source'], 0, 40),
                           'last_seen_at' => $now];
                $row = $rows[$key] ?? null;
                if ($row === null) {
                    // The fact may be older than the first time anybody looked: a mail
                    // incident carries its own start, and that is when it was raised.
                    $first = self::normal($a['raised'] ?? null) ?? $now;
                    DB::table('gates_console_alerts')->insert($fields + ['alert_key' => $key, 'first_seen_at' => $first]);
                    self::event($key, 'raised', null, $first);
                    $changed++;
                } elseif ($row->resolved_at !== null) {
                    DB::table('gates_console_alerts')->where('alert_key', $key)
                        ->update($fields + ['resolved_at' => null, 'reopened_count' => (int) $row->reopened_count + 1]);
                    self::event($key, 'reopened', null, $now);
                    $changed++;
                } else {
                    DB::table('gates_console_alerts')->where('alert_key', $key)->update($fields);
                }
            }

            foreach ($rows as $key => $row) {
                if (isset($seen[$key]) || $row->resolved_at !== null) continue;
                if (in_array($key, $errored, true)) continue;      // unknown is not fixed
                DB::table('gates_console_alerts')->where('alert_key', $key)->update(['resolved_at' => $now]);
                self::event($key, 'resolved', null, $now);
                $changed++;
            }
        } catch (\Throwable $e) {
            error_log('[console-alerts] sync: ' . $e->getMessage());
        }
        return $changed;
    }

    /**
     * The open alerts, each with what the log knows about it: first seen, times it has come
     * back, and its owner.
     *
     * @param list<array<string,mixed>> $open
     * @return list<array<string,mixed>>
     */
    public static function annotate(array $open): array
    {
        $rows = self::rows(array_column($open, 'key'));
        return array_map(static function (array $a) use ($rows): array {
            $r = $rows[$a['key']] ?? null;
            return $a + [
                'first_seen' => $r->first_seen_at ?? ($a['raised'] ?? null),
                'reopened'   => (int) ($r->reopened_count ?? 0),
                'owner_id'   => isset($r->owner_admin_id) ? (int) $r->owner_admin_id : null,
                'owner'      => $r->owner_name ?? null,
                'owner_at'   => $r->owner_at ?? null,
            ];
        }, $open);
    }

    /**
     * Alerts that resolved inside the last N days, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function resolvedSince(int $days = 7): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_console_alerts as c')
                ->leftJoin('gates_admins as a', 'a.id', '=', 'c.owner_admin_id')
                ->whereNotNull('c.resolved_at')
                ->where('c.resolved_at', '>=', date('Y-m-d H:i:s', time() - $days * 86400))
                ->orderByDesc('c.resolved_at')->limit(100)
                ->get(['c.*', 'a.name as owner_name'])
                ->map(static fn (object $r): array => [
                    'key' => (string) $r->alert_key, 'severity' => (string) $r->severity, 'impact' => $r->impact,
                    'title' => (string) $r->title, 'source' => (string) $r->source,
                    'first_seen' => (string) $r->first_seen_at, 'resolved_at' => (string) $r->resolved_at,
                    'reopened' => (int) $r->reopened_count, 'owner' => $r->owner_name,
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * One alert's timeline, oldest first, with the admin's name where a person did it.
     *
     * @return list<array{kind:string, at:string, who:?string}>
     */
    public static function timeline(string $key): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_console_alert_events as e')
                ->leftJoin('gates_admins as a', 'a.id', '=', 'e.admin_id')
                ->where('e.alert_key', $key)->orderBy('e.at')->orderBy('e.id')->limit(50)
                ->get(['e.kind', 'e.at', 'a.name as who'])
                ->map(static fn (object $r): array => ['kind' => (string) $r->kind, 'at' => (string) $r->at, 'who' => $r->who])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Take an alert: one owner at a time, recorded on its timeline. */
    public static function assign(string $key, int $adminId): bool
    {
        if (!self::ready() || $adminId <= 0) return false;
        $now = date('Y-m-d H:i:s');
        try {
            $n = DB::table('gates_console_alerts')->where('alert_key', $key)->whereNull('resolved_at')
                ->update(['owner_admin_id' => $adminId, 'owner_at' => $now]);
            if ($n < 1) return false;
            self::event($key, 'assigned', $adminId, $now);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<string> $keys @return array<string,object> */
    private static function rows(array $keys): array
    {
        if (!self::ready() || $keys === []) return [];
        try {
            $out = [];
            foreach (DB::table('gates_console_alerts as c')->leftJoin('gates_admins as a', 'a.id', '=', 'c.owner_admin_id')
                         ->whereIn('c.alert_key', $keys)->get(['c.*', 'a.name as owner_name']) as $r) {
                $out[(string) $r->alert_key] = $r;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function event(string $key, string $kind, ?int $adminId, string $at): void
    {
        DB::table('gates_console_alert_events')->insert(['alert_key' => $key, 'kind' => $kind, 'admin_id' => $adminId, 'at' => $at]);
    }

    private static function normal(?string $v): ?string
    {
        $t = $v ? strtotime(str_replace('T', ' ', $v)) : false;
        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }
}
