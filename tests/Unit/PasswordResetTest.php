<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\UserAccountService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * THE RESET LINK — the route people go looking for.
 *
 * The one-time code was always the way back into a member account, and this does not
 * replace it. What it adds is the door somebody actually hunts for: a person who has
 * forgotten a password looks for "forgot password", and being offered a sign-in code
 * instead reads as the site not having the thing they asked for.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FOUR THINGS THAT HAVE TO HOLD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * · ONCE. A reset link is a bearer credential for a whole account, and one that stays
 *   spendable is one still sitting in an inbox that may not be the owner's any more.
 *
 * · AN HOUR. Not the verification link's twenty-four: proving an address and taking over
 *   an account are not the same risk and must not share a window.
 *
 * · THE SAME ANSWER EITHER WAY. An address with no account and an address with one get
 *   the same sentence. This platform's members are named public figures, so "no account
 *   uses that email" is a free membership check for anybody who wants one — and a mailer
 *   failure must not become a third, distinguishable outcome.
 *
 * · THE FORM IS NOT DRAWN AGAINST A DEAD TOKEN. People find reset links in old email, on
 *   the wrong device, an hour late. Drawing a password field there means somebody types a
 *   new password, presses the button, and is told the link expired — the password gone
 *   and no screen having warned them.
 */
