<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * THE THREE PUBLIC ACCOUNT DOORS — sign in, register, confirm your email.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE ASSERTIONS AND NOT A SCREENSHOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A sign-in screen fails in ways that are invisible from the screen. Every one of the
 * checks below is a fault this repository has paid for somewhere already:
 *
 *   · A ROW POINTING AT NOTHING. The design these screens were built from offers "Use a
 *     passkey" and "Forgotten your password". This platform has neither a credential store
 *     nor a member password-reset route, and a link to a route that does not exist is this
 *     codebase's most expensive recurring shape — a mechanism complete on every side with
 *     no way in, or the mirror of it, a way in to nothing. So every href and every form
 *     action on these screens is resolved against the table Slim actually serves.
 *
 *   · AN ADDRESS THE CODE SCREEN COULD NOT SHOW. The code form used to carry neither the
 *     address the code went to nor any way to correct it, and the verifier read the address
 *     out of the SESSION. A typo therefore produced a code screen for an inbox the visitor
 *     does not own, and the only feedback available was "invalid or expired code" — a
 *     message about the code, for a fault in the address.
 *
 *   · A FIELD NOBODY CAN SEE. The six code boxes are decorative; one real input takes the
 *     entry, because `autocomplete="one-time-code"` autofills into one input and six would
 *     kill it. If that input is hidden by the STYLESHEET rather than by the script that
 *     paints the boxes, a browser with the script blocked shows six empty boxes over an
 *     invisible field: typing puts nothing anywhere on screen and nothing reports an error.
 *
 *   · A SHARED FRONT DOOR. Admin, judge and organisation sign-in are separate trust
 *     domains on their own routes. A link from the public member screen is what turns three
 *     unlisted doors into one signposted one.
 *
 *   · AN ERROR ANSWERING A QUESTION NOBODY ASKED. The password field is deliberately not
 *     `required` — the one-time code beside it is a route, not a fallback — so an empty
 *     password arrives as an ordinary submission. "That email and password do not match" is
 *     false there: there was no password to match.
 */
final class AccountAuthScreensTest extends TestCase
{
    /** The templates that make up the member auth surface. */
    private const SCREENS = [
        'templates/layout/account-auth.twig',
        'templates/pages/account/login.twig',
        'templates/pages/account/register.twig',
        'templates/pages/account/verify-notice.twig',
        'templates/pages/account/forgot.twig',
        'templates/pages/account/reset.twig',
    ];

    private static function root(): string { return dirname(__DIR__, 2); }

    private static function src(string $rel): string
    {
        $p = self::root() . '/' . $rel;
        self::assertFileExists($p);
        return (string) file_get_contents($p);
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(self::root() . '/config/container.php');
        return $b->build();
    }

