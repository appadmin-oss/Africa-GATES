<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\AlertsController;
use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Support\TimeRange;
use AfricaGates\Admin\Support\TimeSeries;
use AfricaGates\Services\SystemStatus;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The Alerts page with the owner's screenshot features (13 Oct 2026): Severity and Source
 * chips, the range control, and the status timeline.
 */
final class AlertsPageTest extends TestCase
{
    private function page(string $query): string
    {
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = '/admin/alerts' . ($query !== '' ? '?' . $query : '');
        ConsoleAlerts::reset();

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        parse_str($query, $qp);
        $res = $b->build()->get(AlertsController::class)->index(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/alerts')->withQueryParams($qp),
            (new ResponseFactory())->createResponse());
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    /** Each slice takes the worst state in it; a slice with no record is a gap, never green. */
    public function test_the_timeline_is_worst_wins_and_a_gap_is_not_green(): void
    {
        $r = TimeRange::between(1_760_000_000, 1_760_000_000 + 4 * 3600);
        $at = static fn (int $s): string => date('Y-m-d H:i:s', 1_760_000_000 + $s);
        $strip = TimeSeries::worst([
            [$at(60), SystemStatus::OK], [$at(120), SystemStatus::DOWN], [$at(180), SystemStatus::DEGRADED],
            [$at(7200 + 60), SystemStatus::OK],
            [$at(7200 + 120), 'a state from the future'],
        ], $r, static fn (string $s): ?int => SystemStatus::rank($s));

        $states = array_column($strip['bars'], 'state');
        $first = (int) floor((1_760_000_000 - $strip['bars'][0]['from']) / $strip['step']);
        $this->assertSame(SystemStatus::DOWN, $states[$first], 'a down sample was averaged away');
        $this->assertContains(null, $states, 'an hour with no record was drawn as a state');
        $this->assertNotContains('a state from the future', $states, 'an unknown state was counted');
    }

    public function test_the_chips_offer_only_what_is_open_and_the_old_high_tab_still_filters(): void
    {
        $html = $this->page('');
        $this->assertStringContainsString('+</span> Severity', $html);
        $this->assertStringContainsString('+</span> Source', $html);
        $this->assertStringContainsString('Platform status', $html);

        // The pill and older links say ?tab=high; that is the Severity chip set to high.
        $high = $this->page('tab=high');
        if (ConsoleAlerts::high() !== []) {
            $this->assertStringContainsString('Remove filter: Severity High', $high);
        } else {
            $this->assertStringContainsString('No open alert matches those filters', $high);
        }
    }

    public function test_the_timeline_reads_the_status_record(): void
    {
        $now = time();
        foreach ([3, 2, 1] as $h) {
            DB::table('gates_status_log')->insert(['taken_at' => date('Y-m-d H:i:s', $now - $h * 3600),
                'overall' => $h === 2 ? SystemStatus::DOWN : SystemStatus::OK, 'components_json' => '[]',
                'created_at' => date('Y-m-d H:i:s')]);
        }
        $html = $this->page('range=24h');
        $this->assertMatchesRegularExpression('~class="al-tl__b al-tl__b--down"[^>]*aria-label="[^"]*: Not working"~', $html);
        $this->assertStringContainsString('al-tl__b--gap', $html, 'hours with no record are not shown as gaps');
    }
}