final class PasswordResetTest extends TestCase
{
    private const PURPOSE = UserAccountService::RESET_PURPOSE;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['csrf_token' => 'tok'];
    }

    private function accounts(): UserAccountService
    {
        return $this->container()->get(UserAccountService::class);
    }

    private function ctrl(): \AfricaGates\Controllers\AccountController
    {
        return $this->container()->get(\AfricaGates\Controllers\AccountController::class);
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    private function member(?string $password = 'the-old-one'): object
    {
        $email = 'rs-' . bin2hex(random_bytes(4)) . '@example.test';
        $id = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ada Obi', 'email' => $email, 'phone' => '+2348000000000',
            'password_hash' => $password === null ? null : password_hash($password, PASSWORD_BCRYPT),
            'status' => 'active', 'email_verified' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return DB::table('gates_users')->where('id', $id)->first();
    }

    // ───────────────────────────── the token ──────────────────────────────────

    public function test_a_link_sets_the_password_and_then_cannot_be_used_again(): void
    {
        $a = $this->accounts();
        $u = $this->member();

        $raw = $a->issuePasswordReset((int) $u->id, (string) $u->email);
        $this->assertNotNull($raw);

        $this->assertNotNull($a->consumePasswordReset((string) $raw, 'a-brand-new-one'));
        $this->assertNotNull($a->attemptLogin((string) $u->email, 'a-brand-new-one'));
        $this->assertNull($a->attemptLogin((string) $u->email, 'the-old-one'),
            'the old password still works');

        // The same link again is nothing.
        $this->assertNull($a->consumePasswordReset((string) $raw, 'a-third-one'));
        $this->assertNotNull($a->attemptLogin((string) $u->email, 'a-brand-new-one'),
            'a spent link changed the password a second time');
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $a = $this->accounts();
        $u = $this->member();
        $raw = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);

        $row = DB::table('gates_otp_tokens')->where('purpose', self::PURPOSE)
            ->where('nominee_id', (int) $u->id)->orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertNotSame($raw, (string) $row->token_hash, 'the raw token was persisted');
        $this->assertSame(hash('sha256', $raw), (string) $row->token_hash);
    }

    public function test_issuing_a_second_link_kills_the_first(): void
    {
        $a = $this->accounts();
        $u = $this->member();

        $first  = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);
        $second = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);

        $this->assertNull($a->consumePasswordReset($first, 'from-the-old-link'),
            'an earlier link stayed live after a fresh one was asked for');
        $this->assertNotNull($a->consumePasswordReset($second, 'from-the-new-link'));
    }

    public function test_the_window_is_an_hour_and_an_expired_link_is_nothing(): void
    {
        $a = $this->accounts();
        $u = $this->member();
        $raw = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);

        $row = DB::table('gates_otp_tokens')->where('token_hash', hash('sha256', $raw))->first();
        $mins = (int) round((strtotime((string) $row->expires_at) - time()) / 60);
        // An hour, not the verification link's twenty-four. Proving an address and taking
        // over an account are not the same risk.
        $this->assertGreaterThan(50, $mins);
        $this->assertLessThan(70, $mins, 'the reset window is far wider than an hour');

        DB::table('gates_otp_tokens')->where('id', $row->id)
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertNull($a->consumePasswordReset($raw, 'too-late-for-this'));
    }

    public function test_an_empty_token_matches_nothing(): void
    {
        // sha256('') IS A PERFECTLY VALID HASH — `e3b0c442…` — so without the guard an
        // empty token goes to the database and matches any row stored under it. Which
        // is why this plants one: the first version of this test just asserted that an
        // empty token returns null, and it passed with the guard REMOVED, because no
        // fixture had ever written that hash. An assertion nothing can break is not one.
        $u = $this->member();
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', (string) $u->email),
            'token_hash' => hash('sha256', ''),
            'purpose'    => self::PURPOSE,
            'nominee_id' => (int) $u->id,
            'award_id'   => 0, 'attempts' => 0, 'is_used' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertNull($this->accounts()->findByResetToken(''),
            'an empty token resolved to an account');
        $this->assertNull($this->accounts()->consumePasswordReset('', 'whatever-it-is'),
            'an empty token set somebody\u{2019}s password');
        // Trimmed to empty is the same thing arriving through a query string.
        $this->assertNull($this->accounts()->findByResetToken('   '));

        $this->assertNull($this->accounts()->attemptLogin((string) $u->email, 'whatever-it-is'));
    }

    public function test_a_short_password_is_refused_and_the_link_survives(): void
    {
        $a = $this->accounts();
        $u = $this->member();
        $raw = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);

        $this->assertNull($a->consumePasswordReset($raw, 'short'));
        // Burning it would strand somebody for a typo they can see on screen.
        $this->assertNotNull($a->consumePasswordReset($raw, 'long-enough-now'),
            'a refused password spent the link');
    }

    public function test_setting_a_password_this_way_also_verifies_the_email(): void
    {
        // Following a link sent to that inbox proves what a one-time code proves, and the
        // code path already verifies. Leaving it unverified strands somebody who has just
        // proved they own the address on a "confirm your email" screen.
        $a = $this->accounts();
        $u = $this->member();
        $this->assertSame(0, (int) $u->email_verified);

        $raw = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);
        $after = $a->consumePasswordReset($raw, 'a-fresh-password');

        $this->assertNotNull($after);
        $this->assertSame(1, (int) $after->email_verified);
        $this->assertSame(1, (int) DB::table('gates_users')->where('id', $u->id)->value('email_verified'));
    }

    public function test_a_member_with_no_password_can_set_one(): void
    {
        $a = $this->accounts();
        $u = $this->member(null);
        $raw = (string) $a->issuePasswordReset((int) $u->id, (string) $u->email);

        $this->assertNotNull($a->consumePasswordReset($raw, 'my-very-first-one'));
        $this->assertNotNull($a->attemptLogin((string) $u->email, 'my-very-first-one'));
    }

    // ─────────────────────────── the screens ──────────────────────────────────

    public function test_an_address_with_an_account_and_one_without_read_identically(): void
    {
        $u = $this->member();

        $this->ctrl()->forgotSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/forgot')
                ->withParsedBody(['email' => (string) $u->email]), new Response());
        $known = (string) ($_SESSION['flash_notice'] ?? '');

        $_SESSION = ['csrf_token' => 'tok'];
        $this->ctrl()->forgotSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/forgot')
                ->withParsedBody(['email' => 'nobody-' . bin2hex(random_bytes(3)) . '@example.test']),
            new Response());
        $unknown = (string) ($_SESSION['flash_notice'] ?? '');

        $this->assertNotSame('', $known);
        $this->assertSame($known, $unknown,
            'the reply differs by whether the address has an account — that is a free '
            . 'membership check for anybody who wants one');
    }

    public function test_the_reset_screen_refuses_to_draw_a_password_field_for_a_dead_link(): void
    {
        $u   = $this->member();
        $raw = (string) $this->accounts()->issuePasswordReset((int) $u->id, (string) $u->email);

        $live = $this->render(['token' => $raw]);
        $this->assertMatchesRegularExpression('~<input[^>]+name="password"~', $live);
        $this->assertStringContainsString($raw, $live, 'the live token is not carried into the form');

        // Spend it, then ask for the page again.
        $this->accounts()->consumePasswordReset($raw, 'now-it-is-spent');
        $dead = $this->render(['token' => $raw]);

        $this->assertDoesNotMatchRegularExpression('~<input[^>]+name="password"~', $dead,
            'a password field was drawn against a link that cannot work');
        $this->assertStringNotContainsString($raw, $dead, 'a spent token was echoed back');
        $this->assertStringContainsString('/account/forgot', $dead, 'no way to ask for a fresh one');
    }

    /** Render the reset page through the real controller. */
    private function render(array $query): string
    {
        $res = $this->ctrl()->resetForm(
            (new ServerRequestFactory())->createServerRequest('GET', '/account/reset')
                ->withQueryParams($query),
            new Response());
        $res->getBody()->rewind();
        return (string) $res->getBody()->getContents();
    }
}
