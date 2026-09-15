<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Disposable / throwaway email detection — Sybil-vote hardening for the OTP gate.
 *
 * A single hardcoded list goes stale the day a new throwaway service launches,
 * so this combines three layers:
 *   1. a broad curated exact-domain set (below);
 *   2. DISTINCTIVE substring signals ("mailinator", "yopmail", "temp-mail"…) that
 *      are specific enough not to false-positive on real domains — deliberately
 *      NOT generic words like "temp"/"mail"/"spam";
 *   3. an ADMIN-EXTENSIBLE list (gates_settings `disposable_domains_extra`,
 *      comma/newline separated) so ops can block a new service the moment it
 *      appears, with no code deploy.
 *
 * Optional (default OFF) deliverability check: when `disposable_require_mx` is
 * enabled, a domain with no MX and no A record — i.e. one that could never
 * receive the OTP anyway — is rejected too. It FAILS OPEN (never blocks on a DNS
 * hiccup) so a flaky resolver can't lock real voters out.
 *
 * Pure + cached per request; no network in the default path, so it's fast and
 * unit-testable.
 */
final class DisposableEmail
{
    /** Known disposable/temporary mail domains (exact match). */
    private const DOMAINS = [
        'mailinator.com','guerrillamail.com','guerrillamail.info','guerrillamail.biz','guerrillamail.de',
        'guerrillamail.net','guerrillamail.org','guerrillamailblock.com','sharklasers.com','grr.la','spam.la',
        '10minutemail.com','10minutemail.net','20minutemail.com','tempmail.com','temp-mail.org','temp-mail.io',
        'tempmail.net','tempmailo.com','tempr.email','tempm.com','tempinbox.com','throwam.com','throwawaymail.com',
        'yopmail.com','yopmail.fr','yopmail.net','dispostable.com','fakeinbox.com','fakemail.net','trashmail.com',
        'trashmail.at','trashmail.io','trashmail.me','trashmail.net','mailnull.com','spamgourmet.com','jetable.fr',
        'spam4.me','maildrop.cc','mailnesia.com','discard.email','discardmail.com','getnada.com','nada.email',
        'mohmal.com','mailcatch.com','moakt.com','moakt.cc','emailondeck.com','mintemail.com','mailexpire.com',
        'getairmail.com','inboxkitten.com','tempmailaddress.com','mytemp.email','mail-temp.com','burnermail.io',
        'anonaddy.me','33mail.com','spambox.us','tmail.io','tmpmail.org','tmpmail.net','tmpbox.net','luxusmail.org',
        'einrot.com','fleckens.hu','cuvox.de','dayrep.com','gustr.com','rhyta.com','superrito.com','teleworm.us',
        'armyspy.com','filzmail.com','spamboy.com','akerd.com','emltmp.com','vomoto.com','byom.de','1secmail.com',
        'wwjmp.com','esiix.com','xojxe.com','vjuum.com','laafd.com','txcct.com','emailfake.com','fakemailgenerator.com',
    ];

    /** DISTINCTIVE substrings — safe because they rarely occur in legitimate domains. */
    private const SIGNALS = [
        'mailinator','guerrillamail','sharklasers','yopmail','temp-mail','tempmail','tempmailo','throwawaymail',
        'throwaway','trashmail','dispostable','fakeinbox','fakemail','emailfake','maildrop','mailnesia','getnada',
        'mohmal','mailcatch','moakt','emailondeck','mintemail','10minutemail','20minutemail','minutemail','1secmail',
        'discardmail','spamgourmet','getairmail','inboxkitten','burnermail','tmpmail','tempinbox','tempmailaddress',
    ];

    /** @var array<string,bool>|null per-request memo of the merged domain set. */
    private static ?array $set = null;

    public static function isDisposable(string $email): bool
    {
        $email = strtolower(trim($email));
        $at = strrchr($email, '@');
        if ($at === false) return false;
        $domain = substr($at, 1);
        if ($domain === '') return false;

        if (isset(self::domainSet()[$domain])) return true;

        foreach (self::SIGNALS as $needle) {
            if (str_contains($domain, $needle)) return true;
        }

        if (self::requireMx() && !self::deliverable($domain)) return true;

        return false;
    }

    /** Built-in domains + admin extras, merged and memoised. */
    private static function domainSet(): array
    {
        if (self::$set !== null) return self::$set;
        $set = array_fill_keys(self::DOMAINS, true);
        foreach (self::adminExtras() as $d) { if ($d !== '') $set[$d] = true; }
        return self::$set = $set;
    }

    /** Admin-added domains from settings (comma/newline separated). Never throws. */
    private static function adminExtras(): array
    {
        try {
            $raw = (string) (DB::table('gates_settings')->where('key_name', 'disposable_domains_extra')->value('value') ?? '');
        } catch (\Throwable) { return []; }
        if (trim($raw) === '') return [];
        $parts = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
        return array_values(array_filter(array_map(
            static fn($d) => ltrim(trim($d), '@'),
            $parts
        )));
    }

    private static function requireMx(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'disposable_require_mx')->value('value');
            return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
        } catch (\Throwable) { return false; }
    }

    /** True if the domain can plausibly receive mail (has MX or A). FAILS OPEN on any error. */
    private static function deliverable(string $domain): bool
    {
        if (!function_exists('checkdnsrr')) return true;
        try {
            if (checkdnsrr($domain, 'MX')) return true;
            if (checkdnsrr($domain, 'A'))  return true;
            return false;   // positively no MX and no A — undeliverable
        } catch (\Throwable) {
            return true;     // DNS trouble → don't block a real voter
        }
    }

    /** Test seam: drop the memoised set (so an admin-extras change is picked up). */
    public static function flushCache(): void { self::$set = null; }
}
