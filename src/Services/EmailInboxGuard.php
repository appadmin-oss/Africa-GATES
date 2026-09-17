<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The inbox properties, checked against markup that is about to be sent.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SERVICE AND NOT ONLY A TEST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \Tests\Unit\EmailInboxCompatTest} holds twelve properties that decide whether a
 * campaign renders in a real inbox, and it holds them against the TEMPLATE FILE. That was
 * exactly right while the campaign WAS a file: a developer broke it, CI said so, nobody
 * was mailed.
 *
 * Once the copy lives in a database row that a comms person edits, the template is no
 * longer the only input. The skeleton can be perfect and the content can still push the
 * rendered size past Gmail's clipping point, or smuggle in a `data:` image, or paste a
 * link nothing will resolve. None of that fails CI, because CI never sees the row — it
 * fails in eight hundred inboxes.
 *
 * So the mechanical half of those checks lives here, runs on the RENDERED output, and the
 * save path refuses. `HANDOFF.md` §6 asks for exactly this: "run EmailInboxCompatTest
 * against the rendered output in CI so a bad edit fails before it is sent, not after."
 * Failing the save is better than failing CI, because the person who can fix it is the
 * person who is standing there.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT DOES NOT CHECK
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Only the properties an EDIT can break. The MSO conditionals, the VML button, the
 * dark-mode declarations and the `role="presentation"` tables belong to the skeleton, and
 * the skeleton is not editable — those stay where they are, asserted against the file, in
 * the test. Duplicating them here would mean two places to update and one of them
 * silently wrong.
 */
final class EmailInboxGuard
{
    /**
     * Gmail clips a message past roughly 102,400 bytes, and what it clips off the end of a
     * campaign is the footer — where the unsubscribe link lives. So this is not a
     * cosmetic budget: overshooting it turns a compliant email into a non-compliant one.
     *
     * The cap here is deliberately lower than Gmail's threshold. A campaign renders once
     * per recipient with their own name, category and vote link substituted in, so the
     * measured size is one sample of a family of sizes.
     */
    public const MAX_BYTES = 92160;

    /** Where Gmail actually clips, for reporting the margin honestly. */
    public const GMAIL_CLIP_BYTES = 102400;

    /**
     * Everything wrong with this markup, in the order somebody should fix it.
     *
     * @return list<string> empty means it may be sent
     */
    public static function problems(string $html): array
    {
        $out = [];

        $bytes = strlen($html);
        if ($bytes > self::MAX_BYTES) {
            $out[] = sprintf(
                'The email renders to %s bytes. Gmail clips at about %s and takes the footer — '
                . 'including the unsubscribe link — with it. Shorten the copy or remove a block; '
                . 'the limit here is %s so there is room for a long name and category.',
                number_format($bytes), number_format(self::GMAIL_CLIP_BYTES), number_format(self::MAX_BYTES)
            );
        }

        // Gmail, Outlook desktop, Outlook.com and Yahoo all refuse these. The original
        // logo in this campaign was 20,848 base64 bytes AND corrupt — see HANDOFF.md §7.
        if (stripos($html, 'src="data:') !== false || stripos($html, "src='data:") !== false) {
            $out[] = 'There is a data: URI image in the email. Gmail, Outlook and Yahoo all refuse '
                   . 'them — it will render as a broken image. Reference the file by its https URL.';
        }

        // A relative src resolves against nothing in a mail client.
        foreach (self::attr($html, 'img', 'src') as $src) {
            if ($src === '' || str_starts_with($src, 'https://') || str_starts_with($src, 'data:')) continue;
            if (str_contains($src, '{{')) continue;   // an unrendered variable is a different bug
            $out[] = 'The image "' . self::clip($src) . '" is not an absolute https URL. '
                   . 'A mail client has no page to resolve it against, so it will not load.';
        }

        // A CSP nonce in a mail template is the inverse of the web rule, and CspTest
        // enforces the asymmetry deliberately so the exemption cannot become a hole.
        if (str_contains($html, 'nonce=')) {
            $out[] = 'The email carries a CSP nonce. Mail templates must not — see CspTest, which '
                   . 'enforces the opposite rule for templates/emails/ on purpose.';
        }

        // An unrendered Twig tag means a variable the render did not supply. In an inbox it
        // appears literally, which reads as a broken mail-merge.
        if (preg_match('/\{\{|\{%/', $html)) {
            $out[] = 'The rendered email still contains a Twig tag, so something was not substituted. '
                   . 'It would arrive with {{ }} visible in the text.';
        }

        // Every link must be absolute AND resolvable. A campaign whose CTA is "#" is a
        // campaign that measured fine and converted nothing.
        foreach (self::attr($html, 'a', 'href') as $href) {
            if ($href === '' || $href === '#') {
                $out[] = 'There is a link with no destination. Every link in a campaign has to go '
                       . 'somewhere — an empty href is a dead CTA.';
                continue;
            }
            if (str_starts_with($href, 'https://') || str_starts_with($href, 'mailto:')) continue;
            $out[] = 'The link "' . self::clip($href) . '" is not an absolute https URL. Links in '
                   . 'email have no page to resolve against.';
        }

        return array_values(array_unique($out));
    }

    /** True when this markup may be sent. */
    public static function ok(string $html): bool
    {
        return self::problems($html) === [];
    }

    /**
     * Values of one attribute across one tag.
     *
     * A regex rather than a DOM parse on purpose: the markup contains MSO conditional
     * comments, and every HTML parser available here either strips them or chokes — which
     * would make this report problems in markup that is fine, or miss an image hidden
     * inside a conditional.
     *
     * @return list<string>
     */
    private static function attr(string $html, string $tag, string $attr): array
    {
        $out = [];
        if (!preg_match_all('/<' . $tag . '\b[^>]*>/i', $html, $tags)) return $out;

        foreach ($tags[0] as $t) {
            if (preg_match('/\b' . $attr . '\s*=\s*"([^"]*)"/i', $t, $m)
             || preg_match("/\b" . $attr . "\s*=\s*'([^']*)'/i", $t, $m)) {
                $out[] = trim($m[1]);
            }
        }
        return $out;
    }

    private static function clip(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 57) . '…' : $s;
    }
}
