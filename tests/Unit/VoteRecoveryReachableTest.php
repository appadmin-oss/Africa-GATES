<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\VoteRecoveryController;
use AfricaGates\Controllers\VoteController;
use AfricaGates\Services\VoteRecoveryService as Recover;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Vote recovery, reachable — and impossible to use quietly.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see \AfricaGates\Services\VoteRecoveryService} is complete and carefully tested
 * — {@see VoteRecoveryTest} holds its derivation, its cap, its two-person rule and
 * its reversal, twenty-three ways. It was reachable from exactly one place:
 * `bin/console votes:recover`. There is no SSH on this deployment.
 *
 * And it could not have worked from a shell either. The command's own docblock says
 * "`approve` happens in the admin panel where the approver is an authenticated
 * person" — and no admin panel existed. `apply()` refuses anything not `approved`,
 * so no batch could reach the tally by any route at all. §18: a mechanism with no
 * route in, complete and correct in every part.
 *
 * ── AND THE HALF THAT MATTERS MORE ───────────────────────────────────────────
 *
 * The service's doctrine lists six controls and calls none of them ceremony. Five
 * were enforced in code. The sixth — "public disclosure of every applied batch" —
 * was {@see Recover::disclosureFor()}, which nothing called, while
 * `docs/CODEBASE-INDEX.md` stated as settled fact that a batch "is disclosed
 * publicly per nominee".
 *
 * So opening a route in WITHOUT publishing its use would have been strictly worse
 * than leaving the mechanism dead: a way to add votes to a public tally, quietly.
 * Both halves are held here, in the same file, deliberately.
 */
