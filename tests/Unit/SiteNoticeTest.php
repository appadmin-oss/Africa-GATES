<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Services\MaintenanceGate;
use AfricaGates\Services\PlannedWork;
use AfricaGates\Services\SiteNotice;
use AfricaGates\Services\StatusAlert;
use AfricaGates\Support\PublicFault;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Announcements, planned work, closing the site, and the errors visitors see (owner,
 * 11 Nov 2026: "fix the announcement and scheduled maintenance messages and alerts and error
 * management features").
 *
 * Each test is one of the faults found:
 *   · the announcement banner had a settings form and no reader, and a default nobody typed;
 *   · planned work was stated on /status alone;
 *   · the error page's 503 "planned work" could not be produced by anything;
 *   · status subscribers were never told about planned work;
 *   · a 500 shown to a visitor reached nobody running the site;
 *   · and the suite wrote its deliberate faults into the real error log.
 */
final class SiteNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        ChromeRender::$brandedErrors = false;
    }

    protected function tearDown(): void
    {
        ChromeRender::$brandedErrors = false;
        parent::tearDown();
    }

    private function set(array $kv): void
    {
        foreach ($kv as $k => $v) DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
    }

    /** A planned-work window, `$startIn` minutes from now, lasting `$mins`. */
    private function plan(int $startIn, int $mins = 120, bool $close = false, string $title = 'Card payments'): void
    {
        $this->set([PlannedWork::KEYS['title'] => $title,
                    PlannedWork::KEYS['from'] => Carbon::now()->addMinutes($startIn)->toDateTimeString(),
                    PlannedWork::KEYS['to'] => Carbon::now()->addMinutes($startIn + $mins)->toDateTimeString(),
                    PlannedWork::KEYS['note'] => 'Card payments pause; mobile money carries on.',
                    PlannedWork::KEYS['close'] => $close ? '1' : '0']);
    }

    private function admin(string $role = 'superadmin'): array
    {
        $id = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi', 'email' => 'kemi' . bin2hex(random_bytes(3)) . '@example.com',
            'role' => $role, 'is_active' => 1, 'password_hash' => 'x']);
        return ['admin_id' => $id, 'admin_role' => $role, 'admin_name' => 'Kemi'];
    }

    // ── the announcement ─────────────────────────────────────────────────────

    public function test_the_banner_shows_what_an_operator_typed_and_nothing_when_they_typed_nothing(): void
    {
        $this->assertStringNotContainsString('class="ag-notice', ChromeRender::html('/privacy'), 'a banner nobody wrote');

        $this->set(['announce_text' => 'Voting closes on Friday at 23:59', 'announce_url' => '/vote', 'announce_cta' => 'Vote now →']);
        $html = ChromeRender::html('/privacy');
        $this->assertStringContainsString('Voting closes on Friday at 23:59', $html, 'the banner the settings page offers was never drawn');
        $this->assertMatchesRegularExpression('~<a class="ag-notice__cta" href="/vote">Vote now<svg~', $html, 'the link, without the typed arrow');
        $this->assertStringContainsString('action="/notice/hide"', $html, 'no way to hide it');

        // A link the page will not print, and a banner past its end.
        foreach (['javascript:alert(1)', '//evil.example/x', 'http://plain.example'] as $bad) {
            $this->assertNull(SiteNotice::announcement(['announce_text' => 'x', 'announce_url' => $bad])['url'], "$bad was printed");
        }
        $this->assertSame('https://tickets.example.org/a?b=1', SiteNotice::announcement(['announce_text' => 'x', 'announce_url' => 'https://tickets.example.org/a?b=1'])['url']);
        $this->set(['announce_until' => Carbon::now()->subMinute()->toDateTimeString()]);
        $this->assertStringNotContainsString('Voting closes on Friday', ChromeRender::html('/privacy'), 'a banner outlived the end it was given');
    }

    public function test_hiding_is_remembered_and_a_new_message_shows_again(): void
    {
        $this->set(['announce_text' => 'The gala is sold out']);
        $id = SiteNotice::announcement()['id'];
        $r = ChromeRender::page('/notice/hide', [], [], 'POST', ['_token' => 'test-token', 'id' => $id, 'back' => '/privacy']);
        $this->assertSame(303, $r->getStatusCode());
        $this->assertSame('/privacy', $r->getHeaderLine('Location'));
        $off = ChromeRender::page('/notice/hide', [], [], 'POST', ['_token' => 'test-token', 'id' => $id, 'back' => '//evil.example']);
        $this->assertSame('/', $off->getHeaderLine('Location'), 'hiding a notice became an open redirect');

        $_SESSION = [];
        SiteNotice::hide($id);
        $this->assertSame([], SiteNotice::forPage('/privacy'), 'hidden, and drawn again');
        $this->set(['announce_text' => 'The gala is sold out — waiting list open']);
        $this->assertCount(1, SiteNotice::forPage('/privacy'), 'a rewritten announcement stayed hidden');
    }

    public function test_an_announcement_never_interrupts_a_flow_but_planned_work_does(): void
    {
        $this->set(['announce_text' => 'Shop sale this week']);
        $this->plan(60);
        $kinds = array_column(SiteNotice::forPage('/vote/x', true), 'kind');
        $this->assertSame(['planned'], $kinds, 'a payment page is exactly where a pause in payments matters, and no place for news');
        $this->assertSame(['planned', 'announce'], array_column(SiteNotice::forPage('/privacy'), 'kind'));
        $this->assertSame([], SiteNotice::forPage('/status'), '/status states planned work itself');
    }

    // ── planned work, site-wide ──────────────────────────────────────────────

    public function test_planned_work_is_on_every_page_from_three_days_out_and_says_when_it_begins(): void
    {
        $this->plan(5 * 24 * 60);
        $this->assertNotNull(PlannedWork::current(), 'on /status from a week before');
        $this->assertSame([], SiteNotice::forPage('/privacy'), 'five days out is a banner people stop reading');

        $this->plan(90);
        $html = ChromeRender::html('/privacy');
        $this->assertStringContainsString('<b class="ag-notice__k">Planned work</b>', $html);
        $this->assertStringContainsString('<details class="ag-notice__more">', $html, 'the window and the note are not one tap away');
        $this->assertMatchesRegularExpression('~Starts in 1 h (29|30) min~', $html, 'how far off it is, in words');
        $this->assertStringContainsString('Card payments', $html);
        $this->assertStringContainsString('mobile money carries on', $html);
        $before = SiteNotice::forPage('/privacy')[0]['id'];
        SiteNotice::hide($before);
        $this->assertSame([], SiteNotice::forPage('/privacy'));

        $this->plan(-10);
        $now = SiteNotice::forPage('/privacy');
        $this->assertCount(1, $now, 'hiding "starts soon" hid "under way now"');
        $live = ChromeRender::html('/privacy', $_SESSION);
        $this->assertStringContainsString('Planned work under way', $live);
        $this->assertStringContainsString('is-live', $live);
        $this->assertMatchesRegularExpression('~Ends in 1 h (49|50) min~', $live);
    }

    public function test_the_countdown_never_promises_more_time_than_there_is(): void
    {
        $now = Carbon::parse('2026-11-11 10:00:00');
        $w = static fn (string $from, string $to, bool $started): string =>
            SiteNotice::countdown(['from' => $from, 'to' => $to, 'started' => $started], $now);
        $this->assertSame('Starts in 45 minutes', $w('2026-11-11 10:45:30', '2026-11-11 12:00:00', false));
        $this->assertSame('Starts in 1 minute', $w('2026-11-11 10:01:59', '2026-11-11 12:00:00', false));
        $this->assertSame('Starts in 2 h 59 min', $w('2026-11-11 12:59:59', '2026-11-11 14:00:00', false), 'rounded up');
        $this->assertSame('Starts in 3 hours', $w('2026-11-11 13:02:00', '2026-11-11 14:00:00', false));
        $this->assertSame('Starts in 20 hours', $w('2026-11-12 06:30:00', '2026-11-12 08:00:00', false));
        $this->assertSame('Starts in 3 days', $w('2026-11-14 11:00:00', '2026-11-14 12:00:00', false));
        $this->assertSame('Ends in 30 minutes', $w('2026-11-11 09:00:00', '2026-11-11 10:30:00', true));
        $this->assertSame('Starting now', $w('2026-11-11 10:00:20', '2026-11-11 12:00:00', false));
    }

    // ── closing the site ─────────────────────────────────────────────────────

    public function test_a_closed_site_answers_503_with_when_and_keeps_the_machinery_open(): void
    {
        $this->assertSame('1', PlannedWork::fromPost([PlannedWork::KEYS['title'] => 'x', PlannedWork::KEYS['close'] => '1'])[PlannedWork::KEYS['close']]);
        $this->assertSame('0', PlannedWork::fromPost([PlannedWork::KEYS['title'] => 'x'])[PlannedWork::KEYS['close']], 'an unticked box left the site closed');

        $this->plan(-5, 120, false);
        $this->assertSame(200, ChromeRender::page('/privacy')->getStatusCode(), 'announced but not closed');

        $this->plan(-5, 120, true);
        ChromeRender::$brandedErrors = true;
        $r = ChromeRender::page('/privacy');
        $this->assertSame(503, $r->getStatusCode(), 'the planned-work page had nothing that could produce it');
        $this->assertGreaterThan(6000, (int) $r->getHeaderLine('Retry-After'), 'Retry-After is not the window’s end');
        $body = (string) $r->getBody();
        $this->assertStringContainsString('We’re doing planned work', $body);
        $this->assertStringContainsString('Back by', $body);
        $this->assertStringContainsString('Card payments', $body);
        $this->assertStringContainsString('href="/account/login?next=%2Fadmin"', $body, 'no way back in for whoever is doing the work');
        $this->assertStringNotContainsString('Your reference', $body, 'planned work is not a fault to report');
        $this->assertStringNotContainsString('class="ag-notice', $body, 'the closed page repeated itself in a banner');
        $this->assertStringNotContainsString('class="ag-tabbar', $body, 'tabs offered on a site where every tab is closed');
        $this->assertSame(0, PublicFault::recent()['total'], 'a closure was logged as an error and would raise the errors alert');

        $json = ChromeRender::page('/privacy', [], [], 'GET', null, ['Accept' => 'application/json']);
        $this->assertSame(503, $json->getStatusCode());
        $this->assertArrayHasKey('retry_after', json_decode((string) $json->getBody(), true));

        foreach (['/status', '/status.json', '/account/login', '/admin/settings', '/pay/webhook', '/vote/paid/callback', '/shop/callback',
                  '/__cron/run', '/hooks/sms-inbound', '/api/v1/interview/bot/webhook', '/assets/css/shell.css'] as $p) {
            $this->assertTrue(MaintenanceGate::open($p), "$p was closed: it is mid-way through somebody's money or the way back in");
        }
        foreach (['/', '/about', '/vote', '/account', '/shop', '/statusx', '/admins'] as $p) {
            $this->assertFalse(MaintenanceGate::open($p), "$p stayed open while the site was closed");
        }

        // Staff walk through, and are told it is closed.
        $staff = $this->admin();
        $page = ChromeRender::page('/privacy', $staff);
        $this->assertSame(200, $page->getStatusCode(), 'staff were shut out of the site they are fixing');
        $this->assertStringContainsString('Closed to the public', (string) $page->getBody());
        $this->assertStringContainsString('You can see the site because you are signed in as staff.', (string) $page->getBody());
        ConsoleAlerts::reset();
        $this->assertContains('closed', array_column(ConsoleAlerts::open(), 'key'), 'the console did not say the site is shut');
    }

    // ── telling subscribers ──────────────────────────────────────────────────

    public function test_status_subscribers_are_told_about_planned_work_once_per_window(): void
    {
        DB::table('gates_status_alerts')->insert(['email' => 'reader@africa-gates-test.org', 'email_hash' => hash('sha256', 'reader@africa-gates-test.org'), 'token' => str_repeat('a', 32),
            'confirmed_at' => Carbon::now()->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString()]);
        $mailer = new class extends \AfricaGates\Services\OtpService {
            public array $sent = [];
            public function __construct() {}
            public function sendBranded(...$a): array { $this->sent[] = $a; return ['success' => true]; }
        };
        $this->plan(48 * 60, 120, true);
        $this->assertSame(1, StatusAlert::sweep($mailer, 'https://site.test', []), 'planned work was never mailed');
        $this->assertSame(0, StatusAlert::sweep($mailer, 'https://site.test', []), 'mailed twice for one window');
        $this->assertStringStartsWith('Planned work: Card payments', $mailer->sent[0][1]);
        $this->assertStringContainsString('closed to visitors', $mailer->sent[0][3]);
        $this->assertStringContainsString('/status/alerts/' . str_repeat('a', 32) . '/stop', $mailer->sent[0][3]);

        $this->plan(50 * 60, 120, true);
        $this->assertSame(1, StatusAlert::sweep($mailer, 'https://site.test', []), 'a moved window is news');

        [, , $plain] = StatusAlert::confirmMail((object) ['token' => 'x'], 'https://site.test');
        $this->assertStringContainsString('planned work', $plain, 'the confirmation promises less than is sent');
    }

    // ── errors visitors saw ──────────────────────────────────────────────────

    public function test_the_suite_never_writes_the_real_error_log(): void
    {
        $this->assertStringNotContainsString('/var/logs/', PublicFault::logFile());
        PublicFault::record(new \RuntimeException('test fault'), 'GET /privacy');
        $this->assertSame(1, PublicFault::recent()['total']);
    }

    public function test_an_error_shown_to_a_visitor_raises_an_alert_and_has_a_page(): void
    {
        ConsoleAlerts::reset();
        $this->assertNotContains('faults', array_column(ConsoleAlerts::open(), 'key'), 'an alert with nothing behind it');

        $ref = PublicFault::record(new \LogicException('Undefined index: amount'), 'POST /vote/paid');
        PublicFault::record(new \LogicException('Undefined index: amount'), 'POST /vote/paid');
        PublicFault::append('[' . date('c', time() - 3 * 86400) . '] [ref ZZZZ-2222] [GET /old] Old: from three days ago' . "\n\n");
        $r = PublicFault::recent();
        $this->assertSame(2, $r['total'], 'a three-day-old error counted in 24 hours');
        $this->assertSame(['POST /vote/paid' => 2], $r['by_where']);
        $this->assertSame(2, $r['money']);
        $this->assertStringNotContainsString('.php', $r['entries'][0]['what'], 'a list line carried a file path');

        ConsoleAlerts::reset();
        $a = array_values(array_filter(ConsoleAlerts::open(), static fn ($x) => $x['key'] === 'faults'))[0] ?? null;
        $this->assertNotNull($a, 'a visitor met a 500 and nobody running the site was told');
        $this->assertSame(ConsoleAlerts::MEDIUM, $a['severity'], 'an error while paying is not low');
        $this->assertSame('affects', $a['impact']);
        $this->assertSame('/admin/errors', $a['href']);

        $html = ChromeRender::html('/admin/errors?ref=' . strtolower(str_replace('-', ' ', $ref)), $this->admin());
        $this->assertStringContainsString('Errors visitors saw', $html);
        $this->assertStringContainsString('Undefined index: amount', $html, 'a quoted reference did not open its entry');
        $this->assertStringContainsString('1 other', $html);
        $this->assertStringContainsString('POST /vote/paid', $html);

        $viewer = ChromeRender::page('/admin/errors', $this->admin('viewer'));
        $this->assertNotSame(200, $viewer->getStatusCode(), 'stack frames opened to a role the owner has not decided on');
        $this->assertStringContainsString('href="/admin/errors"', ChromeRender::html('/admin/alerts', $this->admin()));
        $this->assertStringNotContainsString('href="/admin/errors"', ChromeRender::html('/admin/alerts', $this->admin('viewer')),
            'a door offered that the guard will close');
    }

    // ── the console ──────────────────────────────────────────────────────────

    public function test_the_settings_page_offers_the_close_and_the_end_and_saves_them(): void
    {
        $s = $this->admin();
        $html = ChromeRender::html('/admin/settings', $s);
        $this->assertStringContainsString('name="status_planned_close"', $html);
        $this->assertStringContainsString('name="announce_until"', $html);
        $this->assertStringContainsString('Nothing is showing.', $html);

        $this->set(['announce_text' => 'Hello', 'announce_url' => 'javascript:alert(1)']);
        $html = ChromeRender::html('/admin/settings', $s);
        $this->assertStringContainsString('The link is not shown:', $html, 'an operator was not told their link is refused');
        $this->assertStringContainsString('Showing now at the top of every public page.', $html);

        $r = ChromeRender::page('/admin/settings', $s, [], 'POST', ['_token' => 'test-token', 'planned_work_settings' => '1',
            'status_planned_title' => 'Database upgrade', 'status_planned_from' => '2030-01-01T09:00', 'status_planned_to' => '2030-01-01T11:00',
            'status_planned_close' => '1', 'announce_text' => 'Hello', 'announce_until' => '2030-01-02T09:00']);
        $this->assertLessThan(400, $r->getStatusCode());
        $this->assertSame('1', DB::table('gates_settings')->where('key_name', 'status_planned_close')->value('value'));
        $this->assertStringStartsWith('2030-01-02 ', (string) DB::table('gates_settings')->where('key_name', 'announce_until')->value('value'));
    }
}
