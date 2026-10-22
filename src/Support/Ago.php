<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * How long ago, as a feed says it: "now", "12m", "3h", "2d", then the date ("8 Oct",
 * "8 Oct 2025" in another year). PulsePage.dc.html's `· 1h`.
 *
 * Stored times are UTC; the date form is shown in the platform's display zone, so a post at
 * 23:30 UTC is not dated the next morning for a reader in Lagos.
 */
final class Ago
{
    public static function of(string|\DateTimeInterface|null $at, ?int $now = null): string
    {
        if ($at === null || $at === '') return '';
        try {
            $t = $at instanceof \DateTimeInterface ? $at->getTimestamp()
               : (new \DateTimeImmutable((string) $at, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return '';
        }
        $now = $now ?? time();
        $d = max(0, $now - $t);
        if ($d < 60)        return Translator::t('now');
        if ($d < 3600)      return Translator::t('%n%m', ['%n%' => (string) intdiv($d, 60)]);
        if ($d < 86400)     return Translator::t('%n%h', ['%n%' => (string) intdiv($d, 3600)]);
        if ($d < 7 * 86400) return Translator::t('%n%d', ['%n%' => (string) intdiv($d, 86400)]);

        $local = (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone(DisplayTime::zone()));

        return $local->format('Y') === (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(DisplayTime::zone()))->format('Y')
            ? $local->format('j M') : $local->format('j M Y');
    }
}
