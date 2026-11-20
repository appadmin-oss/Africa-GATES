<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\NomineesController;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * "Improve the programme, events, awards and nominee management features" (owner, 11 Nov 2026).
 *
 * What every award platform's entry list gives an operator and this one did not:
 *  · a nominee could not be EDITED at all once approved — a misspelt name stayed on the
 *    ballot and every share card for the edition;
 *  · no category filter, no ordering but "most votes", no export, no bulk action.
 * And what each of those must never do: move a nominee's votes out of their edition, give a
 * voter two votes in one category, or uncrown a winner as a side effect of "approve all".
 */
final class NomineeManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin'; $_SESSION['csrf_token'] = 'test-token';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        DB::table('gates_award_programmes')->insert(['id' => 51, 'slug' => 'nm-prog', 'title' => 'Youth Prize', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 5126, 'programme_id' => 51, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_cycles')->insert(['id' => 5125, 'programme_id' => 51, 'year' => 2025, 'status' => 'archived']);
        DB::table('gates_award_categories')->insert(['id' => 511, 'cycle_id' => 5126, 'slug' => 'arts', 'title' => 'Arts', 'sort_order' => 1]);
        DB::table('gates_award_categories')->insert(['id' => 512, 'cycle_id' => 5126, 'slug' => 'sport', 'title' => 'Sport', 'sort_order' => 2]);
        DB::table('gates_award_categories')->insert(['id' => 513, 'cycle_id' => 5125, 'slug' => 'arts', 'title' => 'Arts 2025', 'sort_order' => 1]);
        DB::table('gates_nominees')->insert(['id' => 5101, 'category_id' => 511, 'name' => 'Chiamka Eze', 'status' => 'pending', 'vote_count' => 2]);
        DB::table('gates_nominees')->insert(['id' => 5102, 'category_id' => 511, 'name' => 'Bola Ade', 'status' => 'winner', 'vote_count' => 9]);
        DB::table('gates_nominees')->insert(['id' => 5103, 'category_id' => 512, 'name' => '=HYPERLINK("x")', 'status' => 'pending', 'vote_count' => 0]);
        foreach (['a', 'b'] as $v) {
            DB::table('gates_votes')->insert(['nominee_id' => 5101, 'category_id' => 511, 'voter_email_hash' => str_repeat($v, 64)]);
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function ctrl(): NomineesController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(NomineesController::class);
    }

    private function get(string $method, array $query = [], array $args = []): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/nominees')->withQueryParams($query);
        return $this->ctrl()->$method($req, (new ResponseFactory())->createResponse(), $args);
    }

    private function post(string $method, array $body, array $args = []): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/nominees')->withParsedBody($body);
        return $this->ctrl()->$method($req, new Response(), $args);
    }

    public function test_a_nominee_can_be_corrected_and_the_change_is_on_their_history(): void
    {
        $this->post('update', ['name' => 'Chiamaka  Eze', 'tagline' => 'Painter', 'country_code' => 'ng', 'category_id' => 511], ['id' => 5101]);
        $n = DB::table('gates_nominees')->where('id', 5101)->first();
        $this->assertSame('Chiamaka Eze', $n->name, 'spaces collapsed');
        $this->assertSame('NG', $n->country_code);
        $log = DB::table('gates_audit_log')->where('action', 'nominee.edit')->where('target_id', 5101)->first();
        $this->assertNotNull($log);
        $meta = json_decode((string) $log->meta, true);
        $this->assertSame('Chiamka Eze', $meta['from']['name'], 'the old value is kept, so a rename can be traced');

        $html = (string) $this->get('edit', [], ['id' => 5101])->getBody();
        $this->assertStringContainsString('Details changed', $html);
        $this->assertStringContainsString('value="Chiamaka Eze"', $html);
    }

    public function test_a_move_stays_in_its_edition_and_carries_its_votes_only_when_confirmed(): void
    {
        $this->post('update', ['name' => 'Chiamka Eze', 'category_id' => 513], ['id' => 5101]);
        $this->assertSame(511, (int) DB::table('gates_nominees')->where('id', 5101)->value('category_id'), 'never into another edition');

        $this->post('update', ['name' => 'Chiamka Eze', 'category_id' => 512], ['id' => 5101]);
        $this->assertSame(511, (int) DB::table('gates_nominees')->where('id', 5101)->value('category_id'), 'votes move only when ticked');
        $this->assertStringContainsString('Tick the box', (string) $_SESSION['flash_error']);

        $this->post('update', ['name' => 'Chiamka Eze', 'category_id' => 512, 'move_with_votes' => 1], ['id' => 5101]);
        $this->assertSame(512, (int) DB::table('gates_nominees')->where('id', 5101)->value('category_id'));
        $this->assertSame(2, DB::table('gates_votes')->where('nominee_id', 5101)->where('category_id', 512)->count(), 'the ballots followed');
    }

    public function test_a_move_that_would_give_a_voter_two_votes_in_one_category_is_refused(): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => 5103, 'category_id' => 512, 'voter_email_hash' => str_repeat('a', 64)]);
        $this->post('update', ['name' => 'Chiamka Eze', 'category_id' => 512, 'move_with_votes' => 1], ['id' => 5101]);
        $this->assertSame(511, (int) DB::table('gates_nominees')->where('id', 5101)->value('category_id'));
        $this->assertStringContainsString('one vote per category', (string) $_SESSION['flash_error']);
    }

    public function test_only_an_admin_moves_a_nominee(): void
    {
        $_SESSION['admin_role'] = 'moderator';
        $this->post('update', ['name' => 'Chiamka Eze', 'category_id' => 512, 'move_with_votes' => 1], ['id' => 5101]);
        $this->assertSame(511, (int) DB::table('gates_nominees')->where('id', 5101)->value('category_id'));
    }

    public function test_bulk_approve_never_uncrowns_a_winner(): void
    {
        $this->post('bulk', ['bulk_action' => 'approve', 'ids' => [5101, 5102, 5103]]);
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 5101)->value('status'));
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 5103)->value('status'));
        $this->assertSame('winner', DB::table('gates_nominees')->where('id', 5102)->value('status'));
        $this->assertStringContainsString('2 nominees approved', (string) $_SESSION['flash_ok']);

        $this->post('bulk', ['bulk_action' => 'winner', 'ids' => [5101]]);
        $this->assertSame('approved', DB::table('gates_nominees')->where('id', 5101)->value('status'), 'crowning is not a bulk action');
    }

    public function test_the_list_filters_by_category_and_orders_by_name_and_counts_each_state(): void
    {
        $html = (string) $this->get('index', ['category' => 511, 'sort' => 'name'])->getBody();
        $this->assertStringContainsString('Bola Ade', $html);
        $this->assertStringNotContainsString('HYPERLINK', $html, 'another category is filtered out');
        $this->assertLessThan(strpos($html, 'Chiamka Eze'), strpos($html, 'Bola Ade'), 'A to Z');
        $this->assertMatchesRegularExpression('~Pending <span class="nm-states__n">1</span>~', $html);
        $this->assertStringContainsString('/admin/nominees/export.csv?category=511&amp;sort=name', $html);

        $junk = (string) $this->get('index', ['status' => 'rejected'])->getBody();
        $this->assertStringContainsString('3 nominees', $junk, 'a status the column cannot hold is ignored, not a false zero');
    }

    public function test_the_export_is_the_filtered_list_and_safe_to_open(): void
    {
        $csv = (string) $this->get('export', ['cycle' => 5126])->getBody();
        $this->assertStringContainsString('Chiamka Eze', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'a formula typed by the public is defused');
        $this->assertStringNotContainsString('Bola Ade', (string) $this->get('export', ['status' => 'pending'])->getBody());
    }
}
