<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\EventInvites;
use AfricaGates\Services\EventScanPass;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InvitePass;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The guests of honour a ceremony invites, and the pass each of them carries.
 *
 * Every property here is one an operator cannot check by eye before the letters go out:
 * that the quota in the letter is the quota the code allows, that a person nobody can
 * write to is REPORTED rather than skipped, that a second run does not mint a second
 * reference for somebody who already has one in writing, and that a photograph of a
 * pass stops working.
 */
final class EventInvitesTest extends TestCase
{
    private int $eventId = 0;
    private int $cycleId = 0;
    private int $catId   = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => 2026, 'status' => 'judging', 'edition_label' => 'Rehearsal',
        ]);
        $this->catId = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'excellence', 'title' => 'Academic Excellence', 'sort_order' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'Africa GATES Gala 2026', 'programme_id' => $pid,
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published',
        ]);
    }

    /** A shortlisted nominee reachable at one address. */
    private function nominee(string $name, string $email = '', ?int $profileId = null): int
    {
        $id = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $this->catId, 'name' => $name, 'status' => 'approved',
            'nominated_at' => '2026-02-01 10:00:00', 'vote_count' => 0, 'organic_vote_count' => 0,
            'profile_id' => $profileId,
        ]);
        if ($email !== '') {
            DB::table('gates_nominations')->insert([
                'cycle_id' => $this->cycleId, 'category_id' => $this->catId, 'status' => 'approved',
                'nominee_name' => $name, 'nominee_email' => $email,
                // nominator_name is NOT NULL with no default. Checked against the schema,
                // not inferred from another fixture — see the note in CLAUDE.md.
                'nominator_name' => 'A Nominator', 'nominator_email' => 'nom@example.com',
                'created_at' => '2026-02-01 10:00:00',
            ]);
        }
        return $id;
    }

    private function tier(int $naira, string $name): int
    {
        return (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => \AfricaGates\Support\Slug::make($name, 40),
            'name' => $name, 'price_naira' => $naira, 'is_active' => 1, 'sort_order' => 1,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_the_ceremony_is_found_from_its_programme(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->where('slug', 'principals')->value('id');

        $e = EventInvites::eventForProgramme($pid);

        $this->assertNotNull($e, 'the programme has no ceremony linked to it');
        $this->assertSame('gala-2026', (string) $e->slug);
    }

    /**
     * The minimum support an invitation asks for is the cheapest tier on sale, not a
     * figure typed into the copy — an organiser moves prices up to the week of the event.
     */
    public function test_the_minimum_support_is_the_cheapest_tier_on_sale(): void
    {
        $this->tier(20000, 'Table of Ten');
        $this->tier(5000,  'Supporter');
        // A free tier is the ordinary case — press, sponsors, students — and ordering by
        // price alone would make THIS the "minimum support" an invitation asks for.
        $this->tier(0,     'Press');

        $t = EventInvites::lowestTier($this->eventId);

        $this->assertNotNull($t);
        $this->assertSame('Supporter', (string) $t->name,
            'the ask must point at the cheapest tier somebody can actually buy');
        $this->assertSame(5000, (int) $t->price_naira);
    }

    public function test_the_plan_lists_the_shortlist_and_names_who_cannot_be_reached(): void
    {
        $ada  = $this->nominee('Ada Obi',    'ada@example.com');
        $nobody = $this->nominee('Silent Sam');                  // no address anywhere
        $this->publishShortlist($this->cycleId, $this->catId, [$ada, $nobody]);

        $plan = EventInvites::plan($this->eventId)[InviteAudience::PRINCIPAL];

        $this->assertSame(['Ada Obi'], array_column($plan['ready'], 'name'));
        $this->assertSame(['Silent Sam'], array_column($plan['unreachable'], 'name'));
        $this->assertStringContainsString('No address', $plan['unreachable'][0]['why'],
            'an unreachable invitee must say WHY, not just be missing');
    }

    /**
     * Two nominees approved under one name is ambiguous, and picking one would send a
     * person somebody else's name, category and personal reference.
     */
    public function test_an_ambiguous_name_is_reported_rather_than_guessed(): void
    {
        $one = $this->nominee('Chinelo Eze', 'chinelo.a@example.com');
        $this->nominee('Chinelo Eze', 'chinelo.b@example.com');   // same name, second address
        $this->publishShortlist($this->cycleId, $this->catId, [$one]);

        $plan = EventInvites::plan($this->eventId)[InviteAudience::PRINCIPAL];

        $this->assertSame([], $plan['ready'], 'an ambiguous address must never be picked');
        $this->assertStringContainsString('More than one', $plan['unreachable'][0]['why']);
    }

    /** A nominee is not invited to a ceremony they were not shortlisted for. */
    public function test_an_unshortlisted_nominee_is_not_invited(): void
    {
        $this->nominee('Not Shortlisted', 'no@example.com');

        $plan = EventInvites::plan($this->eventId)[InviteAudience::PRINCIPAL];

        $this->assertSame([], $plan['ready']);
        $this->assertSame([], $plan['unreachable']);
    }

    // ── minting ─────────────────────────────────────────────────────────────

    public function test_minting_stores_the_quota_and_mints_a_code_that_allows_exactly_it(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $this->assertNotNull($inv);
        $this->assertSame(25, (int) $inv->guest_quota);

        $code = DB::table('gates_event_codes')
            ->where('event_id', $this->eventId)->where('code', $inv->reference)->first();

        $this->assertNotNull($code, 'the invite promised a discount and no code was minted');
        $this->assertSame('percent', (string) $code->kind);
        $this->assertSame(10, (int) $code->amount);
        $this->assertSame(25, (int) $code->max_uses,
            'the letter promises 25 guests — the code must allow 25, not 20');
        $this->assertSame(1, (int) $code->max_per_email,
            'the quota counts people, so one guest must not spend two of it');
    }

    public function test_the_quota_and_the_discount_are_admin_configurable(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'invite_quota_judge', 'value' => '14'],
            ['key_name' => 'invite_discount_percent', 'value' => '15'],
        ]);

        $inv = EventInvites::mint($this->eventId, InviteAudience::JUDGE,
            ['name' => 'Hon. Judge', 'email' => 'judge@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $this->assertSame(14, (int) $inv->guest_quota);
        $this->assertSame(14, (int) DB::table('gates_event_codes')->where('code', $inv->reference)->value('max_uses'));
        $this->assertSame(15, (int) DB::table('gates_event_codes')->where('code', $inv->reference)->value('amount'));
    }

    /** Default quotas are the ones the brief asked for. */
    public function test_the_default_quotas_are_25_25_and_10(): void
    {
        $this->assertSame(25, InviteAudience::spec(InviteAudience::PRINCIPAL)['quota']);
        $this->assertSame(25, InviteAudience::spec(InviteAudience::CHILD)['quota']);
        $this->assertSame(10, InviteAudience::spec(InviteAudience::JUDGE)['quota']);
    }

    /**
     * A second run must not mint a second reference. The first one is already in a letter
     * somebody has read, and its code is the one their guests are spending.
     */
    public function test_minting_twice_returns_the_same_invitation(): void
    {
        $who = ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0];

        $first  = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL, $who);
        $second = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL, $who);

        $this->assertSame((string) $first->reference, (string) $second->reference);
        $this->assertSame(1, (int) DB::table('gates_event_invites')->where('event_id', $this->eventId)->count());
        $this->assertSame(1, (int) DB::table('gates_event_codes')->where('event_id', $this->eventId)->count());
    }

    public function test_the_sandbox_judge_is_not_invited_to_a_real_ceremony(): void
    {
        DB::table('gates_judges')->insert([
            ['name' => 'Real Panellist', 'email' => 'real@example.com', 'is_active' => 1],
            ['name' => 'DEMO — Judge',   'email' => 'judge@' . DemoSeeder::MAIL_DOMAIN, 'is_active' => 1],
        ]);

        $names = array_column(EventInvites::plan($this->eventId)[InviteAudience::JUDGE]['ready'], 'name');

        $this->assertContains('Real Panellist', $names);
        $this->assertNotContains('DEMO — Judge', $names);
    }

    // ── the rotating pass ───────────────────────────────────────────────────

    public function test_the_pass_rotates_and_a_photograph_of_it_stops_working(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $now  = InvitePass::window();
        $live = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, $now);

        $ok = InvitePass::verify($live);
        $this->assertTrue($ok['ok'], $ok['reason']);
        $this->assertSame((int) $inv->id, (int) $ok['invite']->id);

        // The window before is still accepted — a steward lining up a camera takes longer
        // than one window, and a code that dies mid-scan reads as a broken pass.
        $prev = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, $now - 1);
        $this->assertTrue(InvitePass::verify($prev)['ok']);

        // Two windows back is a photograph.
        $old = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, $now - 2);
        $stale = InvitePass::verify($old);
        $this->assertFalse($stale['ok']);
        $this->assertStringContainsString('expired', $stale['reason']);
    }

    /** A future window must never verify, however skewed the phone's clock. */
    public function test_a_future_code_is_refused(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $ahead = InvitePass::code((string) $inv->reference, (string) $inv->id_secret, InvitePass::window() + 4);

        $this->assertFalse(InvitePass::verify($ahead)['ok']);
    }

    /**
     * The reference is printed in the letter, shown in the email, displayed on the ID AND
     * handed to twenty-five guests as their discount code. So holding it must not be
     * enough to mint a pass — that is the entire reason for the per-invite secret.
     */
    public function test_holding_the_reference_is_not_enough_to_forge_a_pass(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        $forged = InvitePass::code((string) $inv->reference, 'a-guess-at-the-secret');

        $this->assertFalse(InvitePass::verify($forged)['ok']);
        $this->assertNotSame((string) $inv->id_secret, 'a-guess-at-the-secret');
    }

    public function test_a_scan_is_counted_once_at_the_door_not_per_refresh(): void
    {
        $inv = EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        InvitePass::code((string) $inv->reference, (string) $inv->id_secret);   // a refresh
        $this->assertSame(0, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'));

        InvitePass::touch((int) $inv->id);
        $this->assertSame(1, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'));
    }

    // ════════════════════════════════════════════════════════════════════════
    //  THE MOBILE ID, through the real routing stack
    // ════════════════════════════════════════════════════════════════════════

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        return $app;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        return $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    private function invited(): object
    {
        $this->tier(5000, 'Supporter');

        return EventInvites::mint($this->eventId, InviteAudience::PRINCIPAL,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);
    }

    public function test_the_id_page_shows_the_pass_the_ask_and_the_evening(): void
    {
        $inv  = $this->invited();
        $res  = $this->get('/honour/' . $inv->reference);
        $html = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString((string) $inv->reference, $html, 'the reference is read aloud at the door');
        $this->assertStringContainsString('25', $html, 'the quota promised must be on the pass');
        $this->assertStringContainsString('10% off', $html);
        $this->assertStringContainsString('Supporter', $html, 'the ask names the cheapest paid tier');
        $this->assertStringContainsString('Africa GATES Gala 2026', $html);
    }

    /**
     * The code on screen is valid for one window. A cached copy of this page is an
     * expired pass rendered as though it were live, and a search engine holding it is a
     * directory of who was shortlisted before the ceremony announced it.
     */
    public function test_the_id_page_is_never_cached_and_never_indexed(): void
    {
        $res = $this->get('/honour/' . $this->invited()->reference);

        $this->assertStringContainsString('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('noindex', $res->getHeaderLine('X-Robots-Tag'));
    }

    /** A scannable symbol before any script runs — the door has the worst signal. */
    public function test_the_qr_endpoint_returns_a_symbol(): void
    {
        $inv = $this->invited();
        $res = $this->get('/honour/' . $inv->reference . '/qr.svg');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('image/svg+xml', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<svg', (string) $res->getBody());
    }

    /** The countdown is asked for, because a phone that slept has a confidently wrong clock. */
    public function test_the_tick_endpoint_reports_the_window_from_the_server(): void
    {
        $res = $this->get('/honour/' . $this->invited()->reference . '/tick');
        $d   = json_decode((string) $res->getBody(), true);

        $this->assertSame(InvitePass::STEP_SECONDS, $d['step']);
        $this->assertGreaterThan(0, $d['seconds_left']);
        $this->assertLessThanOrEqual(InvitePass::STEP_SECONDS, $d['seconds_left']);
    }

    public function test_an_unknown_reference_is_a_404_on_all_three_paths(): void
    {
        foreach (['', '/qr.svg', '/tick'] as $tail) {
            $this->assertSame(404, $this->get('/honour/AGI-NOTAREAL' . $tail)->getStatusCode(),
                'AGI-NOTAREAL' . $tail . ' should not resolve');
        }
    }

    /** First open is the only signal an operator has that an invitation landed. */
    public function test_opening_the_id_is_recorded_once(): void
    {
        $inv = $this->invited();
        $this->assertNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('opened_at'));

        $this->get('/honour/' . $inv->reference);
        $first = DB::table('gates_event_invites')->where('id', $inv->id)->value('opened_at');
        $this->assertNotNull($first);

        $this->get('/honour/' . $inv->reference);
        $this->assertSame($first, DB::table('gates_event_invites')->where('id', $inv->id)->value('opened_at'),
            'opened_at records the FIRST open, not the most recent one');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  THE DOOR
    // ════════════════════════════════════════════════════════════════════════

    private function doorToken(): string
    {
        return (string) EventScanPass::issue(
            $this->eventId, '2099-01-01 00:00:00', null, 'Main gate', 1
        );
    }

    /** @return array<string,mixed> */
    private function scan(string $token, string $code): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/door/' . $token . '/check')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        $req->getBody()->write((string) json_encode(['code' => $code]));
        $req->getBody()->rewind();

        $app = $this->app();
        $app->addBodyParsingMiddleware();

        return (array) json_decode((string) $app->handle($req)->getBody(), true);
    }

    public function test_the_door_admits_a_guest_of_honour_and_says_so(): void
    {
        $inv = $this->invited();
        $v   = $this->scan($this->doorToken(),
            InvitePass::code((string) $inv->reference, (string) $inv->id_secret));

        $this->assertSame('admit', $v['verdict'] ?? '', json_encode($v));
        $this->assertTrue($v['honour'] ?? false, 'the page celebrates on this flag');
        $this->assertSame('Guest of honour', $v['title'] ?? '');
        $this->assertSame('Ada Obi', $v['name'] ?? '');
        $this->assertSame(1, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'));
    }

    /**
     * A nominee who steps out to take a call and comes back is the ordinary case. Turning
     * them away from their own ceremony over a re-scan is a worse failure than a second
     * admission, so it is reported rather than refused.
     */
    public function test_a_second_scan_welcomes_them_back_rather_than_refusing(): void
    {
        $inv   = $this->invited();
        $token = $this->doorToken();
        $code  = InvitePass::code((string) $inv->reference, (string) $inv->id_secret);

        $this->scan($token, $code);
        $v = $this->scan($token, $code);

        $this->assertSame('admit', $v['verdict'] ?? '');
        $this->assertSame('Welcome back', $v['title'] ?? '');
        $this->assertStringContainsString('already admitted 1 time', $v['detail'] ?? '');
    }

    /** An invitation to another evening reads exactly like one that does not exist. */
    public function test_an_invitation_to_another_event_is_refused_without_saying_it_exists(): void
    {
        $inv   = $this->invited();
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'other-night', 'title' => 'Another Night',
            'event_date' => '2026-11-11 18:00:00', 'status' => 'published',
        ]);
        $token = (string) EventScanPass::issue($other, '2099-01-01 00:00:00', null, 'Other gate', 1);

        $v = $this->scan($token, InvitePass::code((string) $inv->reference, (string) $inv->id_secret));

        $this->assertSame('refuse', $v['verdict'] ?? '');
        $this->assertSame('No invitation here has that reference.', $v['detail'] ?? '',
            'a door that distinguishes the two is an oracle for which references are real');
    }

    public function test_a_forged_pass_is_refused_at_the_door(): void
    {
        $inv = $this->invited();

        $v = $this->scan($this->doorToken(), InvitePass::code((string) $inv->reference, 'guessed'));

        $this->assertSame('refuse', $v['verdict'] ?? '');
        $this->assertFalse($v['honour'] ?? true);
        $this->assertSame(0, (int) DB::table('gates_event_invites')->where('id', $inv->id)->value('scans'),
            'a refused scan must not count as an admission');
    }

    /**
     * The regression that matters: adding a second kind of pass must not disturb the
     * ticket door. A ticket code has no dots, which is what routes the two apart.
     */
    public function test_a_ticket_code_still_reaches_the_ticket_door(): void
    {
        $v = $this->scan($this->doorToken(), 'NOTATICKETCODE99');

        $this->assertSame('refuse', $v['verdict'] ?? '');
        $this->assertSame('Not a ticket for this event', $v['title'] ?? '',
            'a dotless code must go to EventTicketService, not to the invitation check');
    }
}