    /**
     * Verb → the paths Slim serves. Asked of the router rather than parsed out of
     * `src/routes.php`: the groups nest, the API tree is a closure mounted twice, and a
     * parser that gets the prefix arithmetic wrong reports working pages as unreachable.
     *
     * @return array<string,list<string>>
     */
    private static function routeTable(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;

        $b = new ContainerBuilder();
        $b->addDefinitions(require self::root() . '/config/container.php');
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        (require self::root() . '/src/routes.php')($app);

        $out = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            foreach ($r->getMethods() as $verb) $out[strtoupper((string) $verb)][] = $r->getPattern();
        }
        return $memo = $out;
    }

    /**
     * Does `$path` reach a route registered for `$verb`?
     *
     * TWO SHAPES HAVE TO BE HONOURED OR THE ANSWER IS A LIE IN BOTH DIRECTIONS.
     *
     * A CONSTRAINED PLACEHOLDER IS NOT `[^/]+`. `{id:[0-9]+}` matches digits only, and
     * treating it loosely makes `/admin/shop/codes` look served by `/admin/shop/{id}`.
     *
     * AN OPTIONAL SEGMENT IS A REAL ROUTE, NOT AN EDGE CASE — and declining to read one is
     * how this sweep first reported the HOMEPAGE as a dead link. Slim registers `/` as
     * `'[/]'`, so a matcher that skips any pattern containing a bracket outside a
     * placeholder skips the most-visited route on the site and calls the brand lockup
     * broken. Each `[…]` is expanded into its present and absent forms instead.
     */
    private static function serves(string $verb, string $path): bool
    {
        foreach (self::routeTable()[$verb] ?? [] as $pattern) {
            foreach (self::expand($pattern) as $variant) {
                if ($variant === $path) return true;
                if (!str_contains($variant, '{')) continue;
                $rx = '';
                foreach (preg_split('~(\{[^}]*\})~', $variant, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $bit) {
                    if (!str_starts_with($bit, '{')) { $rx .= preg_quote($bit, '~'); continue; }
                    $body  = substr($bit, 1, -1);
                    $colon = strpos($body, ':');
                    // Grouped, or an alternation in the constraint swallows the tail.
                    $rx   .= $colon === false ? '[^/]+' : '(?:' . substr($body, $colon + 1) . ')';
                }
                if (preg_match('~^' . $rx . '$~', $path) === 1) return true;
            }
        }
        return false;
    }

    /**
     * A pattern with optional segments → every concrete form of it.
     *
     * The brackets that matter are the ones OUTSIDE a placeholder: `{id:[0-9]+}` carries
     * one that is part of a character class, and treating it as optional would produce
     * nonsense. Placeholders are masked out before the scan and restored after.
     *
     * @return list<string>
     */
    private static function expand(string $pattern): array
    {
        if (!str_contains((string) preg_replace('~\{[^}]*\}~', '', $pattern), '[')) return [$pattern];

        $holes = [];
        $masked = (string) preg_replace_callback('~\{[^}]*\}~', static function (array $m) use (&$holes): string {
            $holes[] = $m[0];
            return "\0" . (count($holes) - 1) . "\0";
        }, $pattern);

        $forms = [$masked];
        // Innermost first, so a nested `[/a[/b]]` resolves without leftovers.
        while (true) {
            $next = [];
            $grew = false;
            foreach ($forms as $f) {
                if (preg_match('~\[([^\[\]]*)\]~', $f, $m, PREG_OFFSET_CAPTURE) !== 1) { $next[] = $f; continue; }
                $grew  = true;
                $whole = $m[0][0]; $at = (int) $m[0][1];
                $next[] = substr($f, 0, $at) . $m[1][0] . substr($f, $at + strlen($whole));
                $next[] = substr($f, 0, $at) . substr($f, $at + strlen($whole));
            }
            $forms = array_values(array_unique($next));
            if (!$grew) break;
        }

        return array_map(static function (string $f) use ($holes): string {
            return (string) preg_replace_callback('~\x00(\d+)\x00~',
                static fn (array $m): string => $holes[(int) $m[1]], $f);
        }, $forms);
    }

    /** Strip Twig comments — a `{# … #}` reaches no reader, so it is not a link. */
    private static function stripComments(string $s): string
    {
        return (string) preg_replace('~\{#.*?#\}~s', '', $s);
    }

    // ───────────────────────────── the table itself ─────────────────────────────

    public function test_it_reads_the_table_that_actually_serves(): void
    {
        $get = self::routeTable()['GET'] ?? [];
        $this->assertGreaterThan(300, count($get), 'the route collector came back nearly empty');
        $this->assertTrue(self::serves('GET',  '/account/login'));
        // Slim registers the homepage as `[/]`. A matcher that declines an optional
        // segment reports the most-visited route on the site as a dead link.
        $this->assertTrue(self::serves('GET',  '/'), 'the homepage');
        $this->assertTrue(self::serves('GET',  '/admin/shop/codes'), 'a literal beside {id:[0-9]+}');
        $this->assertTrue(self::serves('POST', '/account/login/otp'));
        // And it must say NO to something plausible that does not exist, or every
        // "the link resolves" assertion below is worthless.
        $this->assertFalse(self::serves('GET', '/account/passkey'));
        $this->assertFalse(self::serves('GET', '/account/password/reset'));
    }

    // ───────────────────────────── links go somewhere ───────────────────────────

    public function test_every_link_and_form_on_the_member_auth_screens_reaches_a_real_route(): void
    {
        $dead = [];
        foreach (self::SCREENS as $rel) {
            $src = self::stripComments(self::src($rel));

            preg_match_all('~\shref="(/[^"#{]*)"~', $src, $m);
            foreach ($m[1] as $href) {
                $path = strtok($href, '?') ?: '/';
                if (!self::serves('GET', $path)) $dead[] = "$rel → GET $href";
            }

            preg_match_all('~\s(?:form)?action="(/[^"#{]*)"~', $src, $m);
            foreach ($m[1] as $action) {
                $path = strtok($action, '?') ?: '/';
                if (!self::serves('POST', $path)) $dead[] = "$rel → POST $action";
            }
        }

        $this->assertSame([], $dead,
            "a member auth screen offers a door with nothing behind it:\n  " . implode("\n  ", $dead));
    }

    public function test_the_member_screens_do_not_link_to_the_admin_judge_or_organisation_doors(): void
    {
        foreach (self::SCREENS as $rel) {
            $src = self::stripComments(self::src($rel));
            foreach (['/admin/login', '/admin/magic', '/judge/login', '/org/login'] as $door) {
                $this->assertStringNotContainsString('"' . $door, $src,
                    "$rel links to $door — three separate trust domains stay unlisted from the public screen");
            }
        }
    }

    // ───────────────────────────── the code screen ──────────────────────────────

    public function test_the_code_screen_posts_the_address_the_code_was_sent_to(): void
    {
        $html = $this->render('pages/account/login.twig', ['sent' => 1, 'login_email' => 'ada@example.test']);

        $this->assertStringContainsString('ada@example.test', $html,
            'the screen never says which inbox to open');

        // The verify form itself must carry it. Without this the verifier falls back to
        // the session, and a session that has rolled over reports a bad code.
        $form = self::formFor($html, '/account/login/verify');
        $this->assertNotNull($form, 'no form posting to /account/login/verify');
        $this->assertMatchesRegularExpression(
            '~<input[^>]+name="email"[^>]+value="ada@example\.test"~', $form,
            'the code is posted without the address it belongs to');
    }

    public function test_a_new_code_is_asked_for_by_post_and_never_by_a_link(): void
    {
        $html = $this->render('pages/account/login.twig', ['sent' => 1, 'login_email' => 'ada@example.test']);

        $this->assertStringNotContainsString('href="/account/login/otp"', $html,
            'a GET link mints a code, so a mail scanner following links burns the one just sent');

        $form = self::formFor($html, '/account/login/otp');
        $this->assertNotNull($form, 'no way to ask for a new code');
        $this->assertMatchesRegularExpression('~<button[^>]*type="submit"~', $form);
        $this->assertMatchesRegularExpression(
            '~<input[^>]+name="email"[^>]+value="ada@example\.test"~', $form,
            'a resend that carries no address asks for a code for nobody');
    }

    public function test_the_real_code_field_is_visible_until_the_script_paints_the_boxes(): void
    {
        $css = self::src('public/assets/css/components/account-auth.css');

        // The boxes appear only once the painter has run…
        $this->assertMatchesRegularExpression('~\.ag-acct__otp\s*\{[^}]*display:\s*none~', $css,
            'the decorative boxes are shown before anything can paint them');
        $this->assertStringContainsString('.is-boxed .ag-acct__otp{', str_replace(' {', '{', $css));

        // …and the real input is hidden only under that same class, never unconditionally.
        $this->assertMatchesRegularExpression('~\.is-boxed\s+\.ag-acct__code\s*\{~', $css,
            'the real input must be hidden by the painter, not by the stylesheet');
        $this->assertDoesNotMatchRegularExpression(
            '~(?<!is-boxed\s)\n\.ag-acct__code\s*\{[^}]*(display:\s*none|position:\s*absolute)~', $css,
            'the input is hidden with no script to reveal what was typed');

        // The one input `one-time-code` can autofill into, still a real focusable field.
        $html = $this->render('pages/account/login.twig', ['sent' => 1, 'login_email' => 'a@b.test']);
        $this->assertSame(1, preg_match_all('~autocomplete="one-time-code"~', $html),
            'six separate inputs kill the code suggestion this attribute exists for');
    }

    public function test_the_code_field_and_the_boxes_cannot_disagree(): void
    {
        // `pattern` and `maxlength` police the SUBMISSION, not the typing. Without a
        // write-back, "4821ab" leaves the boxes showing four digits while the field holds
        // six characters: the sixth keystroke is refused by maxlength, the person sees an
        // unfinished code, and the form then declines to submit for a reason nothing on
        // screen explains. Verified in a browser — typing "4821" then "ab9" used to give
        // boxes "4821" beside a value of "4821ab".
        $src = self::stripComments(self::src('templates/pages/account/login.twig'));

        $this->assertStringContainsString(
            "var v = input.value.replace(/\\D/g, '').slice(0, 6);", $src,
            'the painter no longer derives the digits it draws');
        $this->assertMatchesRegularExpression(
            '~if \(input\.value !== v\)\s*\{[^}]*input\.value = v;~s', $src,
            'the painter draws the digits but never writes them back, so the field can '
            . 'hold characters the boxes do not show');
    }

    public function test_a_row_marked_hidden_is_actually_hidden(): void
    {
        // `[hidden]{display:none}` comes from the USER-AGENT stylesheet, so ANY author
        // rule that sets `display` beats it — and `.ag-acct__row{display:flex}` does.
        // Caught in a browser, not in review: the passkey row, which exists only to be
        // revealed once the script confirms this browser can run the ceremony, rendered
        // in full on every browser including the ones that cannot. Which is precisely the
        // "a door onto nothing" this arrangement was built to avoid, produced by the
        // mechanism meant to prevent it.
        $css = self::src('public/assets/css/components/account-auth.css');
        $this->assertMatchesRegularExpression(
            '~\.ag-acct\s+\[hidden\]\s*\{[^}]*display:\s*none\s*!important~', $css,
            'a `hidden` element inside .ag-acct is overridden by the component\u{2019}s own display rules');

        // And the thing it protects: the row must SHIP hidden, or there is nothing to reveal.
        $html = $this->render('pages/account/login.twig', ['sent' => false, 'passkeys_available' => true]);
        $this->assertMatchesRegularExpression('~id="agPasskeyRow"[^>]*\shidden~', $html,
            'the passkey row is offered before anything has checked this browser can use it');
    }

    public function test_the_passkey_row_is_absent_when_the_server_cannot_verify_one(): void
    {
        // Not merely hidden — ABSENT. The browser half being capable is irrelevant when
        // the library is missing from the deployment, and a revealed row would then open
        // a 503 for somebody whose device was perfectly willing.
        $html = $this->render('pages/account/login.twig', ['sent' => false, 'passkeys_available' => false]);
        $this->assertStringNotContainsString('agPasskeyRow', $html);
        $this->assertStringNotContainsString('passkeys.js', $html);
    }

    public function test_recovery_is_offered_and_the_route_behind_it_exists(): void
    {
        $html = $this->render('pages/account/login.twig', ['sent' => false]);
        $this->assertStringContainsString('/account/forgot', $html,
            'a password field with no way to recover one');
        $this->assertTrue(self::serves('GET', '/account/forgot'));
        $this->assertTrue(self::serves('POST', '/account/forgot'));
        $this->assertTrue(self::serves('GET', '/account/reset'));
        $this->assertTrue(self::serves('POST', '/account/reset'));
    }

    // ───────────────────────────── the chooser ──────────────────────────────────

    public function test_the_chooser_offers_every_kind_of_account_this_platform_has(): void
    {
        $html = $this->render('pages/account/register.twig', []);

        $this->assertStringContainsString('/account/register?as=individual', $html, 'take part');
        $this->assertStringContainsString('/giving/apply', $html, 'register a non-profit');
        $this->assertStringContainsString('/partner', $html, 'partner or exhibit');
        // The chooser is not the form.
        $this->assertStringNotContainsString('name="phone"', $html);
    }

    public function test_the_individual_form_is_one_click_in_and_keeps_what_was_typed(): void
    {
        $html = $this->render('pages/account/register.twig', [
            'as' => 'individual', 'error' => 'An account already uses that email.',
            'old' => ['name' => 'Ada Obi', 'email' => 'ada@example.test', 'phone' => '+2348000000000'],
        ]);

        foreach (['name', 'email', 'phone', 'password'] as $field) {
            $this->assertMatchesRegularExpression('~<input[^>]+name="' . $field . '"~', $html, $field);
        }
        $this->assertStringContainsString('value="Ada Obi"', $html);
        $this->assertStringContainsString('value="ada@example.test"', $html);
        $this->assertStringContainsString('An account already uses that email.', $html);
        // Phone required, password not — current behaviour, not an oversight either way.
        $this->assertMatchesRegularExpression('~<input[^>]+name="phone"[^>]+required~', $html);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]+name="password"[^>]+required~', $html);
    }

    // ───────────────────────────── the controller ───────────────────────────────

    public function test_signing_in_with_no_password_names_the_code_path(): void
    {
        $_SESSION = ['csrf_token' => 'tok'];
        $res = $this->ctrl()->loginSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/login')
                ->withParsedBody(['email' => 'nobody@example.test', 'password' => '']),
            new Response());

        $this->assertSame(302, $res->getStatusCode());
        $msg = (string) ($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('one-time code', $msg,
            'an empty password is told about a mismatch instead of about the button that works');
        $this->assertStringNotContainsString('do not match', $msg);
    }

    public function test_a_wrong_password_and_an_unknown_address_read_the_same(): void
    {
        $_SESSION = ['csrf_token' => 'tok'];
        $this->ctrl()->loginSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/login')
                ->withParsedBody(['email' => 'no-such-person@example.test', 'password' => 'whatever']),
            new Response());
        $unknown = (string) ($_SESSION['flash_error'] ?? '');

        $email = 'ada-' . bin2hex(random_bytes(4)) . '@example.test';
        \Illuminate\Database\Capsule\Manager::table('gates_users')->insert([
            'name' => 'Ada Obi', 'email' => $email, 'phone' => '+2348000000000',
            'password_hash' => password_hash('the-right-one', PASSWORD_DEFAULT),
            'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $_SESSION = ['csrf_token' => 'tok'];
        $this->ctrl()->loginSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/login')
                ->withParsedBody(['email' => $email, 'password' => 'the-wrong-one']),
            new Response());
        $wrongPw = (string) ($_SESSION['flash_error'] ?? '');

        $this->assertNotSame('', $unknown);
        $this->assertSame($unknown, $wrongPw,
            'telling an unknown address apart from a wrong password is an enumeration oracle');
    }

    public function test_showing_the_address_on_the_code_screen_does_not_consume_it(): void
    {
        // The screen prints `$_SESSION['user_login_email']`, and the VERIFIER falls back to
        // the same key. Reading it with the flash helper would make the act of showing the
        // address the thing that stops it being usable — one render, and the next post
        // verifies against an empty address and reports a bad code.
        $_SESSION = ['csrf_token' => 'tok', 'user_login_email' => 'ada@example.test'];
        $res = $this->ctrl()->loginForm(
            (new ServerRequestFactory())->createServerRequest('GET', '/account/login?sent=1')
                ->withQueryParams(['sent' => '1']),
            new Response());

        $res->getBody()->rewind();
        $this->assertStringContainsString('ada@example.test', (string) $res->getBody()->getContents());
        $this->assertSame('ada@example.test', $_SESSION['user_login_email'] ?? null,
            'rendering the code screen threw away the address it had just shown');
    }

    public function test_a_rejected_registration_returns_to_the_form_and_not_to_the_chooser(): void
    {
        $_SESSION = ['csrf_token' => 'tok'];
        $res = $this->ctrl()->registerSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/register')
                ->withParsedBody(['name' => '', 'email' => 'not-an-email', 'phone' => '']),
            new Response());

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/account/register?as=individual', $res->getHeaderLine('Location'),
            'step one would show four options and no sign of what was wrong with what they typed');
    }

    // ───────────────────────────── helpers ──────────────────────────────────────

    private function ctrl(): \AfricaGates\Controllers\AccountController
    {
        return $this->container()->get(\AfricaGates\Controllers\AccountController::class);
    }

    /**
     * `csrf_token` is a Twig GLOBAL (config/container.php), not something a controller
     * passes — so this renders through the container's own environment rather than a
     * hand-built one, which breaks the moment a screen gains a form.
     */
    private function render(string $tpl, array $ctx): string
    {
        $_SESSION = ['csrf_token' => 'tok'] + ($_SESSION ?? []);
        return $this->container()->get(Twig::class)->fetch($tpl, $ctx + ['hide_chrome' => true]);
    }

    /** The one `<form>` posting to `$action`, or null. */
    private static function formFor(string $html, string $action): ?string
    {
        if (!preg_match_all('~<form\b[^>]*>.*?</form>~s', $html, $m)) return null;
        foreach ($m[0] as $form) {
            if (str_contains($form, 'action="' . $action . '"')) return $form;
        }
        return null;
    }
}
