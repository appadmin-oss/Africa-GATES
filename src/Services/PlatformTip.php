<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A DONOR'S VOLUNTARY GIFT TO AFRICA GATES, ON TOP OF WHAT THEY GAVE SOMEBODY ELSE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS, AND WHY IT IS THE ONLY HONEST VERSION OF IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Africa GATES is a project of a Nigerian non-profit and it has to fund itself. Two
 * mechanisms already did that on a partner appeal: `platform_fee_bps`, the agreed cut that
 * comes OUT of the gift, and the shop, tickets and stands. What no surface offered was the
 * obvious one — a donor giving to an organisation through our rails who would also have
 * given something to the rails, and had no way to.
 *
 * The rule that makes it defensible is one line: **the tip is ADDED, never taken.** The
 * organisation receives exactly the number the donor typed for them whether the tip is zero
 * or the largest we allow. That is why it rides to the gateway as a flat
 * `transaction_charge` rather than through the subaccount's percentage
 * ({@see PaymentDestination::initFieldsForPartner()}); a percentage would fund us by giving
 * the charity less, which is not what the form says.
 *
 * ── AND WHY IT IS OFF BY DEFAULT ─────────────────────────────────────────────
 *
 * A pre-ticked box is a dark pattern in every jurisdiction that has looked at one, and
 * under the NDPA 2023 and the GDPR line on consent a default that the user did not choose
 * is not a choice. It is also bad for the thing it is meant to fund: a donor who discovers
 * at the bank that they gave us something they did not intend disputes the whole payment,
 * and a partner loses their gift along with our tip. `DEFAULT_PCT = 0`.
 *
 * ── WHAT IS CAPPED, AND WHY THE CAP IS LOW ───────────────────────────────────
 *
 * A tip is capped at {@see MAX_PCT} of the gift and at {@see MAX_NAIRA} outright. Not to
 * protect us from generosity — to make one class of accident impossible: a mistyped tip
 * that exceeds the gift turns a donation to a charity into a donation to their platform,
 * and the first anybody hears of it is a complaint. The gateway would also refuse a
 * `transaction_charge` above the transaction, so an uncapped value is a failed checkout
 * rather than a windfall.
 *
 * ── AND IT IS NOT OFFERED ON A GIFT TO US ────────────────────────────────────
 *
 * "Would you like to add a gift to Africa GATES?" on the Africa GATES page is a second
 * amount field for the same thing, which reads as a trick. {@see offeredFor()}.
 */
final class PlatformTip
{
    /** Settings key — the percentages the form offers, comma-separated. */
    public const KEY_OPTIONS = 'platform_tip_options';

    /** Settings key — whether to offer it at all. */
    public const KEY_ENABLED = 'platform_tip_enabled';

    /**
     * Pre-ticked is not consent. See the class docblock: this is a legal position and a
     * commercial one at the same time.
     */
    public const DEFAULT_PCT = 0;

    /** The percentages a donor can pick from, when nothing is configured. */
    public const OPTIONS = [0, 5, 10, 15];

    /**
     * Ceilings. A tip is a thank-you, not a second donation — and the gateway refuses a
     * flat charge above the transaction, so an uncapped value is a failed checkout.
     */
    public const MAX_PCT   = 50;
    public const MAX_NAIRA = 500_000;

    /** Is the platform tip offered at all? Settings first, `.env` behind it. */
    public static function enabled(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', self::KEY_ENABLED)->value('value');
            if (is_string($v) && trim($v) !== '') return Env::truthy($v, true);
        } catch (\Throwable) {
            // No settings table yet. Offering it is the behaviour the feature was built
            // for, and it is opt-in per donor regardless.
        }

        return Env::bool('PLATFORM_TIP_ENABLED', true);
    }

    /**
     * The percentages to offer, always including 0 and always ascending.
     *
     * Zero is in the list on purpose and is not the same as hiding the control: a donor who
     * is asked and says no has declined, and a donor who was never asked has not. The first
     * is a defensible record of consent; the second is what a regulator calls an inferred
     * one.
     *
     * @return list<int>
     */
    public static function options(): array
    {
        $raw = '';
        try {
            $v = DB::table('gates_settings')->where('key_name', self::KEY_OPTIONS)->value('value');
            $raw = is_string($v) ? trim($v) : '';
        } catch (\Throwable) {
        }

        $out = [];
        foreach (explode(',', $raw) as $bit) {
            // BLANK IS NOT ZERO. `explode(',', '')` is `['']`, and `(int) ''` is 0 — which
            // passes the range check, leaves `$out` non-empty, and so silently replaces the
            // whole ladder with "No thanks" the moment nothing is configured. The control
            // still renders, the donor can still answer, and every answer is nought: a
            // revenue line that is present, correct-looking and worth exactly zero.
            $bit = trim($bit);
            if ($bit === '' || !ctype_digit($bit)) continue;

            $n = (int) $bit;
            if ($n >= 0 && $n <= self::MAX_PCT) $out[] = $n;
        }
        if ($out === []) $out = self::OPTIONS;

        $out[] = 0;
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * Is a tip offered for this recipient?
     *
     * Only on somebody ELSE's appeal. On the Africa GATES page the donor is already giving
     * to Africa GATES, and a second field asking whether they would also like to reads as a
     * trick rather than an offer.
     */
    public static function offeredFor(?object $org): bool
    {
        return $org !== null && self::enabled();
    }

    /**
     * The tip in naira for a gift, from whatever the form posted.
     *
     * ── COMPUTED SERVER-SIDE FROM THE PERCENTAGE, NEVER TAKEN AS A TOTAL ─────
     *
     * The form posts a percentage, this turns it into naira, and the caller adds it. A
     * client-sent naira figure would be a number the donor's browser chose for a payment
     * the donor's bank will be shown — the same rule the fee cover already follows.
     *
     * An unrecognised percentage is treated as none rather than clamped to the nearest
     * offered one: a value we did not offer is a form we did not render, and charging
     * somebody the closest thing to what their browser sent is not a defensible consent.
     */
    public static function naira(int $giftNaira, mixed $postedPct, ?object $org): int
    {
        if ($giftNaira <= 0) return 0;

        // `null` is the Africa GATES page, where a tip is not offered at all — so this is
        // `offeredFor()` outright rather than a guard that only fires for a partner. The
        // earlier form let a null recipient through and charged a tip on a gift to us,
        // which is the second amount field the whole feature is meant not to be.
        if (!self::offeredFor($org)) return 0;

        // DIGITS OUTRIGHT, not "digits after the others are stripped". Stripping turns
        // `-5` into `5` and `1e3` into `13` — both of which are then charged, and the
        // second is not even a number anybody could have clicked. What a donor consented
        // to is a value we rendered; anything else is declined rather than repaired.
        $raw = trim((string) $postedPct);
        if ($raw === '' || !ctype_digit($raw)) return 0;

        $pct = (int) $raw;
        if ($pct <= 0) return 0;
        if (!in_array($pct, self::options(), true)) return 0;

        $tip = (int) floor($giftNaira * $pct / 100);

        return max(0, min($tip, self::MAX_NAIRA, (int) floor($giftNaira * self::MAX_PCT / 100)));
    }
}