final class VoteRecoveryReachableTest extends TestCase
{
    private const PREPARER = 11;
    private const APPROVER = 22;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_admins')->insert([
            ['id' => self::PREPARER, 'name' => 'Ngozi',  'email' => 'a@example.org', 'role' => 'superadmin'],
            ['id' => self::APPROVER, 'name' => 'Tunde',  'email' => 'b@example.org', 'role' => 'superadmin'],
        ]);
        // is_active = 1 because that one column is the whole thing keeping the sandbox
        // off the public site — nomineeBallot() refuses an inactive programme outright.
        DB::table('gates_award_programmes')->insert([
            'id' => 1, 'slug' => 'p1', 'title' => 'Africa GATES', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'judging',
            'voting_open'  => Carbon::now()->subDays(20)->toDateTimeString(),
            'voting_close' => Carbon::now()->subDays(2)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'c1', 'title' => 'Music']);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 100, 'organic_vote_count' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'],
              $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    /** A vote attempt whose code this platform failed to send. */
    private function attempt(string $who): void
    {
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', $who),
            'token_hash' => hash('sha256', '000000'),
            'purpose'    => 'vote', 'nominee_id' => 1, 'award_id' => 1,
            'attempts'   => 0, 'is_used' => 0,
            'delivery_state' => 'failed', 'delivery_error' => 'SMTP 421 relay unavailable',
            'expires_at' => Carbon::now()->subDays(5)->toDateTimeString(),
            'created_at' => Carbon::now()->subDays(6)->toDateTimeString(),
        ]);
    }

    private function as(int $adminId, string $role = 'superadmin'): void
    {
        $_SESSION['admin_id']   = $adminId;
        $_SESSION['admin_role'] = $role;
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    /** Drive one admin route the way the browser does. @return array{0:int,1:string} */
    private function post(string $action, array $body, array $args = []): array
    {
        $ctrl = $this->container()->get(VoteRecoveryController::class);
        $req  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/vote-recovery')
            ->withParsedBody($body);
        $res  = $ctrl->{$action}($req, (new ResponseFactory())->createResponse(), $args);

        return [$res->getStatusCode(), $res->getHeaderLine('Location')];
    }

    private function get(string $action, array $args = [], string $query = ''): string
    {
        $ctrl = $this->container()->get(VoteRecoveryController::class);
        $req  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/vote-recovery' . ($query !== '' ? '?' . $query : ''));
        if ($query !== '') {
            parse_str($query, $q);
            $req = $req->withQueryParams($q);
        }
        $res = $ctrl->{$action}($req, (new ResponseFactory())->createResponse(), $args);

        $this->assertSame(200, $res->getStatusCode(),
            $action . '() redirected instead of rendering — it refused before reaching the template');

        return (string) $res->getBody();
    }

    /** The window the outage covered. @return array{0:string,1:string} */
    private function window(): array
    {
        return [Carbon::now()->subDays(7)->toDateTimeString(),
                Carbon::now()->subDays(5)->toDateTimeString()];
    }

    private function openBatch(int $attempts = 3): int
    {
        for ($i = 0; $i < $attempts; $i++) $this->attempt('voter' . $i . '@example.org');
        [$f, $t] = $this->window();

        $this->as(self::PREPARER);
        $this->post('open', ['cycle_id' => 1, 'window_from' => $f, 'window_to' => $t,
                             'incident_note' => 'Relay rejected every send for two hours.']);

        $id = (int) DB::table('gates_vote_recovery_batches')->max('id');
        $this->assertGreaterThan(0, $id, 'no batch was opened');

        return $id;
    }

    // ══ the route in ═════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. The whole workflow, from a browser, end to end.
     *
     * Before this existed no batch could reach `applied` by any route: the CLI cannot
     * approve, and `apply()` refuses anything not approved.
     */
    public function test_a_batch_can_be_taken_from_nothing_to_the_tally_without_a_shell(): void
    {
        $id = $this->openBatch(3);

        $this->as(self::PREPARER);
        $this->post('submit', [], ['id' => $id]);
        $this->assertSame('submitted', (string) Recover::batch($id)->status);

        $this->as(self::APPROVER);
        $this->post('approve', ['reason' => 'Incident confirmed with the relay.'], ['id' => $id]);
        $this->assertSame('approved', (string) Recover::batch($id)->status);

        $this->post('apply', ['confirm' => 'APPLY'], ['id' => $id]);

        $this->assertSame('applied', (string) Recover::batch($id)->status);
        $this->assertSame(103, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'),
            'the three dropped votes never reached the tally');
    }

    /** The two-person rule is enforced through the route, not only in the service. */
    public function test_the_preparer_cannot_approve_through_the_screen(): void
    {
        $id = $this->openBatch();
        $this->as(self::PREPARER);
        $this->post('submit', [], ['id' => $id]);

        $this->post('approve', [], ['id' => $id]);

        $this->assertSame('submitted', (string) Recover::batch($id)->status,
            'one person walked a batch from nothing to approved');
        $this->assertStringContainsString('cannot also approve', (string) ($_SESSION['flash_error'] ?? ''));
    }

    /** And the screen says so rather than hiding the button, which teaches nobody. */
    public function test_the_screen_explains_why_the_preparer_has_no_approve_button(): void
    {
        $id = $this->openBatch();
        $this->as(self::PREPARER);
        $this->post('submit', [], ['id' => $id]);

        $html = $this->get('show', ['id' => $id]);

        $this->assertStringContainsString('you cannot also approve it', $html);
        $this->assertStringNotContainsString('/approve"', $html,
            'the form was rendered for somebody who may not use it');
    }

    /** Writing to a live public tally is not a single unguarded press. */
    public function test_applying_needs_the_word_typed(): void
    {
        $id = $this->openBatch();
        $this->as(self::PREPARER);
        $this->post('submit', [], ['id' => $id]);
        $this->as(self::APPROVER);
        $this->post('approve', [], ['id' => $id]);

        $this->post('apply', ['confirm' => 'yes'], ['id' => $id]);

        $this->assertSame('approved', (string) Recover::batch($id)->status);
        $this->assertSame(100, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }

    /** Nobody but a superadmin gets in — this moves a public tally. */
    public function test_an_editor_is_refused_every_route(): void
    {
        $id = $this->openBatch();
        $this->as(self::APPROVER, 'editor');

        foreach ([['submit', []], ['approve', []], ['reject', ['reason' => 'no']],
                  ['apply', ['confirm' => 'APPLY']], ['void', ['reason' => 'no']]] as [$action, $body]) {
            [$code, $to] = $this->post($action, $body, ['id' => $id]);
            $this->assertSame(302, $code);
            $this->assertSame('/admin', $to, $action . ' was reachable by an editor');
        }
        $this->assertSame('draft', (string) Recover::batch($id)->status);
    }

    /**
     * The service's refusals are written to be read by a person. Passing them through
     * verbatim is the point — a generic "could not open" throws away the answer.
     */
    public function test_a_refusal_reaches_the_operator_in_the_services_own_words(): void
    {
        $this->as(self::PREPARER);
        [$f, $t] = $this->window();

        $this->post('open', ['cycle_id' => 1, 'window_from' => $f, 'window_to' => $t,
                             'incident_note' => '']);

        $this->assertStringContainsString('Describe the failure', (string) ($_SESSION['flash_error'] ?? ''),
            'the operator was told something went wrong and not what');
    }

    // ══ the way out: it cannot be quiet ══════════════════════════════════════

    /**
     * THE CONTROL THE DOCTRINE CALLS THE STRONGEST ONE.
     *
     * "Published, because the strongest control on a mechanism like this is not any
     * of the approvals — it is that using it cannot be quiet." The approvals bind the
     * people who happen to be in the room; publication binds everybody afterwards.
     */
    public function test_applied_votes_are_named_on_the_nominees_own_public_page(): void
    {
        $id = $this->openBatch(3);
        $ref = (string) Recover::batch($id)->reference;

        $this->as(self::PREPARER);
        $this->post('submit', [], ['id' => $id]);
        $this->as(self::APPROVER);
        $this->post('approve', [], ['id' => $id]);
        $this->post('apply', ['confirm' => 'APPLY'], ['id' => $id]);

        $html = $this->publicPage();

        $this->assertStringContainsString('added by us', $html,
            'three votes were put on a public tally and the page said nothing');
        $this->assertStringContainsString('Relay rejected every send', $html,
            'the disclosure does not say what went wrong');
        $this->assertStringContainsString($ref, $html,
            'the reference exists so a reader can name the batch and ask about it');
    }

    /** And a reversal takes them off the disclosure with them. */
    public function test_a_reversed_batch_stops_being_disclosed(): void
    {
        $id = $this->openBatch(3);
        $this->as(self::PREPARER);
        $this->post('submit', [], ['id' => $id]);
        $this->as(self::APPROVER);
        $this->post('approve', [], ['id' => $id]);
        $this->post('apply', ['confirm' => 'APPLY'], ['id' => $id]);
        $this->assertStringContainsString('added by us', $this->publicPage());

        $this->post('void', ['reason' => 'The relay log was misread.'], ['id' => $id]);

        $this->assertStringNotContainsString('added by us', $this->publicPage(),
            'votes that are no longer on the tally are still being claimed as support');
        $this->assertSame(100, (int) DB::table('gates_nominees')->where('id', 1)->value('vote_count'));
    }

    /**
     * And nothing is claimed on a platform that has never recovered anything — which
     * is every page on almost every deployment. A permanent "0 recovered votes" would
     * be noise on 100% of pages to be honest about 0% of them.
     */
    public function test_nothing_is_disclosed_where_nothing_was_recovered(): void
    {
        $html = $this->publicPage();

        $this->assertStringNotContainsString('added by us', $html);
        // And the page really did render, so the assertion above means something.
        $this->assertStringContainsString('Ada Obi', $html);
    }

    /** The nominee's public ballot page. */
    private function publicPage(): string
    {
        $ctrl = $this->container()->get(VoteController::class);
        // The canonical shape is /vote/{programme}/{id}-{name}: the id LEADS the segment,
        // and nominee() bounces anything else back to the programme page.
        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/vote/p1/1-ada-obi');
        $res  = $ctrl->nominee($req, (new ResponseFactory())->createResponse(),
                               ['program' => 'p1', 'slug' => '1-ada-obi']);

        $this->assertSame(200, $res->getStatusCode(), 'the public ballot page did not render');

        return (string) $res->getBody();
    }

    // ══ §18: the route in exists at all ══════════════════════════════════════

    /**
     * The structural half. A controller nothing routes to is the same fault written
     * one layer up, and this feature has already been inert once for exactly that.
     */
    public function test_every_step_has_a_route_and_a_way_to_find_it(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        foreach (['open', 'submit', 'approve', 'reject', 'apply', 'void'] as $step) {
            $this->assertStringContainsString("VoteRecoveryController::class.':{$step}'", $routes,
                $step . ' has a controller method and no route');
        }

        $nav = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Support/AdminNav.php');
        $this->assertStringContainsString("'/admin/vote-recovery'", $nav,
            'a page nobody can navigate to is a page that does not exist');
    }
}
