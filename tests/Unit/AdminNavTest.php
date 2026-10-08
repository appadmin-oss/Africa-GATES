<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Middleware\SectionGuardMiddleware;
use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Services\HomeBoard;
use AfricaGates\Admin\Support\AdminNav;
use AfricaGates\Admin\Support\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * THE CONSOLE'S NAVIGATION — rebuilt with the admin handoff on 4 Oct 2026.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THAT MATTERS: REGROUPING CHANGED NOBODY'S ACCESS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Rule 7 of the handoff: access is per page, and moving a page in the sidebar must not
 * change who can open it. The old version of this file asserted the gate each SECTION
 * carried, against a snapshot read off the old rail — and the snapshot was the RAIL's
 * answer, not the guard's. Fifteen pages disagreed: the rail offered them to roles the
 * guard refused (an unmapped path fails closed to superadmin), and the test pinned the
 * rail's version of events.
 *
 * So the snapshot below is the GUARD's answer, taken from
 * `Permissions::sectionForPath()` on every page's href before this rebuild changed a
 * line of it (null = unmapped = superadmin only). Every page's gate today is diffed
 * against it, and exactly three may differ: Integrations and Email health moved to
 * `health`, and Alerts is new there — the owner-approved widening (GAPS §8d). Anything
 * else is an access change wearing a navigation change's clothes.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND A ROLE IS NEVER SHOWN A DOOR THE GUARD WILL CLOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Asked of the rail, the palette, Home's shortcuts and "needs a person", for every role
 * — and asked of the GUARD ITSELF, by running SectionGuardMiddleware over every href, so
 * "the nav agrees with the guard" is measured rather than assumed.
 */
final class AdminNavTest extends TestCase
{
    /** href => the guard's gate BEFORE the rebuild (null: unmapped, superadmin only). */
    private const BEFORE = [
        '/admin/dashboard' => 'overview', '/admin/assistant' => 'overview', '/admin/handbook' => null,
        '/admin/profiles' => 'moderation', '/admin/nominations' => 'moderation',
        '/admin/nominations/review' => 'moderation', '/admin/nominees' => 'moderation',
        '/admin/moderation' => 'moderation', '/admin/interviews' => 'moderation',
        '/admin/questionnaires' => 'moderation', '/admin/questionnaires/invitations' => 'moderation',
        '/admin/campaigns' => null, '/admin/support' => null,
        '/admin/programmes' => 'programmes', '/admin/shortlists' => 'programmes', '/admin/challenges' => null,
        '/admin/awards-page' => 'programmes',
        '/admin/events' => 'content', '/admin/stand-presets' => 'content', '/admin/posts' => 'content',
        '/admin/legacy' => 'content', '/admin/opportunities' => 'content', '/admin/media' => 'content',
        '/admin/products' => 'content', '/admin/shop/orders' => 'content', '/admin/forms' => 'content',
        '/admin/partners' => 'content', '/admin/legal' => 'content',
        '/admin/finance' => 'finance', '/admin/payouts' => null, '/admin/partner-orgs' => null,
        '/admin/vendor-policy' => null, '/admin/payments' => 'finance', '/admin/payments/ledger' => 'finance',
        '/admin/payments/disputes' => 'finance', '/admin/refunds' => 'finance', '/admin/vote-delivery' => 'finance',
        '/admin/vote-recovery' => null,
        '/admin/integrity' => null, '/admin/judging-audit' => null, '/admin/result-release' => null,
        '/admin/audit' => null, '/admin/data' => 'data', '/admin/analytics' => 'data', '/admin/registrations' => 'data',
        '/admin/admins' => 'configuration', '/admin/settings' => 'configuration', '/admin/webhooks' => 'configuration',
        '/admin/ai-prompts' => null, '/admin/attendee' => 'configuration', '/admin/sandbox' => 'configuration',
        '/admin/judges' => 'configuration', '/admin/rubric' => 'configuration',
        '/admin/settings/providers' => 'configuration', '/admin/settings/mail' => 'configuration',
    ];

