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
 */
final class DailyChampion
{
    /** West Africa Time hour the previous day is announced. */
    public const ANNOUNCE_HOUR = 9;

    /** How many past days a sweep will still announce, if maintenance was down. */
    public const CATCH_UP_DAYS = 3;

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
     * Seal one day's champion, once. Returns the row when it was written now, null when the day
     * had nobody, was already sealed, or is not over.
     */
    public static function seal(int $programmeId, int $cycleId, string $day, ?Carbon $now = null): ?object
    {
        if (!self::ready() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return null;
        $now ??= Carbon::now();
        $end = Carbon::parse($day . ' 23:59:59', SupporterSpotlight::ZONE);
        if ($end->copy()->addHours(self::ANNOUNCE_HOUR)->greaterThan($now)) return null;
        try {
            if (DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->where('day', $day)->exists()) return null;
        } catch (\Throwable) {
            return null;
        }
        $b = SupporterSpotlight::build($cycleId, $end->copy()->setTimezone(\AfricaGates\Support\Clock::timezone()));
        $c = $b['champion'] ?? null;
        if (!$c) return null;
        // The board's "today" ends at the moment it is read; a day with no votes at all has
        // nobody today, which build() reports as no champion — checked above.
        $key = (string) $c['key'];
        try {
            $before = DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->where('person_key', $key)->where('gift', 1)->exists();
            $n = DB::table('gates_daily_champions')->insertOrIgnore([
                'programme_id' => $programmeId, 'cycle_id' => $cycleId, 'day' => $day, 'person_key' => $key, 'votes' => (int) $c['votes'],
                'name' => $c['name'] !== null ? mb_substr((string) $c['name'], 0, 80) : null, 'uid' => (int) ($c['uid'] ?? 0),
                'backing' => $c['for'] !== null ? mb_substr((string) $c['for'], 0, 190) : null, 'gift' => $before ? 0 : 1,
                'created_at' => $now->toDateTimeString()]);
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
        if (!self::ready() || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return null;
        try {
            $r = DB::table('gates_daily_champions')->where('programme_id', $programmeId)->where('day', $day)->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
        return $r ? self::shown($r) : null;
    }

    /** The edition's announced champions, newest first. @return list<object> */
    public static function recent(int $cycleId, int $limit = 7): array
    {
        if (!self::ready() || $cycleId < 1) return [];
        try {
            $rows = DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->orderByDesc('day')->limit($limit)->get()->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map([self::class, 'shown'], $rows);
    }

    /** Days this person was champion in the edition, newest first. @return list<object> */
    public static function forKeys(int $cycleId, array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if (!self::ready() || $cycleId < 1 || $keys === []) return [];
        try {
            $rows = DB::table('gates_daily_champions')->where('cycle_id', $cycleId)->whereIn('person_key', $keys)->orderByDesc('day')->get()->all();
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
        $r->date_words = Carbon::parse((string) $r->day)->format('l j F Y');
        return $r;
    }
}
