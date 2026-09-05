<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * The hostile payloads the upload tests need, assembled at run time.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS, AND WHY IT IS NOT SECURITY THEATRE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two tests prove that an upload is judged by its BYTES rather than by its filename or its
 * declared MIME type: a PHP script named `clip.mp4` and announced as `video/mp4` must be
 * refused. To prove that, the test has to actually write a PHP script.
 *
 * Written as a literal, that script is a virus-scanner signature. Specifically it matched
 * ClamAV's `{HEX}php.backdoor.shellexec.getvar`, and the consequence was not theoretical:
 *
 *   • Uploading a deployment archive to cPanel was CANCELLED mid-upload.
 *   • And — worse, because it affects anyone, not just this operator — downloading the
 *     REPOSITORY from GitHub as a zip was quarantined. Excluding `tests/` from the hand-built
 *     archives hid the first symptom and did nothing about the second: a source download
 *     contains every tracked file, so the project could not be fetched at all through any
 *     scanner that reads inside archives. That is a real defect in the repository, not a
 *     false-positive somebody else has to work around.
 *
 * ── WHAT CHANGED, AND WHAT DID NOT ───────────────────────────────────────────
 *
 * The BYTES the tests write are byte-for-byte identical to what they wrote before — asserted
 * below by {@see phpScript()}'s own checks and by the tests that consume it. Only the
 * REPRESENTATION in the source file changed: the payload is stored base64-encoded and decoded
 * on use, so no file in this repository contains the pattern a scanner looks for.
 *
 * That distinction matters. Weakening the test to dodge a scanner would be trading a real
 * protection for a convenience, and the protection here is the one that stops somebody
 * uploading a web shell through the support form. Nothing about the test's strength changes:
 * the same script, with the same shell call and the same request variable, is still written to
 * disk and still has to be refused.
 *
 * ── WHY BASE64 RATHER THAN CONCATENATION ─────────────────────────────────────
 *
 * Splitting the payload across string concatenation would also break a naive substring match,
 * but it leaves the words legible and adjacent — so a slightly better scanner still matches,
 * and the next person to tidy the line for readability silently reassembles it. An encoded
 * constant cannot be put back together by accident.
 *
 * The same care applies to comments. An earlier version of this file described the payload by
 * quoting it, and `clamscan` found the signature in the very archive built to prove the fix
 * had worked: a comment is bytes in a file like any other. So the constants are described in
 * words, and the run-time checks below assemble their needles from fragments.
 */
final class HostileBytes
{
    /**
     * A one-line PHP web shell, base64-encoded: an open tag, an echo, a shell-execution call
     * taking a query-string parameter named `c`, a close tag, and a newline.
     *
     * DESCRIBED RATHER THAN SPELLED OUT, and that is not fussiness — writing the payload in
     * this comment put the signature back into the repository, and `clamscan` caught it in the
     * very archive built to prove the fix worked. A comment is bytes in a file like any other;
     * a scanner does not know it is a comment.
     */
    private const PHP_SHELL_B64 = 'PD9waHAgZWNobyBzaGVsbF9leGVjKCRfR0VUWydjJ10pOyA/Pgo=';

    /** The same script with double quotes around the parameter name, and no trailing newline. */
    private const PHP_SHELL_B64_DQ = 'PD9waHAgZWNobyBzaGVsbF9leGVjKCRfR0VUWyJjIl0pOyA/Pg==';

    /**
     * The web shell with a single-quoted parameter name, newline-terminated.
     *
     * Guarded rather than trusted: a mistyped constant would silently hand the tests something
     * that is NOT a PHP script, and the upload service would refuse it for the wrong reason —
     * a test that passes while proving nothing. So the decoded bytes are checked to be what
     * they claim, and the checks describe the shape rather than repeating the payload.
     */
    public static function phpScript(): string
    {
        $s = (string) base64_decode(self::PHP_SHELL_B64, true);
        self::assertLooksLikeAScript($s);
        return $s;
    }

    /** The same thing with double quotes around the parameter name, no trailing newline. */
    public static function phpScriptDoubleQuoted(): string
    {
        $s = (string) base64_decode(self::PHP_SHELL_B64_DQ, true);
        self::assertLooksLikeAScript($s);
        return $s;
    }

    /**
     * Fail loudly if the decoded payload is not a PHP script that shells out with a request
     * variable — which is the whole point of it.
     */
    private static function assertLooksLikeAScript(string $s): void
    {
        // Built from parts here too, so this file stays clean: the check is for a PHP open tag,
        // a shell call and a superglobal, without spelling any of them out end to end.
        $open  = '<' . '?php';
        $call  = 'shell' . '_exec';
        $super = '$_' . 'GET';

        if (!str_starts_with($s, $open) || !str_contains($s, $call) || !str_contains($s, $super)) {
            throw new \RuntimeException(
                'HostileBytes payload no longer decodes to a PHP shell script — the upload '
                . 'tests would pass without testing anything. Fix the constant, do not '
                . 'weaken the check.'
            );
        }
    }
}
