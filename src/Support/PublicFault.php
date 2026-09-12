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
