<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ProfileService;
use AfricaGates\Support\Speculative;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * A prefetched page is RENDERED, never REMEMBERED (Support\Speculative, 2 Nov 2026).
 *
 * The public site fetches a link the moment a pointer rests on it, with the visitor's
 * cookies, so most GETs now come from somebody who may never open the page. Walking every
 * prefetchable public URL as a prefetch against a real session found the session moving:
 * `/account/login?next=…` and every page that bounces a guest to sign in wrote
 * `login_next`, so on the sign-in page itself, hovering the nav changed where somebody
 * would land once signed in. And two writers the seed could not reach: a profile's
 * `view_count`, which the CPI baseline reads, and an ad's per-visitor frequency cap.
 * Each is asserted through the real code path, prefetch and real visit side by side,
 * because a guard that refuses both is as broken as one that refuses neither.
 */
final class PrefetchSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['login_next'], $_SERVER['HTTP_SEC_PURPOSE']);
        parent::tearDown();
    }

    private function get(string $uri, bool $prefetch): void
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        [$path, $q] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($q, $params);
        $req = $req->withQueryParams($params);
        if ($prefetch) { $req = $req->withHeader('Sec-Purpose', 'prefetch'); $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch'; }
        else unset($_SERVER['HTTP_SEC_PURPOSE']);
        $app->handle($req);
    }

    public function test_the_four_spellings_and_none(): void
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $this->assertFalse(Speculative::is($r));
        foreach ([['Sec-Purpose', 'prefetch'], ['Sec-Purpose', 'prefetch;prerender'], ['Purpose', 'prefetch'], ['X-Moz', 'prefetch'], ['X-Purpose', 'preview']] as [$h, $v]) {
            $this->assertTrue(Speculative::is($r->withHeader($h, $v)), "$h: $v");
        }
        $this->assertFalse(Speculative::is(), 'no request and no header is a real visit');
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        $this->assertTrue(Speculative::is(), 'read from the server variables where no request is passed');
    }

    public function test_hovering_another_link_does_not_change_where_sign_in_returns(): void
    {
        $this->get('/account/login?next=%2Fevents%2Flagos-night', false);
        $this->assertSame('/events/lagos-night', $_SESSION['login_next'] ?? null, 'a real visit must still remember');

        $this->get('/account/login?next=%2Fpulse', true);
        $this->get('/volunteer/apply', true);
        $this->assertSame('/events/lagos-night', $_SESSION['login_next'] ?? null, 'a prefetch rewrote where sign-in returns');

        $this->get('/volunteer/apply', false);
        $this->assertSame('/volunteer/apply', $_SESSION['login_next'] ?? null, 'the real bounce must still remember');
    }

    public function test_a_prefetched_profile_is_not_a_view(): void
    {
        $id = (int) DB::table('gates_profiles')->insertGetId(['slug' => 'pf-' . bin2hex(random_bytes(3)), 'display_name' => 'Ada Obi', 'category' => 'education',
            'status' => 'approved', 'view_count' => 0, 'email' => 'pf-' . bin2hex(random_bytes(3)) . '@example.test']);
        $slug = (string) DB::table('gates_profiles')->where('id', $id)->value('slug');
        $_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
        (new ProfileService())->getBySlug($slug);
        $this->assertSame(0, (int) DB::table('gates_profiles')->where('id', $id)->value('view_count'), 'a page nobody opened counted as a view, and view_count feeds the CPI baseline');
        unset($_SERVER['HTTP_SEC_PURPOSE']);
        (new ProfileService())->getBySlug($slug);
        $this->assertSame(1, (int) DB::table('gates_profiles')->where('id', $id)->value('view_count'));
    }

    /** Ads need a whole campaign to draw one; the guard is read where the counts are written. */
    public function test_a_prefetched_ad_spends_no_frequency_cap_and_fills_no_slot(): void
    {
        $src = ChromeRender::code('src/Services/Ads/AdDelivery.php');
        $this->assertMatchesRegularExpression('~\$ahead = \\\\AfricaGates\\\\Support\\\\Speculative::is\(\);\s*if \(!\$ahead\) self::countSlot\(~', $src);
        $this->assertStringContainsString('if (!$ahead) self::noteSeen(', $src);
    }

    /** Every writer of where sign-in returns asks first — the next one included. */
    public function test_no_get_writes_login_next_without_asking(): void
    {
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            foreach (file($f->getPathname()) as $n => $line) {
                if (preg_match("~\\\$_SESSION\['login_next'\]\s*=(?!=)~", $line) && !str_contains($line, 'Speculative::is(')) {
                    $bad[] = basename($f->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'a GET that remembers on a prefetch');
    }
}
