<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * GET /__setup/deployed — "is the thing I just uploaded actually live?"
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ENDPOINT EARNS ITS KEEP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This platform is deployed by uploading a zip through cPanel File Manager and
 * pressing Extract. No shell, no build, no deploy log. So when a feature does not
 * appear, four completely different causes produce one identical symptom:
 *
 *   the files never landed · the migration has not run · a setting hides the
 *   feature · it is all working and the operator is looking in the wrong place
 *
 * Diagnosing that over a chat window is guesswork, and it was: two rounds of "I
 * cannot find it" went by before the real cause (a setting that hid the form the
 * field lived in) was identified. This reads the disk and the database and says
 * which of the four it is.
 *
 * The tests below are about the two properties that make it safe to ship: it is
 * INVISIBLE without the setup token, and it is READ-ONLY.
 */
final class DeployedEndpointTest extends TestCase
{
    private const TOKEN = 'deployedtesttoken1234';

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        return $app;
    }

    private function get(string $qs): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/__setup/deployed' . $qs)
        );
    }

    /**
     * `$_ENV` because that is the first source {@see \AfricaGates\Support\Env::raw()}
     * consults, and there is no setter — the class is deliberately read-only so a
     * runtime cannot rewrite its own configuration.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['SETUP_TOKEN'] = self::TOKEN;
    }

    protected function tearDown(): void
    {
        unset($_ENV['SETUP_TOKEN']);
        parent::tearDown();
    }

    /**
     * 404, not 403. A public inventory of which files sit on the server is a
     * reconnaissance gift, and "403 Forbidden" confirms the endpoint exists — which is
     * half of what an attacker wanted to know. Every /__setup route behaves this way.
     */
    public function test_it_is_invisible_without_the_token(): void
    {
        $this->assertSame(404, $this->get('')->getStatusCode());
        $this->assertSame(404, $this->get('?token=wrong')->getStatusCode(), 'a wrong token must look identical to no endpoint');
        // Short tokens are refused before any comparison, so a one-character guess
        // cannot be used to time the check.
        $this->assertSame(404, $this->get('?token=short')->getStatusCode());
    }

    public function test_an_unset_token_cannot_be_matched_by_an_empty_one(): void
    {
        unset($_ENV['SETUP_TOKEN']);

        $this->assertSame(404, $this->get('?token=')->getStatusCode());
        $this->assertSame(404, $this->get('?token=' . self::TOKEN)->getStatusCode(),
            'with no token configured the endpoint must stay closed, not open to everyone');
    }

    public function test_with_the_token_it_reports_what_is_on_disk_and_in_the_database(): void
    {
        $res  = $this->get('?token=' . self::TOKEN);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        // Never cached and never indexed: it names internal paths.
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));

        // The feature-level checks a reader is looking for.
        $this->assertStringContainsString('The message box on the paid form', $html);
        $this->assertStringContainsString('Table gates_vote_messages', $html);
        $this->assertStringContainsString('Where to look', $html);

        // ── EVERY SHIPPED FEATURE, NOT JUST THE FIRST ONE ────────────────────
        //
        // This page was written for one release and then four more shipped behind
        // it, and it went on reporting "Everything is in place" while checking none
        // of them. That is the worst possible failure for a page whose whole job is
        // to be believed: the operator opens the URL the upload notes point at, sees
        // green, and still has no idea whether what they just uploaded is live.
        //
        // So each release has a heading here. A new one that forgets to add itself
        // will not fail this test — nothing can assert the absence of a thing nobody
        // wrote — but every release that HAS been added stays asserted, which is
        // what stops them silently dropping out again.
        foreach ([
            'Comments on a vote, and sharing',
            'The full nomination story, and every supporter',
            'Profile claiming: the cooling-off period and the freeze link',
            'Finding a payment by the number the supporter has',
            'The support assistant, with or without an AI key',
            'The Help Centre, and one door for nominations',
        ] as $heading) {
            $this->assertStringContainsString(htmlspecialchars($heading, ENT_QUOTES), $html,
                'the deployment page no longer reports on: ' . $heading);
        }

        // And the columns each of those needs, because "the files landed but the
        // migration was skipped" is the commonest half-done upload and it produces a
        // feature that looks broken rather than absent.
        foreach ([
            'gates_nominees.story',
            'gates_donations.gateway_txn_id',
            'gates_nominee_claims.dispute_token',
            'gates_nominee_claims.cooling_off_until',
        ] as $col) {
            $this->assertStringContainsString($col, $html, $col . ' is not checked');
        }

        // And it found the real files, which is the whole point — a check that cannot
        // fail is not a check.
        $this->assertStringContainsString('present + current', $html);
        // The ROW form, not the bare phrase: the page also explains what "PRESENT BUT
        // OLD" means in its closing section, so asserting on the phrase alone can never
        // pass. `(marker absent)` only ever appears in a failing row.
        $this->assertStringNotContainsString('(marker absent)', $html,
            'a shipped template is missing the marker this page looks for — either the '
            . 'marker or the template has drifted');
        // And no row failed at all, which is the real claim.
        $this->assertStringNotContainsString('class="err"', $html,
            'the diagnostic reports something missing in a clean checkout');
    }

    /**
     * READ-ONLY, and asserted rather than assumed. A diagnostic that mutates anything is
     * a diagnostic nobody dares open on a live site at the exact moment they need it.
     */
    public function test_it_changes_nothing(): void
    {
        DB::table('gates_vote_messages')->insert([
            'nominee_id' => 1, 'voter_email_hash' => 'x', 'body' => 'before',
            'status' => 'approved', 'share_token' => 'tok_before_readonly1', 'created_at' => '2026-08-01 00:00:00',
        ]);
        $before = [
            'messages' => DB::table('gates_vote_messages')->count(),
            'settings' => DB::table('gates_settings')->count(),
            'body'     => DB::table('gates_vote_messages')->value('body'),
        ];

        $this->get('?token=' . self::TOKEN);

        $this->assertSame($before['messages'], DB::table('gates_vote_messages')->count());
        $this->assertSame($before['settings'], DB::table('gates_settings')->count());
        $this->assertSame($before['body'], DB::table('gates_vote_messages')->value('body'));
    }

    /**
     * The "where to look" answer must follow the settings, because that is the question
     * it exists to answer: a paid-only ballot puts the box somewhere different from a
     * free one, and no amount of looking at the page from outside reveals which.
     */
    public function test_where_to_look_follows_the_live_settings(): void
    {
        $set = function (string $k, string $v): void {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        };

        $set('paid_voting_enabled', '1');
        $set('paid_voting_disable_free', '1');
        $paidOnly = (string) $this->get('?token=' . self::TOKEN)->getBody();

        $this->assertStringContainsString('contribution panel', $paidOnly);
        $this->assertStringNotContainsString('free vote form', $paidOnly,
            'a paid-only ballot was told to look at a form it does not render — the exact '
            . 'wrong answer that cost two rounds of back and forth');

        $set('paid_voting_disable_free', '0');
        $both = (string) $this->get('?token=' . self::TOKEN)->getBody();

        $this->assertStringContainsString('free vote form', $both);
        $this->assertStringContainsString('contribution panel', $both);
    }
}
