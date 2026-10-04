<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\CookiePrefs;

/**
 * Every cookie this platform sets, every key it leaves in a browser's own storage, and the
 * consent category each one belongs to.
 *
 * Rebuilt on 3 Oct 2026 with the four-category consent model (GAPS Q11). Rebuilt rather
 * than edited, because the old list had gone stale in the direction nobody tests: seven of
 * its nine storage rows named keys that nothing on the site wrote any more (their writers
 * were destroyed with the old pages — `afg_cart`, `afg_voted_prog_`, `afg_cheer_`,
 * `afg_report_`, `ag_intro`, `ag-celebrated:`, `ag-hide-bal`), the two shop cookies had
 * lost their only writer (the delegated `data-cookie` listener in the destroyed layout),
 * and a key written through a VARIABLE (`ag_community_prompted`) had never been on it at
 * all. docs/handoff/DESTROYED.md, "Stale declarations on `/cookies`".
 *
 * ── THE FAULT THIS EXISTS BECAUSE OF ─────────────────────────────────────────
 *
 * The published policy said in bold "We set ONE cookie". There were three. The second
 * writer was a line of JavaScript in a template, and the policy's own note pointed at
 * `session_set_cookie_params()` — so checking the named evidence confirmed the false
 * answer. The failure mode is FORGETTING, so a corrected list is not the fix. The fix is
 * that the page is GENERATED from here ({@see \AfricaGates\Services\LegalDocument::cookiesHtml()})
 * and that `CookieRegistryTest` holds this list to the code in BOTH directions: a key the
 * code writes and this does not declare fails by name, and so does a key declared here
 * that nothing writes. A row describing a writer that no longer exists is the same stale
 * legal page, on the side of over-disclosure.
 *
 * ── THE CATEGORIES ARE A LEGAL CLAIM, NOT A TIDY-UP ──────────────────────────
 *
 * A cookie in the wrong category is a consent notice asking permission for the wrong
 * thing. `essential` is the ePrivacy Art.5(3) carve-out: strictly necessary for a service
 * the visitor asked for, never refusable. `preferences` is remembering a choice for NEXT
 * time — refusable, and refused it still works for the browsing session. `analytics` is the
 * arrival counting, which stores nothing of its own. `marketing` holds nothing, and the
 * category is described rather than invented a use.
 */
final class CookieRegistry
{
    public const ESSENTIAL   = CookiePrefs::ESSENTIAL;
    public const PREFERENCES = CookiePrefs::PREFERENCES;
    public const ANALYTICS   = CookiePrefs::ANALYTICS;
    public const MARKETING   = CookiePrefs::MARKETING;

    /**
     * The four categories, in the order a reader meets them: what each one controls, in the
     * words the notice, the preferences sheet and `/cookies` all use.
     *
     * `who` is derived from the entries below, so the names under a category on the sheet
     * are the names the code writes.
     *
     * @return list<array{key:string,name:string,desc:string,who:string}>
     */
    public static function categories(): array
    {
        $words = [
            self::ESSENTIAL => ['Essential',
                'Keeps you signed in, protects forms from forgery, and remembers the choices you make here.'],
            self::PREFERENCES => ['Preferences',
                'Remembers your language, your display and reading settings and the places you open most from the Menu on this device between visits. Without it the first two still work until you close your browser, and the Menu shows its usual four.'],
            self::ANALYTICS => ['Analytics',
                'Counts visits ourselves — where a visit came from and whether it led to a vote, a nomination or a ticket — so whoever shared a link can see if it worked. No IP address is kept and nothing reaches anyone else.'],
            self::MARKETING => ['Marketing',
                'Advertising, and following you across other sites. Nothing on this site does either: no ads, no pixels, nothing sold.'],
        ];

        $out = [];
        foreach ($words as $key => [$name, $desc]) {
            // Only what reaches EVERY visitor: a member of the public reading the sheet is
            // not owed a judge's or a door steward's key as if it were theirs. /cookies
            // lists those too, under their audience.
            $names = array_map(static fn (array $e): string => $e['name'], self::inCategory($key, true));
            if ($key === self::ANALYTICS) {
                $who = 'Africa GATES only · stores nothing on your device';
            } elseif ($names === []) {
                $who = 'Not used on this site';
            } else {
                $who = 'Africa GATES only · ' . implode(', ', $names);
            }
            $out[] = ['key' => $key, 'name' => $name, 'desc' => $desc, 'who' => $who];
        }

        return $out;
    }

    /**
     * Every cookie and storage key in one category.
     *
     * @return list<array{name:string,kind:string}>
     */
    public static function inCategory(string $category, bool $everyoneOnly = false): array
    {
        $out = [];
        foreach (self::cookies() as $c) {
            if ($c['category'] === $category) $out[] = ['name' => $c['name'], 'kind' => 'cookie'];
        }
        foreach (self::storage() as $s) {
            if ($s['category'] !== $category) continue;
            if ($everyoneOnly && $s['audience'] !== 'everyone') continue;
            $out[] = ['name' => $s['key'], 'kind' => 'storage'];
        }
        return $out;
    }

