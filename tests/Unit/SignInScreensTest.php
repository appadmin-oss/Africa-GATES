<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OrgAuth;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The two sign-ins, and what one had that the other did not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO DOORS INTO ONE PLATFORM, PROTECTED TO DIFFERENT STANDARDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/account/login` and `/org/login` are separate trust domains on separate routes, and
 * that separation is deliberate — `account/login.twig` refuses to offer organisation
 * sign-in for exactly that reason. What is NOT deliberate is the two of them having
 * arrived at different answers to the same questions, each strong where the other was
 * weak, with nothing anywhere comparing them:
 *
 *   · The organisation side throttles per IP AND locks the account after repeated
 *     failures. The member side had the IP half only — which stops one attacker from one
 *     address and does nothing about many addresses grinding ONE account, the case that is
 *     cheapest to buy. Those accounts hold voting points, a purchase history and a phone
 *     number.
 *   · The member side hands the typed address back after a failure. The organisation side
 *     sent its failure through the QUERY STRING, which carries nothing, so every retry
 *     started from an empty field — and the error survived a refresh, landed in history,
 *     and was shareable as a URL that accuses somebody who has attempted nothing.
 *
 * What both get right is held here too, because it is the property most easily lost by
 * somebody improving an error message: ONE sentence for every kind of failure.
 */
final class SignInScreensTest extends TestCase
{
    private \Slim\App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $this->app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($this->app);
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(false, false, false);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['org_login_failed'], $_SESSION['org_login_email'],
              $_SESSION['flash_error'], $_SESSION['user_login_email'],
              $_SESSION['org_user_id'], $_SESSION['org_id'], $_SESSION['user_id']);
        parent::tearDown();
    }

    private function get(string $path): string
    {
        return (string) $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path))->getBody();
    }

    private function post(string $path, array $body, string $ip = '198.51.100.7'): \Psr\Http\Message\ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', $path, ['REMOTE_ADDR' => $ip])
                ->withParsedBody($body));
    }

    // ══════════════════════ the organisation sign-in ═════════════════════════

    /**
     * A FAILURE IS AN EVENT, NOT A URL.
     *
     * `?e=1` is not a flash. It survives a refresh, so the page goes on accusing somebody
     * who has attempted nothing; it lands in browser history and in the referrer of
     * anything the page links to; and it is shareable, which turns "your sign-in is broken"
     * into a link. The member sign-in has always used a one-shot session value.
     */
    public function test_the_organisation_failure_does_not_live_in_the_url(): void
    {
        $res = $this->post('/org/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/org/login', $res->getHeaderLine('Location'),
            'the failure is still being carried in the query string');

        $this->assertStringContainsString('did not match', $this->get('/org/login'),
            'the failure is not shown at all, which is worse than showing it in the URL');

        // Consumed: a second look is a fresh visit, not a repeat of the accusation.
        $this->assertStringNotContainsString('did not match', $this->get('/org/login'),
            'the failure is replayed on every reload for the rest of the session');
    }

    /** And the retired query string cannot conjure one. */
    public function test_a_query_string_can_no_longer_accuse_anybody(): void
    {
        $this->assertStringNotContainsString('did not match', $this->get('/org/login?e=1'),
            'anybody can still hand somebody a URL that tells them their sign-in failed');
    }

    /**
     * A wrong password costs the password, not the address as well.
     *
     * Whatever was typed is handed back, account or not — which reveals nothing that was
     * not typed into this browser a moment earlier.
     */
    public function test_the_organisation_form_hands_the_address_back(): void
    {
        $this->post('/org/login', ['email' => 'Kigali@Example.TEST', 'password' => 'wrong']);

        $this->assertStringContainsString('kigali@example.test', $this->get('/org/login'),
            'a failed sign-in empties the address field and makes them type it again');
    }

    /**
     * THE ONE REMEDY THIS PAGE OFFERS HAS SOMEWHERE TO GO.
     *
     * There is deliberately no self-service reset: this sign-in can request payouts, and a
     * reset link in an inbox is a payout for whoever holds that inbox. That makes "contact
     * Africa GATES" the only way out of a lockout — and it named no address and carried no
     * link, for a reader who is by definition already locked out and cannot reach their
     * dashboard to find one.
     *
     * Asserted inside the note itself. The page has links further down (the two doors for
     * somebody with no account), so a search of the whole body finds an anchor and reports
     * a remedy that is not there — a first cut of this test did exactly that.
     */
    public function test_the_lost_access_note_is_actionable(): void
    {
        $html = $this->get('/org/login');

        $this->assertMatchesRegularExpression('~<p class="pl__note">.*?mailto:.*?</p>~s', $html,
            'the only remedy this page offers is plain text with nowhere to go');

        $this->assertStringContainsString('never send a password over email', $html,
            'the promise about how we will not contact them has gone');
    }

    // ═════════════════════════ the member sign-in ════════════════════════════

    /**
     * ONE ACCOUNT CANNOT BE GROUND FROM MANY ADDRESSES.
     *
     * The per-IP limit stops one attacker from one address. The case it does nothing about
     * is the cheap one: many addresses against a single account. The organisation side has
     * had both halves since it shipped.
     *
     * Driven from a DIFFERENT address each time, which is the whole point — a test that
     * reused one would pass on the per-IP limit alone and prove nothing about this one.
     */
    public function test_one_member_account_cannot_be_ground_from_many_addresses(): void
    {
        $email = 'ada-' . bin2hex(random_bytes(4)) . '@example.test';
        DB::table('gates_users')->insert([
            'name' => 'Ada Obi', 'email' => $email, 'phone' => '08030000000',
            'password_hash' => password_hash('correct horse battery', PASSWORD_BCRYPT),
            'points' => 0, 'status' => 'active', 'email_verified' => 1,
        ]);

        // Well under the per-IP cap on any single address, and far over a per-account one.
        for ($i = 0; $i < 14; $i++) {
            $this->post('/account/login', ['email' => $email, 'password' => 'wrong'],
                '203.0.113.' . (10 + $i));
        }

        // The real owner, with the real password, from an address that has tried nothing.
        $res = $this->post('/account/login',
            ['email' => $email, 'password' => 'correct horse battery'], '198.51.100.99');

        $this->assertSame('/account/login', $res->getHeaderLine('Location'),
            'a fourteen-address grind against one account is not slowed at all');
    }

    /**
     * EVERY WAY OF FAILING IS TOLD THE SAME SENTENCE.
     *
     * Four states, and all four have to be indistinguishable:
     *
     *   a wrong password on a real account · a wrong password on an address with no
     *   account · either of those once the new per-account throttle has tripped.
     *
     * The first pair is the oracle this page has always closed. The second pair is the one
     * the throttle could open by accident: "too many attempts for this account" is a
     * different sentence from "those do not match", so an attacker learns which state an
     * address is in, and a message written to be helpful becomes the leak the control was
     * added to prevent.
     *
     * ── A NOTE ON HOW THIS TEST WAS WRONG FIRST ──────────────────────────────
     *
     * It compared a THROTTLED known address against a THROTTLED unknown one, and passed
     * with the account named in the message — because the bucket is keyed on the email
     * whether or not an account exists, so both were throttled and both got the same
     * wrong sentence. It read as covering enumeration and covered nothing. The comparison
     * that matters is across the throttle boundary, not along it.
     */
    public function test_every_way_of_failing_is_told_the_same_thing(): void
    {
        $known = 'zoe-' . bin2hex(random_bytes(4)) . '@example.test';
        DB::table('gates_users')->insert([
            'name' => 'Zoe Ade', 'email' => $known, 'phone' => '08030000000',
            'password_hash' => password_hash('correct horse battery', PASSWORD_BCRYPT),
            'points' => 0, 'status' => 'active', 'email_verified' => 1,
        ]);
        $unknown = 'nobody-' . bin2hex(random_bytes(4)) . '@example.test';

        $say = function (string $email, string $ip): string {
            $this->post('/account/login', ['email' => $email, 'password' => 'wrong'], $ip);
            $said = (string) ($_SESSION['flash_error'] ?? '');
            unset($_SESSION['flash_error']);
            return $said;
        };

        $said = [
            'a wrong password on a real account'   => $say($known, '192.0.2.11'),
            'a wrong password on no account'       => $say($unknown, '192.0.2.12'),
        ];

        // Trip the per-account bucket for both, from addresses that are each well under
        // the per-IP cap — otherwise this measures the IP limit instead.
        foreach ([$known, $unknown] as $n => $email) {
            for ($i = 0; $i < 12; $i++) {
                $say($email, '203.0.113.' . (150 + $n * 20 + $i));
            }
        }

        $said['a throttled real account'] = $say($known, '192.0.2.13');
        $said['a throttled unknown address'] = $say($unknown, '192.0.2.14');

        $this->assertNotSame('', $said['a wrong password on a real account'],
            'this test proves nothing: no message is being set at all');

        $this->assertSame([], array_keys(array_diff($said, [reset($said)])), sprintf(
            "these states are told different things, which is an enumeration oracle:\n  %s",
            implode("\n  ", array_map(
                static fn (string $k, string $v): string => "$k → \"$v\"",
                array_keys($said), $said))));
    }
}
