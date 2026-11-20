<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AnalyticsService;
use AfricaGates\Services\VisitTracker;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tests\TestCase;

/**
 * Where the people who arrive here came from.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS BEING HELD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two things, and the second is the one that would be expensive to get wrong.
 *
 * THE COUNT. One row per VISIT. Counting page views instead would make every channel look
 * better in proportion to how much a reader wandered, and the one number an organiser
 * would act on — arrivals against conversions — would be nonsense in the direction that
 * flatters. Bots and link previewers are worse still: WhatsApp fetches every URL pasted
 * into a chat, so one link in a busy group would look like a hundred visitors before a
 * single person had opened it.
 *
 * WHAT IS NOT STORED. This platform puts live credentials in links — an invitation pass,
 * a magic sign-in, a claim token. A landing QUERY STRING in a table built to be read by
 * operators and exported to a spreadsheet would copy those out of the request log and
 * into a report. The same for a full referrer, which carries somebody's search terms.
 */
final class VisitTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        DB::table('gates_settings')->where('key_name', 'like', 'visits_%')->delete();
        VisitTracker::forgetSalt();
    }

    private function get(string $uri, array $headers = []): Request
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', $uri);

        // A plain browser unless a test says otherwise. Without one, looksLikeBot() is
        // right to refuse: no agent at all is a script, not a reader.
        // and the headers every current browser sends with a page navigation (see
        // isPageNavigation()).
        $headers += [
            'User-Agent'     => 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36',
            'Accept'         => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Sec-Fetch-Mode' => 'navigate',
            'Accept-Language' => 'en-GB,en;q=0.9',
            'Sec-Fetch-Dest' => 'document',
        ];
        $headers = array_filter($headers, static fn ($v) => $v !== null);
        foreach ($headers as $k => $v) $r = $r->withHeader($k, $v);

        return $r;
    }

    /** @return array<string,mixed>|null */
    private function lastVisit(): ?array
    {
        $row = DB::table('gates_visits')->orderByDesc('id')->first();

        return $row === null ? null : (array) $row;
    }

    // ══ ONE ROW PER VISIT ═══════════════════════════════════════════════════

    public function test_an_arrival_is_recorded_once_however_many_pages_are_opened(): void
    {
        $key = VisitTracker::record($this->get('https://gates.test/awards'));

        $this->assertNotSame('', $key);
        $this->assertSame(1, (int) DB::table('gates_visits')->count());

        VisitTracker::record($this->get('https://gates.test/vote'));
        VisitTracker::record($this->get('https://gates.test/events'));

        $this->assertSame(1, (int) DB::table('gates_visits')->count(),
            'a reader who opens nine pages is one arrival — counting nine flatters whichever '
            . 'channel sent the wanderers');
    }

    public function test_a_new_session_is_a_new_arrival(): void
    {
        VisitTracker::record($this->get('https://gates.test/'));
        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/'));

        $this->assertSame(2, (int) DB::table('gates_visits')->count());
    }

    /** The key stored in the session is not the session id, which is a credential. */
    public function test_the_stored_key_is_not_the_session_id(): void
    {
        $key = VisitTracker::record($this->get('https://gates.test/'));

        $this->assertSame($key, $_SESSION[VisitTracker::SESSION_KEY]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
    }

    // ══ WHERE THEY CAME FROM ════════════════════════════════════════════════

    public function test_a_tagged_link_is_counted_by_the_name_the_organiser_gave_it(): void
    {
        VisitTracker::record($this->get(
            'https://gates.test/events/gala?utm_source=flier&utm_medium=print&utm_campaign=gala26'));

        $v = $this->lastVisit();
        $this->assertSame('flier', $v['source']);
        $this->assertSame('print', $v['medium']);
        $this->assertSame('gala26', $v['campaign']);
    }

    /**
     * One channel, one name.
     *
     * A WhatsApp forward arrives as l.facebook.com, a bare "whatsapp", or nothing at all
     * depending on the client it passed through. Listed separately, the channel that
     * matters most here is split five ways and looks like five small ones.
     */
    public function test_a_referrer_is_folded_to_the_name_a_person_would_say(): void
    {
        foreach ([
            'https://l.facebook.com/l.php?u=x' => 'facebook',
            'https://www.facebook.com/'        => 'facebook',
            'whatsapp'                          => 'whatsapp',
            'https://t.co/abc'                  => 'x',
            'https://www.google.com/search?q=y' => 'google',
            'https://partner.example.org/page'  => 'partner.example.org',
        ] as $referrer => $expected) {
            $_SESSION = [];
            VisitTracker::record($this->get('https://gates.test/', ['Referer' => $referrer]));

            $this->assertSame($expected, $this->lastVisit()['source'], 'from ' . $referrer);
        }
    }

    /** The organiser's own tag beats a referrer, because they already told us. */
    public function test_a_tag_beats_the_referrer(): void
    {
        VisitTracker::record($this->get('https://gates.test/?utm_source=newsletter',
                                        ['Referer' => 'https://www.facebook.com/']));

        $this->assertSame('newsletter', $this->lastVisit()['source']);
    }

    public function test_no_referrer_is_direct_rather_than_unknown(): void
    {
        VisitTracker::record($this->get('https://gates.test/'));

        $this->assertSame('direct', $this->lastVisit()['source']);
    }

    /** Our own pages are navigation, not arrival. */
    public function test_a_referrer_from_this_site_is_not_a_source(): void
    {
        VisitTracker::record($this->get('https://gates.test/vote',
                                        ['Referer' => 'https://gates.test/awards']));

        $v = $this->lastVisit();
        $this->assertSame('direct', $v['source']);
        $this->assertNull($v['referrer_host']);
    }

    // ══ WHAT IS NEVER STORED ════════════════════════════════════════════════

    /**
     * The landing QUERY is dropped, and this is the sharpest privacy rule here.
     *
     * This platform puts live credentials in links — an invitation pass, a magic sign-in,
     * a claim token. Storing the query would copy those into a table built to be read by
     * operators and exported to a spreadsheet.
     */
    public function test_a_landing_query_never_reaches_the_table(): void
    {
        VisitTracker::record($this->get(
            'https://gates.test/honour/AGI-K7M2QX4T?t=live-secret-token&utm_source=email'));

        $v = $this->lastVisit();
        // Redacted, not stored: the reference IS a credential — see safePath().
        $this->assertSame('/honour/*', $v['landing_path']);
        $this->assertStringNotContainsString('live-secret-token', json_encode($v));
        $this->assertStringNotContainsString('AGI-K7M2QX4T', json_encode($v));
        // The utm was still READ — dropping the query must not cost the attribution.
        $this->assertSame('email', $v['source']);
    }

    /**
     * A credential in the PATH is redacted too, and this was missed the first time.
     *
     * Dropping the query string is only half of it. `/honour/AGI-K7M2QX4T` is a guest of
     * honour's pass reference — the same string the support desk treats as the key to
     * their invitation — and it was being stored verbatim in a table that is browsable and
     * exportable from the admin console.
     */
    public function test_a_credential_in_the_path_never_reaches_the_table(): void
    {
        VisitTracker::record($this->get('https://gates.test/honour/AGI-K7M2QX4T'));

        $v = $this->lastVisit();
        $this->assertSame('/honour/*', $v['landing_path']);
        $this->assertStringNotContainsString('AGI-K7M2QX4T', json_encode($v));
    }

    /**
     * A list of known routes and a shape rule, because the failure mode is forgetting.
     *
     * The prefixes cover what exists; the shape covers the token-bearing URL somebody
     * writes next. Real slugs have to survive both — a report that redacted
     * `/nominee/incredible-principal-2026` would answer nothing.
     */
    public function test_token_shaped_segments_are_starred_and_real_slugs_are_not(): void
    {
        foreach ([
            '/honour/AGI-K7M2QX4T'                          => '/honour/*',
            '/claim/dispute/0f3a9c1b2d4e5f60718293a4b5c6d7e8' => '/claim/dispute/*',
            '/pay/AFG-VOTE-1a2b3c4d'                        => '/pay/*',
            '/x/0f3a9c1b2d4e5f60'                           => '/x/*',
            '/x/aB3xY9zQ7mN2pL5kR8tW'                       => '/x/*',
            // …and everything a real page looks like.
            '/'                                             => '/',
            '/awards'                                       => '/awards',
            '/nominee/incredible-principal-2026'            => '/nominee/incredible-principal-2026',
            '/events/gala-2026'                             => '/events/gala-2026',
            '/blog/why-character-matters'                   => '/blog/why-character-matters',
        ] as $in => $expected) {
            $this->assertSame($expected, VisitTracker::safePath($in), $in);
        }
    }

    /** The host, never the path: a referrer path carries somebody's search terms. */
    public function test_only_the_referrer_host_is_kept(): void
    {
        VisitTracker::record($this->get('https://gates.test/',
            ['Referer' => 'https://www.google.com/search?q=something+private+about+me']));

        $v = $this->lastVisit();
        $this->assertSame('www.google.com', $v['referrer_host']);
        $this->assertStringNotContainsString('private', json_encode($v));
    }

    /** A device class, not a browser string — and never a raw address. */
    public function test_the_reader_is_classed_rather_than_identified(): void
    {
        VisitTracker::record($this->get('https://gates.test/', [
            'User-Agent'       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605 Mobile/15E148',
            'CF-Connecting-IP' => '102.89.34.7',
        ]));

        $v = $this->lastVisit();
        $this->assertSame('mobile', $v['device']);
        $this->assertStringNotContainsString('iPhone', json_encode($v), 'no browser string is kept');
        $this->assertStringNotContainsString('102.89.34.7', json_encode($v), 'and no address');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $v['ip_hash']);
    }

    /**
     * The address hash is salted per day.
     *
     * The column exists to spot one machine refreshing a campaign link a thousand times. A
     * stable hash would be a permanent pseudonymous identifier for every visitor instead.
     */
    public function test_the_address_hash_does_not_follow_a_reader_across_days(): void
    {
        VisitTracker::record($this->get('https://gates.test/', ['CF-Connecting-IP' => '102.89.34.7']));
        $today = (string) $this->lastVisit()['ip_hash'];

        Carbon::setTestNow(Carbon::now()->addDay());
        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/', ['CF-Connecting-IP' => '102.89.34.7']));
        $tomorrow = (string) $this->lastVisit()['ip_hash'];
        Carbon::setTestNow();

        $this->assertNotSame($today, $tomorrow);
    }

    // ══ WHO IS NOT COUNTED ══════════════════════════════════════════════════

    /**
     * WhatsApp fetches every URL pasted into a chat.
     *
     * Counting those would make one link in a busy group look like a hundred visitors
     * before a single person had opened it. A human arriving FROM WhatsApp is identified
     * by the referrer, not the agent, so this costs the channel nothing.
     */
    public function test_link_previewers_and_bots_are_not_arrivals(): void
    {
        foreach (['WhatsApp/2.23', 'facebookexternalhit/1.1', 'Googlebot/2.1',
                  'curl/8.4.0', 'python-requests/2.31'] as $ua) {
            $_SESSION = [];
            VisitTracker::record($this->get('https://gates.test/', ['User-Agent' => $ua]));
        }

        $this->assertSame(0, (int) DB::table('gates_visits')->count());
    }

    public function test_a_reader_who_asked_not_to_be_tracked_is_not(): void
    {
        VisitTracker::record($this->get('https://gates.test/', ['DNT' => '1']));
        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/', ['Sec-GPC' => '1']));

        $this->assertSame(0, (int) DB::table('gates_visits')->count());
    }

    /** The console, the cron and the assets are not people arriving. */
    public function test_admin_cron_and_asset_paths_are_not_arrivals(): void
    {
        foreach (['/admin/analytics', '/judge/ballots', '/__cron/run', '/assets/app.css',
                  '/api/v1/nominees', '/favicon.ico', '/email/unsubscribe'] as $path) {
            $_SESSION = [];
            VisitTracker::record($this->get('https://gates.test' . $path));
        }

        $this->assertSame(0, (int) DB::table('gates_visits')->count());
    }

    public function test_a_post_is_not_an_arrival(): void
    {
        $r = (new ServerRequestFactory())->createServerRequest('POST', 'https://gates.test/vote')
            ->withHeader('User-Agent', 'Mozilla/5.0 Chrome/120');

        $this->assertSame('', VisitTracker::record($r));
        $this->assertSame(0, (int) DB::table('gates_visits')->count());
    }

    public function test_the_switch_stops_everything(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'visits_enabled', 'value' => '0']);

        $this->assertFalse(VisitTracker::enabled());
        $this->assertSame('', VisitTracker::record($this->get('https://gates.test/')));
        $this->assertSame(0, (int) DB::table('gates_visits')->count());
    }

    // ══ WHAT CAME OF IT ═════════════════════════════════════════════════════

    public function test_a_visit_that_leads_somewhere_is_stamped_once(): void
    {
        VisitTracker::record($this->get('https://gates.test/?utm_source=flier'));

        $this->assertTrue(VisitTracker::convert('vote'));
        // First wins. Somebody who votes and then buys a ticket is not two conversions,
        // and the first is the more useful answer: it is what the link was FOR.
        $this->assertFalse(VisitTracker::convert('ticket'));
        $this->assertSame('vote', $this->lastVisit()['converted_kind']);
    }

    public function test_a_conversion_with_no_arrival_behind_it_is_silent(): void
    {
        $_SESSION = [];

        $this->assertFalse(VisitTracker::convert('vote'), 'a cron run has no session and must not throw');
    }

    // ══ RETENTION ═══════════════════════════════════════════════════════════

    public function test_old_arrivals_are_pruned_and_recent_ones_are_not(): void
    {
        DB::table('gates_visits')->insert([
            ['visit_key' => str_repeat('a', 32), 'source' => 'direct', 'landing_path' => '/',
             'device' => 'desktop', 'created_at' => Carbon::now()->subDays(400)->toDateTimeString()],
            ['visit_key' => str_repeat('b', 32), 'source' => 'direct', 'landing_path' => '/',
             'device' => 'desktop', 'created_at' => Carbon::now()->subDays(3)->toDateTimeString()],
        ]);

        $this->assertSame(1, VisitTracker::prune());
        $this->assertSame(1, (int) DB::table('gates_visits')->count());
    }

    /**
     * A retention of zero would delete today's arrivals on the next tick.
     *
     * Clamped on READ as well as on save, because a row written by hand or by an older
     * screen must not be able to empty the table.
     */
    public function test_a_nonsense_retention_cannot_empty_the_table(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'visits_days', 'value' => '0']);
        $this->assertSame(VisitTracker::KEEP_DAYS, VisitTracker::keepDays());

        DB::table('gates_settings')->where('key_name', 'visits_days')->update(['value' => '1']);
        $this->assertSame(7, VisitTracker::keepDays(), 'a week is the floor');

        DB::table('gates_settings')->where('key_name', 'visits_days')->update(['value' => '99999']);
        $this->assertSame(730, VisitTracker::keepDays());
    }

    // ══ THE READER ══════════════════════════════════════════════════════════

    /**
     * A recorder with no reader is the most expensive bug available in this codebase.
     *
     * And the column that makes the report worth opening is the RATE: four hundred
     * arrivals that do nothing is a worse Tuesday than thirty that vote.
     */
    public function test_the_report_says_where_they_came_from_and_what_came_of_it(): void
    {
        foreach (['flier', 'flier', 'flier', 'whatsapp'] as $i => $src) {
            $_SESSION = [];
            VisitTracker::record($this->get('https://gates.test/?utm_source=' . $src . '&utm_campaign=gala26'));
            if ($i === 0) VisitTracker::convert('vote');
        }

        $r = AnalyticsService::arrivals(30, 12);

        $this->assertTrue($r['tracking']);
        $this->assertSame(4, $r['total']);
        $this->assertSame(1, $r['converted']);
        $this->assertSame(25, $r['rate']);

        $this->assertSame('flier', $r['sources'][0]['source'], 'ordered by arrivals');
        $this->assertSame(3, $r['sources'][0]['visits']);
        $this->assertSame(33, $r['sources'][0]['rate'], 'one of three');

        $this->assertSame('gala26', $r['campaigns'][0]['campaign']);
        $this->assertSame([['kind' => 'vote', 'count' => 1]], $r['kinds']);
    }

    /** Zeroes with the recorder off read as "nobody came" unless the screen can tell them apart. */
    public function test_the_report_says_whether_anything_is_being_recorded(): void
    {
        DB::table('gates_settings')->insert(['key_name' => 'visits_enabled', 'value' => '0']);

        $this->assertFalse(AnalyticsService::arrivals(30)['tracking']);
    }

    /**
     * The rows written before the redaction existed are repaired, not left.
     *
     * Fixing the writer protects every arrival from now on and does nothing at all for the
     * ones already in a table that is browsable and exportable from the admin console.
     * This codebase has paid for that distinction once already — a corrected column
     * definition that only applied to databases with no rows left production on the old
     * one forever.
     */
    public function test_paths_recorded_before_the_redaction_are_repaired(): void
    {
        DB::table('gates_visits')->insert([
            ['visit_key' => str_repeat('c', 32), 'source' => 'email',
             'landing_path' => '/honour/AGI-K7M2QX4T', 'device' => 'mobile',
             'created_at' => Carbon::now()->toDateTimeString()],
            ['visit_key' => str_repeat('d', 32), 'source' => 'direct',
             'landing_path' => '/nominee/incredible-principal-2026', 'device' => 'desktop',
             'created_at' => Carbon::now()->toDateTimeString()],
        ]);

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/database/migrations/2026_11_08_visit_path_redact.php';
        } finally {
            ob_end_clean();
        }

        $paths = DB::table('gates_visits')->orderBy('id')->pluck('landing_path')->all();

        $this->assertSame(['/honour/*', '/nominee/incredible-principal-2026'], $paths,
            'the credential goes and the real slug stays');
    }

    /**
     * Production's top landing pages were `/feed`, `/events/feed` and
     * `/wp-json/tribe/events/v1/events`, which are the old WordPress site's addresses,
     * still polled by feed readers and scanners. Every one was a 404, and every one was
     * counted, because the row is written before the handler runs.
     */
    public function test_a_path_the_site_does_not_have_is_not_an_arrival(): void
    {
        $mw = new \AfricaGates\Middleware\VisitTrackingMiddleware();

        $thrown = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(Request $r): \Psr\Http\Message\ResponseInterface {
                throw new \Slim\Exception\HttpNotFoundException($r);
            }
        };
        try {
            $mw->process($this->get('https://gates.test/wp-json/tribe/events/v1/events'), $thrown);
            $this->fail('the 404 must still reach the error handler');
        } catch (\Slim\Exception\HttpNotFoundException) {}

        $this->assertSame(0, (int) DB::table('gates_visits')->count(), 'an unrouted path is nobody reading');
        $this->assertArrayNotHasKey(VisitTracker::SESSION_KEY, $_SESSION,
            'and the session is left free to record the page they do reach');

        foreach ([404, 410, 301, 302] as $status) {
            $_SESSION = [];
            $mw->process($this->get('https://gates.test/events/feed'), $this->answering($status));
        }
        $this->assertSame(0, (int) DB::table('gates_visits')->count(), 'nor a 404 a handler returned, nor a redirect');

        $_SESSION = [];
        $mw->process($this->get('https://gates.test/events'), $this->answering(200));
        $this->assertSame(1, (int) DB::table('gates_visits')->count(), 'a page is still an arrival');
        $this->assertSame('/events', $this->lastVisit()['landing_path']);
    }

    /** Taking a 404 back must never take back the session's real arrival. */
    public function test_a_later_404_does_not_undo_the_arrival(): void
    {
        $mw = new \AfricaGates\Middleware\VisitTrackingMiddleware();
        $mw->process($this->get('https://gates.test/discover'), $this->answering(200));
        $mw->process($this->get('https://gates.test/no-such-page'), $this->answering(404));

        $this->assertSame(1, (int) DB::table('gates_visits')->count());
        $this->assertSame('/discover', $this->lastVisit()['landing_path']);
    }

    /**
     * A script that borrows a browser's User-Agent rarely borrows its fetch headers too. A feed
     * reader asks for RSS, an API scanner for JSON or anything, and a browser's own background
     * fetch says `cors`. An old browser with no Sec-Fetch headers but an HTML Accept is a reader.
     */
    public function test_only_a_page_navigation_is_an_arrival(): void
    {
        $refused = [
            ['Accept' => 'application/rss+xml, application/xml', 'Sec-Fetch-Mode' => null, 'Sec-Fetch-Dest' => null],
            ['Accept' => 'application/json', 'Sec-Fetch-Mode' => null, 'Sec-Fetch-Dest' => null],
            ['Accept' => '*/*', 'Sec-Fetch-Mode' => null, 'Sec-Fetch-Dest' => null],
            ['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'],
            ['Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe'],
        ];
        foreach ($refused as $h) {
            $_SESSION = [];
            VisitTracker::record($this->get('https://gates.test/', $h));
        }
        $this->assertSame(0, (int) DB::table('gates_visits')->count());

        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/', ['Sec-Fetch-Mode' => null, 'Sec-Fetch-Dest' => null]));
        $this->assertSame(1, (int) DB::table('gates_visits')->count(), 'an older browser still counts');
    }

    /** Production recorded an exploit probe's Referer as a source. */
    public function test_a_forged_referrer_is_not_an_arrival(): void
    {
        VisitTracker::record($this->get('https://gates.test/', [
            'Referer' => '554fcae493e564ee0dc75bdf2ebf94caads|a:2:{s:3:"num";s:72:"0,1 union select 1"}',
        ]));
        $this->assertSame(0, (int) DB::table('gates_visits')->count());

        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/', ['Referer' => 'android-app://com.google.android.gm/']));
        $this->assertSame(1, (int) DB::table('gates_visits')->count(), 'an app referrer is not forged');
    }

    public function test_arrivals_that_were_never_a_page_are_repaired(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $row = static fn (string $k, string $path, string $src = 'direct') => [
            'visit_key' => str_repeat($k, 32), 'source' => $src, 'landing_path' => $path,
            'device' => 'desktop', 'created_at' => $now,
        ];
        DB::table('gates_visits')->insert([
            $row('a', '/feed'), $row('b', '/events/feed'), $row('c', '/wp-json/tribe/events/v1/events'),
            $row('d', '/wp-login.php'), $row('e', '/', '554fcae493e564ee0dc75bdf2ebf94caads|a:2:{s:3:"num"'),
            $row('f', '/events'), $row('g', '/'), $row('h', '/vote/x/1', 'gala26 flier'), $row('i', '/discover', 'google'),
        ]);

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/database/migrations/2027_04_08_visit_noise_repair.php';
        } finally {
            ob_end_clean();
        }

        $this->assertSame(['/events', '/', '/vote/x/1', '/discover'],
            DB::table('gates_visits')->orderBy('id')->pluck('landing_path')->all());
    }

    private function answering(int $status): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class($status) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private int $status) {}
            public function handle(Request $r): \Psr\Http\Message\ResponseInterface {
                return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse($this->status);
            }
        };
    }

    /** Recorded on the way in, and pruned by the maintenance run. */
    public function test_it_is_wired_into_the_request_and_the_sweep(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $maint = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');

        $this->assertStringContainsString('VisitTrackingMiddleware', $index,
            'a tracker nothing calls records nothing');
        $this->assertStringContainsString('VisitTracker::prune()', $maint,
            'and a recorder with no pruner grows for the life of the deployment');
    }


    // ══ A VISIT, AS EVERY ANALYTICS TOOL COUNTS ONE (owner, 28 Oct 2026) ═════

    /**
     * A visit ends after 30 minutes without a page view (GA4, Adobe and Plausible all use
     * 30). It used to be one row per PHP session, which lives a week, so somebody who came
     * back every evening was one arrival and every source after the first was invisible.
     */
    public function test_a_visit_ends_after_thirty_idle_minutes_and_the_next_is_a_return(): void
    {
        $mw = new \AfricaGates\Middleware\VisitTrackingMiddleware();
        $mw->process($this->get('https://gates.test/awards?utm_source=flier'), $this->answering(200));
        $mw->process($this->get('https://gates.test/vote'), $this->answering(200));
        $mw->process($this->get('https://gates.test/nope'), $this->answering(404));
        $first = $this->lastVisit();
        $this->assertSame(2, (int) $first['pages'], 'a second page counts; a 404 is not a page read');
        $this->assertSame(0, (int) $first['is_return']);

        Carbon::setTestNow(Carbon::now()->addMinutes(29));
        $mw->process($this->get('https://gates.test/events'), $this->answering(200));
        $this->assertSame(1, (int) DB::table('gates_visits')->count(), 'still the same visit at 29 minutes');
        $this->assertSame(3, (int) $this->lastVisit()['pages']);

        Carbon::setTestNow(Carbon::now()->addMinutes(31));
        $mw->process($this->get('https://gates.test/', ['Referer' => 'https://l.facebook.com/']), $this->answering(200));
        Carbon::setTestNow();
        $this->assertSame(2, (int) DB::table('gates_visits')->count(), 'thirty idle minutes ends a visit');
        $second = $this->lastVisit();
        $this->assertSame('facebook', $second['source'], 'and the new visit has its own source');
        $this->assertSame(1, (int) $second['is_return'], 'from a browser that has been here before');
        $this->assertSame('flier', DB::table('gates_visits')->orderBy('id')->value('source'), 'the first keeps its own');
    }

    /** A page fetched in case it is wanted has not been looked at by anybody. */
    public function test_prefetches_scripts_without_a_language_and_staff_are_not_counted(): void
    {
        foreach ([['Sec-Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['Purpose' => 'prefetch'],
                  ['X-Moz' => 'prefetch'], ['X-Purpose' => 'preview'], ['Accept-Language' => null],
                  ['User-Agent' => 'python-urllib/3.11'], ['User-Agent' => 'Mozilla/5.0 Chrome-Lighthouse'],
                  ['User-Agent' => 'axios/1.6.0'], ['User-Agent' => 'Go-http-client/2.0']] as $h) {
            $_SESSION = [];
            VisitTracker::record($this->get('https://gates.test/', $h));
            $this->assertSame(0, (int) DB::table('gates_visits')->count(), json_encode($h));
        }

        $_SESSION = ['admin_id' => 7];
        VisitTracker::record($this->get('https://gates.test/awards'));
        $this->assertSame(0, (int) DB::table('gates_visits')->count(), 'staff checking their own pages are not the audience');

        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/'));
        $this->assertSame(1, (int) DB::table('gates_visits')->count(), 'a plain browser still is');
    }

    /**
     * The address hash was sha256(ip|date): four billion guesses recover any IPv4 address,
     * so the table held every visitor's address to anybody who cared to look. The salt is
     * random now and replaced daily. And people are told apart by browser as well as
     * network, because a mobile carrier puts thousands of phones behind one address.
     */
    public function test_the_hashes_cannot_be_reversed_and_tell_two_phones_on_one_network_apart(): void
    {
        $ip = '102.89.34.7';
        VisitTracker::record($this->get('https://gates.test/', ['CF-Connecting-IP' => $ip]));
        $a = $this->lastVisit();
        $this->assertNotSame(hash('sha256', $ip . '|' . Carbon::now()->toDateString()), $a['ip_hash'], 'not guessable from the date');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $a['visitor_hash']);

        $_SESSION = [];
        VisitTracker::record($this->get('https://gates.test/', ['CF-Connecting-IP' => $ip,
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36']));
        $b = $this->lastVisit();
        $this->assertSame($a['ip_hash'], $b['ip_hash'], 'one network');
        $this->assertNotSame($a['visitor_hash'], $b['visitor_hash'], 'two people');

        $salt = VisitTracker::salt();
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertNotSame($salt, VisitTracker::salt(), 'and yesterday\'s salt is gone');
        $this->assertStringNotContainsString($salt, (string) DB::table('gates_settings')->where('key_name', 'visits_salt')->value('value'));
        Carbon::setTestNow();
    }
}
