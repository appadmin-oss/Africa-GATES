<?php
declare(strict_types=1);
namespace Tests\Unit;
use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use AfricaGates\Admin\Support\{AdminNav, DataRegistry, Permissions};
final class MemberWorkspaceTest extends TestCase
{
    private function controller(string $class): object
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $builder->build()->get($class);
    }
    public function test_directory_permissions_match_the_existing_dataset(): void
    {
        foreach (['superadmin', 'admin', 'viewer', 'editor', 'moderator', 'judge'] as $role) {
            $allowed = DataRegistry::canRole('users', $role) && Permissions::canAccess($role, 'data');
            $this->assertSame($allowed, Permissions::canOpen($role, '/admin/users'));
            $this->assertSame($allowed, in_array('/admin/users', array_column(AdminNav::destinations($role), 'href'), true));
        }
    }
    public function test_directory_search_is_literal_and_verification_filters_and_secrets_stay_private(): void
    {
        $_SESSION['admin_role'] = 'admin';
        DB::table('gates_users')->insert([
            ['name'=>'Percent % Person', 'email'=>'literal@example.test', 'email_verified'=>1, 'points'=>10, 'password_hash'=>'never-display-this'],
            ['name'=>'Other Person', 'email'=>'other@example.test', 'email_verified'=>0, 'points'=>0, 'password_hash'=>'another-secret'],
        ]);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/users')->withQueryParams(['q'=>'%', 'verification'=>'verified', 'page'=>999]);
        $html = (string) $this->controller(\AfricaGates\Admin\Controllers\UsersController::class)->index($req, new Response())->getBody();
        $this->assertStringContainsString('Percent % Person', $html);
        $this->assertStringNotContainsString('Other Person', $html);
        $this->assertStringNotContainsString('never-display-this', $html);
        $this->assertStringContainsString('page 1 of 1', $html);
    }
    public function test_member_detail_shows_ledger_without_private_credentials(): void
    {
        $_SESSION['admin_role'] = 'admin';
        $id = (int) DB::table('gates_users')->insertGetId(['name'=>'Ledger Member', 'email'=>'ledger@example.test', 'points'=>20, 'password_hash'=>'secret-not-for-admin-pages']);
        DB::table('gates_points_ledger')->insert(['user_id'=>$id, 'delta'=>20, 'reason'=>'admin.adjust', 'balance_after'=>20, 'created_at'=>date('Y-m-d H:i:s')]);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/data/users/' . $id);
        $html = (string) $this->controller(\AfricaGates\Admin\Controllers\UsersController::class)->show($req, new Response(), ['id'=>$id])->getBody();
        $this->assertStringContainsString('Recent points activity', $html);
        $this->assertStringContainsString('action="/admin/users/' . $id . '/points"', $html);
        $this->assertStringContainsString('admin.adjust', $html);
        $this->assertStringNotContainsString('secret-not-for-admin-pages', $html);
        $this->assertSame('members', AdminNav::current('/admin/data/users/' . $id)['item']['page']);
    }
    public function test_staff_search_is_paginated_and_keeps_member_accounts_separate(): void
    {
        $_SESSION['admin_role'] = 'superadmin';
        DB::table('gates_admins')->insert(['name'=>'Staff Example', 'email'=>'staff@example.test', 'role'=>'editor', 'is_active'=>1, 'password_hash'=>'staff-secret']);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/admins')->withQueryParams(['q'=>'staff@example.test']);
        $html = (string) $this->controller(\AfricaGates\Admin\Controllers\AdminsController::class)->index($req, new Response())->getBody();
        $this->assertStringContainsString('Staff Example', $html);
        $this->assertStringContainsString('Member accounts', $html);
        $this->assertStringNotContainsString('staff-secret', $html);
    }
    public function test_password_change_preserves_profile_and_refuses_wrong_current_password(): void
    {
        $id = (int) DB::table('gates_users')->insertGetId(['name'=>'Original Name', 'email'=>'security@example.test', 'password_hash'=>password_hash('old-secret', PASSWORD_DEFAULT), 'points'=>0]);
        $_SESSION['user_id'] = $id;
        $controller = $this->controller(\AfricaGates\Controllers\AccountController::class);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/account/password');
        $controller->passwordUpdate($req->withParsedBody(['current_password'=>'wrong', 'new_password'=>'new-secret']), new Response());
        $this->assertTrue(password_verify('old-secret', (string) DB::table('gates_users')->where('id', $id)->value('password_hash')));
        $res = $controller->passwordUpdate($req->withParsedBody(['current_password'=>'old-secret', 'new_password'=>'new-secret', 'name'=>'Stale Name']), new Response());
        $this->assertSame('/account?tab=security#password', $res->getHeaderLine('Location'));
        $this->assertTrue(password_verify('new-secret', (string) DB::table('gates_users')->where('id', $id)->value('password_hash')));
        $this->assertSame('Original Name', DB::table('gates_users')->where('id', $id)->value('name'));
        $page = (new ServerRequestFactory())->createServerRequest('GET', '/account')->withQueryParams(['tab'=>'security']);
        $html = (string) $controller->dashboard($page, new Response())->getBody();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@action="/account/password"]//input[@name="new_password"][@required]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="/account/password"]//input[@name="current_password"][@required]')->length);
        $this->assertSame(0, $xpath->query('//form[@action="/account/password"]//input[@required]/ancestor::details')->length);

    }
}
