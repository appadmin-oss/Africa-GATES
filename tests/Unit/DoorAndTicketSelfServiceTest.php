<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\{EventScanPass, EventTicketService, TicketSelfService};
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The door, and the four things an attendee should never email support about.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS BEING DEFENDED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A door pass is a bearer credential handed to volunteers and posted into group chats. Three
 * properties make that safe, and all three are load-bearing rather than nice to have:
 *
 *   IT EXPIRES BY ITSELF.     Nobody has to remember to take it away, which is the part that
 *                             never happens.
 *   IT IS CHECKED PER REQUEST. A door is open for hours with the tab left up; a pass revoked
 *                             at 23:00 must stop admitting people from a page loaded at 18:00.
 *   IT CAN DO EXACTLY ONE THING. One event, one question. Anything more turns a link shared
 *                             into a WhatsApp group into a data breach.
 *
 * And on the ticket side, the split is by CONSEQUENCE: resending can only ever mail the
 * address already on the booking, so it needs nothing; changing a ticket needs a code sent to
 * that address, because the reference travels in a QR code that gets photographed.
 */
/** Captures the 6-digit code out of the email, because the stored form is a one-way hash. */
final class CodeCatchingMailer extends \AfricaGates\Services\OtpService
{
    public string $code = '';
    /** @var list<string> */
    public array $to = [];

    public function __construct() { parent::__construct(['username' => 'u', 'password' => 'p']); }

    public function sendBranded(string $to, string $subject, string $htmlBody,
                                string $plainBody = '', string $category = '', string $hero = '',
                                string $unsubscribeUrl = '', array $attachments = []): array
    {
        $this->to[] = $to;
        if (preg_match('/\\b(\\d{6})\\b/', $plainBody . ' ' . $htmlBody, $m) === 1) {
            $this->code = $m[1];
        }
        return ['success' => true];
    }
}

