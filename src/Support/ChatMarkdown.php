<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The console assistant's answers, as HTML a reader can scan: paragraphs, **bold**, `code`,
 * bullet and numbered lists, and links — nothing else.
 *
 * The answer was drawn as one pre-wrapped run of text, so the key figure, the list of what
 * to do and the page to do it on all looked the same. Formatting it is only safe one way
 * round: EVERYTHING is escaped first, and the few marks below are then turned into tags
 * this class writes itself. A model's reply is text from a provider about data typed by the
 * public, so no tag, attribute or entity it contains may survive as markup.
 *
 * Links are held to two shapes: a console path (`/admin…`) or an `https://` address, and an
 * outside one opens in a new tab with no referrer. A bare `/admin/…` path in a sentence is
 * linked too, because that is how the assistant has always pointed at a page.
 */
final class ChatMarkdown
{
    public static function html(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') return '';
        $out = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $lines = array_values(array_filter(explode("\n", $block), static fn (string $l): bool => trim($l) !== ''));
            if ($lines === []) continue;
            $bullets = array_filter($lines, static fn (string $l): bool => preg_match('/^\s*[-*•]\s+/u', $l) === 1);
            $numbers = array_filter($lines, static fn (string $l): bool => preg_match('/^\s*\d{1,2}[.)]\s+/', $l) === 1);
            if (count($bullets) === count($lines)) {
                $out[] = '<ul>' . implode('', array_map(static fn (string $l): string =>
                    '<li>' . self::inline((string) preg_replace('/^\s*[-*•]\s+/u', '', $l)) . '</li>', $lines)) . '</ul>';
            } elseif (count($numbers) === count($lines)) {
                $out[] = '<ol>' . implode('', array_map(static fn (string $l): string =>
                    '<li>' . self::inline((string) preg_replace('/^\s*\d{1,2}[.)]\s+/', '', $l)) . '</li>', $lines)) . '</ol>';
            } elseif (count($bullets) > 0 && preg_match('/^\s*[-*•]\s+/u', $lines[0]) !== 1 && count($bullets) === count($lines) - 1) {
                // "Three things:" followed by its list.
                $out[] = '<p>' . self::inline($lines[0]) . '</p><ul>' . implode('', array_map(static fn (string $l): string =>
                    '<li>' . self::inline((string) preg_replace('/^\s*[-*•]\s+/u', '', $l)) . '</li>', array_slice($lines, 1))) . '</ul>';
            } else {
                $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $lines)) . '</p>';
            }
        }
        return implode('', $out);
    }

    /** The plain text of an answer, for copying and for the conversation's own history. */
    public static function plain(string $text): string
    {
        $t = (string) preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1 ($2)', $text);
        return trim(str_replace(['**', '`'], '', $t));
    }

    private static function inline(string $line): string
    {
        $codes = [];
        // Code first, and held aside, so nothing inside backticks is read as a mark.
        $line = (string) preg_replace_callback('/`([^`\n]{1,200})`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::esc($m[1]) . '</code>';
            return "\u{E000}" . (count($codes) - 1) . "\u{E001}";
        }, $line);

        $links = [];
        $line = (string) preg_replace_callback('/\[([^\]\n]{1,120})\]\(([^)\s]{1,400})\)/', static function (array $m) use (&$links): string {
            $href = self::href($m[2]);
            if ($href === null) return $m[1];
            $ext = !str_starts_with($href, '/');
            $links[] = '<a href="' . self::esc($href) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . self::esc($m[1]) . '</a>';
            return "\u{E002}" . (count($links) - 1) . "\u{E003}";
        }, $line);

        $html = self::esc($line);
        $html = (string) preg_replace('/\*\*([^*\n]{1,200})\*\*/', '<strong>$1</strong>', $html);
        // A bare console path in a sentence, as the assistant has always written them.
        $html = (string) preg_replace_callback('~(^|[\s(])(/admin(?:/[A-Za-z0-9._\-]+)*(?:\?[A-Za-z0-9=._%\-]*)?(?:#[A-Za-z0-9_\-]+)?)(?=[\s).,;:!?]|$)~',
            static fn (array $m): string => $m[1] . '<a href="' . $m[2] . '">' . $m[2] . '</a>', $html);

        $html = (string) preg_replace_callback("/\u{E002}(\d+)\u{E003}/u", static fn (array $m): string => $links[(int) $m[1]] ?? '', $html);
        return (string) preg_replace_callback("/\u{E000}(\d+)\u{E001}/u", static fn (array $m): string => $codes[(int) $m[1]] ?? '', $html);
    }

    /** A console path or an https address, or nothing. */
    private static function href(string $u): ?string
    {
        $u = trim($u);
        if (preg_match('~^/admin(?:[/?#][^\s"<>]*)?$~', $u) === 1 && !str_starts_with($u, '//')) return $u;
        if (preg_match('~^https://[A-Za-z0-9.\-]+(?::\d+)?(?:/[^\s"<>]*)?$~', $u) === 1) return $u;
        return null;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
