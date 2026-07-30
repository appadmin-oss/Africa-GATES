<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CloudinaryService;
use AfricaGates\Services\FlierService;
use AfricaGates\Services\MediaMigrationService;
use AfricaGates\Support\Media;
use AfricaGates\Support\MediaPublicId;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Cloudinary hosting: the URL algebra, the deterministic id, and the sweep's refusals.
 *
 * WHAT IS PINNED HERE AND WHY. Almost none of this needs a network, because almost none
 * of the risk is in the HTTP call. The risk is in three pieces of string manipulation
 * that are each silently wrong in a way that looks right:
 *
 *   • transformation INJECTION — get it wrong and every image 404s, or worse, delivers
 *     the original uncropped (which looks like the feature simply not working);
 *   • the DETERMINISTIC public id — get it wrong and a re-run of a bulk migration
 *     uploads a second copy of every photo on the platform;
 *   • the sweep's PATH GUARD — get it wrong and a crafted stored path publishes a file
 *     from outside the uploads tree to a permanent public CDN URL.
 *
 * The one thing that genuinely needs pinning against Cloudinary's documentation is the
 * signature, which is reproduced from their published example.
 */
class CloudinaryMediaTest extends TestCase
{
    private const HOST = 'https://res.cloudinary.com/demo/image/upload';

    // ── The signature ────────────────────────────────────────────────────────

    /**
     * Cloudinary's own documented example: params `{public_id: sample_image, timestamp:
     * 1315060510}` with secret `abcd` sign to this SHA-1. If this test fails, every
     * upload gets a 401 that reads like bad credentials.
     */
    public function test_signature_matches_cloudinarys_documented_example(): void
    {
        $this->assertSame(
            sha1('public_id=sample_image&timestamp=1315060510' . 'abcd'),
            CloudinaryService::sign(['timestamp' => '1315060510', 'public_id' => 'sample_image'], 'abcd'),
            'params must be alphabetically sorted, not in insertion order'
        );
    }

    public function test_signature_excludes_the_fields_cloudinary_excludes(): void
    {
        $with = CloudinaryService::sign(
            ['timestamp' => '1', 'file' => '@x.jpg', 'api_key' => '123', 'resource_type' => 'image', 'cloud_name' => 'demo'],
            's'
        );
        $this->assertSame(sha1('timestamp=1' . 's'), $with);
    }

    // ── Transformation injection ─────────────────────────────────────────────

    public function test_transformation_is_inserted_before_the_version_segment(): void
    {
        $this->assertSame(
            self::HOST . '/w_100/v1730000000/africa-gates/nominees/ada-1a2b3c4d.jpg',
            CloudinaryService::transformed(self::HOST . '/v1730000000/africa-gates/nominees/ada-1a2b3c4d.jpg', 'w_100')
        );
    }

    /**
     * The version segment must SURVIVE and stay in front of the public id — it is what
     * makes the URL immutably cacheable, so losing it turns every delivery into a miss.
     */
    public function test_version_segment_is_preserved(): void
    {
        $out = (string) CloudinaryService::transformed(self::HOST . '/v42/folder/id.jpg', 'c_fill,w_10');
        $this->assertStringContainsString('/v42/folder/id.jpg', $out);
    }

    /**
     * An existing transformation is REPLACED, never chained. Chaining would apply the
     * flier's face crop to an already-square 128px thumbnail — a face crop of a crop.
     */
    public function test_an_existing_transformation_is_replaced_not_stacked(): void
    {
        $thumb = self::HOST . '/c_fill,g_faces:auto,w_128,h_128/v9/folder/id.jpg';
        $out = (string) CloudinaryService::transformed($thumb, 'c_fill,g_faces:auto,w_1080,h_820');

        $this->assertStringContainsString('w_1080', $out);
        $this->assertStringNotContainsString('w_128', $out, 'the old transformation must be gone');
        $this->assertSame(1, substr_count($out, '/upload/'));
    }

    public function test_a_local_path_is_returned_untouched(): void
    {
        // The property that lets every template call media_url unconditionally while a
        // migration is only partly through.
        $this->assertSame('/uploads/nominees/2026/07/x.jpg', Media::url('/uploads/nominees/2026/07/x.jpg', 'portrait'));
        $this->assertSame('/uploads/x.jpg', CloudinaryService::transformed('/uploads/x.jpg', 'w_100'));
        $this->assertNull(Media::url('', 'portrait'));
        $this->assertNull(Media::url(null, 'portrait'));
    }

    public function test_a_foreign_https_url_is_returned_untouched(): void
    {
        // An operator's hand-typed cover image on someone else's host. Rewriting it
        // would produce a Cloudinary URL for an asset Cloudinary has never seen.
        $foreign = 'https://images.example.org/photo.jpg';
        $this->assertSame($foreign, Media::url($foreign, 'cover'));
    }