    /** The owner-approved widening (GAPS §8d), and the one new page. */
    private const CHANGED = [
        '/admin/settings/providers' => 'health',
        '/admin/settings/mail'      => 'health',
        '/admin/alerts'             => 'health',
    ];

    private const ROLES = ['superadmin', 'admin', 'editor', 'moderator', 'viewer'];

    public function test_no_page_moved_across_a_permission_boundary_but_the_three_health_pages(): void
    {
        $now = AdminNav::hrefs();
        $drift = [];
        foreach ($now as $page => $href) {
            $gate = AdminNav::gateOf($href);
            if (array_key_exists($href, self::CHANGED)) {
                if ($gate !== self::CHANGED[$href]) $drift[] = "$href should be on `" . self::CHANGED[$href] . "`, is " . var_export($gate, true);
                continue;
            }
            $this->assertArrayHasKey($href, self::BEFORE, "$page ($href) is new to the console and unaccounted for");
            if ($gate !== self::BEFORE[$href]) {
                $drift[] = sprintf('%s moved from %s to %s', $href, var_export(self::BEFORE[$href], true), var_export($gate, true));
            }
        }
        $this->assertSame([], $drift, "an access change rode along inside the navigation:\n  " . implode("\n  ", $drift));

        // And every page the old console had is still named somewhere.
        $missing = array_diff(array_keys(self::BEFORE), array_values($now));
        $this->assertSame([], array_values($missing), 'pages vanished from the console');
    }

    public function test_the_health_gate_is_exactly_the_one_the_owner_approved(): void
    {
        $this->assertSame(['superadmin', 'admin', 'viewer'], Permissions::MATRIX['health']);
        // The configuration-changing actions on those pages did not move with them.
        foreach (['/admin/settings/mail/sending', '/admin/settings/mail/rules', '/admin/settings/providers/send-test'] as $p) {
            $this->assertSame('health', Permissions::sectionForPath($p), $p);
        }
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $from = strpos($routes, '// ── HEALTH: READ BY superadmin, admin AND viewer');
        $this->assertNotFalse($from, 'the health route group is gone');
        $group = substr($routes, (int) $from, (int) strpos($routes, '});', (int) $from) - (int) $from);
        foreach (['/providers', '/providers/run', '/mail', '/mail/diagnose'] as $read) {
            $this->assertStringContainsString("'{$read}'", $group, "$read did not move to health");
        }
        foreach (['send-test', '/mail/sending', '/mail/rules', '/mail/use-env', 'rotate', '/lift'] as $write) {
            $this->assertStringNotContainsString($write, $group, "$write rode along into the health group");
        }
    }

    // ══ the shape §2.2 draws ══════════════════════════════════════════════════

    public function test_the_groups_are_the_handoffs_in_its_order_and_words(): void
    {
        $got = [];
        foreach (AdminNav::groups() as $g) $got[$g['label']] = array_column($g['items'], 'label');

        $this->assertSame([
            'Daily work' => ['Review queue', 'Payment issues', 'Alerts', 'Support tickets'],
            // Hosts (not built, owner) and Editions (stage 2) are recorded as deviations.
            'Programmes' => ['Awards', 'Events & stands', 'Challenges'],
            'Entries'    => ['Nominations', 'Nominees', 'Profiles', 'Interviews'],
            'Money'      => ['Revenue', 'Payouts', 'Ledger', 'Refunds & disputes', 'Vote delivery', 'Vendor rules'],
            'Publishing' => ['Blog', 'Media', 'Awards page', 'Opportunities', 'Forms', 'Shop', 'Legal', 'Legacy vault'],
            'Monitoring' => ['Integrations', 'Email health', 'Audit log', 'Integrity', 'Analytics', 'All data'],
            'Settings'   => ['People & roles', 'Judges & rubric', 'Site & keys', 'Webhooks', 'AI & interview bot', 'Test data'],
        ], $got);
        $this->assertSame('Home', AdminNav::home()['label']);
    }

