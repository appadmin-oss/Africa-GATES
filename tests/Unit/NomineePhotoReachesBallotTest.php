<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\NominationsController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\NomineeDetails;
use AfricaGates\Services\NomineePhoto;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * A PHOTO SENT FOR A NOMINEE SHOWS ON THEIR PAGE, AND BELONGS TO THEIR ACCOUNT.
 *
 * Owner, 5 Nov 2026: "photos uploaded are not reflecting on the nominee page". Every public page
 * reads gates_nominees.photo_path, and neither the nomination form's portrait nor a nominee's own
 * upload ever reached it. These drive the REAL writers — the review desk's Approve, and the
 * nominee's own page — and read the column the ballot reads.
 */
final class NomineePhotoReachesBallotTest extends TestCase
{
    private Twig $twig;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'tok';
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'stem', 'title' => 'STEM', 'icon_emoji' => '🔬']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'nominations']);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'innovation', 'title' => 'Innovation', 'description' => 'd', 'sort_order' => 1]);
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $this->twig = $b->build()->get(Twig::class);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token'], $_SESSION['flash_ok'], $_SESSION['flash_error'],
              $_SESSION['user_id'], $_SESSION['user_photo']);
        parent::tearDown();
    }

    private function nomination(int $id, string $name, ?string $photo): void
    {
        DB::table('gates_nominations')->insert(['id' => $id, 'cycle_id' => 1, 'nominee_name' => $name, 'country_code' => 'NG',
            'nominator_name' => 'Ben', 'nominator_email' => 'ben@x.io', 'reason' => 'Great work', 'status' => 'pending',
            'nominee_photo_path' => $photo, 'created_at' => '2026-07-01 10:00:00']);
    }

    private function approve(int $id): array
    {
        $res = (new NominationsController($this->twig, new AuditService()))->action(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/nominations/' . $id . '/approve')
                ->withHeader('X-Requested-With', 'fetch')->withParsedBody(['category_id' => 1, 'desk' => '1']),
            new Response(), ['id' => $id, 'action' => 'approve']);
        return (array) json_decode((string) $res->getBody(), true);
    }

    private function nominee(string $name): object
    {
        return DB::table('gates_nominees')->where('name', $name)->first();
    }

    public function test_approval_carries_the_nomination_photo_onto_the_ballot(): void
    {
        $this->nomination(10, 'Ada Obi', '/uploads/nominations/ada.webp');
        $this->assertTrue($this->approve(10)['ok']);
        $this->assertSame('/uploads/nominations/ada.webp', $this->nominee('Ada Obi')->photo_path,
            'the photo sent with the nomination is what the nominee page shows');

        // No photo sent: nothing is invented.
        $this->nomination(11, 'Bola Ade', null);
        $this->assertTrue($this->approve(11)['ok']);
        $this->assertNull($this->nominee('Bola Ade')->photo_path);
    }

    public function test_a_second_nomination_fills_an_existing_nominee_with_no_photo_and_never_replaces_one(): void
    {
        DB::table('gates_nominees')->insert(['category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved', 'vote_count' => 0]);
        $this->nomination(10, 'Ada Obi', '/uploads/nominations/ada.webp');
        $this->assertTrue($this->approve(10)['ok']);
        $this->assertSame('/uploads/nominations/ada.webp', $this->nominee('Ada Obi')->photo_path);

        $this->nomination(12, 'Ada Obi', '/uploads/nominations/other.webp');
        $this->assertTrue($this->approve(12)['ok']);
        $this->assertSame('/uploads/nominations/ada.webp', $this->nominee('Ada Obi')->photo_path, 'a photo already showing is kept');
    }

    public function test_the_nominees_own_photo_shows_first_and_is_their_accounts_photo(): void
    {
        $this->nomination(10, 'Ada Obi', '/uploads/nominations/ada.webp');
        $this->approve(10);
        $nid = (int) $this->nominee('Ada Obi')->id;
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Ada Obi', 'email' => 'ada@example.test', 'password_hash' => 'x',
            'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $nom = DB::table('gates_nominations')->where('id', 10)->first();
        $nom->nominee_email = 'ada@example.test';
        NomineeDetails::bind(substr($link = NomineeDetails::invite($nid, $nom), strrpos($link, '/') + 1), $uid);

        // Holding the page gives the account the photo showing — it had none.
        $this->assertSame('/uploads/nominations/ada.webp', DB::table('gates_users')->where('id', $uid)->value('photo_path'));

        // The nominee's own upload (the row addPhoto writes, then the sync it calls).
        DB::table('gates_nominee_files')->insert(['nominee_id' => $nid, 'kind' => 'photo', 'path' => '/uploads/nominee-photos/me.webp', 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['user_id'] = $uid;
        NomineePhoto::sync($nid);
        $this->assertSame('/uploads/nominee-photos/me.webp', $this->nominee('Ada Obi')->photo_path, 'their own photo beats the nominator’s');
        $this->assertSame('/uploads/nominee-photos/me.webp', DB::table('gates_users')->where('id', $uid)->value('photo_path'), 'and is their account photo');
        $this->assertSame('/uploads/nominee-photos/me.webp', $_SESSION['user_photo'], 'the avatar changes without signing in again');

        // Taken down: it stops showing at once, and the page falls back.
        $d = NomineeDetails::mine($nid, $uid);
        $this->assertTrue(NomineeDetails::remove($d, (int) DB::table('gates_nominee_files')->where('kind', 'photo')->value('id')));
        $this->assertSame('/uploads/nominations/ada.webp', $this->nominee('Ada Obi')->photo_path, 'a removed photo is not kept on the ballot');
    }

    public function test_a_nominee_upload_reaches_the_ballot_through_the_real_writer(): void
    {
        // addPhoto() must call sync, or the column the page reads never moves. Asserted on the
        // source because an upload needs a real image file; the behaviour is tested above.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/NomineeDetails.php');
        $this->assertMatchesRegularExpression('~function addPhoto\(.*?NomineePhoto::sync~s', $src);
        $this->assertMatchesRegularExpression('~function remove\(.*?NomineePhoto::sync~s', $src);
    }

    public function test_the_header_shows_the_account_photo(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['user_photo'] = '/uploads/nominee-photos/me.webp';
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $twig = $b->build()->get(Twig::class);
        $this->assertSame('/uploads/nominee-photos/me.webp', $twig->getEnvironment()->getGlobals()['member_photo']);
        $hdr = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/partials/site-header.twig');
        $this->assertStringContainsString('src="{{ member_photo }}"', $hdr);
    }
}
