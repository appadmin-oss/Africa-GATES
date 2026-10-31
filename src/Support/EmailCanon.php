<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The key two email addresses share when they reach ONE inbox — for counting people,
 * never for sending, signing in or display.
 *
 * Two rules, and only two, because the failure this guards against is the opposite one:
 * a canonical form that is wrong does not create a duplicate, it MERGES two different
 * people, and a count of people that quietly joins strangers is worse than one that
 * counts a person twice.
 *
 *   · `+tag` is dropped on every domain. Plus addressing delivers to the base mailbox on
 *     Gmail, Outlook, iCloud, Proton, Fastmail and most hosted mail; "ada+votes@" is the
 *     same inbox as "ada@", and it is how one person makes a second address for free.
 *   · dots in the local part are dropped ONLY on gmail.com / googlemail.com, which ignore
 *     them for personal accounts. A Workspace domain and every other provider treat
 *     "a.da@" and "ada@" as two mailboxes that may belong to two people, so the dot is
 *     kept there.
 *
 * The address the person typed is untouched wherever it is stored; this is a separate
 * key, used beside it.
 */
final class EmailCanon
{
    private const DOTLESS = ['gmail.com', 'googlemail.com'];

    /** The canonical form, or '' when the input is not an address. */
    public static function key(?string $email): string
    {
        $e = mb_strtolower(trim((string) $email));
        $at = strrpos($e, '@');
        if ($at === false || $at === 0 || $at === strlen($e) - 1) return '';
        $local = substr($e, 0, $at);
        $domain = substr($e, $at + 1);
        $plus = strpos($local, '+');
        if ($plus !== false && $plus > 0) $local = substr($local, 0, $plus);
        if (in_array($domain, self::DOTLESS, true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }
        return $local === '' ? '' : $local . '@' . $domain;
    }

    /** The key, hashed the way a voter's address is — so no address is ever stored for it. */
    public static function hash(?string $email): ?string
    {
        $k = self::key($email);
        return $k === '' ? null : hash('sha256', 'ag-email-canon:' . $k);
    }
}
