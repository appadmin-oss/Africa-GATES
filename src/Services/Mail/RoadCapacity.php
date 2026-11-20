<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * EVERY ROAD'S OWN ALLOWANCE, AND MAIL SENT WHERE THERE IS ROOM.
 *
 * Owner, 5 Nov 2026: "smarter use of the available infrastructure with integrations to
 * optimise and overcome limitations". The platform can deliver by several roads — the SMTP
 * account, Brevo's HTTP API, Google Apps Script, the host's own mail — and each has its own
 * daily allowance. Two things ignored that:
 *
 *   · The send rules capped announcements at 60% of ONE number, the SMTP account's limit. A
 *     deployment with a Gmail account (500 a day) AND Brevo's API stopped every announcement
 *     at 300 while the API sat unused, because the cap never asked what else could carry it.
 *   · A road that answered "quota exceeded" was retried on every following message — each one
 *     paying for a refused attempt before falling through — and when it was the first road,
 *     every message in the queue paid it. Mail went slow, then a share of it failed.
 *
 * Now each road's allowance is known (`limit()`), its use today is counted from the mail log
 * (`gates_mail_log.via`, written by OtpService for every message a road carried), a road that
 * is spent or has just refused for quota goes to the BACK of the order (`order()`) and rests
 * for {@see REST_HOURS} — never removed, so a lone road is still tried — and the announcement
 * cap is the share of the COMBINED allowance of the roads that may carry announcements
 * (`bulkTotal()`). Apps Script never carries announcements (its ~100 a day are for sign-in
 * codes), so it never adds to that total.
 *
 * A limit of 0 means nobody knows it. A road with an unknown limit makes the combined total
 * unknown too — the cap then steps aside and each road's own refusals govern, which is what
 * happened before any of this existed.
 */
final class RoadCapacity
{
    /** Brevo's API allowance a day (300 on the free plan); 0 when unknown. Set in Settings → Email. */
    public const API_KEY = 'mail_api_daily_limit';
    /** MailApp's allowance a day: 100 on a gmail.com account, 1,500 on Workspace. Settable. */
    public const GAS_KEY = 'mail_gas_daily_limit';
    public const GAS_DEFAULT = 100;
    /** What the script last said it may still send today, and when: {n, at}. */
    public const GAS_SEEN_KEY = 'mail_gas_remaining';
    /** Of Apps Script's allowance, the part announcements may never touch: sign-in codes and receipts. */
    public const GAS_RESERVE_SHARE = 0.3;
    public const GAS_RESERVE_MIN = 20;
    public const REST_KEY = 'mail_road_rest';
    public const REST_HOURS = 6;

    /** Roads that may carry an announcement. */
    public const BULK = ['smtp', 'smtp-env', 'api'];

    /** @var array<string,int>|null */
    private static ?array $usedMemo = null;
    private static int $usedAt = 0;

    public static function limit(string $road, ?MailConfig $c = null): int
    {
        return match ($road) {
            'smtp', 'smtp-env' => ($c ?? MailConfig::load())->dailyLimit(),
            'api'  => max(0, (int) self::setting(self::API_KEY, '0')),
            'gas'  => max(0, (int) self::setting(self::GAS_KEY, (string) self::GAS_DEFAULT)),
            default => 0,
        };
    }

    /** Messages this road carried in the last 24 hours. Read once a minute — a campaign asks for every message. */
    public static function used(string $road): int
    {
        if (self::$usedMemo === null || time() - self::$usedAt >= 60) {
            self::$usedMemo = [];
            self::$usedAt = time();
            if (SchemaHas::column('gates_mail_log', 'via')) {
                $since = Carbon::now()->subHours(24)->toDateTimeString();
                foreach (DB::table('gates_mail_log')->where('status', MailLog::SENT)->where('created_at', '>=', $since)
                             ->groupBy('via')->get([DB::raw('via as v'), DB::raw('COUNT(*) as n')]) as $r) {
                    // Rows from before the column existed were the SMTP account's.
                    self::$usedMemo[(string) ($r->v ?: 'smtp')] = (self::$usedMemo[(string) ($r->v ?: 'smtp')] ?? 0) + (int) $r->n;
                }
            }
        }
        return self::$usedMemo[$road] ?? 0;
    }

    /** True while the road is spent (its own count) or resting after a quota refusal. */
    public static function exhausted(string $road, ?MailConfig $c = null): bool
    {
        if ($road === 'gas' && self::gasRemaining() === 0) return true;
        $limit = self::limit($road, $c);
        if ($limit > 0 && self::used($road) >= $limit) return true;
        $until = self::rests()[$road] ?? '';
        return $until !== '' && $until > Carbon::now()->toDateTimeString();
    }

