<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Throwable;

/**
 * WHAT A PERSON IS TOLD WHEN SOMETHING BREAKS, AND WHAT THEY CAN DO ABOUT IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TWO FAULTS THIS EXISTS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. THE 500 PAGE MADE A PROMISE NOBODY KEEPS. "Our team has been notified and is on
 *    it." Nothing notifies anybody. {@see \AfricaGates\Handlers\ErrorHandler} appends the
 *    exception to `var/logs/error-detail.log`, and the only thing that has ever read that
 *    file is a diagnostics route an operator has to remember to open — on a host with no
 *    shell and no alerting. So the sentence was false, and worse than false: it told
 *    somebody their problem was already being handled, which is the most effective way to
 *    stop them reporting it.
 *
 * 2. AND IT GAVE THEM NOTHING TO HOLD. The log line carried a timestamp; the reader saw
 *    "please try again in a moment". A person who did contact support could describe only
 *    what they were doing, and nobody could find their entry among a day of them. The
 *    detail and the person were in the same building and could not be introduced.
 *
 * A REFERENCE fixes both at once. It is minted here, written into the log line, and shown
 * on the page — so the honest sentence ("we have not seen this yet, tell us and quote
 * this") replaces the false one, and quoting it lands support on the exact stack trace.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE OTHER HALF: WHOSE WORDS ARE THESE?
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Several controllers print `$e->getMessage()` straight to a member — usually harmless,
 * because the throw is ours and the message was written for a person ("File larger than
 * 15MB"). But the catch is `\Throwable`, so the same line renders a PDO error, a file
 * path, or a stack frame the moment something unexpected fails underneath. A partner
 * organisation uploading a logo could be shown our SQL.
 *
 * {@see explain()} answers "were these words written for this reader?" — and it is
 * deliberately not a class check alone. `RuntimeException` is ours *and* every library's,
 * and Illuminate's QueryException is a PDOException carrying the whole statement. So the
 * class must be one we throw AND the text must not look like machinery. Anything else
 * becomes the fallback plus a reference, which is strictly more useful to the reader than
 * a stack frame and strictly less useful to somebody probing the site.
 */
final class PublicFault
{
    /**
     * Reference alphabet: no O/0, no I/1/L, no U.
     *
     * These get read down a phone line and typed into a support form by somebody who is
     * already annoyed. Crockford's set minus the vowel that makes words appear by accident.
     */
    private const ALPHABET = '23456789ACDEFGHJKMNPQRTVWXY';

    /** Exception types this codebase throws WITH COPY WRITTEN FOR A PERSON. */
    private const OURS = [
        \RuntimeException::class,
        \InvalidArgumentException::class,
        \DomainException::class,
        \LengthException::class,
        \AfricaGates\Services\PhaseError::class,
    ];

    /**
     * Text that betrays machinery, whatever class carried it.
     *
     * The class allowlist is necessary and not sufficient: Illuminate wraps a PDO failure
     * in QueryException (a PDOException, so already excluded) but plenty of libraries
     * throw a bare RuntimeException containing a path or a driver string. A message is
     * shown only if it passes BOTH gates.
     */
    private const MACHINERY = [
        'SQLSTATE', 'Stack trace', '#0 ', '::', '.php', '/var/', '/home/', 'C:\\',
        'Exception', 'Fatal error', 'Undefined ', 'Call to ', 'PDO', 'Connection:',
        'syntax error', 'Allowed memory', 'cURL error',
    ];

    /** Longest message we will pass through. Real copy for a person is short. */
    private const MAX = 180;

