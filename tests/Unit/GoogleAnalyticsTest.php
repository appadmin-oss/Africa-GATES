<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CookiePrefs;
use AfricaGates\Services\GoogleAnalytics;
use AfricaGates\Support\CookieRegistry;
use AfricaGates\Support\Csp;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Response;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Google Analytics 4 as an option (owner, 28 Oct 2026).
 *
 * What is held, and why each is worth a test:
 *   · off until a measurement ID is saved, and then the cookie page says so either way;
 *   · it loads ONLY on an explicit yes to Analytics: never on the default posture, never
 *     past Global Privacy Control, never for staff signed in to the console;
 *   · Google is sent the redacted page, never a pass or a sign-in token;
 *   · a no deletes Google's cookies, not only the next page's script;
 *   · the policy, the registry and the notice name Google while it is on.
 */
final class GoogleAnalyticsTest extends TestCase
{
    private const ID = 'G-AB12CD34EF';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', GoogleAnalytics::SETTING)->delete();
        $_SESSION = [];
    }

    private function on(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => GoogleAnalytics::SETTING], ['value' => self::ID]);
    }

    /** @param array<string,?bool> $a */
    private function consent(array $a): array
    {
        return [CookiePrefs::COOKIE => rawurlencode((string) json_encode(['v' => CookiePrefs::VERSION] + $a + ['preferences' => null, 'analytics' => null, 'marketing' => null]))];
    }

    private function page(string $uri, array $cookies = [], array $session = [], array $headers = []): string
    {
        return (string) ChromeRender::page($uri, $session, $cookies, 'GET', null, $headers + [
            'User-Agent' => 'Mozilla/5.0 Chrome/120', 'Accept' => 'text/html', 'Accept-Language' => 'en-GB',
            'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document',
        ])->getBody();
    }

    public function test_it_is_off_until_an_id_is_saved_and_the_policy_says_so(): void
    {
        $this->assertNull(GoogleAnalytics::measurementId());
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $this->page('/', $this->consent(['analytics' => true])));
        $this->assertStringContainsString('There is no Google Analytics here', ChromeRender::html('/cookies'));
        $this->assertNotContains('_ga', CookieRegistry::names());
    }

    public function test_it_loads_only_on_an_explicit_yes(): void
    {
        $this->on();
        $yes = $this->page('/', $this->consent(['analytics' => true]));
        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=' . self::ID, $yes);
        $this->assertStringContainsString('data-ga-id="' . self::ID . '"', $yes);
        $this->assertStringContainsString('/assets/js/ga.js', $yes);

        $this->assertSame(CookiePrefs::MODE_EXEMPT, CookiePrefs::mode());
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $this->page('/'),
            'the default posture counts visits ourselves; it never hands anybody to Google');
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $this->page('/', $this->consent(['analytics' => false])));
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $this->page('/', $this->consent(['analytics' => true]), [], ['Sec-GPC' => '1']),
            'Global Privacy Control beats a stored yes');
        $this->assertStringNotContainsString('googletagmanager.com/gtag', $this->page('/', $this->consent(['analytics' => true]), ['admin_id' => 3]),
            'staff checking their own pages are not the audience');
    }

    /** Links here carry passes and sign-in tokens; Google is told what our own table keeps. */
    public function test_google_is_sent_the_redacted_page_and_the_utm_tags_only(): void
    {
        $this->on();
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', 'https://gates.test/honour/AGI-K7M2QX4T?token=s3cr3t&utm_source=flier&utm_campaign=gala26')
            ->withQueryParams(['token' => 's3cr3t', 'utm_source' => 'flier', 'utm_campaign' => 'gala26'])
            ->withCookieParams($this->consent(['analytics' => true]));
        $this->assertSame('/honour/*?utm_source=flier&utm_campaign=gala26', GoogleAnalytics::forRequest($req)['path'],
            'the pass reference is starred and the token dropped');

        // And on a page as drawn.
        $html = $this->page('/awards?token=s3cr3t&utm_source=flier', $this->consent(['analytics' => true]));
        $this->assertStringContainsString('data-ga-path="/awards?utm_source=flier"', $html);
        $this->assertStringNotContainsString('s3cr3t', substr($html, (int) strpos($html, 'data-ga-path')));

        $js = ChromeRender::code('public/assets/js/ga.js');
        $this->assertStringContainsString("cookie_domain: 'none'", $js, 'host-only, so a no can delete them');
        $this->assertStringContainsString('allow_google_signals: false', $js);
        $this->assertStringContainsString('allow_ad_personalization_signals: false', $js);
        $this->assertStringContainsString("ad_storage: 'denied'", $js);
        $this->assertStringContainsString('new URL(document.referrer).origin', $js, 'the referrer is cut to its origin');
    }

    public function test_a_no_deletes_googles_cookies(): void
    {
        $_COOKIE = ['_ga' => 'GA1.1.123.456', '_ga_AB12CD34EF' => 'GS1.1.x', 'other' => '1'];
        $set = CookiePrefs::write(new Response(), ['preferences' => true, 'analytics' => false, 'marketing' => false])->getHeader('Set-Cookie');
        $_COOKIE = [];
        $joined = implode("\n", $set);
        $this->assertStringContainsString('_ga=; Path=/; Max-Age=0', $joined);
        $this->assertStringContainsString('_ga_AB12CD34EF=; Path=/; Max-Age=0', $joined);
        $this->assertStringNotContainsString('other=', $joined);

        $_COOKIE = ['_ga' => 'GA1.1.123.456'];
        $kept = implode("\n", CookiePrefs::write(new Response(), ['preferences' => true, 'analytics' => true, 'marketing' => false])->getHeader('Set-Cookie'));
        $_COOKIE = [];
        $this->assertStringNotContainsString('_ga=;', $kept, 'a yes keeps them');
    }

    public function test_while_it_is_on_the_policy_the_registry_and_the_notice_name_google(): void
    {
        $this->on();
        $this->assertContains('_ga', CookieRegistry::names());
        $this->assertContains('_ga_AB12CD34EF', CookieRegistry::names());

        $policy = ChromeRender::html('/cookies');
        $this->assertStringContainsString('only if you say yes to Analytics', $policy);
        $this->assertStringNotContainsString('There is no Google Analytics here', $policy);

        $analytics = array_values(array_filter(CookieRegistry::categories(), static fn ($c) => $c['key'] === 'analytics'))[0];
        $this->assertStringContainsString('Google Analytics', $analytics['desc']);
        $this->assertStringNotContainsString('nothing reaches anyone else', $analytics['desc']);

        $this->assertStringContainsString('Google Analytics also measures visits', $this->page('/'), 'said in the notice before anybody answers');
    }

    public function test_only_a_real_measurement_id_is_kept_and_the_policy_allows_google(): void
    {
        $this->assertSame(self::ID, GoogleAnalytics::clean(' g-ab12cd34ef '));
        foreach (['', 'UA-12345-1', 'G-', 'G-ab"><script>', 'GTM-ABC123'] as $bad) {
            $this->assertNull(GoogleAnalytics::clean($bad), $bad);
        }
        DB::table('gates_settings')->updateOrInsert(['key_name' => GoogleAnalytics::SETTING], ['value' => 'G-"><x']);
        $this->assertNull(GoogleAnalytics::measurementId(), 'a stored value is re-checked on the way out');

        $this->assertStringContainsString('https://www.googletagmanager.com', Csp::SCRIPT_HOSTS);
        $this->assertStringContainsString('https://*.google-analytics.com', Csp::CONNECT_HOSTS);
        $this->assertStringContainsString('https://*.analytics.google.com', (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess'));
    }
}
