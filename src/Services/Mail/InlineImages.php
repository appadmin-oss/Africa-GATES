<?php
declare(strict_types=1);

namespace AfricaGates\Services\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * OUR OWN IMAGES TRAVEL INSIDE THE MESSAGE, SO THEY SHOW WITHOUT "DISPLAY IMAGES".
 *
 * Owner, 5 Nov 2026: "the email images do not load by default". Every image in our mail was a
 * LINK to the site — the mark in the header, the illustration at the top, a campaign's logo —
 * and Outlook, Yahoo, most corporate mail and many phone clients block linked images until the
 * reader presses "show images", which most never do. So the first thing a nominee saw of a
 * congratulations email was a grey box with an X in it.
 *
 * An image carried INSIDE the message (a `cid:` part, RFC 2392) is not a remote load and is
 * shown by those clients as it arrives. This class does that for the images we serve
 * ourselves: an `<img>` whose source is a file under public/assets or public/uploads on this
 * site, PNG / JPEG / GIF only (SVG is refused by Gmail and WebP by Outlook, so neither is
 * worth carrying), up to {@see MAX_BYTES} in total so a message stays light. Anything else —
 * a hosted photo, the live countdown (it must render at OPEN time, which is its whole point) —
 * stays a link, exactly as before.
 *
 * ── AND IT IS UNDONE FOR THE ROADS THAT CANNOT CARRY IT ──────────────────────
 * The Brevo HTTP API and Google Apps Script take an HTML string and a list of attachments; an
 * inline part sent there would arrive as a stray attachment and every picture would break.
 * So those roads read {@see html()}, which puts the links back, and {@see BuiltMessage} leaves
 * inline parts out of their attachment lists. The same message is correct on every road.
 */
final class InlineImages
{
    /** Never carry more than this inside one message. The house illustrations are 30–145 KB. */
    public const MAX_BYTES = 450_000;

    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    /** @var \WeakMap<PHPMailer, array<string,string>>|null cid => original URL, per message */
    private static ?\WeakMap $map = null;

    /**
     * Embed every eligible image in `$m->Body`. Returns how many were embedded.
     *
     * @param string $base this site's absolute address (APP_URL); only its own files are carried
     */
    public static function embed(PHPMailer $m, string $base, ?string $publicDir = null): int
    {
        $body = (string) $m->Body;
        if ($body === '' || stripos($body, '<img') === false) return 0;
        $publicDir ??= dirname(__DIR__, 3) . '/public';
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));

        if (!preg_match_all('~<img\b[^>]*?\bsrc\s*=\s*(["\'])([^"\']+)\1~i', $body, $mm)) return 0;
        $done = [];
        $total = 0;
        foreach (array_unique($mm[2]) as $src) {
            $file = self::localFile(html_entity_decode($src, ENT_QUOTES | ENT_HTML5), $host, $publicDir);
            if ($file === null) continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $bytes = @file_get_contents($file);
            if ($bytes === false || $bytes === '' || $total + strlen($bytes) > self::MAX_BYTES) continue;
            $cid = 'ag' . substr(sha1($file), 0, 16) . '@africagates';
            try {
                $m->addStringEmbeddedImage($bytes, $cid, basename($file), PHPMailer::ENCODING_BASE64, self::TYPES[$ext], 'inline');
            } catch (\Throwable) {
                continue;
            }
            $total += strlen($bytes);
            $done[$cid] = $src;
        }
        if ($done === []) return 0;

        foreach ($done as $cid => $src) {
            $body = preg_replace('~(<img\b[^>]*?\bsrc\s*=\s*)(["\'])' . preg_quote($src, '~') . '\2~i', '$1$2cid:' . $cid . '$2', $body);
        }
        $m->Body = (string) $body;
        self::$map ??= new \WeakMap();
        self::$map[$m] = (self::$map[$m] ?? []) + $done;
        return count($done);
    }

    /** The HTML with every carried image turned back into its link — for the roads that send HTML by API. */
    public static function html(PHPMailer $m): string
    {
        $body = (string) $m->Body;
        $map = self::$map[$m] ?? [];
        foreach ($map as $cid => $src) $body = str_replace('cid:' . $cid, $src, $body);
        return $body;
    }

    /** A URL to one of our own files, as a path on disk — or null when it is not one we can carry. */
    private static function localFile(string $src, string $host, string $publicDir): ?string
    {
        if (str_starts_with($src, 'cid:') || str_starts_with($src, 'data:')) return null;
        $p = parse_url($src);
        if (!is_array($p) || empty($p['path'])) return null;
        if (isset($p['host']) && ($host === '' || strtolower((string) $p['host']) !== $host)) return null;
        if (isset($p['query'])) return null;                       // generated per request: not a file
        $path = rawurldecode((string) $p['path']);
        if (!preg_match('~^/(assets|uploads)/[A-Za-z0-9_./-]+$~', $path) || str_contains($path, '..')) return null;
        if (!isset(self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) return null;
        $file = realpath($publicDir . $path);
        $root = realpath($publicDir);
        if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) return null;
        return $file;
    }
}