    /**
     * A new reference — eight characters, grouped for reading aloud.
     *
     * Random rather than derived from the fault: two people hitting the same bug must get
     * different references, or support cannot tell their reports apart, and a reference
     * that encodes the error would leak which error it was to anybody who collected a few.
     */
    public static function reference(): string
    {
        $n = strlen(self::ALPHABET);
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= self::ALPHABET[random_int(0, $n - 1)];
            if ($i === 3) $out .= '-';
        }
        return $out;
    }

    /**
     * Write the full detail against a reference, and hand the reference back.
     *
     * Best-effort by design: a logging failure must never become the response. That is why
     * the reference is minted BEFORE the write and returned regardless — a reader with a
     * reference and no log entry can still be told apart from a reader with neither, and
     * the alternative is a page that breaks while reporting a break.
     *
     * @param string $where a short human label for the surface, e.g. 'org logo upload'
     */
    public static function record(Throwable $e, string $where = ''): string
    {
        $ref = self::reference();

        try {
            $dir = dirname(__DIR__, 2) . '/var/logs';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            // The reference FIRST on the line, so grepping for what somebody quoted finds
            // it without knowing the date, the class or the route.
            @file_put_contents($dir . '/error-detail.log',
                '[' . date('c') . '] [ref ' . $ref . ']'
                . ($where !== '' ? ' [' . $where . ']' : '') . ' '
                . get_class($e) . ': ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n"
                . $e->getTraceAsString() . "\n\n", FILE_APPEND);
        } catch (\Throwable) {}

        return $ref;
    }

    /**
     * The sentence to show, and a reference when the real one had to be withheld.
     *
     * @param  string $fallback what to say when the exception's own words are not for
     *                          this reader — write it as a next step, never as an apology
     * @return array{message:string, reference:?string}
     */
    public static function explain(Throwable $e, string $fallback, string $where = ''): array
    {
        if (self::isOurs($e)) {
            return ['message' => $e->getMessage(), 'reference' => null];
        }

        $ref = self::record($e, $where);
        return ['message' => $fallback, 'reference' => $ref];
    }

    /**
     * One line, ready to print: the message, plus the reference when there is one.
     *
     * Convenience for the several flash-message call sites, so none of them has to decide
     * how a reference is phrased. Wording matters here — "quote this" is the instruction
     * that turns a dead end into a report.
     */
    public static function line(Throwable $e, string $fallback, string $where = ''): string
    {
        $x = self::explain($e, $fallback, $where);
        return $x['reference'] === null
            ? $x['message']
            : $x['message'] . ' If you tell us about it, quote ' . $x['reference'] . '.';
    }

    /**
     * A reference somebody has QUOTED back to us — usually by pressing "Tell Gee" on the
     * error page, which sends "Something went wrong. Reference XXXX-XXXX".
     *
     * ── WHY THE HELP DESK HAS TO ASK THIS FIRST ──────────────────────────────────
     *
     * That sentence reached the Help Centre search, which matched the word "reference" to
     * "The reference my wallet app shows is different" and quoted it at the person — an
     * answer about payment numbers, to somebody whose page had just failed, with our own
     * markup printed raw in the bubble. The one person who had done exactly what the
     * error page asked was told about OPay.
     *
     * A string of this SHAPE is not enough on its own — a reference is eight characters
     * from a 27-letter alphabet, and the odd word pair fits it — so it must also either be
     * in the log or arrive in a sentence about a reference or an error.
     */
    public static function quoted(string $message): ?string
    {
        $a = self::ALPHABET;
        // Typed the way people type it off a screen: lower case, a space for the dash, or
        // no dash at all. Read back to the one canonical form before anything compares it.
        if (!preg_match_all('/(?<![A-Za-z0-9-])([' . $a . ']{4})[-\s]?([' . $a . ']{4})(?![A-Za-z0-9-])/i', $message, $all, PREG_SET_ORDER)) {
            return null;
        }
        $about = (bool) preg_match('/\b(ref|reference|error|went wrong|wrong|broke|failed|failing|crash)/i', $message);
        foreach ($all as $m) {
            $ref = strtoupper($m[1] . '-' . $m[2]);
            if (self::find($ref) !== null) return $ref;
            // Eight letters from this alphabet is also an ordinary WORD — "appeared",
            // "accepted" — and two four-letter words make a dashed one. A reference with
            // no digit in it is believed only when the log holds it.
            if ($about && preg_match('/\d/', $ref)) return $ref;
        }
        return null;
    }

    /**
     * The log entry a reference was written against — when and where, never what.
     *
     * Reads the tail of the log only: a reference somebody is quoting is minutes or days
     * old, and the file grows for ever on a host where nobody rotates it.
     *
     * @return array{at:string, where:string, method:string, path:string}|null
     */
    public static function find(string $ref, ?string $file = null): ?array
    {
        $e = self::entry($ref, $file);
        return $e === null ? null : ['at' => $e['at'], 'where' => $e['where'], 'method' => $e['method'], 'path' => $e['path']];
    }

    /**
     * The whole entry, for STAFF: what failed and where in the code. Read by the admin
     * ticket screen, so whoever picks up the ticket sees the fault without the token page.
     * Never put any of `detail` or `trace` in front of the person who reported it.
     *
     * @return array{at:string, where:string, method:string, path:string, detail:string, trace:list<string>}|null
     */
    public static function entry(string $ref, ?string $file = null): ?array
    {
        if (!preg_match('/^[' . self::ALPHABET . ']{4}-[' . self::ALPHABET . ']{4}$/', $ref)) return null;
        $raw = self::tail($file);
        if ($raw === '' || !preg_match('/^\[([^\]]+)\] \[ref ' . preg_quote($ref, '/') . '\](?: \[([^\]]*)\])? ([^\n]*)((?:\n(?!\n|\[\d{4}-)[^\n]*)*)/m', $raw, $m)) {
            return null;
        }
        $where = (string) ($m[2] ?? '');
        [$method, $path] = preg_match('/^([A-Z]+)\s+(\S+)/', $where, $w) ? [$w[1], $w[2]] : ['', ''];
        $trace = array_values(array_filter(array_map('trim', explode("\n", (string) ($m[4] ?? ''))), 'strlen'));
        return ['at' => $m[1], 'where' => $where, 'method' => $method, 'path' => $path,
                'detail' => trim((string) $m[3]), 'trace' => array_slice($trace, 0, 8)];
    }

    /**
     * How many OTHER references were written for the same request in the last day.
     *
     * The question a person cannot answer for themselves: is it my phone, or is it you?
     */
    public static function others(string $ref, string $where, ?string $file = null, ?\DateTimeImmutable $now = null): int
    {
        if ($where === '') return 0;
        $since = ($now ?? new \DateTimeImmutable('now'))->modify('-24 hours');
        $n = 0;
        if (preg_match_all('/^\[([^\]]+)\] \[ref ([A-Z0-9-]+)\] \[' . preg_quote($where, '/') . '\]/m', self::tail($file), $all, PREG_SET_ORDER)) {
            foreach ($all as $hit) {
                if ($hit[2] === $ref) continue;
                try { if (new \DateTimeImmutable($hit[1]) >= $since) $n++; } catch (\Throwable) {}
            }
        }
        return $n;
    }

    /** Was this request about money — paying, voting, giving, buying, a ticket? */
    public static function aboutMoney(string $path): bool
    {
        return (bool) preg_match('~^/(?:api/(?:v1/)?)?(?:pay|payments?|checkout|donate|gift|giving|shop|orders?|vote|votes|tickets?|hooks/pay)(?:/|$)|^/events/[^/]+/(?:register|tickets?|pay)~', $path);
    }

    /**
     * What the person was doing, in their words rather than ours — "creating a challenge in
     * the admin console", not "POST /admin/challenges". Null when the path says nothing
     * a person would recognise; the caller then says less rather than printing a route.
     */
    public static function activity(string $method, string $path): ?string
    {
        $send = $method !== '' && $method !== 'GET' && $method !== 'HEAD';
        foreach ([
            '~^/admin/challenges~'          => [$send ? 'saving a challenge' : 'opening challenges', ' in the admin console'],
            '~^/admin/nominees/(?:duplicate|merge)~' => [$send ? 'merging nominees' : 'checking for duplicate nominees', ' in the admin console'],
            '~^/admin/settings~'            => [$send ? 'saving settings' : 'opening settings', ' in the admin console'],
            '~^/admin/support~'             => [$send ? 'replying to a ticket' : 'opening a support ticket', ' in the admin console'],
            '~^/admin~'                     => [$send ? 'saving something' : 'opening a page', ' in the admin console'],
            '~^/(?:api/(?:v1/)?)?(?:pay|payments?|checkout)~' => ['paying', ''],
            '~^/(?:api/(?:v1/)?)?votes?~'   => ['voting', ''],
            '~^/(?:donate|gift|giving)~'    => ['giving', ''],
            '~^/shop~'                      => [$send ? 'placing an order' : 'browsing', ' in the shop'],
            '~^/nominat~'                   => [$send ? 'sending a nomination' : 'nominating', ''],
            '~^/account/login|^/login~'     => ['signing in', ''],
            '~^/account/register|^/register~' => ['registering', ''],
            '~^/account~'                   => [$send ? 'saving' : 'opening', ' your account'],
            '~^/org~'                       => [$send ? 'saving' : 'opening', ' your organisation console'],
            '~^/events~'                    => [$send ? 'registering' : 'opening', ' an event'],
            '~^/challenges?~'               => [$send ? 'entering' : 'opening', ' a challenge'],
            '~^/results~'                   => ['opening', ' a result'],
            '~^/(?:help|support)~'          => ['asking', ' for help'],
        ] as $re => [$verb, $where]) {
            if (preg_match($re, $path)) return $verb . $where;
        }
        return null;
    }

    /**
     * What the help desk says to a quoted reference. One sentence set, shared by the
     * support agent and the no-provider floor, so the two cannot describe it differently.
     *
     * Honest in the places the old reply was not: it never says payments were unaffected
     * about a request that WAS a payment, and it never tells somebody to resend a form that
     * may already have gone through.
     *
     * @param array{at:string, where:string, method?:string, path?:string}|null $entry
     */
    public static function chatReply(string $ref, ?array $entry, int $others = 0, ?\DateTimeImmutable $now = null): string
    {
        $method = (string) ($entry['method'] ?? '');
        $path   = (string) ($entry['path'] ?? '');
        $money  = $path !== '' && self::aboutMoney($path);

        $first = '**Thank you — that one is our fault, not anything you did.**';
        if ($entry !== null) {
            $doing = self::activity($method, $path);
            $when  = self::when($entry['at'], $now);
            $first .= ' Reference ' . $ref . ' was ' . ($when !== '' ? 'written ' . $when : 'recorded')
                    . ($doing !== null ? ', while you were ' . $doing : '') . '.';
        } else {
            $first .= ' I cannot find reference ' . $ref . ' in our records yet. References never contain 0, 1, O, I, L '
                    . 'or U, so check it was copied exactly — the team will look for it either way.';
        }
        if (!$money) $first .= ' Your votes, entries and payments are not affected by it.';
        $out = [$first];

        if ($others > 0) {
            $out[] = $others === 1
                ? 'One other person hit the same fault on that page in the last day, so it is on our side — not your phone or your connection.'
                : $others . ' other people hit the same fault on that page in the last day, so it is on our side — not your phone or your connection.';
        }

        if ($money) {
            $out[] = 'If you were paying, your money is safe either way: a payment that went through is credited '
                   . 'automatically, and one that did not go through is never taken. Send me the reference that '
                   . 'starts with **AFG-** — it is in your payment email and your bank alert — and I will check it now.';
        } elseif ($entry !== null && $method !== '' && $method !== 'GET') {
            $out[] = 'What you sent may or may not have been saved. Check before you send it again, so it does not go in twice.';
        } elseif ($entry !== null && preg_match('~^/[a-z0-9/-]{1,80}$~', $path)) {
            $out[] = 'Opening it again in a minute usually works: [try it again](' . $path . ').';
        }
        return implode("\n\n", $out);
    }

    /** "12 minutes ago", "at 14:32 today", "on 7 Oct at 14:32" — Lagos time. */
    private static function when(string $at, ?\DateTimeImmutable $now): string
    {
        try {
            $lagos = new \DateTimeZone('Africa/Lagos');
            $t = (new \DateTimeImmutable($at))->setTimezone($lagos);
            $n = ($now ?? new \DateTimeImmutable('now'))->setTimezone($lagos);
        } catch (\Throwable) {
            return '';
        }
        $mins = intdiv($n->getTimestamp() - $t->getTimestamp(), 60);
        if ($mins >= 0 && $mins < 1) return 'just now';
        if ($mins >= 0 && $mins < 60) return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
        if ($t->format('Y-m-d') === $n->format('Y-m-d')) return 'at ' . $t->format('H:i') . ' today (Lagos time)';
        return 'on ' . $t->format('j M \a\t H:i') . ' (Lagos time)';
    }

    /** The last 4 MB of the log, or ''. */
    private static function tail(?string $file): string
    {
        $file ??= dirname(__DIR__, 2) . '/var/logs/error-detail.log';
        if (!is_file($file)) return '';
        $size = (int) @filesize($file);
        $h = @fopen($file, 'rb');
        if ($h === false) return '';
        $max = 4 * 1024 * 1024;
        if ($size > $max) fseek($h, $size - $max);
        $raw = (string) stream_get_contents($h);
        fclose($h);
        return $raw;
    }

    /** Were these words written for a person by us? */
    public static function isOurs(Throwable $e): bool
    {
        // Exact class, never instanceof: QueryException extends PDOException which is a
        // RuntimeException, so an `instanceof RuntimeException` test would wave through
        // the one exception type that carries an entire SQL statement.
        if (!in_array($e::class, self::OURS, true)) return false;

        $msg = trim($e->getMessage());
        if ($msg === '' || mb_strlen($msg) > self::MAX) return false;

        foreach (self::MACHINERY as $tell) {
            if (stripos($msg, $tell) !== false) return false;
        }
        return true;
    }
}
