<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * E.164 phone normalisation for messaging providers.
 *
 * Twilio (SMS + WhatsApp) and the Meta WhatsApp Business Cloud API all take
 * E.164 (`+` followed by up to 15 digits). Everything user-typed funnels
 * through normalize() at the validation boundary; the normalised value is
 * what gets stored and dialled. Returns null (never a guess) when a number
 * can't be resolved confidently — callers turn that into a validation error.
 */
final class Phone
{
    /** ITU dial codes for all African states (ISO-3166 alpha-2 → code). */
    private const DIAL = [
        'DZ' => '213', 'AO' => '244', 'BJ' => '229', 'BW' => '267', 'BF' => '226',
        'BI' => '257', 'CV' => '238', 'CM' => '237', 'CF' => '236', 'TD' => '235',
        'KM' => '269', 'CG' => '242', 'CD' => '243', 'CI' => '225', 'DJ' => '253',
        'EG' => '20',  'GQ' => '240', 'ER' => '291', 'SZ' => '268', 'ET' => '251',
        'GA' => '241', 'GM' => '220', 'GH' => '233', 'GN' => '224', 'GW' => '245',
        'KE' => '254', 'LS' => '266', 'LR' => '231', 'LY' => '218', 'MG' => '261',
        'MW' => '265', 'ML' => '223', 'MR' => '222', 'MU' => '230', 'MA' => '212',
        'MZ' => '258', 'NA' => '264', 'NE' => '227', 'NG' => '234', 'RW' => '250',
        'ST' => '239', 'SN' => '221', 'SC' => '248', 'SL' => '232', 'SO' => '252',
        'ZA' => '27',  'SS' => '211', 'SD' => '249', 'TZ' => '255', 'TG' => '228',
        'TN' => '216', 'UG' => '256', 'ZM' => '260', 'ZW' => '263',
        // Diaspora entries commonly typed on the platform's forms.
        'US' => '1', 'CA' => '1', 'GB' => '44', 'FR' => '33', 'DE' => '49',
        'AE' => '971', 'SA' => '966', 'CN' => '86', 'IN' => '91', 'BR' => '55',
    ];

    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15; // E.164 ceiling

    /**
     * Normalise a user-typed phone into E.164 (+<digits>) or null.
     *
     * @param string|null $country ISO-3166 alpha-2 used to resolve national
     *                             formats (leading 0 / bare subscriber digits).
     */
    public static function normalize(?string $raw, ?string $country = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // Reject anything with letters — catches "call me on WhatsApp" etc.
        if (preg_match('/[a-z]/i', $raw)) return null;

        // Keep a leading +, drop every other non-digit (spaces, dashes, dots, parens).
        $plus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // 00-prefixed international is equivalent to +.
        if (!$plus && str_starts_with($digits, '00')) {
            $plus = true;
            $digits = substr($digits, 2);
        }

        if ($plus) {
            return self::validLength($digits) ? '+' . $digits : null;
        }

        $dial = self::DIAL[strtoupper(trim((string) $country))] ?? null;

        // Trunk-0 national format needs a known country to resolve.
        if (str_starts_with($digits, '0')) {
            if ($dial === null) return null;
            $subscriber = ltrim($digits, '0');
            if (strlen($subscriber) < 7) return null; // no national plan is shorter
            $candidate = $dial . $subscriber;
            return self::validLength($candidate) ? '+' . $candidate : null;
        }

        if ($dial !== null) {
            // Already typed with the country's dial code, just missing the +.
            if (str_starts_with($digits, $dial) && strlen($digits) >= strlen($dial) + 7 && self::validLength($digits)) {
                return '+' . $digits;
            }
            // Bare subscriber number — prepend the dial code. Subscriber part
            // must be at least 7 digits (no national plan is shorter).
            if (strlen($digits) < 7) return null;
            $candidate = $dial . $digits;
            return self::validLength($candidate) ? '+' . $candidate : null;
        }

        // No country context: only accept if it plausibly already carries a
        // dial code (long enough to be fully-qualified).
        return (strlen($digits) >= 11 && self::validLength($digits)) ? '+' . $digits : null;
    }

    /** True when the value normalises to E.164 as-is. */
    public static function isValid(?string $raw, ?string $country = null): bool
    {
        return self::normalize($raw, $country) !== null;
    }

    /** Privacy mask for logs/UI: dial-code prefix + tail only (≤24 chars). */
    public static function mask(string $e164): string
    {
        $digits = ltrim($e164, '+');
        if (strlen($digits) <= 7) return '+' . str_repeat('*', strlen($digits));
        return '+' . substr($digits, 0, 3) . str_repeat('*', min(8, strlen($digits) - 6)) . substr($digits, -3);
    }

    private static function validLength(string $digits): bool
    {
        $len = strlen($digits);
        return $len >= self::MIN_DIGITS && $len <= self::MAX_DIGITS && ctype_digit($digits);
    }
}
