<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\DoorController;
use AfricaGates\Services\{DoorWelcome, EventArrivals, EventInvites, EventScanPass,
                          EventTicketService, EventTierTone, InviteAudience, InvitePass,
                          PaymentService, RateLimitService};
use AfricaGates\Support\{EventTime, Qr};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\{ResponseFactory, ServerRequestFactory};
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * ONE EVENING, END TO END — created, sold, scanned, counted, corrected.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS WHEN FORTY-NINE OTHERS ALREADY COVER THE PARTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every stage of an event has its own test and each one passes. That is precisely the
 * failure mode §18 of the index describes: "Each part was complete and correct in
 * isolation." The Chrome extension had an install note naming a folder nothing served; the
 * vote-recovery mechanism had a two-person rule and no panel to work it; the door's voice —
 * this month — rendered clips keyed on the event and asked for them keyed on nothing, and
 * would have greeted an entire hall with the fallback while every unit test stayed green.
 *
 * None of those is visible from inside a unit test, because none of them is a fault in a
 * unit. They are faults in the SEAMS: what one stage writes and the next stage reads.
 *
 * So this walks a single evening through every stage IN ORDER, in one database, and asserts
 * the handoffs. It is deliberately not a collection of independent cases — the ordering is
 * the point, and a failure here should be read as "the night breaks at this step", which is
 * how it would actually be experienced.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE EVENING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A gala in Lagos. Two tiers, the premium one dragged to the top. Ada books three seats and
 * pays. Tunde is nominated and invited. The doors open at seven; Ada's party arrives in two
 * pieces, Tunde arrives, somebody scans a code that is not a ticket, the wifi drops and a
 * scan is taken on paper, and a steward admits the wrong person and has to take it back.
 */
final class EventLifecycleTest extends TestCase
{
    private int $eventId = 0;
    private int $premium = 0;
    private int $standard = 0;
    private string $door = '';

    /**
     * 19:00 in Lagos — TOMORROW, not a date written down.
     *
     * A fixed date would drift out of every window the platform cares about: `reserve()`
     * refuses an event in the past, and the greeting sweep only looks a few days ahead. A
     * lifecycle test pinned to a calendar date passes for a while and then starts failing
     * for reasons that have nothing to do with the code.
     *
     * Lagos observes no summer offset, so 18:00 UTC is 19:00 WAT on any day of the year.
     */
    private string $startUtc = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/var/cache/door-welcome/*.mp3') ?: [] as $f) @unlink($f);
        parent::tearDown();
    }

    // ══ ACT 1 · THE EVENING EXISTS, AND IT IS IN LAGOS ═══════════════════════

    /**
     * An event carries its own clock, and the tiers hold the order the organiser gave them.
     *
     * Both of these have been real bugs. The door printed its closing time by slicing the
     * stored string, which is the UTC storage convention read aloud — a gate closing at
     * 23:00 in Lagos told the steward it closed at 22:00. And a design handoff asked for
     * `loop.index0` on the tier ladder, which ranks by position in the list rather than by
     * price: an organiser who puts their premium row first would have had the cheapest tier
     * sweep hardest, invisibly.
     */
    public function test_1_the_evening_is_created_in_its_own_city(): void
    {
        $this->stage();

        $e = (object) DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $this->assertSame('19:00', EventTime::at($e, $this->startUtc, 'H:i'),
            'the gala starts at seven in Lagos and the platform said otherwise');
        $this->assertSame('WAT', EventTime::abbr($e, $this->startUtc));

        // The same instant, read by a platform pointed at Nairobi, is a different hour —
        // which is exactly why the zone belongs to the EVENT and not to the settings screen.
        $this->assertSame('21:00',
            EventTime::at((object) ['timezone' => 'Africa/Nairobi'], $this->startUtc, 'H:i'));

        // Tiers come back in the organiser's own order. Premium was dragged to the top.
        $tiers = EventTicketService::tiers($this->eventId);
        $this->assertSame(['Premium', 'Standard'], array_column($tiers, 'name'),
            'the ladder is ordered by sort_order, never by price');

        // And a tier's colour is a SLOT resolved against the event's accent, never a hex —
        // so `hue` (the identity, for a fill) and `edge` (the 3:1 variant, for a border) are
        // two different values and painting one with the other is a real defect.
        $hues = EventTierTone::hues($tiers[0], $e);
        $this->assertArrayHasKey('hue', $hues);
        $this->assertArrayHasKey('edge', $hues);
        $this->assertNotSame($hues['hue'], $hues['edge'],
            'fill and edge collapsed to one value — a pale border holds nothing');
    }

    // ══ ACT 2 · SOMEBODY PAYS ════════════════════════════════════════════════

