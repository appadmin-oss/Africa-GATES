<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CrmAudiencePush;
use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\Mail\Suppression;
use AfricaGates\Services\Newsletter\NewsletterAudience;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Africa GATES' people, pushed to the CACENTRE CRM.
 *
 * What this holds: the envelope is the one CACENTRE verifies; every source is read past its
 * cursor and only past it; a cursor moves only once CACENTRE answered 200; a stop, a bounce
 * or a complaint that lands AFTER a subscriber was sent still reaches the CRM; a newsletter
 * row's state is NewsletterAudience's answer; and nothing from the sandbox, no nominee, no
 * message text and no transcript ever leaves.
 */
final class CrmAudiencePushTest extends TestCase
{
    private const SECRET = 'gggggggggggggggggggggggggggggggggggggggggggggg';

    /** @var list<list<array<string,string>>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        CrmAudiencePush::$transport = $this->recorder();
    }

    /** A CACENTRE that accepts every push and remembers what it was sent. */
    private function recorder(): \Closure
    {
        return function (string $url, string $body): array {
            $tok = (string) (json_decode($body, true)['token'] ?? '');
            $this->sent[] = json_decode(self::un(explode('.', $tok)[1] ?? ''), true)['rows'] ?? [];
            return ['code' => 200, 'body' => '{"ok":true}'];
        };
    }

    protected function tearDown(): void
    {
        CrmAudiencePush::$transport = null;
        parent::tearDown();
    }

