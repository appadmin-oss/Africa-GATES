<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Middleware\CsrfMiddleware;
use AfricaGates\Support\FormReplay;
use AfricaGates\Support\SessionStore;
use Psr\Http\Message\ResponseInterface as Res;
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Support\TestApp;
use Tests\TestCase;

/**
 * "CSRF validation failed" — and nowhere to go (owner, 8 Oct 2026: the site tells users
 * csrf invalid and they get stuck there).
 *
 * Two faults, each held here:
 *
 *   · THE TOKEN DIED UNDER AN OPEN PAGE. The cookie lasted seven days and the session data
 *     behind it about twenty-four minutes (PHP's default `gc_maxlifetime`, in the host's
 *     shared directory), so any form left open that long posted a token the server had
 *     already forgotten. SessionStore gives the data the cookie's lifetime in a directory
 *     nobody else collects; csrf-fresh.js keeps an open page's token current.
 *
 *   · THE REFUSAL WAS A DEAD END: a 403 and a line of JSON, to a browser that had posted a
 *     form. Now a same-origin browser form is sent back (303) to the page it came from,
 *     told in words, with what it typed kept — minus anything secret. A cross-site post
 *     and a JSON caller are still simply refused.
 */
final class CsrfRecoveryTest extends TestCase
{
    private const HOST = 'https://afg.local';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['csrf_token'] = 'live-token';
        unset($_SESSION['flash_error'], $_SESSION['form_replay']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['flash_error'], $_SESSION['form_replay']);
        parent::tearDown();
    }

    private function handler(bool &$reached): Handler
    {
        return new class($reached) implements Handler {
            public function __construct(private bool &$r) {}
            public function handle(Req $q): Res { $this->r = true; return new Response(200); }
        };
    }

    private function post(string $path, array $headers, array $body): array
    {
        $r = (new ServerRequestFactory())->createServerRequest('POST', self::HOST . $path)->withParsedBody($body);
        foreach ($headers as $k => $v) $r = $r->withHeader($k, $v);
        $reached = false;
        $res = (new CsrfMiddleware())($r, $this->handler($reached));

        return [$res, $reached];
    }

    private const FORM = ['_token' => 'dead-token', 'name' => 'Ngozi Adichie', 'reason' => "Line one\nline two",
        'categories' => ['3', '7'], 'password' => 'hunter22', 'otp' => '123456', 'card_number' => '4111'];

    public function test_a_browser_form_with_a_stale_token_is_sent_back_with_words_and_its_fields(): void
    {
        [$res, $reached] = $this->post('/nominate', [
            'Origin' => self::HOST, 'Referer' => self::HOST . '/nominate/education?step=2', 'Accept' => 'text/html,*/*',
        ], self::FORM);

        $this->assertFalse($reached, 'the refusal stands: nothing about the request is accepted');
        $this->assertSame(303, $res->getStatusCode(), 'a form gets a way back, not a 403 and a line of JSON');
        $this->assertSame('/nominate/education?step=2', $res->getHeaderLine('Location'));
        $this->assertSame(CsrfMiddleware::EXPIRED_MESSAGE, $_SESSION['flash_error']);
        $this->assertStringNotContainsString('CSRF', CsrfMiddleware::EXPIRED_MESSAGE, 'a visitor is never shown the acronym');

        $kept = json_decode((string) $_SESSION['form_replay'], true);
        $this->assertSame('/nominate', $kept['action']);
        $this->assertSame([['name', 'Ngozi Adichie'], ['reason', "Line one\nline two"], ['categories[]', '3'], ['categories[]', '7']],
            $kept['fields'], 'named as the form names them, and nothing secret: no token, password, code or card');
    }

    public function test_an_expired_session_is_the_same_recoverable_refusal(): void
    {
        unset($_SESSION['csrf_token']);
        [$res, $reached] = $this->post('/nominate', ['Origin' => self::HOST, 'Accept' => 'text/html'], ['_token' => '']);
        $this->assertFalse($reached, '`hash_equals("", "")` must never let an empty session through');
        $this->assertSame(303, $res->getStatusCode());
        $this->assertSame('/nominate', $res->getHeaderLine('Location'), 'no Referer: back to the address it posted to');
    }

    public function test_a_cross_site_post_is_refused_and_leaves_nothing_behind(): void
    {
        foreach ([['Origin' => 'https://evil.example', 'Accept' => 'text/html'], ['Accept' => 'text/html']] as $h) {
            [$res, $reached] = $this->post('/account/settings', $h, self::FORM);
            $this->assertFalse($reached);
            $this->assertSame(403, $res->getStatusCode(), 'no Origin proven to be ours: a refusal and nothing else');
            $this->assertArrayNotHasKey('form_replay', $_SESSION, 'a stranger cannot seed a visitor\'s form');
            $this->assertArrayNotHasKey('flash_error', $_SESSION);
        }
    }

    public function test_a_script_asking_for_json_gets_json_it_can_act_on(): void
    {
        foreach ([['X-CSRF-Token' => 'dead-token', 'Origin' => self::HOST], ['X-Requested-With' => 'XMLHttpRequest', 'Origin' => self::HOST],
                  ['Accept' => 'application/json', 'Origin' => self::HOST]] as $h) {
            [$res, $reached] = $this->post('/admin/nominees/1/approve', $h, ['_token' => 'dead-token']);
            $this->assertFalse($reached);
            $this->assertSame(403, $res->getStatusCode());
            $body = json_decode((string) $res->getBody(), true);
            $this->assertSame('CSRF_EXPIRED', $body['code']);
            $this->assertSame(CsrfMiddleware::EXPIRED_MESSAGE, $body['message']);
        }
    }

    public function test_the_way_back_is_always_a_path_on_this_host(): void
    {
        $req = fn (string $ref) => (new ServerRequestFactory())->createServerRequest('POST', self::HOST . '/shop/cart')->withHeader('Referer', $ref);
        $this->assertSame('/shop/cart', CsrfMiddleware::backTo($req('https://evil.example/phish')), 'a foreign Referer is ignored');
        $this->assertSame('/', CsrfMiddleware::backTo($req('https://afg.local//evil.example/x')), 'never a protocol-relative address');
        $this->assertSame('/shop?x=1', CsrfMiddleware::backTo($req('https://AFG.local/shop?x=1')));
    }

    public function test_kept_fields_are_capped_and_read_once(): void
    {
        FormReplay::keep('/x', ['body' => str_repeat('a', 70000)]);
        $this->assertNull(FormReplay::take(), 'past the cap nothing is kept rather than a truncated form');

        FormReplay::keep('/x', ['a' => ['b' => '1', 'c' => '2'], 'pin' => '0000', 'new_password' => 'x', 'code' => '999']);
        $this->assertSame('{"action":"\/x","fields":[["a[b]","1"],["a[c]","2"]]}', FormReplay::take());
        $this->assertNull(FormReplay::take(), 'a second page view does not see them again');
    }

    public function test_the_page_it_lands_on_carries_the_message_and_the_fields_once(): void
    {
        FormReplay::keep('/nominate', ['name' => 'Ngozi "N" <Adichie>']);
        $_SESSION['flash_error'] = CsrfMiddleware::EXPIRED_MESSAGE;

        $get = fn () => (string) TestApp::build()->handle((new ServerRequestFactory())->createServerRequest('GET', self::HOST . '/cookies'))->getBody();
        $html = $get();
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('page had been open for a while', $html);
        $this->assertMatchesRegularExpression('~<meta name="ag-form-replay" content="([^"]+)">~', $html);
        preg_match('~<meta name="ag-form-replay" content="([^"]+)">~', $html, $m);
        $this->assertSame([['name', 'Ngozi "N" <Adichie>']], json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['fields'],
            'escaped as an attribute and decoded back to exactly what was typed');
        $this->assertStringContainsString('/assets/js/csrf-fresh.js', $html);

        $this->assertStringNotContainsString('ag-form-replay', $get(), 'consumed by the first view');
    }

    public function test_an_open_page_can_ask_for_the_current_token(): void
    {
        $res = TestApp::build()->handle((new ServerRequestFactory())->createServerRequest('GET', self::HOST . '/session/token.json'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(['token' => 'live-token'], json_decode((string) $res->getBody(), true));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'), 'a token must never sit in a cache');
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', implode("\n", array_keys($res->getHeaders())),
            'readable by this site\'s own pages only');
    }

    public function test_the_session_lasts_as_long_as_its_cookie_in_a_directory_nobody_else_collects(): void
    {
        $saved = [ini_get('session.gc_maxlifetime'), session_save_path()];
        $root = sys_get_temp_dir() . '/ss-' . bin2hex(random_bytes(4));
        mkdir($root);
        try {
            $this->assertSame($root . '/var/sessions', SessionStore::configure($root));
            $this->assertSame((string) SessionStore::LIFETIME, ini_get('session.gc_maxlifetime'), 'not PHP\'s 24 minutes');
            $this->assertSame($root . '/var/sessions', session_save_path());
            $this->assertSame('1', ini_get('session.gc_probability'), 'a private directory is collected by us or by nobody');

            $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
            // The CALL, not the comment above it that also says `session_start()`.
            $this->assertMatchesRegularExpression('~SessionStore::configure\(.*^\s*session_start\(\);~ms', $index, 'configured before the session starts');
            $this->assertStringContainsString("'lifetime' => \\AfricaGates\\Support\\SessionStore::LIFETIME", $index, 'one number for the cookie and the data');
            $this->assertStringContainsString('Require all denied', (string) file_get_contents(dirname(__DIR__, 2) . '/var/.htaccess'),
                'session files sit under var/, which is never served');
        } finally {
            ini_set('session.gc_maxlifetime', (string) $saved[0]);
            session_save_path((string) $saved[1]);
            @rmdir($root . '/var/sessions'); @rmdir($root . '/var'); @rmdir($root);
        }
    }

    /** Every layout that draws a token also keeps it fresh — a layout added later is held too. */
    public function test_every_layout_that_draws_a_token_keeps_it_fresh(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $missing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $f) {
            if (!$f->isFile() || !str_ends_with((string) $f, '.twig')) continue;
            $body = (string) file_get_contents((string) $f);
            if (stripos($body, '<!doctype') === false || str_contains((string) $f, '/emails/')) continue;
            if (!str_contains($body, 'csrf_token') && !str_contains($body, '_token')) continue;
            if (!str_contains($body, "include 'partials/csrf-fresh.twig'")) $missing[] = str_replace($root . '/', '', (string) $f);
        }
        $this->assertSame([], $missing);
    }

    /**
     * A script that copies the token once at load posts the dead one for the rest of the
     * page's life, whatever csrf-fresh.js writes into the page afterwards. So a token read
     * at the top level of a script (two-space indent inside its IIFE) may only be SENT from
     * a line that also reads it live.
     */
    public function test_no_script_posts_a_token_it_copied_at_load(): void
    {
        $this->assertSame([], $this->stale((string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js'), 'admin.js'));
        $bad = [];
        foreach (glob(dirname(__DIR__, 2) . '/public/assets/js/*.js') as $f) {
            array_push($bad, ...$this->stale((string) file_get_contents($f), basename($f)));
        }
        $this->assertSame([], $bad);

        // The sweep can fail: the shape this change removed from admin.js.
        $planted = "(function(){\n  var csrf = (document.querySelector('meta[name=\"csrf-token\"]') || {}).content || '';\n"
                 . "  function go(){ fetch('/x', { headers: { 'X-CSRF-Token': csrf } }); }\n})();";
        $this->assertSame(['planted.js:3 sends `csrf`, read once at load'], $this->stale($planted, 'planted.js'));
    }

    /** @return list<string> */
    private function stale(string $js, string $name): array
    {
        $lines = explode("\n", $js);
        $vars = [];
        foreach ($lines as $l) {
            if (preg_match('~^ {0,2}var\s+(?:\w+\s*=[^,;]*,\s*)*(\w+)\s*=\s*[^;]*(?:csrf-token|ag-csrf|data-csrf)[^;]*(?:\.content|getAttribute)~', $l, $m)) {
                if (!preg_match("~querySelector\(\s*'meta\[name=\"(?:csrf-token|ag-csrf)\"\]'\s*\)\s*;~", $l)) $vars[] = $m[1];
            }
        }
        $out = [];
        foreach ($lines as $i => $l) {
            if (!preg_match("~X-CSRF-Token|'_token'|_token\s*:~", $l)) continue;
            if (preg_match('~getAttribute\(|csrf\(\)|querySelector\(~', $l)) continue;
            foreach (array_unique($vars) as $v) {
                if (preg_match('~(?<![\w.])' . preg_quote($v, '~') . '\b(?!\s*\()~', $l)) $out[] = "{$name}:" . ($i + 1) . " sends `{$v}`, read once at load";
            }
        }

        return $out;
    }
}