    /**
     * A party of three: held at checkout, ticketed only when the gateway says the money
     * arrived, and never for less than the price.
     */
    public function test_2_three_seats_are_held_then_paid_for(): void
    {
        $this->stage();

        $r = EventTicketService::reserve($this->eventId, $this->standard,
            ['name' => 'Ada Obi', 'email' => 'ada@example.test', 'phone' => '08031234567'], 3);

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $ref = (string) $r['reference'];

        $held = (object) DB::table('gates_event_registrations')->where('reference', $ref)->first();
        $this->assertSame('pending', (string) $held->status, 'a checkout that holds nothing oversells');
        $this->assertSame(3, (int) $held->quantity);
        $this->assertNull($held->ticket_code, 'a ticket existed before the money did');

        // Underpaid: nothing is issued. Half a ticket price is not a ticket.
        $short = EventTicketService::confirm($ref, $this->gateway([
            $ref => ['status' => 'success', 'amount' => (int) $held->amount_naira - 1],
        ]));
        $this->assertFalse($short['ok']);
        $this->assertNull(
            DB::table('gates_event_registrations')->where('reference', $ref)->value('ticket_code'));

        $ok = EventTicketService::confirm($ref, $this->gateway([
            $ref => ['status' => 'success', 'amount' => (int) $held->amount_naira],
        ]));
        $this->assertTrue($ok['ok'], (string) ($ok['message'] ?? ''));
        $this->assertNotSame('', (string) $ok['ticket_code']);

        // And it is idempotent: a gateway that calls back twice must not mint twice.
        $again = EventTicketService::confirm($ref, $this->gateway([$ref => ['status' => 'success', 'amount' => 999999]]));
        $this->assertTrue($again['ok']);
        $this->assertTrue((bool) ($again['already'] ?? false));
        $this->assertSame(1, DB::table('gates_event_registrations')->where('reference', $ref)->count());
    }

    /**
     * The hall has a size, and it holds over every tier at once.
     *
     * An organiser selling forty early-bird and forty standard into a room of sixty needs
     * both numbers to be true — the tier limits AND the event's own. Only one of them being
     * enforced is how a room comes to be oversold by exactly the amount nobody checked.
     */
    public function test_2b_the_halls_own_size_is_a_ceiling_over_every_tier(): void
    {
        $this->stage();
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['capacity' => 4]);

        $this->sell('Ada Obi', 'ada@example.test', 3);

        // One more fits. Two do not, on EITHER tier — the tiers themselves are uncapped, so
        // only the event's number can be refusing this.
        $ok = EventTicketService::reserve($this->eventId, $this->premium,
            ['name' => 'Bisi Alabi', 'email' => 'bisi@example.test', 'phone' => '08030000000'], 1);
        $this->assertTrue($ok['ok'], (string) ($ok['message'] ?? ''));