    public function test_no_page_appears_twice_and_every_href_is_an_admin_path(): void
    {
        $pages = AdminNav::pages();
        $this->assertSame(array_values(array_unique($pages)), $pages, 'a page is named twice');
        $hrefs = array_values(AdminNav::hrefs());
        $this->assertSame(array_values(array_unique($hrefs)), $hrefs, 'two entries open the same page');
        foreach (AdminNav::hrefs() as $page => $href) {
            $this->assertStringStartsWith('/admin/', $href, "$page points outside the admin");
        }
    }

    /** Every href is a route the router actually serves — asked of Slim, not parsed. */
    public function test_every_link_is_a_registered_route(): void
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        \Slim\Factory\AppFactory::setContainer($b->build());
        $app = \Slim\Factory\AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $gets = [];
        foreach ($app->getRouteCollector()->getRoutes() as $r) {
            if (in_array('GET', $r->getMethods(), true)) $gets[$r->getPattern()] = true;
        }
        foreach (AdminNav::hrefs() as $page => $href) {
            $this->assertArrayHasKey($href, $gets, "$page → $href is in the nav and not a GET route");
        }
        $this->assertArrayHasKey('/admin/alerts', $gets);
    }

    public function test_every_icon_the_nav_names_is_in_the_sprite(): void
    {
        $sprite = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/partials/nav-icons.twig');
        $icons = ['home', 'sidebar', 'search', 'assistant', 'shortlists'];   // the shell's own
        foreach (AdminNav::railItems() as $i) $icons[] = $i['icon'];
        foreach (AdminNav::elsewhere() as $e) $icons[] = $e['icon'];
        foreach (HomeBoard::shortcuts('superadmin') as $t) $icons[] = $t['icon'];
        foreach (array_unique($icons) as $icon) {
            $this->assertStringContainsString("id=\"ic-{$icon}\"", $sprite, "no icon for {$icon}");
        }
    }

    public function test_a_group_holds_no_more_than_a_dozen_and_none_is_empty(): void
    {
        foreach (AdminNav::groups() as $g) {
            $this->assertNotSame([], $g['items'], "{$g['label']} is empty");
            $this->assertLessThanOrEqual(12, count($g['items']), "{$g['label']} is a list, not a group");
        }
    }

    // ══ a role never sees a page it cannot open ══════════════════════════════

    /** Run the real guard over a path for a role: does it let the request through? */
    private function guardLets(string $role, string $href): bool
    {
        $_SESSION['admin_role'] = $role;
        $req = (new ServerRequestFactory())->createServerRequest('GET', $href);
        $passed = false;
        $handler = new class($passed) implements RequestHandlerInterface {
            public function __construct(private bool &$hit) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { $this->hit = true; return new Response(200); }
        };
        (new SectionGuardMiddleware())($req, $handler);
        unset($_SESSION['flash_error']);
        return $passed;
    }

    public function test_can_open_is_the_guards_own_answer_for_every_page_and_role(): void
    {
        foreach (self::ROLES as $role) {
            foreach (array_merge(AdminNav::hrefs(), ['alerts' => '/admin/alerts']) as $href) {
                $this->assertSame($this->guardLets($role, $href), Permissions::canOpen($role, $href),
                    "the nav and the guard disagree about $role on $href");
            }
        }
    }

    public function test_no_surface_offers_a_role_a_page_it_cannot_open(): void
    {
        ConsoleAlerts::reset();
        HomeBoard::resetAll();
        foreach (self::ROLES as $role) {
            $offered = [];
            $nav = AdminNav::forRole($role);
            if ($nav['home']) $offered[] = $nav['home']['href'];
            foreach ($nav['groups'] as $g) foreach ($g['items'] as $i) {
                $offered[] = $i['href'];
                foreach ($i['children'] as $c) $offered[] = $c['href'];
            }
            foreach (AdminNav::destinations($role) as $d) $offered[] = $d['href'];
            foreach (AdminNav::related($role, '/admin/events') as $r) $offered[] = $r['href'];
            foreach (HomeBoard::shortcuts($role) as $t) $offered[] = $t['href'];
            foreach (HomeBoard::board($role) as $b) $offered[] = $b['href'];
            if ($p = ConsoleAlerts::pill($role)) $offered[] = $p['href'];

            foreach (array_unique($offered) as $href) {
                $this->assertTrue($this->guardLets($role, (string) parse_url($href, PHP_URL_PATH)),
                    "$role was offered $href, which the guard refuses");
            }
        }
    }

    public function test_the_roles_see_different_rails_for_the_right_reasons(): void
    {
        $labels = static function (string $role): array {
            $out = [];
            foreach (AdminNav::forRole($role)['groups'] as $g) foreach ($g['items'] as $i) $out[] = $i['label'];
            return $out;
        };
        $this->assertContains('Site & keys', $labels('superadmin'));
        $this->assertNotContains('Site & keys', $labels('admin'), 'an admin was offered configuration');
        $this->assertNotContains('Revenue', $labels('moderator'), 'a moderator was offered money');
        $this->assertNotContains('Integrations', $labels('editor'), 'an editor was offered health');
        $this->assertContains('Integrations', $labels('viewer'), 'the viewer lost the owner-approved health pages');
        $this->assertContains('Email health', $labels('admin'));
        // A viewer sees no Settings group and (Hosts not being built) no Hosts.
        $this->assertNotContains('Settings', array_column(AdminNav::forRole('viewer')['groups'], 'label'));
    }

    // ══ where a request is ═══════════════════════════════════════════════════

    public function test_a_request_finds_its_page_by_path_before_its_key(): void
    {
        $this->assertSame('review', AdminNav::current('/admin/nominations/review')['item']['page']);
        $this->assertSame('nominations', AdminNav::current('/admin/nominations/42')['item']['page']);
        // The integrations check passes `admin_page: settings`; the path wins.
        $this->assertSame('providers', AdminNav::current('/admin/settings/providers', 'settings')['item']['page']);
        $this->assertSame('mail-health', AdminNav::current('/admin/settings/mail/x')['item']['page']);
        // A child lights its parent in the rail and itself in the strip.
        $c = AdminNav::current('/admin/shortlists');
        $this->assertSame('programmes', $c['item']['page']);
        $this->assertSame('shortlists', $c['page']['page']);
        $this->assertSame('dashboard', AdminNav::current('/admin')['item']['page']);
        $this->assertNull(AdminNav::current('/admin/nowhere')['item']);
    }

    public function test_the_also_here_strip_is_the_item_and_its_children_and_never_one_link(): void
    {
        $strip = AdminNav::related('superadmin', '/admin/events');
        $this->assertSame(['events', 'stand_presets', 'registrations'], array_column($strip, 'page'));
        $this->assertTrue($strip[0]['on']);
        $this->assertSame([], AdminNav::related('superadmin', '/admin/challenges'), 'a lone page drew a strip');
        // A moderator on Interviews: the questionnaires are theirs.
        $this->assertContains('questionnaires', array_column(AdminNav::related('moderator', '/admin/interviews'), 'page'));
    }

    /** The palette is generated from the same tree as the rail. */
    public function test_the_command_palette_is_generated_from_the_same_tree(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/layout.twig');
        $pal = substr($layout, (int) strpos($layout, 'id="cnPalList"'));
        $pal = substr($pal, 0, (int) strpos($pal, '</ul>'));
        $this->assertStringContainsString('{% for d in shell.destinations %}', $pal,
            'the palette holds a hand-written list instead of looping the nav tree');
        $this->assertStringContainsString('{{ d.href }}', $pal);
    }
}
