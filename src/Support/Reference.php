<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Enterprise-grade nomination references: AGN-YYYY-XXXXXX-C
 *
 *   AGN     — Africa GATES Nomination namespace
 *   YYYY    — cycle year (human context, natural yearly partitioning)
 *   XXXXXX  — 6 Crockford-base32 chars from a bijective mix of the row id:
 *             (id · 48271) mod 2³⁰ — collision-free for ids below ~1.07 B,
 *             and consecutive ids don't produce consecutive-looking codes
 *   C       — Crockford mod-37 check character (catches any single mistyped
 *             character before a lookup resolves to the wrong nomination)
 *
 * Crockford base32 excludes I, L, O and U, so codes survive handwriting and
 * phone calls. The reference is persisted on the row (unique column) at
 * submit time; legacy NOM-{id} references remain parseable for old emails.
 */
final class Reference
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const CHECK    = '0123456789ABCDEFGHJKMNPQRSTVWXYZ*~$=U';
    private const MIX      = 48271;        // odd → bijective multiplier mod 2^30
    private const MOD      = 1 << 30;      // 32^6 — exactly six base32 chars

    public static function nomination(int $id, ?int $year = null): string
    {
        if ($id < 1 || $id >= self::MOD) {
            throw new \InvalidArgumentException('Nomination id out of reference range.');
        }
        $year ??= (int) date('Y');
        $enc = self::mix($id);
        return sprintf('AGN-%04d-%s-%s', $year, self::base32($enc, 6), self::CHECK[$enc % 37]);
    }

    /**
     * A claim reference: AGC-XXXXXX-C.
     *
     * Same machinery as a nomination reference, minus the year. A nomination belongs to
     * exactly one cycle, so the year is useful context; a claim is on a PERSON's page and
     * may outlive several cycles, so a year in it would actively mislead — somebody would
     * read AGC-2026 on a claim still open in 2028 and file it under the wrong cycle.
     *
     * Shorter matters here beyond tidiness. This is the string a nominee reads out of an
     * SMS, over a bad line, from a handset that may not be theirs, to say that somebody
     * has taken their page. Every character dropped is a character that cannot be
     * misheard.
     */
    public static function claim(int $id): string
    {
        if ($id < 1 || $id >= self::MOD) {
            throw new \InvalidArgumentException('Claim id out of reference range.');
        }
        $enc = self::mix($id);
        return sprintf('AGC-%s-%s', self::base32($enc, 6), self::CHECK[$enc % 37]);
    }

    /**
     * A vote-recovery batch: AGR-XXXXXX-C.
     *
     * Meant to be QUOTED. It is printed on the public disclosure beside any votes the
     * platform put on a tally itself, so that "where did these come from" has an
     * answer somebody can look up and argue with, rather than being a number that
     * simply appeared.
     */
    public static function recovery(int $id): string
    {
        if ($id < 1 || $id >= self::MOD) {
            throw new \InvalidArgumentException('Recovery batch id out of reference range.');
        }
        $enc = self::mix($id);
        return sprintf('AGR-%s-%s', self::base32($enc, 6), self::CHECK[$enc % 37]);
    }

    /** Resolve a recovery reference back to a batch id, checksum-verified. */
    public static function parseRecoveryId(string $ref): ?int
    {
        if (!preg_match('/^AGR-([0-9A-HJKMNP-TV-Z]{6})-([0-9A-HJKMNP-TV-Z*~$=U])$/', strtoupper(trim($ref)), $m)) {
            return null;
        }
        $enc = 0;
        foreach (str_split($m[1]) as $ch) {
            $enc = ($enc << 5) | strpos(self::ALPHABET, $ch);
        }
        if (self::CHECK[$enc % 37] !== $m[2]) return null;
        $id = self::unmix($enc);
        return ($id >= 1 && $id < self::MOD) ? $id : null;
    }

    /** Resolve a claim reference back to a claim id, checksum-verified. Null when invalid. */
    public static function parseClaimId(string $ref): ?int
    {
        if (!preg_match('/^AGC-([0-9A-HJKMNP-TV-Z]{6})-([0-9A-HJKMNP-TV-Z*~$=U])$/', strtoupper(trim($ref)), $m)) {
            return null;
        }
        $enc = 0;
        foreach (str_split($m[1]) as $ch) {
            $enc = ($enc << 5) | strpos(self::ALPHABET, $ch);
        }
        if (self::CHECK[$enc % 37] !== $m[2]) return null;
        $id = self::unmix($enc);
        return ($id >= 1 && $id < self::MOD) ? $id : null;
    }

    /** Format + checksum validation (does not hit the database). */
    public static function isValid(string $ref): bool
    {
        return self::decode($ref) !== null;
    }

    /**
     * Resolve a reference back to a nomination id. Accepts the AGN format
     * (checksum-verified) and the legacy NOM-{id} format. Null when invalid.
     */
    public static function parseId(string $ref): ?int
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^NOM-(\d+)$/', $ref, $m)) {
            return (int) $m[1] > 0 ? (int) $m[1] : null;
        }
        return self::decode($ref);
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /** Verify shape + checksum and return the original id, or null. */
    private static function decode(string $ref): ?int
    {
        if (!preg_match('/^AGN-\d{4}-([0-9A-HJKMNP-TV-Z]{6})-([0-9A-HJKMNP-TV-Z*~$=U])$/', strtoupper(trim($ref)), $m)) {
            return null;
        }
        $enc = 0;
        foreach (str_split($m[1]) as $ch) {
            $enc = ($enc << 5) | strpos(self::ALPHABET, $ch);
        }
        if (self::CHECK[$enc % 37] !== $m[2]) return null;
        $id = self::unmix($enc);
        return ($id >= 1 && $id < self::MOD) ? $id : null;
    }

    private static function base32(int $value, int $width): string
    {
        $out = '';
        for ($i = 0; $i < $width; $i++) {
            $out = self::ALPHABET[$value & 31] . $out;
            $value >>= 5;
        }
        return $out;
    }

    private static function mix(int $id): int
    {
        return (int) (($id * self::MIX) % self::MOD);
    }

    private static function unmix(int $enc): int
    {
        return (int) (($enc * self::modInverse(self::MIX, self::MOD)) % self::MOD);
    }

    /** Modular inverse via extended Euclid (MIX is odd, so it exists mod 2^30). */
    private static function modInverse(int $a, int $m): int
    {
        [$old_r, $r] = [$a, $m];
        [$old_s, $s] = [1, 0];
        while ($r !== 0) {
            $q = intdiv($old_r, $r);
            [$old_r, $r] = [$r, $old_r - $q * $r];
            [$old_s, $s] = [$s, $old_s - $q * $s];
        }
        return (($old_s % $m) + $m) % $m;
    }
}
