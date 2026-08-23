<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EmailOptOut, OrgAuth, PartnerOrg, QueueService, StandApplication,
                          StandCall, StandNotice, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Telling a vendor what happened to their application.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG, AND WHY IT WAS THE WORST THING IN THIS SUBSYSTEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There was no mail. Not on an offer, not on a rejection, not on a waitlisting, and not
 * when an offer expired. A decision was written to the table and that was the end of it.
 *
 * The offer path is the one that actually costs somebody something. Pressing Record starts
 * a 72-hour acceptance clock; the vendor could only discover it by logging in unprompted;
 * and `Maintenance::expireStandOffers()` then gave the pitch to the waiting list. So the
 * most likely outcome of a SUCCESSFUL application was silently losing the place.
 *
 * The public form promises, in these words: "You hear either way, with a reason." That is
 * the sentence these tests exist to make true — so most of them are about the promise
 * rather than about the mechanism.
 */
final class StandNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_jobs')->where('type', StandNotice::JOB_NOTICE)->delete();
        DB::table('gates_broadcast_log')->where('campaign', 'like', 'stand:%')->delete();
    }

    // ───────────────────────────────── fixtures ─────────────────────────────

    private function event(): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'market-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+60 days')), 'status' => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    private function vendor(): int
    {
        $id = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'adaeze-' . bin2hex(random_bytes(4)),
            'name' => 'Adaeze Foods', 'legal_name' => 'Adaeze Foods Limited',
            'kind' => PartnerOrg::KIND_VENDOR, 'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => 'BN9988', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_v', 'contact_phone' => '08031234567',
            'contact_email' => 'adaeze-' . bin2hex(random_bytes(4)) . '@example.test',
            'contact_name' => 'Adaeze Nwosu',
        ]);
        foreach (array_keys(PartnerOrg::requiredDocuments($id)) as $kind) {
            DB::table('gates_org_documents')->insert([
                'org_id' => $id, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $id;
    }

    /** An open call with one type of `$quota` places, and one eligible application in it. */
    private function applied(int $quota = 2): array
    {
        $event = $this->event();
        $t = StandType::save((int) $event->id, [
            'name' => 'Food pitch', 'category' => 'food', 'price_naira' => '50000',
            'deposit_naira' => '10000', 'quota' => (string) $quota, 'size_preset' => '3x3',
        ]);
        $this->assertTrue($t['ok'], $t['message'] ?? '');

        $c = StandCall::save((int) $event->id, [
            'intro' => 'We are looking for cooks who can feed four hundred people.',
            'closes_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ]);
        $this->assertTrue(StandCall::open($c['id'], 1)['ok']);

        $orgId = $this->vendor();
        $app   = StandApplication::submit($orgId, (int) $t['id'], ['what_they_sell' => 'Jollof.']);
        $this->assertTrue($app['ok'], $app['message'] ?? '');
        StandApplication::checkEligibility((int) $app['id']);

        return ['event' => $event, 'type_id' => (int) $t['id'],
                'org_id' => $orgId, 'app_id' => (int) $app['id']];
    }

    /** @return list<array<string,mixed>> the queued notice payloads */
    private function queued(): array
    {
        $out = [];
        foreach (DB::table('gates_jobs')->where('type', StandNotice::JOB_NOTICE)
                   ->orderBy('id')->get() as $j) {
            $p = json_decode((string) $j->payload, true);
            if (is_array($p)) $out[] = $p + ['dedupe_key' => (string) ($j->dedupe_key ?? '')];
        }
        return $out;
    }

    // ══ the promise: you hear either way ═════════════════════════════════════

    public function test_an_offer_queues_a_message_because_the_clock_is_already_running(): void
    {
        $f = $this->applied();
        $this->assertTrue(StandApplication::offer($f['app_id'], 1)['ok']);

        $q = $this->queued();
        $this->assertCount(1, $q, 'an offer with a 72-hour clock and no message is a lost pitch');
        $this->assertSame('offered', $q[0]['kind']);
        $this->assertSame($f['app_id'], $q[0]['app_id']);
    }

    public function test_a_rejection_queues_the_reason_it_was_refused_without(): void
    {
        // decide() refuses a rejection with no reason, precisely so the applicant is owed an
        // explanation. That reason was then stored and never sent to them.
        $f = $this->applied();
        $this->assertTrue(StandApplication::decide(
            $f['app_id'], 'rejected', 1, 'Two other food vendors scored higher on the '
            . 'published criteria for menu range.')['ok']);

        $q = $this->queued();
        $this->assertCount(1, $q);
        $this->assertSame('rejected', $q[0]['kind']);
    }

    public function test_a_waitlisting_queues_a_message(): void
    {
        $f = $this->applied();
        $this->assertTrue(StandApplication::decide($f['app_id'], 'waitlisted', 1)['ok']);
        $this->assertSame('waitlisted', $this->queued()[0]['kind'] ?? '');
    }

    public function test_an_expiring_offer_tells_them_it_expired_and_not_that_they_are_waitlisted(): void
    {
        // The stored decision for both is `waitlisted`, but the message a person needs is
        // completely different: "you are on the waiting list", to somebody who was offered a
        // pitch and lost it, reads as though we never offered it.
        $f = $this->applied();
        StandApplication::offer($f['app_id'], 1);
        DB::table('gates_jobs')->where('type', StandNotice::JOB_NOTICE)->delete();

        DB::table('gates_stand_applications')->where('id', $f['app_id'])
            ->update(['offer_expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);

        $this->assertSame(1, StandApplication::expireStaleOffers());
        $this->assertSame('expired', $this->queued()[0]['kind'] ?? '');
    }

    public function test_a_vendor_accepting_gets_no_pointless_receipt(): void
    {
        // They pressed the button and watched the screen change. A mail saying "you
        // accepted" tells them what they already know, and every message that says nothing
        // makes the next one that matters slightly less likely to be opened.
        $f = $this->applied();
        StandApplication::offer($f['app_id'], 1);
        DB::table('gates_jobs')->where('type', StandNotice::JOB_NOTICE)->delete();

        $this->assertTrue(StandApplication::accept($f['app_id'], $f['org_id'])['ok']);
        $this->assertSame([], $this->queued());
    }

    // ══ once, and once only ══════════════════════════════════════════════════

    public function test_pressing_record_twice_does_not_send_two_offers(): void
    {
        $f = $this->applied();
        StandApplication::offer($f['app_id'], 1);
        // The second call is refused by offer() itself ("already holds a place"), but the
        // dedupe key is what protects against every other double-submit route.
        StandNotice::queue($f['app_id'], 'offered');
        StandNotice::queue($f['app_id'], 'offered');

        $this->assertCount(1, $this->queued());
    }

    public function test_the_dedupe_key_carries_the_outcome_so_a_second_offer_still_sends(): void
    {
        // Offered, expired, offered again is a real sequence and all three are news. Keyed on
        // the application alone, the vendor would hear about one of the three.
        $f = $this->applied();
        StandNotice::queue($f['app_id'], 'offered');
        StandNotice::queue($f['app_id'], 'expired');

        $keys = array_column($this->queued(), 'dedupe_key');
        $this->assertCount(2, array_unique($keys));
        foreach ($keys as $k) $this->assertStringContainsString((string) $f['app_id'], $k);
    }

    // ══ what the message says ════════════════════════════════════════════════

    /** @return array<string,mixed> */
    private function vars(int $appId, string $kind): array
    {
        $app = DB::table('gates_stand_applications')->where('id', $appId)->first();
        $ctx = [
            'app'   => $app,
            'org'   => DB::table('gates_partner_orgs')->where('id', $app->org_id)->first(),
            'type'  => DB::table('gates_stand_types')->where('id', $app->stand_type_id)->first(),
            'event' => DB::table('gates_site_events')->where('id', $app->event_id)->first(),
        ];
        return StandNotice::vars($ctx, $kind, 'https://example.test', 'v@example.test');
    }

    public function test_the_offer_carries_the_deadline_the_price_and_the_deposit(): void
    {
        $f = $this->applied();
        StandApplication::offer($f['app_id'], 1);

        $v = $this->vars($f['app_id'], 'offered');
        $this->assertNotSame('', $v['expires_at'], 'an offer without its deadline is the bug');
        $this->assertSame(72, $v['offer_hours']);
        $this->assertSame('₦50,000', $v['price']);
        $this->assertSame('₦10,000', $v['deposit']);

        $html = StandNotice::html($v);
        $this->assertStringContainsString($v['expires_at'], $html);
        $this->assertStringContainsString('₦50,000', $html);
        $this->assertStringContainsString('Accept my stand', $html);

        $text = StandNotice::plain($v);
        $this->assertStringContainsString($v['expires_at'], $text);
        // The link as text too. A client that mangles the button must not be the thing that
        // costs somebody a pitch.
        $this->assertStringContainsString('https://example.test/org', $text);
    }

    public function test_the_rejection_quotes_the_panel_rather_than_paraphrasing_it(): void
    {
        $f      = $this->applied();
        $reason = 'Two other food vendors scored higher on menu range.';
        StandApplication::decide($f['app_id'], 'rejected', 1, $reason);

        $v = $this->vars($f['app_id'], 'rejected');
        $this->assertSame($reason, $v['reason']);
        $this->assertStringContainsString($reason, StandNotice::html($v));
        $this->assertStringContainsString($reason, StandNotice::plain($v));
        // No clock and no accept button on a rejection.
        $this->assertStringNotContainsString('Accept my stand', StandNotice::html($v));
    }

    public function test_a_foot_sized_pitch_is_named_in_feet_in_the_message_too(): void
    {
        // The vendor applied for "6 × 6 ft". A mail describing it as 1.83 × 1.83 m is
        // describing something they did not agree to.
        $event = $this->event();
        $t = StandType::save((int) $event->id, [
            'name' => '6 × 6 ft stand', 'category' => 'general', 'price_naira' => '10000',
            'quota' => '5', 'width_cm' => '183', 'depth_cm' => '183', 'size_preset' => 'custom',
        ]);
        $this->assertTrue($t['ok'], $t['message'] ?? '');
        $c = StandCall::save((int) $event->id, ['intro' => 'A market for makers.',
            'closes_at' => date('Y-m-d H:i:s', strtotime('+14 days'))]);
        StandCall::open($c['id'], 1);

        $app = StandApplication::submit($this->vendor(), (int) $t['id'],
                                       ['what_they_sell' => 'Beadwork.']);
        $v = $this->vars((int) $app['id'], 'waitlisted');

        $this->assertSame('6 × 6 ft', $v['stand_size']);
    }

    public function test_the_message_names_a_person_and_never_their_id_document_name(): void
    {
        // legal_name on an individual is the name off a photo ID. Opening a mail with it
        // reads like a letter from a bank about a debt.
        $f = $this->applied();
        StandApplication::decide($f['app_id'], 'waitlisted', 1);
        $v = $this->vars($f['app_id'], 'waitlisted');

        $this->assertSame('Adaeze', $v['first_name']);
        $this->assertStringNotContainsString('Limited', StandNotice::html($v));
    }

    public function test_every_outcome_renders_without_a_missing_variable(): void
    {
        $f = $this->applied();
        StandApplication::offer($f['app_id'], 1);

        foreach (array_keys(StandNotice::KINDS) as $kind) {
            $v = $this->vars($f['app_id'], $kind);
            $html = StandNotice::html($v);
            $this->assertStringContainsString('Africa G.A.T.E.S.', $html, $kind);
            $this->assertStringNotContainsString('{{', $html, $kind);
            $this->assertNotSame('', trim(StandNotice::plain($v)), $kind);
            // Bulk-sender rules do not read intent, and a message in Spam has not been sent.
            $this->assertStringContainsString('Unsubscribe', $html, $kind);
        }
    }

    // ══ suppression: news respects it, consequences do not ═══════════════════

    public function test_an_opt_out_does_not_cost_somebody_the_pitch_they_applied_for(): void
    {
        // An opt-out is a request to stop being marketed at. It is not a request to forfeit a
        // stand — and an unsent offer expires, which is unrecoverable.
        $f = $this->applied();
        $email = (string) DB::table('gates_partner_orgs')->where('id', $f['org_id'])
            ->value('contact_email');
        EmailOptOut::record($email, 'test');
        $this->assertTrue(EmailOptOut::suppressed($email));

        StandApplication::offer($f['app_id'], 1);

        // Queued regardless. deliver() is where suppression is weighed, and it lets an offer
        // and a rejection through — the two outcomes a person is waiting on.
        $this->assertCount(1, $this->queued());
    }

    // ══ the job is actually wired ════════════════════════════════════════════

    public function test_the_queued_job_has_a_handler_registered_on_the_tick(): void
    {
        // There is no worker process on this host, so an unregistered job type is a queue
        // that fills for ever. A pruner nobody calls and a job nobody handles are the same
        // class of bug and this platform has shipped both.
        $m = file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');
        $this->assertStringContainsString('StandNotice::JOB_NOTICE', $m);
        $this->assertStringContainsString('StandNotice::deliver', $m);
    }

    public function test_delivering_with_no_mailer_throws_rather_than_eating_the_message(): void
    {
        // Returning false would mark the job DONE. A deployment with briefly broken SMTP
        // would consume every pending offer notice and log them as handled — and the
        // vendors would lose their pitches to a config typo.
        $this->expectException(\RuntimeException::class);
        StandNotice::deliver(['app_id' => 1, 'kind' => 'offered'], null);
    }

    public function test_a_decision_reversed_before_the_tick_does_not_send_the_old_message(): void
    {
        // deliver() re-reads the row rather than trusting the payload. A vendor who has
        // already accepted must not then receive a mail with a countdown in it.
        $f = $this->applied();
        StandApplication::offer($f['app_id'], 1);
        StandApplication::accept($f['app_id'], $f['org_id']);

        $mailer = new \AfricaGates\Services\OtpService(['host' => '', 'user' => '', 'pass' => '']);
        $this->assertFalse(
            StandNotice::deliver(['app_id' => $f['app_id'], 'kind' => 'offered'], $mailer),
            'the offer was accepted, so the offer message is no longer true'
        );
    }
}
