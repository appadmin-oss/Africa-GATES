<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * What a nomination's `status` may say, and which of those words mean "verified".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE COLUMN WAS WIDENED, NOT REPLACED, AND THAT IS THE WHOLE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `handoff-new-pages/HANDOFF-NEW-PAGES.md` specifies
 * `status ENUM(draft,submitted,checking,verified,needs_details,rejected)`.
 *
 * The live column is `ENUM('pending','approved','rejected')` and **176 places in
 * `src/` read the literal `'approved'`**. Replacing the set would make every one of
 * them match zero rows — silently, on MySQL, with nothing thrown and nothing in a
 * log. That is the fault `CLAUDE.md` opens with, and this codebase has shipped it
 * three times: a schedule screen filtering on a word its column never allowed, a
 * funnel reading "Profile claimed — 0 — 0%" on every deployment since it shipped,
 * and a moderation warning that could not fire.
 *
 * So the ENUM is the UNION of both lists, and this class is the one place that knows
 * the two vocabularies overlap. **`approved` is the historic spelling of a moderator
 * pass**; `verified` is the challenge pipeline's word for the same decision plus the
 * nominee's own confirmation. Nothing is rewritten in the table — a migration that
 * renamed the old rows would be a migration that changed history.
 *
 * ── WHAT COUNTS FOR A CHALLENGE IS NARROWER THAN WHAT COUNTS FOR AN AWARD ───
 *
 * §3 of the prompt: a nomination counts "when the nomination reaches `verified`" —
 * which it defines as a moderator approving it AND the nominee confirming. A
 * moderator pass alone is enough for the award and is NOT enough for a prize, so
 * {@see countsForChallenge()} asks for both and nothing else may decide it.
 */
final class NominationStatus
{
    // ── The live vocabulary, unchanged ──────────────────────────────────────
    /** Submitted, nobody has looked yet. */
    public const PENDING  = 'pending';
    /** A moderator passed it. The award's own word, and 176 readers use it. */
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    // ── The challenge pipeline's additions ──────────────────────────────────
    /** Started and not sent. Nothing writes this yet; the admin preview may. */
    public const DRAFT         = 'draft';
    /** Sent, awaiting the nominee's confirmation. */
    public const SUBMITTED     = 'submitted';
    /** The nominee confirmed; a moderator is looking. */
    public const CHECKING      = 'checking';
    /** Confirmed by the nominee AND passed by a moderator. Counts for a prize. */
    public const VERIFIED      = 'verified';
    /** Something is missing and the nominator can still fix it. */
    public const NEEDS_DETAILS = 'needs_details';

    /**
     * Every value the column may hold.
     *
     * The order is the live three first, because that is the order the column was
     * written in and a migration that reorders an ENUM rewrites every row's stored
     * index on MySQL.
     *
     * @var list<string>
     */
    public const ALL = [
        self::PENDING, self::APPROVED, self::REJECTED,
        self::DRAFT, self::SUBMITTED, self::CHECKING, self::VERIFIED, self::NEEDS_DETAILS,
    ];

    /**
     * Does this nomination count towards a challenge target?
     *
     * Both halves, always: the nominee confirmed they are real and agreed to be
     * nominated, and a person checked the details. Either alone is somebody else's
     * decision standing in for a prize.
     *
     * `approved` is accepted beside `verified` because they are the same moderator
     * decision under two spellings — see the class docblock. The confirmation stamp
     * is what separates an award pass from a prize-counting one, so it is required
     * whichever word the row carries.
     */
    public static function countsForChallenge(?string $status, mixed $confirmedAt): bool
    {
        $passed = in_array((string) $status, [self::VERIFIED, self::APPROVED], true);
        $confirmed = $confirmedAt !== null && trim((string) $confirmedAt) !== '';

        return $passed && $confirmed;
    }

    /**
     * The words a nominator is shown, keyed by the stored value.
     *
     * Here rather than in a template, because the Account activity list, the
     * challenge progress card and the admin queue all print them and three copies is
     * three chances for one screen to invent a fourth word.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            self::PENDING       => 'Waiting for review',
            self::APPROVED      => 'Verified',
            self::REJECTED      => 'Not accepted',
            self::DRAFT         => 'Not sent yet',
            self::SUBMITTED     => 'Waiting for them to confirm',
            self::CHECKING      => 'We are checking',
            self::VERIFIED      => 'Verified',
            self::NEEDS_DETAILS => 'Needs details',
        ];
    }

    /** The one label for a stored value, never a bare echo of the column. */
    public static function label(?string $status): string
    {
        return self::labels()[(string) $status] ?? 'Waiting for review';
    }
}
