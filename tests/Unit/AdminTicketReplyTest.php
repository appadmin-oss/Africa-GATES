<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\SupportTicketService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * When a person on the support desk answers a ticket, it has to be THEIR answer,
 * and somebody has to actually receive it.
 *
 * Neither was true. The admin desk pushed staff replies through agentReply(), which
 * files every message as `agent` / "Support assistant" and signs the outgoing email
 * as the bot — the controller worked out the admin's name and then dropped it. And
 * the call returned one bool, true as soon as the ROW was written, so a missing
 * mailer, a ticket with no address, and a send that threw were all reported to the
 * desk as "Reply sent and added to the member's thread."
 *
 * Put together with a confirmation banner that never rendered (see
 * AdminSupportFlashTest), the experience was: type a reply, press send, watch the
 * page come back unchanged, and find no message from you in the thread. Which is
 * indistinguishable from a button that does nothing.
 */
class AdminTicketReplyTest extends TestCase
{
    /** Records what would have been sent instead of sending it. */
    private function mailer(bool $throw = false): OtpService
    {
        return new class(['from_address' => 'x@y.io'], $throw) extends OtpService {
            /** @var array<int,array{to:string,subject:string,plain:string}> */
            public array $sent = [];
            public function __construct(array $cfg, private bool $throw) { parent::__construct($cfg); }
            public function sendBranded(string $to, string $subject, string $htmlBody,
                                        string $plainBody = '', string $category = '', string $hero = '',
                                        string $unsubscribeUrl = '', array $attachments = []): array
            {
                if ($this->throw) throw new \RuntimeException('smtp refused');
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'plain' => $plainBody];
                return ['ok' => true];
            }
        };
    }

    private function ticket(array $over = []): int
    {
        return (int) DB::table('gates_support_tickets')->insertGetId(array_merge([
            'reference' => 'AGS-TEST01', 'email' => 'member@example.com', 'name' => 'Member',
            'subject' => 'My votes never arrived', 'severity' => 'high', 'status' => 'open',
            'created_at' => date('Y-m-d H:i:s'),
        ], $over));
    }

    private function lastMessage(): object
    {
        return DB::table('gates_support_messages')->orderByDesc('id')->first();
    }

    public function test_a_staff_reply_is_filed_under_the_person_who_wrote_it(): void
    {
        $id = $this->ticket();
        $svc = new SupportTicketService($this->mailer());

        $r = $svc->staffReply($id, 'Checked the gateway — your votes are on the tally now.', 'Ada Admin', 7);

        $this->assertTrue($r['ok']);
        $m = $this->lastMessage();
        $this->assertSame('staff', (string) $m->author_type, 'not "agent"');
        $this->assertSame('Ada Admin', (string) $m->author_name, 'not "Support assistant"');
        $this->assertSame(7, (int) $m->author_id);
    }

    /** And the member is not told a machine wrote it. */
    public function test_the_email_does_not_claim_the_bot_wrote_it(): void
    {
        $id = $this->ticket();
        $mailer = $this->mailer();
        $svc = new SupportTicketService($mailer);

        $svc->staffReply($id, 'I have refunded it by hand.', 'Ada Admin', 7);

        $this->assertCount(1, $mailer->sent);
        $this->assertSame('member@example.com', $mailer->sent[0]['to']);
        $this->assertStringNotContainsString('written by the Africa GATES support assistant',
            $mailer->sent[0]['plain']);
        $this->assertStringContainsString('comes straight back to the team', $mailer->sent[0]['plain']);
    }

    /** The assistant keeps its own identity — the point is that they differ. */
    public function test_the_assistant_is_still_labelled_as_the_assistant(): void
    {
        $id = $this->ticket();
        $mailer = $this->mailer();

        (new SupportTicketService($mailer))->agentReply($id, 'Automated answer.');

        $m = $this->lastMessage();
        $this->assertSame('agent', (string) $m->author_type);
        $this->assertSame('Support assistant', (string) $m->author_name);
        $this->assertStringContainsString('written by the Africa GATES support assistant',
            $mailer->sent[0]['plain']);
    }

    public function test_a_delivered_reply_reports_delivered(): void
    {
        $id = $this->ticket();
        $r = (new SupportTicketService($this->mailer()))->staffReply($id, 'Done.', 'Ada Admin', 7);

        $this->assertTrue($r['emailed']);
        $this->assertNull($r['reason']);
        $this->assertSame(1, (int) $this->lastMessage()->emailed);
    }

    /**
     * THE ONE THAT MATTERS MOST. A send that fails must not be reported as a send.
     * The reply is still recorded — losing the text would be worse — but the desk
     * has to know that the person waiting on it has not been told anything.
     */
    public function test_a_failed_send_is_reported_as_a_failed_send(): void
    {
        $id = $this->ticket();
        $r = (new SupportTicketService($this->mailer(throw: true)))->staffReply($id, 'Done.', 'Ada Admin', 7);

        $this->assertTrue($r['ok'], 'the reply is still on the record');
        $this->assertFalse($r['emailed']);
        $this->assertSame('send_failed', $r['reason']);
        $this->assertSame(0, (int) $this->lastMessage()->emailed);
    }

    public function test_a_ticket_with_no_address_says_so_instead_of_claiming_success(): void
    {
        $id = $this->ticket(['email' => null, 'reference' => 'AGS-NOMAIL']);
        $r = (new SupportTicketService($this->mailer()))->staffReply($id, 'Hello?', 'Ada Admin', 7);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['emailed']);
        $this->assertSame('no_address', $r['reason']);
    }

    public function test_with_no_mailer_configured_the_desk_is_told(): void
    {
        $id = $this->ticket();
        $r = (new SupportTicketService(null))->staffReply($id, 'Hello?', 'Ada Admin', 7);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['emailed']);
        $this->assertSame('no_mailer', $r['reason'],
            'every reply written on this installation is reaching nobody, and that is worth saying once');
    }

    /**
     * A ticket opened from a signed-in session can carry a user_id and no email of
     * its own. The reply used to go nowhere; the address is on the account.
     */
    public function test_the_address_is_found_on_the_account_when_the_ticket_has_none(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId([
            'email' => 'signed.in@example.com', 'name' => 'Chi',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = $this->ticket(['email' => null, 'user_id' => $uid]);

        $mailer = $this->mailer();
        $r = (new SupportTicketService($mailer))->staffReply($id, 'Found you.', 'Ada Admin', 7);

        $this->assertTrue($r['emailed']);
        $this->assertSame('signed.in@example.com', $r['to']);
        $this->assertSame('signed.in@example.com', $mailer->sent[0]['to']);
    }

    /** Whoever replies, the ticket moves — and resolving still works. */
    public function test_a_staff_reply_can_resolve_the_ticket(): void
    {
        $id = $this->ticket();
        (new SupportTicketService($this->mailer()))->staffReply($id, 'Sorted.', 'Ada Admin', 7, true);

        $t = DB::table('gates_support_tickets')->where('id', $id)->first();
        $this->assertSame('resolved', (string) $t->status);
        $this->assertNotNull($t->resolved_at);
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $id = $this->ticket();
        $r = (new SupportTicketService($this->mailer()))->staffReply($id, '   ', 'Ada Admin', 7);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, (int) DB::table('gates_support_messages')->count());
    }
}
