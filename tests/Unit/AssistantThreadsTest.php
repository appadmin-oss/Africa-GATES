<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\AssistantController;
use AfricaGates\Admin\Services\AssistantThreads as T;
use AfricaGates\Support\ChatMarkdown;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The console assistant's conversations, kept on the server (26 Oct 2026).
 *
 * What has to hold: an answer's HTML can carry no markup the model or the data wrote; one
 * administrator's conversations are not another's to read, delete or rate; a check's output
 * reaches the evidence panel as the table it is; a tagged or current page is only ever one
 * the person's role can open; and the record is kept no longer than it says.
 */
final class AssistantThreadsTest extends TestCase
{
    private function admin(string $role = 'superadmin'): int
    {
        return (int) DB::table('gates_admins')->insertGetId(['name' => 'Chioma Obi', 'email' => 'a' . bin2hex(random_bytes(4)) . '@afrovanguard.example',
            'password_hash' => 'x', 'role' => $role]);
    }

    private function as(int $id, string $role = 'superadmin'): void
    {
        $_SESSION['admin_id'] = $id; $_SESSION['admin_role'] = $role; $_SESSION['admin_name'] = 'Chioma Obi';
        $_SERVER['REQUEST_URI'] = '/admin/assistant';
    }

    private function controller(): AssistantController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(AssistantController::class);
    }

    // ── the answer as HTML ───────────────────────────────────────────────────

    public function test_nothing_the_model_or_the_data_wrote_survives_as_markup(): void
    {
        $h = ChatMarkdown::html("<script>alert(1)</script> <img src=x onerror=alert(1)> &amp;\n\n"
            . "[click](javascript:alert(1)) [x](/admin/a\"onmouseover=alert(1)) [y](//evil.example/admin) [z](data:text/html,hi)");
        $this->assertStringNotContainsString('<script', $h);
        $this->assertStringNotContainsString('<img', $h);
        $this->assertStringNotContainsString('javascript:', $h);
        $this->assertStringNotContainsString('onmouseover', $h, 'an attribute cannot be smuggled through a link target');
        $this->assertStringNotContainsString('href="//', $h);
        $this->assertStringNotContainsString('data:text', $h);
        $this->assertStringContainsString('&lt;script&gt;', $h);
        $this->assertStringContainsString('&amp;amp;', $h, 'an entity is text too');
    }

    public function test_the_marks_it_does_draw(): void
    {
        $h = ChatMarkdown::html("**3 of 42** are stuck. Run `payments_reconcile` on [Operations scripts](/admin/assistant/scripts#payments_reconcile).\n\n"
            . "Two things:\n- the webhook, see /admin/payments\n- the **votes**\n\n1. first\n2. second\n\n[Docs](https://paystack.com/docs)");
        $this->assertStringContainsString('<strong>3 of 42</strong>', $h);
        $this->assertStringContainsString('<code>payments_reconcile</code>', $h);
        $this->assertStringContainsString('<a href="/admin/assistant/scripts#payments_reconcile">Operations scripts</a>', $h);
        $this->assertStringContainsString('<p>Two things:</p><ul><li>the webhook, see <a href="/admin/payments">/admin/payments</a></li><li>the <strong>votes</strong></li></ul>', $h);
        $this->assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $h);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $h, 'an outside link leaves in a new tab with no referrer');
        $this->assertStringContainsString('<code>a**b**</code>', ChatMarkdown::html('`a**b**`'), 'nothing inside code is a mark');
        $this->assertSame('3 of 42 are stuck. See Payments (/admin/payments).', ChatMarkdown::plain('**3 of 42** are stuck. See [Payments](/admin/payments).'));
    }

    // ── one person's conversations ───────────────────────────────────────────

    public function test_a_conversation_is_its_owners_alone(): void
    {
        $a = $this->admin(); $b = $this->admin();
        $t = T::create($a, 'Why are payments stuck?');
        T::append($t, 'user', 'Why are payments stuck?');
        $m = T::append($t, 'assistant', '3 are stuck.');

        $this->assertNull(T::find($t, $b));
        $this->assertSame([], T::messages($t, $b));
        $this->assertSame([], T::list($b));
        $this->assertFalse(T::rate($m, $b, 1));
        $this->assertFalse(T::delete($t, $b));

        $this->as($b);
        $c = $this->controller();
        $res = $c->thread((new ServerRequestFactory())->createServerRequest('GET', "/admin/assistant/threads/$t"), (new ResponseFactory())->createResponse(), ['id' => $t]);
        $this->assertSame(404, $res->getStatusCode(), 'somebody else’s conversation reads as not found');
        $res = $c->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/assistant')->withQueryParams(['t' => (string) $t]), (new ResponseFactory())->createResponse());
        $this->assertStringContainsString('data-as-open="0"', (string) $res->getBody());

        $this->assertTrue(T::rate($m, $a, 1));
        $this->assertSame(1, (int) DB::table('gates_assistant_messages')->where('id', $m)->value('rating'));
        $this->assertSame(['answers' => 1, 'rated' => 1, 'helpful' => 1], T::ratings());
        $this->assertFalse(T::rate((int) T::append($t, 'user', 'thanks'), $a, 1), 'only an answer is rated');
    }

    public function test_the_list_is_grouped_searched_and_kept_only_as_long_as_it_says(): void
    {
        $a = $this->admin();
        $today = T::create($a, 'Why are payments stuck?');
        T::append($today, 'user', 'Why are payments stuck?');
        T::append($today, 'assistant', 'The webhook for AG-PV-7Q2K91 never arrived.');
        $old = T::create($a, 'Did every paid vote land?');
        DB::table('gates_assistant_threads')->where('id', $old)->update(['updated_at' => date('Y-m-d H:i:s', time() - 9 * 86400)]);
        $gone = T::create($a, 'Something from the spring');
        T::append($gone, 'user', 'Something from the spring');
        DB::table('gates_assistant_threads')->where('id', $gone)->update(['updated_at' => date('Y-m-d H:i:s', time() - (T::RETAIN_DAYS + 1) * 86400)]);

        $this->assertSame([[$today, 'Today'], [$old, 'Past month']], array_map(static fn (array $t): array => [$t['id'], $t['group']], T::list($a)));
        $this->assertSame([$today], array_column(T::list($a, '7Q2K91'), 'id'), 'anything said in it, not only the title');
        $this->assertSame([], T::list($a, '%'), 'a wildcard typed is a character, not "everything"');

        $this->assertSame(1, T::prune());
        $this->assertNull(DB::table('gates_assistant_threads')->find($gone));
        $this->assertSame(0, DB::table('gates_assistant_messages')->where('thread_id', $gone)->count(), 'its evidence goes with it');
        $this->assertSame(2, DB::table('gates_assistant_threads')->where('admin_id', $a)->count());
    }

    public function test_the_title_is_the_first_question_cut_at_a_word(): void
    {
        $this->assertSame('Why are payments stuck?', T::title("  Why are   payments\nstuck?  "));
        $long = T::title(str_repeat('payments ', 20));
        $this->assertLessThanOrEqual(81, mb_strlen($long));
        $this->assertStringEndsWith('payments…', $long);
    }

    // ── evidence ─────────────────────────────────────────────────────────────

    public function test_a_check_result_reaches_the_panel_as_what_it_is(): void
    {
        $rows = [];
        for ($i = 1; $i <= 230; $i++) $rows[] = ['ref' => 'AG-' . $i, 'step' => 'webhook not received', 'paid' => $i % 2 === 0, 'meta' => ['a' => 1]];
        $e = T::shape('payments_triage', 'Where paid-vote orders stopped', true, 840,
            (string) json_encode(['window_days' => 7, 'stuck' => 230, 'by_step' => ['webhook' => 229, 'mint' => 1], 'stuck_orders' => $rows]));
        $this->assertSame('table', $e['kind']);
        $this->assertSame(['Ref', 'Step', 'Paid', 'Meta'], $e['columns']);
        $this->assertSame(['AG-2', 'webhook not received', 'yes', '{"a":1}'], $e['rows'][1]);
        $this->assertCount(200, $e['rows']);
        $this->assertSame(230, $e['total'], 'the panel can say "showing 200 of 230"');
        $this->assertSame(['Window days', 'Stuck', 'By step · webhook', 'By step · mint'], array_column($e['figures'], 'k'));

        $this->assertSame('figures', T::shape('votes_proof', 'x', true, 1, '{"proved":false,"orders_checked":39}')['kind']);
        $text = T::shape('doctor', 'x', false, 1, "PHP 8.4\nmigrations: 3 pending");
        $this->assertSame(['text', "PHP 8.4\nmigrations: 3 pending", false], [$text['kind'], $text['text'], $text['ok']]);

        $t = T::create($this->admin(), 'q');
        $id = T::append($t, 'assistant', 'done', [['key' => 'doctor', 'title' => 'What is live', 'ok' => true, 'ms' => 5, 'output' => 'ok']]);
        $row = DB::table('gates_assistant_messages')->find($id);
        $this->assertSame([['key' => 'doctor', 'title' => 'What is live', 'ok' => true, 'ms' => 5]], json_decode((string) $row->ran_json, true));
        $this->assertSame('text', json_decode((string) $row->evidence_json, true)[0]['kind']);
    }

    // ── context ──────────────────────────────────────────────────────────────

    public function test_the_page_and_the_tags_are_only_ever_pages_the_role_can_open(): void
    {
        $m = new \ReflectionMethod(AssistantController::class, 'context');
        $ctx = $m->invoke(null, ['page' => '/admin/payments?days=7', 'tags' => ['/admin/payments', '/admin/nominations/review', 'https://evil.example/']], 'superadmin');
        $this->assertSame('/admin/payments?days=7', $ctx['page']);
        $this->assertSame('Payments', $ctx['page_label']);
        $this->assertSame(['/admin/payments', '/admin/nominations/review'], array_column($ctx['tags'], 'href'));

        // A moderator's rail has no Money, so neither does their assistant's context.
        $mod = $m->invoke(null, ['page' => '/admin/payments', 'tags' => ['/admin/payments', '/admin/nominations/review']], 'moderator');
        $this->assertArrayNotHasKey('page', $mod);
        $this->assertSame(['/admin/nominations/review'], array_column($mod['tags'], 'href'));

        $sub = $m->invoke(null, ['page' => '/admin/nominations/review/count'], 'superadmin');
        $this->assertSame('Nominations', $sub['page_label'], 'a sub-page is named after the page it sits under');
        foreach (['https://evil.example/admin/payments', '/admin/../etc', '/admin/payments?<script>', '/admin/assistant'] as $bad) {
            $c = $m->invoke(null, ['page' => $bad], 'superadmin');
            $this->assertFalse(isset($c['page']) && str_contains((string) $c['page'], '<'), $bad);
            if ($bad !== '/admin/payments?<script>') $this->assertArrayNotHasKey('page', $c, $bad);
        }
    }

    public function test_a_question_is_kept_even_when_no_provider_answers(): void
    {
        $a = $this->admin();
        $this->as($a);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/assistant/chat')
            ->withParsedBody(['message' => 'Why are payments stuck?', 'page' => '/admin/payments']);
        $res = $this->controller()->chat($req, (new ResponseFactory())->createResponse());
        $j = json_decode((string) $res->getBody(), true);
        $this->assertFalse($j['ok'], 'no provider in the suite');
        $t = T::list($a);
        $this->assertCount(1, $t);
        $this->assertSame('Why are payments stuck?', $t[0]['title']);
        $msg = DB::table('gates_assistant_messages')->where('thread_id', $t[0]['id'])->first();
        $this->assertSame(['user', '/admin/payments'], [$msg->role, $msg->page]);
    }
}
