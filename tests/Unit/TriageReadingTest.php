<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\ReviewDesk;
use AfricaGates\Admin\Services\ReviewQueue;
use AfricaGates\Services\AiService;
use AfricaGates\Services\NominationAsk;
use AfricaGates\Services\NominationTriageService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The AI triage, read by the people it is for (26 Oct 2026).
 *
 * Three faults, each silent: the model was told every nomination from the current form had
 * no reference links (it counted three columns the form no longer writes), a nominator's
 * answer to "Ask for more" left the reading of the nomination BEFORE the answer in place,
 * and the screens reviewers decide from never drew the reading at all.
 */
final class TriageReadingTest extends TestCase
{
    private int $cycle = 0;
    private int $cat = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'tr-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
        $this->cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'tr' . bin2hex(random_bytes(3)), 'title' => 'Teacher of the Year', 'sort_order' => 1]);
    }

    private function nom(array $o = []): int
    {
        return (int) DB::table('gates_nominations')->insertGetId($o + [
            'cycle_id' => $this->cycle, 'category_id' => $this->cat, 'nominee_name' => 'Grace Mensah', 'country_code' => 'GH',
            'nominator_name' => 'Kofi Asante', 'nominator_email' => 'kofi@school.gh', 'reason' => 'Runs a weekend club.',
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
    }

    private function evidence(int $id, string $kind, string $addr, string $at = 'now'): void
    {
        DB::table('gates_nomination_evidence')->insert(['nomination_id' => $id, 'kind' => $kind,
            $kind === 'file' ? 'path' : 'url' => $addr, 'created_at' => date('Y-m-d H:i:s', strtotime($at))]);
    }

    /** A provider that records what it was sent and answers like a model. */
    private function spy(string $reply = '{"score": 82, "summary": "Specific, with a link to the club."}'): AiService
    {
        return new class($reply) extends AiService {
            public string $seen = '';
            public function __construct(private string $reply) { parent::__construct(groqKey: 'test-key'); }
            public function complete(string $system, string $user, int $maxTokens = 512, bool $json = false, float $temperature = 0.2, array $route = [], int $maxAttempts = 0): ?string
            {
                $this->seen = $user;
                return $this->reply;
            }
            public function lastUsage(): array { return ['in' => 1, 'out' => 1]; }
        };
    }

    public function test_the_model_is_told_about_the_evidence_the_form_actually_stores(): void
    {
        $id = $this->nom();
        $this->evidence($id, 'link', 'https://club.example.org/a');
        $this->evidence($id, 'link', 'https://club.example.org/a');   // the same address twice is one link
        $this->evidence($id, 'link', 'https://news.example.org/b');
        $this->evidence($id, 'file', '/uploads/nominations/x.pdf');
        $spy = $this->spy();
        NominationTriageService::generate($id, $spy);

        $this->assertStringContainsString('Supporting links provided: 2', $spy->seen);
        $this->assertStringContainsString('Supporting files attached: 1', $spy->seen);
        $this->assertStringNotContainsString('Reference links provided: 0', $spy->seen);
        $this->assertSame(82, (int) DB::table('gates_nomination_insights')->where('nomination_id', $id)->value('quality_score'));
    }

    public function test_every_category_case_is_read_not_only_the_first(): void
    {
        $other = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $this->cycle, 'slug' => 'tr2' . bin2hex(random_bytes(2)), 'title' => 'STEM Educator', 'sort_order' => 2]);
        $id = $this->nom(['reason' => 'Runs a weekend club for forty children.']);
        DB::table('gates_nomination_categories')->insert([
            ['nomination_id' => $id, 'category_id' => $this->cat, 'reason' => 'Runs a weekend club for forty children.', 'sort_order' => 0, 'created_at' => date('Y-m-d H:i:s')],
            ['nomination_id' => $id, 'category_id' => $other, 'reason' => 'Built the first science lab from donated kit.', 'sort_order' => 1, 'created_at' => date('Y-m-d H:i:s')],
        ]);
        $spy = $this->spy();
        NominationTriageService::generate($id, $spy);
        $this->assertStringContainsString('[STEM Educator]', $spy->seen);
        $this->assertStringContainsString('first science lab', $spy->seen);
    }

    public function test_an_answer_to_ask_for_more_is_read_again(): void
    {
        $id = $this->nom(['status' => 'needs_details']);
        NominationAsk::answer(DB::table('gates_nominations')->find($id), ['https://club.example.org/photos'], []);
        $jobs = DB::table('gates_jobs')->where('type', NominationTriageService::JOB_TRIAGE)->pluck('payload')->all();
        $this->assertContains($id, array_map(static fn (string $p): int => (int) (json_decode($p, true)['nomination_id'] ?? 0), $jobs));
    }

    public function test_the_reading_is_drawn_in_words_and_says_when_it_is_out_of_date(): void
    {
        $id = $this->nom();
        $this->assertNull(ReviewQueue::aiReading($id), 'AI off and never read: nothing to say');

        DB::table('gates_nomination_insights')->insert(['nomination_id' => $id, 'quality_score' => 82, 'summary' => 'Specific.',
            'duplicates_json' => '{}', 'created_at' => date('Y-m-d H:i:s', time() - 600)]);
        $r = ReviewQueue::aiReading($id);
        $this->assertSame(['ok', 'Reads specific and backed', 'green', false], [$r['state'], $r['band'], $r['tone'], $r['stale']]);

        $this->evidence($id, 'link', 'https://club.example.org/new');
        $this->assertTrue(ReviewQueue::aiReading($id)['stale'], 'evidence arrived after the reading');

        DB::table('gates_nomination_insights')->where('nomination_id', $id)->update(['quality_score' => 31]);
        $this->assertSame(['Reads thin', 'red'], [ReviewQueue::aiReading($id)['band'], ReviewQueue::aiReading($id)['tone']]);

        DB::table('gates_nomination_insights')->where('nomination_id', $id)->update(['quality_score' => null,
            'summary' => NominationTriageService::UNAVAILABLE . ' (PROVIDER_ERROR) — reviewed on duplicates only.']);
        $this->assertSame('unavailable', ReviewQueue::aiReading($id)['state']);
        $this->assertSame('unavailable', ReviewDesk::view($id)['ai']['state'], 'the desk carries the same reading');
    }

    // ── the button ───────────────────────────────────────────────────────────

    private function readAgain(int $id, string $referer): \Psr\Http\Message\ResponseInterface
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $req = (new ServerRequestFactory())->createServerRequest('POST', "/admin/nominations/$id/ai-insight", ['HTTP_REFERER' => $referer]);
        return $b->build()->get(\AfricaGates\Admin\Controllers\NominationsController::class)
            ->aiInsight($req, (new ResponseFactory())->createResponse(), ['id' => $id]);
    }

    public function test_reading_again_says_what_happened_and_goes_back_only_to_the_console(): void
    {
        $_SESSION = ['admin_id' => 1, 'admin_role' => 'superadmin'];
        $id = $this->nom();
        $res = $this->readAgain($id, 'http://localhost/admin/nominations/desk?item=' . $id);
        $this->assertSame('/admin/nominations/desk?item=' . $id, $res->getHeaderLine('Location'));
        // No provider in the suite: it must not claim a reading it did not get.
        $this->assertArrayNotHasKey('flash_ok', $_SESSION);
        $this->assertStringContainsString('switched off', (string) ($_SESSION['flash_error'] ?? ''));

        // Only the PATH of the referrer is ever used, so a foreign host cannot be the target.
        $this->assertSame('/admin/nominations/1', $this->readAgain($id, 'https://evil.example/admin/nominations/1')->getHeaderLine('Location'));
        foreach (['https://evil.example/phish', 'http://localhost/admin/users', 'http://localhost//evil.example/admin/nominations', ''] as $bad) {
            $this->assertSame('/admin/nominations/' . $id, $this->readAgain($id, $bad)->getHeaderLine('Location'), $bad);
        }
    }
}
