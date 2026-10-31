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
        // AdminConsole v2 (20 Oct 2026): severity chips with counts, drawn only for a
        // severity that has an open alert (or is the one selected).
        $html = $this->page('');
        $open = ConsoleAlerts::open();
        foreach ([ConsoleAlerts::HIGH, ConsoleAlerts::MEDIUM, ConsoleAlerts::LOW] as $sev) {
            $n = count(array_filter($open, static fn (array $a): bool => $a['severity'] === $sev));
            $chip = 'al2-sev-dot--' . $sev . '" aria-hidden="true"></span>' . ucfirst($sev) . '<span class="al2-chip__num">' . $n . '</span>';
            $n > 0 ? $this->assertStringContainsString($chip, $html) : $this->assertStringNotContainsString($chip, $html);
        }

        // The pill and older links say ?tab=high; that is the High chip, pressed.
        $high = $this->page('tab=high');
        $this->assertMatchesRegularExpression('~href="[^"]*" aria-pressed="true">\s*<span class="al2-sev-dot al2-sev-dot--high"~', $high);
    }

    public function test_the_timeline_reads_the_status_record(): void
    {
        $now = time();
        foreach ([30, 18, 6] as $h) {
            DB::table('gates_status_log')->insert(['taken_at' => date('Y-m-d H:i:s', $now - $h * 3600),
                'overall' => $h === 18 ? SystemStatus::DOWN : SystemStatus::OK,
                'components_json' => json_encode([['name' => 'Payments', 'status' => $h === 18 ? SystemStatus::DOWN : SystemStatus::OK, 'label' => 'x']]),
                'created_at' => date('Y-m-d H:i:s')]);
        }
        $html = $this->page('');
        // Worst wins in a twelve-hour block, and a block with no record is a gap, never green.
        $this->assertMatchesRegularExpression('~class="al2-block al2-block--red" title="[^"]*: down"~', $html);
        $this->assertStringContainsString('al2-block al2-block--none', $html, 'blocks with no record are not shown as gaps');
    }
}
