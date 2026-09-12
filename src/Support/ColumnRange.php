<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Clamping a value to the width of the MySQL column it is going into.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Production is MySQL; dev and the test harness are SQLite. **SQLite ignores integer
 * widths entirely** — `INTEGER` is `INTEGER`, and a column declared `SMALLINT UNSIGNED`
 * will happily store 100000. MySQL in strict mode (the default since 5.7) rejects the
 * whole statement with "Out of range value for column".
 *
 * So a form field that is lower-bounded but not upper-bounded saves cleanly in dev, on
 * every test, in every review — and fails in production only, for whoever typed the big
 * number, with an error that names a column rather than a field. `CLAUDE.md` records that
 * this has already bitten a `sort_order` once.
 *
 * ── WHY IT CLAMPS TO THE COLUMN AND NOT TO A PRODUCT LIMIT ───────────────────
 *
 * "Nobody needs a discount code usable 65,535 times by one address" is true and is not
 * this file's business. A product limit is a second opinion about what the schema already
 * says, and the two drift: somebody widens the column and the invented ceiling silently
 * stays. Clamp to the column, and if a real product limit is wanted, apply it separately
 * and visibly where the rule lives.
 *
 * ── AND WHY CLAMPING RATHER THAN REFUSING ────────────────────────────────────
 *
 * These are ceilings on things like "how many times one address may use this code". A
 * value past the ceiling is not a different intention from the ceiling — somebody typing
 * 999999 means "effectively unlimited", and storing the maximum gives them that. A
 * refusal would be correct for a value whose magnitude carries meaning (a price, a
 * quantity ordered); use {@see fits()} there and reject with a message instead.
 */
final class ColumnRange
{
    /** Signed MySQL integer maxima. */
    public const TINYINT   = 127;
    public const SMALLINT  = 32767;
    public const MEDIUMINT = 8388607;
    public const INT       = 2147483647;

    /** Unsigned MySQL integer maxima — the ones this codebase actually declares. */
    public const TINYINT_UNSIGNED   = 255;
    public const SMALLINT_UNSIGNED  = 65535;
    public const MEDIUMINT_UNSIGNED = 16777215;
    public const INT_UNSIGNED       = 4294967295;

    /**
     * $value, held between $min and the column's ceiling.
     *
     * @param int $max one of the constants above — write the constant, not the number,
     *                 so the call site says which column type it is protecting.
     */
    public static function clamp(int $value, int $max, int $min = 0): int
    {
        return max($min, min($max, $value));
    }

    /** Whether a value would survive the write, for a path that should refuse rather than clamp. */
    public static function fits(int $value, int $max, int $min = 0): bool
    {
        return $value >= $min && $value <= $max;
    }
}
