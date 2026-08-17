<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgAuth, PartnerOrg, StandApplication, StandCall, StandType};
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The screens: the organiser's allocation console, and the form anybody can fill in.
 *
 * A controller and a template that have never been rendered together are two files that
 * happen to be in the same repository. The rules themselves are covered in VendorStandTest —
 * what is tested here is that the surfaces enforce them rather than merely describing them,
 * and that the two of them agree about what is on the page.
 */
class StandSurfacesTest extends TestCase
{
    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    private function admin(): \AfricaGates\Admin\Controllers\StandsController
    {
        return $this->container()->get(\AfricaGates\Admin\Controllers\StandsController::class);
    }

    private function publicCtrl(): \AfricaGates\Controllers\StandApplyController
    {
        return $this->container()->get(\AfricaGates\Controllers\StandApplyController::class);
    }

    private function asAdmin(string $role = 'superadmin'): void
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = $role;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'],
              $_SESSION['flash_error'], $_SESSION['org_flash_ok'], $_SESSION['org_flash_error'],
              $_SESSION['org_user_id'], $_SESSION['org_id']);
        parent::tearDown();
    }

    // ───────────────────────────────── fixtures ─────────────────────────────

    private function makeEvent(): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'market-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+60 days')), 'status' => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    /** A vendor with every document in place, so eligibility turns on nothing accidental. */
    private function makeVendor(array $over = []): int
    {
        $id = (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => 'adaeze-' . bin2hex(random_bytes(4)),
            'name' => 'Adaeze Foods', 'legal_name' => 'Adaeze Foods Limited',
            'kind' => PartnerOrg::KIND_VENDOR, 'entity_type' => PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => 'BN9988', 'status' => PartnerOrg::STATUS_APPROVED,
            'subaccount_code' => 'ACCT_v', 'contact_phone' => '08031234567',
        ]);
        foreach (array_keys(PartnerOrg::requiredDocuments($id)) as $kind) {
            DB::table('gates_org_documents')->insert([
                'org_id' => $id, 'kind' => $kind, 'stored_path' => 'uploads/org-docs/x.pdf',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $id;
    }

    /** An event with one stand type of two places, and an open call. */
    private function openCall(object $event, int $quota = 2): void
    {
        $t = StandType::save((int) $event->id, [
            'name' => 'Food pitch', 'category' => 'food', 'price_naira' => '50000',
            'quota' => (string) $quota, 'includes_power' => '1',
        ]);
        $this->assertTrue($t['ok'], $t['message'] ?? '');

        $c = StandCall::save((int) $event->id, [
            'intro'     => 'We are looking for cooks who can feed four hundred people.',
            'closes_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
        ]);
        $this->assertTrue($c['ok']);
        $o = StandCall::open($c['id'], 1);
        $this->assertTrue($o['ok'], $o['message']);
    }

    private function get(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path);
    }

    private function post(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withParsedBody($body);
    }

    // ───────────────────────── the organiser's console ──────────────────────

    public function test_the_console_shows_the_locked_terms_and_the_quota(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        $this->openCall($event);

        $html = (string) $this->admin()
            ->index($this->get('/admin/events/' . $event->id . '/stands'), new Response(),
                    ['id' => (int) $event->id])->getBody();

        $this->assertStringContainsString('Food pitch', $html);
        $this->assertStringContainsString('0/2', $html, 'The published quota is the headline fact.');
        $this->assertStringContainsString('locked on', $html,
            'A reader has to be able to see that the terms can no longer be edited.');
        // The edit form must be gone, not merely discouraged.
        $this->assertStringNotContainsString('Add stand type', $html,
            'Prices and quotas are locked while the call is open.');
    }

    public function test_a_draft_call_is_editable_and_says_publishing_is_permanent(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        StandCall::save((int) $event->id, ['closes_at' => date('Y-m-d H:i:s', strtotime('+9 days'))]);

        $html = (string) $this->admin()
            ->index($this->get('/admin/events/' . $event->id . '/stands'), new Response(),
                    ['id' => (int) $event->id])->getBody();

        $this->assertStringContainsString('Add stand type', $html);
        $this->assertStringContainsString('permanently', $html);
    }

    /** Saving and publishing a call through the screen, not just through the service. */
    public function test_the_screen_can_create_and_publish_a_call(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        $args  = ['id' => (int) $event->id];

        $this->admin()->saveType($this->post('/x', [
            'name' => 'Craft table', 'category' => 'craft', 'quota' => '5', 'price_naira' => '20000',
        ]), new Response(), $args);
        $this->admin()->saveCall($this->post('/x', [
            'closes_at' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]), new Response(), $args);
        $this->admin()->openCall($this->post('/x', []), new Response(), $args);

        $call = StandCall::forEvent((int) $event->id);
        $this->assertSame(StandCall::STATUS_OPEN, $call->status);
        $this->assertNotEmpty($call->locked_at);
        $this->assertNotEmpty(StandCall::criteria($call)['types'] ?? [],
            'Opening must snapshot the quotas, or a rejection has nothing to be measured against.');
    }

    /** A moderator can read the page and must not be able to move anything on it. */
    public function test_a_moderator_cannot_decide(): void
    {
        $this->asAdmin('moderator');
        $event = $this->makeEvent();
        $this->openCall($event);

        $type = StandType::forEvent((int) $event->id)[0];
        $app  = StandApplication::submit($this->makeVendor(), (int) $type->id,
                                         ['what_they_sell' => 'Jollof rice']);
        StandApplication::checkEligibility($app['id']);

        $this->admin()->decide($this->post('/x', ['decision' => 'offered']), new Response(),
                               ['id' => (int) $event->id, 'app' => $app['id']]);

        $this->assertSame(StandApplication::DECISION_PENDING,
                          StandApplication::find($app['id'])->decision);
        $this->assertStringContainsString('Only an admin', (string) $_SESSION['flash_error']);
    }

    public function test_the_bulk_check_marks_eligibility_and_explains_a_failure(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        $this->openCall($event);
        $type = StandType::forEvent((int) $event->id)[0];

        $good = StandApplication::submit($this->makeVendor(), (int) $type->id,
                                         ['what_they_sell' => 'Jollof rice']);
        // No documents at all: ineligible, and entitled to know precisely why.
        $bare = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'bare-' . bin2hex(random_bytes(4)), 'name' => 'Bare Vendor',
            'kind' => PartnerOrg::KIND_VENDOR, 'status' => PartnerOrg::STATUS_PENDING,
        ]);
        $bad = StandApplication::submit($bare, (int) $type->id, ['what_they_sell' => 'Beads']);

        $this->admin()->checkAll($this->post('/x', []), new Response(), ['id' => (int) $event->id]);

        $this->assertSame(StandApplication::ELIGIBILITY_PASS,
                          StandApplication::find($good['id'])->eligibility);
        $failed = StandApplication::find($bad['id']);
        $this->assertSame(StandApplication::ELIGIBILITY_FAIL, $failed->eligibility);
        $this->assertStringContainsString('missing', (string) $failed->eligibility_note);
    }

    /**
     * The screen must not offer a place that does not exist.
     *
     * The refusal lives in the service; what this checks is that the controller surfaces it
     * rather than swallowing it, because a silently-ignored click is how an organiser ends up
     * believing they have allocated a stand they have not.
     */
    public function test_an_offer_beyond_the_quota_is_refused_and_said_out_loud(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        $this->openCall($event, 1);
        $type = StandType::forEvent((int) $event->id)[0];
        $args = ['id' => (int) $event->id];

        $first  = StandApplication::submit($this->makeVendor(), (int) $type->id, ['what_they_sell' => 'Rice']);
        $second = StandApplication::submit($this->makeVendor(), (int) $type->id, ['what_they_sell' => 'Suya']);
        StandApplication::checkEligibility($first['id']);
        StandApplication::checkEligibility($second['id']);

        $this->admin()->decide($this->post('/x', ['decision' => 'offered']), new Response(),
                               $args + ['app' => $first['id']]);
        $this->admin()->decide($this->post('/x', ['decision' => 'offered']), new Response(),
                               $args + ['app' => $second['id']]);

        $this->assertSame(StandApplication::DECISION_OFFERED, StandApplication::find($first['id'])->decision);
        $this->assertSame(StandApplication::DECISION_PENDING, StandApplication::find($second['id'])->decision);
        $this->assertStringContainsString('quota was published', (string) $_SESSION['flash_error']);
    }

    public function test_a_rejection_without_a_reason_is_refused_by_the_screen(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        $this->openCall($event);
        $type = StandType::forEvent((int) $event->id)[0];
        $app  = StandApplication::submit($this->makeVendor(), (int) $type->id, ['what_they_sell' => 'Rice']);

        $this->admin()->decide($this->post('/x', ['decision' => 'rejected', 'reason' => '   ']),
                               new Response(), ['id' => (int) $event->id, 'app' => $app['id']]);

        $this->assertSame(StandApplication::DECISION_PENDING, StandApplication::find($app['id'])->decision);
        $this->assertStringContainsString('needs a reason', (string) $_SESSION['flash_error']);
    }

    /**
     * The allocation sheet is the artefact the whole screen exists to produce.
     *
     * On the morning of the market nobody is logging in to read a dashboard, so it carries
     * the contact and the access requirements — and only the vendors who actually accepted.
     */
    public function test_the_allocation_sheet_carries_accepted_stands_and_nobody_else(): void
    {
        $this->asAdmin();
        $event = $this->makeEvent();
        $this->openCall($event);
        $type = StandType::forEvent((int) $event->id)[0];

        $inId  = $this->makeVendor(['name' => 'Accepted Foods', 'contact_phone' => '08099999999']);
        $outId = $this->makeVendor(['name' => 'Rejected Foods']);

        $in  = StandApplication::submit($inId,  (int) $type->id,
                                        ['what_they_sell' => 'Jollof', 'needs_step_free' => '1']);
        $out = StandApplication::submit($outId, (int) $type->id, ['what_they_sell' => 'Suya']);
        StandApplication::checkEligibility($in['id']);
        StandApplication::offer($in['id'], 1);
        StandApplication::accept($in['id'], $inId);
        StandApplication::decide($out['id'], StandApplication::DECISION_REJECTED, 1, 'Category full.');

        $csv = (string) $this->admin()
            ->exportCsv($this->get('/x'), new Response(), ['id' => (int) $event->id])->getBody();

        $this->assertStringContainsString('Accepted Foods', $csv);
        $this->assertStringContainsString('08099999999', $csv);
        $this->assertStringContainsString('Step-free', $csv);
        $this->assertStringNotContainsString('Rejected Foods', $csv,
            'A sheet that lists people who were not allocated a pitch is worse than no sheet.');
    }

    // ─────────────────────────────── the public call ────────────────────────

    public function test_the_public_call_page_publishes_the_terms(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);

        $html = (string) $this->publicCtrl()
            ->call($this->get('/events/' . $event->slug . '/stands'), new Response(),
                   ['slug' => (string) $event->slug])->getBody();

        $this->assertStringContainsString('Food pitch', $html);
        $this->assertStringContainsString('50,000', $html);
        $this->assertStringContainsString('2 of 2 still open', $html);
        // The requirements have to say, on the public page, that you need not be a company.
        $this->assertStringContainsString('do not need to be a registered company', $html);
    }

    /** A draft call is not a public fact — its terms are still being written. */
    public function test_a_draft_call_is_not_published(): void
    {
        $event = $this->makeEvent();
        StandCall::save((int) $event->id, ['closes_at' => date('Y-m-d H:i:s', strtotime('+9 days'))]);

        $res = $this->publicCtrl()->call($this->get('/x'), new Response(), ['slug' => (string) $event->slug]);
        $this->assertSame(302, $res->getStatusCode());
    }

    /** And a closed one still is, so a late applicant learns when it closed. */
    public function test_a_closed_call_still_says_when_it_closed(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);
        StandCall::close((int) StandCall::forEvent((int) $event->id)->id);

        $html = (string) $this->publicCtrl()
            ->call($this->get('/x'), new Response(), ['slug' => (string) $event->slug])->getBody();

        $this->assertStringContainsString('what was published', $html);
    }

    // ────────────────────────────── applying in public ──────────────────────

    public function test_the_form_offers_the_individual_route_first(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);

        $html = (string) $this->publicCtrl()
            ->form($this->get('/x'), new Response(), ['slug' => (string) $event->slug])->getBody();

        $this->assertStringContainsString('Individual or sole trader', $html);
        $this->assertStringContainsString('registered businesses only', $html,
            'The CAC field must be visibly optional, or a sole trader invents a number for it.');
    }

    /**
     * One request: an account and an application.
     *
     * Splitting them produces accounts belonging to people who never finished applying —
     * rows nobody will review and nobody will delete.
     */
    public function test_a_stranger_can_register_and_apply_in_one_go(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);
        $type  = StandType::forEvent((int) $event->id)[0];
        $email = 'ngozi-' . bin2hex(random_bytes(4)) . '@example.test';

        $res = $this->publicCtrl()->submit($this->post('/x', [
            'stand_type_id'  => (string) $type->id,
            'entity_type'    => PartnerOrg::ENTITY_INDIVIDUAL,
            'name'           => 'Mama Ngozi’s Kitchen',
            'legal_name'     => 'Ngozi Okafor',
            'contact_email'  => $email,
            'password'       => 'correct horse battery',
            'what_they_sell' => 'Jollof rice and moi moi, cooked on site.',
            'needs_power'    => '1',
        ]), new Response(), ['slug' => (string) $event->slug]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/org', $res->getHeaderLine('Location'));

        $user = OrgAuth::findByEmail($email);
        $this->assertNotNull($user, 'The account must exist after a successful application.');
        $this->assertSame((int) $user->id, OrgAuth::userId(), 'They must be signed in, not bounced to a login.');

        $apps = StandApplication::forOrg((int) $user->org_id);
        $this->assertCount(1, $apps);
        $this->assertSame(1, (int) $apps[0]->needs_power);

        // And they are told what is still missing, in the flash they land on.
        $this->assertStringContainsString('not complete until you upload',
                                          (string) $_SESSION['org_flash_ok']);
    }

    /** A bad detail must not cost them the other eight fields. */
    public function test_a_rejected_form_comes_back_filled_in(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);
        $type = StandType::forEvent((int) $event->id)[0];

        $html = (string) $this->publicCtrl()->submit($this->post('/x', [
            'stand_type_id'  => (string) $type->id,
            'entity_type'    => PartnerOrg::ENTITY_INDIVIDUAL,
            'name'           => 'Mama Ngozi’s Kitchen',
            'legal_name'     => 'Ngozi Okafor',
            'contact_email'  => 'ngozi@example.test',
            'password'       => 'tooshort',
            'what_they_sell' => 'Jollof rice and moi moi.',
        ]), new Response(), ['slug' => (string) $event->slug])->getBody();

        $this->assertStringContainsString('at least 12 characters', $html);
        $this->assertStringContainsString('Mama Ngozi', $html, 'The typed answers must survive the error.');
        $this->assertStringContainsString('Jollof rice and moi moi.', $html);
    }

    public function test_applications_are_refused_once_the_call_is_closed(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);
        $type = StandType::forEvent((int) $event->id)[0];
        StandCall::close((int) StandCall::forEvent((int) $event->id)->id);

        $res = $this->publicCtrl()->submit($this->post('/x', [
            'stand_type_id' => (string) $type->id,
        ]), new Response(), ['slug' => (string) $event->slug]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('closed', (string) $_SESSION['org_flash_error']);
        $this->assertSame(0, (int) DB::table('gates_stand_applications')
            ->where('event_id', $event->id)->count());
    }

    /** A signed-in vendor is not asked to invent a second account. */
    public function test_a_signed_in_vendor_applies_without_registering_again(): void
    {
        $event = $this->makeEvent();
        $this->openCall($event);
        $type = StandType::forEvent((int) $event->id)[0];

        $orgId = $this->makeVendor();
        $this->assertTrue(OrgAuth::createUser($orgId, 'owner-' . bin2hex(random_bytes(4)) . '@example.test',
                                              'correct horse battery', 'Owner', 'owner')['ok']);
        $_SESSION['org_user_id'] = (int) DB::table('gates_org_users')->where('org_id', $orgId)->first()->id;
        $_SESSION['org_id']      = $orgId;

        $before = (int) DB::table('gates_partner_orgs')->count();
        $res    = $this->publicCtrl()->submit($this->post('/x', [
            'stand_type_id'  => (string) $type->id,
            'what_they_sell' => 'Jollof rice.',
        ]), new Response(), ['slug' => (string) $event->slug]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame($before, (int) DB::table('gates_partner_orgs')->count(),
            'Applying while signed in must not mint a second organisation.');
        $this->assertCount(1, StandApplication::forOrg($orgId));
    }
}
