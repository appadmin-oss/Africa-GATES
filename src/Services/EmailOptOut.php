<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The "stop" for bulk mail: one global opt-out list, checked before every send.
 *
 * ── WHY THE TOKEN IS DERIVED AND NOT STORED-ON-FIRST-SEND ────────────────────
 * A broadcast needs an unsubscribe URL for an address that may have no row yet, and
 * writing a row for every recipient just to mint a link would mean the opt-out table
 * is really a mailing list. So the token is an HMAC of the address under the app key:
 * stable, verifiable without a lookup, and useless for enumerating anybody — you
 * cannot walk from one token to another without the key. A row is written only when
 * somebody actually opts out, which keeps the table meaning exactly what its name says.
 *
 * ── SUPPRESSION IS BY HASH ───────────────────────────────────────────────────
 * `email_hash` is what the send checks, so the list can be consulted without reading
 * addresses. The plain address is stored beside it only so an operator answering
 * "why did this person get mail" can look.
 */
final class EmailOptOut
{
    private const SCOPE = 'all';

    public static function normalise(string $email): string
    {
        return \strtolower(\trim($email));
    }

    public static function hash(string $email): string
    {
        return \hash('sha256', self::normalise($email));
    }

    /**
     * The unsubscribe credential for an address.
     *
     * Keyed on APP_KEY so a token cannot be forged or guessed from another one. If no
     * key is configured we fall back to the DB name + address, which is weak — but a
     * weak unsubscribe link still works, and refusing to render one would ship an email
     * with no way out. `app:doctor` is where a missing APP_KEY should be surfaced.
     */
    public static function token(string $email): string
    {
        $key = (string) \AfricaGates\Support\Env::get('APP_KEY', '');
        if ($key === '') {
            $key = 'ag-fallback|' . (string) \AfricaGates\Support\Env::get('DB_NAME', 'africa_gates');
        }

        return \substr(\hash_hmac('sha256', self::normalise($email) . '|' . self::SCOPE, $key), 0, 32);
    }

    /** True when this address has asked not to receive bulk mail. */
    public static function suppressed(string $email): bool
    {
        return DB::table('gates_email_optout')
            ->where('email_hash', self::hash($email))
            ->where('scope', self::SCOPE)
            ->exists();
    }

    /**
     * Every suppressed hash, for filtering a recipient list in one query rather than
     * one query per recipient. A broadcast is thousands of rows; this is two.
     *
     * @return array<string,true> keyed by email_hash for O(1) lookup
     */
    public static function suppressedHashes(): array
    {
        $out = [];
        foreach (DB::table('gates_email_optout')->where('scope', self::SCOPE)->pluck('email_hash') as $h) {
            $out[(string) $h] = true;
        }
        return $out;
    }

    /**
     * Record an opt-out. Idempotent: clicking the link twice is not an error, and the
     * second click must still show the confirmation rather than a failure.
     */
    public static function record(string $email, string $source = 'email-link'): void
    {
        $email = self::normalise($email);
        if ($email === '') return;

        DB::table('gates_email_optout')->updateOrInsert(
            ['email_hash' => self::hash($email), 'scope' => self::SCOPE],
            ['email'      => $email,
             'token'      => self::token($email),
             'source'     => \mb_substr($source, 0, 60),
             'created_at' => Carbon::now()->toDateTimeString()]
        );
    }

    /**
     * The unsubscribe link for an address.
     *
     * The address travels in the URL and the token AUTHENTICATES it, rather than the
     * token being an opaque handle the page has to look up. An earlier version tried to
     * resolve a bare token by testing it against every enumerable recipient, which is a
     * table scan per click and quietly breaks for anybody who is no longer in the list
     * the scan covers. One HMAC compare replaces all of it, and because the token is
     * bound to the address, a guessed link cannot unsubscribe somebody else.
     */
    public static function url(string $siteUrl, string $email): string
    {
        return \rtrim($siteUrl, '/') . '/email/unsubscribe?e=' . self::encode($email)
             . '&t=' . self::token($email);
    }

    /** URL-safe base64, so an address survives a query string and a mail client. */
    public static function encode(string $email): string
    {
        return \rtrim(\strtr(\base64_encode(self::normalise($email)), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        $b = \strtr($encoded, '-_', '+/');
        $pad = \strlen($b) % 4;
        if ($pad !== 0) $b .= \str_repeat('=', 4 - $pad);
        $out = \base64_decode($b, true);

        return \is_string($out) ? self::normalise($out) : '';
    }

    /**
     * Verify a link. Returns the address when the token matches it, else null.
     *
     * `hash_equals`, not `===`: this is a credential comparison, and a timing-safe
     * compare costs nothing here.
     */
    public static function verify(string $encoded, string $token): ?string
    {
        $email = self::decode($encoded);
        if ($email === '' || !\filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        $token = \strtolower(\preg_replace('/[^a-f0-9]/i', '', $token) ?? '');
        if (\strlen($token) !== 32) return null;

        return \hash_equals(self::token($email), $token) ? $email : null;
    }
}
