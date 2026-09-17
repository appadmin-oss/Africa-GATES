<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\CookiePrefs;
use AfricaGates\Services\CurrencyService;
use AfricaGates\Services\ShopPricing;

/**
 * Every cookie this platform sets, and every key it leaves in a browser's own storage.
 *
 * ── THE BUG THIS EXISTS BECAUSE OF ───────────────────────────────────────────
 *
 * The published cookie policy said, in bold, "We set ONE cookie", and listed `PHPSESSID`
 * in a one-row table. There were three. `ag_region` and `ag_currency` are written by
 * `document.cookie` in the shop's region and currency selects and live for a YEAR, and
 * they were added long after the policy was written. The policy's own docblock named the
 * evidence — "see `session_set_cookie_params()` in public/index.php" — and that is exactly
 * why it stayed wrong: the second writer is a line of JavaScript in a template, so
 * checking the named place confirmed the false answer.
 *
 * The failure mode is FORGETTING, so a corrected list is not the fix. The fix is that the
 * page is GENERATED from here ({@see \AfricaGates\Services\LegalDocument::cookiesHtml()}),
 * and that `CookieRegistryTest` sweeps the shipped templates and JavaScript for a cookie
 * or storage key that is not declared below and fails naming it. A cookie added tomorrow
 * either appears on the policy or breaks the build.
 *
 * ── DERIVED WHERE IT CAN BE ──────────────────────────────────────────────────
 *
 * The session cookie's name and lifetime are read from PHP's own session configuration
 * rather than typed, so changing `session_set_cookie_params()` changes the published
 * policy in the same commit. The two shop cookies are read from the constants their
 * services already expose. Nothing here restates a value that exists somewhere else.
 *
 * ── THE CATEGORIES ARE A LEGAL CLAIM, NOT A TIDY-UP ──────────────────────────
 *
 * `essential` is the ePrivacy Art.5(3) carve-out — strictly necessary for a service the
 * visitor asked for, so no consent is required and none is asked for. `preference` is a
 * setting the visitor themselves chose from a control on the page. `analytics` is the
 * arrival counting, which is the only thing on this site anybody could want to refuse,
 * and it is the only category {@see CookiePrefs} can switch off.
 *
 * A cookie in the wrong category here is a consent banner asking permission for the wrong
 * thing, so the category is asserted per entry in the test rather than left to a reader.
 */
final class CookieRegistry
{
    public const ESSENTIAL  = 'essential';
    public const PREFERENCE = 'preference';
    public const ANALYTICS  = 'analytics';

    /**
     * The cookies, in the order a reader should meet them.
     *
     * @return list<array{name:string,category:string,purpose:string,lifetime:string,
     *                    set_by:string,refusable:bool}>
     */
    public static function cookies(): array
    {
        return [
            [
                'name'     => self::sessionName(),
                'category' => self::ESSENTIAL,
                'purpose'  => 'Identifies your session, so you stay signed in, a form you are '
                            . 'halfway through is not lost, and we can tell a real submission '
                            . 'from a forged one. It holds a reference to data kept on our own '
                            . 'server; it does not contain your details.',
                'lifetime' => self::sessionLifetime(),
                'set_by'   => 'server',
                'refusable' => false,
            ],
            [
                'name'     => CookiePrefs::COOKIE,
                'category' => self::ESSENTIAL,
                'purpose'  => 'Remembers the choice you made about arrival counting, so we do '
                            . 'not have to ask again. It holds one character — a yes or a no — '
                            . 'and no identifier of any kind. Refusing this one is not possible, '
                            . 'because it is the record of a refusal.',
                'lifetime' => self::yearsWord(CookiePrefs::TTL_DAYS),
                'set_by'   => 'server',
                'refusable' => false,
            ],
            [
                'name'     => ShopPricing::COOKIE,
                'category' => self::PREFERENCE,
                'purpose'  => 'The pricing region you picked from the selector on the shop, so '
                            . 'the prices are the ones that apply to you on your next visit. '
                            . 'Written only when you use that selector.',
                'lifetime' => 'One year',
                'set_by'   => 'browser',
                'refusable' => false,
            ],
            [
                'name'     => CurrencyService::COOKIE,
                'category' => self::PREFERENCE,
                'purpose'  => 'The currency you asked prices to be shown in, from the same '
                            . 'selector. Written only when you use it.',
                'lifetime' => 'One year',
                'set_by'   => 'browser',
                'refusable' => false,
            ],
        ];
    }