        $over = EventTicketService::reserve($this->eventId, $this->standard,
            ['name' => 'Uche Mba', 'email' => 'uche@example.test', 'phone' => '08030000001'], 2);
        $this->assertFalse($over['ok'], 'the hall sold five seats into a room of four');
        // The refusal names the HALL, not the tier — so an organiser reading it knows which
        // number to raise. A tier-shaped message here would send them to the wrong screen.
        $this->assertStringContainsString('event filled up', (string) $over['message']);
    }

    /**
     * The code on the ticket, and the URL under the QR, are encoded by two DIFFERENT calls —
     * and swapping them produces a symbol that scans perfectly and goes nowhere.
     *
     * `encode()` folds case, because a ticket code read off a screen may be typed either
     * way. Uppercasing a URL path does not have that property.
     */
    public function test_3_the_ticket_carries_a_code_that_scans(): void
    {
        $code = $this->sell('Ada Obi', 'ada@example.test', 3);

        $reg = EventTicketService::byTicketCode($code);
        $this->assertNotNull($reg, 'a ticket code that finds no ticket');
        $this->assertSame('confirmed', (string) $reg->status);

        // A ticket code: version 1, alphanumeric, case folded.
        $m = Qr::encode($code);
        $this->assertCount(Qr::SIZE, $m);
        $this->assertSame($m, Qr::encode(strtolower($code)),
            'the code stopped folding case — a code typed in lower case would not scan');

        // A URL: byte mode, case PRESERVED. The same matrix for both would mean one of them
        // is wrong, and the wrong one is the URL, silently.
        $url = 'https://example.test/events/ticket/' . $code;
        $this->assertNotSame(Qr::encodeBytes($url), Qr::encodeBytes(strtoupper($url)),
            'the URL encoder folded case — the symbol scans and the link 404s');
    }

    // ══ ACT 3 · THE GUEST OF HONOUR ══════════════════════════════════════════

    /**
     * A nominee is invited, not sold to — so their pass is a different object with a
     * different shape, and the door tells them apart by that shape rather than by trying
     * one lookup and falling through to the other.
     */
    public function test_4_a_nominee_is_invited_rather_than_ticketed(): void
    {
        $this->stage();
        $invite = $this->invite('Tunde Cole', 'tunde@example.test');

        $this->assertSame(InviteAudience::NOMINEE, (string) $invite->audience);
        $this->assertSame(0, DB::table('gates_event_registrations')
            ->where('email', 'tunde@example.test')->count(),
            'a guest of honour was given a complimentary TICKET — that counts as a sale '
            . 'and stops the hall selling');

        $scanned = $this->idCode($invite);
        $this->assertSame(2, substr_count($scanned, '.'),
            'the invitation lost the shape the door recognises it by');

        $v = InvitePass::verify($scanned);
        $this->assertTrue($v['ok'], (string) ($v['reason'] ?? ''));
        $this->assertSame((int) $invite->id, (int) $v['invite']->id);
    }

    // ══ ACT 4 · THE DOOR OPENS ═══════════════════════════════════════════════

    /** A pass is minted for a gate, resolves for whoever holds it, and stops when revoked. */
    public function test_5_a_gate_is_opened_and_can_be_shut(): void
    {
        $this->stage();

        $r = EventScanPass::resolve($this->door);
        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));
        $this->assertSame($this->eventId, (int) $r['pass']->event_id);

        $this->assertTrue(EventScanPass::anyOpen($this->eventId));

        $this->assertTrue(EventScanPass::revoke((int) $r['pass']->id, $this->eventId));
        $this->assertFalse(EventScanPass::resolve($this->door)['ok'],
            'a revoked pass still opened the door');
    }

    /**
     * THE OTHER HALF OF §18: a sweep that runs on time and looks in the wrong place.
     *
     * Every other test here reaches `linesFor()` directly, which is the sweep's second
     * step. Its FIRST step is choosing which events to render for — and if that window
     * missed this gala the clips would simply never be made. Nothing would report it: the
     * run returns 0, which also means "ran, nothing to do", and the door would greet a full
     * hall with the fallback while every unit test in this file stayed green.
     *
     * Reached by reflection deliberately. Making it public to test it would add a method
     * with no caller, which is the bug §17 is about — the point is to check the sweep's own
     * selection, not to widen the surface for the checking.
     */
    public function test_5b_the_scheduled_sweep_would_actually_reach_this_gala(): void
    {
        $this->stage();

        $soon = (new \ReflectionMethod(DoorWelcome::class, 'soonEvents'))->invoke(null);

        $this->assertContains($this->eventId, $soon,
            'the 06:00 run does not consider this event at all, so no clip is ever made');

        // A gala further out than the lead time is correctly NOT rendered — the cache is
        // pruned on a two-week clock and rendering a March event in December spends the
        // free tier on names that will have changed.
        $far = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'far-off', 'title' => 'Next year', 'status' => 'published',
            'event_date' => Carbon::now()->addDays(DoorWelcome::LEAD_DAYS + 30)->toDateTimeString(),
        ]);
        $this->assertNotContains($far,
            (new \ReflectionMethod(DoorWelcome::class, 'soonEvents'))->invoke(null));
    }

    // ══ ACT 5 · THE NIGHT ════════════════════════════════════════════════════

    /**
     * THE SEAM THIS FILE WAS WRITTEN FOR.
     *
     * The sweep renders the guest list ahead of time; the door looks a filename up. The key
     * IS the sentence, so the two must build the same one — and nothing anywhere reports it
     * when they do not. The door still returns 200, still admits, still plays a clip. It
     * plays the WRONG clip, for every guest, for the whole evening.
     */
    public function test_6_the_greeting_the_sweep_rendered_is_the_one_the_door_plays(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 3);
        $this->voiceOn();

        // Exactly what the 06:00 sweep would put on disk, without touching the network.
        foreach (DoorWelcome::linesFor($this->eventId) as $line) $this->plant($line);

        $v = $this->scan($code, 1);

        $this->assertSame('admit', $v['verdict']);
        $this->assertNotSame('', (string) $v['welcome'], 'the door had no greeting to play at all');
        $this->assertNotSame(DoorWelcome::keyFor(DoorWelcome::genericLine()), (string) $v['welcome'],
            'Ada was greeted by the fallback while her own clip sat on disk — the sweep and '
            . 'the door built different sentences');
    }

    /**
     * A party arrives in pieces, which is the ordinary case and was the missing one: a
     * ticket for three was all-or-nothing, so a couple arriving ahead of their friend either
     * waited outside or burned the whole ticket.
     */
    public function test_7_a_party_of_three_arrives_in_two_pieces(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 3);

        $one = $this->scan($code, 1);
        $this->assertSame('admit', $one['verdict']);
        $this->assertSame(1, (int) $one['admitted_now']);
        $this->assertSame(2, (int) $one['seats_left']);

        $rest = $this->scan($code, 2);
        $this->assertSame('admit', $rest['verdict']);
        $this->assertSame(3, (int) $rest['seats_in']);
        $this->assertSame(0, (int) $rest['seats_left']);

        // A fourth person on a ticket for three is not admitted, however they ask.
        $extra = $this->scan($code, 1);
        $this->assertNotSame('admit', $extra['verdict'], 'a ticket for three admitted a fourth');

        // The seats landed on the row, in one place, and stopped at the ticket's own size.
        // The OTHER half of that ceiling — two gates scanning the same ticket in the same
        // second, where only the conditional UPDATE can decide — cannot be shown by scans
        // in sequence and is held by DoorPerSeatTest with a deliberately stale count.
        $this->assertSame(3, (int) DB::table('gates_event_registrations')
            ->where('ticket_code', $code)->value('checked_in_seats'));
    }

    /** Already through is reported, never refused — and a bad code says nothing useful. */
    public function test_8_a_re_scan_is_told_apart_from_a_bad_code(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 1);

        $this->assertSame('admit', $this->scan($code)['verdict']);
        $this->assertSame('duplicate', $this->scan($code)['verdict']);

        $junk = $this->scan('ZZZZZZZZ');
        $this->assertSame('refuse', $junk['verdict']);
        $this->assertSame('', (string) $junk['name'], 'a refusal leaked a name');
        $this->assertSame('', (string) $junk['welcome'], 'somebody was greeted while being turned away');
    }

    /** The guest of honour walks through the same door, and is met as one. */
    public function test_9_the_nominee_is_admitted_on_their_invitation(): void
    {
        $this->stage();
        $invite = $this->invite('Tunde Cole', 'tunde@example.test');
        $this->voiceOn();
        foreach (DoorWelcome::linesFor($this->eventId) as $line) $this->plant($line);

        $v = $this->scan($this->idCode($invite));

        $this->assertSame('admit', $v['verdict']);
        $this->assertTrue((bool) $v['honour']);
        $this->assertSame('Tunde Cole', (string) $v['name']);
        $this->assertNotSame('', (string) $v['welcome']);

        // Coming back from a phone call is not a refusal at your own ceremony.
        $back = $this->scan($this->idCode($invite));
        $this->assertSame('admit', $back['verdict']);
        $this->assertStringContainsString('already admitted', (string) $back['detail']);
    }

    /**
     * A judge comes through the same gate and is met as a judge.
     *
     * Both audiences exist for a reason the schema learned the hard way: `audience` shipped
     * as `ENUM('principal','child','judge')` and was corrected to `ENUM('nominee','judge')`
     * one commit later, so every production database kept the first set — where 'judge' is
     * valid and 'nominee' is not. "Build the list" minted judges and only judges, while dev,
     * built fresh from the corrected definition, was green. Walking BOTH through the door
     * here is what makes that class of drift visible from the outside.
     */
    public function test_9b_a_judge_is_admitted_and_named_as_a_judge(): void
    {
        $this->stage();
        $judge = $this->invite('Ngozi Adaeze', 'ngozi@example.test', InviteAudience::JUDGE);

        $v = $this->scan($this->idCode($judge));

        $this->assertSame('admit', $v['verdict']);
        $this->assertTrue((bool) $v['honour']);
        $this->assertSame('Judge', (string) $v['tier'],
            'a judge was announced as something else at their own panel\'s ceremony');

        // And they are in the room, counted the same as anybody else standing in it.
        $this->assertSame(1, EventArrivals::inTheRoom($this->eventId));
    }

    /**
     * §19 — the invitation row remembers WHEN and THROUGH WHICH GATE, and a screen shows it.
     *
     * All three columns existed and all three were half-dead. `last_scan_at` was written at
     * every admission and read nowhere. `last_scan_via` was written by nothing at all,
     * while its own migration promised the opposite in as many words: "guests of honour
     * need the same pair the ticket path has, for the same reason: without it the record of
     * an evening says a volunteer's scan was nobody's". And the invitations screen rendered
     * `scans` as a bare number — so the answer to "did they come?" was "3", with no when
     * and no where, on the one screen that is asked it.
     */
    public function test_9c_a_guest_of_honours_arrival_records_when_and_where(): void
    {
        $this->stage();
        $invite = $this->invite('Tunde Cole', 'tunde@example.test');

        $this->assertSame('admit', $this->scan($this->idCode($invite))['verdict']);

        $row = (object) DB::table('gates_event_invites')->where('id', $invite->id)->first();

        $this->assertSame(1, (int) $row->scans);
        $this->assertNotNull($row->last_scan_at, 'the arrival has no time on it');
        $this->assertStringContainsString('Main gate', (string) $row->last_scan_via,
            'the record of the evening says a volunteer\'s scan was nobody\'s');

        // And it is on the screen, not merely in the table.
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/events/invites.twig');
        $this->assertStringContainsString('last_scan_via', $t);
        $this->assertStringContainsString('last_scan_at', $t);
    }

    /** The wifi drops. The gate records; the SERVER still decides. */
    public function test_10_a_scan_taken_offline_is_admitted_when_the_line_returns(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 1);

        $at   = Carbon::now()->subMinutes(20)->toDateTimeString();
        $ctrl = new DoorController($this->twig(), new RateLimitService());
        $req  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/door/' . $this->door . '/sync')
            ->withParsedBody(['scans' => [['code' => $code, 'at' => $at, 'seats' => 1]]]);
        $body = json_decode((string) $ctrl->sync($req,
            (new ResponseFactory())->createResponse(), ['token' => $this->door])->getBody(), true);

        $this->assertTrue((bool) $body['ok']);
        $this->assertSame('admit', (string) $body['results'][0]['verdict']);

        // ── THE MOMENT TRAVELS, AND IT HAS TO REACH BOTH RECORDS ─────────────
        //
        // The ticket row had this right from the start. The arrivals log did NOT: it
        // stamped every row with the instant it was inserted, so a batch flushed at 21:00
        // wrote 21:00 against everybody who walked in at 19:05 — and the log is the durable
        // record, the one an organiser stands behind a week later when somebody disputes
        // being turned away. Two accounts of one evening, disagreeing by the length of
        // the outage.
        $stamp = (string) DB::table('gates_event_registrations')
            ->where('ticket_code', $code)->value('checked_in_at');
        $this->assertSame(substr($at, 0, 16), substr($stamp, 0, 16),
            'an admission taken at 19:05 was logged at whenever the line returned');

        $logged = (string) (EventArrivals::recent($this->eventId)[0]['created_at'] ?? '');
        $this->assertSame(substr($at, 0, 16), substr($logged, 0, 16),
            'the ticket row remembers when they arrived and the arrivals log does not');
    }

    /**
     * A gate scanning faster than a person can is slowed down — and told so in words that
     * are not a refusal.
     *
     * The distinction is the whole point. "Not a ticket for this event", shown on a code
     * that is perfectly good, sends a steward to argue with somebody who has paid, and
     * neither of them has any way to know the screen is lying. So the verdict is its own
     * thing, it says nothing was recorded, and it is true — the guard runs before the write.
     */
    public function test_10b_a_gate_scanning_too_fast_is_slowed_not_told_the_ticket_is_bad(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 1);

        // The window as the limiter itself would leave it, at the cap.
        $passId = (int) EventScanPass::resolve($this->door)['pass']->id;
        DB::table('gates_rate_limits')->insert([
            'fingerprint' => 'pass:' . $passId, 'action' => 'door_scan',
            'hit_count' => 100000, 'window_start' => Carbon::now()->toDateTimeString(),
        ]);

        $v = $this->scan($code, 1);

        $this->assertSame('slow', $v['verdict'], 'a busy gate was told the ticket was bad');
        $this->assertStringContainsString('nothing was recorded', (string) $v['detail']);
        $this->assertNull(DB::table('gates_event_registrations')
            ->where('ticket_code', $code)->value('checked_in_at'),
            'the screen said nothing was recorded and something was');
    }

    // ══ ACT 6 · HOW MANY ARE IN THE ROOM ═════════════════════════════════════

    /**
     * ONE number, counting seats and guests of honour alike.
     *
     * This summed registrations only, so every nominee and judge in the building was
     * missing from the closest thing this platform has to a fire-safety figure — and a
     * ticket for three counted as one.
     */
    public function test_11_the_headcount_is_seats_plus_guests_of_honour(): void
    {
        $this->stage();
        $code   = $this->sell('Ada Obi', 'ada@example.test', 3);
        $invite = $this->invite('Tunde Cole', 'tunde@example.test');

        $this->scan($code, 3);
        $this->scan($this->idCode($invite));

        $s = EventArrivals::summary($this->eventId);
        $this->assertSame(4, (int) $s['in'], 'three seats and a nominee is four people');
        $this->assertSame(3, (int) $s['tickets_in']);
        $this->assertSame(1, (int) $s['honoured_in']);

        // And the door's own running total is the SAME resolver — a door and an office
        // disagreeing about how many people are in a building is the failure here.
        $this->assertSame((int) $s['in'], EventArrivals::inTheRoom($this->eventId));
    }

    /** Every arrival in one list, whichever door it came through and whatever kind it was. */
    public function test_12_the_arrivals_log_is_one_list(): void
    {
        $this->stage();
        $code   = $this->sell('Ada Obi', 'ada@example.test', 2);
        $invite = $this->invite('Tunde Cole', 'tunde@example.test');

        $this->scan($code, 2);
        $this->scan($this->idCode($invite));

        $log = EventArrivals::recent($this->eventId);
        $this->assertCount(2, $log);

        $who = array_column($log, 'who');
        $this->assertContains('Ada Obi', $who);
        $this->assertContains('Tunde Cole', $who);

        // Both rows label their gate identically. An evening whose log says 'door: Main
        // gate' for one kind of arrival and 'door' for another cannot be read as one
        // sequence, which is the only thing this list is for.
        $vias = array_unique(array_column($log, 'via'));
        $this->assertCount(1, $vias, 'two names for the same gate: ' . implode(' / ', $vias));
        $this->assertStringContainsString('Main gate', (string) $vias[0]);
    }

    /**
     * The organiser's own record reads in the ROOM's clock, and names the person.
     *
     * ── THE BUG THIS HOLDS DOWN ──────────────────────────────────────────────
     *
     * Both lists on this screen printed their times with `|slice(11, 5)` over the stored
     * string. Storage here is UTC by convention, so a Lagos gala's 19:42 admission was
     * shown as 18:42 — on the record an organiser is meant to stand behind a week later,
     * when somebody is disputing an entry. Slicing a datetime is not formatting it: it
     * reads the storage convention out loud.
     *
     * The door had exactly this fault on its closing time and it was fixed there. The
     * office screen that reads the same log kept it, which is what a seam failure looks
     * like from the inside: each half was corrected on its own.
     *
     * ── AND WHO ──────────────────────────────────────────────────────────────
     *
     * `checked_in_by` was written at every admission and rendered on no screen, and the
     * log showed "admin #7" — unreadable at the only moment it is opened.
     */
    public function test_12b_the_organisers_record_is_in_the_rooms_clock(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 1);

        $adminId = (int) DB::table('gates_admins')->insertGetId([
            'name' => 'Bisi Alabi', 'email' => 'bisi@example.test',
            'password_hash' => 'x', 'role' => 'admin',
        ]);

        // Admitted at 19:42 in Lagos — stored as 18:42 UTC, which is the whole point.
        $at = Carbon::now()->format('Y-m-d') . ' 18:42:00';
        EventTicketService::checkIn($code, $this->eventId, 'door: Main gate', $adminId, $at);

        $rows = $this->ticketsScreen();

        $me = null;
        foreach ($rows['attendees'] as $a) if (($a['ticket_code'] ?? '') === $code) $me = $a;
        $this->assertNotNull($me);
        $this->assertSame('19:42', (string) $me['arrived_time'],
            'the arrival was printed in the storage convention rather than in Lagos');
        $this->assertSame('Bisi Alabi', (string) $me['by_name'],
            'the person who admitted somebody is recorded and shown nowhere');

        $this->assertSame('19:42', (string) $rows['arrivals'][0]['at_time']);
        $this->assertSame('WAT', (string) $rows['arrivals'][0]['at_zone']);
        $this->assertSame('Bisi Alabi', (string) $rows['arrivals'][0]['by_name']);

        // And the template reads those rather than slicing the raw stamp again.
        $t = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/events/tickets.twig');
        foreach (['{{ r.at_time }}', 'a.arrived_time', 'r.by_name', 'a.by_name'] as $needle) {
            $this->assertStringContainsString($needle, $t, 'the screen does not read ' . $needle);
        }
        $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*slice\(11/', $t,
            'a datetime is still being sliced rather than formatted');
    }

    // ══ ACT 7 · PUTTING IT RIGHT ═════════════════════════════════════════════

    /**
     * A steward admits the wrong person. There has to be a way back, it has to say why, and
     * the headcount has to follow it.
     */
    public function test_13_a_mistaken_admission_can_be_taken_back(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 2);

        $this->scan($code, 2);
        $this->assertSame(2, EventArrivals::inTheRoom($this->eventId));

        $undone = EventTicketService::undoCheckIn($code, $this->eventId, 'door: Main gate',
                                                  null, 'scanned the wrong ticket');
        $this->assertTrue($undone['ok'], (string) ($undone['message'] ?? ''));
        $this->assertSame(0, EventArrivals::inTheRoom($this->eventId),
            'the room still held two people who had been sent back out');

        // The reversal is IN the log, with its reason, rather than the admission simply
        // vanishing — a record that quietly loses rows is not a record.
        $log = EventArrivals::recent($this->eventId);
        $actions = array_column($log, 'action');
        $this->assertContains('undo', $actions);
        $this->assertContains('scanned the wrong ticket', array_column($log, 'reason'));

        // And they can come back in, because a reversal is a correction and not a ban.
        $this->assertSame('admit', $this->scan($code, 2)['verdict']);
    }

    // ══ ACT 8 · THE MORNING AFTER ════════════════════════════════════════════

    /** The organiser's own reconciliation: what sold, what it made, who came. */
    public function test_14_the_night_reconciles(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 3);
        $this->scan($code, 2);

        $s = EventTicketService::summary($this->eventId);
        $this->assertSame(3, (int) $s['sold'], 'a ticket for three counted as one sale');
        $this->assertGreaterThan(0, (int) $s['revenue']);
        $this->assertSame(200, (int) $s['capacity']);

        // Expected counts the seats SOLD; in-the-room counts the seats that walked in. The
        // gap between them is the number an organiser actually looks at.
        $this->assertSame(3, EventArrivals::expected($this->eventId));
        $this->assertSame(2, EventArrivals::inTheRoom($this->eventId));
    }

    /**
     * Somebody withdraws. The seats go back to the hall AND the old ticket stops opening
     * the door — which is the half that matters and the half that was once missing.
     *
     * `cancel()` is deliberately not the call here: it only touches a hold. A paid seat is
     * withdrawn through `release()`, because a machine must never take back somebody's paid
     * ticket on its own and a refund is a separate decision an organiser makes.
     */
    public function test_15_a_released_ticket_frees_its_seats_and_stops_scanning(): void
    {
        $this->stage();
        $code = $this->sell('Ada Obi', 'ada@example.test', 3);
        $id   = (int) DB::table('gates_event_registrations')->where('ticket_code', $code)->value('id');

        $this->assertSame(3, EventTicketService::soldForEvent($this->eventId));

        $out = EventTicketService::release($id, 'could not come');
        $this->assertTrue($out['ok'], (string) ($out['message'] ?? ''));
        $this->assertSame(3, (int) $out['seats']);
        $this->assertTrue((bool) $out['refund_due'],
            'money was taken and nothing said a refund was owed');

        $this->assertSame(0, EventTicketService::soldForEvent($this->eventId),
            'the seats never came back and the hall is permanently smaller');

        // ── THE CODE IS GONE, NOT MERELY OUTVOTED BY THE STATUS ──────────────
        //
        // Asserted on the COLUMN and not only on the door's answer, because the door
        // refuses a cancelled row anyway — so a release() that stopped clearing the code
        // would leave this scan refusing and the test green, while the ticket code went on
        // existing. It is the same fault the reversal path shipped: a charged-back ticket
        // stayed `confirmed`, and a confirmed row renders a scannable QR on a page
        // reachable with the reference alone. Clearing the code is the point of the method;
        // the status change is bookkeeping around it.
        $this->assertNull(DB::table('gates_event_registrations')->where('id', $id)->value('ticket_code'),
            'the withdrawn ticket still carries a code');
        $this->assertSame('refuse', $this->scan($code, 1)['verdict'],
            'a withdrawn ticket still opened the door');
    }

    // ══ the evening, staged ══════════════════════════════════════════════════

    /** The event, its tiers and its gate. Called by every act — the ordering IS the test. */
    private function stage(): void
    {
        if ($this->eventId > 0) return;

        $this->startUtc = Carbon::now()->addDay()->format('Y-m-d') . ' 18:00:00';

        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'lagos-gala-2026', 'title' => 'The Gala',
            'event_date' => $this->startUtc, 'status' => 'published',
            'timezone' => 'Africa/Lagos', 'capacity' => 200,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        // Premium DRAGGED TO THE TOP: sort_order 0 on the dearer row, which is exactly the
        // arrangement that breaks a ladder ranked by list position.
        $this->premium = $this->tier('premium', 'Premium', 50000, 0);
        $this->standard = $this->tier('standard', 'Standard', 10000, 1);

        $this->door = (string) EventScanPass::issue(
            $this->eventId, Carbon::now()->addHours(12)->toDateTimeString(), null, 'Main gate');
    }

    private function tier(string $slug, string $name, int $price, int $order): int
    {
        return (int) DB::table('gates_event_tiers')->insertGetId([
            'event_id' => $this->eventId, 'slug' => $slug, 'name' => $name,
            'price_naira' => $price, 'capacity' => null, 'min_per_order' => 1,
            'max_per_order' => 10, 'is_active' => 1, 'sort_order' => $order,
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /** Reserve and pay, the way a real booking gets there. Returns the ticket code. */
    private function sell(string $name, string $email, int $qty): string
    {
        $this->stage();

        $r = EventTicketService::reserve($this->eventId, $this->standard,
            ['name' => $name, 'email' => $email, 'phone' => '08031234567'], $qty);
        $this->assertTrue($r['ok'], 'could not reserve: ' . (string) ($r['message'] ?? ''));

        $ref  = (string) $r['reference'];
        $owed = (int) DB::table('gates_event_registrations')->where('reference', $ref)->value('amount_naira');

        $c = EventTicketService::confirm($ref, $this->gateway([$ref => ['status' => 'success', 'amount' => $owed]]));
        $this->assertTrue($c['ok'], 'could not confirm: ' . (string) ($c['message'] ?? ''));

        return (string) $c['ticket_code'];
    }

    private function invite(string $name, string $email,
                           string $audience = InviteAudience::NOMINEE): object
    {
        $this->stage();

        $inv = EventInvites::mint($this->eventId, $audience, ['name' => $name, 'email' => $email]);
        $this->assertNotNull($inv, 'could not invite: ' . EventInvites::lastMintError());

        DB::table('gates_event_invites')->where('id', $inv->id)
            ->update(['sent_at' => Carbon::now()->toDateTimeString()]);

        return (object) DB::table('gates_event_invites')->where('id', $inv->id)->first();
    }

    /**
     * The two lists on the organiser's tickets screen, shaped the way that screen gets them.
     *
     * Reached through the private shaper by reflection rather than by standing the whole
     * admin controller up with a session, an audit log and a Twig environment — and the
     * wiring the reflection cannot see is asserted separately, right below, because a
     * perfect shaper the page does not call is the §18 bug wearing a different hat.
     *
     * @return array{attendees:list<array<string,mixed>>, arrivals:list<array<string,mixed>>}
     */
    private function ticketsScreen(): array
    {
        $c = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/EventsController.php');
        $this->assertSame(2, substr_count($c, '$this->inTheRoomsClock('),
            'the tickets screen shapes only one of its two lists — the other still prints '
            . 'the storage convention');

        $m = new \ReflectionMethod(\AfricaGates\Admin\Controllers\EventsController::class,
                                   'inTheRoomsClock');
        $m->setAccessible(true);
        $ctrl = (new \ReflectionClass(\AfricaGates\Admin\Controllers\EventsController::class))
            ->newInstanceWithoutConstructor();
        $event = (object) DB::table('gates_site_events')->where('id', $this->eventId)->first();

        return [
            'attendees' => $m->invoke($ctrl, $event,
                EventTicketService::attendees($this->eventId), 'checked_in_at', 'arrived'),
            'arrivals'  => $m->invoke($ctrl, $event, EventArrivals::recent($this->eventId, 300)),
        ];
    }

    /**
     * The code on a nominee's screen at this second.
     *
     * Signed with the invite's OWN `id_secret`, which is the whole point of the column: a
     * platform-wide secret would make one leaked code a key to every invitation ever
     * issued. It rotates every 30 seconds so a screenshot passed between two people dies
     * before the second of them reaches the door.
     */
    private function idCode(object $invite): string
    {
        return InvitePass::code((string) $invite->reference, (string) $invite->id_secret);
    }

    /** A scan at the real door, through the real controller. @return array<string,mixed> */
    private function scan(string $code, int $seats = 0): array
    {
        $ctrl = new DoorController($this->twig(), new RateLimitService());
        $req  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/door/' . $this->door . '/check')
            ->withParsedBody(['code' => $code, 'seats' => $seats]);
        $res  = $ctrl->check($req, (new ResponseFactory())->createResponse(), ['token' => $this->door]);

        return json_decode((string) $res->getBody(), true) ?: [];
    }

    private function voiceOn(): void
    {
        DB::table('gates_settings')->insert([
            ['key_name' => 'door_welcome_enabled', 'value' => '1'],
            ['key_name' => 'azure_speech_key', 'value' => 'test-key-not-real'],
        ]);
    }

    /** A clip on disk, without going anywhere near the network. */
    private function plant(string $line): void
    {
        $p = DoorWelcome::pathFor(DoorWelcome::keyFor($line));
        if ($p !== null) file_put_contents($p, 'ID3' . str_repeat("\x00", 128));
    }

    /** @param array<string,array<string,mixed>> $byRef */
    private function gateway(array $byRef): PaymentService
    {
        return new class ($byRef) extends PaymentService {
            public function __construct(private array $byRef) {}
            public function isEnabled(string $p): bool { return $p === 'paystack'; }
            public function enabledProviderIds(): array { return ['paystack']; }
            public function verify(string $p, string $ref): array
            {
                return isset($this->byRef[$ref])
                    ? ['ok' => true, 'currency' => 'NGN'] + $this->byRef[$ref]
                    : ['ok' => false];
            }
        };
    }

    private function twig(): Twig
    {
        return Twig::create(dirname(__DIR__, 2) . '/templates', ['cache' => false]);
    }
}
