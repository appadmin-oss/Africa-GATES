<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PhaseError;
use AfricaGates\Support\PublicFault;
use Tests\TestCase;

/**
 * WHAT A PERSON IS TOLD WHEN SOMETHING BREAKS.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO FAULTS, ONE FILE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. THE 500 PAGE PROMISED SOMETHING NOBODY DOES. "Our team has been notified and is on
 *    it." Nothing notifies anybody — the exception goes to `var/logs/error-detail.log`,
 *    whose only reader is a diagnostics route an operator has to remember to open, on a
 *    host with no shell and no alerting. Worse than merely false: telling somebody their
 *    problem is already being handled is the most effective way to stop them reporting it.
 *
 *    And the template had carried an `error_ref` block since it was written, which nothing
 *    ever set — so it had never rendered once. The page was built to show a reference and
 *    was never given one.
 *
 * 2. RAW EXCEPTION TEXT REACHED NON-ADMIN READERS. Three call sites on the partner-org
 *    dashboard caught `\Throwable` and printed `getMessage()`. Usually harmless, because
 *    the throw is ours and the words were written for a person — until something
 *    unexpected fails underneath and a partner organisation is shown our SQL.
 *
 * The class allowlist is necessary and NOT sufficient, which is the part worth testing:
 * Illuminate's QueryException is a PDOException is a RuntimeException, so an `instanceof`
 * test would wave through the one exception type that carries a whole SQL statement.
 */
final class PublicFaultTest extends TestCase
{
    // ══ whose words are these? ═══════════════════════════════════════════════

    public function test_our_own_copy_reaches_the_reader_unchanged(): void
    {
        foreach ([
            new \RuntimeException('File larger than 15MB'),
            new \InvalidArgumentException('Upload a PDF or an image.'),
            new \DomainException('That category is not open for voting.'),
            // Built through its own named constructor rather than by hand — the
            // message a reader sees is the one that factory writes.
            PhaseError::votingNotOpenYet(\AfricaGates\Services\CyclePhase::Nominations),
        ] as $e) {
            $out = PublicFault::explain($e, 'FALLBACK');
            $this->assertSame($e->getMessage(), $out['message'],
                $e::class . ' was suppressed, so a reader loses the one sentence that '
                . 'tells them what to do differently');
            $this->assertNull($out['reference'],
                'a reference was minted for a message that needed no explaining');
        }
    }

    /**
     * AND AN INSTANCEOF TEST WOULD HAVE LET THIS THROUGH.
     *
     * PDOException extends RuntimeException, and Illuminate's QueryException extends
     * PDOException carrying the entire statement — bound values, table names and all. The
     * check is on the exact class for exactly this.
     */
    public function test_a_database_failure_never_reaches_the_reader(): void
    {
        $sql = new \PDOException(
            'SQLSTATE[42S02]: Base table or view not found: 1146 Table '
            . "'africa_gates.gates_nominees' doesn't exist (Connection: mysql, SQL: select * "
            . 'from `gates_nominees` where `email` = seun@example.test)');

        $this->assertInstanceOf(\RuntimeException::class, $sql,
            'the premise of this test has changed — PDOException is no longer a '
            . 'RuntimeException, so re-check what else the allowlist now admits');

        $out = PublicFault::explain($sql, 'That did not save. Try once more.');
        $this->assertSame('That did not save. Try once more.', $out['message']);
        $this->assertNotNull($out['reference']);
        $this->assertStringNotContainsString('SQLSTATE', $out['message']);
        $this->assertStringNotContainsString('gates_nominees', $out['message']);
    }

    /**
     * NOR DOES ANYTHING THAT MERELY LOOKS LIKE MACHINERY.
     *
     * The class gate is not enough on its own: libraries throw bare RuntimeExceptions
     * carrying paths, driver strings and stack fragments, and those are ours by class and
     * nobody's by authorship.
     */
    public function test_our_own_class_still_cannot_carry_machinery(): void
    {
        foreach ([
            'Failed opening /var/www/africa-gates/src/Services/AiService.php',
            'Call to a member function id() on null',
            'cURL error 28: Operation timed out after 6001 milliseconds',
            'Undefined array key "openai_key"',
            'AfricaGates\Services\VoteService::castVote(): Argument #1 must be int',
            'SQLSTATE[HY000] general error',
        ] as $msg) {
            $out = PublicFault::explain(new \RuntimeException($msg), 'FALLBACK');
            $this->assertSame('FALLBACK', $out['message'],
                'this reached a reader: ' . $msg);
        }
    }

    /** Real copy for a person is short; a wall of text is a dump wearing a sentence. */
    public function test_an_overlong_message_is_withheld(): void
    {
        $this->assertSame('FALLBACK',
            PublicFault::explain(new \RuntimeException(str_repeat('word ', 60)), 'FALLBACK')['message']);
        $this->assertSame('FALLBACK',
            PublicFault::explain(new \RuntimeException('   '), 'FALLBACK')['message'],
            'an empty message rendered as an empty error, which tells a reader nothing');
    }

    /** A PHP Error is never ours, whatever it says. */
    public function test_an_engine_error_is_never_shown(): void
    {
        foreach ([new \TypeError('bad type'), new \Error('boom'),
                  new \DivisionByZeroError('Division by zero')] as $e) {
            $this->assertFalse(PublicFault::isOurs($e), $e::class);
        }
    }

    // ══ the reference ════════════════════════════════════════════════════════

