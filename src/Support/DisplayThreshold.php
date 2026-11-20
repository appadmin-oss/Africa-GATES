<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * "Is there enough of this to show it in public?" — one answer, from one table
 * (config/display_thresholds.php, LOW-DATA-RULES.md §3).
 *
 * Every public block that hides below a minimum asks here, so the rule is tuned in one
 * place and a template never carries its own copy of "25". An unknown key THROWS: a typo
 * that silently returned 0 would show every block at every size, which is the exact
 * failure the table exists to prevent.
 */
final class DisplayThreshold
{
    /** @var array<string,int>|null */
    private static ?array $table = null;

    public static function min(string $key): int
    {
        self::$table ??= (array) require dirname(__DIR__, 2) . '/config/display_thresholds.php';
        if (!array_key_exists($key, self::$table)) {
            throw new \InvalidArgumentException("Unknown display threshold: {$key}");
        }
        return (int) self::$table[$key];
    }

    public static function met(string $key, int|float $value): bool
    {
        return $value >= self::min($key);
    }

    /**
     * A large figure, approximated for discovery pages (§1.7): 1,240 → 1.2K, 1,250,000 →
     * 1.25M. Exact figures belong on the campaign or award page itself.
     */
    public static function approx(int|float $n): string
    {
        $n = (float) $n;
        if ($n >= 1_000_000) return rtrim(rtrim(number_format($n / 1_000_000, 2, '.', ''), '0'), '.') . 'M';
        if ($n >= 1_000)     return rtrim(rtrim(number_format($n / 1_000, 1, '.', ''), '0'), '.') . 'K';
        return number_format($n);
    }
}
