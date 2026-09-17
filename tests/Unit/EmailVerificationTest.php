<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\UserAccountService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * THE VERIFICATION LINK IS A SIGN-IN, AND HAS TO BE HELD LIKE ONE.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO READERS OF "MAY THIS ACCOUNT BE SIGNED IN", AND THEY DISAGREED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `findByEmail()` has always required `status = 'active'`, so `attemptLogin()` never finds
 * a member who is not — a password simply stops working. The two LINK paths resolve their
 * account with `findById()` instead, which has no status filter because it is also how a
 * profile is read, and both then call `startSession()`.
 *
 * So a verification link and a password-reset link each opened a door the password had
 * already been refused at. Measured: a suspended member was refused a password login and
 * allowed in by both links.
 *
 * ── STATED PRECISELY: THIS IS LATENT, NOT LIVE ───────────────────────────────
 *
 * `gates_users.status` is a free VARCHAR defaulting to 'active' and no admin screen writes
 * it, so nothing on this platform can currently produce a member who is not active. What
 * makes it worth closing anyway is WHO would make it live: somebody adding a suspend
 * button will be looking at a members table, not at two token consumers in a service, and
 * the account they suspend would keep a working way in that nothing on their screen
 * mentions.
 *
 * `UserAccountService::canSignIn()` is the one answer now, and these tests ask both paths
 * the same question so the two cannot drift apart again.
 */
final class EmailVerificationTest extends TestCase
{
    private function accounts(): UserAccountService
    {
        return new UserAccountService();
    }

    /** @return array{0:int,1:string} */
    private function member(string $status = 'active'): array
    {
        $email = 'v-' . bin2hex(random_bytes(4)) . '@example.test';
        $id = (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ada Obi', 'email' => $email, 'phone' => '08030000000',
            'password_hash' => password_hash('the password', PASSWORD_BCRYPT),
            'points' => 0, 'status' => $status, 'email_verified' => 0,
        ]);
        return [$id, $email];
    }

    // ═══════════════════ one answer, for every way in ════════════════════════

    /**
     * A LINK DOES NOT OPEN A DOOR THE PASSWORD IS REFUSED AT.
     *
     * Both paths asked in one test, because the finding is not "verification is wrong" —
     * it is that the three ways into an account gave two different answers, and a test
     * that checked one of them would let the other drift back.
     */
    public function test_a_link_cannot_sign_in_an_account_a_password_cannot(): void
    {
        $svc = $this->accounts();

        [$liveId, $liveEmail] = $this->member();
        [$deadId, $deadEmail] = $this->member('suspended');

        // The baseline: the password path already disagreed with the links.
        $this->assertNotNull($svc->attemptLogin($liveEmail, 'the password'),
            'this test proves nothing: an ordinary member cannot sign in either');
        $this->assertNull($svc->attemptLogin($deadEmail, 'the password'),
            'this test proves nothing: the password path never refused a suspended member');

        // Verification link.
        $this->assertNotNull(
            $svc->verifyEmailToken((string) $svc->issueEmailVerification($liveId, $liveEmail)),
            'an ordinary member can no longer verify');
        $this->assertNull(
            $svc->verifyEmailToken((string) $svc->issueEmailVerification($deadId, $deadEmail)),
            'a verification link signs in an account whose password is refused');

        // Reset link — the same question, asked of the other path.
        $this->assertNotNull(
            $svc->consumePasswordReset((string) $svc->issuePasswordReset($liveId, $liveEmail), 'a brand new password'),
            'an ordinary member can no longer reset');
        $this->assertNull(
            $svc->consumePasswordReset((string) $svc->issuePasswordReset($deadId, $deadEmail), 'a brand new password'),
            'a reset link signs in an account whose password is refused');
    }

    /**
     * A PRESENTED LINK IS SPENT, EVEN WHEN IT IS REFUSED.
     *
     * Otherwise the refusal is a retry loop: the link stays live in the inbox and the same
     * person can keep presenting it for the rest of its window. Both consumers burn before
     * they resolve the account, for exactly this reason.
     */
    public function test_a_refused_link_is_still_spent(): void
    {
        $svc = $this->accounts();
        [$id, $email] = $this->member('suspended');

        $verify = (string) $svc->issueEmailVerification($id, $email);
        $reset  = (string) $svc->issuePasswordReset($id, $email);

        $this->assertNull($svc->verifyEmailToken($verify));
        $this->assertNull($svc->consumePasswordReset($reset, 'a brand new password'));

        $live = static fn (string $email, string $purpose): int => (int) DB::table('gates_otp_tokens')
            ->where('email_hash', hash('sha256', $email))->where('purpose', $purpose)
            ->where('is_used', 0)->count();

        $this->assertSame(0, $live($email, 'verify_email'),
            'a refused verification link is still sitting live in the inbox');
        $this->assertSame(0, $live($email, UserAccountService::RESET_PURPOSE),
            'a refused reset link is still sitting live in the inbox');
    }

    // ═══════════════════════ the resend, and the page ════════════════════════

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        return $app;
    }

    /**
     * RESEND WAS THE ONE MAIL-SENDING ENDPOINT COUNTING ONE OF THE TWO.
     *
     * Four an address, and nothing counting the connection — so four messages per address
     * is a small number right up until one connection walks a list of ten thousand of
     * them. Every sibling in this controller counts both.
     *
     * Driven from ONE address with a DIFFERENT email each time, which is the whole point:
     * a test that reused one address would pass on the per-address limit alone and prove
     * nothing about the one being added.
     */
    public function test_one_connection_cannot_resend_to_an_endless_list_of_addresses(): void
    {
        $app  = $this->app();
        $ip   = '203.0.113.' . random_int(2, 240);
        $seen = [];

        for ($i = 0; $i < 30; $i++) {
            [, $email] = $this->member();
            $app->handle(
                (new ServerRequestFactory())
                    ->createServerRequest('POST', '/account/verify/resend', ['REMOTE_ADDR' => $ip])
                    ->withParsedBody(['email' => $email]));
            $seen[] = (int) DB::table('gates_otp_tokens')
                ->where('email_hash', hash('sha256', $email))
                ->where('purpose', 'verify_email')->count();
        }

        $delivered = count(array_filter($seen));
        $this->assertLessThan(30, $delivered,
            'one connection resent to thirty different addresses with nothing counting it');
        $this->assertGreaterThan(0, $delivered,
            'the limit refuses everybody, which is not a limit but an outage');
    }

    /**
     * THE PAGE STATES THE WINDOW, AND READS IT FROM THE CODE.
     *
     * The life of the link was in the EMAIL and nowhere else, so the one person who could
     * not read it was the one whose link had already expired — they met the rule as
     * "invalid or expired", with no sign there had ever been a clock.
     *
     * Asserted against the CONSTANT the token is minted with rather than against "24", so
     * a number typed into the template fails the moment the two disagree. That is this
     * repo's own rule about looping a figure from the code, and this page is the cheapest
     * possible place to break it.
     */
    public function test_the_notice_states_the_window_and_gets_it_from_the_constant(): void
    {
        $html = (string) $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/account/verify'))->getBody();

        $this->assertStringContainsString(
            'lasts ' . UserAccountService::VERIFY_TTL_HOURS . ' hours', $html,
            'the page does not state how long the link lives, or has its own copy of the number');

        // The commonest reason a verification message "has not arrived".
        $this->assertStringContainsString('junk folder', $html,
            'the page offers a resend without first suggesting the free thing');
    }
}
