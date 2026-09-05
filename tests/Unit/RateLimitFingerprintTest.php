<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\RateLimitService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A rate-limit key wider than the column it is stored in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FAILED THE WRONG WAY ROUND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_rate_limits.fingerprint` is VARCHAR(64) on MySQL. A sha256 hex digest is exactly
 * 64, so any caller that concatenates anything onto one — an IP hash plus a token, say —
 * overflows by precisely the amount it added.
 *
 * `check()` opens with an INSERT and treats a failure as "the row already exists",
 * falling through to a conditional increment. That is right for the collision it was
 * written for and wrong for this one: a strict-mode length refusal is not a duplicate
 * key, the increment matches nothing, and the method reports OVER THE LIMIT.
 *
 * A limiter that fails closed sounds safe. It is not, when the thing being limited is a
 * REPORT: every report of an abusive vote message was refused on production, always, and
 * the reporter was thanked — deliberately, so a brigade cannot map the ceiling. The one
 * route for getting a message about a named person in front of a moderator did not work
 * and could not be seen not to work.
 *
 * SQLite declares the column TEXT, so the suite was green throughout.
 */
final class RateLimitFingerprintTest extends TestCase
{
    /**
     * The width is asserted from the MIGRATION rather than written down here, so this
     * test follows the column instead of describing a copy of it.
     */
    private function declaredWidth(): int
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        return preg_match('/fingerprint\s+VARCHAR\((\d+)\)/i', $sql, $m) ? (int) $m[1] : 64;
    }

    /** The real shape that broke: sha256 + a separator + a share token. */
    public function test_a_key_longer_than_the_column_still_limits(): void
    {
        $svc = new RateLimitService();
        $fp  = hash('sha256', '41.58.1.1') . '|' . bin2hex(random_bytes(16));

        $this->assertGreaterThan($this->declaredWidth(), strlen($fp),
            'this fixture is meant to be too wide for the column');

        // First call inside the limit, second over it. Before the fold, the FIRST already
        // came back false — the insert was refused and read as a collision.
        $this->assertTrue($svc->check($fp, 'vmsg_report', 1, 86400),
            'a report was refused before anybody had made one');
        $this->assertFalse($svc->check($fp, 'vmsg_report', 1, 86400),
            'the limit stopped applying once the key was folded');
    }

    /** And what is stored fits, so MySQL has nothing to refuse. */
    public function test_what_is_stored_fits_the_column(): void
    {
        (new RateLimitService())->check(
            hash('sha256', 'x') . '|' . str_repeat('t', 40), 'probe', 5, 60);

        $stored = (string) DB::table('gates_rate_limits')->value('fingerprint');
        $this->assertNotSame('', $stored);
        $this->assertLessThanOrEqual($this->declaredWidth(), strlen($stored),
            'the row written is wider than the column that holds it');
    }

    /** Two different long keys must not fold onto each other. */
    public function test_two_long_keys_are_still_two_limits(): void
    {
        $svc = new RateLimitService();
        $a = hash('sha256', 'a') . '|' . str_repeat('1', 40);
        $b = hash('sha256', 'b') . '|' . str_repeat('2', 40);

        $this->assertTrue($svc->check($a, 'vmsg_report', 1, 86400));
        $this->assertTrue($svc->check($b, 'vmsg_report', 1, 86400),
            'one reporter used up another reporter\'s allowance');
    }

    /** A short readable key is left exactly as it was — existing rows keep working. */
    public function test_a_short_key_is_not_rewritten(): void
    {
        (new RateLimitService())->check('pass:15', 'door_scan', 5, 60);

        $this->assertSame('pass:15', (string) DB::table('gates_rate_limits')->value('fingerprint'));
    }
}