final class DoorAndTicketSelfServiceTest extends TestCase
{
    private int $eventId = 0;
    private int $tierId  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_event_registrations')->delete();
        try { DB::table('gates_event_scan_passes')->delete(); } catch (\Throwable) {}
        try { DB::table('gates_otp_tokens')->delete(); } catch (\Throwable) {}

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Gala', 'slug' => 'door-gala', 'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        $this->tierId = (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => 'reg', 'name' => 'Regular',
            'price_naira' => 5000, 'capacity' => 100, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => 0,
        ]);
    }

    private function ticket(string $code, string $status = 'confirmed', int $qty = 1,
                            string $email = 'holder@example.test'): int
    {
        return (int) DB::table('gates_event_registrations')->insertGetId([
            'event_id' => $this->eventId, 'tier_id' => $this->tierId, 'tier' => 'Regular',
            'name' => 'Ada Obi', 'email' => $email, 'phone' => '08030000000',
            'quantity' => $qty, 'amount_naira' => 5000,
            'reference' => 'AFG-EVT-' . strtoupper(substr(md5($code), 0, 12)),
            'ticket_code' => $code, 'status' => $status,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ══ 1 · the decision at the door ═════════════════════════════════════════

    /** Three outcomes, not two — the middle one is the whole reason this is not a boolean. */
    public function test_a_good_ticket_is_admitted_once_and_then_reports_a_duplicate(): void
    {
        $this->ticket('AAAA-2222');

        $first = EventTicketService::checkIn('AAAA-2222', $this->eventId, 'test');
        $this->assertSame('admit', $first['verdict']);
        $this->assertSame('Ada Obi', $first['name']);

        $second = EventTicketService::checkIn('AAAA-2222', $this->eventId, 'test');
        $this->assertSame('duplicate', $second['verdict'],
            'the same code twice was accepted twice — a shared screenshot walks in');
        $this->assertStringContainsString('admitted at', $second['detail']);
    }

    /** Lower case at 2am with cold hands is the normal case, not the exception. */
    public function test_the_code_is_not_case_sensitive(): void
    {
        $this->ticket('BBBB-3333');
        $this->assertSame('admit', EventTicketService::checkIn('bbbb-3333', $this->eventId)['verdict']);
    }

    /**
     * An unpaid booking is refused — and told apart from a forgery, because somebody standing
     * in the rain deserves to know which it is.
     */
    public function test_an_unpaid_booking_is_refused_with_its_own_sentence(): void
    {
        $this->ticket('CCCC-4444', 'pending');
        $v = EventTicketService::checkIn('CCCC-4444', $this->eventId);

        $this->assertSame('refuse', $v['verdict']);
        $this->assertSame('Payment not received', $v['title']);
        $this->assertStringContainsString('desk', $v['detail']);
    }

    /** A code for another event is refused with the SAME answer as one that does not exist. */
    public function test_a_ticket_for_another_event_is_indistinguishable_from_no_ticket(): void
    {
        $other = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Other', 'slug' => 'other-gala', 'status' => 'published',
            'event_date' => Carbon::now()->addDay()->toDateTimeString(),
        ]);
        $this->ticket('DDDD-5555');

        $a = EventTicketService::checkIn('DDDD-5555', $other);
        $b = EventTicketService::checkIn('ZZZZ-9999', $other);

        $this->assertSame($a['title'], $b['title'],
            'the door is an oracle for which ticket codes exist');
        $this->assertSame('refuse', $a['verdict']);
    }

    /** A table of ten is ten people through the door, and the verdict says so. */
    public function test_the_verdict_carries_the_seat_count(): void
    {
        $this->ticket('EEEE-6666', 'confirmed', 10);
        $v = EventTicketService::checkIn('EEEE-6666', $this->eventId);

        $this->assertSame(10, $v['seats']);
        $this->assertStringContainsString('10 seats', $v['detail']);
    }

    // ══ 2 · the time gate ════════════════════════════════════════════════════

    public function test_a_pass_inside_its_window_resolves(): void
    {
        $token = EventScanPass::issue($this->eventId,
            Carbon::now()->addHours(3)->toDateTimeString(), null, 'Main gate');
        $this->assertNotNull($token);

        $r = EventScanPass::resolve((string) $token);
        $this->assertTrue($r['ok']);
        $this->assertSame('Main gate', (string) $r['pass']->label);
    }

    /**
     * Before and after are DIFFERENT refusals.
     *
     * "This opens at 18:00" and "this closed at 23:00" send the person holding the phone to do
     * different things, and a single "invalid" would send both of them to ring an organiser
     * mid-event.
     */
    public function test_a_pass_says_which_side_of_its_window_it_failed_on(): void
    {
        $early = EventScanPass::issue($this->eventId,
            Carbon::now()->addDays(2)->toDateTimeString(),
            Carbon::now()->addDay()->toDateTimeString());
        $late = EventScanPass::issue($this->eventId,
            Carbon::now()->subHour()->toDateTimeString(),
            Carbon::now()->subDay()->toDateTimeString());

        $this->assertSame('early',   EventScanPass::resolve((string) $early)['reason']);
        $this->assertSame('expired', EventScanPass::resolve((string) $late)['reason']);
    }

    /** Revoking is immediate — the reason a link sent to six phones is still controllable. */
    public function test_a_revoked_pass_stops_at_once(): void
    {
        $token = EventScanPass::issue($this->eventId, Carbon::now()->addHours(3)->toDateTimeString());
        $pass  = EventScanPass::resolve((string) $token)['pass'];

        $this->assertTrue(EventScanPass::revoke((int) $pass->id, $this->eventId));
        $this->assertSame('revoked', EventScanPass::resolve((string) $token)['reason']);
    }

    /** A window that closes before it opens can never admit anybody, so it is never created. */
    public function test_a_backwards_window_is_refused(): void
    {
        $this->assertNull(EventScanPass::issue($this->eventId,
            Carbon::now()->addHour()->toDateTimeString(),
            Carbon::now()->addHours(5)->toDateTimeString()));
    }

    /** Only the hash is stored, so a dump of the table yields nothing usable. */
    public function test_the_token_itself_is_never_stored(): void
    {
        $token = (string) EventScanPass::issue($this->eventId,
            Carbon::now()->addHours(3)->toDateTimeString());

        $row = DB::table('gates_event_scan_passes')->first();
        $this->assertNotSame($token, (string) $row->token_hash);
        $this->assertSame(hash('sha256', $token), (string) $row->token_hash);
    }

    // ══ 3 · the door, over HTTP ══════════════════════════════════════════════

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->add(\Slim\Views\TwigMiddleware::createFromContainer($app, \Slim\Views\Twig::class));
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        return $app;
    }

    private function postJson(string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        $req->getBody()->write((string) json_encode($body));
        $req->getBody()->rewind();
        return $this->app()->handle($req);
    }

    /**
     * ── THE CSRF EXEMPTION IS LOAD-BEARING ───────────────────────────────────
     *
     * The door has no session and no login, so there is no token to hold. If the exemption
     * regressed, every scan would be refused with a CSRF error at a live gate — and the
     * exemption is a regex, which is exactly the kind of thing that gets widened by accident.
     */
    public function test_the_door_check_works_with_no_csrf_token(): void
    {
        $this->ticket('FFFF-7777');
        $token = (string) EventScanPass::issue($this->eventId,
            Carbon::now()->addHours(3)->toDateTimeString());

        $res = $this->postJson('/door/' . $token . '/check', ['code' => 'FFFF-7777']);
        $this->assertSame(200, $res->getStatusCode());

        $j = json_decode((string) $res->getBody(), true);
        $this->assertSame('admit', $j['verdict']);
        $this->assertSame('Ada Obi', $j['name']);
    }

    /**
     * The pass is re-resolved on EVERY check, not trusted from the page load.
     *
     * A door is open for hours with the tab up. Without this, a pass revoked at 23:00 keeps
     * admitting people from a page loaded at 18:00 — the exact failure the window exists for.
     */
    public function test_a_revoked_pass_stops_admitting_even_from_a_page_already_open(): void
    {
        $this->ticket('GGGG-8888');
        $token = (string) EventScanPass::issue($this->eventId,
            Carbon::now()->addHours(3)->toDateTimeString());
        $pass = EventScanPass::resolve($token)['pass'];
        EventScanPass::revoke((int) $pass->id, $this->eventId);

        $res = $this->postJson('/door/' . $token . '/check', ['code' => 'GGGG-8888']);
        $this->assertSame(403, $res->getStatusCode());
        // And nobody was let in by it: the refusal happens before the check-in, not after.
        $this->assertEmpty(DB::table('gates_event_registrations')
            ->where('ticket_code', 'GGGG-8888')->value('checked_in_at'),
            'a revoked pass still admitted somebody');
    }

    /** A scanner pointed at the ticket PAGE reads a URL. Accept its tail rather than refusing. */
    public function test_a_scanned_ticket_url_is_accepted_as_a_code(): void
    {
        $this->ticket('HHHH-9999');
        $token = (string) EventScanPass::issue($this->eventId,
            Carbon::now()->addHours(3)->toDateTimeString());

        $res = $this->postJson('/door/' . $token . '/check',
            ['code' => 'https://afg.example.test/events/ticket/HHHH-9999']);

        $this->assertSame('admit', json_decode((string) $res->getBody(), true)['verdict']);
    }

    /** A refused pass still renders a PAGE, because the holder is standing at a door. */
    public function test_a_closed_door_renders_a_page_that_says_when(): void
    {
        $token = (string) EventScanPass::issue($this->eventId,
            Carbon::now()->subHour()->toDateTimeString(),
            Carbon::now()->subDay()->toDateTimeString());

        $res = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/door/' . $token));

        $this->assertSame(403, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('Door closed', $html);
        $this->assertStringContainsString('It closed at', $html,
            'the holder was not told when — so they will ring an organiser mid-event');
    }

    /** A garbage token is a 404-shaped refusal, not a crash, and touches no database row. */
    public function test_a_nonsense_token_is_refused_quietly(): void
    {
        $this->assertSame('unknown', EventScanPass::resolve('not-a-token')['reason']);
        $this->assertSame('unknown', EventScanPass::resolve(str_repeat('z', 64))['reason']);
    }

    // ══ 4 · ticket self-service ══════════════════════════════════════════════

    /** Resending needs no code: it can only ever mail the address already on the booking. */
    public function test_resending_answers_the_same_way_for_a_reference_that_does_not_exist(): void
    {
        $this->ticket('IIII-1111');
        $ref = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'IIII-1111')->value('reference');

        $real  = TicketSelfService::resend($ref, null);
        $fake  = TicketSelfService::resend('AFG-EVT-DOESNOTEXIST', null);

        $this->assertSame($real['message'], $fake['message'],
            'the endpoint is a way to test which references exist');
    }

    /** Changing a ticket without the emailed code changes nothing. */
    public function test_a_rename_without_a_code_is_refused(): void
    {
        $this->ticket('JJJJ-2222');
        $ref = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'JJJJ-2222')->value('reference');

        $r = TicketSelfService::rename($ref, '', 'Someone Else');
        $this->assertFalse($r['ok']);
        $this->assertSame('Ada Obi', (string) DB::table('gates_event_registrations')
            ->where('reference', $ref)->value('name'));
    }

    /** With the code, it saves — and the code is single-use. */
    public function test_a_rename_with_the_code_works_once(): void
    {
        $this->ticket('KKKK-3333');
        $ref = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'KKKK-3333')->value('reference');

        TicketSelfService::sendCode($ref, $this->mailer());
        $code = $this->lastCode();

        $this->assertTrue(TicketSelfService::rename($ref, $code, 'Chidi Eze')['ok']);
        $this->assertSame('Chidi Eze', (string) DB::table('gates_event_registrations')
            ->where('reference', $ref)->value('name'));

        // Burned. A code that survives its use is a code somebody can replay from an inbox.
        $this->assertFalse(TicketSelfService::rename($ref, $code, 'Third Person')['ok']);
    }

    /**
     * ── A TRANSFER MUST KILL THE OLD CODE ────────────────────────────────────
     *
     * Otherwise "transfer" quietly means "two people can enter on one ticket", and the second
     * one through the door is refused in front of a queue holding a ticket they were
     * legitimately given.
     */
    public function test_a_transfer_re_issues_the_code_so_the_old_screenshot_dies(): void
    {
        $this->ticket('LLLL-4444');
        $ref = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'LLLL-4444')->value('reference');

        TicketSelfService::sendCode($ref, $this->mailer());
        $r = TicketSelfService::transfer($ref, $this->lastCode(), 'Kwame Mensah',
                                         'kwame@example.test', null);
        $this->assertTrue($r['ok'], $r['message']);

        $row = DB::table('gates_event_registrations')->where('reference', $ref)->first();
        $this->assertSame('Kwame Mensah', (string) $row->name);
        $this->assertSame('kwame@example.test', (string) $row->email);
        $this->assertNotSame('LLLL-4444', (string) $row->ticket_code);

        // And the old code is now nobody's ticket.
        $this->assertSame('refuse', EventTicketService::checkIn('LLLL-4444', $this->eventId)['verdict']);
        $this->assertSame('admit',
            EventTicketService::checkIn((string) $row->ticket_code, $this->eventId)['verdict']);
    }

    /** A ticket already used at the door cannot be transferred out from under the door list. */
    public function test_a_checked_in_ticket_cannot_be_transferred_or_renamed(): void
    {
        $this->ticket('MMMM-5555');
        $ref = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'MMMM-5555')->value('reference');
        EventTicketService::checkIn('MMMM-5555', $this->eventId);

        TicketSelfService::sendCode($ref, $this->mailer());
        $code = $this->lastCode();

        $this->assertFalse(TicketSelfService::rename($ref, $code, 'Nope')['ok']);
        $this->assertFalse(TicketSelfService::transfer($ref, $code, 'Nope', 'n@example.test', null)['ok']);
    }

    /** A code minted for one ticket must not unlock another the same person holds. */
    public function test_a_code_for_one_ticket_does_not_work_on_another(): void
    {
        $this->ticket('NNNN-6666', 'confirmed', 1, 'same@example.test');
        $this->ticket('OOOO-7777', 'confirmed', 1, 'same@example.test');
        $refA = (string) DB::table('gates_event_registrations')->where('ticket_code', 'NNNN-6666')->value('reference');
        $refB = (string) DB::table('gates_event_registrations')->where('ticket_code', 'OOOO-7777')->value('reference');

        TicketSelfService::sendCode($refA, $this->mailer());
        $codeForA = $this->lastCode();

        $this->assertFalse(TicketSelfService::rename($refB, $codeForA, 'Wrong Ticket')['ok'],
            'a code for one ticket unlocked another');
    }

    /** The masked address lets you recognise your own without learning somebody else's. */
    public function test_the_address_the_code_went_to_is_masked(): void
    {
        $this->ticket('PPPP-8888', 'confirmed', 1, 'adaobi@example.test');
        $ref = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', 'PPPP-8888')->value('reference');

        $r = TicketSelfService::sendCode($ref, $this->mailer());
        $this->assertTrue($r['ok']);
        $this->assertSame('a•••@example.test', $r['sent_to']);
        $this->assertStringNotContainsString('adaobi', $r['message']);
    }

    /**
     * The mailer that captures the code.
     *
     * Read from the EMAIL rather than reversed out of the stored hash — which is the point:
     * the hash is one-way, and a test that could recover the code from the database would be
     * proving the opposite of what this stores.
     */
    private ?\Tests\Unit\CodeCatchingMailer $mail = null;

    private function mailer(): \Tests\Unit\CodeCatchingMailer
    {
        return $this->mail ??= new \Tests\Unit\CodeCatchingMailer();
    }

    private function lastCode(): string
    {
        return $this->mailer()->code;
    }
}
