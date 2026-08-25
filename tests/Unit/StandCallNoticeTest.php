<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EmailOptOut, OtpService, QueueService, StandCall, StandCallNotice};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * "Email me when it opens", falling due.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE BUTTON NEEDED THIS TO EXIST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The call page for an unpublished event says the terms are not up yet and asks for an
 * address. Without a send, that address goes into a list nobody reads and the button is a
 * promise the platform cannot keep — which is worse than the grey sentence it replaced,
 * because the grey sentence promised nothing.
 */
final class StandCallNoticeTest extends TestCase
{
    /** @var list<array{to:string, subject:string, body:string, unsub:string}> */
    private array $sent = [];
    private int $callId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];

        DB::table('gates_site_events')->insert([
            'id' => 1, 'slug' => 'market', 'title' => 'Lagos Market Day', 'event_date' => '2026-12-14',
        ]);
        $this->callId = (int) DB::table('gates_stand_calls')->insertGetId([
            'event_id' => 1, 'status' => 'draft',
            'closes_at' => date('Y-m-d H:i:s', strtotime('+20 days')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('gates_stand_types')->insert([
            'event_id' => 1, 'slug' => 't', 'name' => 'Table', 'category' => 'books',
            'price_naira' => 20000, 'quota' => 5,
        ]);
    }

    private function asked(string $email, string $source = 'stands:market'): void
    {
        DB::table('gates_newsletter')->insert([
            'email_hash' => hash('sha256', $email), 'email' => $email,
            'source' => $source, 'subscribed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function mailer(): OtpService
    {
        return new class($this->sent) extends OtpService {
            /** @param list<array<string,mixed>> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = []): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject,
                                 'body' => $htmlBody . ' ' . $plainBody, 'unsub' => $unsubscribeUrl];
                return ['success' => true];
            }
        };
    }

    /** Run every queued notice through the handler the maintenance tick uses. */
    private function drain(): void
    {
        foreach (DB::table('gates_jobs')->where('type', StandCallNotice::JOB_NOTICE)->get() as $j) {
            StandCallNotice::deliver((array) json_decode((string) $j->payload, true), $this->mailer());
        }
    }

    private function queued(): int
    {
        return (int) DB::table('gates_jobs')->where('type', StandCallNotice::JOB_NOTICE)->count();
    }

    // ════════════════════════════════════════════════════════════════════════

    /** Opening the call is what makes the promise fall due. */
    public function test_opening_the_call_queues_everyone_who_asked(): void
    {
        $this->asked('one@example.com');
        $this->asked('two@example.com');

        $r = StandCall::open($this->callId, 1);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['told']['queued']);
        $this->assertSame(2, $this->queued());
        $this->assertStringContainsString('asked to be told', $r['message']);
    }

    /**
     * QUEUED, not sent in the request.
     *
     * Opening a call is one press of one button, and the press must not become as slow as
     * the list is long — four hundred interested vendors would hang it behind a proxy and
     * leave an operator pressing again against a call that is already open.
     */
    public function test_nothing_is_sent_during_the_press(): void
    {
        $this->asked('one@example.com');

        StandCall::open($this->callId, 1);

        $this->assertSame([], $this->sent, 'the button sent mail inline');
        $this->assertSame(1, $this->queued());
    }

    /** And the message says the thing the page's whole promise rests on. */
    public function test_the_message_says_nothing_is_first_come(): void
    {
        $this->asked('one@example.com');
        StandCall::open($this->callId, 1);
        $this->drain();

        $this->assertCount(1, $this->sent);
        $m = $this->sent[0];

        $this->assertStringContainsString('Lagos Market Day', $m['subject']);
        $this->assertStringContainsString('/events/market/stands', $m['body']);
        $this->assertStringContainsString('Nothing is allocated first-come', $m['body']);
        // It is an announcement, so it carries a way out — the header URL and the
        // plain-text line, because the branded footer only covers the HTML.
        $this->assertStringContainsString('/email/unsubscribe?e=', $m['unsub']);
        $this->assertStringContainsString('/email/unsubscribe?e=', $m['body']);
    }

    /** Only the people who asked about THIS event. */
    public function test_somebody_who_asked_about_another_event_is_not_told(): void
    {
        $this->asked('mine@example.com');
        $this->asked('elsewhere@example.com', 'stands:other-market');
        $this->asked('homepage@example.com', 'homepage');

        StandCall::open($this->callId, 1);
        $this->drain();

        $this->assertSame(['mine@example.com'], array_column($this->sent, 'to'));
    }

    /** Somebody who unsubscribed is not written to, however they got on the list. */
    public function test_an_unsubscribed_address_is_left_alone(): void
    {
        $this->asked('quiet@example.com');
        $this->asked('loud@example.com');
        EmailOptOut::record('quiet@example.com', 'test');

        $r = StandCall::open($this->callId, 1);
        $this->drain();

        $this->assertSame(1, $r['told']['unsubscribed']);
        $this->assertSame(['loud@example.com'], array_column($this->sent, 'to'));
    }

    /**
     * And one who unsubscribes between the press and the tick is not written to either.
     *
     * The check runs twice on purpose: the queue can hold a message for minutes, and the
     * person who opted out in those minutes did so before it was sent.
     */
    public function test_unsubscribing_after_the_press_still_stops_the_message(): void
    {
        $this->asked('late@example.com');
        StandCall::open($this->callId, 1);

        EmailOptOut::record('late@example.com', 'test');
        $this->drain();

        $this->assertSame([], $this->sent, 'a message queued before the opt-out was sent after it');
    }

    /**
     * Queueing twice queues nothing the second time.
     *
     * The dedupe key is (call, address) rather than (event, address): a market that runs
     * twice a year would otherwise tell its vendors once and never again.
     */
    public function test_the_same_person_is_not_queued_twice_for_one_call(): void
    {
        $this->asked('one@example.com');
        StandCall::open($this->callId, 1);

        $again = StandCallNotice::queueForCall($this->callId);

        $this->assertSame(0, $again['queued']);
        $this->assertSame(1, $again['skipped']);
        $this->assertSame(1, $this->queued());
    }

    /**
     * A call closed again between the press and the tick sends nothing.
     *
     * Announcing an open call to somebody who then follows the link to a closed one is
     * worse than silence.
     */
    public function test_a_call_closed_before_the_tick_is_not_announced(): void
    {
        $this->asked('one@example.com');
        StandCall::open($this->callId, 1);

        DB::table('gates_stand_calls')->where('id', $this->callId)->update(['status' => 'closed']);
        $this->drain();

        $this->assertSame([], $this->sent);
    }

    /**
     * A broken mail queue never costs the operator the open.
     *
     * The call is open the moment the row is written. Whether anybody could be told about
     * it is a separate fact, and it is not allowed to undo the first one.
     */
    public function test_the_call_still_opens_when_the_notice_cannot_be_queued(): void
    {
        $this->asked('one@example.com');
        DB::statement('ALTER TABLE gates_jobs RENAME TO _jobs_hidden');

        try {
            $r = StandCall::open($this->callId, 1);

            $this->assertTrue($r['ok'], 'the open was refused because a mail could not be queued');
            $this->assertSame('open', (string) DB::table('gates_stand_calls')
                ->where('id', $this->callId)->value('status'));
        } finally {
            DB::statement('ALTER TABLE _jobs_hidden RENAME TO gates_jobs');
        }
    }

    /** The source string has one owner, and the form uses it. */
    public function test_the_form_and_the_lookup_agree_on_the_source(): void
    {
        $this->assertSame('stands:market', StandCallNotice::source('market'));

        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/stands/call.twig');
        $this->assertStringContainsString('value="{{ notify_source }}"', $t,
            'the template writes its own copy of the source string');
    }
}
