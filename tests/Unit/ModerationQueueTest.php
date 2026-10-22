<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Admin\Controllers\ModerationController;
use AfricaGates\Admin\Services\AuditService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/** Moderation queue: release (approve) or remove (reject) auto-quarantined posts. */
class ModerationQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    private function act(string $type, int $id, string $decision): Response
    {
        $_SESSION['admin_id'] = 1;
        $ctrl = new ModerationController(Twig::create(__DIR__ . '/../../templates'), new AuditService());
        $req = (new ServerRequestFactory())->createServerRequest('POST', 'https://x/admin/moderation');
        return $ctrl->action($req, new Response(), ['type' => $type, 'id' => $id, 'decision' => $decision]);
    }

    public function test_approve_thread_publishes(): void
    {
        DB::table('gates_threads')->insert(['id' => 1, 'slug' => 't', 'title' => 'T', 'author_name' => 'A', 'author_email_hash' => 'x', 'status' => 'quarantined', 'last_activity' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
        $this->act('thread', 1, 'approve');
        $this->assertSame('approved', DB::table('gates_threads')->where('id', 1)->value('status'));
    }

    public function test_remove_comment_rejects(): void
    {
        DB::table('gates_comments')->insert(['id' => 1, 'target_type' => 'thread', 'target_id' => 9, 'author_name' => 'A', 'body' => 'x', 'status' => 'quarantined', 'created_at' => '2026-01-01 00:00:00']);
        $this->act('comment', 1, 'remove');
        $this->assertSame('rejected', DB::table('gates_comments')->where('id', 1)->value('status'));
    }

    public function test_approve_thread_reply_bumps_count(): void
    {
        DB::table('gates_threads')->insert(['id' => 3, 'slug' => 't3', 'title' => 'T', 'author_name' => 'A', 'author_email_hash' => 'x', 'status' => 'approved', 'reply_count' => 0, 'last_activity' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00']);
        DB::table('gates_comments')->insert(['id' => 2, 'target_type' => 'thread', 'target_id' => 3, 'author_name' => 'A', 'body' => 'reply', 'status' => 'quarantined', 'created_at' => '2026-01-01 00:00:00']);
        $this->act('comment', 2, 'approve');
        $this->assertSame('approved', DB::table('gates_comments')->where('id', 2)->value('status'));
        $this->assertSame(1, (int)DB::table('gates_threads')->where('id', 3)->value('reply_count'));
    }

    public function test_invalid_action_is_noop(): void
    {
        $res = $this->act('banana', 1, 'approve');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error']);
    }
}
