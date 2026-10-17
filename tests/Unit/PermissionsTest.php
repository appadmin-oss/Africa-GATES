<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use AfricaGates\Admin\Support\Permissions;

/**
 * The admin RBAC matrix — single source of truth for section access. These
 * lock the role separation: moderators see only moderation, editors only
 * content/programmes, viewers everything-but-config (read-only), and
 * configuration is superadmin-only.
 */
class PermissionsTest extends TestCase
{
    public function test_superadmin_sees_everything(): void
    {
        foreach (array_keys(Permissions::MATRIX) as $section) {
            $this->assertTrue(Permissions::canAccess('superadmin', $section), "superadmin → $section");
        }
    }

    public function test_admin_sees_all_but_configuration(): void
    {
        $this->assertTrue(Permissions::canAccess('admin', 'moderation'));
        $this->assertTrue(Permissions::canAccess('admin', 'content'));
        $this->assertTrue(Permissions::canAccess('admin', 'data'));
        $this->assertFalse(Permissions::canAccess('admin', 'configuration'));
    }

    public function test_moderator_is_moderation_only(): void
    {
        $this->assertTrue(Permissions::canAccess('moderator', 'moderation'));
        $this->assertTrue(Permissions::canAccess('moderator', 'overview'));
        $this->assertFalse(Permissions::canAccess('moderator', 'content'));
        $this->assertFalse(Permissions::canAccess('moderator', 'programmes'));
        $this->assertFalse(Permissions::canAccess('moderator', 'configuration'));
    }

    public function test_editor_is_content_and_programmes(): void
    {
        $this->assertTrue(Permissions::canAccess('editor', 'content'));
        $this->assertTrue(Permissions::canAccess('editor', 'programmes'));
        $this->assertFalse(Permissions::canAccess('editor', 'moderation'));
        $this->assertTrue(Permissions::canAccess('editor', 'data'));   // can reach Data; DataRegistry scopes which datasets
        $this->assertFalse(Permissions::canAccess('editor', 'configuration'));
    }

    public function test_viewer_reads_all_but_configuration(): void
    {
        $this->assertTrue(Permissions::canAccess('viewer', 'moderation'));
        $this->assertTrue(Permissions::canAccess('viewer', 'content'));
        $this->assertTrue(Permissions::canAccess('viewer', 'data'));
        $this->assertFalse(Permissions::canAccess('viewer', 'configuration'));
    }

    public function test_unknown_role_sees_nothing(): void
    {
        $this->assertFalse(Permissions::canAccess('contributor', 'overview'));
        $this->assertFalse(Permissions::canAccess('', 'moderation'));
    }

    public function test_section_for_path(): void
    {
        $this->assertSame('overview', Permissions::sectionForPath('/admin'));
        $this->assertSame('overview', Permissions::sectionForPath('/admin/dashboard'));
        $this->assertSame('moderation', Permissions::sectionForPath('/admin/nominations'));
        $this->assertSame('moderation', Permissions::sectionForPath('/admin/nominees/5'));
        $this->assertSame('content', Permissions::sectionForPath('/admin/products'));
        $this->assertSame('programmes', Permissions::sectionForPath('/admin/programmes/3/cycle'));
        $this->assertSame('configuration', Permissions::sectionForPath('/admin/settings'));
        $this->assertSame('configuration', Permissions::sectionForPath('/admin/judges'));
        $this->assertSame('data', Permissions::sectionForPath('/admin/votes'));
        // Auth-exempt / utility / unmapped → no section
        $this->assertNull(Permissions::sectionForPath('/admin/login'));
        $this->assertNull(Permissions::sectionForPath('/admin/logout'));
        $this->assertNull(Permissions::sectionForPath('/admin/totally-unknown'));
        $this->assertNull(Permissions::sectionForPath('/something-else'));
    }

    public function test_allowed_sections(): void
    {
        $this->assertSame(['overview', 'moderation', 'data'], Permissions::allowedSections('moderator'));
        $this->assertSame(['overview', 'programmes', 'content', 'data'], Permissions::allowedSections('editor'));
        $this->assertContains('configuration', Permissions::allowedSections('superadmin'));
        $this->assertNotContains('configuration', Permissions::allowedSections('admin'));
    }

    public function test_is_utility_path(): void
    {
        $this->assertTrue(Permissions::isUtilityPath('/admin/login'));
        $this->assertTrue(Permissions::isUtilityPath('/admin/login/submit'));
        $this->assertTrue(Permissions::isUtilityPath('/admin/logout'));
        $this->assertTrue(Permissions::isUtilityPath('/admin/magic/request'));
        $this->assertTrue(Permissions::isUtilityPath('/admin/api/anything'));
        $this->assertFalse(Permissions::isUtilityPath('/admin/settings'));
        $this->assertFalse(Permissions::isUtilityPath('/admin/dashboard'));
        $this->assertFalse(Permissions::isUtilityPath('/admin'));
    }

    public function test_role_helpers(): void
    {
        $this->assertTrue(Permissions::isRole('moderator'));
        $this->assertFalse(Permissions::isRole('judge'));      // judge is not a console role
        $this->assertSame('Moderator', Permissions::label('moderator'));
    }
}
