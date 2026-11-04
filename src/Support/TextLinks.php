<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Text somebody typed, made safe for a page and with its web addresses made links.
 *
 * Escaped FIRST, so nothing a member wrote is ever markup; then bare http(s) URLs (and
 * www. ones) become `<a class="ag-link">` with `rel="nofollow ugc noopener"` — a member's
 * link is theirs, not the platform's endorsement. Line breaks are kept. The Pulse feed's
 * linkifier was a script and went with the old page; this is the one helper now.
 */
final class TextLinks
{
    public static function html(?string $text): string
    {
        $safe = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = (string) preg_replace_callback('~\b((?:https?://|www\.)[^\s<]+[^\s<.,;:!?)\]\'"])~i', static function (array $m): string {
            $label = $m[1];
            $href  = stripos($label, 'www.') === 0 ? 'https://' . $label : $label;
            $href  = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
            if (!preg_match('~^https?://~i', $href)) return $label;

            return '<a class="ag-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" rel="nofollow ugc noopener" target="_blank">' . $label . '</a>';
        }, $safe);

        return nl2br($out, false);
    }
}