    /**
     * Things kept in the browser's own storage, which are not cookies and never leave the device.
     *
     * Declared with the PREFIX that the code writes, because several are per-item
     * (`afg_voted_prog_12`, `ag-celebrated:nominee-88`) and a reader does not need the
     * numbers. `CookieRegistryTest` matches a swept key against these prefixes.
     *
     * @return list<array{key:string,purpose:string}>
     */
    public static function storage(): array
    {
        return [
            ['key' => 'afg_cart',        'purpose' => 'Your shopping basket, so it survives a reload.'],
            ['key' => 'afg_voted_prog_', 'purpose' => 'Which programmes you have already voted in, so the page can stop offering.'],
            ['key' => 'afg_cheer_',      'purpose' => 'A message you started writing for a nominee, so a mistaken tap does not lose it.'],
            ['key' => 'afg_report_',     'purpose' => 'A report you began, for the same reason.'],
            ['key' => 'ag_intro',        'purpose' => 'Whether you have seen the introduction, so it is not shown twice.'],
            ['key' => 'ag-celebrated:',  'purpose' => 'Which results you have already seen celebrated, so the animation plays once and not on every visit.'],
            ['key' => 'coi_declared_',   'purpose' => 'For judges only: that you have made this programme\'s conflict-of-interest declaration on this device.'],
        ];
    }

    /** Every cookie name this platform can set. */
    public static function names(): array
    {
        return array_map(static fn (array $c): string => $c['name'], self::cookies());
    }

    /** How many cookies there are, for prose that would otherwise say "one" for ever. */
    public static function count(): int
    {
        return count(self::cookies());
    }

    /**
     * Is any cookie here in a category a visitor can refuse?
     *
     * False today, and that is the point: nothing this site STORES is refusable, because
     * the arrival counting stores nothing new on the device — it reuses the session cookie
     * that is already strictly necessary. A banner would therefore be asking permission to
     * store something we are not storing. What IS refusable is the counting itself, which
     * is a different question and is answered by {@see CookiePrefs}.
     */
    public static function anyRefusable(): bool
    {
        foreach (self::cookies() as $c) {
            if ($c['refusable']) return true;
        }

        return false;
    }

    /**
     * The session cookie's name, as PHP will actually send it.
     *
     * `session_name()` is authoritative and answers before `session_start()`, so this is
     * right in a test process too. The fallback is PHP's own default and exists because a
     * host with `session.name` blanked should not publish an empty table cell.
     */
    public static function sessionName(): string
    {
        $n = '';
        try { $n = trim((string) session_name()); } catch (\Throwable) { $n = ''; }

        return $n !== '' ? $n : 'PHPSESSID';
    }

    /** The session cookie's life, in the words a policy uses, read from the live parameters. */
    public static function sessionLifetime(): string
    {
        $seconds = 0;
        try {
            $seconds = (int) (session_get_cookie_params()['lifetime'] ?? 0);
        } catch (\Throwable) {
            $seconds = 0;
        }

        // 0 is PHP's "until the browser closes", which is a real answer and not a missing one.
        if ($seconds <= 0) return 'Until you close your browser, or sign out';

        return self::daysWord((int) floor($seconds / 86400)) . ', or until you sign out';
    }

    private static function daysWord(int $days): string
    {
        if ($days <= 0)  return 'Less than a day';
        if ($days === 1) return 'One day';
        if ($days === 7) return 'Seven days';

        return $days . ' days';
    }

    private static function yearsWord(int $days): string
    {
        if ($days >= 365 && $days < 730) return 'One year';
        if ($days >= 730)                return (int) round($days / 365) . ' years';

        return self::daysWord($days);
    }
}
