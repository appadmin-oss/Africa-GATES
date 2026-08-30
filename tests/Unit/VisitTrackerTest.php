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
    }

    private function get(string $uri, array $headers = []): Request
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', $uri);

        // A plain browser unless a test says otherwise. Without one, looksLikeBot() is
        // right to refuse: no agent at all is a script, not a reader.
        $headers += ['User-Agent' => 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36'];
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
        $this->assertSame('/honour/AGI-K7M2QX4T', $v['landing_path']);
        $this->assertStringNotContainsString('live-secret-token', json_encode($v));
        // The utm was still READ — dropping the query must not cost the attribution.
        $this->assertSame('email', $v['source']);
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
}
