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

    // ═══════════════════════════════════════════════════════════════════════
    // CREATING AND CORRECTING WHAT IS ON OFFER
    // ═══════════════════════════════════════════════════════════════════════
    //
    // Reported as "the vendor stand creation is completely bugged". It was, in five
    // separate ways, and every one of them is asserted below. The common thread is that
    // each failure was SILENT: the action appeared to work, or appeared to do nothing,
    // and nothing on the screen said which.

    public function test_a_stand_type_can_be_corrected_after_it_is_created(): void
    {
        // THE headline defect. save() has taken a type_id since it was written and no form
        // ever sent one, so a typo in a name or a price entered in kobo could only be fixed
        // by Remove-and-add — and Remove is refused, correctly, once anybody has applied.
        // A priced, published row that could neither be changed nor deleted.
        $event = $this->makeEvent();
        $this->asAdmin();

        StandType::save((int) $event->id, ['name' => 'Food pich', 'category' => 'food',
                                           'price_naira' => '5000000', 'quota' => '10']);
        $type = StandType::forEvent((int) $event->id)[0];

        $res = $this->admin()->saveType($this->post('/x', [
            'type_id' => (string) $type->id, 'name' => 'Food pitch', 'category' => 'food',
            'price_naira' => '50000', 'quota' => '10', 'size_preset' => '3x3', 'sort_order' => '0',
        ]), new Response(), ['id' => (int) $event->id]);

        $this->assertSame(302, $res->getStatusCode());
        $after = StandType::find((int) $type->id);
        $this->assertSame('Food pitch', $after->name);
        $this->assertSame(50000, (int) $after->price_naira);
        // Edited, not duplicated.
        $this->assertCount(1, StandType::forEvent((int) $event->id));
    }

    public function test_the_edit_form_exists_on_the_screen_and_posts_the_row_it_edits(): void
    {
        $event = $this->makeEvent();
        $this->asAdmin();
        StandType::save((int) $event->id, ['name' => 'Food pitch', 'category' => 'food',
                                           'price_naira' => '50000', 'quota' => '10']);
        $type = StandType::forEvent((int) $event->id)[0];

        $res  = $this->admin()->index($this->get('/x'), new Response(), ['id' => (int) $event->id]);
        $html = (string) $res->getBody();

        $this->assertStringContainsString('name="type_id" value="' . $type->id . '"', $html,
            'without a posted type_id the edit is an insert');
        // sort_order carried through: save() reads it from the form and defaults it to 0,
        // so omitting it would reorder the whole table every time somebody fixed a price.
        $this->assertStringContainsString('name="sort_order" value="' . $type->sort_order . '"', $html);
    }

    public function test_the_quota_cannot_be_cut_below_the_places_already_promised(): void
    {
        // Dropping "how many exist" does not un-offer anybody. Without this guard the
        // capacity view reads 2/1 and a vendor holds a place the published quota says does
        // not exist.
        $event = $this->makeEvent();
        $this->openCall($event, 2);
        $type  = StandType::forEvent((int) $event->id)[0];

        // TWO offers against a quota of two, so the guard is tested at quota 1 — quota 0 is
        // refused by an earlier and separate rule and would never reach it.
        foreach (['Jollof.', 'Suya.'] as $sells) {
            $app = StandApplication::submit($this->makeVendor(), (int) $type->id,
                                            ['what_they_sell' => $sells]);
            $this->assertTrue($app['ok'], $app['message'] ?? '');
            StandApplication::checkEligibility((int) $app['id']);
            $off = StandApplication::offer((int) $app['id'], 1);
            $this->assertTrue($off['ok'], $off['message'] ?? '');
        }
        $this->assertSame(2, StandType::allocated((int) $type->id));

        // The call has to be closed before terms can change at all — that lock already
        // worked and is asserted elsewhere. This is the guard UNDER it.
        StandCall::close((int) StandCall::forEvent((int) $event->id)->id);

        $r = StandType::save((int) $event->id, [
            'type_id' => (string) $type->id, 'name' => 'Food pitch', 'category' => 'food',
            // 1, not 0: a quota of zero is refused by an earlier and separate rule, so
            // testing with it would never reach the guard under test.
            'price_naira' => '50000', 'quota' => '1', 'size_preset' => '3x3',
        ], (int) $type->id);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already hold', $r['message']);
        $this->assertSame(2, (int) StandType::find((int) $type->id)->quota, 'nothing was written');
    }

    public function test_adding_from_the_catalogue_needs_the_role_that_sets_terms(): void
    {
        // This was the one write on the page that skipped mayDecide(), so any role that
        // could open the screen could add a priced stand type to a call.
        $event = $this->makeEvent();
        $preset = DB::table('gates_stand_presets')->where('is_active', 1)->first();
        $this->assertNotNull($preset, 'the migration seeds the catalogue');

        $this->asAdmin('moderator');
        $res = $this->admin()->applyPreset($this->post('/x', [
            'preset_id' => (string) $preset->id, 'quota' => '20',
        ]), new Response(), ['id' => (int) $event->id]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame([], StandType::forEvent((int) $event->id));
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    public function test_adding_from_the_catalogue_confirms_itself_and_lands_on_the_table(): void
    {
        // The reported symptom exactly: press Add, the page reloads at the top, and there is
        // no sign anything happened — indistinguishable from a silent failure. Two causes,
        // both here: the success message went to a session key nothing rendered, and the
        // redirect dropped the anchor.
        $event  = $this->makeEvent();
        $preset = DB::table('gates_stand_presets')->where('is_active', 1)
            ->orderBy('sort_order')->first();
        $this->asAdmin();

        $res = $this->admin()->applyPreset($this->post('/x', [
            'preset_id' => (string) $preset->id, 'quota' => '20',
        ]), new Response(), ['id' => (int) $event->id]);

        $this->assertStringEndsWith('/stands#types', $res->getHeaderLine('Location'));
        $this->assertNotEmpty($_SESSION['flash_ok'] ?? '', 'the confirmation must be renderable');

        $types = StandType::forEvent((int) $event->id);
        $this->assertCount(1, $types);
        $this->assertSame(20, (int) $types[0]->quota);
    }

    public function test_a_preset_in_feet_keeps_its_feet_all_the_way_to_the_table(): void
    {
        // The whole point of the catalogue. A pitch added from "6 × 6 ft stand" appeared in
        // the capacity table as "1.83 × 1.83 m" — a number no vendor would recognise as the
        // thing they applied for, on a screen whose job is to publish the terms.
        $event  = $this->makeEvent();
        $preset = DB::table('gates_stand_presets')->where('slug', 'six-by-six-ft')->first();
        $this->assertNotNull($preset);
        $this->asAdmin();

        $this->admin()->applyPreset($this->post('/x', [
            'preset_id' => (string) $preset->id, 'quota' => '12',
        ]), new Response(), ['id' => (int) $event->id]);

        $type = StandType::forEvent((int) $event->id)[0];
        $this->assertSame(183, (int) $type->width_cm, 'not the 3x3 default');
        $this->assertSame(183, (int) $type->depth_cm);

        // And the name is not the size said twice.
        $this->assertSame('6 × 6 ft stand', $type->name);

        $row = StandCall::capacity((int) $event->id)[0];
        $this->assertSame('6 × 6 ft', $row['size']);

        $html = (string) $this->admin()->index($this->get('/x'), new Response(),
                                               ['id' => (int) $event->id])->getBody();
        $this->assertStringContainsString('6 × 6 ft', $html);
        $this->assertStringNotContainsString('1.83 × 1.83 m', $html);
    }

    public function test_the_custom_size_boxes_cannot_silently_lose_what_was_typed_in_them(): void
    {
        // They sat live and pre-filled with 3 beside a select reading "Standard gazebo",
        // under small print saying they were ignored. Typing 6 × 6 into them gave a 3 × 3
        // pitch and no warning — and a stand's size is a published term.
        $event = $this->makeEvent();
        $this->asAdmin();
        $html = (string) $this->admin()->index($this->get('/x'), new Response(),
                                               ['id' => (int) $event->id])->getBody();

        $this->assertStringContainsString('data-ag-do="stand-size"', $html);
        $this->assertStringContainsString('data-ag-size-custom', $html);

        // Delegated, never inline: the admin CSP has no 'unsafe-inline' in script-src, so an
        // inline onchange here would not be merely discouraged — it would never run.
        $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');
        $this->assertStringContainsString('stand-size', $js);
        $this->assertStringNotContainsString('onchange=', $html);
    }

    public function test_the_catalogue_quota_box_does_not_start_empty_while_being_required(): void
    {
        // A `required` box with only a placeholder is a form that refuses itself on the
        // first press, and the catalogue already records a default per preset.
        $event = $this->makeEvent();
        $this->asAdmin();
        $html = (string) $this->admin()->index($this->get('/x'), new Response(),
                                               ['id' => (int) $event->id])->getBody();

        $this->assertMatchesRegularExpression(
            '~id="stPresetQty"[^>]*\brequired\b[^>]*value="[1-9][0-9]*"~', $html
        );
    }
}
