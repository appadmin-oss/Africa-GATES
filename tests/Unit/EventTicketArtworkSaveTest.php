<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\EventsController;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Services\CacheService;
use AfricaGates\Services\TicketArtwork;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * Saving the ticket's artwork from the event form.
 *
 * The unit-level geometry is proved in TicketArtworkTest; what is at stake here is the part
 * that decides between two ways of putting a picture on a ticket — an uploaded file with a
 * frame, or a typed address — and the rule that only one of them can be in charge at a time.
 * Getting that wrong does not throw; it quietly resurrects a picture somebody deleted, or
 * ignores an address they typed, and the organiser has no way to tell which happened.
 */
class EventTicketArtworkSaveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'admin';
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
        // Its own public root, so nothing is written into the repository's uploads tree.
        $this->root = sys_get_temp_dir() . '/ag-evart-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/uploads', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->sweep($this->root);
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function sweep(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $p) { is_dir($p) ? $this->sweep($p) : @unlink($p); }
        foreach (glob($dir . '/.*') ?: [] as $p) { if (is_file($p)) @unlink($p); }
        @rmdir($dir);
    }

    private function controller(): EventsController
    {
        return new EventsController(
            \Slim\Views\Twig::create(dirname(__DIR__, 2) . '/templates'),
            new AuditService(),
            new CacheService(),
            null,
            new UploadService($this->root),
        );
    }

    private function event(array $extra = []): int
    {
        return (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Founders Night', 'slug' => 'founders-' . bin2hex(random_bytes(3)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+40 days')),
            'status' => 'published',
        ] + $extra);
    }

    /** The fields the event form always posts, so a save is a save and not a validation error. */
    private function base(int $id): array
    {
        $row = (array) DB::table('gates_site_events')->where('id', $id)->first();
        return [
            'title' => $row['title'], 'slug' => $row['slug'],
            'event_date' => $row['event_date'], 'end_date' => '', 'status' => 'published',
            // Always on the form, so always posted. Left out, the save blanks it.
            'cover_image' => (string) ($row['cover_image'] ?? ''),
            'ticket_design_posted' => '1', 'ticket_accent' => '#2A6FDB',
            'ticket_theme' => 'dark', 'ticket_rows' => ['price'],
        ];
    }

    private function save(int $id, array $body, array $files = []): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/events/' . $id)
            ->withParsedBody($body)
            ->withUploadedFiles($files);
        $this->controller()->save($req, new Response(), ['id' => (string) $id]);
    }

    private function upload(int $w = 600, int $h = 900): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agart_') . '.png';
        $im  = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, (int) ($h / 2) - 1, imagecolorallocate($im, 200, 30, 30));
        imagefilledrectangle($im, 0, (int) ($h / 2), $w - 1, $h - 1, imagecolorallocate($im, 30, 30, 200));
        imagepng($im, $tmp);
        imagedestroy($im);
        // sapi=false → moveTo() uses rename(), which is what works outside a real upload.
        return new UploadedFile($tmp, 'poster.png', 'image/png', filesize($tmp) ?: null, UPLOAD_ERR_OK, false);
    }

    /** @return array{ticket_image:?string,ticket_image_src:?string,ticket_image_edit:?string} */
    private function columns(int $id): array
    {
        $r = (array) DB::table('gates_site_events')->where('id', $id)->first();
        return [
            'ticket_image'      => $r['ticket_image'] ?? null,
            'ticket_image_src'  => $r['ticket_image_src'] ?? null,
            'ticket_image_edit' => $r['ticket_image_edit'] ?? null,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────

    public function test_an_uploaded_picture_is_kept_whole_and_the_ticket_gets_the_baked_crop(): void
    {
        $id = $this->event();
        $this->save($id, $this->base($id) + [
            'ticket_image_edit' => json_encode(['crop' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => .4]]),
        ], ['ticket_artwork' => $this->upload()]);

        $c = $this->columns($id);
        $this->assertNotSame($c['ticket_image'], $c['ticket_image_src'], 'the ticket must render the crop, not the original');
        $this->assertFileExists($this->root . $c['ticket_image_src'], 'the original is kept so a later re-frame is not a re-crop');
        $this->assertFileExists($this->root . $c['ticket_image']);

        // The baked file is the ticket's shape, whatever shape was uploaded.
        [$w, $h] = getimagesize($this->root . $c['ticket_image']);
        $this->assertSame([TicketArtwork::W, TicketArtwork::H], [$w, $h]);

        // …and the original is still the portrait poster it was.
        [$sw, $sh] = getimagesize($this->root . $c['ticket_image_src']);
        $this->assertGreaterThan($sw, $sh, 'the source keeps its own proportions');

        $this->assertSame(0.4, TicketArtwork::recipe($c['ticket_image_edit'])['crop']['h']);
    }

    public function test_changing_only_the_frame_re_cuts_from_the_original_with_no_second_upload(): void
    {
        $id = $this->event();
        $this->save($id, $this->base($id) + ['ticket_image_edit' => TicketArtwork::pack([])],
            ['ticket_artwork' => $this->upload()]);
        $first = $this->columns($id);

        // The top of the poster is red and the bottom is blue. Re-frame onto the bottom half
        // WITHOUT uploading anything, and the baked file must change colour.
        $this->save($id, $this->base($id) + [
            'ticket_image_edit' => json_encode(['crop' => ['x' => 0, 'y' => .62, 'w' => 1, 'h' => .3]]),
        ]);
        $second = $this->columns($id);

        $this->assertSame($first['ticket_image_src'], $second['ticket_image_src'], 'the original does not move');
        $this->assertNotSame($first['ticket_image'], $second['ticket_image'], 'a new frame is a new file');

        $im = imagecreatefromjpeg($this->root . $second['ticket_image']);
        $c  = imagecolorat($im, (int) (imagesx($im) / 2), (int) (imagesy($im) / 2));
        imagedestroy($im);
        $this->assertGreaterThan(($c >> 16) & 255, $c & 255, 'the lower frame is the blue half of the poster');
    }

    public function test_typing_an_address_over_existing_artwork_lets_the_artwork_go(): void
    {
        $id = $this->event();
        $this->save($id, $this->base($id) + ['ticket_image_edit' => TicketArtwork::pack([])],
            ['ticket_artwork' => $this->upload()]);
        $this->assertNotNull($this->columns($id)['ticket_image_src']);

        $this->save($id, $this->base($id) + [
            'ticket_image'      => 'https://cdn.example.org/other.jpg',
            'ticket_image_edit' => TicketArtwork::pack([]),
        ]);

        $c = $this->columns($id);
        $this->assertSame('https://cdn.example.org/other.jpg', $c['ticket_image']);
        $this->assertNull($c['ticket_image_src'], 'a source nothing renders would come back on the next save');
        $this->assertNull($c['ticket_image_edit']);
    }

    public function test_re_posting_the_unchanged_address_is_not_a_decision_to_drop_the_artwork(): void
    {
        // The address box is pre-filled and an untouched form posts it straight back. Reading
        // that as "they typed an address" would delete the artwork of anybody who pressed Save
        // after fixing a typo in the venue.
        $id = $this->event();
        $this->save($id, $this->base($id) + ['ticket_image_edit' => TicketArtwork::pack([])],
            ['ticket_artwork' => $this->upload()]);
        $before = $this->columns($id);

        $this->save($id, $this->base($id) + [
            'ticket_image'      => $before['ticket_image'],
            'ticket_image_edit' => $before['ticket_image_edit'],
        ]);

        $after = $this->columns($id);
        $this->assertSame($before['ticket_image_src'], $after['ticket_image_src']);
        $this->assertNotNull($after['ticket_image']);
    }

    public function test_remove_clears_all_three_columns(): void
    {
        $id = $this->event();
        $this->save($id, $this->base($id) + ['ticket_image_edit' => TicketArtwork::pack([])],
            ['ticket_artwork' => $this->upload()]);

        $this->save($id, $this->base($id) + ['ticket_artwork_clear' => '1']);

        $this->assertSame(
            ['ticket_image' => null, 'ticket_image_src' => null, 'ticket_image_edit' => null],
            $this->columns($id)
        );
    }

    public function test_a_form_that_posts_no_recipe_leaves_an_existing_crop_alone(): void
    {
        // An old cached page, or a browser where the editor script never booted. Resetting the
        // frame to the centre would be a silent change to a document people print.
        $id = $this->event();
        $this->save($id, $this->base($id) + [
            'ticket_image_edit' => json_encode(['crop' => ['x' => .1, 'y' => .2, 'w' => .5, 'h' => .3]]),
        ], ['ticket_artwork' => $this->upload()]);
        $before = $this->columns($id);

        $this->save($id, $this->base($id));

        $this->assertSame($before, $this->columns($id));
    }

    public function test_a_rejected_upload_costs_the_picture_and_nothing_else_on_the_form(): void
    {
        $id  = $this->event();
        $tmp = tempnam(sys_get_temp_dir(), 'agbad_');
        file_put_contents($tmp, 'PK not a picture');
        $bad = new UploadedFile($tmp, 'cv.pdf', 'application/pdf', filesize($tmp) ?: null, UPLOAD_ERR_OK, false);

        $this->save($id, $this->base($id) + [
            'venue' => 'Eko Hotel', 'ticket_note' => 'Doors 5pm',
            'ticket_image_edit' => TicketArtwork::pack([]),
        ], ['ticket_artwork' => $bad]);

        $row = (array) DB::table('gates_site_events')->where('id', $id)->first();
        $this->assertSame('Eko Hotel', $row['venue'], 'the rest of the form is not lost with the picture');
        $this->assertSame('Doors 5pm', $row['ticket_note']);
        $this->assertNull($row['ticket_image_src'] ?? null);
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '', 'and the organiser is told');
    }

    public function test_an_event_with_no_artwork_is_untouched_by_any_of_this(): void
    {
        // The promise the whole migration rests on: nothing changes for anybody who does not
        // open the editor. A cover photo still reaches the ticket through EventTicketDesign.
        $id = $this->event(['cover_image' => '/uploads/covers/poster.jpg']);
        $this->save($id, $this->base($id));

        $c = $this->columns($id);
        $this->assertNull($c['ticket_image']);
        $this->assertNull($c['ticket_image_src']);
        $this->assertSame('/uploads/covers/poster.jpg',
            \AfricaGates\Services\EventTicketDesign::forEvent(
                (array) DB::table('gates_site_events')->where('id', $id)->first()
            )['image']);
    }
}
