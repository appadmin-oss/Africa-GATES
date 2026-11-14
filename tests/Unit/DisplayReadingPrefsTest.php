<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DisplayReadingPrefs;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Display & reading on the member's account — REFERENCE §7.5 / §10, "localStorage plus
 * the member profile when signed in", which GAPS §3.7 found half-built: the device store
 * existed, the profile half did not (no column, no route, no reader).
 *
 * Built end to end in Phase 2: migration `2027_02_26_member_display_reading.php` (both
 * drivers, plus both schema files), `DisplayReadingPrefs`, `POST /account/display`, the
 * `member_display()` function the first-paint script reads, and `a11y.js` saving on change.
 * Each test below asks one link of that chain the question that would be silent if it
 * broke.
 */
final class DisplayReadingPrefsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaHas::forget();
        DB::table('gates_users')->insert([
            'id' => 4101, 'name' => 'Chioma Obi', 'email' => 'chioma.dr@example.test',
            'status' => 'active', 'email_verified' => 1, 'created_at' => '2026-10-03 09:00:00',
        ]);
    }

    public function test_the_column_exists_on_a_migrated_database(): void
    {
        // The harness runs every migration over the schema files; the column must be there
        // either way, or every read below is answering "nothing saved" for a missing column.
        $this->assertTrue(SchemaHas::column('gates_users', 'display_json'));
    }

    public function test_a_body_is_cut_down_to_the_known_keys_and_their_types(): void
    {
        $clean = DisplayReadingPrefs::normalise([
            'size' => '9', 'hc' => 'true', 'easy' => 1, 'space' => false, 'motion' => '0',
            'lang' => 'fr', '<script>' => true, 'listen' => true,
        ]);

        // Size is clamped to the three steps; only TRUE switches are kept; a key the store
        // does not have — including the language, which is a cookie — is dropped.
        $this->assertSame(['size' => 2, 'hc' => true, 'easy' => true, 'listen' => true], $clean);
        $this->assertSame(['size' => 0], DisplayReadingPrefs::normalise([]));
    }

    public function test_never_saved_is_null_and_not_the_defaults(): void
    {
        // Null lets the head script keep this browser's settings. The defaults would reset
        // somebody's chosen large text to standard the moment they signed in.
        $this->assertNull(DisplayReadingPrefs::forUser(4101));
        $this->assertNull(DisplayReadingPrefs::forUser(0));
    }

    public function test_what_is_saved_is_what_is_read_back(): void
    {
        $saved = DisplayReadingPrefs::save(4101, ['size' => 1, 'ul' => true, 'saver' => 'true']);
        $this->assertSame(['size' => 1, 'ul' => true, 'saver' => true], $saved);
        $this->assertSame($saved, DisplayReadingPrefs::forUser(4101));

        // Read back through the normaliser, so a document written by an older version — or
        // by hand — cannot hand the head script a key it would apply blindly.
        DB::table('gates_users')->where('id', 4101)->update(['display_json' => '{"size":7,"hc":true,"x":1}']);
        $this->assertSame(['size' => 2, 'hc' => true], DisplayReadingPrefs::forUser(4101));
    }

    public function test_the_route_saves_for_the_session_member_and_nobody_else(): void
    {
        $res = ChromeRender::page('/account/display', ['user_id' => 4101, 'user_name' => 'Chioma Obi'], [], 'POST',
            ['prefs' => ['size' => 2, 'hc' => true], 'user_id' => 1]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame(['size' => 2, 'hc' => true], DisplayReadingPrefs::forUser(4101),
            'the member in the SESSION is the one written — a `user_id` in the body is ignored');
    }

    public function test_the_route_refuses_a_visitor_who_is_not_signed_in(): void
    {
        $res = ChromeRender::page('/account/display', [], [], 'POST', ['prefs' => ['size' => 2]]);

        $this->assertSame(302, $res->getStatusCode(), 'UserAuthMiddleware must guard the write');
        $this->assertStringStartsWith('/account/login', $res->getHeaderLine('Location'));
    }

    public function test_the_first_paint_carries_the_members_settings(): void
    {
        DisplayReadingPrefs::save(4101, ['size' => 2, 'hc' => true]);

        $html = ChromeRender::html('/_dev/ui', ['user_id' => 4101, 'user_name' => 'Chioma Obi']);
        $this->assertStringContainsString('m={"size":2,"hc":true}', $html,
            'the head script was not handed the member\'s saved settings');
        $this->assertStringContainsString('data-ag-sync="saved"', $html);
        $this->assertStringContainsString('<meta name="ag-csrf" content="test-token">', $html);

        $out = ChromeRender::html('/_dev/ui');
        $this->assertStringContainsString('m=null', $out, 'a signed-out page must leave the device store in charge');
        $this->assertStringNotContainsString('data-ag-sync=', $out);
        $this->assertStringNotContainsString('name="ag-csrf"', $out, 'a token on a page with nothing to post it to');
    }
}