    /**
     * TYPEABLE, AND UNAMBIGUOUS READ ALOUD.
     *
     * This gets read down a phone line and typed into a form by somebody who is already
     * annoyed. O/0, I/1/L and U are out — the last because it is what turns a random
     * string into a word somebody then reports having seen.
     */
    public function test_a_reference_cannot_be_misread(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $ref = PublicFault::reference();
            $this->assertMatchesRegularExpression('~^[2-9A-Z]{4}-[2-9A-Z]{4}$~', $ref, $ref);
            $this->assertSame(0, preg_match('~[OIL1U0]~', $ref),
                'a reference contains a character that is misread when spoken: ' . $ref);
        }
    }

    /** Two people hitting one bug must be distinguishable. */
    public function test_references_are_not_derived_from_the_fault(): void
    {
        $e = new \PDOException('the same failure twice');
        $this->assertNotSame(
            PublicFault::explain($e, 'x')['reference'],
            PublicFault::explain($e, 'x')['reference'],
            'two reports of one bug share a reference, so support cannot tell them apart');
    }

    /**
     * AND THE REFERENCE IS ON THE LOG LINE, FIRST.
     *
     * This is the whole point: quoting it has to find the stack trace without anybody
     * knowing the date, the class or the route it came from. A reference shown to a reader
     * and absent from the log is worse than none — it looks like a handle and is not.
     */
    public function test_the_reference_is_written_where_it_can_be_found(): void
    {
        $log = dirname(__DIR__, 2) . '/var/logs/error-detail.log';
        $before = is_file($log) ? (int) filesize($log) : 0;

        $ref = PublicFault::record(
            new \PDOException('SQLSTATE[42S02] table missing'), 'GET /vote/17');

        $this->assertTrue(is_file($log), 'nothing was written, so the reference is a dead handle');
        $written = (string) file_get_contents($log);
        $this->assertStringContainsString('[ref ' . $ref . ']', $written);
        $this->assertStringContainsString('GET /vote/17', $written,
            'the log does not say which surface failed');
        $this->assertStringContainsString('SQLSTATE[42S02] table missing', $written,
            'the detail was withheld from the log as well as from the reader, so the '
            . 'reference leads nowhere');
        $this->assertGreaterThan($before, (int) filesize($log));
    }

    /** Logging cannot become the failure. */
    public function test_a_reference_is_returned_even_if_nothing_can_be_written(): void
    {
        $this->assertMatchesRegularExpression('~^[2-9A-Z]{4}-[2-9A-Z]{4}$~',
            PublicFault::reference());
    }

    // ══ the one-liner the call sites use ═════════════════════════════════════

    /**
     * NO NON-ADMIN CONTROLLER HANDS A RAW EXCEPTION TO A READER.
     *
     * The three org-dashboard sites were found by reading; this is how the fourth gets
     * found.
     *
     * ── WHY IT NAMES SINKS RATHER THAN SCANNING FOR getMessage() ────────────
     *
     * The first version flagged any `getMessage()` on a line that also mentioned "error"
     * or "message", and produced four false positives on its first run: two loggers (one
     * of them a multi-line array, so a same-line logger test missed it) and two internal
     * notes that go onto the nomination RECORD an operator reads, not into a response.
     *
     * A sweep that cries wolf gets an exclusion list bolted to it and then gets deleted.
     * So this asks the narrow question it actually means — does the message reach a
     * RESPONSE? — by naming the four places a response is built here. A logger is not a
     * sink and neither is a stored note.
     *
     * Scoped to `src/Controllers`: an ADMIN flash showing `getMessage()` is correct and
     * deliberate, because an operator is exactly who should see the detail.
     */
    public function test_no_public_controller_prints_a_raw_exception_to_a_reader(): void
    {
        // The four ways a controller here answers a person.
        $sinks = [
            '~\$_SESSION\[[^\]]*flash[^\]]*\]\s*=~i' => 'a flash message',
            '~\$this->err\(~'                            => 'an API error body',
            '~\$rerender\(~'                             => 're-rendering a form',
            '~->getBody\(\)->write\(~'                    => 'a written response body',
        ];

        $found = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') ?: [] as $file) {
            // Comments stripped: every note left by this fix quotes the line it replaced.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
                (string) file_get_contents($file));

            foreach ($sinks as $re => $what) {
                if (!preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE)) continue;
                foreach ($m[0] as [$hit, $at]) {
                    // The statement this sink starts: up to the next semicolon.
                    $end  = strpos($code, ';', $at);
                    $stmt = substr($code, $at, ($end === false ? 400 : $end - $at));
                    if (!str_contains($stmt, 'getMessage()')) continue;
                    if (str_contains($stmt, 'PublicFault::')) continue;
                    // Mapped to fixed copy rather than printed.
                    if (str_contains($stmt, 'str_contains($e->getMessage()')) continue;

                    $found[] = basename($file) . ' — ' . $what . ': '
                             . trim(preg_replace('~\s+~', ' ', substr($stmt, 0, 120)));
                }
            }
        }

        $this->assertSame([], $found,
            "A public controller hands a reader whatever failed underneath. Route it "
          . "through PublicFault::line() — our own copy still shows verbatim, machinery "
          . "becomes a next step and a reference:\n  " . implode("\n  ", $found));
    }

    public function test_the_line_tells_the_reader_what_to_do_with_the_reference(): void
    {
        $ours = PublicFault::line(new \RuntimeException('File larger than 15MB'), 'FALLBACK');
        $this->assertSame('File larger than 15MB', $ours,
            'a reference was appended to a message that already says what to do');

        $theirs = PublicFault::line(new \PDOException('SQLSTATE[HY000]'),
            'That file could not be uploaded.');
        $this->assertStringStartsWith('That file could not be uploaded.', $theirs);
        $this->assertStringContainsString('quote', $theirs,
            'the reference is shown without saying what it is for, which is a serial '
            . 'number rather than a next step');
        $this->assertStringNotContainsString('SQLSTATE', $theirs);
    }
}
