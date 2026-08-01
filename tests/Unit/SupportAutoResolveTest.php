<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{SupportAnswerer, SupportAutoResolver, SupportContext, SupportTicketService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The assistant working the ticket queue unattended.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS ARE ACTUALLY DEFENDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Letting a model answer support tickets on its own is the highest-risk thing in
 * this codebase, and none of the risk is in the answer being wrong — a wrong
 * answer gets corrected by the person who reads it. The risk is in the ticket
 * being MARKED ANSWERED. A resolved ticket leaves the queue; a resolved ticket
 * that was not resolved is somebody's money, quietly filed as dealt with.
 *
 * So the rules under test are all about restraint, not about quality:
 *   • it only closes what a repair tool actually returned ok for;
 *   • it never answers an urgent ticket;
 *   • it never answers twice without a member message in between;
 *   • it says nothing at all rather than saying something empty;
 *   • its replies are labelled as automated, in the thread and the email.
 *
 * The agent is faked throughout. What is being tested is the POLICY around the
 * model, which is the part that must hold no matter which model is plugged in or
 * how badly it behaves — so the fakes are free to behave badly on purpose.
 */
final class SupportAutoResolveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_support_tickets')->delete();
        DB::table('gates_support_messages')->delete();
    }

    /**
     * An agent that returns exactly what it is told to, and records what it was
     * asked. Nothing here reaches a model or a network.
     */
    private function agent(string $reply, array $results = [], bool $available = true): SupportAnswerer
    {
        return new class ($reply, $results, $available) implements SupportAnswerer {
            public array $seen = [];
            public function __construct(
                private string $r, private array $res, private bool $up
            ) {}

            public function available(): bool { return $this->up; }

            public function ask(string $message, array $history, SupportContext $ctx,
                                array $only = [], bool $escalate = true): array
            {
                $this->seen[] = ['message' => $message, 'only' => $only, 'escalate' => $escalate];
                return ['reply' => $this->r, 'escalated' => false, 'ticket' => null,
                        'used' => array_column($this->res, 'tool'), 'results' => $this->res,
                        'provider' => 'fake'];
            }
        };
    }

    private function tickets(): SupportTicketService
    {
        return new SupportTicketService(null);   // no mailer: delivery is not what is under test
    }

    private function ticket(array $over = []): int
    {
        return (int) DB::table('gates_support_tickets')->insertGetId($over + [
            'reference'  => 'AGS-' . strtoupper(bin2hex(random_bytes(3))),
            'user_id'    => null,
            'email'      => 'okun@example.test',
            'name'       => 'Okun Alimosho',
            'subject'    => 'My votes have not arrived',
            'transcript' => 'User: I bought 20 votes with opay and nothing has come. ref paystack_6413965117_hw8rf',
            'severity'   => 'high',
            'status'     => 'open',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A tool result shaped the way SupportContext::run() shapes them. */
    private function toolResult(string $tool, bool $ok, array $data = []): array
    {
        return ['ok' => true, 'tool' => $tool, 'data' => ['ok' => $ok] + $data];
    }

    private function messages(int $ticketId): array
    {
        return DB::table('gates_support_messages')->where('ticket_id', $ticketId)
            ->orderBy('id')->get()->map(fn($r) => (array) $r)->all();
    }

    // ── it answers, and says what it is ──────────────────────────────────────

    public function test_a_repaired_payment_is_answered_and_the_ticket_is_resolved(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver(
            $this->agent('Found it — your payment was confirmed and 20 votes have now been added.',
                [$this->toolResult('fix_payment', true, ['outcome' => 'CONFIRMED'])]),
            $this->tickets()
        );

        $this->assertTrue($r->consider($id));

        $msgs = $this->messages($id);
        $this->assertCount(1, $msgs);
        $this->assertSame('agent', $msgs[0]['author_type'],
            'a member must be able to tell what wrote to them');
        $this->assertStringContainsString('20 votes', $msgs[0]['body']);
        $this->assertSame('resolved',
            DB::table('gates_support_tickets')->where('id', $id)->value('status'));
    }

    public function test_an_answer_with_no_repair_behind_it_leaves_the_ticket_open(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver(
            $this->agent('Voting closes on the 20th and paid votes are counted immediately.',
                [$this->toolResult('site_state', true)]),
            $this->tickets()
        );

        $this->assertTrue($r->consider($id), 'a useful read is still worth sending');
        $this->assertSame('open', DB::table('gates_support_tickets')->where('id', $id)->value('status'),
            '"here is an explanation" is not the same as "this is dealt with"');
        $this->assertStringContainsString('with the team', $this->messages($id)[0]['body']);
    }

    // ── and otherwise it stays quiet ─────────────────────────────────────────

    public function test_an_urgent_ticket_is_left_for_a_person(): void
    {
        $id = $this->ticket(['severity' => 'urgent', 'subject' => 'My card was used fraudulently']);
        $r  = new SupportAutoResolver(
            $this->agent('I have re-checked that for you.',
                [$this->toolResult('fix_payment', true)]),
            $this->tickets()
        );

        $this->assertFalse($r->consider($id),
            'fraud, a stolen card, a lawyer — a machine answering first is worse than silence');
        $this->assertSame([], $this->messages($id));
    }

    public function test_it_does_not_answer_twice_without_a_reply_in_between(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver(
            $this->agent('Voting is open until the 20th, and paid votes land in the tally immediately.',
                [$this->toolResult('site_state', true)]),
            $this->tickets()
        );

        $this->assertTrue($r->consider($id));
        // Nothing has been said back, so there is nothing new to answer.
        $this->assertFalse($r->consider($id));
        $this->assertCount(1, $this->messages($id));
    }

    public function test_a_member_reply_makes_it_answerable_again(): void
    {
        $id  = $this->ticket();
        $ref = (string) DB::table('gates_support_tickets')->where('id', $id)->value('reference');
        $r   = new SupportAutoResolver(
            $this->agent('Voting is open until the 20th, and paid votes land in the tally immediately.',
                [$this->toolResult('site_state', true)]),
            $this->tickets()
        );

        $this->assertTrue($r->consider($id));
        $this->tickets()->reply($ref, 'That is still not right.', 0, 'okun@example.test', 'Okun');
        $this->assertTrue($r->consider($id), 'the newest word on the thread is the member\'s again');

        $types = array_column($this->messages($id), 'author_type');
        $this->assertSame(['agent', 'member', 'agent'], $types);
    }

    public function test_an_answer_built_on_nothing_is_not_sent(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver(
            // Looked nothing up, repaired nothing — a paragraph of sympathy, which a
            // person can write better and with the authority to act on it.
            $this->agent('I am so sorry to hear that! Our team will look into this as soon as possible.', []),
            $this->tickets()
        );

        $this->assertFalse($r->consider($id));
        $this->assertSame([], $this->messages($id));
        $this->assertSame('open', DB::table('gates_support_tickets')->where('id', $id)->value('status'));
    }

    public function test_a_failed_lookup_does_not_count_as_having_looked(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver(
            $this->agent('I could not reach your records, but here is what usually happens…',
                [['ok' => false, 'tool' => 'fix_payment', 'error' => 'unavailable']]),
            $this->tickets()
        );

        $this->assertFalse($r->consider($id),
            'an answer built on a failed lookup is an answer built on nothing');
    }

    public function test_a_one_word_answer_is_not_sent(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver(
            $this->agent('Done.', [$this->toolResult('fix_payment', true)]),
            $this->tickets()
        );

        $this->assertFalse($r->consider($id));
    }

    public function test_nothing_happens_when_the_assistant_is_offline(): void
    {
        $id = $this->ticket();
        $r  = new SupportAutoResolver($this->agent('x', [], available: false), $this->tickets());

        $this->assertFalse($r->available());
        $this->assertFalse($r->consider($id));
        $this->assertSame(0, $r->sweep());
    }

    // ── the scope it works under ─────────────────────────────────────────────

    public function test_it_asks_with_a_narrow_toolset_and_cannot_escalate(): void
    {
        $id    = $this->ticket();
        $agent = $this->agent('Re-checked it.', [$this->toolResult('fix_payment', true)]);
        (new SupportAutoResolver($agent, $this->tickets()))->consider($id);

        $this->assertNotSame([], $agent->seen[0]['only'], 'unattended runs are allowlisted');
        $this->assertNotContains('my_transactions', $agent->seen[0]['only']);
        $this->assertFalse($agent->seen[0]['escalate'],
            'a ticket that escalates itself makes a second ticket about the first');
        $this->assertStringContainsString('paystack_6413965117_hw8rf', $agent->seen[0]['message'],
            'the reference in the ticket body is what makes the repair possible');
    }

    public function test_the_sweep_picks_up_waiting_tickets_and_skips_answered_ones(): void
    {
        $waiting  = $this->ticket();
        $answered = $this->ticket();
        DB::table('gates_support_messages')->insert([
            'ticket_id' => $answered, 'author_type' => 'agent', 'author_name' => 'Support assistant',
            'body' => 'Already answered.', 'is_internal' => 0, 'emailed' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $r = new SupportAutoResolver(
            $this->agent('Voting is open until the 20th, and paid votes land in the tally immediately.',
                [$this->toolResult('site_state', true)]),
            $this->tickets()
        );

        $this->assertSame(1, $r->sweep());
        $this->assertCount(1, $this->messages($waiting));
        $this->assertCount(1, $this->messages($answered), 'unchanged');
    }

    public function test_a_stale_ticket_is_left_alone(): void
    {
        $id = $this->ticket(['created_at' => date('Y-m-d H:i:s', time() - 8 * 86400)]);
        $r  = new SupportAutoResolver(
            $this->agent('Voting is open until the 20th, and paid votes land in the tally immediately.',
                [$this->toolResult('site_state', true)]),
            $this->tickets()
        );

        $this->assertSame(0, $r->sweep(),
            'a week-old unanswered ticket is a queue problem, and a bot reply buries it');
    }
}
