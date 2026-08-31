<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\PaymentsTriageController;
use AfricaGates\Services\GatewayEventLog;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * "Has a payment notification ever reached us?" — asked where somebody can read it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see GatewayEventLog::everReceived()} describes itself as "the single most useful
 * diagnostic on this platform", and names the harm precisely: an empty table after a
 * week of live payments means the webhook URL or the signing secret is wrong, "which
 * presents to a buyer as 'I paid and nothing happened' and to an operator as nothing
 * at all."
 *
 * It had no caller. The diagnostic was computed by nobody and shown nowhere — §20 in
 * one method, and the one whose own docblock states the consequence most plainly.
 *
 * ── AND THE HALF THE BARE QUESTION MISSES ────────────────────────────────────
 *
 * "Never" is the loud failure. The quiet one is a feed that worked for months and
 * stopped, because somebody rotated the signing secret in the dashboard: that site
 * HAS received deliveries, so the bare question answers yes and everything looks
 * well. The date is what makes it visible, which is why health() carries it — and
 * why `ever` still comes from everReceived() rather than being re-derived.
 */
final class GatewayFeedHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['gateway_ledger']);
        parent::tearDown();
    }

    private function delivery(string $at, string $outcome = 'ok'): void
    {
        DB::table('gates_gateway_events')->insert([
            'provider'   => 'paystack',
            'event'      => 'charge.success',
            'reference'  => 'AGP-' . substr(md5($at . $outcome), 0, 10),
            'outcome'    => $outcome,
            'created_at' => $at,
        ]);
    }

    /** The screen, as an operator opens it. */
    private function screen(): string
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(PaymentsTriageController::class);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/payments/ledger');
        $res = $ctrl->ledger($req, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $res->getStatusCode(),
            'the ledger screen redirected instead of rendering');

        return (string) $res->getBody();
    }

    // ══ the reading ══════════════════════════════════════════════════════════

    public function test_a_platform_that_has_never_heard_from_the_gateway_says_so(): void
    {
        $h = GatewayEventLog::health();

        $this->assertFalse($h['ever']);
        $this->assertNull($h['days_since']);
        $this->assertSame('', $h['last_at']);
    }

    /**
     * A rejected delivery is not a delivery. Signature failures arrive on a site whose
     * secret is wrong, so counting them would answer "yes, we are hearing from Paystack"
     * on exactly the site the diagnostic exists to catch.
     */
    public function test_a_rejected_delivery_does_not_count_as_having_heard_from_them(): void
    {
        $this->delivery(Carbon::now()->toDateTimeString(), 'rejected');

        $this->assertFalse(GatewayEventLog::health()['ever'],
            'a bad-signature delivery was read as a working webhook feed');
    }

    public function test_a_live_feed_reports_when_it_last_heard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));
        $this->delivery('2026-06-08 09:00:00');
        $this->delivery('2026-06-10 08:00:00');

        $h = GatewayEventLog::health();

        $this->assertTrue($h['ever']);
        $this->assertSame(0, $h['days_since'], 'the newest delivery is the one that matters');
        $this->assertStringStartsWith('2026-06-10', $h['last_at']);
    }

    /** THE QUIET FAILURE. Worked for months, then stopped. */
    public function test_a_feed_that_stopped_is_measured_in_days_not_in_yes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));
        $this->delivery('2026-04-02 09:00:00');

        $h = GatewayEventLog::health();

        $this->assertTrue($h['ever'], 'it really did work once');
        $this->assertSame(69, $h['days_since'],
            'a feed silent since April reported only that it had worked at some point');
    }

    // ══ the screen ═══════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. The diagnostic is on a page, not in a method.
     *
     * With no reader, the failure it detects is invisible from both ends at once —
     * the buyer sees a payment that did nothing, and the operator sees no signal
     * whatsoever that anything is wrong.
     */
    public function test_the_ledger_screen_says_no_notification_has_ever_arrived(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString('No payment notification has ever reached this platform', $html);
        $this->assertStringContainsString('signing secret', $html,
            'the operator is told something is wrong and not what to go and check');
    }

    /**
     * And does not cry wolf on a site that has simply not sold anything. A diagnostic
     * that alarms on every fresh install is one operators learn to scroll past, which
     * costs exactly the sites it was written for.
     */
    public function test_it_says_plainly_that_no_sales_looks_the_same(): void
    {
        $this->assertStringContainsString('has not sold anything yet this', $this->screen());
    }

    public function test_a_live_feed_reads_as_live_on_the_screen(): void
    {
        $this->delivery(Carbon::now()->toDateTimeString());

        $html = $this->screen();

        $this->assertStringContainsString('arrived today', $html);
        $this->assertStringNotContainsString('has ever reached this platform', $html);
    }

    public function test_a_long_gap_is_flagged_on_the_screen(): void
    {
        $this->delivery(Carbon::now()->subDays(40)->toDateTimeString());

        $html = $this->screen();

        $this->assertStringContainsString('40 days ago', $html);
        $this->assertStringContainsString('rotated in the dashboard', $html,
            'a silent fortnight and a rotated secret look identical from here, and the screen must say so');
    }

    // ══ §20: one resolver ════════════════════════════════════════════════════

    /**
     * `ever` comes from everReceived(), not from a second query written beside it.
     * Two readers of one fact is how the halves of a diagnostic come to disagree —
     * and this one is a diagnostic about disagreement.
     */
    public function test_the_health_reading_is_not_a_second_copy_of_the_question(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/GatewayEventLog.php');
        $body = substr($src, (int) strpos($src, 'public static function health()'));

        $this->assertStringContainsString('self::everReceived()', $body,
            'health() re-derives what everReceived() already answers');
    }
}
