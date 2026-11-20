<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\ChallengeController;
use AfricaGates\Services\ChallengeCopy;
use AfricaGates\Services\ChallengeService as CS;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Signed in is not entered — and the join button has to write the entry.
 *
 * `ChallengeService::join()` had no caller. The page called every signed-in visitor
 * "joined", offered "Add a nominee" and sent them to /nominate, so no entry was ever
 * written and `attachNomination()` had nothing to count their nominations against.
 */
final class ChallengeJoinTest extends TestCase
{
    private function challenge(): object
    {
        $slug = 'join-' . bin2hex(random_bytes(3));
        DB::table('gates_challenges')->insert([
            'slug' => $slug, 'title' => 'Join probe', 'kicker' => 'Nominate someone',
            'action' => 'nominate', 'target' => 10, 'mode' => 'first', 'cap' => 10,
            'prize_type' => 'cash_each', 'prize_amount' => 5000, 'prize_currency' => 'NGN',
            'theme' => 'green', 'flag' => 0, 'status' => 'open',
            'starts_at' => date('Y-m-d H:i:s', time() - 86400),
            'ends_at'   => date('Y-m-d H:i:s', time() + 864000),
            'published_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return CS::bySlug($slug);
    }

    private function user(?string $phone): int
    {
        return (int) DB::table('gates_users')->insertGetId([
            'name' => 'Ada Obi', 'email' => 'ada' . bin2hex(random_bytes(3)) . '@gates.test',
            'phone' => $phone, 'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function press(object $c): \Psr\Http\Message\ResponseInterface
    {
        $twig = $this->createMock(\Slim\Views\Twig::class);
        $req  = (new ServerRequestFactory())->createServerRequest('POST', '/challenges/' . $c->slug . '/join');
        return (new ChallengeController($twig))->join($req, (new ResponseFactory())->createResponse(), ['slug' => $c->slug]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    public function test_a_signed_in_visitor_with_no_entry_is_offered_the_join_not_told_they_are_in(): void
    {
        $copy = ChallengeCopy::for((array) $this->challenge(), ['signed_in' => true, 'entered' => false]);

        $this->assertTrue($copy['cta_post']);
        $this->assertSame('Join now', $copy['cta']);
        $this->assertStringEndsWith('/join', $copy['cta_href']);
        $this->assertFalse($copy['joined']);
    }

    public function test_an_entrant_is_sent_on_to_nominate(): void
    {
        $copy = ChallengeCopy::for((array) $this->challenge(), ['signed_in' => true, 'entered' => true]);

        $this->assertFalse($copy['cta_post']);
        $this->assertSame('/nominate', $copy['cta_href']);
    }

    public function test_pressing_join_writes_the_entry_nominations_count_against(): void
    {
        $c = $this->challenge();
        $_SESSION['user_id'] = $uid = $this->user('0803 123 4567');

        $res = $this->press($c);

        $this->assertSame(303, $res->getStatusCode());
        $this->assertSame('/challenges/' . $c->slug, $res->getHeaderLine('Location'));
        $this->assertNotNull(CS::entryFor((int) $c->id, $uid), 'the join wrote no entry');

        // Twice is one entry.
        $this->press($c);
        $this->assertSame(1, DB::table('gates_challenge_entries')->where('challenge_id', $c->id)->count());
    }

    public function test_no_phone_is_refused_with_the_reason(): void
    {
        $c = $this->challenge();
        $_SESSION['user_id'] = $uid = $this->user(null);

        $this->press($c);

        $this->assertNull(CS::entryFor((int) $c->id, $uid));
        $this->assertStringContainsString('phone number', (string) $_SESSION['flash_error']);
    }

    public function test_signed_out_goes_to_sign_in_and_back(): void
    {
        $c = $this->challenge();
        unset($_SESSION['user_id']);

        $res = $this->press($c);

        $this->assertSame('/account/login?next=' . rawurlencode('/challenges/' . $c->slug), $res->getHeaderLine('Location'));
    }

    public function test_the_route_is_registered_as_a_post(): void
    {
        $this->assertStringContainsString("\$g->post('/challenges/{slug:[a-z0-9-]{1,160}}/join'",
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php'));
    }
}
