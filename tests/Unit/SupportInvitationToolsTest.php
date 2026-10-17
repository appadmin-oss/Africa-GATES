<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportPlan;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The support desk and the guests of honour.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GAP THESE CLOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every tool the assistant had was about paying for votes: repair a payment, resend a
 * receipt, check a refund, is the gateway up. A guest of honour has paid for NOTHING.
 * They were shortlisted or they sat on the panel, they were sent a letter, and the
 * countdown letters now write to them five times in the final week — so "tomorrow is the
 * gala and I can't find my pass" is a message this desk will receive, and every answer it
 * had sent that person hunting for a receipt that does not exist.
 *
 * Worse, and this is the part that was actually shipped: an invitation reference is
 * `AGI-` plus eight characters, which matched the pattern for a bank or wallet app's own
 * transaction number exactly. So a nominee quoting the code from their own invitation was
 * told it looked like a bank's number and given directions to a confirmation page they
 * had never seen.
 */
final class SupportInvitationToolsTest extends TestCase
{
    private int $eventId = 0;
    private string $ref = '';

    protected function setUp(): void
    {
        parent::setUp();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'The Incredible Principal Awards 2026',
            'event_date' => Carbon::now()->addDays(4)->setTime(18, 0)->toDateTimeString(),
            'status' => 'published', 'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
        EventInvites::setProgrammes($this->eventId, [$pid]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $this->eventId, 'slug' => 'supporter', 'name' => 'Supporter',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1,
        ]);

