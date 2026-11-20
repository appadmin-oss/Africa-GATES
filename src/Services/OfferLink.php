<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * THE PERSONAL "WHAT YOUR VOTES UNLOCK" LINK (owner, 17 Nov 2026: "once they vote, a link is
 * sent with all the possible benefits").
 *
 * It carries the voter's PERSON KEY (the board's own: the hash a free vote is stored under, or
 * the hash a buyer's address resolves to), signed, so the offer page can show them their own
 * votes, what they have unlocked and what is next — without an account, which matters because
 * a free voter is stored as a hash only and cannot be found any other way.
 *
 * The key is a one-way hash; the signature stops anybody walking keys to read other people's
 * standing. It reveals nothing but a vote count and a place on a public board.
 */
final class OfferLink
{
    public static function path(string $programmeSlug, string $key): string
    {
        $base = '/vote/' . rawurlencode($programmeSlug) . '/offer';
        return $key === '' ? $base : $base . '?k=' . rawurlencode($key) . '&s=' . self::sign($key);
    }

    /** The key a link carries, when its signature holds. */
    public static function key(array $query): ?string
    {
        $k = is_string($query['k'] ?? null) ? $query['k'] : '';
        $s = is_string($query['s'] ?? null) ? $query['s'] : '';
        if ($k === '' || $s === '' || strlen($k) > 80) return null;
        return hash_equals(self::sign($k), $s) ? $k : null;
    }

    private static function sign(string $key): string
    {
        return substr(EmailOptOut::sign('offer:' . $key, 'offer-link'), 0, 16);
    }
}
