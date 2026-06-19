<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Allowlist HTML sanitiser for admin-authored rich text (blog/legacy bodies).
 *
 * Renders are passed through this instead of Twig's `|raw`, so stored HTML can
 * never inject <script>, inline event handlers, or javascript:/data: URLs — the
 * stored-XSS vector flagged in the audit. DOM-based allowlist: unknown tags are
 * unwrapped (their safe text is kept), disallowed attributes are stripped.
 */
final class Html
{
    /** Tags permitted in rich text. Everything else is unwrapped or dropped. */
    private const ALLOWED = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
        'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'h2', 'h3', 'h4', 'h5', 'img', 'figure', 'figcaption', 'span',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    /** Attributes permitted per tag. Any attribute not listed is removed. */
    private const ALLOWED_ATTR = [
        'a'    => ['href', 'title', 'target', 'rel'],
        'img'  => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'span' => ['class'],
        'td'   => ['colspan', 'rowspan'],
        'th'   => ['colspan', 'rowspan'],
    ];

    public static function sanitize(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') return '';

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // The XML encoding hint keeps UTF-8 intact; loadHTML wraps in <html><body>.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return '';

        self::clean($body, $doc);

        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function clean(\DOMNode $node, \DOMDocument $doc): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) continue;
            if (!($child instanceof \DOMElement)) {       // comments, processing instructions
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (!in_array($tag, self::ALLOWED, true)) {
                // Drop these AND their subtree. Beyond the obvious script/style,
                // svg/math/template/noscript are foreign-content roots that, if merely
                // unwrapped, promote mutation-XSS vectors into the tree; base/link/meta
                // are document metadata that must never appear in rich text.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form',
                                    'svg', 'math', 'template', 'noscript', 'base', 'link', 'meta', 'title'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::clean($child, $doc);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $allowed = self::ALLOWED_ATTR[$tag] ?? [];
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                if (!in_array($name, $allowed, true)) {            // strips on*, style, etc.
                    $child->removeAttribute($attr->name);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true) && self::badUrl($attr->value)) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && strtolower($child->getAttribute('target')) === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            self::clean($child, $doc);
        }
    }

    /** True for schemes we don't allow (javascript:, data:, vbscript:, …). */
    private static function badUrl(string $url): bool
    {
        $u = strtolower(trim($url));
        if ($u === '') return true;
        // Explicitly safe: http(s), mailto, tel, anchors, relative & protocol-relative.
        if (preg_match('#^(https?:|mailto:|tel:|/|\#|\./|\.\./)#', $u)) return false;
        if (str_starts_with($u, '//')) return false;
        // Any other explicit scheme (foo:) is rejected; no scheme = relative = allowed.
        return (bool) preg_match('#^[a-z][a-z0-9+.\-]*:#', $u);
    }
}