    /**
     * The script's own count of what it may still send today — exact, where ours is an
     * estimate — recorded from every answer it gives (AppsScriptMail). Null when it is unknown
     * or more than a day old, since MailApp's allowance renews within the day.
     */
    public static function noteGasRemaining(int $n): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(['key_name' => self::GAS_SEEN_KEY],
                ['value' => json_encode(['n' => max(0, $n), 'at' => Carbon::now()->toDateTimeString()])]);
        } catch (\Throwable) {
        }
    }

    public static function gasRemaining(): ?int
    {
        $v = json_decode(self::setting(self::GAS_SEEN_KEY, ''), true);
        if (!is_array($v) || !isset($v['n'], $v['at'])) return null;
        if ((string) $v['at'] < Carbon::now()->subHours(24)->toDateTimeString()) return null;
        return (int) $v['n'];
    }

    /** What announcements must leave in Apps Script's allowance. */
    public static function gasReserve(): int
    {
        return max(self::GAS_RESERVE_MIN, (int) ceil(self::limit('gas') * self::GAS_RESERVE_SHARE));
    }

    /**
     * May an announcement go by Apps Script now? Only while more than the reserve is left —
     * read from the script's own count where it has given one, else from ours.
     *
     * Announcements used to be barred from this road outright, on the reasoning that its
     * allowance belongs to sign-in codes. On a deployment where Apps Script is the road that
     * WORKS (SMTP refusing, no API), that bar meant no campaign, newsletter or reminder ever
     * left — held for an SMTP that was never coming back. The reserve keeps the original
     * promise (codes always have room) without stranding everything else.
     */
    public static function gasMayCarryBulk(): bool
    {
        $left = self::gasRemaining() ?? (self::limit('gas') - self::used('gas'));
        return $left > self::gasReserve();
    }

    /** A road said "quota": rest it, so the next messages go straight to one that has room. */
    public static function rest(string $road): void
    {
        $r = self::rests();
        $r[$road] = Carbon::now()->addHours(self::REST_HOURS)->toDateTimeString();
        try {
            DB::table('gates_settings')->updateOrInsert(['key_name' => self::REST_KEY], ['value' => json_encode($r)]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string,string> road => resting until */
    public static function rests(): array
    {
        $v = json_decode((string) self::setting(self::REST_KEY, '{}'), true);
        return is_array($v) ? array_filter($v, 'is_string') : [];
    }

    /**
     * The roads in the order to try them: the operator's order, with any road that is spent or
     * resting moved to the back. Never fewer roads than were given.
     *
     * @param list<string> $roads
     * @return list<string>
     */
    public static function order(array $roads, ?MailConfig $c = null): array
    {
        if (count($roads) < 2) return $roads;
        $ready = $spent = [];
        foreach ($roads as $r) {
            if (self::exhausted($r, $c)) $spent[] = $r; else $ready[] = $r;
        }
        return array_merge($ready, $spent);
    }

    /**
     * The combined daily allowance of the roads that may carry announcements, or 0 when any of
     * them is unknown (then the cap steps aside). Only roads that are actually configured count.
     */
    public static function bulkTotal(?MailConfig $c = null): int
    {
        $c ??= MailConfig::load();
        $roads = [];
        if ($c->hasCredentials()) $roads[] = 'smtp';
        if ($c->hasApiKey()) $roads[] = 'api';
        // Apps Script, as far as announcements may use it (its allowance less the reserve).
        if (AppsScriptMail::configured() && self::gasMayCarryBulk()) $roads[] = 'gas';
        if ($roads === []) return $c->dailyLimit();
        $total = 0;
        foreach ($roads as $r) {
            $l = self::limit($r, $c);
            if ($l <= 0) return 0;
            $total += $r === 'gas' ? max(0, $l - self::gasReserve()) : $l;
        }
        return $total;
    }

    /** For the console's Email page: each configured road, its allowance, what it carried today. @return list<array{road:string, limit:int, used:int, resting:?string}> */
    public static function summary(?MailConfig $c = null): array
    {
        $c ??= MailConfig::load();
        $out = [];
        foreach (['smtp' => $c->hasCredentials(), 'api' => $c->hasApiKey(), 'gas' => AppsScriptMail::configured()] as $road => $on) {
            if (!$on) continue;
            $rest = self::rests()[$road] ?? null;
            $out[] = ['road' => $road, 'limit' => self::limit($road, $c), 'used' => self::used($road),
                      'resting' => $rest !== null && $rest > Carbon::now()->toDateTimeString() ? $rest : null];
        }
        return $out;
    }

    public static function forget(): void
    {
        self::$usedMemo = null;
    }

    private static function setting(string $key, string $default): string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return $v === null ? $default : (string) $v;
        } catch (\Throwable) {
            return $default;
        }
    }
}
