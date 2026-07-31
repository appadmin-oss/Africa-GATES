<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CloudinaryService;
use AfricaGates\Services\MediaMigrationService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The bulk sweep, end to end, against a stubbed Cloudinary.
 *
 * The HTTP call is mocked; everything else is real — real files on disk, the real
 * signature, the real ledger, the real column rewrites. That is deliberate, because the
 * properties an operator actually depends on are not in the HTTP call:
 *
 *   • the referencing column ends up pointing at the CDN,
 *   • RE-RUNNING uploads nothing (the property that makes "run it again to be sure" safe
 *     rather than a way to triple a Cloudinary bill),
 *   • one file referenced by several rows is uploaded ONCE,
 *   • a JSON gallery is rewritten entry by entry rather than clobbered,
 *   • the local file is still there afterwards.
 */
class MediaMigrationSweepTest extends TestCase
{
    private string $root = '';

    /** @var list<array{0:string,1:array}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/afg-media-' . bin2hex(random_bytes(5));
        @mkdir($this->root . '/uploads/nominees/2026/07', 0775, true);
        @mkdir($this->root . '/uploads/legacy/2026/07', 0775, true);

        $_ENV['CLOUDINARY_URL'] = 'cloudinary://12345:secret@testcloud';
        $_ENV['CLOUDINARY_FOLDER'] = 'africa-gates';
        $this->requests = [];
    }

    protected function tearDown(): void
    {
        unset($_ENV['CLOUDINARY_URL'], $_ENV['CLOUDINARY_FOLDER']);
        $this->rmrf($this->root);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** A real 4×4 PNG, so nothing in the pipeline is reading a zero-byte file. */
    private function writeImage(string $rel): void
    {
        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 90, 60));
        imagepng($im, $this->root . '/' . $rel);
        imagedestroy($im);
    }

    /** A CloudinaryService whose HTTP layer answers like Cloudinary and records calls. */
    private function stubbedCloud(int $uploads = 12): CloudinaryService
    {
        $queue = [];
        for ($i = 0; $i < $uploads; $i++) {
            $queue[] = new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'public_id'  => 'africa-gates/stub/asset-' . $i,
                'secure_url' => 'https://res.cloudinary.com/testcloud/image/upload/v170000000' . $i . '/africa-gates/stub/asset-' . $i . '.png',
                'width'      => 4, 'height' => 4, 'bytes' => 120, 'format' => 'png', 'version' => 1700000000 + $i,
            ]));
        }
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(function (callable $next) {
            return function ($request, array $options) use ($next) {
                $this->requests[] = [(string) $request->getUri(), $options];
                return $next($request, $options);
            };
        });
        return new CloudinaryService(null, new Client(['handler' => $stack]));
    }

    private function sweep(int $uploads = 12): MediaMigrationService
    {
        return new MediaMigrationService($this->stubbedCloud($uploads), null, $this->root);
    }

    private function seedNominee(int $id, string $photo): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 81, 'slug' => 'p', 'title' => 'P', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 8101, 'programme_id' => 81, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 8102, 'cycle_id' => 8101, 'slug' => 'c', 'title' => 'C']);
        DB::table('gates_nominees')->insert([
            'id' => $id, 'category_id' => 8102, 'name' => 'Nominee ' . $id,
            'status' => 'approved', 'photo_path' => $photo,
        ]);
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_nominee_photo_is_uploaded_and_the_row_repointed(): void
    {
        $this->writeImage('uploads/nominees/2026/07/ada.png');
        $this->seedNominee(8200, '/uploads/nominees/2026/07/ada.png');

        $r = $this->sweep()->run(false, 10);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['migrated']);

        $stored = (string) DB::table('gates_nominees')->where('id', 8200)->value('photo_path');
        $this->assertTrue(CloudinaryService::isRemote($stored), "photo_path should now be a CDN URL, got: {$stored}");

        // The ledger records the source so a re-run can recognise it.
        $ledger = DB::table('gates_media_migrations')->where('source_path', 'uploads/nominees/2026/07/ada.png')->first();
        $this->assertNotNull($ledger);
        $this->assertSame('migrated', $ledger->status);
        $this->assertNotEmpty($ledger->public_id);
    }

    /** Non-destructive: the local original must survive, so the change is reversible. */
    public function test_the_local_file_is_not_deleted(): void
    {
        $this->writeImage('uploads/nominees/2026/07/ada.png');
        $this->seedNominee(8201, '/uploads/nominees/2026/07/ada.png');

        $this->sweep()->run(false, 10);

        $this->assertFileExists($this->root . '/uploads/nominees/2026/07/ada.png');
    }

    /**
     * The property that makes re-running safe. Without it, an operator who is not sure
     * the sweep finished and runs it again pays for a second copy of every photo.
     */
    public function test_a_second_run_uploads_nothing(): void
    {
        $this->writeImage('uploads/nominees/2026/07/ada.png');
        $this->seedNominee(8202, '/uploads/nominees/2026/07/ada.png');

        $this->sweep()->run(false, 10);
        $firstPass = count($this->requests);
        $this->assertSame(1, $firstPass);

        $this->requests = [];
        $second = $this->sweep()->run(false, 10);

        $this->assertSame(0, $second['done'], 'nothing should even be examined the second time');
        $this->assertSame([], $this->requests, 'and certainly nothing uploaded');
        $this->assertSame(0, $second['pending']);
    }

    /**
     * One file, several referencing rows — a portrait reused as a nominee photo, which
     * happens whenever a registry profile is linked to a nomination. It must cost one
     * upload, and all rows must end up on the same asset.
     */
    public function test_one_file_referenced_twice_is_uploaded_once(): void
    {
        $this->writeImage('uploads/nominees/2026/07/shared.png');
        $this->seedNominee(8203, '/uploads/nominees/2026/07/shared.png');
        DB::table('gates_posts')->insert([
            'id' => 8300, 'slug' => 's', 'title' => 'S', 'cover_image' => '/uploads/nominees/2026/07/shared.png',
        ]);

        $r = $this->sweep()->run(false, 10);

        $this->assertSame(1, count($this->requests), 'the same bytes must not be uploaded twice');
        $this->assertSame(2, $r['migrated'], 'both rows are still counted as migrated');
        $this->assertSame(
            (string) DB::table('gates_nominees')->where('id', 8203)->value('photo_path'),
            (string) DB::table('gates_posts')->where('id', 8300)->value('cover_image'),
            'both rows must point at the one asset'
        );
    }

    /** A JSON gallery: every entry rewritten, the array shape preserved. */
    public function test_a_json_gallery_is_rewritten_entry_by_entry(): void
    {
        $this->writeImage('uploads/legacy/2026/07/g1.png');
        $this->writeImage('uploads/legacy/2026/07/g2.png');
        DB::table('gates_legacy_events')->insert([
            'id' => 8400, 'slug' => 'e', 'title' => 'E', 'event_date' => '2026-07-01',
            'gallery_paths' => json_encode(['/uploads/legacy/2026/07/g1.png', '/uploads/legacy/2026/07/g2.png']),
        ]);

        $this->sweep()->run(false, 10);

        $out = json_decode((string) DB::table('gates_legacy_events')->where('id', 8400)->value('gallery_paths'), true);
        $this->assertIsArray($out);
        $this->assertCount(2, $out, 'the gallery must not lose an entry');
        foreach ($out as $u) {
            $this->assertTrue(CloudinaryService::isRemote($u), "gallery entry not repointed: {$u}");
        }
    }

    /**
     * The predicate must see a JSON-ESCAPED path. `json_encode` escapes forward slashes
     * by default, so every gallery in the database is stored as `"\/uploads\/..."` — and
     * a `LIKE '%uploads/%'` predicate matched none of them, so the sweep reported "0
     * remaining" while having skipped every gallery image on the platform. Pinned
     * separately from the rewrite test because the failure was in the FINDING, not the
     * rewriting, and it was completely silent.
     */
    public function test_a_json_escaped_path_is_still_found(): void
    {
        $this->writeImage('uploads/legacy/2026/07/g1.png');
        // json_encode's default output — escaped slashes — exactly as the app stores it.
        $escaped = json_encode(['/uploads/legacy/2026/07/g1.png']);
        $this->assertStringContainsString('\\/uploads', (string) $escaped, 'precondition: the slashes are escaped');

        DB::table('gates_legacy_events')->insert([
            'id' => 8402, 'slug' => 'e3', 'title' => 'E3', 'event_date' => '2026-07-01', 'gallery_paths' => $escaped,
        ]);

        $this->assertSame(1, (new MediaMigrationService(null, null, $this->root))->status()['by_target']['gates_legacy_events.gallery_paths'] ?? 0);
        $this->assertSame(1, $this->sweep()->run(false, 10)['migrated']);
    }

    /**
     * A malformed gallery value is left exactly as it was. Guessing at the structure to
     * save one manual fix risks destroying a gallery.
     *
     * ── WHY THE VALUE IS A JSON *STRING* AND NOT A BARE CSV ─────────────────────
     *
     * This test used to store `/uploads/…/a.png,/uploads/…/b.png` — a raw legacy CSV.
     * That passed under the SQLite harness, where `gallery_paths` is TEXT and accepts
     * anything, and it could NEVER have run against production: the column is `JSON` in
     * schema.sql, and MySQL/MariaDB attach an implicit `json_valid()` CHECK, so the
     * INSERT is rejected outright. The test was asserting the sweep survives a state the
     * real database forbids — which is worse than not testing it, because it reads as
     * coverage.
     *
     * A JSON-encoded STRING is the honest version: valid JSON, so it stores on both
     * drivers; decodes to a string rather than an array, so it lands in exactly the
     * `!is_array($list)` skip branch; and still contains "uploads", so the pending query
     * SELECTS the row and the test proves it was considered and then left alone — rather
     * than never looked at, which would pass for the wrong reason.
     *
     * It is also reachable in production: a hand-edited value, or a legacy importer that
     * wrapped the old CSV in JSON instead of splitting it.
     */
    public function test_a_malformed_gallery_is_left_untouched(): void
    {
        $bad = (string) json_encode('/uploads/legacy/2026/07/a.png,/uploads/legacy/2026/07/b.png');
        DB::table('gates_legacy_events')->insert(['id' => 8401, 'slug' => 'e2', 'title' => 'E2', 'event_date' => '2026-07-01', 'gallery_paths' => $bad]);

        $r = $this->sweep()->run(false, 10);

        $stored = (string) DB::table('gates_legacy_events')->where('id', 8401)->value('gallery_paths');
        $this->assertSame(
            json_decode($bad, true),
            json_decode($stored, true),
            'the value must come back unchanged — compared as JSON because MySQL normalises '
            . 'whitespace and escaping inside a JSON column while SQLite stores the bytes'
        );
        $this->assertGreaterThan(0, $r['skipped']);
    }

    /** A rejected upload leaves the row alone and records why. */
    public function test_a_rejected_upload_does_not_repoint_the_row(): void
    {
        $this->writeImage('uploads/nominees/2026/07/bad.png');
        $this->seedNominee(8204, '/uploads/nominees/2026/07/bad.png');

        $stack = HandlerStack::create(new MockHandler([
            new GuzzleResponse(401, [], (string) json_encode(['error' => ['message' => 'Invalid Signature']])),
        ]));
        $svc = new MediaMigrationService(new CloudinaryService(null, new Client(['handler' => $stack])), null, $this->root);

        $r = $svc->run(false, 10);

        $this->assertSame(1, $r['failed']);
        $this->assertSame('/uploads/nominees/2026/07/bad.png',
            (string) DB::table('gates_nominees')->where('id', 8204)->value('photo_path'),
            'a failed upload must never repoint the row');

        $ledger = DB::table('gates_media_migrations')->where('source_path', 'uploads/nominees/2026/07/bad.png')->first();
        $this->assertSame('failed', $ledger->status);
        $this->assertStringContainsString('Invalid Signature', (string) $ledger->error);
    }

    /**
     * A retry after a failure must be able to succeed. The ledger is upserted, not
     * insert-ignored, so a `failed` verdict does not become permanent.
     */
    public function test_a_failure_can_be_retried_successfully(): void
    {
        $this->writeImage('uploads/nominees/2026/07/retry.png');
        $this->seedNominee(8205, '/uploads/nominees/2026/07/retry.png');

        $failing = HandlerStack::create(new MockHandler([new GuzzleResponse(500, [], '{}')]));
        (new MediaMigrationService(new CloudinaryService(null, new Client(['handler' => $failing])), null, $this->root))->run(false, 10);
        $this->assertSame('failed', DB::table('gates_media_migrations')->where('source_path', 'uploads/nominees/2026/07/retry.png')->value('status'));

        $r = $this->sweep()->run(false, 10);

        $this->assertSame(1, $r['migrated']);
        $this->assertSame('migrated', DB::table('gates_media_migrations')->where('source_path', 'uploads/nominees/2026/07/retry.png')->value('status'));
        $this->assertTrue(CloudinaryService::isRemote((string) DB::table('gates_nominees')->where('id', 8205)->value('photo_path')));
    }

    /** A dry run must write nothing at all — not the column, not the ledger. */
    public function test_a_dry_run_changes_nothing(): void
    {
        $this->writeImage('uploads/nominees/2026/07/ada.png');
        $this->seedNominee(8206, '/uploads/nominees/2026/07/ada.png');

        $r = $this->sweep()->run(true, 10);

        $this->assertTrue($r['ok']);
        $this->assertSame([], $this->requests);
        $this->assertSame('/uploads/nominees/2026/07/ada.png', (string) DB::table('gates_nominees')->where('id', 8206)->value('photo_path'));
        $this->assertSame(0, DB::table('gates_media_migrations')->count(),
            'a dry run must not leave a ledger row, or the real run would skip the work');
    }

    /** Batching is bounded, so a web request finishes — and the rest is still reported. */
    public function test_a_batch_is_bounded_and_reports_what_remains(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->writeImage("uploads/nominees/2026/07/n{$i}.png");
            $this->seedNominee(8210 + $i, "/uploads/nominees/2026/07/n{$i}.png");
        }

        $r = $this->sweep()->run(false, 2);

        $this->assertSame(2, $r['done']);
        $this->assertSame(3, $r['pending'], 'the caller needs to know to come back');
    }

    /** The gates_uploads row records provider + public_id, so a delete can reach the CDN. */
    public function test_the_media_library_row_records_its_provider(): void
    {
        $this->writeImage('uploads/nominees/2026/07/lib.png');
        DB::table('gates_uploads')->insert([
            'id' => 8500, 'uploader_type' => 'admin', 'path' => '/uploads/nominees/2026/07/lib.png', 'mime' => 'image/png',
        ]);

        $this->sweep()->run(false, 10);

        $row = DB::table('gates_uploads')->where('id', 8500)->first();
        $this->assertSame('cloudinary', $row->provider);
        $this->assertNotEmpty($row->public_id);
        $this->assertSame('/uploads/nominees/2026/07/lib.png', $row->local_path,
            'the original path must be retained so the change is reversible');
        $this->assertTrue(CloudinaryService::isRemote((string) $row->path));
    }
}
