<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\InterviewsController;
use AfricaGates\Services\InterviewService;
use AfricaGates\Services\OtpService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Telling the nominee a recording bot will be in the room — and keeping the record that
 * they were told.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `docs/CODEBASE-INDEX.md` describes `bot_disclosed_at` as "stamped by the invitation
 * rather than by an admin ticking a box, because a bot in the room is a materially
 * different thing to consent to than a human taking notes."
 *
 * It was not stamped by anything. The migration added the column, the index explained it,
 * and the literal string `bot_disclosed_at` appeared in no file under src/, templates/,
 * cron/ or public/ — §17's fault (a declared field with no reader) and §18's (a mechanism
 * with no route in) at the same time. Each part was complete in isolation.
 *
 * The invitation did say the sitting "may be recorded and written down", which is the
 * consent that gates capture. What it never said is that a PARTICIPANT would join to do
 * the recording. Somebody who opens a Meet link and finds an unnamed stranger already
 * there has been surprised in a conversation about their own work, and no column recorded
 * whether that surprise was avoidable.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE HOLD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The sentence appears only when a bot will actually attend — a paragraph about a
 * participant who never arrives is its own small dishonesty — and the stamp is written
 * only when the sentence was really sent, because a consent record that claims a
 * disclosure nobody made is worse than an empty column.
 */
