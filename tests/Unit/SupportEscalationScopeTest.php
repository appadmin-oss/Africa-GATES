<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportTicketService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A guest must not reach a stranger's ticket by typing their address.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HOLE THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/api/support/escalate` is open to guests, by design — the escape hatch to a
 * human has to work for somebody with no account. It takes an email from the
 * request body so the team has somewhere to reply.
 *
 * That address was then also used to look for an EXISTING open ticket, so a second
 * press would append to the first rather than minting a duplicate reference. Good
 * intention, wrong key. For a guest the address is simply typed, so:
 *
 *   DISCLOSE — supply a stranger's address with a matching first sentence and the
 *     JSON came back with THEIR reference, confirming they have an open complaint
 *     and handing over the number used to look it up.
 *   INJECT — appendEscalation() then wrote the sender's text into that stranger's
 *     thread, where support staff read it. On a desk that issues refunds and
 *     delivers votes, arbitrary text inside somebody else's complaint is a
 *     social-engineering vector rather than a nuisance.
 *
 * ── AND THE SUBJECT MATCH IS NOT THE OBSTACLE IT LOOKS LIKE ──────────────────
 *
 * The subject is the first sentence of the message, normalised for case and
 * punctuation. On this platform the complaints are near-identical — "I paid but my
 * votes have not been added" was written almost verbatim by everyone caught in the
 * unminted-vote incident. Guessing it is not the hard part; there is no hard part.
 *
 * ── THE RULE ─────────────────────────────────────────────────────────────────
 *
 * Deduplication needs a PROVEN identity. A member session is proof. A typed address
 * is not — so for a guest the only safe match is a ticket this same browser opened,
 * which happens to be exactly the case the feature exists for: one person pressing
 * the button again because nobody has answered yet.
 */
final class SupportEscalationScopeTest extends TestCase
{
    private const VICTIM  = 'victim@example.test';
    private const SUBJECT = 'I paid but my votes have not been added';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_support_messages')->delete();
        DB::table('gates_support_tickets')->delete();
        $_SESSION = [];
    }

    /** The victim's open ticket, opened an hour ago. */
    private function victimTicket(): string
    {
        $ref = 'AGS-VICTIM-1';
        DB::table('gates_support_tickets')->insert([
            'reference' => $ref, 'user_id' => null, 'email' => self::VICTIM,
            'name' => 'A Victim', 'subject' => self::SUBJECT,
            'transcript' => '', 'severity' => 'normal', 'status' => 'open',
            'created_at' => Carbon::now()->subHour()->toDateTimeString(),
        ]);
        return $ref;
    }

    /**
     * The service-level lookup still finds it — that is not the bug and must not
     * be "fixed" by weakening the member path, which relies on it.
     */
    public function test_the_lookup_itself_still_matches_on_a_known_address(): void
    {
        $this->victimTicket();

        $found = (new SupportTicketService())->openTicketFor(0, self::VICTIM, self::SUBJECT);

        $this->assertNotNull($found, 'A member with this address should still find their own ticket.');
        $this->assertSame('AGS-VICTIM-1', $found['reference']);
    }

    /**
     * THE ATTACK. A guest who did not open it must not be handed it.
     *
     * Mirrors the controller's rule directly: for a guest, a found ticket counts
     * only when this browser's session recorded opening it.
     */
    public function test_a_guest_who_never_opened_it_is_not_given_the_reference(): void
    {
        $this->victimTicket();

        $isMember    = false;
        $sessionRefs = [];                       // this browser opened nothing
        $found       = (new SupportTicketService())->openTicketFor(0, self::VICTIM, self::SUBJECT);

        $usable = $found !== null
            && ($isMember || in_array($found['reference'], $sessionRefs, true));

        $this->assertFalse($usable,
            'Typing a stranger\'s address returned their ticket reference and let text '
            . 'be appended to their thread.');
    }

    /** The person who really did open it, pressing again, still gets deduplicated. */
    public function test_the_same_browser_pressing_again_still_appends(): void
    {
        $ref = $this->victimTicket();

        $isMember    = false;
        $sessionRefs = [$ref];                   // this browser opened it
        $found       = (new SupportTicketService())->openTicketFor(0, self::VICTIM, self::SUBJECT);

        $usable = $found !== null
            && ($isMember || in_array($found['reference'], $sessionRefs, true));

        $this->assertTrue($usable,
            'The fix must not cost the honest repeat-presser a second reference for '
            . 'one problem — that is the whole reason the check exists.');
    }

    /**
     * The controller enforces it, not just this test's arithmetic.
     *
     * Asserted against the source because the alternative is a test that proves a
     * rule the shipped code does not follow — which is how the CSRF field name and
     * the ?q= handover both survived having tests nearby.
     */
    public function test_the_controller_gates_the_match_on_session_or_membership(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Controllers/SupportController.php');

        $this->assertStringContainsString("\$_SESSION['support_refs']", $src,
            'The guest\'s own tickets have to be remembered somewhere, or the '
            . 'duplicate check cannot work for them at all.');

        $this->assertMatchesRegularExpression(
            '~\$existing !== null && !\$m\s*\n\s*&& !in_array\(~',
            $src,
            'A guest match must be discarded unless this browser opened that ticket.');
    }

    /**
     * A ticket belonging to a DIFFERENT address is never a match, member or not.
     *
     * The second line of defence: even a signed-in member must not be handed
     * somebody else's thread because the subject happened to collide.
     */
    public function test_a_different_address_never_matches(): void
    {
        $this->victimTicket();

        $found = (new SupportTicketService())->openTicketFor(0, 'someone.else@example.test', self::SUBJECT);

        $this->assertNull($found, 'Identical complaints from two people are two tickets.');
    }

    /** An old ticket is not resurrected — the window is deliberately short. */
    public function test_a_stale_ticket_is_not_matched(): void
    {
        DB::table('gates_support_tickets')->insert([
            'reference' => 'AGS-OLD-1', 'user_id' => null, 'email' => self::VICTIM,
            'name' => 'A Victim', 'subject' => self::SUBJECT,
            'transcript' => '', 'severity' => 'normal', 'status' => 'open',
            'created_at' => Carbon::now()->subDays(10)->toDateTimeString(),
        ]);

        $this->assertNull((new SupportTicketService())->openTicketFor(0, self::VICTIM, self::SUBJECT));
    }

    /**
     * Near-identical complaints normalise to the same subject.
     *
     * Recorded so nobody later argues the attack needed an improbable guess: on
     * this platform the sentences really are this close.
     */
    public function test_the_subject_match_is_easy_to_hit_on_purpose(): void
    {
        $a = SupportTicketService::subjectFrom('I paid but my votes have not been added. Please help.');
        $b = SupportTicketService::subjectFrom('I PAID BUT MY VOTES HAVE NOT BEEN ADDED! Anyone there?');

        $norm = static function (string $s): string {
            return trim((string) preg_replace('/\s+/u', ' ',
                mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s))));
        };

        $this->assertSame($norm($a), $norm($b),
            'Two strangers writing the same sentence collide — which is why the '
            . 'address, not the subject, had to be the thing that is proven.');
    }
}
