<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\{SupportContext, SupportTicketService};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What the assistant remembers about the person in front of it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROBLEM THESE TESTS EXIST FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every conversation used to start from nothing. Somebody escalated on Monday,
 * was told "your reference is AGS-9B5DE7", heard nothing by Tuesday, came back —
 * and was offered an escalation, because the assistant had no way to know one
 * already existed. They accepted, and the queue then held two tickets about one
 * payment, each with a number the person had been told to quote.
 *
 * So there are two halves here, and both must hold:
 *   • the assistant can READ its own tickets and nominations — and only its own;
 *   • pressing "talk to a human" twice about one thing produces ONE ticket.
 *
 * The scoping half matters more than the feature. `my_tickets` takes no
 * argument, exactly like every other read here: identity comes from the session
 * and there is nothing to point somewhere else.
 */
final class SupportMemoryTest extends TestCase
{
    private const MINE   = 'okun@example.test';
    private const THEIRS = 'someone.else@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_support_tickets')->delete();
        DB::table('gates_nominations')->delete();
    }

    private function member(int $id = 7, string $email = self::MINE): SupportContext
    {
        return new SupportContext($id, $email, false, null);
    }

    private function guest(): SupportContext
    {
        return new SupportContext(null, null, false, null);
    }

    private function ticket(string $ref, string $email, string $subject,
                            string $status = 'open', ?int $userId = null, string $when = 'now'): int
    {
        return (int) DB::table('gates_support_tickets')->insertGetId([
            'reference' => $ref, 'user_id' => $userId, 'email' => $email,
            'name' => 'Okun Alimosho', 'subject' => $subject, 'transcript' => 'x',
            'severity' => 'normal', 'status' => $status,
            'created_at' => date('Y-m-d H:i:s', strtotime($when)),
            'last_activity' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    /** @return array{int,int} cycle id, category id */
    private function cycle(): array
    {
        // Allocated, never literal: gates_award_programmes.id is TINYINT UNSIGNED.
        $p = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'p-' . bin2hex(random_bytes(3)), 'title' => 'Programme', 'is_active' => 1,
        ]);
        $c = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2031, 'status' => 'nominations',
        ]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $c, 'title' => 'Choral Excellence', 'slug' => 'cat-' . bin2hex(random_bytes(3)),
        ]);
        return [$c, $cat];
    }

    // ── the reads are scoped ─────────────────────────────────────────────────

    public function test_a_member_is_offered_the_memory_tools_and_a_guest_is_not(): void
    {
        $mine  = array_column($this->member()->tools(), 'name');
        $guest = array_column($this->guest()->tools(), 'name');

        $this->assertContains('my_tickets', $mine);
        $this->assertContains('my_nominations', $mine);
        // Both return somebody's own records, so both need an identity to scope to.
        $this->assertNotContains('my_tickets', $guest);
        $this->assertNotContains('my_nominations', $guest);
    }

    public function test_a_guest_naming_a_memory_tool_is_refused(): void
    {
        // The planner is a language model and can name a tool it was never shown.
        // The refusal lives in run(), not in the prompt that built the list.
        $r = $this->guest()->run('my_tickets');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No such tool', $r['error']);
    }

    public function test_my_tickets_returns_only_this_persons_tickets(): void
    {
        $this->ticket('AGS-AAAAAA', self::MINE,   'Votes not credited');
        $this->ticket('AGS-BBBBBB', self::THEIRS, 'Somebody else’s problem');

        $r = $this->member()->run('my_tickets');

        $this->assertTrue($r['ok']);
        $this->assertSame(['AGS-AAAAAA'], array_column($r['data'], 'reference'));
        $this->assertStringNotContainsString('AGS-BBBBBB', (string) json_encode($r));
    }

    public function test_a_ticket_comes_back_with_somewhere_to_follow_it(): void
    {
        $this->ticket('AGS-CCCCCC', self::MINE, 'Receipt never arrived');

        $t = $this->member()->run('my_tickets')['data'][0];

        $this->assertSame('open', $t['status']);
        $this->assertSame('/support/tickets?ref=AGS-CCCCCC', $t['follow'],
            'the assistant links to the page rather than describing where to look');
    }

    public function test_a_ticket_matched_by_account_id_counts_even_with_a_different_address(): void
    {
        // People change their address, and a ticket opened under the old one is
        // still theirs. Same rule as the ticket page: id OR email.
        $this->ticket('AGS-DDDDDD', 'old.address@example.test', 'Votes not credited', 'open', 7);

        $r = $this->member()->run('my_tickets');

        $this->assertSame(['AGS-DDDDDD'], array_column($r['data'], 'reference'));
    }

    public function test_my_nominations_returns_the_decision_and_its_reason(): void
    {
        [$cycle, $cat] = $this->cycle();
        DB::table('gates_nominations')->insert([
            'cycle_id' => $cycle, 'category_id' => $cat,
            'nominee_name' => 'Adaeze Okonkwo', 'nominator_name' => 'Okun Alimosho',
            'nominator_email' => self::MINE, 'status' => 'rejected',
            'decision_reason' => 'Outside the eligibility window.',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $n = $this->member()->run('my_nominations')['data'][0];

        $this->assertSame('rejected', $n['status']);
        $this->assertSame('Choral Excellence', $n['category']);
        $this->assertSame('Outside the eligibility window.', $n['reason'],
            '"rejected" with no reason is the answer that produces the angry second ticket');
    }

    public function test_my_nominations_matches_the_NOMINATOR_not_the_nominee(): void
    {
        // Somebody asking "did my entry go through" filled in a form. The person
        // they nominated is very often not them.
        [$cycle, $cat] = $this->cycle();
        DB::table('gates_nominations')->insert([
            'cycle_id' => $cycle, 'category_id' => $cat,
            'nominee_name' => 'Somebody Famous', 'nominee_email' => self::MINE,
            'nominator_name' => 'A Stranger', 'nominator_email' => self::THEIRS,
            'status' => 'approved', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], $this->member()->run('my_nominations')['data'],
            'being nominated by somebody else is not a nomination you submitted');
    }

    // ── one problem, one ticket ──────────────────────────────────────────────

    public function test_a_second_escalation_about_the_same_thing_finds_the_first(): void
    {
        $msg = 'I paid for votes and they never arrived';
        $this->ticket('AGS-EEEEEE', self::MINE, SupportTicketService::subjectFrom($msg));

        $found = (new SupportTicketService())->openTicketFor(7, self::MINE,
            SupportTicketService::subjectFrom($msg));

        $this->assertNotNull($found);
        $this->assertSame('AGS-EEEEEE', $found['reference']);
    }

    public function test_a_resolved_ticket_does_not_swallow_a_new_problem(): void
    {
        $msg = 'I paid for votes and they never arrived';
        $this->ticket('AGS-FFFFFF', self::MINE, SupportTicketService::subjectFrom($msg), 'resolved');

        $this->assertNull((new SupportTicketService())->openTicketFor(7, self::MINE,
            SupportTicketService::subjectFrom($msg)),
            'it happening again is a new problem, not a continuation of a closed one');
    }

    public function test_an_old_ticket_does_not_swallow_a_new_problem(): void
    {
        $msg = 'I paid for votes and they never arrived';
        $this->ticket('AGS-GGGGGG', self::MINE, SupportTicketService::subjectFrom($msg),
                      'open', null, '-9 days');

        $this->assertNull((new SupportTicketService())->openTicketFor(7, self::MINE,
            SupportTicketService::subjectFrom($msg)),
            'the window is deliberately short — merging two real problems loses one of them');
    }

    public function test_a_different_problem_opens_its_own_ticket(): void
    {
        $this->ticket('AGS-HHHHHH', self::MINE,
            SupportTicketService::subjectFrom('I paid for votes and they never arrived'));

        $this->assertNull((new SupportTicketService())->openTicketFor(7, self::MINE,
            SupportTicketService::subjectFrom('My nomination was rejected and I do not know why')),
            'a merged ticket is worse than a duplicate one — the second problem vanishes');
    }

    public function test_somebody_elses_open_ticket_is_never_matched(): void
    {
        $msg = 'I paid for votes and they never arrived';
        $this->ticket('AGS-IIIIII', self::THEIRS, SupportTicketService::subjectFrom($msg));

        $this->assertNull((new SupportTicketService())->openTicketFor(7, self::MINE,
            SupportTicketService::subjectFrom($msg)));
    }

    public function test_chasing_adds_to_the_ticket_and_moves_it_up_the_queue(): void
    {
        $id = $this->ticket('AGS-JJJJJJ', self::MINE, 'Votes not credited', 'open', 7, '-2 days');
        $svc = new SupportTicketService();

        $ok = $svc->appendEscalation(['id' => $id, 'reference' => 'AGS-JJJJJJ'],
            'Still nothing, three days later.', [], 'Okun Alimosho');

        $this->assertTrue($ok);
        $body = (string) DB::table('gates_support_messages')->where('ticket_id', $id)->value('body');
        $this->assertStringContainsString('Still nothing, three days later.', $body);

        $row = DB::table('gates_support_tickets')->where('id', $id)->first(['status', 'last_activity']);
        $this->assertSame('open', $row->status);
        $this->assertGreaterThan(strtotime('-1 hour'), strtotime((string) $row->last_activity),
            'a chased ticket has to rise in the queue, not age quietly at the bottom of it');
    }

    public function test_a_guest_with_no_identity_matches_nothing(): void
    {
        $this->ticket('AGS-KKKKKK', self::MINE, 'Votes not credited');

        $this->assertNull((new SupportTicketService())->openTicketFor(0, '', 'Votes not credited'),
            'with nobody to match, every open ticket would look like theirs');
    }
}
