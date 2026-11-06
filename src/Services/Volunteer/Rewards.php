<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\PointsService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A volunteer's rewards wallet (owner, 30 Oct 2026: "Rewards, not cash"). Verified hours earn
 * points into the member's own points balance — the one PointsService already keeps, which a
 * member spends on votes — never a second balance beside it, and never money.
 *
 * ── ONLY WHAT WAS VERIFIED ───────────────────────────────────────────────────
 *
 * An hour earns when it is `verified` (a lead's tap, a confirmed claim, accepted work) and not
 * before: an hour in review is somebody's say-so. Each hours row is credited once, keyed in the
 * ledger by its id, so the sweep can run on every page view and a retry cannot pay twice. A row
 * that stops being verified is taken back, once, if the points are still there; if they were
 * already spent the ledger cannot go below nought, and the reversal waits for the balance.
 *
 * ── NOT ON THEIR OWN AWARD ───────────────────────────────────────────────────
 *
 * A volunteer who checks an award's nominations or rings its nominees must not then vote in it
 * with points the platform paid them: PointsService::redeemForVote() refuses that, for ANY
 * points, while they hold a role there (helpsRun()).
 */
final class Rewards
{
    public const REF = 'vol_hours';
    public const REF_BACK = 'vol_hours_back';

    /** Points an hour earns, from the operator's setting. */
    public static function perHour(): int
    {
        return Rules::get('vol_points_per_hour');
    }

    /**
     * Credit every verified hour not yet credited, and take back any that is no longer verified.
     * Returns the points credited by this call. Quiet when points are switched off: they are
     * credited when they are switched on, from the same rows.
     */
    public static function sync(object $v): int
    {
        if (!PointsService::enabled() || self::perHour() < 1 || (int) $v->user_id < 1) return 0;
        $uid = (int) $v->user_id;
        $credited = [];
        foreach (DB::table('gates_points_ledger')->where('user_id', $uid)->whereIn('ref_type', [self::REF, self::REF_BACK])->get(['ref_type', 'ref_id', 'delta']) as $l) {
            $credited[(string) $l->ref_id][(string) $l->ref_type] = (int) $l->delta;
        }
        $added = 0;
        $rows = DB::table('gates_vol_hours')->where('volunteer_id', $v->id)->where('hours', '>', 0)->get(['id', 'hours', 'status', 'what']);
        foreach ($rows as $h) {
            $k = (string) $h->id;
            $got = $credited[$k][self::REF] ?? null;
            if ((string) $h->status === 'verified' && $got === null) {
                $pts = (int) floor((float) $h->hours * self::perHour());
                if ($pts > 0 && self::once($uid, $pts, self::REF, $k, 'Volunteering: ' . mb_substr((string) $h->what, 0, 120))) $added += $pts;
            } elseif ((string) $h->status !== 'verified' && $got !== null && !isset($credited[$k][self::REF_BACK])) {
                self::once($uid, -$got, self::REF_BACK, $k, 'Hours no longer verified');
            }
        }
        return $added;
    }

    /** What volunteering has earned, net of anything taken back. */
    public static function earned(object $v): int
    {
        return (int) DB::table('gates_points_ledger')->where('user_id', (int) $v->user_id)->whereIn('ref_type', [self::REF, self::REF_BACK])->sum('delta');
    }

    /** Does this member hold a live volunteer role on the award this nominee stands in? */
    public static function helpsRun(int $userId, int $nomineeId): bool
    {
        $cycle = (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
        if ($cycle < 1) return false;
        return DB::table('gates_grants')->where('actor_kind', 'member')->where('actor_id', $userId)->where('capability', 'like', 'vol.%')
            ->where('scope_kind', 'cycle')->where('scope_id', $cycle)->whereNull('revoked_at')->where('expires_at', '>', date('Y-m-d H:i:s'))->exists();
    }

    /**
     * One ledger line for one hours row, inside a lock on the member, checked again under the
     * lock: two page views sweeping at once must not both credit the same hour.
     */
    private static function once(int $uid, int $delta, string $ref, string $id, string $note): bool
    {
        try {
            return (bool) DB::transaction(static function () use ($uid, $delta, $ref, $id, $note): bool {
                DB::table('gates_users')->where('id', $uid)->lockForUpdate()->first();
                if (DB::table('gates_points_ledger')->where('user_id', $uid)->where('ref_type', $ref)->where('ref_id', $id)->exists()) return false;
                return PointsService::award($uid, $delta, $ref === self::REF ? 'volunteer.hours' : 'volunteer.hours.back', $ref, $id, $note) !== null;
            });
        } catch (\Throwable) {
            return false;
        }
    }
}
