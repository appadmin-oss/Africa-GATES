<?php
declare(strict_types=1);

namespace Tests\Unit;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * GET /__setup/assistant — "is the support assistant actually working?"
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ENDPOINT EARNS ITS KEEP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "The assistant is not working" has at least six causes and one symptom: no
 * provider key; a key that is expired or out of quota; a key whose circuit breaker
 * has latched open; a working assistant being looked for on the wrong page; the
 * tools working while the model does not; and the unattended ticket queue not
 * being swept because cron is not running.
 *
 * There is no shell on this host, so none of that is visible in a log. Every one
 * of the six has a different fix and they are indistinguishable from outside.
 *
 * The most useful section is the last: it runs the real planner against real
 * example questions and prints the tools each would call. That both demonstrates
 * the routing and shows the thing that is easy to disbelieve — that the assistant
 * repairs payments with no AI key at all, because the repair is ordinary code.
 */
final class AssistantEndpointTest extends TestCase
{
    private const TOKEN = 'assistanttesttoken1234';

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
            (new ServerRequestFactory())->createServerRequest('GET', '/__setup/assistant' . $qs)
        );
    }

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

    /** 404, not 403 — a 403 confirms the endpoint exists, which is half the answer. */
    public function test_it_is_invisible_without_the_token(): void
    {
        $this->assertSame(404, $this->get('')->getStatusCode());
        $this->assertSame(404, $this->get('?token=wrong')->getStatusCode());
        $this->assertSame(404, $this->get('?token=short')->getStatusCode());
    }

    public function test_an_unset_token_cannot_be_matched_by_an_empty_one(): void
    {
        unset($_ENV['SETUP_TOKEN']);

        $this->assertSame(404, $this->get('?token=')->getStatusCode());
        $this->assertSame(404, $this->get('?token=' . self::TOKEN)->getStatusCode(),
            'with no token configured the endpoint must stay closed, not open to everyone');
    }

    public function test_it_reports_the_model_half_and_the_working_half_separately(): void
    {
        $res  = $this->get('?token=' . self::TOKEN);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));

        // The distinction the whole page exists to draw.
        $this->assertStringContainsString('The model (planning and phrasing)', $html);
        $this->assertStringContainsString('The work (looking things up and fixing them)', $html);
        $this->assertStringContainsString('Unattended ticket queue', $html);
        // The columns a Paystack receipt number is searched by — a missing migration
        // here is the difference between finding a supporter's payment and not.
        $this->assertStringContainsString('gates_donations.gateway_txn_id', $html);
    }

    /**
     * THE SECTION THAT MATTERS: it must actually route, not just claim it can.
     *
     * A demonstration that plans nothing is worse than no demonstration — it would
     * read as proof the assistant is broken when it is fine, or the reverse.
     */
    public function test_it_shows_the_real_routing_for_real_questions(): void
    {
        $html = (string) $this->get('?token=' . self::TOKEN)->getBody();

        // The payment repair, which needs no model at all.
        $this->assertStringContainsString('fix_payment', $html);
        // A free vote, which must never be answered with a payment tool.
        $this->assertStringContainsString('free_vote_help', $html);
        // An outage, checked before anybody is asked for a reference.
        $this->assertStringContainsString('gateway_status', $html);

        $this->assertStringNotContainsString('would answer from the Help Centre', $html,
            'every sample question is chosen to route somewhere — if one stopped routing, '
            . 'the planner has regressed and this page is the place that says so');
        $this->assertStringNotContainsString('(failed:', $html, 'the planner threw');
    }

    /**
     * With no key configured, the verdict must say the assistant WORKS — because it
     * does — rather than that it is down.
     *
     * This is the whole behavioural change being defended. Reporting "not working"
     * to somebody whose payment repair, receipt resend and ticket queue are all
     * running would send them looking for a fault that is not there.
     */
    public function test_with_no_provider_it_reports_working_without_a_key_not_broken(): void
    {
        $html = (string) $this->get('?token=' . self::TOKEN)->getBody();

        $this->assertStringContainsString('Operational without an AI key', $html);
        $this->assertStringContainsString('free tier', $html, 'and it says how to add the phrasing layer');
    }

    /**
     * The schedule is reported, and reported SEPARATELY from the assistant's own
     * prerequisites.
     *
     * The page used to say "the queue is swept from maintenance, so confirm the cron
     * job is running" — an instruction, where CronHealth could give a fact. But when
     * that fact was first folded into the same list as the database checks, a missing
     * cron job turned the headline into "Something the assistant needs is missing",
     * which sends an operator to inspect columns that are perfectly fine.
     *
     * They are different faults with different reactions: one stops the assistant
     * answering, the other stops the platform paying people.
     */
    public function test_the_schedule_is_reported_as_its_own_question(): void
    {
        $html = (string) $this->get('?token=' . self::TOKEN)->getBody();

        $this->assertStringContainsString('Is any of it actually running?', $html);
        $this->assertStringContainsString('Scheduled work', $html);
        $this->assertStringContainsString('Runs from web traffic', $html);

        // No cron rows exist in the fixture, so the schedule is a fault here — and
        // it must NOT be described as the assistant lacking something.
        $this->assertStringNotContainsString('Something the assistant needs is missing', $html,
            'a missing cron job is being reported as a broken assistant');
        $this->assertStringContainsString('more urgently', $html,
            'and the schedule fault must still be said plainly in the verdict');
    }

    /**
     * A live provider call costs quota, and a URL can be prefetched, bookmarked and
     * retried on a flaky connection. So it happens only when asked for.
     */
    public function test_the_live_provider_call_is_opt_in(): void
    {
        $this->assertStringNotContainsString('Live call to the provider',
            (string) $this->get('?token=' . self::TOKEN)->getBody());
        $this->assertStringContainsString('Live call to the provider',
            (string) $this->get('?token=' . self::TOKEN . '&ping=1')->getBody());
    }

    /**
     * No API key may ever appear on this page, in any form.
     *
     * It is reachable by anyone holding the setup token, and a provider key is a
     * bearer credential — a masked key is still four characters of a secret plus
     * confirmation of which provider to attack.
     */
    public function test_it_never_prints_a_key(): void
    {
        $secret = 'gsk_liveKeyThatMustNotAppear999';
        $_ENV['GROQ_API_KEY'] = $secret;
        try {
            $html = (string) $this->get('?token=' . self::TOKEN)->getBody();
            $this->assertStringNotContainsString($secret, $html);
            $this->assertStringNotContainsString(substr($secret, -4), $html,
                'not even the tail — this page is not the place to confirm a key');
            $this->assertStringContainsString('Groq key', $html, 'it still reports THAT one is set');
        } finally {
            unset($_ENV['GROQ_API_KEY']);
        }
    }

    /** Read-only, asserted rather than assumed. */
    public function test_it_changes_nothing(): void
    {
        $before = [
            'tickets'  => DB::table('gates_support_tickets')->count(),
            'messages' => DB::table('gates_support_messages')->count(),
            'settings' => DB::table('gates_settings')->count(),
        ];

        $this->get('?token=' . self::TOKEN);

        $this->assertSame($before['tickets'], DB::table('gates_support_tickets')->count());
        $this->assertSame($before['messages'], DB::table('gates_support_messages')->count(),
            'a diagnostic that replies to a ticket is not a diagnostic');
        $this->assertSame($before['settings'], DB::table('gates_settings')->count());
    }
}
