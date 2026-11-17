<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE CHAMPION OF THE DAY, ANNOUNCED (owner, 17 Nov 2026: "they get the gift if they become
 * champion of the day. The champion is announced at the end of the day and is shareable";
 * "most votes"; "the gift may not be physical, but they can contact us to claim it").
 *
 * The live board names whoever leads TODAY; that can change until midnight, and a paid vote
 * can still be reversed after it. So the champion is ANNOUNCED the morning after, at
 * {@see ANNOUNCE_HOUR} West Africa Time, once the day's payments have had the night to settle —
 * the framework's "publish the winner after reconciliation". Counting is the board's own
 * (SupporterSpotlight::build read at the day's last second): verified votes, refunded and
 * unsettled orders left out, fraud-flagged rows left out, ties to whoever got there first.
 *
 * Then it is SEALED: one row per edition per day, never rewritten. A refund next week does not
 * quietly hand an announced day to somebody else — the announcement is what was announced.
 *
 * ONE GIFT PER PERSON PER CAMPAIGN (the framework's rule, "so a small number of wealthy donors
 * cannot consume all available benefits"): a person can be champion on many days and is
 * celebrated every time, but `gift` is set only the first time. The gift itself is whatever
 * the award's campaign says (AwardCampaign `champion_gifts`), claimed by contacting the team
 * with the code {@see self::code()} — checkable at /champions/{code}, so whoever receives the
 * claim can see in one click that it is genuine.
 *
 * THREE KINDS, ONE MECHANISM (owner, 17 Nov 2026: "become the champion donor of the day, week
 * and award"; prizes "tied to champions"). The Champion of the WEEK is the most votes from one
 * person Monday to Sunday, West Africa Time, announced the Monday morning; the Champion of the
 * AWARD is the most votes across the whole edition, announced the morning after voting closes.
 * Each is counted by the same board, sealed the same way and claimed with the same kind of
 * code. The period key says which (`2026-11-16`, `2026-W47`, `award`), so one row per period
 * needs no new constraint; `kind` is stored beside it so the gift rule can be asked per kind:
 * a person gets each kind's gift once, so the day's champion can still win the week's prize.
 *
 * A ranking, never a draw. Prizes here go to whoever gave the most, by a rule published on
 * the offer page — a prize drawn by chance from people who paid to enter would be a lottery.
 */
final class DailyChampion
{
    /** West Africa Time hour the previous day is announced. */
    public const ANNOUNCE_HOUR = 9;

    /** How many past days a sweep will still announce, if maintenance was down. */
    public const CATCH_UP_DAYS = 3;

    /** What each kind is called, everywhere it is shown. */
    public const KINDS = ['day' => 'Champion of the Day', 'week' => 'Champion of the Week', 'award' => 'Champion of the Award'];

    /** The period key of the whole edition. */
    public const AWARD = 'award';

    /** Which kind a period key is, or null for a key that is none of them. */
    public static function kindOf(string $key): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) return 'day';
        if (preg_match('/^\d{4}-W\d{2}$/', $key)) return 'week';
        return $key === self::AWARD ? 'award' : null;
    }

    /** The ISO week key of the week a moment falls in, West Africa Time: `2026-W47`. */
    public static function weekKey(Carbon $at): string
    {
        return $at->copy()->setTimezone(SupporterSpotlight::ZONE)->format('o-\\WW');
    }

    /**
     * When a period's counting ENDS (its last second, in the app's zone) and when it may be
     * ANNOUNCED. Null for a key that is not a period, or an award whose voting has no close.
     *
     * @return array{0:Carbon,1:Carbon}|null
     */
    public static function window(int $cycleId, string $key): ?array
    {
        $zone = \AfricaGates\Support\Clock::timezone();
        switch (self::kindOf($key)) {
            case 'day':
                $end = Carbon::parse($key . ' 23:59:59', SupporterSpotlight::ZONE);
                return [$end->copy()->setTimezone($zone), $end->copy()->addHours(self::ANNOUNCE_HOUR)->setTimezone($zone)];
            case 'week':
                [$y, $w] = array_map('intval', explode('-W', $key));
                $end = Carbon::now(SupporterSpotlight::ZONE)->setISODate($y, $w, 7)->setTime(23, 59, 59);
                return [$end->copy()->setTimezone($zone), $end->copy()->addHours(self::ANNOUNCE_HOUR)->setTimezone($zone)];
            case 'award':
                try {
                    $close = DB::table('gates_award_cycles')->where('id', $cycleId)->value('voting_close');
                } catch (\Throwable) {
                    return null;
                }
                if (!$close) return null;
                // The same night to settle as a day, and never announced before nine in the
                // morning: voting that closes at five in the afternoon is announced at nine.
                $end = Carbon::parse((string) $close, $zone);
                $at = $end->copy()->addHours(self::ANNOUNCE_HOUR)->setTimezone(SupporterSpotlight::ZONE);
                if ($at->hour < self::ANNOUNCE_HOUR) $at->setTime(self::ANNOUNCE_HOUR, 0);
                return [$end, $at->setTimezone($zone)];
        }
        return null;
    }

    /**
     * Who the board names for a period, read at the period's last second, with their address
     * (for the message). The count is the board's own: SupporterSpotlight::build.
     */
    public static function leaderAt(int $cycleId, string $key): ?array
    {
        $w = self::window($cycleId, $key);
        if ($w === null) return null;
        $b = SupporterSpotlight::build($cycleId, $w[0]->copy());
        return match (self::kindOf($key)) {
            'day' => $b['champion'] ?? null,
            'week' => $b['week_champion'] ?? null,
            'award' => $b['top'][0] ?? null,
            default => null,
        };
    }

    /** Is the `kind` column there yet (2027_06_02)? Before it, only days exist. */
    private static function kinds(): bool
    {
        return SchemaHas::column('gates_daily_champions', 'kind');
    }

    public static function ready(): bool
    {
        return SchemaHas::table('gates_daily_champions');
    }

    /** Announce every day that has closed and settled. Returns how many were sealed now. */
    public static function sweep(?Carbon $now = null): int
    {
        if (!self::ready()) return 0;
        $now ??= Carbon::now();
        try {
            $progs = DB::table('gates_award_programmes')->where('is_active', 1)->get(['id', 'campaign_json'])->all();
        } catch (\Throwable) {
            return 0;
        }
        $sealed = 0;
        foreach ($progs as $prog) {
            if (!\AfricaGates\Support\AwardCampaign::of($prog)['spotlight']) continue;
            $cycle = BallotGuard::currentCycleForProgramme((int) $prog->id);
            if (!$cycle) continue;
            foreach (self::due($now) as $day) {
                if (self::seal((int) $prog->id, (int) $cycle->id, $day, $now) !== null) $sealed++;
            }
            if (!self::kinds()) continue;
            foreach ([...self::dueWeeks($now), self::AWARD] as $key) {
                if (self::seal((int) $prog->id, (int) $cycle->id, $key, $now) !== null) $sealed++;
            }
        }
        return $sealed;
    }

    /**
     * The days that may be announced now: yesterday once it is past the announce hour, and a
     * few before it in case a sweep was missed. Newest last.
     *
     * @return list<string> Y-m-d, West Africa Time
     */
    public static function due(Carbon $now): array
    {
        $lagos = $now->copy()->setTimezone(SupporterSpotlight::ZONE);
        $latest = $lagos->hour >= self::ANNOUNCE_HOUR ? $lagos->copy()->subDay() : $lagos->copy()->subDays(2);
        $out = [];
        for ($i = self::CATCH_UP_DAYS - 1; $i >= 0; $i--) $out[] = $latest->copy()->subDays($i)->format('Y-m-d');
        return $out;
    }

    /**
     * The weeks that may be announced now: last week once it is past the announce hour on
     * Monday, and the one before in case a sweep was missed. Newest last.
     *
     * @return list<string> `2026-W47`
     */
    public static function dueWeeks(Carbon $now): array
    {
        $lagos = $now->copy()->setTimezone(SupporterSpotlight::ZONE);
        $monday = $lagos->copy()->startOfWeek(Carbon::MONDAY);
        $latest = $lagos->greaterThanOrEqualTo($monday->copy()->setTime(self::ANNOUNCE_HOUR, 0)) ? $monday->copy()->subWeek() : $monday->copy()->subWeeks(2);
        return [self::weekKey($latest->copy()->subWeek()), self::weekKey($latest)];
    }

    /**
     * Seal one day's champion, once. Returns the row when it was written now, null when the day
     * had nobody, was already sealed, or is not over.
     */
    public static function seal(int $programmeId, int $cycleId, string $day, ?Carbon $now = null): ?object
    {
        $kind = self::kindOf($day);
        if (!self::ready() || $kind === null || ($kind !== 'day' && !self::kinds())) return null;
        $now ??= Carbon::now();
        $w = self::window($cycleId, $day);
        if ($w === null || $w[1]->greaterThan($now)) return null;
        try {
            if (DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->where('day', $day)->exists()) return null;
        } catch (\Throwable) {
            return null;
        }
        // A period with no votes at all has nobody, which the board reports as no leader.
        $c = self::leaderAt($cycleId, $day);
        if (!$c) return null;
        $key = (string) $c['key'];
        try {
            // One gift per person per KIND each campaign: the day's champion can still win the
            // week's prize, and the award's.
            $q = DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->where('person_key', $key)->where('gift', 1);
            $before = (self::kinds() ? $q->where('kind', $kind) : $q)->exists();
            $row = ['programme_id' => $programmeId, 'cycle_id' => $cycleId, 'day' => $day, 'person_key' => $key, 'votes' => (int) $c['votes'],
                'name' => $c['name'] !== null ? mb_substr((string) $c['name'], 0, 80) : null, 'uid' => (int) ($c['uid'] ?? 0),
                'backing' => $c['for'] !== null ? mb_substr((string) $c['for'], 0, 190) : null, 'gift' => $before ? 0 : 1,
                'created_at' => $now->toDateTimeString()];
            if (self::kinds()) $row['kind'] = $kind;
            $n = DB::table('gates_daily_champions')->insertOrIgnore($row);
        } catch (\Throwable) {
            return null;
        }
        return $n === 1 ? self::day($cycleId, $day) : null;
    }

    public static function day(int $cycleId, string $day): ?object
    {
        if (!self::ready()) return null;
        try {
            $r = DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->where('day', $day)->first();
        } catch (\Throwable) {
            return null;
        }
        return $r ? self::shown($r) : null;
    }

    /** A programme's champion on a day, whichever edition it belonged to. */
    public static function forProgrammeDay(int $programmeId, string $day): ?object
    {
        if (!self::ready() || self::kindOf($day) === null) return null;
        try {
            $r = DB::table('gates_daily_champions')->where('programme_id', $programmeId)->where('day', $day)->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
        return $r ? self::shown($r) : null;
    }

    /** The edition's announced champions of one kind, newest first. @return list<object> */
    public static function recent(int $cycleId, int $limit = 7, string $kind = 'day'): array
    {
        if (!self::ready() || $cycleId < 1 || !isset(self::KINDS[$kind])) return [];
        if (!self::kinds() && $kind !== 'day') return [];
        try {
            $q = DB::table('gates_daily_champions')->where('cycle_id', $cycleId);
            if (self::kinds()) $q->where('kind', $kind);
            $rows = $q->orderByDesc('id')->limit($limit)->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map([self::class, 'shown'], $rows);
    }

    /** Every period this person was champion in the edition, of any kind, newest first. @return list<object> */
    public static function forKeys(int $cycleId, array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if (!self::ready() || $cycleId < 1 || $keys === []) return [];
        try {
            $rows = DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->whereIn('person_key', $keys)->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map([self::class, 'shown'], $rows);
    }

    /**
     * The code a champion quotes to claim their gift: the row, signed. Anybody can check it at
     * /champions/{code}; nobody can make one up.
     */
    public static function code(object $row): string
    {
        return 'CD' . (int) $row->id . '-' . strtoupper(substr(EmailOptOut::sign('champion:' . (int) $row->id . ':' . $row->person_key, 'champion-claim'), 0, 10));
    }

    public static function byCode(string $code): ?object
    {
        if (!self::ready() || !preg_match('/^CD(\d+)-([A-F0-9]{10})$/i', trim($code), $m)) return null;
        try {
            $r = DB::table('gates_daily_champions')->where('id', (int) $m[1])->first();
        } catch (\Throwable) {
            return null;
        }
        return $r && hash_equals(self::code($r), strtoupper(trim($code))) ? self::shown($r) : null;
    }

    /**
     * How the row is shown: the name sealed with it, unless the person has since asked to be
     * taken off the Spotlight — a request honoured on every page, including past days.
     */
    private static function shown(object $r): object
    {
        $hidden = false;
        try {
            $hidden = SchemaHas::table('gates_spotlight_hidden') && DB::table('gates_spotlight_hidden')->where('person_key', (string) $r->person_key)->exists();
        } catch (\Throwable) {
        }
        $r->display = !$hidden && trim((string) ($r->name ?? '')) !== '' ? (string) $r->name : null;
        $r->photo = null;
        if (!$hidden && (int) $r->uid > 0) {
            try {
                $cols = array_values(array_filter(['photo_path', 'avatar_path'], static fn ($c) => SchemaHas::column('gates_users', $c)));
                $u = $cols ? DB::table('gates_users')->where('id', (int) $r->uid)->first($cols) : null;
                $r->photo = $u ? (trim((string) (($u->photo_path ?? '') ?: ($u->avatar_path ?? ''))) ?: null) : null;
            } catch (\Throwable) {
            }
        }
        $r->kind = self::kindOf((string) $r->day) ?? 'day';
        $r->title = self::KINDS[$r->kind];
        $r->date_words = self::words((string) $r->day);
        return $r;
    }

    /** A period in words: `Monday 16 November 2026`, `the week of 16 November 2026`, `the whole award`. */
    public static function words(string $key): string
    {
        return match (self::kindOf($key)) {
            'day' => Carbon::parse($key)->format('l j F Y'),
            'week' => (function () use ($key): string {
                [$y, $w] = array_map('intval', explode('-W', $key));
                return 'the week of ' . Carbon::now(SupporterSpotlight::ZONE)->setISODate($y, $w, 1)->format('j F Y');
            })(),
            'award' => 'the whole award',
            default => $key,
        };
    }
}
