<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Is the schedule still running?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS HAS TO EXIST SEPARATELY FROM MAINTENANCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every automatic money decision on this platform is made by the maintenance run:
 * reconciliation confirms payments the callback dropped, and the refund sweep
 * returns money for votes that could not be minted. Neither happens on a web
 * request. If the schedule stops, both stop — and nothing about the site looks
 * wrong. Pages serve, votes are cast, checkouts complete. The only symptom is
 * supporters who are owed money not being paid, discovered when they complain.
 *
 * That is precisely the failure mode this codebase keeps finding: a thing that is
 * off rather than broken, so no error is ever raised.
 *
 * ── AND WHY THE CHECK CANNOT LIVE INSIDE THE RUN ─────────────────────────────
 *
 * A stalled scheduler cannot notice its own stall. Maintenance alerting "I have
 * not run" is a contradiction — if it can alert, it ran. So the check must happen
 * somewhere traffic still arrives, which is an admin page load. Every admin screen
 * asks this question on render; it is one indexed row.
 *
 * ── WHY THE THRESHOLD IS SIX HOURS AND NOT THIRTY MINUTES ────────────────────
 *
 * The schedule is meant to tick every ~15 minutes, so a 30-minute threshold sounds
 * right and is wrong. Free webcron tiers are irregular, opportunistic traffic-driven
 * runs only fire when somebody visits, and a quiet night on a Nigerian audience is
 * a real gap. A banner that cries wolf at 03:00 every night is a banner that gets
 * ignored by the second week, and then it is worth nothing on the night it matters.
 *
 * Six hours is chosen against what is actually at stake: the refund retry ladder is
 * 1h → 6h → 24h and the payment in-flight window is two hours, so a six-hour gap is
 * the point at which real decisions have provably been missed rather than merely
 * delayed. It is late enough to be true and early enough to act on.
 */
final class CronHealth
{
    /** Beyond this, work that should have happened provably has not. */
    public const STALE_HOURS = 6;

    /** How often the same stall may be emailed about. Once a day, not once a tick. */
    private const ALERT_EVERY_HOURS = 24;

    /**
     * When maintenance last completed, or null if it never has.
     *
     * Reads `gates_cron_log` rather than a settings key so it agrees exactly with
     * what the Settings screen already shows. Two sources for one fact is how they
     * end up disagreeing, and an operator comparing a green banner with a stale
     * timestamp has no way to tell which is lying.
     */
    public static function lastRunAt(): ?Carbon
    {
        try {
            $at = DB::table('gates_cron_log')
                ->where('job_name', 'maintenance')
                ->orderByDesc('id')
                ->value('ran_at');
            return $at === null ? null : Carbon::parse((string) $at);
        } catch (\Throwable) {
            // An unreadable log is not evidence of a stall. Saying "the schedule is
            // dead" because a query failed would send somebody to reconfigure a
            // webcron that was working perfectly.
            return null;
        }
    }

    /** Hours since the last completed run, or null when that cannot be established. */
    public static function hoursSinceLastRun(): ?float
    {
        $last = self::lastRunAt();
        if ($last === null) return null;

        $hours = $last->diffInMinutes(Carbon::now(), false) / 60;
        // A clock skew or a future-dated row reads as "just ran", never as a stall.
        return $hours < 0 ? 0.0 : $hours;
    }

    /**
     * Has the schedule provably missed work?
     *
     * False when it has NEVER run, deliberately. A fresh install with no rows is
     * not a stalled schedule, and reporting one on day zero teaches an operator to
     * dismiss this banner before it has ever been right. {@see neverRun()} is the
     * separate question, and it has a different answer: finish setting it up.
     */
    public static function isStale(): bool
    {
        $h = self::hoursSinceLastRun();
        return $h !== null && $h >= self::STALE_HOURS;
    }

    /** True when maintenance has no recorded run at all. */
    public static function neverRun(): bool
    {
        return self::lastRunAt() === null;
    }

