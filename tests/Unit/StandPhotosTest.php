<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\{StandApplication, StandPhotos};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * Photographs of what a vendor sells.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE ARE GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two things, and they pull in opposite directions.
 *
 * The photographs have to COUNT — they are the only thing on a stand application that
 * shows what every other field merely claims, and an application is not complete without
 * three of them. Completeness is the §5.4 tiebreak, so this is a ranking mechanism and
 * the minimum has to hold at the endpoint rather than in the form.
 *
 * And they must not BLOCK. Making them an eligibility rule would refuse applications from
 * exactly the people this platform is for: somebody photographing their goods on a
 * borrowed phone the evening before the deadline. So the tests below check both — that
 * the minimum moves completeness, and that it never reaches the eligibility gate.
 */
final class StandPhotosTest extends TestCase
{
    private int $appId = 0;
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/ag-standphotos-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/uploads', 0775, true);

        DB::table('gates_site_events')->insert([
            'id' => 1, 'slug' => 'market', 'title' => 'Market', 'event_date' => '2026-12-14',
        ]);
        DB::table('gates_stand_calls')->insert([
            'id' => 1, 'event_id' => 1, 'status' => 'open',
            'closes_at' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);
        DB::table('gates_stand_types')->insert([
            'id' => 1, 'event_id' => 1, 'slug' => 't', 'name' => 'Table', 'category' => 'books',
            'price_naira' => 20000, 'quota' => 5,
        ]);
        $this->appId = (int) DB::table('gates_stand_applications')->insertGetId([
            'call_id' => 1, 'event_id' => 1, 'org_id' => 7, 'stand_type_id' => 1,
            'decision' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        // The service writes real files; leaving them behind fills the runner's temp dir
        // one test run at a time.
        foreach (glob($this->root . '/uploads/*/*/*/*') ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    private function uploads(): UploadService
    {
        return new UploadService($this->root);
    }

    /** A real JPEG of the given size, as an uploaded file. */
    private function photo(int $w = 900, int $h = 700): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 90));
        $tmp = tempnam(sys_get_temp_dir(), 'agphoto');
        imagejpeg($im, $tmp, 85);
        imagedestroy($im);

        return new UploadedFile($tmp, 'goods.jpg', 'image/jpeg', filesize($tmp) ?: null, UPLOAD_ERR_OK);
    }

    private function add(int $w = 900, int $h = 700): array
    {
        return StandPhotos::add($this->appId, 7, $this->photo($w, $h), $this->uploads());
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_a_photograph_is_stored_and_the_first_one_is_the_cover(): void
    {
        $this->assertTrue($this->add()['ok']);
        $this->assertTrue($this->add()['ok']);

        $all = StandPhotos::forApplication($this->appId);
        $this->assertCount(2, $all);
        $this->assertTrue($all[0]['cover']);
        $this->assertFalse($all[1]['cover']);
        $this->assertStringContainsString('/uploads/' . StandPhotos::BUCKET . '/', $all[0]['path']);
    }

    /**
     * The maximum holds at the service, not in the counter on the form.
     *
     * The endpoint is reachable without the form, and what a seventh photograph would buy
     * is nothing — the scorers read six.
     */
    public function test_a_seventh_photograph_is_refused(): void
    {
        for ($i = 0; $i < StandPhotos::MAX; $i++) $this->assertTrue($this->add()['ok']);

        $r = $this->add();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('remove one', $r['message']);
        $this->assertSame(StandPhotos::MAX, StandPhotos::count($this->appId));
    }

    /**
     * A photograph too small to read is refused before anything is written.
     *
     * A 200px thumbnail tells a scorer nothing, and accepting it makes the application
     * read as though the vendor could not be bothered — which is a judgement they never
     * had the chance to answer.
     */
    public function test_a_tiny_photograph_is_refused(): void
    {
        $r = $this->add(240, 180);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too small', strtolower($r['message']));
        $this->assertSame(0, StandPhotos::count($this->appId));
    }

    // ── completeness, and the line it does not cross ─────────────────────────

    /** Three photographs are what make an application complete. */
    public function test_the_third_photograph_completes_the_application(): void
    {
        $this->add();
        $this->add();
        $this->assertNull(DB::table('gates_stand_applications')->where('id', $this->appId)->value('completed_at'),
            'an application with two photographs is not complete');

        $this->add();
        $this->assertNotNull(DB::table('gates_stand_applications')->where('id', $this->appId)->value('completed_at'));
    }

    /** And they are named as missing in the same voice as a missing certificate. */
    public function test_missing_photographs_are_reported_like_a_missing_document(): void
    {
        $this->add();

        $missing = StandApplication::missingForCompleteness($this->appId);
        $this->assertArrayHasKey('stand_photos', $missing);
        $this->assertStringContainsString('1 of 3', $missing['stand_photos']);
    }

    /**
     * THE LINE. Photographs never reach the eligibility gate.
     *
     * Eligibility is a rule with no judgement in it, and a rule refuses the application
     * outright. If photographs were on that list, a vendor with every certificate in
     * order and no camera would be told they are not eligible to trade.
     */
    public function test_photographs_are_not_an_eligibility_rule(): void
    {
        $this->assertArrayNotHasKey('stand_photos', StandApplication::missingDocuments(7),
            'photographs reached the eligibility gate, where a miss is a refusal');

        $r = StandApplication::checkEligibility($this->appId);
        $this->assertArrayNotHasKey('stand_photos', $r['missing']);
    }

    /**
     * Deleting one does not move the clock.
     *
     * §5.4 ranks on the moment an application BECAME complete. If a deletion reset it, a
     * vendor could lose their place by tidying up — and one who deleted and re-added
     * could gain one.
     */
    public function test_removing_a_photograph_does_not_move_the_completeness_clock(): void
    {
        $this->add(); $this->add(); $this->add();
        $stamped = DB::table('gates_stand_applications')->where('id', $this->appId)->value('completed_at');
        $this->assertNotNull($stamped);

        $first = StandPhotos::forApplication($this->appId)[0]['id'];
        $this->assertTrue(StandPhotos::remove($this->appId, $first)['ok']);

        $this->assertSame($stamped,
            DB::table('gates_stand_applications')->where('id', $this->appId)->value('completed_at'));
    }

    /** Removing the cover promotes the next one rather than leaving none. */
    public function test_removing_the_cover_promotes_the_next_photograph(): void
    {
        $this->add(); $this->add();
        $ids = array_column(StandPhotos::forApplication($this->appId), 'id');

        StandPhotos::remove($this->appId, $ids[0]);

        $left = StandPhotos::forApplication($this->appId);
        $this->assertCount(1, $left);
        $this->assertSame($ids[1], $left[0]['id']);
        $this->assertTrue($left[0]['cover'], 'an application with photographs and no cover');
    }

    public function test_reordering_puts_the_named_photograph_first(): void
    {
        $this->add(); $this->add(); $this->add();
        $ids = array_column(StandPhotos::forApplication($this->appId), 'id');

        $this->assertTrue(StandPhotos::reorder($this->appId, [$ids[2], $ids[0]]));

        $now = array_column(StandPhotos::forApplication($this->appId), 'id');
        $this->assertSame([$ids[2], $ids[0], $ids[1]], $now,
            'a photograph left out of the order should keep its place at the end');
    }

    // ── the scope check, which is the whole controller ──────────────────────

    private function ctrl(): \AfricaGates\Controllers\StandPhotoController
    {
        return new \AfricaGates\Controllers\StandPhotoController($this->uploads());
    }

    private function post(array $files = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/org/stands/' . $this->appId . '/photos')
            ->withUploadedFiles($files)
            ->withParsedBody([]);
    }

    /** @return array{0:int, 1:array<string,mixed>} status and decoded body */
    private function call(callable $fn): array
    {
        $res = $fn();
        return [$res->getStatusCode(), (array) json_decode((string) $res->getBody(), true)];
    }

    /**
     * An application id is a small integer in a URL, and the scope check is the only thing
     * between a vendor and every other vendor's photographs.
     *
     * 404 rather than 403, deliberately: a refusal that distinguishes "no such
     * application" from "not yours" tells a stranger which ids exist.
     */
    public function test_another_vendors_application_is_not_reachable(): void
    {
        $_SESSION['org_id'] = 999;   // signed in, but not as the owner of this application

        [$status, $body] = $this->call(fn () => $this->ctrl()->add(
            $this->post(['photo' => $this->photo()]), new \Slim\Psr7\Response(),
            ['application' => $this->appId]));

        $this->assertSame(404, $status);
        $this->assertFalse($body['ok']);
        $this->assertSame(0, StandPhotos::count($this->appId));
    }

    public function test_a_signed_out_visitor_is_refused(): void
    {
        unset($_SESSION['org_id']);

        [$status, $body] = $this->call(fn () => $this->ctrl()->add(
            $this->post(['photo' => $this->photo()]), new \Slim\Psr7\Response(),
            ['application' => $this->appId]));

        $this->assertSame(401, $status);
        $this->assertSame('SIGN_IN', $body['code']);
    }

    /**
     * Once the call has closed the photographs are part of what was judged.
     *
     * Changing them afterwards is the same act as editing an answer once the marking has
     * started, and the refusal says so rather than reading as a fault.
     */
    public function test_photographs_cannot_be_changed_after_the_call_closes(): void
    {
        $_SESSION['org_id'] = 7;
        $this->add();
        DB::table('gates_stand_calls')->where('id', 1)->update(['status' => 'closed']);

        [$status, $body] = $this->call(fn () => $this->ctrl()->add(
            $this->post(['photo' => $this->photo()]), new \Slim\Psr7\Response(),
            ['application' => $this->appId]));

        $this->assertSame(409, $status);
        $this->assertSame('CLOSED', $body['code']);
        $this->assertSame(1, StandPhotos::count($this->appId));

        $id = StandPhotos::forApplication($this->appId)[0]['id'];
        [$delStatus] = $this->call(fn () => $this->ctrl()->remove(
            $this->post(), new \Slim\Psr7\Response(),
            ['application' => $this->appId, 'photo' => $id]));
        $this->assertSame(409, $delStatus, 'a closed call still allowed a deletion');
    }

    /** And the owner can add through the endpoint, so the guard is not simply refusing. */
    public function test_the_owner_can_add_through_the_endpoint(): void
    {
        $_SESSION['org_id'] = 7;

        [$status, $body] = $this->call(fn () => $this->ctrl()->add(
            $this->post(['photo' => $this->photo()]), new \Slim\Psr7\Response(),
            ['application' => $this->appId]));

        $this->assertSame(200, $status);
        $this->assertTrue($body['ok']);
        $this->assertSame(1, $body['count']);
    }

    // ── who may see them ────────────────────────────────────────────────────

    /**
     * Nothing is public while the call is running.
     *
     * The form says so in as many words, and this is that sentence as a function. It is
     * checked before a path is handed out rather than inside a template, because a
     * template `if` protects a page and the thing that needs protecting is the file.
     */
    public function test_photographs_are_not_public_until_the_offer_is_accepted(): void
    {
        $this->add();

        $this->assertFalse(StandPhotos::arePublic($this->appId));
        $this->assertNull(StandPhotos::publicCover($this->appId));

        foreach (['offered', 'waitlisted', 'rejected'] as $decision) {
            DB::table('gates_stand_applications')->where('id', $this->appId)->update(['decision' => $decision]);
            $this->assertFalse(StandPhotos::arePublic($this->appId),
                "a '{$decision}' application published its photographs");
        }

        DB::table('gates_stand_applications')->where('id', $this->appId)->update(['decision' => 'accepted']);
        $this->assertTrue(StandPhotos::arePublic($this->appId));
        $this->assertNotNull(StandPhotos::publicCover($this->appId));
    }
}
