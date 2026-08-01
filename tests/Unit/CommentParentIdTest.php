<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{CommunityService, SpamService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * A top-level comment must store parent_id as NULL, not 0.
 *
 * ── WHY THIS TEST TURNS FOREIGN KEYS BACK ON ────────────────────────────────
 *
 * `gates_comments.parent_id` is a foreign key onto `gates_comments.id`, so the
 * value 0 can never be valid — there is no comment #0. The service wrote it
 * anyway: it used `isset($data['parent_id']) ? (int) ... : null`, and every
 * client sends the field as an empty string rather than omitting it, so isset()
 * was true and `(int) ''` was 0.
 *
 * Against a database that enforces the key — which is the production
 * configuration, `foreign_key_constraints => true` in config/database.php —
 * every top-level reply was a "FOREIGN KEY constraint failed" 500 with nothing
 * stored. It affected the community thread page exactly as much as the Pulse
 * feed; they send an identical payload.
 *
 * The suite could not have caught it, because TestCase deliberately runs with
 * `PRAGMA foreign_keys = OFF` so unit seeds can stay minimal. That is a
 * reasonable default and this test does not change it — it turns the key on for
 * its own body only, which is the one place the constraint is the thing under
 * test. Any future change that reintroduces a 0 fails here.
 */
final class CommentParentIdTest extends TestCase
{
    private function withForeignKeys(callable $fn): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('PRAGMA foreign_keys = ON');
        try { $fn(); } finally { $pdo->exec('PRAGMA foreign_keys = OFF'); }
    }

    private function thread(): int
    {
        return (int) DB::table('gates_threads')->insertGetId([
            'slug' => 'fk-' . bin2hex(random_bytes(4)), 'title' => 'A thread',
            'body' => 'Body', 'author_name' => 'Amara Nwosu',
            'author_email_hash' => hash('sha256', 'amara@example.test'),
            'status' => 'approved', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function test_a_top_level_reply_stores_a_null_parent(): void
    {
        $this->withForeignKeys(function () {
            $id = $this->thread();
            $r = (new CommunityService(new SpamService()))->replyToThread($id, [
                // '' is what the browser sends for "no parent" — the form field
                // exists and is empty. Omitting it here would test nothing.
                'parent_id'    => '',
                'body'         => 'Voted this morning. Sharing with my staff group.',
                'author_name'  => 'Chidi Okeke',
                'author_email' => 'chidi@example.test',
            ]);

            $this->assertTrue($r['ok'], 'a top-level reply must be accepted');
            $row = DB::table('gates_comments')->orderByDesc('id')->first();
            $this->assertNotNull($row);
            $this->assertNull($row->parent_id, 'no parent must be NULL, never 0');
        });
    }

    public function test_a_threaded_reply_keeps_its_parent(): void
    {
        $this->withForeignKeys(function () {
            $svc = new CommunityService(new SpamService());
            $id  = $this->thread();

            $svc->replyToThread($id, ['parent_id' => '', 'body' => 'First',
                'author_name' => 'Amara Nwosu', 'author_email' => 'amara@example.test']);
            $parent = (int) DB::table('gates_comments')->orderByDesc('id')->value('id');

            $svc->replyToThread($id, ['parent_id' => (string) $parent, 'body' => 'Replying to that',
                'author_name' => 'Fatima Bello', 'author_email' => 'fatima@example.test']);

            $this->assertSame($parent, (int) DB::table('gates_comments')->orderByDesc('id')->value('parent_id'),
                'a real parent must survive');
        });
    }

    /** The literal string '0' is "no parent" too — it is not comment zero. */
    public function test_a_zero_parent_is_treated_as_no_parent(): void
    {
        $this->withForeignKeys(function () {
            $id = $this->thread();
            $r = (new CommunityService(new SpamService()))->replyToThread($id, ['parent_id' => '0', 'body' => 'Top level',
                'author_name' => 'Ngozi Eze', 'author_email' => 'ngozi@example.test']);

            $this->assertTrue($r['ok']);
            $this->assertNull(DB::table('gates_comments')->orderByDesc('id')->value('parent_id'));
        });
    }
}
