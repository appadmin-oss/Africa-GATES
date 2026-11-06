<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/** One allocation scope per rendered page. Rotation never creates a tracking cookie. */
final class ImageSelection
{
    private array $used = [];
    private readonly int $day;

    public function __construct(string $date)
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$day || $day->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Expected a calendar date.');
        $this->day = (int) floor($day->getTimestamp() / 86400);
    }

    public function claim(string $identity): bool
    {
        if (isset($this->used[$identity])) return false;
        $this->used[$identity] = true;
        return true;
    }

    /** Priority order by default; daily round-robin for interchangeable, context-appropriate candidates. */
    public function pick(array $candidates, bool $rotate = false, string $context = ''): ?array
    {
        $candidates = array_values(array_filter($candidates));
        $count = count($candidates);
        if ($rotate && $count > 1) {
            $offset = ($this->day + (int) hexdec(substr(hash('sha256', $context), 0, 6))) % $count;
            $candidates = array_merge(array_slice($candidates, $offset), array_slice($candidates, 0, $offset));
        }
        foreach ($candidates as $asset) if ($this->claim($asset['id'])) return $asset;
        return null;
    }
}