    public function test_public_id_is_recovered_from_a_delivery_url(): void
    {
        $this->assertSame(
            'africa-gates/nominees/ada-1a2b3c4d',
            CloudinaryService::publicIdFromUrl(self::HOST . '/v1730000000/africa-gates/nominees/ada-1a2b3c4d.jpg')
        );
        // Also through a transformation, which is what a stored value may carry.
        $this->assertSame(
            'africa-gates/nominees/ada-1a2b3c4d',
            CloudinaryService::publicIdFromUrl(self::HOST . '/c_fill,w_128/v1/africa-gates/nominees/ada-1a2b3c4d.jpg')
        );
        $this->assertNull(CloudinaryService::publicIdFromUrl('/uploads/x.jpg'));
    }

    public function test_remote_detection_is_host_based_not_substring_based(): void
    {
        $this->assertTrue(CloudinaryService::isRemote(self::HOST . '/v1/x.jpg'));
        $this->assertFalse(CloudinaryService::isRemote('/uploads/res.cloudinary.com/x.jpg'),
            'a local path merely CONTAINING the host name is not a delivery URL');
        $this->assertFalse(CloudinaryService::isRemote('https://evil.test/res.cloudinary.com/x.jpg'));
    }

    // ── Face gravity: the reason any of this exists ──────────────────────────

    public function test_every_portrait_preset_asks_for_face_gravity(): void
    {
        foreach (['avatar', 'thumb', 'portrait', 'flier', 'og'] as $preset) {
            $url = (string) Media::url(self::HOST . '/v1/folder/id.jpg', $preset);
            $this->assertStringContainsString('g_faces:auto', $url,
                "the '{$preset}' preset must anchor on the face — a centre crop of a "
                . 'waist-up portrait lands on the chest, which is the defect this fixes');
        }
    }

    /**
     * The flier preset and the flier's photo panel must agree exactly. If the preset
     * asked for a different shape, GD would crop the derivative to fit — reintroducing
     * the centre crop the face gravity exists to remove, invisibly.
     */
    public function test_flier_preset_matches_the_photo_panel_geometry(): void
    {
        $url = (string) Media::url(self::HOST . '/v1/folder/id.jpg', 'flier');
        $this->assertStringContainsString('w_' . FlierService::W, $url);
        $this->assertStringContainsString('h_' . FlierService::PHOTO_H, $url);
        // f_jpg, not f_auto: the flier derivative is fetched by GD and by crawlers, and
        // neither sends an Accept header for f_auto to negotiate against.
        $this->assertStringContainsString('f_jpg', $url);
    }

    public function test_absolute_prefixes_a_local_path_but_not_a_remote_one(): void
    {
        $this->assertSame(
            'https://afg.test/uploads/x.jpg',
            Media::absolute('/uploads/x.jpg', 'portrait', 'https://afg.test')
        );
        $this->assertStringStartsWith(self::HOST, (string) Media::absolute(self::HOST . '/v1/x.jpg', 'flier', 'https://afg.test'));
    }

    // ── The deterministic public id ──────────────────────────────────────────

    public function test_public_id_is_stable_for_the_same_file(): void
    {
        $a = MediaPublicId::forPath('uploads/nominees/2026/07/abc.jpg');
        $b = MediaPublicId::forPath('/uploads/nominees/2026/07/abc.jpg');   // leading slash
        $this->assertSame($a, $b, 'the same file must not get two ids because of a leading slash');
    }

    public function test_public_id_distinguishes_the_same_filename_in_different_months(): void
    {
        // The uploads tree is dated, so the same basename recurs. A collision here would
        // make one month's photo silently overwrite another's on Cloudinary.
        $this->assertNotSame(
            MediaPublicId::forPath('uploads/nominees/2026/07/photo.jpg'),
            MediaPublicId::forPath('uploads/nominees/2026/08/photo.jpg')
        );
    }

    public function test_public_id_carries_no_slash_and_no_extension(): void
    {
        $id = MediaPublicId::forPath('uploads/nominees/2026/07/Adébáyọ̀-Portrait.JPG');
        $this->assertStringNotContainsString('/', $id, 'a slash would make Cloudinary read it as a folder');
        $this->assertStringNotContainsString('.jpg', strtolower($id), 'the extension is a delivery format, not part of the id');
        // Slug::make folds rather than deletes, so an African name survives as letters.
        $this->assertStringContainsString('adebayo', $id);
    }

    // ── The sweep's guards ───────────────────────────────────────────────────

