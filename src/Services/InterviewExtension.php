<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The Chrome extension, packed for THIS deployment.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE EXTENSION COULD NOT BE INSTALLED AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The interview screen said, in full:
 *
 *     Install: Chrome → Extensions → Developer mode → Load unpacked → the
 *     `extension/` folder from the upload.
 *
 * Nothing served that folder. It exists in the repository, and on the server it sits
 * outside the web root — which is correct, because a directory of extension source under
 * `public/` is a directory anybody can enumerate. So the instruction described a folder the
 * operator had no way to obtain: not by URL, not over SSH, which this host does not have,
 * and not from the admin console. "It is impossible to trigger the extension" is the
 * accurate report, and the cause was upstream of every line of the extension's own code.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE ZIP IS BUILT RATHER THAN COMMITTED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two of the extension's files carry this platform's address: `manifest.json`'s
 * `host_permissions`, and the `DEFAULT_BASE` constant in `worker.js` and `popup.js`. All
 * three were hardcoded to one hostname.
 *
 * That is the difference between an extension that works and one that fails in the least
 * legible way available. `host_permissions` decides whether the service worker's fetch is a
 * privileged extension request; pointed at the wrong host, every call to this platform is
 * an ordinary cross-origin fetch instead, blocked with no CORS headers to satisfy it. The
 * panel then shows "Could not reach the platform" from inside a live interview, and nothing
 * in Chrome names the manifest as the reason.
 *
 * So the host is injected at download time from the request the admin made, which is by
 * definition the deployment they are configuring. A staging install, a renamed domain and a
 * fresh deployment all get a correct extension without anybody editing JSON.
 *
 * The committed source stays the source. It is not rewritten, and the substitution is
 * anchored to the exact literals so a file that stops containing one is REPORTED rather
 * than silently shipped pointing at the wrong host — which is the whole failure this
 * exists to prevent.
 */
final class InterviewExtension
{
    /**
     * The host the committed source is written against.
     *
     * Also the substitution anchor. Deliberately the literal rather than a pattern: a
     * regex over `https://[^/]+` would rewrite any address in any file, including one in a
     * comment or a Google URL, and the point of this class is to be certain about what it
     * changed.
     */
    public const SOURCE_HOST = 'https://afg.afrovanguard.org.ng';

    /** Everything that goes in, in the order it is written. */
    public const FILES = [
        'manifest.json',
        'worker.js',
        'content.js',
        'popup.html',
        'popup.js',
        'panel.css',
        'README.md',
    ];