    /**
     * When maintenance last completed with EVERY task succeeding.
     *
     * ── WHY THIS IS A SECOND QUESTION ────────────────────────────────────────
     *
     * `lastRunAt()` takes the newest row whatever its status, and `gates_cron_log.status`
     * is 'error' when any task inside the run failed — a distinction Maintenance already
     * makes deliberately, and which this class was throwing away.
     *
     * So a schedule that ran every fifteen minutes and failed the queue drain every single
     * time reported "Scheduled work · ran 12 minutes ago", in green, for as long as it kept
     * failing. Meanwhile the queue's own check went degraded because the head of it was
     * hours old, and the one component on the page whose entire purpose is to explain that
     * said nothing. The operator is left with two symptoms and no cause.
     *
     * Both readings are kept, because they answer different questions and an operator needs
     * both: "is the schedule alive" and "is it getting anything done".
     */
    public static function lastCleanRunAt(): ?Carbon
    {
        try {
            $at = DB::table('gates_cron_log')
                ->where('job_name', 'maintenance')
                ->where('status', 'success')
                ->orderByDesc('id')
                ->value('ran_at');
            return $at === null ? null : Carbon::parse((string) $at);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The schedule is running but its work is not landing.
     *
     * True when there IS a recent run and there is NOT a recent clean one. Deliberately not
     * "the last run errored": one failed task in one tick is a blip, and a page that cries
     * about a blip gets ignored on the day it matters. This only speaks when nothing has
     * completed cleanly for as long as a total stall would have taken to notice.
     */
    public static function isFailing(): bool
    {
        if (self::neverRun() || self::isStale()) return false;   // a different fault, reported as itself

        // HOW LONG has nothing worked — not "did the last tick fail".
        //
        // Measured from the last clean run, or from the first run ever when there has never
        // been one. That second half is what keeps a fresh install honest: a schedule whose
        // first tick five minutes ago failed one task is a broken TASK, and pointing its
        // operator at their webcron configuration would send them to fix the wrong thing.
        // It has to have had a full window to succeed in before its silence means anything.
        $since = self::lastCleanRunAt() ?? self::firstRunAt();

        return $since !== null
            && ($since->diffInMinutes(Carbon::now(), false) / 60) >= self::STALE_HOURS;
    }

    /** The earliest recorded run — the clock for "it has never once got through". */
    private static function firstRunAt(): ?Carbon
    {
        try {
            $at = DB::table('gates_cron_log')
                ->where('job_name', 'maintenance')
                ->orderBy('id')
                ->value('ran_at');
            return $at === null ? null : Carbon::parse((string) $at);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Everything an admin screen needs to say something true, in one row read.
     *
     * `say` is written for the person reading it and names the consequence rather
     * than the mechanism. "Cron stale" tells an operator nothing they can act on;
     * "refunds and payment reconciliation are not running" tells them why they
     * should care, and the two things at stake are exactly the two that involve
     * somebody else's money.
     *
     * @return array{ok:bool, never:bool, stale:bool, hours:?float, last:?string, say:?string}
     */
    public static function status(): array
    {
        $never = self::neverRun();
        $hours = self::hoursSinceLastRun();
        $stale = self::isStale();
        $last  = self::lastRunAt();

        $failing = self::isFailing();

        $say = null;
        if ($never) {
            $say = 'Scheduled maintenance has never run, so payment reconciliation and automatic '
                 . 'refunds are not happening. Set it up under Settings → Automation & cron.';
        } elseif ($stale) {
            $say = 'Scheduled maintenance last ran ' . self::humanGap($hours) . ' ago. Payments are '
                 . 'not being reconciled and refunds are not being sent. Check the webcron job, or '
                 . 'press "Run maintenance now" under Settings → Automation & cron.';
        } elseif ($failing) {
            $say = 'Scheduled maintenance is running but has not completed cleanly for at least '
                 . self::STALE_HOURS . ' hours — tasks inside it are failing, so queued email, '
                 . 'refunds and reconciliation are not finishing even though the schedule looks '
                 . 'alive. The reasons are on each run under Settings → Automation & cron.';
        }

        return [
            'ok'      => !$never && !$stale && !$failing,
            'never'   => $never,
            'stale'   => $stale,
            // Running, but nothing has got through. The state that used to read as healthy.
            'failing' => $failing,
            'hours'   => $hours,
            'last'    => $last?->toDateTimeString(),
            'clean'   => self::lastCleanRunAt()?->toDateTimeString(),
            'say'     => $say,
        ];
    }

    /** "7 hours" / "2 days" — rounded, because a stall is not a stopwatch. */
    public static function humanGap(?float $hours): string
    {
        if ($hours === null) return 'an unknown time';
        if ($hours < 1)  return 'less than an hour';
        if ($hours < 48) return ((int) round($hours)) . ' hour' . ((int) round($hours) === 1 ? '' : 's');
        return ((int) round($hours / 24)) . ' days';
    }

    /**
     * Email a person about a stall — at most once a day.
     *
     * Claim-before-send against a settings row, the same shape as the refund
     * sweep's daily ceiling alert and for the same reason: this is checked on every
     * admin page load, so an unclaimed alert would send one email per click. A
     * hundred copies of one warning is how a real alert becomes a mail filter.
     *
     * Returns true only when THIS call is the one that should send, so the caller
     * does the sending and this stays free of a mailer dependency.
     */
    public static function claimAlert(): bool
    {
        if (!self::isStale()) return false;

        $key = 'cron_stall_alerted_at';
        try {
            $last = DB::table('gates_settings')->where('key_name', $key)->value('value');
            if ($last !== null && trim((string) $last) !== '') {
                if (Carbon::parse((string) $last)->gt(Carbon::now()->subHours(self::ALERT_EVERY_HOURS))) {
                    return false;
                }
            }

            $now = Carbon::now()->toDateTimeString();
            // Conditional first, so two simultaneous page loads cannot both claim.
            $updated = DB::table('gates_settings')->where('key_name', $key)->update(['value' => $now]);
            if ($updated === 0) {
                DB::table('gates_settings')->insertOrIgnore(['key_name' => $key, 'value' => $now]);
            }
            return true;
        } catch (\Throwable) {
            // Never alert on an error. An unwritable settings table would otherwise
            // mean an email per page load, with nothing recording that it was sent.
            return false;
        }
    }
}
