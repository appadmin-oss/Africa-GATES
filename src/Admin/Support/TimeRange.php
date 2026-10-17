<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

/**
 * THE WINDOW A CONSOLE LIST IS LOOKING AT — one reading of `?range=`, `?from=` and `?to=`.
 *
 * Owner, 13 Oct 2026, with screenshots of a console they liked: a range control that says
 * "Last 7 days · WAT", steps back and forward a window at a time, and a chart above the
 * table that the range drives. Every list that offers it reads its window here, so "the
 * last 7 days" means the same span on the audit log, the alerts timeline and the home
 * chart, and the previous/next arrows always move by the window's own length.
 *
 * Times are SHOWN and TYPED in the platform's display zone (DisplayTime), named in the
 * label, and compared against storage in the process clock (UTC) — a log read in one zone
 * and filtered in another is a log that hides the hour somebody asked about.
 */
final class TimeRange
{
    /** The presets offered, in order. Keys are what `?range=` carries. */
    public const PRESETS = [
        '24h' => ['Last 24 hours', 86400],
        '7d'  => ['Last 7 days', 7 * 86400],
        '30d' => ['Last 30 days', 30 * 86400],
        '90d' => ['Last 90 days', 90 * 86400],
    ];

    private function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly ?string $preset,
    ) {}

    /** @param array<string,mixed> $qp the request's query parameters */
    public static function fromQuery(array $qp, string $default = '7d', ?int $now = null): self
    {
        $now = $now ?? time();
        $from = self::parse((string) ($qp['from'] ?? ''), false);
        $to   = self::parse((string) ($qp['to'] ?? ''), true);
        if ($from !== null || $to !== null) {
            $to   = $to ?? $now;
            $from = $from ?? ($to - self::PRESETS[$default][1]);
            if ($from > $to) [$from, $to] = [$to, $from];
            return new self($from, $to, null);
        }
        $key = (string) ($qp['range'] ?? $default);
        if (!isset(self::PRESETS[$key])) $key = $default;
        return new self($now - self::PRESETS[$key][1], $now, $key);
    }

    /** An explicit window, for a caller that knows its own span (e.g. "all time"). */
    public static function between(int $from, int $to): self
    {
        return new self(min($from, $to), max($from, $to), null);
    }

    public function seconds(): int { return max(1, $this->to - $this->from); }

    /** The same length, immediately before / after. Next is null when it would pass now. */
    public function previous(): self { return new self($this->from - $this->seconds(), $this->from, null); }
    public function next(?int $now = null): ?self
    {
        $now = $now ?? time();
        if ($this->to >= $now - 60) return null;
        $to = min($now, $this->to + $this->seconds());
        return new self($to - $this->seconds(), $to, null);
    }

    /** As query parameters, merged onto whatever else the page carries. */
    public function query(): array
    {
        return $this->preset !== null
            ? ['range' => $this->preset]
            : ['from' => self::show($this->from, 'Y-m-d H:i'), 'to' => self::show($this->to, 'Y-m-d H:i')];
    }

    /** For a SQL bound on a TIMESTAMP column, in the stored format (never `T`-separated). */
    public function fromSql(): string { return date('Y-m-d H:i:s', $this->from); }
    public function toSql(): string   { return date('Y-m-d H:i:s', $this->to); }

    /** "Last 7 days", or "5 Oct, 19:56 – 12 Oct, 19:56". */
    public function label(): string
    {
        if ($this->preset !== null) return self::PRESETS[$this->preset][0];
        $sameYear = self::show($this->from, 'Y') === self::show($this->to, 'Y');
        $f = $sameYear ? 'j M, H:i' : 'j M Y, H:i';
        return self::show($this->from, $f) . ' – ' . self::show($this->to, $f);
    }

    /** The zone the times are SHOWN in, for the label ("WAT"). Storage stays UTC. */
    public static function zone(): string
    {
        return \AfricaGates\Support\DisplayTime::abbr();
    }

    /** A timestamp in the display zone (DisplayTime) — what an operator reads and types. */
    public static function show(int $t, string $format): string
    {
        return (new \DateTimeImmutable('@' . $t))
            ->setTimezone(new \DateTimeZone(\AfricaGates\Support\DisplayTime::zone()))->format($format);
    }

    private static function parse(string $s, bool $endOfDay): ?int
    {
        $s = trim($s);
        if ($s === '') return null;
        // Typed in the display zone, as everything an operator types is (DisplayTime).
        try {
            $t = (new \DateTimeImmutable(str_replace('T', ' ', $s),
                new \DateTimeZone(\AfricaGates\Support\DisplayTime::zone())))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
        // A bare date as an upper bound means the END of that day; without it
        // `to=2026-10-12` silently drops everything on the 12th.
        if ($endOfDay && !str_contains($s, ':')) $t += 86399;
        return $t;
    }
}
