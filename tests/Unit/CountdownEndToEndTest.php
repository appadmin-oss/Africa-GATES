<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteReminders;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\VisitTracker;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The whole countdown, run day by day, and the screens that report it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS ALONGSIDE THE UNIT TESTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every piece of this is tested in isolation: the marks, the letters, the schedule, the
 * dedupe. None of that answers the question the operator is actually asking, which is
 * whether a person invited on Monday receives five different letters over five days and
 * then stops.
 *
 * Two failures live only at that seam. A letter can be correct and arrive twice, because
 * the dedupe is per (event, mark) and the mark is chosen from a clock. And a letter can
 * be correct and never arrive at all, because the window that selects it closed a day
 * before the sweep ran. Neither is visible from a test that sends one letter.
 *
 * ── AND THE SCREENS, RENDERED ────────────────────────────────────────────────
 *
 * `TemplateSyntaxTest` compiles every template; it does not run one. Twig renders an
 * undefined variable as empty rather than raising, so a controller that forgets to pass
 * `arrivals.sections` produces a page that is silently missing a table and a suite that
 * stays green. That is the same shape as the `{% set %}`-inside-`{% block %}` trap this
 * codebase has shipped twice, arrived at from the controller side.
 */
final class CountdownEndToEndTest extends TestCase
{
    private int $eventId = 0;
    private object $event;
    private Carbon $night;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        // A fixed evening, so every "now" below is set relative to something that does not
        // move while the test runs. A fixture built from Carbon::now() at each step drifts
        // across midnight on a slow suite and fails on nothing.
        $this->night = Carbon::parse('2026-12-12 18:00:00');

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'The Incredible Principal Awards 2026',
            'tagline' => 'Accountability and Responsibility',
            'event_date' => $this->night->toDateTimeString(), 'status' => 'published',
            'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
        EventInvites::setProgrammes($this->eventId, [$pid]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $this->eventId, 'slug' => 'supporter', 'name' => 'Supporter',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->event = DB::table('gates_site_events')->where('id', $this->eventId)->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An invitation that has been sent — the only kind a countdown follows. */
    private function guest(string $audience, string $email): void
    {
        $inv = EventInvites::mint($this->eventId, $audience,
            ['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
             'nominee_id' => 0, 'judge_id' => 0]);

        DB::table('gates_event_invites')->where('id', $inv->id)
            ->update(['sent_at' => $this->night->copy()->subDays(30)->toDateTimeString()]);
    }

    private function recorder(): OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25, 'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function smtpConfigured(): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody];

                return ['success' => true];
            }
        };
    }

    // ══ THE FIVE DAYS ═══════════════════════════════════════════════════════

    /**
     * Five days, five letters each, no repeats and nothing after the night.
     *
     * The two failures that live only at this seam: a letter that is correct and arrives
     * twice, and a letter that is correct and never arrives because the window selecting
     * it closed a day before the sweep ran.
     */
    public function test_a_nominee_and_a_judge_each_get_five_letters_and_then_it_stops(): void
    {
        $this->guest(InviteAudience::NOMINEE, 'nominee@example.com');
        $this->guest(InviteAudience::JUDGE, 'judge@example.com');

        $mailer = $this->recorder();
        $byDay  = [];

        // The run as it really happens: one sweep a day, morning of, for a fortnight
        // either side of the evening. Sweeping only on the five days that matter would
        // assume the answer.
        for ($offset = 10; $offset >= -3; $offset--) {
            Carbon::setTestNow($this->night->copy()->subDays($offset)->setTime(9, 0));
            $before = count($mailer->sent);
            InviteReminders::sweep($mailer, 100);
            $byDay[$offset] = array_slice($mailer->sent, $before);
        }

        // Nothing before the arc opens, nothing after the night.
        foreach ([10, 9, 8, 7, 6, 0, -1, -2, -3] as $quiet) {
            $this->assertSame([], $byDay[$quiet],
                'something was sent ' . $quiet . ' days out, outside the five-day arc');
        }

        // One letter each, per day, on each of the five.
        foreach ([5, 4, 3, 2, 1] as $day) {
            $this->assertCount(2, $byDay[$day], 'day ' . $day . ' did not send exactly two letters');

            $to = array_column($byDay[$day], 'to');
            sort($to);
            $this->assertSame(['judge@example.com', 'nominee@example.com'], $to,
                'day ' . $day . ' wrote to the wrong people');
        }

        $this->assertCount(10, $mailer->sent, 'five days, two guests of honour, ten letters');
    }

    /** Each audience gets the arc written for it, in order, on the right day. */
    public function test_each_letter_is_the_one_written_for_that_day_and_that_audience(): void
    {
        $this->guest(InviteAudience::NOMINEE, 'nominee@example.com');
        $this->guest(InviteAudience::JUDGE, 'judge@example.com');

        $mailer = $this->recorder();
        for ($offset = 5; $offset >= 1; $offset--) {
            Carbon::setTestNow($this->night->copy()->subDays($offset)->setTime(9, 0));
            InviteReminders::sweep($mailer, 100);
        }

        $letters = [];
        foreach ($mailer->sent as $m) $letters[$m['to']][] = $m;

        // The hinges of the arc, in order.
        foreach (['nominee@example.com', 'judge@example.com'] as $who) {
            $subjects = array_column($letters[$who], 'subject');
            $this->assertCount(5, $subjects, $who);

            foreach (['begin with your WHY', 'find your message', 'own your message',
                      'prepare to speak'] as $i => $hinge) {
                $this->assertStringContainsString($hinge, $subjects[$i], $who . ' letter ' . ($i + 1));
            }
            $this->assertStringContainsString('tomorrow is', strtolower($subjects[4]));
        }

        // And the premise never crosses over. A judge told their nomination represents
        // trust is the one failure a reader notices instantly.
        $judgeHtml = implode("\n", array_column($letters['judge@example.com'], 'html'));
        $this->assertStringNotContainsString('your nomination', strtolower($judgeHtml));
        $this->assertStringContainsString('your seat on that panel', $judgeHtml);
        $this->assertStringContainsString('the names you weighed will be read aloud', $judgeHtml);

        $nomHtml = implode("\n", array_column($letters['nominee@example.com'], 'html'));
        $this->assertStringContainsString('your nomination represents more than recognition', $nomHtml);
    }

    /**
     * A cron that misses a morning does not swallow that day's letter.
     *
     * A mark holds until the next one below it, so day four's letter still goes when the
     * sweep first runs on day four — and having gone late, it does not go again.
     */
    public function test_a_missed_morning_is_recovered_and_not_repeated(): void
    {
        $this->guest(InviteAudience::NOMINEE, 'nominee@example.com');

        $mailer = $this->recorder();

        // Day five never ran at all.
        Carbon::setTestNow($this->night->copy()->subDays(4)->setTime(9, 0));
        InviteReminders::sweep($mailer, 100);
        $this->assertCount(1, $mailer->sent);
        $this->assertStringContainsString('find your message', $mailer->sent[0]['subject'],
            'day four is the mark that is due on day four — day five is behind us and is dropped, '
            . 'because "we are officially five days away" posted on day four is worse than silence');

        // Twice on the same day, and again that evening.
        InviteReminders::sweep($mailer, 100);
        Carbon::setTestNow($this->night->copy()->subDays(4)->setTime(20, 0));
        InviteReminders::sweep($mailer, 100);

        $this->assertCount(1, $mailer->sent, 'one mark is one letter, however often the sweep runs');
    }

    /** Nobody is reminded of an invitation they never received. */
    public function test_an_unsent_invitation_is_silent_for_the_whole_arc(): void
    {
        EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Never Written To', 'email' => 'never@example.com',
             'nominee_id' => 0, 'judge_id' => 0]);

        $mailer = $this->recorder();
        for ($offset = 5; $offset >= 1; $offset--) {
            Carbon::setTestNow($this->night->copy()->subDays($offset)->setTime(9, 0));
            InviteReminders::sweep($mailer, 100);
        }

        $this->assertSame([], $mailer->sent);
    }

    // ══ THE SCREENS, RENDERED ═══════════════════════════════════════════════

    private function render(string $class, string $method, array $args = [], string $path = '/admin/x'): string
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get($class)->{$method}(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
            (new ResponseFactory())->createResponse(),
            $args
        );

        $this->assertSame(200, $res->getStatusCode(), $method . ' did not render');

        return (string) $res->getBody();
    }

    /**
     * The invitations screen shows the schedule, the arc and both audiences.
     *
     * Compiling a template proves its syntax. Only rendering it proves the controller
     * passes what it reads — Twig renders an undefined variable as empty rather than
     * raising, so a forgotten key is a silently missing panel and a green suite.
     */
    public function test_the_invitations_screen_reports_the_countdown(): void
    {
        Carbon::setTestNow($this->night->copy()->subDays(3)->setTime(9, 0));
        $this->guest(InviteAudience::NOMINEE, 'nominee@example.com');

        $html = $this->render(\AfricaGates\Admin\Controllers\InvitesController::class,
                              'index', ['id' => $this->eventId]);

        $this->assertStringContainsString('Reminders', $html);
        $this->assertStringContainsString('In 3 days', $html, 'the distance to the evening');
        $this->assertStringContainsString('responsibility', $html, 'day three names its place in the arc');
        $this->assertStringContainsString('nominees and panel', $html,
            'the schedule has to say the panel gets one too, or nothing does');
        $this->assertStringContainsString('The countdown letters', $html);

        // No AI provider is configured in this harness, so the drafting form is correctly
        // absent — and the branch that renders instead has to leave a way through rather
        // than an empty box. A dead end here is the "mechanism with no route in" fault:
        // the letters are still editable by hand, and the screen must say where.
        $this->assertStringContainsString('The writer is unavailable', $html);
        $this->assertMatchesRegularExpression('~/admin/settings#f-invite_seq_body_~', $html,
            'an unavailable writer must still point at the place letters can be written');
    }

    /** Both arcs are editable, and the arrivals card is reachable. */
    public function test_the_settings_screen_carries_both_arcs_and_the_tracker(): void
    {
        $html = $this->render(\AfricaGates\Admin\Controllers\SettingsController::class, 'form');

        foreach ([1, 2, 3, 4, 5] as $d) {
            $this->assertStringContainsString('name="invite_seq_body_nominee_' . $d . '"', $html);
            $this->assertStringContainsString('name="invite_seq_body_judge_' . $d . '"', $html);
        }
        $this->assertStringContainsString('name="invite_reminder_time"', $html);
        $this->assertStringContainsString('name="visits_enabled"', $html);
        $this->assertStringContainsString('name="visits_days"', $html);
    }

    /**
     * The arrivals report renders every table it computes.
     *
     * A column with a reader in the service and no cell on the page is the same bug one
     * layer up — and it is the layer the unit tests cannot see.
     */
    public function test_the_analytics_screen_renders_the_arrivals_report(): void
    {
        // TWO sections, because the grouped table is deliberately hidden when there is
        // only one — with everything landing in the same place it would repeat the exact
        // list above it and say nothing new.
        DB::table('gates_visits')->insert([
            ['visit_key' => str_repeat('a', 32), 'source' => 'flier', 'medium' => 'print',
             'campaign' => 'gala26', 'landing_path' => '/nominee/ada-obi',
             'referrer_host' => 'l.facebook.com', 'device' => 'mobile', 'country' => 'NG',
             'ip_hash' => str_repeat('b', 64), 'converted_kind' => 'vote',
             'converted_at' => Carbon::now()->toDateTimeString(),
             'created_at' => Carbon::now()->toDateTimeString()],
            // The same key set, because a batch insert requires it — nulls where this
            // arrival simply carried nothing.
            ['visit_key' => str_repeat('c', 32), 'source' => 'whatsapp', 'medium' => null,
             'campaign' => null, 'landing_path' => '/events',
             'referrer_host' => null, 'device' => 'mobile', 'country' => null,
             'ip_hash' => str_repeat('d', 64), 'converted_kind' => null,
             'converted_at' => null,
             'created_at' => Carbon::now()->toDateTimeString()],
        ]);

        $html = $this->render(\AfricaGates\Admin\Controllers\AnalyticsController::class, 'index');

        $this->assertStringContainsString('Where people came from', $html);
        foreach (['flier', 'gala26', 'l.facebook.com', 'print', 'NG', '/nominee/*'] as $fact) {
            $this->assertStringContainsString($fact, $html, $fact . ' is computed but never rendered');
        }
        $this->assertStringContainsString('Networks', $html);
    }

    /**
     * Every "Settings → …" link on the admin lands on a field that exists.
     *
     * ── WHY THIS IS RENDERED AND NOT GREPPED ─────────────────────────────────
     *
     * The letters link was left pointing at `#f-invite_seq_body_5` when those fields
     * gained an audience and became `…_nominee_5`. So the one route out of the
     * "the writer is unavailable" branch — the branch whose whole job is to leave a way
     * through — scrolled nowhere.
     *
     * A grep over the settings TEMPLATE cannot catch it, and reported the fixed link as
     * broken too: those ids are built by a loop, so `id="f-invite_seq_body_nominee_5"`
     * exists in the rendered page and nowhere in the source. The page has to be rendered
     * and the anchors resolved against what an operator's browser would actually receive.
     */
    public function test_every_settings_anchor_on_the_admin_lands_somewhere(): void
    {
        $settings = $this->render(\AfricaGates\Admin\Controllers\SettingsController::class, 'form');

        $ids = [];
        preg_match_all('~\bid="(f-[A-Za-z0-9_]+)"~', $settings, $m);
        foreach ($m[1] as $id) $ids[$id] = true;
        $this->assertNotEmpty($ids, 'the settings screen rendered no fields at all');

        // RECURSIVE, and not glob('admin/**/*.twig') — PHP's glob does not expand `**`,
        // so that pattern reads exactly one directory deep and silently skips both the
        // top level and anything nested below it. A scan with a hole in it is the fault
        // this test exists to close, arrived at from the other side.
        $dead  = [];
        $found = 0;
        $dir   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));

        foreach ($dir as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;

            preg_match_all('~/admin/settings#(f-[A-Za-z0-9_]+)~',
                           (string) file_get_contents($file->getPathname()), $hit);

            foreach ($hit[1] as $anchor) {
                $found++;
                // Anchors a browser is handed literally. One built by interpolation is
                // not scannable here, and is not what broke.
                if (!isset($ids[$anchor])) {
                    $dead[] = $file->getBasename() . ' → #' . $anchor;
                }
            }
        }

        $this->assertGreaterThan(0, $found,
            'the scan found no settings anchors at all, so it is proving nothing');

        $this->assertSame([], $dead,
            'these links point at a settings field that is not on the page: '
            . implode(', ', $dead));
    }

    /** Off is reported as off, not as a page of zeroes that reads as "nobody came". */
    public function test_the_arrivals_report_says_when_nothing_is_being_recorded(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'visits_enabled', 'value' => '0']);

        $html = $this->render(\AfricaGates\Admin\Controllers\AnalyticsController::class, 'index');

        $this->assertStringContainsString('Nothing is being recorded', $html);
        $this->assertFalse(VisitTracker::enabled());
    }
}
