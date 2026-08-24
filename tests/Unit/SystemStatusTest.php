<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\SystemStatus;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The status page has to be capable of saying something other than "fine".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS GUARDING AGAINST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The page this replaced could not report a fault. Four of its six components were the
 * literal string 'Operational' with no branch that produced anything else, and the other
 * two checked whether an ENVIRONMENT VARIABLE NAME EXISTED — equally true of a revoked key,
 * a typo'd key and a key belonging to somebody else's account. The Twig template then
 * repeated the same six green rows as its own `|default(...)`, so even a route passing
 * nothing rendered a full board of health.
 *
 * A test that only checks the happy path would have passed against that page. So the
 * assertions here are almost all about the UNHAPPY paths: each check must be reachable in a
 * state that is not OK, "we could not check" must never be rounded up to "fine", and the
 * page must not be able to reintroduce a hard-coded fallback.
 */
final class SystemStatusTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string,array<string,mixed>> component keyed by name */
    private function byName(array $report): array
    {
        $out = [];
        foreach ($report['components'] as $c) $out[(string) $c['name']] = $c;
        return $out;
    }

    private function componentSaying(array $report, string $needle): ?array
    {
        foreach ($report['components'] as $c) {
            if (str_contains(strtolower((string) $c['name']), strtolower($needle))) return $c;
        }
        return null;
    }

    /** worst() is pure ranking and the one place a lie would be silent. */
    private function worst(array $components): string
    {
        $m = new \ReflectionMethod(SystemStatus::class, 'worst');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $components);
    }

    private function markCron(string $ranAt): void
    {
        DB::table('gates_cron_log')->insert([
            'job_name' => 'maintenance', 'ran_at' => $ranAt, 'status' => 'success',
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // SHAPE
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_report_has_every_field_the_page_renders(): void
    {
        $r = SystemStatus::report();

        foreach (['overall', 'overall_label', 'checked_at', 'components', 'note'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertNotSame([], $r['components']);

        foreach ($r['components'] as $c) {
            foreach (['name', 'what', 'status', 'label', 'detail', 'metric'] as $k) {
                $this->assertArrayHasKey($k, $c, 'component missing ' . $k);
            }
            $this->assertNotSame('', trim((string) $c['name']));
            $this->assertNotSame('', trim((string) $c['what']),
                'a row with no description is a row nobody can act on');
        }
    }

    public function test_every_status_is_one_of_the_four_and_carries_its_own_label(): void
    {
        foreach (SystemStatus::report()['components'] as $c) {
            $this->assertArrayHasKey((string) $c['status'], SystemStatus::LABELS,
                'invented status: ' . $c['status']);
            $this->assertSame(SystemStatus::LABELS[(string) $c['status']], $c['label'],
                'the label and the status disagree, so the page and the logic would too');
        }
    }

    /**
     * A timestamp, because a status page with no time on it is one nobody can tell is stale.
     */
    public function test_the_report_is_stamped_with_when_it_was_taken(): void
    {
        $r = SystemStatus::report();
        $this->assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~',
                                              (string) $r['checked_at']);
        $this->assertSame(SystemStatus::LABELS[$r['overall']], $r['overall_label']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // "NOT CHECKED" IS NOT "FINE"
    // ════════════════════════════════════════════════════════════════════════

    public function test_unknown_is_a_distinct_state_with_its_own_words(): void
    {
        $labels = SystemStatus::LABELS;

        $this->assertCount(4, array_unique($labels), 'four states, four different words');
        $this->assertNotSame($labels[SystemStatus::OK], $labels[SystemStatus::UNKNOWN],
            '"not checked" reading the same as "working" is the whole bug');
    }

    /**
     * UNKNOWN must rank ABOVE fine and BELOW a real problem.
     *
     * Above fine, or an unchecked component disappears into a green board. Below a real
     * problem, or one thing we could not measure would mask an outage we did.
     */
    public function test_unknown_outranks_ok_and_never_outranks_a_real_problem(): void
    {
        $c = fn(string $s): array => ['status' => $s];

        $this->assertSame(SystemStatus::UNKNOWN,
            $this->worst([$c(SystemStatus::OK), $c(SystemStatus::UNKNOWN)]));

        $this->assertSame(SystemStatus::DEGRADED,
            $this->worst([$c(SystemStatus::UNKNOWN), $c(SystemStatus::DEGRADED)]));

        $this->assertSame(SystemStatus::DOWN,
            $this->worst([$c(SystemStatus::UNKNOWN), $c(SystemStatus::DEGRADED), $c(SystemStatus::DOWN)]));

        $this->assertSame(SystemStatus::OK,
            $this->worst([$c(SystemStatus::OK), $c(SystemStatus::OK)]));
    }

    /** An empty board is not a healthy board. */
    public function test_the_overall_verdict_is_the_worst_row_and_not_an_average(): void
    {
        $r = SystemStatus::report();
        $ranks = [SystemStatus::OK => 0, SystemStatus::UNKNOWN => 1,
                  SystemStatus::DEGRADED => 2, SystemStatus::DOWN => 3];

        $worstRow = SystemStatus::OK;
        foreach ($r['components'] as $c) {
            if ($ranks[$c['status']] > $ranks[$worstRow]) $worstRow = $c['status'];
        }
        $this->assertSame($worstRow, $r['overall']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE CHECKS CAN ACTUALLY FAIL
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The single most important signal on a cPanel deployment.
     *
     * There is no worker process. The cron tick IS the worker: payment reconciliation,
     * automatic refunds, stand-offer expiry, questionnaire invitations and every queued
     * message run from it. When it stops, all of that stops and every other component still
     * looks perfectly healthy — which is precisely the outage the old page could not show.
     */
    public function test_a_schedule_that_has_never_run_is_reported_as_not_working(): void
    {
        DB::table('gates_cron_log')->truncate();

        $c = $this->componentSaying(SystemStatus::report(), 'Scheduled work');
        $this->assertNotNull($c);
        $this->assertSame(SystemStatus::DOWN, $c['status']);
        $this->assertStringContainsString('never run', strtolower((string) $c['detail']));
    }

    public function test_a_stalled_schedule_is_reported_as_not_working(): void
    {
        DB::table('gates_cron_log')->truncate();
        $this->markCron(date('Y-m-d H:i:s', strtotime('-3 days')));

        $c = $this->componentSaying(SystemStatus::report(), 'Scheduled work');
        $this->assertSame(SystemStatus::DOWN, $c['status']);
        $this->assertNotSame('', trim((string) $c['detail']),
            'a DOWN row with no sentence is a red light with no instruction');
    }

    public function test_a_schedule_that_ran_minutes_ago_is_reported_as_working(): void
    {
        DB::table('gates_cron_log')->truncate();
        $this->markCron(date('Y-m-d H:i:s', strtotime('-10 minutes')));

        $c = $this->componentSaying(SystemStatus::report(), 'Scheduled work');
        $this->assertSame(SystemStatus::OK, $c['status']);
    }

    /**
     * Payments must not read as fine merely because nobody has tried to pay.
     *
     * Quiet and broken produce the same row count. The difference is whether a provider is
     * configured at all, asked of the same list the checkout itself reads — not of an
     * environment variable's existence, which is what the old page mistook for a working
     * gateway.
     */
    public function test_payments_with_no_provider_and_no_traffic_are_not_reported_as_working(): void
    {
        DB::table('gates_donations')->truncate();

        $c = $this->componentSaying(SystemStatus::report(), 'Payments');
        $this->assertNotNull($c);
        $this->assertNotSame(SystemStatus::DEGRADED, $c['status']);
        // In the test environment no gateway is configured, so the honest answer is DOWN.
        $this->assertSame(SystemStatus::DOWN, $c['status'],
            'no configured provider means nothing can be paid for, quiet or not');
        $this->assertStringContainsString('payment provider', strtolower((string) $c['detail']));
    }

    /** Payments that are mostly failing are reported as failing. */
    public function test_failing_payments_are_reported(): void
    {
        DB::table('gates_donations')->truncate();
        for ($i = 0; $i < 10; $i++) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'Supporter ' . $i, 'donor_email' => 'd' . $i . '@example.test',
                'amount_naira' => 5000, 'status' => $i === 0 ? 'confirmed' : 'failed',
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
            ]);
        }

        $c = $this->componentSaying(SystemStatus::report(), 'Payments');
        $this->assertSame(SystemStatus::DOWN, $c['status']);
        $this->assertStringContainsString('nothing is lost', strtolower((string) $c['detail']),
            'somebody whose card was charged needs to be told what happens to the money');
    }

    public function test_healthy_payments_are_reported_as_working(): void
    {
        DB::table('gates_donations')->truncate();
        for ($i = 0; $i < 10; $i++) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'Supporter ' . $i, 'donor_email' => 'd' . $i . '@example.test',
                'amount_naira' => 5000, 'status' => 'confirmed',
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
            ]);
        }

        $this->assertSame(SystemStatus::OK,
                          $this->componentSaying(SystemStatus::report(), 'Payments')['status']);
    }

    /** Mail that is mostly bouncing is reported, and says what to do while waiting. */
    public function test_failing_email_is_reported(): void
    {
        DB::table('gates_mail_log')->truncate();
        for ($i = 0; $i < 10; $i++) {
            DB::table('gates_mail_log')->insert([
                'to_masked' => 'a***@example.test', 'subject' => 'Your code',
                'status' => $i < 8 ? 'failed' : 'sent',
                'created_at' => date('Y-m-d H:i:s', strtotime('-20 minutes')),
            ]);
        }

        $c = $this->componentSaying(SystemStatus::report(), 'Email');
        $this->assertSame(SystemStatus::DOWN, $c['status']);
        $this->assertStringContainsString('spam', strtolower((string) $c['detail']));
    }

    /** A queue whose head has been waiting is a queue that has stopped draining. */
    public function test_a_stale_queue_head_is_reported(): void
    {
        DB::table('gates_jobs')->truncate();
        DB::table('gates_jobs')->insert([
            'type' => 'mail.send', 'payload' => '{}', 'status' => 'pending',
            'run_after'  => date('Y-m-d H:i:s', strtotime('-5 hours')),
            'created_at' => date('Y-m-d H:i:s', strtotime('-5 hours')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-5 hours')),
        ]);

        $c = $this->componentSaying(SystemStatus::report(), 'Messages waiting');
        $this->assertNotNull($c);
        $this->assertSame(SystemStatus::DEGRADED, $c['status']);
        $this->assertStringContainsString('nothing is lost', strtolower((string) $c['detail']),
            'a delayed message is not a dropped one and the page must say so');
    }

    public function test_an_empty_queue_is_reported_as_working(): void
    {
        DB::table('gates_jobs')->truncate();

        $this->assertSame(SystemStatus::OK,
            $this->componentSaying(SystemStatus::report(), 'Messages waiting')['status']);
    }

    /**
     * The AI row is advisory, so it degrades and never goes DOWN.
     *
     * Every AI capability on this platform is declared advisory: nothing needs one to be
     * completed. A status page that shows a full red outage for a feature nobody needs is a
     * status page that teaches people to ignore the red on the row that matters.
     */
    public function test_the_ai_row_never_reports_a_full_outage(): void
    {
        DB::table('gates_ai_calls')->truncate();
        for ($i = 0; $i < 10; $i++) {
            DB::table('gates_ai_calls')->insert([
                'capability' => 'evidence.analyse', 'outcome' => 'ERROR',
                'created_at' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
            ]);
        }

        $c = $this->componentSaying(SystemStatus::report(), 'AI');
        $this->assertNotNull($c);
        $this->assertNotSame(SystemStatus::DOWN, $c['status'],
            'an advisory feature failing is not an outage of the platform');
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE PAGE MUST NOT BE ABLE TO INVENT AN ANSWER
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A status page that makes outbound requests is a status page that times out under
     * exactly the load it exists to report on — and it hands any visitor a way to make us
     * hammer our own providers, once per reload.
     */
    public function test_no_check_makes_an_outbound_request(): void
    {
        $src = (string) file_get_contents($this->root() . '/src/Services/SystemStatus.php');

        foreach (['curl_init', 'curl_exec', 'fsockopen', 'HttpClient', 'Guzzle',
                  "file_get_contents('http", 'file_get_contents("http'] as $needle) {
            $this->assertStringNotContainsString($needle, $src,
                'the status page must not call anything over the network: ' . $needle);
        }
    }

    /**
     * The template must not carry a fallback board of its own.
     *
     * The version this replaced hard-coded six 'Operational' rows as a Twig `|default(...)`,
     * so a route that passed nothing still rendered a full green page. Two independent
     * places were asserting health that neither had checked.
     */
    public function test_the_template_has_no_hard_coded_component_list(): void
    {
        $tpl = (string) file_get_contents($this->root() . '/templates/pages/status.twig');

        $this->assertStringNotContainsString('components|default', $tpl,
            'a defaulted component list is a board that renders green with no data');
        $this->assertStringNotContainsString("'status':'Operational'", $tpl);
        $this->assertStringContainsString('checked_at', $tpl,
            'an undated status board is one nobody can tell is stale');
    }

    /** And the route must delegate rather than build its own answer inline. */
    public function test_the_route_delegates_to_the_measured_report(): void
    {
        $routes = (string) file_get_contents($this->root() . '/src/routes.php');

        $this->assertStringContainsString('SystemStatus::report()', $routes);
        $this->assertStringNotContainsString("'name'=>'Voting & ballots'", $routes,
            'the inline hard-coded status board is back');
    }

    /**
     * The template must actually render the report, not merely be free of the old fiction.
     *
     * A controller and a template that have never been rendered together are two files that
     * happen to be in the same repository.
     */
    public function test_the_page_renders_the_measured_report(): void
    {
        DB::table('gates_cron_log')->truncate();

        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $twig = $b->build()->get(\Slim\Views\Twig::class);

        $report = SystemStatus::report();
        $html   = $twig->fetch('pages/status.twig', $report + [
            'page_title' => 'Is it working?', 'gates_page' => 'status', 'has_hero' => false,
        ]);

        foreach ($report['components'] as $c) {
            $this->assertStringContainsString((string) $c['name'], $html);
            $this->assertStringContainsString((string) $c['label'], $html,
                'the state must be printed as a WORD, not carried by colour alone');
        }
        $this->assertStringContainsString((string) $report['checked_at'], $html);

        // The dead schedule is in this render. If the page can still say everything is
        // working, it is the old page with new markup.
        $this->assertStringNotContainsString('Everything we can check is <em>working</em>', $html);
    }

    // ════════════════════════════════════════════════════════════════════════
    // "WAS IT BROKEN EARLIER?"
    // ════════════════════════════════════════════════════════════════════════
    //
    // Measuring at request time is what makes the live check honest, and it is also what
    // makes it blind: it says what is true this second and nothing about the two hours
    // somebody could not check out. Without a history, a supporter whose payment failed at
    // nine and who loads a green page at noon concludes the fault was theirs.

    private function snapshot(string $day, string $overall, int $hour = 3): void
    {
        DB::table('gates_status_log')->insert([
            'taken_at'        => $day . sprintf(' %02d:00:00', $hour),
            'overall'         => $overall,
            'components_json' => '[]',
            'created_at'      => $day . sprintf(' %02d:00:00', $hour),
        ]);
    }

    public function test_the_history_covers_the_whole_window_ending_today(): void
    {
        DB::table('gates_status_log')->truncate();
        $h = SystemStatus::history();

        $this->assertCount(SystemStatus::HISTORY_DAYS, $h);
        $this->assertSame(date('Y-m-d'), $h[count($h) - 1]['date'], 'the strip must end today');
    }

    /**
     * A day with nothing recorded is UNKNOWN, never OK.
     *
     * And on this deployment a gap usually IS the outage: the thing that writes these rows
     * is the scheduled task, so a missing day is evidence the task was not running — the
     * one failure no self-report can cover, because the reporter is what stopped.
     */
    public function test_a_day_with_no_snapshot_reports_unknown_and_never_working(): void
    {
        DB::table('gates_status_log')->truncate();
        $this->snapshot(date('Y-m-d'), SystemStatus::OK);

        foreach (SystemStatus::history() as $day) {
            if ($day['date'] === date('Y-m-d')) continue;
            $this->assertSame(SystemStatus::UNKNOWN, $day['status'],
                $day['date'] . ' had no snapshot and did not report as unchecked');
            $this->assertSame(0, $day['samples']);
        }
    }

    /**
     * Worst state wins within a day.
     *
     * A day with one broken hour is not a good day, and an average would turn a real outage
     * into a pale shade of green — which is the same rounding-up the whole page exists to
     * refuse.
     */
    public function test_one_bad_hour_makes_the_whole_day_bad(): void
    {
        DB::table('gates_status_log')->truncate();
        $today = date('Y-m-d');

        $this->snapshot($today, SystemStatus::OK, 1);
        $this->snapshot($today, SystemStatus::OK, 2);
        $this->snapshot($today, SystemStatus::DOWN, 3);
        $this->snapshot($today, SystemStatus::OK, 4);

        $last = SystemStatus::history()[SystemStatus::HISTORY_DAYS - 1];
        $this->assertSame(SystemStatus::DOWN, $last['status']);
        $this->assertSame(4, $last['samples']);
    }

    /** DEGRADED does not get promoted to DOWN, or demoted to fine. */
    public function test_a_degraded_day_reports_as_degraded(): void
    {
        DB::table('gates_status_log')->truncate();
        $today = date('Y-m-d');
        $this->snapshot($today, SystemStatus::OK, 1);
        $this->snapshot($today, SystemStatus::DEGRADED, 2);

        $this->assertSame(SystemStatus::DEGRADED,
            SystemStatus::history()[SystemStatus::HISTORY_DAYS - 1]['status']);
    }

    /** An empty history says so rather than reporting a fortnight of outage. */
    public function test_an_installation_with_no_record_says_so(): void
    {
        DB::table('gates_status_log')->truncate();
        $note = SystemStatus::historyNote(SystemStatus::history());

        $this->assertStringContainsString('Nothing has been recorded yet', $note);
        $this->assertStringNotContainsString('had a problem', $note,
            'a fresh installation must not be reported as a fortnight of outage');
    }

    /** The note counts against the days it actually has, not against the window. */
    public function test_the_note_counts_recorded_days_not_calendar_days(): void
    {
        DB::table('gates_status_log')->truncate();
        $this->snapshot(date('Y-m-d'), SystemStatus::DOWN);
        $this->snapshot(date('Y-m-d', strtotime('-1 day')), SystemStatus::OK);

        $note = SystemStatus::historyNote(SystemStatus::history());
        $this->assertStringContainsString('1 of the last 2 recorded days', $note);
        $this->assertStringContainsString('dashed square', $note,
            'a strip with gaps must explain what a gap means');
    }

    /** And it does not explain a dashed square on a strip that has none. */
    public function test_a_complete_strip_does_not_explain_a_square_it_does_not_show(): void
    {
        DB::table('gates_status_log')->truncate();
        for ($i = 0; $i < SystemStatus::HISTORY_DAYS; $i++) {
            $this->snapshot(date('Y-m-d', strtotime('-' . $i . ' days')), SystemStatus::OK);
        }

        $note = SystemStatus::historyNote(SystemStatus::history());
        $this->assertStringNotContainsString('dashed square', $note);
    }

    /** record() writes one row and never throws — it runs inside the maintenance tick. */
    public function test_recording_a_snapshot_writes_one_row_and_keeps_only_the_shape(): void
    {
        DB::table('gates_status_log')->truncate();

        $this->assertSame(1, SystemStatus::record());
        $row = DB::table('gates_status_log')->first();
        $this->assertNotNull($row);

        $parts = json_decode((string) $row->components_json, true);
        $this->assertIsArray($parts);
        $this->assertNotSame([], $parts);
        foreach ($parts as $p) {
            $this->assertSame(['name', 'status'], array_keys($p),
                'the prose is written for a reader NOW and would be quietly wrong in a week');
        }
    }

    public function test_snapshots_past_the_retention_window_are_dropped(): void
    {
        DB::table('gates_status_log')->truncate();
        $this->snapshot(date('Y-m-d', strtotime('-' . (SystemStatus::KEEP_DAYS + 5) . ' days')),
                        SystemStatus::OK);
        $this->snapshot(date('Y-m-d'), SystemStatus::OK);

        $this->assertSame(1, SystemStatus::prune());
        $this->assertSame(1, (int) DB::table('gates_status_log')->count());
    }

    /**
     * The recorder must be wired into the tick, and pruned beside the mail log.
     *
     * A recorder nobody calls is an empty table, and the page would show a fortnight of
     * "not recorded" forever. A recorder with no pruner is a table that grows for the life
     * of the deployment.
     */
    public function test_the_maintenance_run_records_and_prunes(): void
    {
        $src = (string) file_get_contents($this->root() . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('SystemStatus::record()', $src,
            'nothing writes the history, so the strip stays empty forever');
        $this->assertStringContainsString('SystemStatus::prune()', $src,
            'nothing trims the history, so the table grows without bound');
    }

    /** The note names the worst thing, because that is what a reader has ten seconds for. */
    public function test_the_note_names_a_real_problem_when_there_is_one(): void
    {
        DB::table('gates_cron_log')->truncate();

        $r = SystemStatus::report();
        $this->assertNotSame('', trim((string) $r['note']));
        $this->assertStringNotContainsString('Everything we can measure is working.',
                                             (string) $r['note'],
                                             'a dead schedule must not read as everything working');
    }
}
