<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteLetter;
use AfricaGates\Services\InviteMailer;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What a guest of honour actually receives.
 *
 * The properties held here are the ones nobody can check by reading the code and nobody
 * will check by eye on four hundred letters: that the number promised in the email is the
 * number the code allows, that a formal letter is attached and carries the right person's
 * reference, that an opted-out address is not written to, and that running the send twice
 * does not write to anybody twice.
 *
 * The MARKUP half — whether this survives Outlook — is not tested here and does not need
 * to be: the invitation renders through `templates/emails/campaign.twig`, so
 * {@see CampaignInboxCompatTest} already holds all twelve inbox properties for it. That
 * is the whole reason it is a block list rather than a thirteenth template.
 */
final class InviteMailerTest extends TestCase
{
    private int $eventId = 0;
    private object $event;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'Africa GATES Gala 2026', 'programme_id' => $pid,
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published',
            'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $this->eventId, 'slug' => 'supporter', 'name' => 'Supporter',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->event = DB::table('gates_site_events')->where('id', $this->eventId)->first();
    }

    private function invite(string $audience = InviteAudience::PRINCIPAL, string $email = 'ada@example.com'): object
    {
        return EventInvites::mint($this->eventId, $audience,
            ['name' => 'Ada Obi', 'email' => $email, 'nominee_id' => 0, 'judge_id' => 0]);
    }

    /** A transport that records instead of dialling out. */
    private function recorder(): OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25,
                          'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function smtpConfigured(): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = []): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'category', 'attachments');

                return ['success' => true];
            }
        };
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_the_invitation_names_the_person_the_evening_and_the_ask(): void
    {
        $inv = $this->invite();
        $m   = $this->recorder();

        $r = InviteMailer::send($inv, $this->event, $m);

        $this->assertTrue($r['ok'], $r['error']);
        $this->assertCount(1, $m->sent);

        $sent = $m->sent[0];
        $this->assertSame('ada@example.com', $sent['to']);
        $this->assertStringContainsString('Ada Obi', $sent['subject'],
            'this is an invitation to one person, not a campaign — the inbox must read that way');
        $this->assertStringContainsString('Africa GATES Gala 2026', $sent['subject']);

        $html = $sent['htmlBody'];
        $this->assertStringContainsString('Eko Convention Centre', $html);
        $this->assertStringContainsString((string) $inv->reference, $html, 'their guest code');
        $this->assertStringContainsString('25', $html, 'the quota promised');
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('Supporter', $html, 'seats start at the cheapest PAID tier');
        $this->assertStringContainsString('/honour/' . $inv->reference, $html, 'the pass is a link');
    }

    /** Each audience is told why the room is being filled for them, in their own words. */
    public function test_each_audience_is_given_its_own_reason(): void
    {
        $cases = [
            InviteAudience::PRINCIPAL => 'resilience',
            InviteAudience::CHILD     => 'incorruptible life',
            InviteAudience::JUDGE     => 'integrity of your judgement',
        ];

        foreach ($cases as $audience => $phrase) {
            $m   = $this->recorder();
            $inv = $this->invite($audience, $audience . '@example.com');
            InviteMailer::send($inv, $this->event, $m);

            $this->assertStringContainsString($phrase, $m->sent[0]['htmlBody'],
                $audience . ' was sent somebody else\'s reason');
        }
    }

    public function test_the_formal_letter_is_attached_and_carries_their_reference(): void
    {
        $inv = $this->invite();
        $m   = $this->recorder();

        InviteMailer::send($inv, $this->event, $m);
        $files = $m->sent[0]['attachments'];

        $this->assertCount(1, $files, 'the letter, and no cover image on this event');
        $this->assertSame('application/pdf', $files[0]['mime']);
        $this->assertStringStartsWith('%PDF-', $files[0]['body'], 'that is not a PDF');
        $this->assertStringContainsString(strtolower((string) $inv->reference), $files[0]['name'],
            'the filename must identify whose invitation it is');
        $this->assertGreaterThan(2000, strlen($files[0]['body']), 'a one-page letter is not 2kB of nothing');
    }

    /**
     * The rotating code must never reach a file. A document showing an expired pass is
     * worse than one showing none: somebody holds it up and is turned away by a letter
     * telling them they were invited.
     */
    public function test_the_letter_carries_the_reference_but_never_a_rotating_code(): void
    {
        $inv = $this->invite();
        $pdf = InviteLetter::render($inv, $this->event, EventInvites::lowestTier($this->eventId));

        // The window number appears in every rotating code and in no reference.
        $this->assertStringNotContainsString(
            (string) \AfricaGates\Services\InvitePass::window(), $pdf,
            'a rotating window number is in the PDF — it will be false within a minute'
        );
    }

    public function test_an_opted_out_address_is_never_written_to(): void
    {
        $inv = $this->invite();
        EmailOptOut::record('ada@example.com', 'test');
        $m = $this->recorder();

        $r = InviteMailer::send($inv, $this->event, $m);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['skipped']);
        $this->assertSame([], $m->sent);
    }

    /**
     * A second run must not write to anybody twice. A duplicate invitation is not a
     * duplicate newsletter — it is a second personal letter to somebody who already
     * replied to the first.
     */
    public function test_running_the_send_twice_writes_once(): void
    {
        $inv = $this->invite();

        $first  = InviteMailer::send($inv, $this->event, $m1 = $this->recorder());
        $second = InviteMailer::send($inv, $this->event, $m2 = $this->recorder());

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertTrue($second['skipped']);
        $this->assertCount(1, $m1->sent);
        $this->assertSame([], $m2->sent);
    }

    public function test_a_successful_send_is_stamped_on_the_invitation(): void
    {
        $inv = $this->invite();
        $this->assertNull($inv->sent_at);

        InviteMailer::send($inv, $this->event, $this->recorder());

        $this->assertNotNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('sent_at'));
        $this->assertSame('sent', (string) DB::table('gates_broadcast_log')
            ->where('campaign', InviteMailer::campaignKey($this->eventId))->value('status'));
    }

    /** A judge's log row must not carry a nominee id it does not have. */
    public function test_a_judges_log_row_has_no_nominee_id(): void
    {
        InviteMailer::send($this->invite(InviteAudience::JUDGE, 'judge@example.com'),
                           $this->event, $this->recorder());

        $this->assertNull(DB::table('gates_broadcast_log')
            ->where('email', 'judge@example.com')->value('nominee_id'));
    }

    /** The preview renders without sending anything at all. */
    public function test_the_preview_sends_nothing(): void
    {
        $inv  = $this->invite();
        $html = InviteMailer::preview($inv, $this->event);

        $this->assertStringContainsString((string) $inv->reference, $html);
        $this->assertSame(0, (int) DB::table('gates_broadcast_log')->count());
        $this->assertNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('sent_at'));
    }

    /**
     * `cover_image` is an admin-entered column. An attachment builder that resolves
     * whatever string it is handed is a file-disclosure primitive pointed at its own mail
     * queue, so the path is checked against the web root rather than trusted.
     */
    public function test_a_traversing_cover_path_is_not_attached(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['cover_image' => '../../.env']);
        $event = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $m = $this->recorder();
        InviteMailer::send($this->invite(), $event, $m);

        $names = array_column($m->sent[0]['attachments'], 'name');
        $this->assertSame([], array_filter($names, fn ($n) => str_contains((string) $n, 'env')),
            'a path outside public/ was attached to an outgoing email');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  A ROUTE IN — CODEBASE-INDEX §18
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Every configurable this feature reads has a field that sets it.
     *
     * §18 is the sibling of the no-reader bug: a mechanism that is complete and correct
     * and that nothing can reach. Quotas and the discount are read out of `gates_settings`
     * by {@see InviteAudience}, so a form that cannot write them makes the defaults the
     * only values the platform will ever have.
     */
    public function test_every_invitation_setting_has_a_field_that_sets_it(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        foreach (['invite_quota_principal', 'invite_quota_child', 'invite_quota_judge',
                  'invite_discount_percent', 'invite_programme_principal',
                  'invite_programme_child'] as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $form,
                $key . ' is read by InviteAudience but nothing can set it');
        }
    }

    /** And the run itself is reachable from the event it belongs to. */
    public function test_the_invitation_run_is_reachable_from_the_event(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        $this->assertStringContainsString('/invites', $form,
            'the invitation run has no link from the event screen');
        $this->assertStringContainsString('name="programme_id"', $form,
            'without this field no event can be marked as a ceremony, so nobody is ever invited');
    }

    /** The three admin actions exist as routes. */
    public function test_the_admin_actions_are_routed(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        foreach (["/events/{id:[0-9]+}/invites'",
                  "/events/{id:[0-9]+}/invites/build'",
                  "/events/{id:[0-9]+}/invites/send'"] as $path) {
            $this->assertStringContainsString($path, $routes, $path . ' is not routed');
        }
    }
}
