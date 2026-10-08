<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Reading a PHPMailer message that has already been built — for the roads that hand it
 * to an HTTP API instead of sending the MIME themselves.
 *
 * ── `ContentType` IS NOT WHAT IT WAS WHEN THE MESSAGE WAS BUILT ──────────────────
 *
 * `isHTML(true)` sets `text/html`; a send attempt then runs `preSend()`, which rewrites it
 * to `multipart/alternative` (HTML with a text part) or `multipart/mixed` (attachments).
 * Every fallback road runs AFTER a failed SMTP attempt, so a road asking "does the type
 * say html" sent the branded HTML as plain text — measured: a sign-in code arriving as
 * five kilobytes of markup. Asked once, here, in a way that survives `preSend()`.
 */
final class BuiltMessage
{
    public static function isHtml(PHPMailer $m): bool
    {
        $type = strtolower((string) $m->ContentType);
        if (str_contains($type, 'html')) return true;
        // After preSend(): multipart with a separate text part means the Body is HTML.
        return str_starts_with($type, 'multipart/') && trim((string) $m->AltBody) !== '';
    }

    /**
     * Attachments by value. Only string attachments are built on this platform
     * (OtpService attaches by value, never by path).
     *
     * @return list<array{name:string, mime:string, content:string}> content is raw bytes
     */
    public static function attachments(PHPMailer $m): array
    {
        $out = [];
        foreach ($m->getAttachments() as $a) {
            $content = !empty($a[5]) ? (string) $a[0] : (is_file((string) $a[0]) ? (string) file_get_contents((string) $a[0]) : '');
            if ($content === '') continue;
            $out[] = ['name' => (string) ($a[2] ?: $a[1]), 'mime' => (string) ($a[4] ?? 'application/octet-stream'),
                      'content' => $content];
        }
        return $out;
    }
}
