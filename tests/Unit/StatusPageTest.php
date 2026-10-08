<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PlannedWork;
use AfricaGates\Services\StatusAlert;
use AfricaGates\Services\SystemStatus;
use Carbon\Carbon;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * /status as a page (Phase 9, StatusPageV2) — the rules the destroyed template carried in its
 * header, now asserted against what the rebuilt one RENDERS rather than what it says.
 *
 *   · No defaults: with no report the page says it could not check; it never invents rows.
 *   · Every state is a WORD and a FILL, never a hue alone; "Not checked" is drawn dashed.
 *   · "Happening now" is the measured timeline (SystemStatus incidents' `steps`).
 *   · Planned work shows from a week before until it ends, then never (PlannedWork).
 *   · "Get updates" exists only where mail can leave, and it is double opt-in (StatusAlert):
 *     nothing reaches an unconfirmed address, and a stop is one click with no account.
 */
final class StatusPageTest extends TestCase
{
    private const NOW = '2026-06-10 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        DB::table('gates_status_log')->delete();
        DB::table('gates_status_alerts')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function snap(int $minutesAgo, array $parts): void
    {
        $rank = ['operational' => 0, 'unknown' => 1, 'degraded' => 2, 'down' => 3];
        $ov = 'operational';
        foreach ($parts as $s) if ($rank[$s] > $rank[$ov]) $ov = $s;
        DB::table('gates_status_log')->insert([
            'taken_at'        => Carbon::now()->subMinutes($minutesAgo)->toDateTimeString(),
            'overall'         => $ov,
            'components_json' => (string) json_encode(array_map(
                static fn (string $n, string $s): array => ['name' => $n, 'status' => $s],
                array_keys($parts), array_values($parts))),
            'created_at'      => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** @param array<string,mixed> $over */
    private function render(array $over = []): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $twig = $b->build()->get(Twig::class);
        $report = SystemStatus::report();
        $tl     = SystemStatus::timeline();
        return $twig->fetch('pages/status.twig', $over + $report + [
            'history'           => $tl['days'],
            'history_note'      => SystemStatus::historyNote($tl['days']),
            'history_days'      => SystemStatus::HISTORY_DAYS,
            'component_history' => $tl['components'],
            'incidents'         => $tl['incidents'],
            'uptime'            => $tl['uptime'],
            'status_labels'     => SystemStatus::LABELS,
            'page_title'        => 'Is it working?',
            'gates_page'        => 'status',
            'planned'           => null,
            'can_subscribe'     => false,
            'subscribe_said'    => null,
        ]);
    }

    // ── no defaults ──────────────────────────────────────────────────────────

    public function test_with_no_report_the_page_says_so_and_invents_no_rows(): void
    {
        $html = $this->render(['components' => []]);
        $this->assertStringContainsString('We could not check', $html);
        $this->assertStringNotContainsString('class="sx-row"', $html);
        $this->assertStringNotContainsString('Get updates', $html);
    }

    public function test_every_row_names_its_state_in_words_and_its_group(): void
    {
        $html = $this->render();
        foreach (SystemStatus::report()['components'] as $c) {
            $this->assertStringContainsString('>' . htmlspecialchars($c['name'], ENT_QUOTES) . '<', $html);
            $this->assertStringContainsString('>' . htmlspecialchars($c['label'], ENT_QUOTES) . '<', $html);
            $this->assertStringContainsString('>' . htmlspecialchars($c['group'], ENT_QUOTES) . '<', $html);
        }
    }

    public function test_a_day_with_no_record_is_drawn_unknown_and_says_so(): void
    {
        $this->snap(30, ['Payments' => 'operational']);
        $html = $this->render(['components' => [['group' => 'Giving', 'name' => 'Payments', 'what' => 'x', 'status' => 'operational',
                                                  'label' => 'Working', 'detail' => '', 'metric' => '']]]);
        // Fourteen squares, thirteen of them with no record: dashed, and worded as such.
        $this->assertSame(SystemStatus::HISTORY_DAYS, substr_count($html, 'class="sx-cell '));
        $this->assertSame(SystemStatus::HISTORY_DAYS - 1, substr_count($html, 'sx-cell st-m--unknown'));
        $this->assertStringContainsString('· Not recorded"', $html);
        // The strip as a whole is one labelled image, so the summary reaches a screen reader.
        $this->assertMatchesRegularExpression('~class="sx-cells" role="img"\s+aria-label="Payments over the last 14 days: ~', $html);
    }

    // ── happening now ────────────────────────────────────────────────────────

    public function test_an_open_problem_is_shown_with_what_the_checks_saw(): void
    {
        $this->snap(120, ['Email' => 'operational']);
        $this->snap(90, ['Email' => 'degraded']);
        $this->snap(60, ['Email' => 'down']);
        $this->snap(5, ['Email' => 'down']);
        $html = $this->render();
        $this->assertStringContainsString('Happening now', $html);
        $this->assertSame(3, substr_count($html, 'class="sx-steps__i"'), 'first seen, the change, and the latest check');
        $this->assertStringContainsString('Latest check', $html);
        $this->assertStringContainsString('First seen', $html);
    }

    public function test_nothing_open_means_no_happening_now(): void
    {
        $this->snap(120, ['Email' => 'down']);
        $this->snap(90, ['Email' => 'down']);
        $this->snap(5, ['Email' => 'operational']);
        $html = $this->render();
        $this->assertStringNotContainsString('Happening now', $html);
        $this->assertStringContainsString('Past problems', $html);
        $this->assertStringContainsString('class="sx-inc__i"', $html);
    }

    // ── planned work ─────────────────────────────────────────────────────────

    public function test_planned_work_shows_in_its_window_and_never_after(): void
    {
        $set = static fn (string $from, string $to): array => [
            PlannedWork::KEYS['title'] => 'Card payments maintenance', PlannedWork::KEYS['from'] => $from,
            PlannedWork::KEYS['to'] => $to, PlannedWork::KEYS['note'] => ''];
        $this->assertNotNull(PlannedWork::current($set('2026-06-12 01:00:00', '2026-06-12 02:00:00')));
        $this->assertNull(PlannedWork::current($set('2026-06-30 01:00:00', '2026-06-30 02:00:00')), 'over a week away');
        $this->assertNull(PlannedWork::current($set('2026-06-09 01:00:00', '2026-06-09 02:00:00')), 'already over');
        $this->assertNull(PlannedWork::current($set('2026-06-12 02:00:00', '2026-06-12 01:00:00')), 'ends before it starts');
        $this->assertTrue(PlannedWork::current($set('2026-06-10 11:00:00', '2026-06-10 13:00:00'))['started']);

        $html = $this->render(['planned' => PlannedWork::current($set('2026-06-12 01:00:00', '2026-06-12 02:00:00'))]);
        $this->assertStringContainsString('Planned: Card payments maintenance', $html);
        $this->assertStringNotContainsString('Planned:', $this->render());
    }

    public function test_a_t_separated_time_from_the_form_is_stored_with_a_space(): void
    {
        $out = PlannedWork::fromPost([PlannedWork::KEYS['from'] => '2026-06-12T01:00']);
        $this->assertSame('2026-06-12 01:00:00', $out[PlannedWork::KEYS['from']]);
    }

    // ── get updates ──────────────────────────────────────────────────────────

    public function test_get_updates_is_offered_only_where_mail_can_leave(): void
    {
        $this->assertStringNotContainsString('action="/status/subscribe"', $this->render(['can_subscribe' => false]));
        $html = $this->render(['can_subscribe' => true]);
        $this->assertStringContainsString('action="/status/subscribe"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertFalse(StatusAlert::senderReady(null));
    }

    public function test_subscribing_is_double_opt_in_and_a_stop_is_one_step(): void
    {
        $sent = [];
        $send = static function (string $to, string $s, string $h, string $p) use (&$sent): bool { $sent[] = $p; return true; };
        $r = StatusAlert::want('reader@africa-gates-test.org', 'ip', 'https://site.test', $send);
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $sent, 'one confirmation');
        $row = DB::table('gates_status_alerts')->first();
        $this->assertNull($row->confirmed_at);
        $this->assertStringContainsString('/status/alerts/' . $row->token . '/confirm', $sent[0]);

        // A second ask the same day sends nothing more, and answers the same sentence.
        $r2 = StatusAlert::want('reader@africa-gates-test.org', 'ip', 'https://site.test', $send);
        $this->assertSame($r['message'], $r2['message']);
        $this->assertCount(1, $sent);

        // Unconfirmed: a problem goes to nobody.
        $news = [['name' => 'Email', 'status' => 'down', 'from' => Carbon::now()->subMinutes(30)->toDateTimeString(),
                  'to' => Carbon::now()->toDateTimeString(), 'minutes' => 30, 'checks' => 3, 'ongoing' => true, 'duration' => '30 min']];
        $mailer = new class extends \AfricaGates\Services\OtpService {
            public array $to = [];
            public function __construct() {}
            public function sendBranded(...$a): array { $this->to[] = $a[0]; return ['success' => true]; }
        };
        $this->assertSame(0, StatusAlert::sweep($mailer, 'https://site.test', $news));
        $this->assertSame([], $mailer->to, 'nothing may be sent to an unconfirmed address');

        $this->assertNotNull(StatusAlert::confirm((string) $row->token)->confirmed_at);
        $this->assertSame(1, StatusAlert::sweep($mailer, 'https://site.test', $news), 'confirmed: told once');
        $this->assertSame(0, StatusAlert::sweep($mailer, 'https://site.test', $news), 'and never twice for one event');
        $this->assertNotNull(StatusAlert::stop((string) $row->token)->cancelled_at);
        $this->assertNull(StatusAlert::find('not-a-token'));
    }

    public function test_one_failing_check_is_not_news_and_an_old_fix_is_not_sent(): void
    {
        $at = static fn (int $m): string => Carbon::now()->subMinutes($m)->toDateTimeString();
        $once  = ['name' => 'Email', 'status' => 'down', 'from' => $at(5), 'to' => $at(5), 'checks' => 1, 'ongoing' => true];
        $old   = ['name' => 'Email', 'status' => 'down', 'from' => $at(600), 'to' => $at(500), 'checks' => 4, 'ongoing' => false];
        $fresh = ['name' => 'Email', 'status' => 'down', 'from' => $at(60), 'to' => $at(10), 'checks' => 4, 'ongoing' => false];
        $this->assertSame([], StatusAlert::news([$once, $old]));
        $n = StatusAlert::news([$fresh]);
        $this->assertCount(1, $n);
        $this->assertSame('fixed', $n[0]['kind']);
    }

    public function test_the_alert_links_show_on_get_and_act_on_post(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $this->assertMatchesRegularExpression("~map\(\['GET', 'POST'\], '/status/alerts/\{token:\[a-f0-9\]\{32\}\}/\{action:confirm\|stop\}'~", $routes);
        $this->assertMatchesRegularExpression("~getMethod\(\) === 'POST'\) \{ \\\$row = \\\$action === 'stop'~", $routes);
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/status-alert.twig');
        $this->assertSame(2, substr_count($tpl, 'method="post"'), 'the confirm and the stop are each a form, never a GET');
    }
}
