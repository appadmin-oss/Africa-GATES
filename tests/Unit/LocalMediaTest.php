<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\LocalMedia;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Finding the file behind a URL we serve — and the ticket that printed blank without it.
 *
 * UploadService writes every upload to disk first and only then hands it to Cloudinary, so
 * with a CDN configured the STORED url is remote while the bytes are still local. Everything
 * that renders in a browser is fine with that. TicketPdf is not: it refuses to fetch a remote
 * URL, and used to give up at that point — so on every Cloudinary deployment, every printed
 * ticket had a gold rectangle where the artwork should be, silently, while the file sat on
 * disk the whole time.
 */
class LocalMediaTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/ag-localmedia-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/uploads/events/2026/09', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['uploads/events/2026/09', 'uploads/events/2026', 'uploads/events', 'uploads', ''] as $d) {
            foreach (glob(rtrim($this->root . '/' . $d, '/') . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
        }
        foreach (['uploads/events/2026/09', 'uploads/events/2026', 'uploads/events', 'uploads', ''] as $d) {
            @rmdir(rtrim($this->root . '/' . $d, '/'));
        }
        parent::tearDown();
    }

    private function put(string $rel): string
    {
        $abs = $this->root . '/' . $rel;
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, 'not really a jpeg, but it is a file');
        return $abs;
    }

    public function test_a_same_site_path_is_its_own_answer(): void
    {
        $this->assertSame('uploads/events/x.jpg', LocalMedia::path('/uploads/events/x.jpg'));
        $this->assertSame('uploads/events/x.jpg', LocalMedia::path('uploads/events/x.jpg'));
    }

    public function test_a_cdn_url_is_resolved_through_what_the_uploader_recorded(): void
    {
        $remote = 'https://res.cloudinary.com/demo/image/upload/v1/agates/events/poster.jpg';
        DB::table('gates_uploads')->insert([
            'uploader_type' => 'admin', 'path' => $remote, 'mime' => 'image/jpeg',
            'size_bytes' => 1234, 'width' => 1200, 'height' => 800,
            'provider' => 'cloudinary', 'public_id' => 'agates/events/poster',
            'local_path' => '/uploads/events/2026/09/poster.jpg',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('uploads/events/2026/09/poster.jpg', LocalMedia::path($remote),
            'the bytes never left this server — only the URL did');
    }

    public function test_a_remote_url_nobody_recorded_is_not_guessed_at(): void
    {
        // Somebody else's host. Inventing a local path for it would be a 404 at best and a
        // read of an unrelated file at worst.
        $this->assertSame('', LocalMedia::path('https://example.org/someone-elses/poster.jpg'));
        $this->assertSame('', LocalMedia::path('//example.org/protocol-relative.jpg'));
        $this->assertSame('', LocalMedia::path(''));
    }

    public function test_a_path_that_climbs_out_of_the_uploads_tree_is_refused(): void
    {
        // This value feeds file_get_contents() with an image decoder behind it, and it can
        // come from a hand-edited row or a restored backup.
        foreach ([
            '/uploads/../config/.env',
            'uploads/events/../../../etc/passwd',
            "/uploads/ok.jpg\0/etc/passwd",
        ] as $nasty) {
            $this->assertSame('', LocalMedia::path($nasty), 'refused: ' . addcslashes($nasty, "\0"));
        }
    }

    public function test_file_returns_a_real_readable_path_and_nothing_else(): void
    {
        $this->put('uploads/events/2026/09/poster.jpg');

        $this->assertSame(
            realpath($this->root . '/uploads/events/2026/09/poster.jpg'),
            LocalMedia::file('/uploads/events/2026/09/poster.jpg', $this->root)
        );
        $this->assertSame('', LocalMedia::file('/uploads/events/2026/09/missing.jpg', $this->root),
            'a path to nothing is not a file');
    }

    public function test_file_will_not_hand_back_something_outside_the_public_root(): void
    {
        // `path()` already refuses the obvious spellings; `file()` is the second gate, and it
        // is the one that catches a symlink — a legitimate thing to have inside the uploads
        // tree and an illegitimate way out of it.
        $outside = sys_get_temp_dir() . '/ag-outside-' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($outside, 'secret');
        @symlink($outside, $this->root . '/uploads/escape.jpg');

        $got = LocalMedia::file('/uploads/escape.jpg', $this->root);
        @unlink($this->root . '/uploads/escape.jpg');
        @unlink($outside);

        $this->assertSame('', $got, 'a symlink out of public/ is not a local file');
    }

    public function test_no_uploads_table_is_a_missing_picture_and_not_an_exception(): void
    {
        // A pre-migration schema, or a caller with no database at all. The fallback is a
        // ticket with no photograph, which is still a ticket; a 500 on a ticket download
        // is not.
        DB::schema()->drop('gates_uploads');
        $this->assertSame('', LocalMedia::path('https://res.cloudinary.com/demo/x.jpg'));
    }
}
