<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ProgrammesController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * "Improve the programme, events, awards and nominee management features" (owner, 11 Nov 2026).
 *
 * Two faults in category management, both silent:
 *  · Saving a category wrote `cycle_id` = the programme's LIVE cycle on an update too, so
 *    pressing Update on a category of another edition (the cycle screen opens any edition
 *    with `?cycle=`) moved it, with its nominees and votes, into the edition being voted in.
 *  · Delete was a hard DELETE, and votes, nominees and judge criteria cascade from a
 *    category — one press erased the ballot ledger. A used record is retired, never deleted.
 */
final class CategoryEditionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        DB::table('gates_award_programmes')->insert(['id' => 41, 'slug' => 'ce-prog', 'title' => 'Teachers Prize', 'is_active' => 1, 'sort_order' => 1]);
        // 2026 is live (voting); 2027 is the next edition being prepared.
        DB::table('gates_award_cycles')->insert(['id' => 4126, 'programme_id' => 41, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_cycles')->insert(['id' => 4127, 'programme_id' => 41, 'year' => 2027, 'status' => 'upcoming']);
        DB::table('gates_award_categories')->insert(['id' => 41271, 'cycle_id' => 4127, 'slug' => 'science', 'title' => 'Science teacher', 'sort_order' => 1]);
        DB::table('gates_award_categories')->insert(['id' => 41261, 'cycle_id' => 4126, 'slug' => 'maths', 'title' => 'Maths teacher', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function post(string $method, array $args, array $body): Response
    {
        $c = new ProgrammesController(\Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'), new AuditService());
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin')->withParsedBody($body + ['_token' => 't']);
        return $c->$method($req, new Response(), $args);
    }

    public function test_updating_a_category_of_another_edition_leaves_it_in_that_edition(): void
    {
        $res = $this->post('categorySave', ['id' => 41], ['id' => 41271, 'title' => 'Science teacher of the year', 'slug' => 'science', 'sort_order' => 2]);
        $row = DB::table('gates_award_categories')->where('id', 41271)->first();
        $this->assertSame(4127, (int) $row->cycle_id, 'an update must never move a category to the live edition');
        $this->assertSame('Science teacher of the year', $row->title);
        $this->assertStringContainsString('cycle=4127', $res->getHeaderLine('Location'), 'back to the edition that was on screen');
    }

    public function test_a_new_category_goes_into_the_edition_on_screen_and_only_this_programmes(): void
    {
        $this->post('categorySave', ['id' => 41], ['cycle_id' => 4127, 'title' => 'Head teacher', 'slug' => '']);
        $this->assertSame(4127, (int) DB::table('gates_award_categories')->where('slug', 'head-teacher')->value('cycle_id'),
            'added where the operator was looking, with a slug derived from the title');

        DB::table('gates_award_programmes')->insert(['id' => 42, 'slug' => 'other', 'title' => 'Other', 'is_active' => 1, 'sort_order' => 2]);
        DB::table('gates_award_cycles')->insert(['id' => 4226, 'programme_id' => 42, 'year' => 2026, 'status' => 'voting']);
        $this->post('categorySave', ['id' => 41], ['cycle_id' => 4226, 'title' => 'Smuggled', 'slug' => 'smuggled']);
        $this->assertNotSame(4226, (int) DB::table('gates_award_categories')->where('slug', 'smuggled')->value('cycle_id'),
            'a cycle id from another programme is not honoured');

        $this->post('categorySave', ['id' => 42], ['id' => 41271, 'title' => 'Hijack', 'slug' => 'science']);
        $this->assertSame('Science teacher', DB::table('gates_award_categories')->where('id', 41271)->value('title'),
            'a category is only edited through its own programme');
    }

    public function test_a_category_anybody_stands_in_is_never_deleted(): void
    {
        DB::table('gates_nominees')->insert(['id' => 4101, 'category_id' => 41261, 'name' => 'Ada Obi', 'status' => 'approved']);
        $this->post('categoryDelete', ['catId' => 41261], []);
        $this->assertTrue(DB::table('gates_award_categories')->where('id', 41261)->exists());
        $this->assertTrue(DB::table('gates_nominees')->where('id', 4101)->exists());
        $this->assertStringContainsString('1 nominee stands in it', (string) ($_SESSION['flash_error'] ?? ''));

        // Nothing points at the 2027 category yet, so it may go.
        $res = $this->post('categoryDelete', ['catId' => 41271], []);
        $this->assertFalse(DB::table('gates_award_categories')->where('id', 41271)->exists());
        $this->assertStringContainsString('/admin/programmes/41/cycle?cycle=4127', $res->getHeaderLine('Location'));
    }

    public function test_votes_alone_keep_a_category(): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => 999, 'category_id' => 41271, 'voter_email_hash' => str_repeat('a', 64)]);
        $this->assertStringContainsString('1 vote was cast', (string) ProgrammesController::categoryInUse(41271));
    }
}