    private static function un(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private function secret(string $v = self::SECRET): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'crm_intake_secret'], ['value' => $v]);
    }

    private function subscriber(string $email, ?string $confirmed, string $source = 'footer'): void
    {
        DB::table('gates_newsletter')->insert(['email_hash' => EmailOptOut::hash($email), 'email' => $email,
            'source' => $source, 'subscribed_at' => '2026-10-01 09:00:00', 'confirmed_at' => $confirmed]);
    }

    /** @return array<string,array<string,string>> every sent row by ref, last write wins */
    private function byRef(): array
    {
        $out = [];
        foreach ($this->sent as $push) foreach ($push as $r) $out[$r['ref']] = $r;
        return $out;
    }

    private function seed(): void
    {
        $this->subscriber('ada@obi-family.ng', '2026-10-02 09:00:00');
        $this->subscriber('bola@bola-mail.ng', null);
        $this->subscriber('stands@chi-mail.ng', null, 'stands:42');
        $this->subscriber('judge@demo.invalid', '2026-10-02 09:00:00');
        DB::table('gates_partner_enquiries')->insert(['org_name' => 'Bright Futures', 'contact_name' => 'Dayo', 'contact_email' => 'dayo@bright.ng',
            'contact_phone' => '0803 000 1111', 'partnership_type' => 'Sponsor', 'message' => 'PRIVATE WORDS', 'status' => 'new', 'created_at' => '2026-10-03 09:00:00']);
        DB::table('gates_support_tickets')->insert(['reference' => 'SUP-1', 'email' => 'eko@eko-mail.ng', 'name' => 'Eko', 'subject' => 'My vote did not count',
            'transcript' => 'TRANSCRIPT TEXT', 'created_at' => '2026-10-04 09:00:00']);
        DB::table('gates_donations')->insert(['donor_name' => 'Funmi', 'donor_email' => 'funmi@funmi-mail.ng', 'amount_naira' => 20000,
            'status' => 'confirmed', 'confirmed_at' => '2026-10-05 09:00:00', 'created_at' => '2026-10-05 08:59:00']);
        DB::table('gates_donations')->insert(['donor_name' => 'Gbenga', 'donor_email' => 'gbenga@gb-mail.ng', 'amount_naira' => 1000,
            'intent_nominee_id' => 7, 'status' => 'confirmed', 'confirmed_at' => '2026-10-05 10:00:00', 'created_at' => '2026-10-05 09:59:00']);
        DB::table('gates_donations')->insert(['donor_name' => 'Pending', 'donor_email' => 'pending@p-mail.ng', 'amount_naira' => 500,
            'status' => 'pending', 'created_at' => '2026-10-05 11:00:00']);
    }

    public function test_with_no_secret_nothing_is_sent(): void
    {
        $this->seed();
        $r = CrmAudiencePush::run();
        $this->assertTrue($r['ok'] && !empty($r['skipped']));
        $this->assertSame([], $this->sent);
        $this->assertSame(0, CrmAudiencePush::sweep(), 'an unconfigured platform is not a failed task');
    }

    public function test_the_envelope_is_the_one_cacentre_verifies(): void
    {
        $tok = CrmAudiencePush::sign([['kind' => 'subscriber', 'ref' => 'x', 'email' => 'x@x-mail.ng']], self::SECRET, 1_800_000_000);
        $p = explode('.', $tok);
        $claims = json_decode(self::un($p[1]), true);
        $this->assertSame('v1', $p[0]);
        $this->assertTrue(hash_equals(hash_hmac('sha256', $p[1], self::SECRET, true), self::un($p[2])));
        $this->assertSame('gates', $claims['app']);
        $this->assertSame(300, $claims['exp'] - $claims['iat']);
        $this->assertSame(24, strlen((string) $claims['nonce']));
    }

    public function test_every_source_is_sent_and_nothing_private_leaves(): void
    {
        $this->seed();
        $this->secret();
        $r = CrmAudiencePush::run();
        $this->assertTrue($r['ok']);
        $by = $this->byRef();
        $ada = 'news:1';
        $bola = 'news:2';

        $this->assertSame('subscribed', $by[$ada]['status'] ?? null, 'a confirmed subscriber is subscribed');
        $this->assertSame('unconfirmed', $by[$bola]['status'] ?? null, 'an unconfirmed one is never counted as reachable');
        $this->assertSame('enquiry', $by['stand:3']['kind'] ?? null,
            'a stand-call request is an enquiry about one event, not a subscription');
        $this->assertSame('Bright Futures · Sponsor', $by['partner:1']['detail'] ?? null);
        $this->assertSame('My vote did not count', $by['ticket:SUP-1']['detail'] ?? null);
        $this->assertSame('Gave ₦20,000', $by['gift:1']['detail'] ?? null);
        $this->assertSame('customer', $by['gift:2']['kind'] ?? null, 'a vote order is a purchase');
        $this->assertSame('Bought votes, ₦1,000', $by['gift:2']['detail'] ?? null);
        $this->assertArrayNotHasKey('gift:3', $by, 'a pending payment is nobody yet');

        foreach ($this->sent as $push) foreach ($push as $row) {
            $this->assertLessThanOrEqual(64, strlen($row['ref']), 'CACENTRE refuses a ref over 64 characters: ' . $row['ref']);
        }
        $all = json_encode($this->sent);
        $this->assertStringNotContainsString('demo.invalid', $all, 'the sandbox never leaves');
        $this->assertStringNotContainsString('PRIVATE WORDS', $all);
        $this->assertStringNotContainsString('TRANSCRIPT TEXT', $all);
        $this->assertStringNotContainsString('7', (string) json_encode($by['gift:2']), 'nor who the votes were for');
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/CrmAudiencePush.php');
        $this->assertDoesNotMatchRegularExpression("/table\\('gates_nominee/", $src, 'no nominee table is read');
    }

    public function test_a_refused_push_moves_no_cursor_and_the_next_run_sends_only_what_is_new(): void
    {
        $this->seed();
        $this->secret();
        CrmAudiencePush::$transport = static fn() => ['code' => 403, 'body' => '{"ok":false,"error":"bad-signature"}'];
        $r = CrmAudiencePush::run();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('secret here differs', $r['error']);
        $this->assertSame(-1, CrmAudiencePush::sweep(), 'the maintenance tick reports a failed push as a failed task');
        $this->assertSame([], CrmAudiencePush::state());

        CrmAudiencePush::$transport = $this->recorder();
        CrmAudiencePush::run();
        $first = count($this->sent[0] ?? []);
        $this->assertGreaterThan(5, $first);
        $this->sent = [];
        CrmAudiencePush::run();
        $this->assertSame([], $this->sent, 'nothing already sent goes again');

        DB::table('gates_partner_enquiries')->insert(['org_name' => 'New Org', 'contact_name' => 'Hadiza', 'contact_email' => 'hadiza@h-mail.ng',
            'status' => 'new', 'created_at' => '2026-10-06 09:00:00']);
        CrmAudiencePush::run();
        $this->assertSame(['partner:2'], array_column($this->sent[0] ?? [], 'ref'));
    }

    public function test_a_stop_a_bounce_and_a_refund_after_the_fact_still_reach_the_crm(): void
    {
        $this->seed();
        $this->secret();
        CrmAudiencePush::run();
        $this->sent = [];
        $ada = 'news:1';

        EmailOptOut::record('ada@obi-family.ng', 'email-link');
        CrmAudiencePush::run();
        $this->assertSame('unsubscribed', $this->byRef()[$ada]['status'] ?? null, 'a global opt-out re-sends the subscription as stopped');

        $this->sent = [];
        Suppression::record('ada@obi-family.ng', Suppression::COMPLAINT, 'webhook');
        CrmAudiencePush::run();
        $this->assertSame('complained', $this->byRef()[$ada]['status'] ?? null, 'a complaint outranks a preference');

        $this->sent = [];
        DB::table('gates_donations')->where('id', 1)->update(['refunded_at' => '2026-10-09 09:00:00']);
        CrmAudiencePush::run();
        $this->assertSame('closed', $this->byRef()['gift:1']['status'] ?? null);
        $this->assertStringContainsString('refunded', $this->byRef()['gift:1']['detail'] ?? '');
    }

    public function test_state_of_is_the_newsletter_rule_read_for_one_row(): void
    {
        $row = (object) ['email_hash' => 'h', 'confirmed_at' => '2026-10-01', 'unsubscribed_at' => null];
        $this->assertSame('subscribed', NewsletterAudience::stateOf($row, [], []));
        $this->assertSame('unsubscribed', NewsletterAudience::stateOf($row, ['h' => true], []));
        $this->assertSame('bounced', NewsletterAudience::stateOf($row, [], ['h' => Suppression::BOUNCE]));
        $this->assertSame('complained', NewsletterAudience::stateOf($row, ['h' => true], ['h' => Suppression::COMPLAINT]));
        $row->confirmed_at = null;
        $this->assertSame('unconfirmed', NewsletterAudience::stateOf($row, [], []));

        // And it agrees with recipients(): exactly the 'subscribed' rows are the list.
        $this->subscriber('one@one-mail.ng', '2026-10-01 09:00:00');
        $this->subscriber('two@two-mail.ng', null);
        $this->subscriber('three@three-mail.ng', '2026-10-01 09:00:00');
        EmailOptOut::record('three@three-mail.ng');
        $opt = EmailOptOut::suppressedHashes(); $sup = Suppression::hashes();
        $subscribed = [];
        foreach (DB::table('gates_newsletter')->get() as $r) {
            if (NewsletterAudience::stateOf($r, $opt, $sup) === 'subscribed') $subscribed[] = (string) $r->email;
        }
        $this->assertSame(array_column(NewsletterAudience::recipients(), 'email'), $subscribed);
    }

    public function test_a_row_cacentre_refuses_by_name_is_said_not_counted_as_sent(): void
    {
        $this->seed();
        $this->secret();
        CrmAudiencePush::$transport = static fn() => ['code' => 200, 'body' => '{"ok":true,"refused":[{"ref":"gift:1","why":"bad-ref"}]}'];
        $r = CrmAudiencePush::run();
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['refused']);
        $this->assertStringContainsString('1 refused by CACENTRE', CrmAudiencePush::lastResult());
    }

    public function test_settings_resolve_from_the_screen_and_refuse_a_path(): void
    {
        $this->assertSame('https://cacentre.afrovanguard.org.ng/api/crm-intake.php', CrmAudiencePush::url());
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'crm_intake_url'], ['value' => 'https://crm.example-centre.ng/']);
        $this->assertSame('https://crm.example-centre.ng/api/crm-intake.php', CrmAudiencePush::url());
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'crm_intake_url'], ['value' => 'https://crm.example-centre.ng/some/page']);
        $this->assertSame('https://cacentre.afrovanguard.org.ng/api/crm-intake.php', CrmAudiencePush::url(), 'a path is not an origin');
        $this->assertFalse(CrmAudiencePush::ready());
        $this->secret();
        $this->assertTrue(CrmAudiencePush::ready());
    }
}
