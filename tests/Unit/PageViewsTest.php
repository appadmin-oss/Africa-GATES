<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PageViews;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "Seen by" (owner, 11 Nov 2026, by voice note: show the number of views on each page, with
 * an icon that offers "promote your business, promote your event"; then "make it smart",
 * "UX-optimized").
 *
 * The number is public, so every rule about it is a rule about not overstating it: an
 * ENGAGED view (the page visible a few seconds — views.js), a PERSON (not a bot, not staff,
 * not somebody who said no), ONCE a day each, a ceiling per connection, and only for a page
 * the server drew. And it is never shown beside a nominee.
 */
final class PageViewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'views_show_min'], ['value' => '3']);
    }

    private function req(string $ua = 'Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile', string $ip = '102.89.1.1', array $extra = []): \Psr\Http\Message\ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest('POST', '/views', ['REMOTE_ADDR' => $ip]);
        foreach (['User-Agent' => $ua, 'Accept-Language' => 'en-NG,en;q=0.9', 'Accept' => '*/*', 'Sec-Fetch-Site' => 'same-origin'] + $extra as $k => $v) $r = $r->withHeader($k, $v);
        return $r;
    }

    public function test_a_page_token_is_only_good_for_a_page_the_server_drew(): void
    {
        $t = PageViews::token('event', 12);
        $this->assertSame(['kind' => 'event', 'id' => 12], PageViews::read($t));
        $this->assertNull(PageViews::read(substr($t, 0, -1) . (substr($t, -1) === 'a' ? 'b' : 'a')), 'a forged signature was accepted');
        $this->assertNull(PageViews::read(str_replace('event.12', 'event.13', $t)), 'one page’s token counted another');
        $this->assertNull(PageViews::read(PageViews::token('event', 12, date('Ymd', time() - 3 * 86400))), 'a token from last week still counts');
        $this->assertNotNull(PageViews::read(PageViews::token('event', 12, date('Ymd', time() - 86400))), 'a page left open over midnight stopped counting');
        $this->assertNull(PageViews::forPage('nominee', 4), 'a nominee page was given a count');
        foreach (['nominee', 'vote', 'ballot', 'result'] as $k) $this->assertNotContains($k, PageViews::KINDS);
    }

    public function test_a_person_is_counted_once_a_day_and_nobody_else_at_all(): void
    {
        $t = PageViews::token('post', 5);
        $this->assertTrue(PageViews::engaged($this->req(), $t));
        $this->assertFalse(PageViews::engaged($this->req(), $t), 'a refresh counted twice');
        $this->assertTrue(PageViews::engaged($this->req('Mozilla/5.0 (iPhone) Safari/604'), $t), 'a second phone on the same mobile network was not counted');
        $this->assertFalse(PageViews::engaged($this->req('Googlebot/2.1 (+http://www.google.com/bot.html)', '66.249.1.1'), $t), 'a crawler counted');
        $this->assertFalse(PageViews::engaged($this->req('Mozilla/5.0 Chrome/120', '41.1.1.1', ['Sec-GPC' => '1']), $t), 'somebody who said no was counted');
        $_SESSION['admin_id'] = 3;
        $this->assertFalse(PageViews::engaged($this->req('Mozilla/5.0 Firefox/130', '41.2.2.2'), $t), 'our own staff counted');
        $_SESSION = [];
        $this->assertSame(2, PageViews::stats('post', 5)['total'], 'two people, two views');
        $this->assertSame(2, PageViews::stats('post', 5)['week']);
        $this->assertSame(2, (int) DB::table('gates_page_view_seen')->count(), 'who was counted is not kept to the day’s two hashes');
    }

    public function test_one_connection_cannot_move_the_number_past_its_ceiling(): void
    {
        $t = PageViews::token('thread', 9);
        $n = 0;
        for ($i = 0; $i < PageViews::NET_CAP + 15; $i++) {
            if (PageViews::engaged($this->req('Mozilla/5.0 Chrome/120 rotated-' . $i, '102.89.9.9'), $t)) $n++;
        }
        $this->assertSame(PageViews::NET_CAP, $n, 'a script rotating its browser string moved the count past the ceiling');
        $this->assertTrue(PageViews::engaged($this->req('Mozilla/5.0 Chrome/120', '197.210.1.1'), $t), 'the ceiling is not per connection');
    }

    public function test_the_chip_shows_from_the_threshold_says_trending_honestly_and_never_rounds_up(): void
    {
        $this->assertNull(PageViews::badge('post', 77), 'a page with no views drew a chip');
        DB::table('gates_page_views')->insert(['page_key' => 'post:77', 'views' => 1250, 'updated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_page_view_days')->insert(['page_key' => 'post:77', 'day' => date('Y-m-d', time() - 9 * 86400), 'views' => 40]);
        DB::table('gates_page_view_days')->insert(['page_key' => 'post:77', 'day' => date('Y-m-d', time() - 86400), 'views' => 90]);
        $b = PageViews::badge('post', 77);
        $this->assertSame('1.2k', $b['short']);
        $this->assertSame(90, $b['week']);
        $this->assertTrue($b['trending'], '90 this week against 40 the week before is trending');
        DB::table('gates_page_view_days')->where('page_key', 'post:77')->where('views', 40)->update(['views' => 80]);
        $this->assertFalse(PageViews::badge('post', 77)['trending'], '90 against 80 called trending');
        foreach ([[940, '940'], [9999, '9.9k'], [10500, '10k'], [1_250_000, '1.2m'], [1000, '1k']] as [$n, $s]) $this->assertSame($s, PageViews::short($n));
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'views_show_min'], ['value' => '2000']);
        $this->assertNull(PageViews::badge('post', 77), 'drawn below the threshold');
    }

    public function test_the_page_draws_the_marker_always_and_the_chip_with_its_promote_offer(): void
    {
        $id = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'lagos-nights', 'title' => 'Lagos nights', 'status' => 'published',
            'event_date' => date('Y-m-d 18:00:00', strtotime('+10 days')), 'created_at' => date('Y-m-d H:i:s')]);
        $html = ChromeRender::html('/events/lagos-nights');
        $this->assertMatchesRegularExpression('~<span hidden data-pv="event\.' . $id . '\.\d{8}\.[a-f0-9]{32}" data-pv-after="' . PageViews::ENGAGED_SECONDS . '"~', $html,
            'a page below the threshold gathers no views');
        $this->assertStringNotContainsString('class="ag-views"', $html);
        $this->assertStringContainsString('/assets/js/views.js', $html);

        DB::table('gates_page_views')->insert(['page_key' => 'event:' . $id, 'views' => 10240, 'updated_at' => date('Y-m-d H:i:s')]);
        $html = ChromeRender::html('/events/lagos-nights');
        $this->assertStringContainsString('10k views', $html);
        $this->assertStringContainsString('href="/account/ads/new?for=event&amp;slug=lagos-nights"', $html, 'no "promote this event"');
        $this->assertStringContainsString('href="/advertise"', $html);

        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi', 'email' => 'k' . bin2hex(random_bytes(3)) . '@example.test', 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $b = ChromeRender::html('/account/ads/new?for=event&slug=lagos-nights', ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringContainsString('value="/events/lagos-nights"', $b, 'the builder did not open ready for the event');
        $this->assertMatchesRegularExpression('~<option value="get_tickets" selected>~', $b);
        $this->assertStringContainsString('value="Lagos nights"', $b);
    }

    public function test_the_beacon_endpoint_counts_once_and_never_on_a_nominee_page(): void
    {
        $t = PageViews::token('post', 31);
        $h = ['User-Agent' => 'Mozilla/5.0 Chrome/120', 'Accept-Language' => 'en-GB', 'X-CSRF-Token' => 'test-token', 'CF-Connecting-IP' => '41.5.5.5'];
        $this->assertSame(204, ChromeRender::page('/views', [], [], 'POST', ['_token' => 'test-token', 't' => $t], $h)->getStatusCode());
        $this->assertSame(202, ChromeRender::page('/views', [], [], 'POST', ['_token' => 'test-token', 't' => $t], $h)->getStatusCode());

        foreach (glob(dirname(__DIR__, 2) . '/templates/**/*.twig') ?: [] as $f) {
            if (!str_contains((string) file_get_contents($f), "partials/views-badge.twig")) continue;
            $rel = substr($f, strlen(dirname(__DIR__, 2)) + 1);
            $this->assertContains($rel, ['templates/pages/events/detail.twig', 'templates/pages/blog/post.twig', 'templates/pages/community/thread.twig'],
                "$rel shows a view count; a ballot, a nominee page or a result never may (D15)");
        }
    }

    public function test_the_threshold_is_set_in_the_console(): void
    {
        $aid = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi', 'email' => 'kemi@example.com', 'role' => 'superadmin', 'is_active' => 1, 'password_hash' => 'x']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'views_show_min'], ['value' => '250']);
        $html = ChromeRender::html('/admin/settings', ['admin_id' => $aid, 'admin_role' => 'superadmin', 'admin_name' => 'Kemi']);
        $this->assertMatchesRegularExpression('~name="views_show_min"[^>]*value="250"~', $html, 'there is no shell on production: a setting must be settable');
        $this->assertSame(250, PageViews::showMin());
    }

    public function test_the_script_counts_only_engaged_time_and_closes_like_a_menu(): void
    {
        $js = ChromeRender::code('public/assets/js/views.js');
        $this->assertStringContainsString("mark.getAttribute('data-pv-after')", $js);
        $this->assertStringContainsString("pg.on(document, 'visibilitychange'", $js, 'a background tab’s time counts');
        $this->assertMatchesRegularExpression('~if \(shown < need\) return;~', $js);
        $this->assertStringContainsString("'X-CSRF-Token': mark.getAttribute('data-csrf')", $js, 'the token is copied at load');
        $this->assertStringContainsString("e.key !== 'Escape'", $js);
    }
}