    public function test_sweep_targets_cover_every_image_column_and_no_audio_column(): void
    {
        $cols = [];
        foreach (MediaMigrationService::targets() as $t) $cols[] = $t['table'] . '.' . $t['column'];

        foreach ([
            'gates_nominees.photo_path', 'gates_nominations.nominee_photo_path',
            'gates_profiles.avatar_path', 'gates_profiles.cover_path', 'gates_profiles.gallery_paths',
            'gates_judges.avatar_path', 'gates_admins.avatar_path',
            'gates_award_programmes.cover_path', 'gates_legacy_events.cover_path',
            'gates_legacy_events.gallery_paths', 'gates_products.cover_path',
            'gates_site_events.cover_image', 'gates_posts.cover_image', 'gates_uploads.path',
        ] as $expected) {
            $this->assertContains($expected, $cols, "{$expected} holds an image path and must be swept");
        }

        $this->assertNotContains('gates_posts.audio_path', $cols,
            'audio_path is an MP3 — Cloudinary image/upload would reject it, and a '
            . '"%_path" heuristic is exactly how it would have been swept in by accident');
    }

    /** The media library's own row must be swept last — see the note on TARGETS. */
    public function test_gates_uploads_is_swept_last(): void
    {
        $targets = MediaMigrationService::targets();
        $this->assertSame('gates_uploads', end($targets)['table']);
    }

    /**
     * Values a sweep must not touch. `nominee_photo_path` is written by the PUBLIC
     * nomination form, so a stored value is partly attacker-influenced — and this code
     * turns one into a filesystem read followed by an upload to a permanent public URL.
     */
    public function test_the_sweep_refuses_paths_outside_the_uploads_tree(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 9100, 'slug' => 'p', 'title' => 'P', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 9101, 'programme_id' => 9100, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 9102, 'cycle_id' => 9101, 'slug' => 'c', 'title' => 'C']);

        $dangerous = [
            'uploads/../../.env',                 // traversal out of the tree
            '/etc/passwd',                        // absolute, outside uploads
            '/assets/img/logo.svg',               // a bundled site asset, not an upload
            'https://images.example.org/a.jpg',   // already remote
            '//evil.test/a.jpg',                  // protocol-relative
        ];
        foreach ($dangerous as $i => $path) {
            DB::table('gates_nominees')->insert([
                'id' => 9200 + $i, 'category_id' => 9102, 'name' => 'N' . $i,
                'status' => 'approved', 'photo_path' => $path,
            ]);
        }

        // Dry run so no network is needed; it still exercises the full guard path.
        $r = (new MediaMigrationService())->run(true, 50);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['migrated'], 'not one of these may be uploaded');
        foreach ($dangerous as $path) {
            $this->assertStringNotContainsString($path, implode("\n", $r['lines']));
        }
    }

    /**
     * A referenced file that is not on disk is recorded and LEFT ALONE. Rewriting it to
     * a Cloudinary URL that also 404s would convert a broken image into a broken image
     * nobody can trace.
     */
    public function test_a_missing_file_is_reported_and_the_row_is_not_rewritten(): void
    {
        DB::table('gates_posts')->insert([
            'id' => 9300, 'slug' => 'p', 'title' => 'P',
            'cover_image' => '/uploads/posts/2026/07/gone.jpg',
        ]);

        $r = (new MediaMigrationService())->run(true, 50);
        $this->assertSame(1, $r['missing']);
        $this->assertSame(
            '/uploads/posts/2026/07/gone.jpg',
            DB::table('gates_posts')->where('id', 9300)->value('cover_image'),
            'the row must be untouched'
        );
    }

    /** status() counts only rows that still reference a local upload. */
    public function test_status_counts_pending_rows_and_ignores_already_migrated_ones(): void
    {
        DB::table('gates_posts')->insert([
            ['id' => 9401, 'slug' => 'a', 'title' => 'A', 'cover_image' => '/uploads/posts/2026/07/a.jpg'],
            ['id' => 9402, 'slug' => 'b', 'title' => 'B', 'cover_image' => self::HOST . '/v1/x/b.jpg'],
            ['id' => 9403, 'slug' => 'c', 'title' => 'C', 'cover_image' => null],
        ]);

        $s = (new MediaMigrationService())->status();
        $this->assertSame(1, $s['by_target']['gates_posts.cover_image'] ?? 0);
    }

    /** Refuses to run for real without credentials, rather than logging a thousand failures. */
    public function test_a_real_run_without_credentials_refuses_up_front(): void
    {
        foreach (['CLOUDINARY_URL', 'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $this->assertFalse(CloudinaryService::enabled());

        $r = (new MediaMigrationService())->run(false, 10);
        $this->assertFalse($r['ok']);
        $this->assertSame('not_configured', $r['error']);
    }

    public function test_credentials_are_read_from_the_url_form(): void
    {
        $_ENV['CLOUDINARY_URL'] = 'cloudinary://123456789:s3cr3t@afrovanguard';
        unset($_ENV['CLOUDINARY_CLOUD_NAME'], $_ENV['CLOUDINARY_API_KEY'], $_ENV['CLOUDINARY_API_SECRET']);

        $this->assertTrue(CloudinaryService::enabled());
        $this->assertSame('afrovanguard', CloudinaryService::cloudName());

        unset($_ENV['CLOUDINARY_URL']);
        $this->assertFalse(CloudinaryService::enabled(), 'and it is off again when unset — local storage is the default');
    }
}
