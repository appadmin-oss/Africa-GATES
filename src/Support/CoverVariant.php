<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/** Stable decoration only. The kind's colours, glyph, text and mask never change. */
final class CoverVariant
{
    public const VERSION = 1;

    public static function forRecord(string $subject, string $kind, int|string|null $identity): int
    {
        $identity = trim((string) $identity);
        if ($identity === '') return 0;
        return (int) hexdec(substr(hash('sha256', $subject . '|' . $kind . '|' . $identity), 0, 8)) % 4;
    }

    public static function flipHorizontal(int $variant): bool { return ($variant & 1) !== 0; }
    public static function flipVertical(int $variant): bool { return ($variant & 2) !== 0; }
}
