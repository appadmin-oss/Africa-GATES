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
 * ── THE DOOR MOVED; THESE ASSERTIONS DID NOT ─────────────────────────────────
 *
 * The application was `/giving/apply`, with a controller of its own. It is the
 * `?as=organisation` branch of `/account/register` now — one registration door rather than
 * two, which is what the chooser there was built to be. Everything below is pointed at the
 * new handler and otherwise unchanged, deliberately: a moved form is the commonest way a
 * control gets left behind, and the only way to know none was is to re-run the same
 * questions against the new address.
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

    private function ctrl(): \AfricaGates\Controllers\AccountController
    {
        return $this->container()->get(\AfricaGates\Controllers\AccountController::class);
    }

    /** POST the application the way the form does — the branch travels in the BODY. */
    private function apply(array $in): \Psr\Http\Message\ResponseInterface
    {
        return $this->ctrl()->registerSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/register')
                ->withParsedBody($in + ['as' => 'organisation']),
            new Response()
        );
    }

    /** GET the application branch. */
    private function applyForm(): string
    {
        return (string) $this->ctrl()->registerForm(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/account/register?as=organisation')
                ->withQueryParams(['as' => 'organisation']),
            new Response()
        )->getBody();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['org_user_id'], $_SESSION['org_id'],
              $_SESSION['org_flash_ok'], $_SESSION['org_flash_error'],
              $_SESSION['flash_error'], $_SESSION['reg_old'], $_SESSION['user_id']);
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
        $res = $this->apply($in);

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
        $this->apply($in);

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
        $this->apply($in);
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
        $this->apply($this->form());
        $before = (int) DB::table('gates_partner_orgs')->count();

        $res = $this->apply($this->form());

        $this->assertSame('/org', $res->getHeaderLine('Location'));
        $this->assertSame($before, (int) DB::table('gates_partner_orgs')->count());
    }

    /**
     * A bad detail must not cost them the other nine fields.
     *
     * The old page re-rendered in place; this one redirects back to the branch with the
     * message and the values in the session, which is the pattern the member half already
     * uses. So the test follows the redirect — asserting only that the POST bounced would
     * pass on a handler that dropped every field on the floor.
     */
    public function test_a_rejected_form_comes_back_filled_in(): void
    {
        $res = $this->apply($this->form(['password' => 'tooshort']));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/account/register?as=organisation', $res->getHeaderLine('Location'));

        $html = $this->applyForm();
        $this->assertStringContainsString('at least 12 characters', $html);
        $this->assertStringContainsString('Bright Futures Initiative', $html);
        $this->assertStringContainsString('IT/1234567', $html);

        // Never handed back, even for one redirect: a session bag is not where a password
        // belongs, and the field is re-typed rather than repopulated.
        $this->assertStringNotContainsString('tooshort', $html);
    }

    /** The requirements are on the page, above the form, not behind a link. */
    public function test_the_page_states_what_it_will_ask_for(): void
    {
        $html = $this->applyForm();

        foreach (['CAC registration', 'SCUML', 'registered name', 'with a reason'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    // ──────────────────── one registered body, one record ───────────────────

    /**
     * A SECOND APPLICATION FOR THE SAME CAC NUMBER IS REFUSED.
     *
     * The email duplicate has been refused since this form shipped, and it is the weaker of
     * the two: an applicant who tries again from another address sails past it. What lands
     * then is two organisations for one registered body, both queuing their own registry
     * check against the same number, both part-approvable, and each able to acquire its own
     * settlement account — at which point "which of these should the money reach" has no
     * answer on any screen.
     */
    public function test_a_second_application_for_the_same_cac_number_is_refused(): void
    {
        $this->apply($this->form());

        $r = PartnerOrg::registerPartner($this->form(['cac_number' => 'IT/1234567']));

        $this->assertFalse($r['ok'], 'one registered body now has two records');
        $this->assertStringContainsString('CAC number', $r['message']);
    }

    /**
     * And it is refused on the NORMALISED number, not on what was typed.
     *
     * `IT/1234567` and `it 1234567` are one registration. A comparison on the raw string
     * would refuse only somebody who typed it the same way twice, which is close to nobody
     * — the check would read as present and catch almost nothing.
     */
    public function test_the_same_number_typed_differently_is_still_the_same_number(): void
    {
        $this->apply($this->form());

        foreach (['it 1234567', 'IT-1234567', 'IT/1234567'] as $written) {
            $r = PartnerOrg::registerPartner($this->form(['cac_number' => $written]));
            $this->assertFalse($r['ok'], "\"$written\" was accepted as a different registration");
        }
    }

    /**
     * The refusal does not say WHO holds the number.
     *
     * A form that answers "which body is registered under this number" is a register lookup
     * anybody can run against our database, and this one is not ours to publish.
     */
    public function test_the_refusal_does_not_name_the_organisation_holding_it(): void
    {
        $in = $this->form(['name' => 'Bright Futures Initiative']);
        $this->apply($in);

        $r = PartnerOrg::registerPartner($this->form(['cac_number' => 'IT/1234567']));
        $this->assertStringNotContainsString('Bright Futures', (string) $r['message']);
    }

    // ───────────────────────── the door it now lives behind ─────────────────

    /**
     * The chooser sends a non-profit to the branch, not to a page of its own.
     *
     * This is the fault the chooser itself was built to fix, one level up: a mechanism with
     * no findable way in. Two doors to one thing is the same fault wearing the other face —
     * the chooser pointed off to a separate address, and the two screens disagreed about
     * what registering here even is.
     */
    public function test_the_chooser_opens_the_application_in_place(): void
    {
        $html = (string) $this->ctrl()->registerForm(
            (new ServerRequestFactory())->createServerRequest('GET', '/account/register'),
            new Response()
        )->getBody();

        $this->assertStringContainsString('/account/register?as=organisation', $html,
            'the chooser no longer offers the organisation branch');
        $this->assertStringNotContainsString('/giving/apply', $html,
            'the chooser still sends a non-profit to the retired page');
    }

    /**
     * The branch travels in the BODY, and the handler reads it there.
     *
     * A submit does not carry a query string. A controller forking on `?as=` would send
     * every organisation down the member path, where `registerPartner` is never called —
     * so the application would quietly become a member account with a missing name.
     */
    public function test_an_application_without_the_branch_field_is_not_an_application(): void
    {
        $before = (int) DB::table('gates_partner_orgs')->count();

        $in = $this->form();
        unset($in['as']);
        $this->ctrl()->registerSubmit(
            (new ServerRequestFactory())->createServerRequest('POST', '/account/register')
                ->withParsedBody($in),
            new Response()
        );

        $this->assertSame($before, (int) DB::table('gates_partner_orgs')->count(),
            'an organisation was created by a post that never said it was one');
    }

    // ───────────────── the two branches do not share a pocket ───────────────

    /**
     * A FAILED APPLICATION MUST NOT PREFILL THE OTHER FORM.
     *
     * Both branches kept their rejected values under one session key, and `name` means
     * different things on either side — a person on one, an organisation on the other. So
     * a failed application for "Bright Futures Initiative" put that string into the Full
     * name field of the individual form, for anybody who backed out and started again as
     * themselves.
     *
     * Nothing threw and nothing looked wrong. A prefilled field IS the feature, and the
     * value was one the same person had typed a minute earlier — which is exactly why it
     * needed keying rather than patching: the next field the two branches happen to name
     * alike would have done it again, silently.
     */
    public function test_a_failed_application_does_not_leak_into_the_member_form(): void
    {
        $this->apply($this->form(['name' => 'Bright Futures Initiative', 'password' => 'short']));

        $html = (string) $this->ctrl()->registerForm(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/account/register?as=individual')
                ->withQueryParams(['as' => 'individual']),
            new Response()
        )->getBody();

        $this->assertStringNotContainsString('Bright Futures Initiative', $html,
            "the organisation's name is prefilled into the member form's Full name field");
    }

    /** And the application's own values still come back to the application. */
    public function test_the_application_still_gets_its_own_values_back(): void
    {
        $this->apply($this->form(['password' => 'short']));

        $this->assertStringContainsString('Bright Futures Initiative', $this->applyForm(),
            'keying the bag per branch emptied the branch it belongs to');
    }

    // ────────────────────── member registration is throttled ────────────────

    /**
     * IT SENDS AN EMAIL PER CALL, AND HAD NO LIMIT OF ANY KIND.
     *
     * The organisation branch has been throttled since it shipped. Member registration
     * beside it was open: an account created and a verification message sent on every
     * successful call, to an address somebody else chose. The cost of that is not a table
     * of junk rows, it is outbound mail against our sending reputation — and this platform
     * reaches everybody by email.
     *
     * Asserted through the controller so the limit is proved where a request meets it, and
     * on the SENT COUNT rather than the row count: the refusal that matters is the one
     * that stops the mail.
     */
    public function test_member_registration_is_rate_limited_per_connection(): void
    {
        $ctrl = $this->ctrl();
        $ip   = '203.0.113.' . random_int(2, 250);

        $post = static function (string $email) use ($ctrl, $ip) {
            return $ctrl->registerSubmit(
                (new ServerRequestFactory())
                    ->createServerRequest('POST', '/account/register',
                        ['REMOTE_ADDR' => $ip])
                    ->withParsedBody([
                        'as' => 'individual', 'name' => 'Ada Obi',
                        'email' => $email, 'phone' => '08030000000',
                    ]),
                new Response()
            );
        };

        $made = 0;
        for ($i = 0; $i < 14; $i++) {
            $post('m' . $i . '-' . bin2hex(random_bytes(3)) . '@example.test');
            // The accepted ones land on the verification notice; a refusal goes back to
            // the form. Counting the destination rather than the rows, because a limit
            // that creates the account and skips the mail would pass a row count.
            if (($_SESSION['pending_verify_email'] ?? '') !== '') {
                $made++;
                unset($_SESSION['pending_verify_email']);
            }
        }

        $this->assertLessThan(14, $made,
            'member registration accepts an unlimited number of accounts from one connection');
        $this->assertGreaterThan(0, $made,
            'the limit refuses everybody, which is not a limit but an outage');
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