    /**
     * The cookies, in the order a reader should meet them.
     *
     * @return list<array{name:string,category:string,purpose:string,lifetime:string,set_by:string}>
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
            ],
            [
                'name'     => CookiePrefs::COOKIE,
                'category' => self::ESSENTIAL,
                'purpose'  => 'Remembers the choices you made in the cookie notice: a yes or a '
                            . 'no for each of Preferences, Analytics and Marketing, and a version '
                            . 'number. No identifier of any kind. It cannot be refused, because it '
                            . 'is the record of a refusal.',
                'lifetime' => self::yearsWord(CookiePrefs::TTL_DAYS),
                'set_by'   => 'server',
            ],
            [
                'name'     => Languages::COOKIE,
                'category' => self::PREFERENCES,
                'purpose'  => 'The language you chose, so the site opens in it. It holds a '
                            . 'two-letter code and nothing else. Written when you pick a language, '
                            . 'or answer the question we ask about the language your browser asks for.',
                'lifetime' => self::yearsWord((int) (Languages::TTL / 86400))
                            . ' if you allow Preferences; otherwise until you close your browser',
                'set_by'   => 'server',
            ],
        ];
    }

    /**
     * Cookies this platform no longer writes, and what happens to one still in a browser.
     *
     * Not a compatibility alias: each is read once, to carry an answer forward, and then
     * expired. Listed so the policy says what a visitor may still find on their device.
     *
     * @return list<array{name:string,purpose:string}>
     */
    public static function retired(): array
    {
        return [
            [
                'name'    => CookiePrefs::LEGACY,
                'purpose' => 'The earlier record of your answer about counting visits. If your '
                           . 'browser still has it, your answer is carried into '
                           . CookiePrefs::COOKIE . ' — a no stays a no — and this one is deleted.',
            ],
        ];
    }

    /**
     * Things kept in the browser's own storage, which are not cookies and never leave the device.
     *
     * Declared with the PREFIX the code writes, because several are per-item
     * (`coi_declared_12`, `ag-door-q:3f9c…`). `CookieRegistryTest` matches a swept key
     * against these prefixes, and requires every prefix here to match something written.
     *
     * `where`: `local` survives the browser closing; `session` is one tab, gone when it
     * closes; `local-or-session` is decided by the Preferences answer.
     *
     * @return list<array{key:string,category:string,where:string,audience:string,purpose:string}>
     */
    public static function storage(): array
    {
        return [
            ['key' => 'ag-a11y', 'category' => self::PREFERENCES, 'where' => 'local-or-session', 'audience' => 'everyone',
             'purpose' => 'Your display and reading settings — text size, high contrast, the easy-read font, line spacing, underlined links, reduced motion, data saver and read-aloud — applied before the page paints so every page opens the way you set it. Kept on this device between visits if you allow Preferences; otherwise only until you close the tab. When you are signed in, the same settings are also saved to your account.'],
            // Written by celebration.js (the handoff's engine, shipped verbatim), which
            // stores unconditionally — so celebration-boot.js hands it a key only when
            // Preferences is allowed, and with no key it writes nothing.
            ['key' => 'ag-cel-', 'category' => self::PREFERENCES, 'where' => 'local', 'audience' => 'everyone',
             'purpose' => 'That you have already seen a celebration — a vote counted, a nomination sent, a gift, a ticket, a win — so it plays its burst once and goes straight to its quiet loop the next time you open the same page. Kept only if you allow Preferences; without it nothing is stored and the celebration plays in full each time.'],
            // The phone Menu's most-used tiles (MenuShortcuts, 4 Oct 2026). For a guest only,
            // and only with Preferences allowed — refused, nothing is written and the Menu shows
            // its four defaults. A signed-in member's count is kept on their account instead.
            ['key' => 'ag-menu-use', 'category' => self::PREFERENCES, 'where' => 'local', 'audience' => 'everyone',
             'purpose' => 'Which places you open from the Menu on your phone, and roughly how often and how recently, so the four squares at the top of the Menu become the ones you use most. Kept on this device only if you allow Preferences, and only while you are not signed in; without it nothing is stored and the Menu shows its usual four.'],
            // Gee (Phase 3): its transcript and the privacy note's dismissal.
            ['key' => 'ag-gee-chat:', 'category' => self::ESSENTIAL, 'where' => 'session', 'audience' => 'everyone',
             'purpose' => 'Your conversation with Gee, the site guide and help desk — one for each — so it survives moving between pages and an accidental reload in this tab. Gone when you close the tab. Nothing you type is kept on our server unless you pass it to a person, when it is kept with your support ticket.'],
            ['key' => 'ag-gee-privacy', 'category' => self::PREFERENCES, 'where' => 'local-or-session', 'audience' => 'everyone',
             'purpose' => 'That you closed the privacy note at the top of Gee, so it is not shown again. Kept on this device between visits if you allow Preferences; otherwise only until you close the tab.'],
            ['key' => 'coi_declared_', 'category' => self::ESSENTIAL, 'where' => 'session', 'audience' => 'judges',
             'purpose' => 'For judges only: that you have made this programme\'s conflict-of-interest declaration in this tab.'],
            ['key' => 'ag-door-q:', 'category' => self::ESSENTIAL, 'where' => 'local', 'audience' => 'door staff',
             'purpose' => 'For event door staff only: tickets scanned while the connection was down, held on the scanning phone until they can be checked.'],
            ['key' => 'afStep:', 'category' => self::ESSENTIAL, 'where' => 'session', 'audience' => 'administrators',
             'purpose' => 'For administrators only: which step of a long form you were on, so a save that bounces back reopens it there.'],
            ['key' => 'ag-copilot', 'category' => self::ESSENTIAL, 'where' => 'session', 'audience' => 'administrators',
             'purpose' => 'For administrators only: the console assistant\'s conversation, so it survives moving between pages in one tab.'],
            ['key' => 'ag-asst', 'category' => self::ESSENTIAL, 'where' => 'session', 'audience' => 'administrators',
             'purpose' => 'For administrators only: the same, on the assistant\'s full page.'],
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
     * The session cookie's name, as PHP will actually send it.
     *
     * `session_name()` is authoritative and answers before `session_start()`. The fallback
     * is PHP's own default, so a host with `session.name` blanked does not publish an empty
     * table cell.
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
