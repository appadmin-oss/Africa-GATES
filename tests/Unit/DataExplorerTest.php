<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\DataController;
use AfricaGates\Admin\Support\DataRegistry;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/** The generic data explorer: registry resilience, filtered CSV export, secret-column hiding, 404s. */
class DataExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_role'] = 'superadmin';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_role']);
        parent::tearDown();
    }

    private function ctrl(): DataController
    {
        return new DataController(Twig::create(__DIR__ . '/../../templates'));
    }

    public function test_registry_only_exposes_existing_tables(): void
    {
        $sets = DataRegistry::available();
        $this->assertArrayHasKey('users', $sets);
        $this->assertArrayHasKey('votes', $sets);
        // every exposed table really exists
        foreach ($sets as $d) {
            $this->assertTrue(DB::schema()->hasTable($d['table']));
        }
    }

    public function test_export_filters_and_hides_secrets(): void
    {
        DB::table('gates_users')->insert(['name' => 'Ada Obi', 'email' => 'ada@x.io', 'password_hash' => 'SECRETHASH', 'points' => 10, 'status' => 'active', 'created_at' => '2026-06-01 00:00:00']);
        DB::table('gates_users')->insert(['name' => 'Bob Roy', 'email' => 'bob@x.io', 'password_hash' => 'SECRETHASH', 'points' => 5, 'status' => 'active', 'created_at' => '2026-06-02 00:00:00']);

        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/users/export')->withQueryParams(['q' => 'ada']);
        $res = $this->ctrl()->export($req, new Response(), ['dataset' => 'users']);

        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));
        $csv = (string) $res->getBody();
        $this->assertStringContainsString('Ada Obi', $csv);
        $this->assertStringNotContainsString('Bob Roy', $csv);          // search filter applied
        $this->assertStringNotContainsString('password_hash', $csv);    // secret column hidden from header
        $this->assertStringNotContainsString('SECRETHASH', $csv);       // and from data
    }

    public function test_unknown_dataset_is_404(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/nope/export');
        $this->expectException(\Slim\Exception\HttpNotFoundException::class);
        $this->ctrl()->export($req, new Response(), ['dataset' => 'nope']);
    }

    public function test_role_scoping(): void
    {
        // see-all tier gets everything
        $this->assertArrayHasKey('donations', DataRegistry::availableForRole('viewer'));
        $this->assertArrayHasKey('users', DataRegistry::availableForRole('admin'));
        // moderator: community/integrity yes, financial/PII no
        $this->assertTrue(DataRegistry::canRole('comments', 'moderator'));
        $this->assertTrue(DataRegistry::canRole('moderation-log', 'moderator'));
        $this->assertFalse(DataRegistry::canRole('donations', 'moderator'));
        $this->assertFalse(DataRegistry::canRole('users', 'moderator'));
        // editor: analytics yes, financial no
        $this->assertTrue(DataRegistry::canRole('funnel', 'editor'));
        $this->assertFalse(DataRegistry::canRole('orders', 'editor'));
    }

    public function test_dataset_export_blocked_for_role_without_access(): void
    {
        $_SESSION['admin_role'] = 'moderator';
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/donations/export');
        $this->expectException(\Slim\Exception\HttpNotFoundException::class);
        $this->ctrl()->export($req, new Response(), ['dataset' => 'donations']);
    }

    public function test_export_honours_date_range_filter(): void
    {
        $today = date('Y-m-d H:i:s');
        $old   = date('Y-m-d H:i:s', strtotime('-60 days'));
        DB::table('gates_users')->insert(['name' => 'Recent Ruth', 'email' => 'ruth@x.io', 'points' => 0, 'status' => 'active', 'created_at' => $today]);
        DB::table('gates_users')->insert(['name' => 'Ancient Amos', 'email' => 'amos@x.io', 'points' => 0, 'status' => 'active', 'created_at' => $old]);

        // range=30d must include the recent row and exclude the 60-day-old one.
        $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://x/admin/data/users/export')->withQueryParams(['range' => '30d']);
        $csv = (string) $this->ctrl()->export($req, new Response(), ['dataset' => 'users'])->getBody();
        $this->assertStringContainsString('Recent Ruth', $csv);
        $this->assertStringNotContainsString('Ancient Amos', $csv);
    }

    /**
     * §17 · THE OPT-OUT LIST, WHICH HAD NO SCREEN AT ALL.
     *
     * `gates_sms_optout.phone_masked` exists so that a support desk can answer "am I
     * still getting these". Its own note says why it holds only the last digits: sixty-
     * four hex characters are useless to a person on a phone call, and the alternative is
     * keeping the number.
     *
     * There was no screen. The column was written on every opt-out and read by nothing,
     * so the one person it was designed for could not be answered — while the platform
     * went on texting somebody who had asked it to stop. See the STOP fix that landed
     * beside this: the recording was broken too, for a different reason.
     */
    public function test_the_sms_optout_list_is_reachable_and_searchable(): void
    {
        $this->assertArrayHasKey('sms-optout', DataRegistry::SETS,
            'somebody asked to be left alone and no screen can say whether it worked');

        $set = DataRegistry::SETS['sms-optout'];
        $this->assertSame('gates_sms_optout', $set['table']);

        // A support desk searches by the digits the caller reads out.
        $this->assertContains('phone_masked', $set['search']);
        $this->assertContains('phone_masked', array_column($set['cols'], 0));

        // And by how they came to be on it — a STOP reply is a different conversation
        // from an operator adding somebody by hand.
        $this->assertContains('source', array_column($set['cols'], 0));
    }

    /**
     * And the hash never travels, which is the other half of why the mask exists.
     *
     * `phone_hash` is a sha256 of the number. It is the column that could link a
     * suppression back to a person, and it must not appear on a screen or in an export.
     * Caught by the `_hash` suffix rule rather than by being named — asserted here
     * because a rule nobody tests is a rule until somebody renames a column.
     */
    public function test_the_optout_hash_is_never_rendered(): void
    {
        $this->assertTrue(DataRegistry::isHidden('phone_hash'),
            'the hash that identifies a suppressed number is renderable');
        $this->assertNotContains('phone_hash', array_column(DataRegistry::SETS['sms-optout']['cols'], 0));
    }
}
