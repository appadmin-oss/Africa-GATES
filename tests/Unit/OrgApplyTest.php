<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgAuth, PartnerOrg, RegistryCheck};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Organisations applying to raise gifts, and the register searched from our own screen.
 *
 * ── WHAT THE APPLICATION FORM CHANGES, AND WHAT IT MUST NOT ──────────────────
 *
 * Until it existed, every organisation here was typed in by an administrator — which is not
 * a high bar, it is a NARROW one: the only bodies that could raise money were the ones that
 * already knew somebody. So most of what follows checks that opening the door did not lower
 * the standard behind it. A self-registered organisation is a draft that can sign in, upload
 * certificates and read its own decision, and nothing else.
 */
class OrgApplyTest extends TestCase
{
    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    private function ctrl(): \AfricaGates\Controllers\OrgApplyController
    {
        return $this->container()->get(\AfricaGates\Controllers\OrgApplyController::class);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['org_user_id'], $_SESSION['org_id'],
              $_SESSION['org_flash_ok'], $_SESSION['org_flash_error']);
        parent::tearDown();
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name'          => 'Bright Futures Initiative',
            'legal_name'    => 'Bright Futures Initiative',
            'cac_number'    => 'IT/1234567',
            'scuml_number'  => 'SC-9988',
            'contact_name'  => 'Adaeze Okonkwo',
            'contact_email' => 'bf-' . bin2hex(random_bytes(4)) . '@example.test',
            'contact_phone' => '08030000000',
            'password'      => 'correct horse battery',
            'description'   => 'Scholarships and mentoring in Lagos State.',
        ];
    }

    // ─────────────────────────────── applying ───────────────────────────────

    public function test_an_organisation_can_apply_and_lands_on_its_dashboard(): void
    {
        $in  = $this->form();
        $res = $this->ctrl()->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/gift/apply')->withParsedBody($in),
            new Response()
        );

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/org', $res->getHeaderLine('Location'));

        $user = OrgAuth::findByEmail($in['contact_email']);
        $this->assertNotNull($user);
        $this->assertSame('owner', $user->role);
        $this->assertSame((int) $user->id, OrgAuth::userId(), 'They must be signed in, not bounced.');

        $org = PartnerOrg::find((int) $user->org_id);
        $this->assertSame(PartnerOrg::KIND_PARTNER, $org->kind);
        $this->assertSame(PartnerOrg::STATUS_DRAFT, $org->status);
        $this->assertSame(1, (int) $org->self_registered);
    }

    /**
     * Applying buys a place in a queue and nothing else.
     *
     * No public listing, no collecting, no appeal. If any of that came with registration, a
     * stranger could put the platform's name behind their own fundraising in ninety seconds.
     */
    public function test_applying_grants_no_ability_to_collect(): void
    {
        $in = $this->form();
        $this->ctrl()->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody($in),
            new Response()
        );

        $org = PartnerOrg::find((int) OrgAuth::findByEmail($in['contact_email'])->org_id);
        $this->assertFalse(PartnerOrg::canReceive($org));
        $this->assertNotContains($org->slug,
            array_map(static fn($p) => $p->slug, PartnerOrg::listReceivable()),
            'A self-registered draft must not appear on the public list.');
    }

    /**
     * And it still cannot be approved without everything an administrator would have demanded.
     *
     * The form is a different door, not a different standard.
     */
    public function test_the_vetting_standard_is_unchanged(): void
    {
        $in = $this->form(['scuml_number' => '']);
        $this->ctrl()->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody($in),
            new Response()
        );
        $id = (int) OrgAuth::findByEmail($in['contact_email'])->org_id;

        // A settlement account first, so the assertion lands on the SCUML rule rather than on
        // the earlier refusal — both are part of the standard and only one is under test here.
        DB::table('gates_partner_orgs')->where('id', $id)->update([
            'subaccount_code' => 'ACCT_x', 'account_name_resolved' => 'BRIGHT FUTURES INITIATIVE',
        ]);

        $r = PartnerOrg::approve($id, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('SCUML', $r['message']);
    }

    /**
     * A body collecting charitable gifts in Nigeria has to be incorporated.
     *
     * This is the one requirement that is not ours — an unregistered group asking the public
     * for money is precisely what this platform must never put its name behind.
     */
    public function test_a_cac_number_is_required(): void
    {
        $r = PartnerOrg::registerPartner($this->form(['cac_number' => '']));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('incorporated', $r['message']);
    }

    public function test_a_failed_login_creation_leaves_nothing_behind(): void
    {
        $before = (int) DB::table('gates_partner_orgs')->count();

        $r = PartnerOrg::registerPartner($this->form(['password' => 'short']));
        $this->assertFalse($r['ok']);
        $this->assertSame($before, (int) DB::table('gates_partner_orgs')->count(),
            'A half-built organisation is one an administrator eventually approves by accident.');
    }

    /** Somebody already signed in has an organisation; a second one is a duplicate. */
    public function test_a_signed_in_user_is_sent_to_their_dashboard(): void
    {
        $in = $this->form();
        $this->ctrl()->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')->withParsedBody($in),
            new Response()
        );
        $before = (int) DB::table('gates_partner_orgs')->count();

        $res = $this->ctrl()->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')
                ->withParsedBody($this->form()),
            new Response()
        );

        $this->assertSame('/org', $res->getHeaderLine('Location'));
        $this->assertSame($before, (int) DB::table('gates_partner_orgs')->count());
    }

    /** A bad detail must not cost them the other nine fields. */
    public function test_a_rejected_form_comes_back_filled_in(): void
    {
        $html = (string) $this->ctrl()->submit(
            (new ServerRequestFactory())->createServerRequest('POST', '/x')
                ->withParsedBody($this->form(['password' => 'tooshort'])),
            new Response()
        )->getBody();

        $this->assertStringContainsString('at least 12 characters', $html);
        $this->assertStringContainsString('Bright Futures Initiative', $html);
        $this->assertStringContainsString('IT/1234567', $html);
    }

    /** The requirements are on the page, above the form, not behind a link. */
    public function test_the_page_states_what_it_will_ask_for(): void
    {
        $html = (string) $this->ctrl()->form(
            (new ServerRequestFactory())->createServerRequest('GET', '/gift/apply'), new Response()
        )->getBody();

        foreach (['CAC registration', 'SCUML', 'own registered name', 'with a reason'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    // ──────────────────────────────── the stats ─────────────────────────────

    /**
     * The applied count includes everybody, not only the ones who got through.
     *
     * A page reporting successes alone is claiming a 100% acceptance rate for a process that
     * refuses people, on a page asking strangers to trust it.
     */
    public function test_the_applied_count_is_the_honest_denominator(): void
    {
        $mk = function (string $status) {
            DB::table('gates_partner_orgs')->insert([
                'slug' => 'p-' . bin2hex(random_bytes(4)), 'name' => 'Org',
                'kind' => PartnerOrg::KIND_PARTNER, 'status' => $status,
            ]);
        };
        $before = PartnerOrg::platformTotals();

        $mk(PartnerOrg::STATUS_APPROVED);
        $mk(PartnerOrg::STATUS_DRAFT);
        $mk(PartnerOrg::STATUS_REJECTED);

        $after = PartnerOrg::platformTotals();
        $this->assertSame($before['orgs'] + 3, $after['orgs']);
        $this->assertSame($before['approved'] + 1, $after['approved']);
    }

    /** Vendors are not counted. They do not raise gifts and never appear on that page. */
    public function test_vendors_are_not_counted_as_applicants(): void
    {
        $before = PartnerOrg::platformTotals()['orgs'];
        DB::table('gates_partner_orgs')->insert([
            'slug' => 'v-' . bin2hex(random_bytes(4)), 'name' => 'Adaeze Foods',
            'kind' => PartnerOrg::KIND_VENDOR, 'status' => PartnerOrg::STATUS_APPROVED,
        ]);
        $this->assertSame($before, PartnerOrg::platformTotals()['orgs']);
    }

    /** Funds generated counts CONFIRMED gifts to organisations, and nothing else. */
    public function test_funds_generated_counts_only_confirmed_gifts_to_organisations(): void
    {
        $orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'bf-' . bin2hex(random_bytes(4)), 'name' => 'Bright Futures',
            'kind' => PartnerOrg::KIND_PARTNER, 'status' => PartnerOrg::STATUS_APPROVED,
        ]);
        $before = PartnerOrg::platformTotals();

        $gift = function (?int $org, string $status, int $amount) {
            DB::table('gates_donations')->insert([
                'donor_name' => 'A Giver', 'donor_email' => 'g@example.test',
                'payment_ref' => 'g-' . bin2hex(random_bytes(5)),
                'recipient_org_id' => $org, 'amount_naira' => $amount,
                'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        };
        $gift($orgId, 'confirmed', 50000);
        $gift($orgId, 'pending',   90000);   // not money yet
        $gift(null,   'confirmed', 70000);   // given to Africa GATES, not to an organisation

        $after = PartnerOrg::platformTotals();
        $this->assertSame($before['raised'] + 50000, $after['raised']);
        $this->assertSame($before['gifts'] + 1, $after['gifts']);
    }

    // ───────────────────── searching the register from here ─────────────────

    /** A stub standing in for whatever endpoint an operator has pointed this at. */
    private function http(string $body): callable
    {
        return static fn(string $url, string $key): string => $body;
    }

    public function test_a_search_reads_results_out_of_a_wrapped_payload(): void
    {
        $body = json_encode(['data' => ['items' => [
            ['companyName' => 'BRIGHT FUTURES INITIATIVE', 'rcNumber' => 'IT/1234567',
             'classification' => 'INCORPORATED TRUSTEES', 'companyStatus' => 'ACTIVE',
             'address' => '12 Marina, Lagos'],
        ]]]);

        $r = RegistryCheck::searchCac('bright futures', $this->http((string) $body));
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['results']);
        $this->assertSame('BRIGHT FUTURES INITIATIVE', $r['results'][0]['name']);
        $this->assertSame('IT/1234567', $r['results'][0]['rc']);
    }

    /** Providers nest differently and rename fields; the parser is loose on purpose. */
    public function test_a_flat_list_with_other_field_names_also_reads(): void
    {
        $body = json_encode([
            ['approvedName' => 'HOPE TRUST', 'registrationNumber' => 'IT/999', 'status' => 'ACTIVE'],
        ]);

        $r = RegistryCheck::searchCac('hope', $this->http((string) $body));
        $this->assertTrue($r['ok']);
        $this->assertSame('HOPE TRUST', $r['results'][0]['name']);
        $this->assertSame('IT/999', $r['results'][0]['rc']);
    }

    /**
     * Unreachable is UNKNOWN, never "no such company".
     *
     * On a screen where somebody is deciding whether a charity is real, an outage that reads
     * as a refusal is the most dangerous failure available.
     */
    public function test_an_unreachable_register_does_not_read_as_a_refusal(): void
    {
        $r = RegistryCheck::searchCac('bright futures', static function (): string {
            throw new \RuntimeException('connection timed out');
        });

        $this->assertFalse($r['ok']);
        $this->assertFalse($r['live']);
        $this->assertSame([], $r['results']);
        $this->assertStringContainsString('not the same as the company not existing', $r['message']);
    }

    public function test_an_empty_result_is_distinguished_from_a_failure(): void
    {
        $r = RegistryCheck::searchCac('zzzz', $this->http('{"data":[]}'));
        $this->assertTrue($r['ok'], 'The register answered — it just had nothing.');
        $this->assertTrue($r['live']);
        $this->assertSame([], $r['results']);
    }

    public function test_unreadable_output_is_not_treated_as_an_answer(): void
    {
        $r = RegistryCheck::searchCac('bright', $this->http('<html>Cloudflare</html>'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('could not read', $r['message']);
    }

    /** A one-letter query is refused before any outbound call is made. */
    public function test_a_short_query_never_reaches_the_network(): void
    {
        $called = false;
        $r = RegistryCheck::searchCac('a', function () use (&$called): string {
            $called = true;
            return '{}';
        });

        $this->assertFalse($r['ok']);
        $this->assertFalse($called);
    }

    /** A row with no name is not a candidate — it is noise a reviewer would have to discount. */
    public function test_rows_without_a_name_are_dropped(): void
    {
        $body = json_encode(['results' => [
            ['rcNumber' => 'IT/1'],
            ['companyName' => 'REAL TRUST', 'rcNumber' => 'IT/2'],
        ]]);

        $r = RegistryCheck::searchCac('trust', $this->http((string) $body));
        $this->assertCount(1, $r['results']);
        $this->assertSame('REAL TRUST', $r['results'][0]['name']);
    }
}
