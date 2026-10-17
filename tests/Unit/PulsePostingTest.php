<?php
declare(strict_types=1);
namespace Tests\Unit;
use AfricaGates\Controllers\PulseController;
use AfricaGates\Services\CommunityService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;
/**
 * People can post to the Pulse.
 *
 * Pulse shipped read-only — a wall of things the platform had published AT its
 * members, with not one form on the page. These pin the three properties that make
 * it a feed instead:
 *
 *   • a member's post is really stored, and stored AS A COMMUNITY THREAD, so it
 *     inherits the spam filter, the moderation verdict, the moderator queue, replies
 *     and cheers rather than needing a second set of all of them;
 *   • a GUEST cannot write. Reading is public, posting is not, and the assertion
 *     checks the database is untouched rather than trusting the redirect;
 *   • the author comes from the ACCOUNT, never the form, so a post cannot be
 *     attributed to someone else.
 */
final class PulsePostingTest extends TestCase
{
    public function test_a_member_can_post_to_the_pulse(): void
    {
        DB::table('gates_threads')->delete();
        $uid = (int) DB::table('gates_users')->insertGetId([
            'name'=>'Amara Nwosu','email'=>'amara@example.test','status'=>'active',
            'created_at'=>date('Y-m-d H:i:s'),
        ]);
        $_SESSION['user_id'] = $uid;

        $b = new \DI\ContainerBuilder(); $b->addDefinitions(require dirname(__DIR__,2).'/config/container.php');
        $c = $b->build()->get(PulseController::class);

        $req = (new ServerRequestFactory())->createServerRequest('POST','/pulse')
            ->withParsedBody(['body' => 'Just watched the Young Innovator category flip overnight. Ada is 11 votes off the lead — this is going down to the wire.']);
        $res = $c->post($req, new Response());

        $this->assertSame(302, $res->getStatusCode());
        $row = DB::table('gates_threads')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'the post must exist as a thread');
        // A status update has no headline, so postThread's required title is derived
        // from the first sentence — the thread page and its URL then read like the
        // post instead of "untitled-4".
        $this->assertSame('Just watched the Young Innovator category flip overnight', (string) $row->title);
        $this->assertSame('just-watched-the-young-innovator-category-flip-overnight', (string) $row->slug);
        $this->assertStringContainsString('Young Innovator', (string) $row->body);
        $this->assertSame('Amara Nwosu', (string) $row->author_name);
    }

    public function test_a_guest_is_sent_to_sign_in_and_writes_nothing(): void
    {
        DB::table('gates_threads')->delete();
        unset($_SESSION['user_id']);
        $b = new \DI\ContainerBuilder(); $b->addDefinitions(require dirname(__DIR__,2).'/config/container.php');
        $res = $b->build()->get(PulseController::class)->post(
            (new ServerRequestFactory())->createServerRequest('POST','/pulse')->withParsedBody(['body'=>'hello']),
            new Response());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('/account/login', $res->getHeaderLine('Location'));
        $this->assertSame(0, DB::table('gates_threads')->count(), 'a guest must not be able to write');
    }

    /**
     * A malformed body is rejected cleanly, with no PHP warning and no fatal.
     *
     * Both of these are what a hand-rolled POST sends, not a browser: no `body` field
     * at all, and `body[]` which arrives as an array. The first used to warn (`??`
     * only silences the array access it directly wraps, and the string cast was
     * outside it); the second would have fataled on the cast. Warnings turn into
     * failures here, so this test is the guard.
     */
    public function test_a_malformed_body_is_rejected_without_a_warning(): void
    {
        DB::table('gates_threads')->delete();
        $uid = (int) DB::table('gates_users')->insertGetId([
            'name'=>'Chidi Okeke','email'=>'chidi@example.test','status'=>'active',
            'created_at'=>date('Y-m-d H:i:s'),
        ]);
        $_SESSION['user_id'] = $uid;

        $b = new \DI\ContainerBuilder(); $b->addDefinitions(require dirname(__DIR__,2).'/config/container.php');
        $c = $b->build()->get(PulseController::class);

        foreach ([[], ['body' => ['a','b']], ['body' => '   ']] as $payload) {
            $res = $c->post(
                (new ServerRequestFactory())->createServerRequest('POST','/pulse')->withParsedBody($payload),
                new Response());
            $this->assertSame(302, $res->getStatusCode());
            $this->assertSame('/pulse', $res->getHeaderLine('Location'));
        }
        $this->assertSame(0, DB::table('gates_threads')->count(), 'nothing malformed may be stored');
    }

    /** The title is derived, never asked for — nobody writes a headline for a status. */
    public function test_the_derived_title_reads_like_the_post(): void
    {
        $this->assertSame('Ada is 11 votes off the lead',
            PulseController::titleFrom('Ada is 11 votes off the lead. Every vote counts now!'));
        $this->assertSame('Short one', PulseController::titleFrom('Short one'));
        $this->assertStringEndsWith('…', PulseController::titleFrom(str_repeat('word ', 40)));
        $this->assertSame('Pulse post', PulseController::titleFrom('   '));
    }
}
