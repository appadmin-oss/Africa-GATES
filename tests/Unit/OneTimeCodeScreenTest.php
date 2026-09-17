<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\UserAccountService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The one-time code screen — /account/login?sent=1 — and the rules it states.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A SCREEN ON WHICH NOTHING COULD SUCCEED, UNDER A COMMENT SAYING IT WAS FIXED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The code screen reads the address out of the session and posts it beside the code,
 * which was itself a repair: it used to read the address out of the session at VERIFY
 * time, so a typo put somebody on a code screen for an inbox they do not own with
 * "Invalid or expired code" as the only feedback.
 *
 * That repair carried the address FORWARD and so did nothing for the case its own
 * comment named — a session that has already rolled over has nothing to carry.
 * Measured before this file existed: with an empty session the page rendered the full
 * screen (lede, six boxes, Sign in, "Send a new one") and every control on it posted an
 * empty address. Verifying answered "Invalid or expired code. Request a new one.";
 * resending answered "Please enter a valid email." Nothing on the page could work, and
 * the page's own docblock was the evidence somebody had already looked.
 *
 * Not contrived: `?sent=1` sits in browser history, and the commonest shape of this
 * flow is a code requested on a laptop and read on a phone. The code is live for
 * fifteen minutes either way, so the screen asks for the one thing it is missing
 * rather than discarding a completed request.
 *
 * ── AND TWO RULES THE SCREEN ENFORCED WITHOUT STATING ────────────────────────
 *
 * The fifteen-minute window was typed into four places — the mint, both halves of the
 * email, and this page — with nothing making them agree. It is read from
 * {@see UserAccountService::OTP_TTL_MINUTES} now, and this file asserts against the
 * constant so a number typed back into the template fails the moment the two diverge.
 *
 * The five-guess cap was stated nowhere at all until it fired, at which point the code
 * is already dead and the person has to go back to their inbox: the rule arrived only
 * as its own punishment. It counts down out loud now. The number is no use to an
 * attacker — they can count their own guesses — and it is the only thing that tells
 * somebody mistyping a code off a phone screen that they are near the end of it.
 */
final class OneTimeCodeScreenTest extends TestCase
{
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($b->build());
        $this->app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($this->app);
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(false, false, false);