    /**
     * Files whose contents carry the platform's address.
     *
     * README.md is NOT one of them, deliberately. It names the committed host while
     * explaining what happens if you load the repository folder directly instead of
     * downloading it — rewriting the host there would turn a true warning into a false one.
     * What is specific to a built download goes in INSTALL.txt instead.
     */
    private const REWRITTEN = ['manifest.json', 'worker.js', 'popup.js', 'popup.html'];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/extension';
    }

    /** Is the source actually on disk? A deployment can be missing it — see download(). */
    public static function available(): bool
    {
        foreach (self::FILES as $f) {
            if (!is_readable(self::dir() . '/' . $f)) return false;
        }
        return class_exists(\ZipArchive::class);
    }

    /**
     * Why it cannot be offered, in words an operator can act on.
     *
     * Two different faults with two different fixes, and both used to be invisible: the
     * files were left out of the upload, or the host has no zip extension.
     */
    public static function why(): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return 'This server has no ZIP extension, so the download cannot be built. '
                 . 'Enable php-zip, or copy the extension/ folder off the server by FTP and '
                 . 'load it unpacked from there.';
        }
        $missing = [];
        foreach (self::FILES as $f) {
            if (!is_readable(self::dir() . '/' . $f)) $missing[] = $f;
        }
        if ($missing !== []) {
            return 'The extension source is not on this server (' . implode(', ', $missing)
                 . ' missing from extension/). It is in the repository — re-upload that folder.';
        }
        return '';
    }

    /**
     * The scheme and host to bake in, taken from the request the admin made.
     *
     * `$base` arrives from the PSR-7 URI rather than from a setting, because the whole
     * failure mode here is a value that disagrees with reality. The URL the operator is
     * looking at cannot disagree with the deployment they are on.
     */
    public static function normaliseBase(string $base): string
    {
        $base = rtrim(trim($base), '/');
        // Refuse rather than guess. A blank or malformed base would bake a broken
        // host_permissions into an extension that then fails inside a live interview.
        if (!preg_match('~^https?://[A-Za-z0-9.\-]+(:\d+)?$~', $base)) {
            throw new \RuntimeException('Cannot pack the extension: “' . $base
                . '” is not a usable site address.');
        }
        return $base;
    }

    /**
     * One file's contents, with this deployment's host in place of the committed one.
     *
     * @return array{0:string, 1:int} the contents and how many substitutions were made
     */
    public static function rewrite(string $name, string $src, string $base): array
    {
        if ($base === self::SOURCE_HOST || !in_array($name, self::REWRITTEN, true)) {
            return [$src, 0];
        }
        $n = 0;
        $out = str_replace(self::SOURCE_HOST, $base, $src, $n);
        return [$out, $n];
    }

    /**
     * Build the archive. Returns the bytes, because the caller streams them and there is
     * nothing worth keeping on disk afterwards.
     *
     * @return array{bytes:string, filename:string, host:string, rewrites:int}
     */
    public static function pack(string $base): array
    {
        $base = self::normaliseBase($base);
        if (($why = self::why()) !== '') throw new \RuntimeException($why);

        // ZipArchive writes to a path, so a real temp file is unavoidable. Removed in the
        // finally below even when the write throws — a web host's temp directory filling up
        // with half-built archives is the kind of failure that surfaces as something else
        // entirely, three weeks later.
        $tmp = tempnam(sys_get_temp_dir(), 'ag-ext-');
        if ($tmp === false) throw new \RuntimeException('Could not open a temporary file to build the download.');

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not start the archive.');
            }

            $rewrites = 0;
            $host = parse_url($base, PHP_URL_HOST) ?: 'site';

            foreach (self::FILES as $name) {
                $src = (string) file_get_contents(self::dir() . '/' . $name);
                [$out, $n] = self::rewrite($name, $src, $base);
                $rewrites += $n;

                // Flat, with no wrapping directory. Chrome's "Load unpacked" wants the
                // folder that CONTAINS manifest.json, and an operator who unzips a folder
                // inside a folder picks the outer one, gets "Manifest file is missing or
                // unreadable", and has no way to tell which of the two it meant.
                $zip->addFromString($name, $out);
            }

            // A note that did not exist in the repository copy, because it is only true of
            // a built download: which host this one is wired to. An operator with two
            // deployments has two zips that look identical.
            $zip->addFromString('INSTALL.txt', self::installNote($base));

            if (!$zip->close()) throw new \RuntimeException('Could not finish the archive.');

            $bytes = (string) file_get_contents($tmp);

            return [
                'bytes'    => $bytes,
                // The host is in the filename for the same reason it is in INSTALL.txt.
                'filename' => 'africa-gates-interview-assistant-' . preg_replace('/[^a-z0-9.\-]/i', '-', $host) . '.zip',
                'host'     => $host,
                'rewrites' => $rewrites,
            ];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The four steps, in the box, next to the files they refer to.
     *
     * A README explaining the extension already exists and is included. This is the part
     * that is specific to the download in the operator's hands, and it is a separate file
     * because the README is committed source and this is not.
     */
    private static function installNote(string $base): string
    {
        return implode("\n", [
            'Africa GATES — Interview Assistant',
            '==================================',
            '',
            'This copy is wired to: ' . $base,
            'If that is not the site you use, download it again from that site instead of',
            'editing this folder — the address is in three files and missing one of them',
            'produces an extension that loads, runs, and cannot reach the platform.',
            '',
            'To install (one laptop, two minutes):',
            '',
            '  1. Unzip this folder somewhere you will not delete by accident.',
            '     Do NOT move manifest.json out of it.',
            '  2. In Chrome, open  chrome://extensions',
            '  3. Turn on "Developer mode" (top right).',
            '  4. Press "Load unpacked" and choose THIS folder — the one holding',
            '     manifest.json. Not the zip, and not a folder containing it.',
            '',
            'Then, for each interview:',
            '',
            '  5. Click the extension icon in the toolbar. Paste the live key from the',
            '     interview screen in the admin console. The site address is already',
            '     filled in.',
            '  6. Join the Meet call and press CC to turn captions on. Google requires a',
            '     person to do this, every call — the extension cannot.',
            '',
            'The live key is one interview only. Paste the next one before the next call.',
            '',
            'Chrome forgets unpacked extensions if you delete the folder, and shows a',
            '"Disable developer mode extensions" prompt on every start. Both are normal.',
            'Keeping the folder and dismissing the prompt is all that is needed.',
            '',
            'What it does, what it cannot do, and what it collects: see README.md.',
        ]) . "\n";
    }
}
