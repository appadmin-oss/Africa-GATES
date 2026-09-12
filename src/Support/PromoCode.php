<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Support\Carbon;

/**
 * What a discount code MEANS, in one place.
 *
 * ── WHY THIS IS SHARED RATHER THAN COPIED ────────────────────────────────────
 *
 * Event tickets and the shop both take codes, and the questions are word for word the same:
 * is it switched on, are we inside its window, has it been used as many times as it allows,
 * and what does it take off this line? Only the TARGET differs — a ticket tier on one side, a
 * product or a category on the other — and that is the part each caller keeps.
 *
 * Two copies of these rules would not stay identical. One would gain a fix the other did not,
 * and the failure would be invisible: a code that clamps correctly on tickets and goes
 * negative in the shop, or a window that is inclusive in one place and exclusive in the other.
 * That is the drift this codebase keeps finding — four implementations of "is it sold out",
 * three of "is this reference ours" — so the arithmetic and the limits live here.
 *
 * ── EVERY RULE HERE EXISTS BECAUSE ITS ABSENCE IS A KNOWN FAILURE ────────────
 *
 *   • The percentage is applied to the LINE total, never per unit. 15% off three ₦333 items
 *     rounded three times produces a total that is not three times anything.
 *   • A fixed amount larger than the line clamps to the line. A ₦10,000 code against a
 *     ₦4,000 order is a free order, not a refund.
 *   • A window is checked against the database's own clock string, not a timestamp
 *     comparison, because that is how every other date filter in this codebase reads and
 *     mixing the two is how an "expired" code works for one more day in one timezone.
 *
 * Stateless and static: the callers own their tables, their per-person counting and their
 * targeting. This owns only what a code is.
 */
final class PromoCode
{
    /** A code as typed by a human, folded to what is stored. */
    public static function normalise(string $raw): string
    {
        return strtoupper(trim($raw));
    }

    /**
     * Is this code usable at all, right now, ignoring who is asking?
     *
     * Returns the empty string when it is, and the sentence to show when it is not — so a
     * caller cannot accidentally treat "no reason" as "no good".
     *
     * @param object $row a code row carrying is_active, starts_at, ends_at, max_uses, used_count
     */
    public static function refusal(object $row, ?string $now = null): string
    {
        if ((int) ($row->is_active ?? 0) !== 1) {
            return 'That code is no longer active.';
        }

        $now ??= Carbon::now()->toDateTimeString();

        if (($row->starts_at ?? null) !== null && (string) $row->starts_at > $now) {
            return 'That code is not usable yet.';
        }
        if (($row->ends_at ?? null) !== null && (string) $row->ends_at < $now) {
            return 'That code has expired.';
        }
        if (($row->max_uses ?? null) !== null && (int) $row->used_count >= (int) $row->max_uses) {
            return 'That code has been used as many times as it allows.';
        }

        return '';
    }

    /**
     * What this code takes off a line total, in whole naira.
     *
     * `kind` is 'percent' or 'fixed'. Anything else is treated as a percentage, because that
     * is the safer misreading: a typo in a kind column should shave a fraction off, not
     * discount somebody's entire order by the number 20.
     */
    public static function amountOff(object $row, int $lineTotal): int
    {
        $amount = max(0, (int) ($row->amount ?? 0));
        if ($amount === 0 || $lineTotal <= 0) return 0;

        $off = (string) ($row->kind ?? 'percent') === 'fixed'
            ? $amount
            : (int) floor($lineTotal * min(100, $amount) / 100);

        return max(0, min($lineTotal, $off));
    }

    /**
     * Whether a code restricted to a list of ids applies to this one.
     *
     * NULL or an empty list means "everything". A list means exactly those — which is the
     * control that stops a student discount applying to the most expensive thing on sale.
     */
    public static function targets(mixed $rawJson, int $id): bool
    {
        $ids = is_string($rawJson) ? json_decode($rawJson, true) : $rawJson;
        if (!is_array($ids) || $ids === []) return true;
        return in_array($id, array_map('intval', $ids), true);
    }

    /** The sentence a buyer sees when a code worked. */
    public static function says(object $row, int $off): string
    {
        $label = trim((string) ($row->label ?? ''));
        return ($label !== '' ? $label . ': ' : '') . '₦' . number_format($off) . ' off.';
    }

    /** The sentence for a per-person limit, which reads differently at one than at many. */
    public static function perPersonRefusal(int $limit): string
    {
        return $limit === 1
            ? 'That code has already been used with this email address.'
            : 'That code has been used the maximum number of times with this email address.';
    }
}
