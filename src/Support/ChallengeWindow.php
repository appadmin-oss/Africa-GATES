<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Support\ChallengeEnum as E;

/**
 * WHERE A CHALLENGE IS IN ITS LIFE, RIGHT NOW — ONE ANSWER.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_challenges.status` had an `ended` value, three readers filtering on it, and NO
 * WRITER. A grep for `ST_ENDED` across `src/` returned the enum, one comparison in
 * `ChallengeCopy` and one in `PromoService` — nothing anywhere set it. So a challenge
 * whose `ends_at` had passed stayed `open` for ever:
 *
 *   · the page went on saying "Open" and counting days left into the negative;
 *   · the promo banner stayed up on five placements, inviting people into a closed race
 *     — which is the exact failure `PromoService`'s own docblock says it exists to stop;
 *   · and `join()` went on ACCEPTING ENTRIES, because it checked the same column. People
 *     could enter a competition that had finished, and nothing would tell them until a
 *     prize they were never eligible for was not paid.
 *
 * `ChallengeCopy` was one line away from catching it: it already derives `full` at read
 * time, under a comment saying "a challenge whose last prize was claimed a second ago is
 * full before any sweep has written the word, and the page must not go on inviting people
 * into a race that is over". That reasoning is about the clock as much as the cap, and it
 * had been applied to one of them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * DERIVED AT READ TIME, AND WRITTEN BACK AS A CACHE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see status()} is the truth and needs no sweep: every page is right the moment the
 * clock passes `ends_at`. The column is then a cache the hourly sweep brings into line,
 * because three queries FILTER on it — the public index, the promo lookup and the admin
 * queue — and a filter cannot call a function per row.
 *
 * That is the same split `gates_award_cycles.next_boundary_at` already uses, and the same
 * reason: a computed phase cannot be indexed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DECIDED STATES WIN OVER THE CLOCK, AND THE ORDER IS THE WHOLE RULE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `draft` and `cancelled` are decisions a person made and no date may overturn one: a
 * cancelled challenge whose end date has passed is still cancelled, not ended, and the
 * page must say which. `full` is likewise a fact about the prizes rather than the clock —
 * but it loses to the clock, because a challenge that filled and then ran out of time is
 * over, and "Full" on a finished challenge reads as an invitation to wait for a place.
 */
final class ChallengeWindow
{
    /**
     * @param array<string,mixed>|object $c       a `gates_challenges` row
     * @param string|null                $now     UTC 'Y-m-d H:i:s'; null means now
     * @param int|null                   $claimed places already taken, when known
     */
    public static function status(array|object $c, ?string $now = null, ?int $claimed = null): string
    {
        $row = is_object($c) ? (array) $c : $c;

        $stored = (string) ($row['status'] ?? E::ST_OPEN);

        // A person's decision. Never overturned by a clock.
        if ($stored === E::ST_DRAFT || $stored === E::ST_CANCELLED) {
            return $stored;
        }

        // ── THE CLOCK ONLY EVER MOVES A CHALLENGE FORWARD ───────────────────
        //
        // upcoming → open → ended, and never back. `ended` is therefore terminal: a
        // challenge recorded as over does not reopen because somebody cleared its end
        // date, and a prize that has been awarded cannot be competed for again.
        //
        // This was found by a test rather than reasoned out. The first version of this
        // method asked the dates and fell back to `open`, so a row stored as `ended`
        // with no `ends_at` — which is every challenge an admin ended by hand — came
        // back OPEN and started taking entries again. `ChallengeServiceTest` named it
        // immediately; it is recorded here because the fallback looked obviously right.
        if ($stored === E::ST_ENDED) {
            return E::ST_ENDED;
        }

        $at = $now ?? gmdate('Y-m-d H:i:s');

        $ends = self::stamp($row['ends_at'] ?? null);
        if ($ends !== null && $at > $ends) {
            return E::ST_ENDED;
        }

        // Not started. Either the date says so, or the column does and there is no date
        // to contradict it — a challenge an admin marked `upcoming` without setting a
        // start is waiting for them, not open.
        $starts = self::stamp($row['starts_at'] ?? null);
        if ($starts !== null) {
            if ($at < $starts) return E::ST_UPCOMING;
        } elseif ($stored === E::ST_UPCOMING) {
            return E::ST_UPCOMING;
        }

        // Full, by the stored word or by the count. `claimed` is passed when the caller
        // already has it; asking for it here would make a list page one query per row.
        $cap  = (int) ($row['cap'] ?? 0);
        $mode = (string) ($row['mode'] ?? E::MODE_FIRST);

        if ($stored === E::ST_FULL
            || ($claimed !== null && $mode === E::MODE_FIRST && $cap > 0 && $claimed >= $cap)) {
            return E::ST_FULL;
        }

        return E::ST_OPEN;
    }

    /** May somebody enter right now? */
    public static function open(array|object $c, ?string $now = null, ?int $claimed = null): bool
    {
        return self::status($c, $now, $claimed) === E::ST_OPEN;
    }

    /**
     * Normalise whatever the row holds into something comparable as a string.
     *
     * SQLite stores a datetime verbatim and MySQL normalises it, so the same row can come
     * back as `2026-10-15 22:59:59` on one driver and with a `T`, a zone or milliseconds
     * on the other if it was ever written loosely. A string comparison between those two
     * shapes is wrong in whichever direction the characters happen to sort — which is the
     * `next_charge_at` fault at the top of CLAUDE.md, one table over.
     */
    private static function stamp(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        if ($s === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            // An unparseable date is not a date, and treating it as "ended" would close a
            // live challenge over a typo. It is simply not a boundary.
            return null;
        }
    }
}