        unset($_SESSION['user_login_email'], $_SESSION['user_id'],
              $_SESSION['flash_error'], $_SESSION['flash_notice']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_login_email'], $_SESSION['user_id'],
              $_SESSION['flash_error'], $_SESSION['flash_notice']);
        parent::tearDown();
    }

    private function get(string $path): string
    {
        return (string) $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path))->getBody();
    }

    private function post(string $path, array $body, string $ip = '198.51.100.9'): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', $path, ['REMOTE_ADDR' => $ip])
                ->withParsedBody($body));
    }

    private function member(string $email): int
    {
        return (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ada', 'email' => $email, 'phone' => '',
            'password_hash' => password_hash('correct horse', PASSWORD_DEFAULT),
            'status' => 'active', 'email_verified' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** A live sign-in code for $email, returning the raw six digits. */
    private function liveCode(string $email, int $userId): string
    {
        $code = '424242';
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', $email), 'token_hash' => hash('sha256', $code),
            'purpose' => 'user_login', 'nominee_id' => $userId, 'award_id' => 0,
            'attempts' => 0, 'is_used' => 0,
            'expires_at' => Carbon::now()->addMinutes(UserAccountService::OTP_TTL_MINUTES)->toDateTimeString(),
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        return $code;
    }

    // ═════════════════════ the screen with no address ════════════════════════

    /**
     * THE FAULT. Every control the screen offers has to be able to succeed. Before
     * the fix this asked for an address it never showed and posted an empty one.
     */
    public function test_with_no_remembered_address_the_screen_asks_for_one(): void
    {
        $html = $this->get('/account/login?sent=1');

        $this->assertMatchesRegularExpression(
            '~<input[^>]*id="otp-email"[^>]*name="email"~', $html,
            'the code screen must ask for the address when the session no longer has one');
        $this->assertDoesNotMatchRegularExpression(
            '~<input type="hidden" name="email" value="">~', $html,
            'a hidden field carrying nothing posts nothing, and the only feedback is a message about the code');
        $this->assertStringNotContainsString('we just sent you', $html,
            'this browser has no record of a send; saying so is what made the dead screen look complete');
    }

    /** And it does not offer a resend it cannot perform. */
    public function test_with_no_remembered_address_no_control_posts_an_empty_one(): void
    {
        $html = $this->get('/account/login?sent=1');

        // Every form on the card, and what it would post as `email`.
        preg_match_all('~<form[^>]*action="(/account/login[^"]*)"(.*?)</form>~s', $html, $forms, PREG_SET_ORDER);
        $this->assertNotEmpty($forms, 'the code screen must still have a form on it');

        foreach ($forms as [, $action, $body]) {
            $hidden = preg_match('~<input type="hidden" name="email" value="([^"]*)"~', $body, $m) ? $m[1] : null;
            $asked  = (bool) preg_match('~<input[^>]*type="email"[^>]*name="email"~', $body);
            $this->assertTrue($asked || ($hidden !== null && $hidden !== ''),
                "the form posting to {$action} would send no usable address, and cannot succeed "
                . '(a missing field falls back to the same empty session as an empty one)');
        }
    }

    /** With an address in hand the screen keeps its cheaper shape: no second field. */
    public function test_with_a_remembered_address_the_field_stays_hidden(): void
    {
        $_SESSION['user_login_email'] = 'ada@example.com';
        $html = $this->get('/account/login?sent=1');

        $this->assertStringContainsString('<input type="hidden" name="email" value="ada@example.com">', $html);
        $this->assertStringNotContainsString('id="otp-email"', $html,
            'asking again for an address the page is already printing is a field nobody needs');
        $this->assertStringContainsString('ada@example.com', $html);
    }

    /** A code posted with its address verifies, whatever the session remembers. */
    public function test_a_code_verifies_when_the_address_travels_with_it(): void
    {
        $uid  = $this->member('ada@example.com');
        $code = $this->liveCode('ada@example.com', $uid);

        unset($_SESSION['user_login_email']);           // the session has rolled over
        $r = $this->post('/account/login/verify', ['email' => 'ada@example.com', 'otp' => $code]);

        $this->assertSame($uid, (int) ($_SESSION['user_id'] ?? 0),
            'the code was live and the address was supplied — there is nothing left to refuse');
        $this->assertSame(302, $r->getStatusCode());
    }

    // ═══════════════════════ the rules it now states ═════════════════════════

    /** The window is read from the rule, not typed into the page. */
    public function test_the_window_on_the_page_is_the_window_in_the_code(): void
    {
        $_SESSION['user_login_email'] = 'ada@example.com';
        $html = $this->get('/account/login?sent=1');

        $this->assertStringContainsString(
            'It expires ' . UserAccountService::OTP_TTL_MINUTES . ' minutes after it is sent.', $html);
    }

    /** And the mint uses the same one, so the promise and the token agree. */
    public function test_the_minted_code_lives_exactly_that_long(): void
    {
        $this->member('ada@example.com');
        $this->post('/account/login/otp', ['email' => 'ada@example.com']);

        $tok = DB::table('gates_otp_tokens')->where('purpose', 'user_login')->orderByDesc('id')->first();
        $this->assertNotNull($tok, 'a member asking for a code must get one');
        $this->assertEqualsWithDelta(
            UserAccountService::OTP_TTL_MINUTES * 60,
            Carbon::parse($tok->expires_at)->getTimestamp() - Carbon::now()->getTimestamp(),
            90.0,
            'the page states this number; the token has to honour it');
    }

    /** A wrong guess says how many are left, rather than killing the code unannounced. */
    public function test_a_wrong_guess_counts_down_out_loud(): void
    {
        $uid = $this->member('ada@example.com');
        $this->liveCode('ada@example.com', $uid);

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $this->post('/account/login/verify',
                ['email' => 'ada@example.com', 'otp' => '00000' . $i], '203.0.113.' . (40 + $i));
            $seen[] = (string) ($_SESSION['flash_error'] ?? '');
            unset($_SESSION['flash_error']);
        }

        $this->assertStringContainsString('4 tries left', $seen[0]);
        $this->assertStringContainsString('One try left', $seen[3],
            'the last chance has to read as one, not as "1 tries"');
        $this->assertStringContainsString('last try on that code', $seen[4],
            'and the budget running out is said plainly, not left as another wrong-code message');

        // And the code is spent at that point rather than alive-but-unusable.
        $this->assertSame(1, (int) DB::table('gates_otp_tokens')
            ->where('email_hash', hash('sha256', 'ada@example.com'))->orderByDesc('id')->value('is_used'));
    }

    /**
     * The cap still holds, and the code is spent once it is reached — the counting
     * above must not have turned the cap into advice.
     */
    public function test_the_cap_still_burns_the_code(): void
    {
        $uid = $this->member('ada@example.com');
        $code = $this->liveCode('ada@example.com', $uid);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/account/login/verify',
                ['email' => 'ada@example.com', 'otp' => '10000' . $i], '203.0.113.' . (60 + $i));
            unset($_SESSION['flash_error']);
        }

        // The right code, too late.
        $this->post('/account/login/verify', ['email' => 'ada@example.com', 'otp' => $code], '203.0.113.99');
        $this->assertSame(0, (int) ($_SESSION['user_id'] ?? 0),
            'a code ground against five times is spent, and the sixth arrival cannot redeem it');
    }

    /**
     * The mailer-failure sentence does not describe the account.
     *
     * That branch is reachable only for an address that HAS an account, and it used to
     * add ", or sign in with your password" only when one was set — so an attacker
     * probing during a mail outage learned which accounts have no password, i.e. which
     * are pointless to grind and which are worth phishing a code for. Existence is
     * already discoverable through registration; the account's SHAPE is not.
     */
    public function test_the_send_failure_sentence_is_the_same_whatever_the_account_holds(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Controllers/AccountController.php');

        $this->assertStringNotContainsString("password_hash) ? ', or sign in with your password'", $src,
            'the sign-in-code failure must not branch on whether the account has a password');
        $this->assertStringContainsString('or sign in with your password if you have one', $src);
    }
}
