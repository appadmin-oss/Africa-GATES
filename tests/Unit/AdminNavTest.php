<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Support\AdminNav;
use AfricaGates\Admin\Support\Permissions;
use Tests\TestCase;

/**
 * The admin navigation tree, and the properties the old hand-written sidebar could not have.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The nav was thirty-six `<a>` tags in `admin/layout.twig`. A page could be built, routed
 * and permissioned and still never appear, because putting it in the sidebar was a
 * separate manual step nothing checked — and a link could rot into a 404 the same way.
 *
 * The most important assertion here is the LAST one: the restructure split the two
 * oversized groups, and splitting a group must never move a page across a permission
 * boundary. That would silently grant or remove access, which is not a navigation
 * decision and must not ride along inside one.
 */
final class AdminNavTest extends TestCase
{
    /**
     * The gate each page sat behind BEFORE the sections were split — read off the old
     * layout markup. Any drift from this is a permission change wearing a nav change's
     * clothes.
     *
     * @var array<string,?string>
     */
    private const ORIGINAL_GATES = [
        'dashboard' => null, 'assistant' => null,

        'profiles' => 'moderation', 'nominations' => 'moderation', 'nominees' => 'moderation',
        'moderation' => 'moderation', 'interviews' => 'moderation', 'questionnaires' => 'moderation',
        'campaigns' => 'moderation', 'support' => 'moderation',

        'programmes' => 'programmes', 'awards_page' => 'programmes',

        'events' => 'content', 'posts' => 'content', 'legacy' => 'content',
        'opportunities' => 'content', 'partners' => 'content', 'media' => 'content',
        'products' => 'content', 'shop_orders' => 'content', 'forms' => 'content',
        'legal' => 'content',

        'data' => 'data', 'analytics' => 'data', 'registrations' => 'data',

        'finance' => 'finance', 'partner-orgs' => 'finance', 'refunds' => 'finance',
        'vote-delivery' => 'finance', 'payments' => 'finance',
        'payments-ledger' => 'finance', 'payments-disputes' => 'finance',

        'admins' => 'configuration', 'settings' => 'configuration',
        'webhooks' => 'configuration', 'judges' => 'configuration',
    ];

    /** THE ONE THAT MATTERS: no page changed which permission covers it. */
    public function test_the_restructure_moved_no_page_across_a_permission_boundary(): void
    {
        $now = [];
        foreach (AdminNav::sections() as $s) {
            foreach ($s['items'] as $i) $now[$i['page']] = $s['gate'];
        }

        foreach (self::ORIGINAL_GATES as $page => $gate) {
            $this->assertArrayHasKey($page, $now, "{$page} vanished from the nav");
            $this->assertSame($gate, $now[$page],
                "{$page} moved from the '{$gate}' gate to '{$now[$page]}' — that is an access change, not a nav change");
        }
    }

    public function test_every_original_page_survived_the_restructure(): void
    {
        $missing = array_diff(array_keys(self::ORIGINAL_GATES), AdminNav::pages());

        $this->assertSame([], array_values($missing), 'pages were dropped from the nav');
    }

    public function test_no_page_appears_twice(): void
    {
        $pages = AdminNav::pages();

        $this->assertSame(array_unique($pages), $pages, 'a duplicated page would light two nav entries');
    }

    public function test_every_href_is_an_admin_path(): void
    {
        foreach (AdminNav::sections() as $s) {
            foreach ($s['items'] as $i) {
                $this->assertStringStartsWith('/admin/', $i['href'], "{$i['page']} points outside the admin");
                $this->assertNotSame('', trim($i['label']), "{$i['page']} has no label");
            }
        }
    }

    /**
     * Every href must be a route the app actually serves. A nav entry pointing at a 404 is
     * the failure the hand-written list produced twice.
     */
    public function test_every_link_is_a_registered_route(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        foreach (AdminNav::sections() as $s) {
            foreach ($s['items'] as $i) {
                // Routes are declared relative to their group, so match the tail.
                $tail = substr($i['href'], strlen('/admin'));
                $this->assertMatchesRegularExpression(
                    '~[\'"]' . preg_quote($tail, '~') . '[\'"]|[\'"]' . preg_quote(basename($tail), '~') . '[\'"]~',
                    $routes,
                    "{$i['href']} is in the nav but not in routes.php"
                );
            }
        }
    }

    /** Every item needs an icon symbol, or the sprite reference renders as nothing. */
    public function test_every_item_has_an_icon_in_the_sprite(): void
    {
        $sprite = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/partials/nav-icons.twig'
        );

        foreach (AdminNav::pages() as $page) {
            $this->assertStringContainsString("id=\"ic-{$page}\"", $sprite, "no icon for {$page}");
        }
    }

    // ══ the point of the exercise ════════════════════════════════════════════

    /**
     * Collapsing only helps if the sections are small enough that opening one is not a
     * second scroll. "Moderation" held eight and "Content" ten.
     */
    public function test_no_section_is_large_enough_to_need_scrolling(): void
    {
        foreach (AdminNav::sections() as $s) {
            $this->assertLessThanOrEqual(6, count($s['items']),
                "the '{$s['key']}' section has " . count($s['items']) . ' items — split it');
            $this->assertNotSame([], $s['items'], "the '{$s['key']}' section is empty");
        }
    }

    // ══ role filtering ═══════════════════════════════════════════════════════

    public function test_a_role_sees_only_the_sections_it_may_use(): void
    {
        $visible = AdminNav::visible(['moderation']);
        $keys    = array_column($visible, 'key');

        $this->assertContains('overview', $keys, 'the ungated section must always show');
        $this->assertContains('entries', $keys);
        $this->assertNotContains('configuration', $keys, 'a moderator was offered the superadmin section');
        $this->assertNotContains('finance', $keys);
    }

    public function test_no_role_is_offered_a_section_its_permissions_deny(): void
    {
        foreach (['superadmin', 'admin', 'moderator', 'viewer'] as $role) {
            $allowed = Permissions::allowedSections($role);
            foreach (AdminNav::visible($allowed) as $s) {
                if ($s['gate'] === null) continue;
                $this->assertContains($s['gate'], $allowed,
                    "{$role} was offered the '{$s['key']}' section, which its permissions deny");
            }
        }
    }

    // ══ the second level ═════════════════════════════════════════════════════

    public function test_a_page_finds_its_own_section_and_siblings(): void
    {
        $this->assertSame('entries', AdminNav::sectionFor('nominees')['key']);

        $siblings = array_column(AdminNav::siblings('nominees'), 'page');
        $this->assertContains('profiles', $siblings);
        $this->assertContains('moderation', $siblings);
        $this->assertContains('nominees', $siblings, 'the current page belongs in its own strip');
    }

    /** A bar with one tab in it is noise, so the layout is given nothing to draw. */
    public function test_a_lone_page_gets_no_sub_nav(): void
    {
        foreach (AdminNav::sections() as $s) {
            if (count($s['items']) === 1) {
                $this->assertSame([], AdminNav::siblings($s['items'][0]['page']));
            }
        }
        $this->assertSame([], AdminNav::siblings('a-page-that-does-not-exist'));
    }

    public function test_an_unknown_page_has_no_section(): void
    {
        $this->assertNull(AdminNav::sectionFor('nope'));
    }
}