        $inv = EventInvites::mint($this->eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);
        $this->ref = (string) $inv->reference;
    }

    private function guest(): SupportContext
    {
        return new SupportContext(null, null);
    }

    /** @return list<string> */
    private function planned(string $message): array
    {
        return array_column(SupportPlan::steps($message, $this->guest()), 'tool');
    }

    // ══ THE MISREAD REFERENCE ═══════════════════════════════════════════════

    /**
     * The bug that was live: `AGI-XXXXXXXX` read as a bank transaction number.
     *
     * "three to twelve letters, a dash, six or more word characters" is exactly the shape
     * of an invitation reference, and it sat above nothing that would catch it first.
     */
    public function test_an_invitation_reference_is_not_mistaken_for_a_bank_number(): void
    {
        $r = $this->guest()->run('check_reference', ['reference' => $this->ref]);

        $this->assertTrue($r['ok']);
        $this->assertSame('invitation', $r['data']['shape'],
            'a guest of honour was being told their code looked like a wallet app transaction');
        $this->assertStringContainsString('invitation_lookup', $r['data']['say'],
            'and pointed at the tool that can actually answer them');
    }

    // ══ THE LOOKUP ══════════════════════════════════════════════════════════

    public function test_the_desk_can_find_a_guest_of_honour_from_their_reference(): void
    {
        $r = $this->guest()->run('invitation_lookup', ['reference' => $this->ref]);

        $this->assertTrue($r['ok']);
        $d = $r['data'];

        $this->assertTrue($d['found']);
        $this->assertSame('Ada Obi', $d['name']);
        $this->assertSame('Nominee', $d['invited_as']);
        $this->assertSame('The Incredible Principal Awards 2026', $d['event']);
        $this->assertStringContainsString('Eko Convention Centre', $d['where']);
        $this->assertStringContainsString('/honour/' . $this->ref, $d['pass_url']);
        $this->assertSame(25, $d['guests_allowed']);
        // "I never got it" and "it was never sent" are opposite problems — one is a
        // delivery question, the other is the organiser's to finish.
        $this->assertFalse($d['invitation_sent']);
    }

    /** Lower case, because nobody retypes a reference in the case they were given it. */
    public function test_the_reference_is_matched_however_it_was_typed(): void
    {
        $r = $this->guest()->run('invitation_lookup', ['reference' => ' ' . strtolower($this->ref) . ' ']);

        $this->assertTrue($r['data']['found']);
    }

    /**
     * The address is the one fact on the row the pass does not show.
     *
     * The reference is already the key to the pass, so returning what the pass shows to
     * somebody holding it discloses nothing new. An email address is a different question.
     */
    public function test_the_lookup_never_hands_back_the_invitees_address(): void
    {
        $r = $this->guest()->run('invitation_lookup', ['reference' => $this->ref]);

        $this->assertStringNotContainsString('ada@example.com', json_encode($r));
    }

    /**
     * There is no lookup by email, and that is the security model.
     *
     * A lookup by address turns the desk into an oracle for "was this person nominated?",
     * asked one address at a time by anybody who can open a chat, about a shortlist that
     * may not be public yet.
     */
    public function test_there_is_no_way_to_ask_whether_an_address_was_invited(): void
    {
        $tool = null;
        foreach ($this->guest()->tools() as $t) {
            if ($t['name'] === 'invitation_lookup') $tool = $t;
        }

        $this->assertNotNull($tool);
        $this->assertSame(['reference'], array_keys($tool['args']),
            'an email argument here would make the shortlist enumerable one address at a time');
    }

    /** Never "you were not invited" — the alphabet is built to be misread. */
    public function test_an_unmatched_reference_is_treated_as_a_typo_not_a_denial(): void
    {
        $r = $this->guest()->run('invitation_lookup', ['reference' => 'AGI-ZZZZZZZZ']);

        $this->assertFalse($r['data']['found']);
        $say = strtolower($r['data']['say']);
        $this->assertStringContainsString('mistyped', $say);
        $this->assertStringContainsString('do not tell them they were not invited', $say);
    }

    public function test_no_reference_asks_for_one_rather_than_refusing(): void
    {
        $r = $this->guest()->run('invitation_lookup', ['reference' => '']);

        $this->assertFalse($r['data']['found']);
        $this->assertStringContainsString('AGI-', $r['data']['say']);
    }

    // ══ THE EVENING ═════════════════════════════════════════════════════════

    public function test_the_desk_can_say_when_and_where_the_ceremony_is(): void
    {
        $r = $this->guest()->run('event_details', ['name' => '']);

        $this->assertTrue($r['data']['found']);
        $this->assertSame('The Incredible Principal Awards 2026', $r['data']['event']);
        $this->assertStringContainsString('Eko Convention Centre', $r['data']['where']);
        $this->assertSame([['name' => 'Supporter', 'naira' => 5000]], $r['data']['tiers']);

        // WITH the zone. A support answer that says "18:00" to somebody flying in is an
        // answer they cannot act on.
        $this->assertStringContainsString(\AfricaGates\Support\DisplayTime::abbr(), $r['data']['when']);
    }

    /** The next one coming up, not the newest row an organiser happened to add. */
    public function test_the_next_event_means_the_next_one_not_the_latest_added(): void
    {
        DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2028', 'title' => 'A Much Later Gala',
            'event_date' => Carbon::now()->addYears(2)->toDateTimeString(), 'status' => 'published',
        ]);

        $this->assertSame('The Incredible Principal Awards 2026',
            $this->guest()->run('event_details', ['name' => ''])['data']['event']);
    }

    public function test_an_unpublished_event_is_not_described(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)->update(['status' => 'draft']);

        $r = $this->guest()->run('event_details', ['name' => 'Principal']);

        $this->assertFalse($r['data']['found']);
        $this->assertStringContainsString('No published event matches', $r['data']['say'] ?? '',
            'a draft is not describable, and the desk should ask which ceremony they mean');
    }

    public function test_a_missing_event_is_never_invented(): void
    {
        DB::table('gates_site_events')->delete();

        $this->assertStringContainsString('Do not invent a date',
            $this->guest()->run('event_details', ['name' => ''])['data']['say']);
    }

    // ══ THE MODEL-FREE PLANNER ══════════════════════════════════════════════

    /**
     * The assistant works with no AI key at all, so the rules half has to route these too
     * — otherwise the tools exist and are never reached on exactly the deployments that
     * most need them.
     */
    public function test_an_invitation_reference_plans_the_lookup_without_a_model(): void
    {
        $tools = $this->planned('Hello, my reference is ' . $this->ref . ' and I cannot open my pass');

        $this->assertContains('invitation_lookup', $tools);
        $this->assertNotContains('fix_payment', $tools,
            'they have not paid for anything — a payment repair sends them hunting for a receipt');
    }

    public function test_being_invited_plans_the_lookup_even_with_no_reference(): void
    {
        $this->assertContains('invitation_lookup',
            $this->planned('I was shortlisted and invited but I cannot find my pass anywhere'));
    }

    public function test_asking_when_it_starts_plans_the_event_and_not_a_help_search(): void
    {
        $this->assertContains('event_details', $this->planned('what time does the gala start?'));
        $this->assertContains('event_details', $this->planned('where is the ceremony being held'));
    }

    /**
     * Precision, which is the bar this planner is held to.
     *
     * A wrong tool is worse than no tool. "I paid and my votes never arrived" must not
     * pick up the invitation branch just because the platform now has one.
     */
    public function test_a_payment_complaint_is_untouched_by_the_new_branches(): void
    {
        $tools = $this->planned('I paid with reference AFG-ABC-1234abcd and my votes never arrived');

        $this->assertContains('fix_payment', $tools);
        $this->assertNotContains('invitation_lookup', $tools);
        $this->assertNotContains('event_details', $tools);
    }
}