final class InterviewBotDisclosureTest extends TestCase
{
    /** @var list<array{to:string, subject:string, body:string}> */
    private array $sent = [];
    private int $ivId = 0;

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];

        // The harness may carry an ATTENDEE_* value; these tests decide for themselves
        // whether a bot is configured, so snapshot and clear rather than assume.
        foreach (['ATTENDEE_API_KEY', 'ATTENDEE_BOT_NAME'] as $k) {
            $this->savedEnv[$k] = $_ENV[$k] ?? false;
            unset($_ENV[$k], $_SERVER[$k]);
        }

        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'p', 'title' => 'P']);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'judging']);
        DB::table('gates_award_categories')->insert([
            'id' => 1, 'cycle_id' => 1, 'slug' => 'music', 'title' => 'Music', 'sort_order' => 1,
        ]);
        DB::table('gates_nominees')->insert([
            'id' => 1, 'category_id' => 1, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 0, 'organic_vote_count' => 0,
        ]);
        // The address is resolved from the approved nomination, never from the caller.
        DB::table('gates_nominations')->insert([
            'id' => 1, 'cycle_id' => 1, 'category_id' => 1, 'nominee_name' => 'Ada Obi',
            'nominee_email' => 'ada@example.com', 'country_code' => 'NG',
            'nominator_name' => 'Ngozi', 'nominator_email' => 'ngozi@example.com',
            'status' => 'approved',
        ]);

        $this->ivId = (int) DB::table('gates_interviews')->insertGetId([
            'nominee_id'   => 1,
            'scheduled_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'timezone'     => 'Africa/Lagos',
            'status'       => 'invited',
            'meet_url'     => 'https://meet.google.com/abc-defg-hij',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === false) unset($_ENV[$k], $_SERVER[$k]);
            else $_ENV[$k] = $v;
        }
        parent::tearDown();
    }

    private function set(string $key, string $value): void
    {
        DB::table('gates_settings')->insert([
            'key_name' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A bot that is switched on AND has somewhere to run. Both are required. */
    private function botInPlay(string $name = 'Africa GATES Interview Assistant'): void
    {
        $this->set('attendee_api_key', 'sk-test-key');
        $this->set('attendee_bot_name', $name);
    }

    private function mailer(): OtpService
    {
        return new class($this->sent) extends OtpService {
            /** @param list<array<string,string>> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }

            public function sendCustom(string $to, string $subject, string $body): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
                return ['success' => true];
            }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'body' => $plainBody];
                return ['success' => true];
            }
        };
    }

    /** The message that went to the nominee, not to the panel. */
    private function nomineeMail(): string
    {
        InterviewService::invite($this->ivId, $this->mailer());

        foreach ($this->sent as $m) {
            if ($m['to'] === 'ada@example.com') return $m['body'];
        }

        $this->fail('the nominee was not written to at all');
    }

    private function stamp(): ?string
    {
        $v = DB::table('gates_interviews')->where('id', $this->ivId)->value('bot_disclosed_at');

        return $v === null ? null : (string) $v;
    }

    // ══ the sentence ═════════════════════════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. A stranger in the participant list is disclosed before the
     * call, in the message that sets the call up.
     */
    public function test_the_nominee_is_told_a_participant_will_join_to_record(): void
    {
        $this->botInPlay();

        $body = $this->nomineeMail();

        $this->assertStringContainsString('participant list', $body,
            'the nominee was invited to a call a bot would join without being told it would');
        $this->assertStringContainsString('Africa GATES Interview Assistant', $body,
            'a name in the participant list nobody recognises is the surprise this exists to prevent');
    }

    /**
     * And it is separate from the recording permission. Consenting to be recorded by the
     * people you are talking to is not consenting to a stranger arriving.
     */
    public function test_the_disclosure_does_not_replace_the_recording_permission(): void
    {
        $this->botInPlay();

        $body = $this->nomineeMail();

        $this->assertStringContainsString('recorded', $body);
        $this->assertStringContainsString('unless you have given the permission', $body,
            'the bot paragraph must point back at the consent that gates capture, not stand in for it');
    }

    /** With no key there is no bot, and a paragraph about one would be a fiction. */
    public function test_nothing_is_claimed_when_no_bot_is_configured(): void
    {
        $body = $this->nomineeMail();

        $this->assertStringNotContainsString('participant list', $body,
            'a nominee was told to expect a recording assistant that cannot be dispatched');
    }

    /**
     * The master switch is a separate gate from the credentials, and either one being
     * off means no bot arrives.
     */
    public function test_nothing_is_claimed_when_the_bot_is_switched_off(): void
    {
        $this->botInPlay();
        $this->set('interview_bot_enabled', '0');

        $body = $this->nomineeMail();

        $this->assertStringNotContainsString('participant list', $body);
    }

    // ══ the record ═══════════════════════════════════════════════════════════

    public function test_the_disclosure_is_recorded_when_it_was_actually_sent(): void
    {
        $this->botInPlay();
        $this->assertNull($this->stamp(), 'nothing has been sent yet');

        $this->nomineeMail();

        $this->assertNotNull($this->stamp(),
            'the column the index describes as the consent record stayed empty after the disclosure went out');
        $this->assertNotSame('', $this->stamp());
    }

    /** A stamp with no sentence behind it is a consent record that lies. */
    public function test_no_record_is_written_when_nothing_was_disclosed(): void
    {
        $this->nomineeMail();

        $this->assertTrue($this->stamp() === null || $this->stamp() === '',
            'a disclosure was recorded for an invitation that never mentioned a bot');
    }

    /**
     * Re-inviting sends the sentence again, but the fact worth keeping is when the person
     * was FIRST told — that is the question an operator asks when a bot has already joined.
     */
    public function test_re_inviting_does_not_move_the_first_disclosure(): void
    {
        $this->botInPlay();
        $this->nomineeMail();
        $first = $this->stamp();

        DB::table('gates_interviews')->where('id', $this->ivId)
            ->update(['bot_disclosed_at' => '2020-01-01 00:00:00']);

        $this->sent = [];
        $this->nomineeMail();

        $this->assertSame('2020-01-01 00:00:00', $this->stamp(),
            'the earliest disclosure is the one that answers "were they told before it joined?"');
        $this->assertNotNull($first);
    }

    // ══ the reader ═══════════════════════════════════════════════════════════

    /**
     * §17 the other way round: a column written and never shown is the same bug.
     *
     * Rendered rather than grepped, because the tag sits inside a `{% if %}` on a value
     * the controller has to supply — and a template referencing a variable nobody passes
     * renders as null in silence, which is the fault two doors down in this same file.
     */
    public function test_the_operator_screen_says_the_nominee_was_told(): void
    {
        $this->botInPlay();
        $this->nomineeMail();

        $html = $this->screen();

        $this->assertStringContainsString('bot disclosed', $html,
            'the stamp is written and never rendered — the same fault, inverted');
        $this->assertStringNotContainsString('bot not yet disclosed', $html);
    }

    /** And says the opposite plainly, because that is the state an operator must act on. */
    public function test_the_operator_screen_says_when_the_nominee_was_not_told(): void
    {
        $this->botInPlay();

        $html = $this->screen();

        $this->assertStringContainsString('bot not yet disclosed', $html,
            'a sitting a bot will join, with nobody told, looked identical to one where they were');
    }

    /**
     * Where no bot is in play the pair is a fact about nothing. A row of tags answering
     * questions nobody asked is how the two that matter stop being read.
     */
    public function test_the_tag_is_absent_where_no_bot_will_attend(): void
    {
        $html = $this->screen();

        $this->assertStringNotContainsString('bot not yet disclosed', $html);
        $this->assertStringNotContainsString('bot disclosed', $html);
        // The tags that DO matter on every deployment are still there.
        $this->assertStringContainsString('no permission to record', $html,
            'the render is empty for some other reason and these assertions prove nothing');
    }

    /** The operator screen for this sitting. */
    private function screen(): string
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'test-token';

        try {
            $b = new ContainerBuilder();
            $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
            $ctrl = $b->build()->get(InterviewsController::class);

            $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/interviews/' . $this->ivId);
            $res = $ctrl->show($req, (new ResponseFactory())->createResponse(), ['id' => $this->ivId]);

            $this->assertSame(200, $res->getStatusCode(),
                'show() redirected instead of rendering — it refused before reaching the template');

            return (string) $res->getBody();
        } finally {
            unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token']);
        }
    }
}
