<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Account\Roles;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The role switch (owner, 4 Nov 2026, from a reference drawn for it): at the FOOT of the rail,
 * a square, the role you are working as and a "Switch" button, opening upward to one row per
 * role — square, name, one line — with the current one tinted and ticked. One partial for every
 * console that carries it, so two consoles cannot grow two designs of one control.
 */
final class RoleSwitchTest extends TestCase
{
    private function staffWithAnOrganisation(): array
    {
        $e = 'kemi@example.com';
        $aid = (int) DB::table('gates_admins')->insertGetId(['name' => 'Kemi Adebayo', 'email' => $e, 'role' => 'superadmin', 'is_active' => 1, 'password_hash' => 'x']);
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi Adebayo', 'email' => $e, 'status' => 'active', 'email_verified' => 1]);
        $org = (int) DB::table('gates_partner_orgs')->insertGetId(['slug' => 'bf', 'name' => 'Bright Futures', 'cac_number' => 'IT/1', 'scuml_number' => 'S', 'status' => 'approved']);
        DB::table('gates_org_users')->insert(['org_id' => $org, 'email' => $e, 'name' => 'Kemi', 'role' => 'owner', 'is_active' => 1, 'password_hash' => 'x']);
        return ['admin_id' => $aid, 'admin_role' => 'superadmin', 'admin_name' => 'Kemi Adebayo', 'user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid];
    }

    public function test_the_staff_console_carries_it_at_the_foot_of_the_rail(): void
    {
        $html = ChromeRender::html('/admin', $this->staffWithAnOrganisation());
        $this->assertMatchesRegularExpression('~<div class="cn-side__foot">.*?<details class="rs">~s', $html, 'not at the foot of the rail');
        $this->assertStringNotContainsString('cn-prod', $html, 'the old switcher at the top is still drawn');
        $foot = substr($html, (int) strpos($html, '<details class="rs">'));
        $foot = substr($foot, 0, (int) strpos($foot, '</details>'));
        $this->assertMatchesRegularExpression('~<span class="rs__cur">Platform admin</span>\s*<span class="rs__btn"[^>]*>Switch</span>~', $foot);
        $this->assertMatchesRegularExpression('~rs__i is-on" role="menuitem" aria-current="true">.*?Platform admin.*?Every host, every page.*?rs__tick~s', $foot);
        $this->assertMatchesRegularExpression('~action="/account/switch".*?name="kind" value="org".*?Org console.*?What a partner organisation sees~s', $foot,
            'another role is a link, or is missing');
        $this->assertSame(1, substr_count($foot, 'rs__tick'), 'more than one role marked current');
    }

    public function test_one_partial_for_both_consoles_and_honest_lines(): void
    {
        foreach (['templates/admin/layout.twig', 'templates/shell/layout.twig'] as $f) {
            $this->assertStringContainsString("include 'partials/role-switch.twig'", ChromeRender::source($f), "$f draws its own switch");
            $this->assertStringContainsString('/assets/css/console/role-switch.css', ChromeRender::source($f));
        }
        // "Every host, every page" is a superadmin's; a narrower role says which role it is.
        DB::table('gates_admins')->insert(['name' => 'M', 'email' => 'mod@example.com', 'role' => 'moderator', 'is_active' => 1, 'password_hash' => 'x']);
        $m = array_column(Roles::menu((object) ['id' => 9, 'email' => 'mod@example.com', 'email_verified' => 1]), null, 'kind');
        $this->assertStringNotContainsString('Every host', $m['admin']['sub']);
        $this->assertSame('Platform admin', $m['admin']['label']);
    }
}
