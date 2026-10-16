<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Ask, then act. Gee may file a refund REQUEST for a signed-in member's own payment,
 * and only after the member has said yes to that specific reference and amount.
 *
 * The consent is decided in code, from the person's own reply and from the assistant's
 * previous turn having proposed the request. A model cannot grant it to itself.
 */
final class GeeRefundRequestTest extends TestCase
{
    private const REF = 'AFG-PVOTE-REFUND1';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->insert([
            'donor_name' => 'Ada N', 'donor_email' => 'ada@example.com', 'amount_naira' => 2000,
            'status' => 'confirmed', 'payment_ref' => self::REF, 'tier' => 'paid-vote',
        ]);
    }

    private static function member(bool $consented = false): SupportContext
    {
        return (new SupportContext(viewerId: 42, viewerEmail: 'ada@example.com'))->withConsent($consented);
    }

    public function test_without_a_yes_it_proposes_and_files_nothing(): void
    {
        $r = self::member()->run('request_refund', ['reference' => self::REF, 'reason' => 'votes did not arrive']);

        $this->assertSame('NEEDS_CONFIRMATION', $r['data']['outcome'] ?? null);
        $this->assertStringContainsString(self::REF, (string) $r['data']['say']);
        $this->assertStringContainsString('₦2,000', (string) $r['data']['say'], 'the proposal names the amount');
        $this->assertSame(0, DB::table('gates_support_tickets')->count(), 'nothing was filed before the yes');
    }

    public function test_after_a_yes_it_files_once(): void
    {
        $r = self::member(true)->run('request_refund', ['reference' => self::REF, 'reason' => 'votes did not arrive']);
        $this->assertSame('REQUESTED', $r['data']['outcome'] ?? null);
        $ticket = (string) $r['data']['ticket'];
        $this->assertNotSame('', $ticket);
        $this->assertSame(1, DB::table('gates_support_tickets')->where('reference', $ticket)->count());
        $this->assertStringContainsString('not an approval', (string) $r['data']['say']);

        $again = self::member(true)->run('request_refund', ['reference' => self::REF, 'reason' => 'again']);
        $this->assertSame('ALREADY_REQUESTED', $again['data']['outcome'] ?? null);
        $this->assertSame($ticket, $again['data']['ticket'] ?? null);
        $this->assertSame(1, DB::table('gates_support_tickets')->count(), 'one payment, one request');
    }

    public function test_somebody_elses_payment_is_refused_without_saying_whose(): void
    {
        $other = (new SupportContext(viewerId: 7, viewerEmail: 'grace@example.com'))->withConsent(true);
        $r = $other->run('request_refund', ['reference' => self::REF, 'reason' => 'x']);

        $this->assertFalse((bool) ($r['data']['ok'] ?? true));
        $this->assertStringNotContainsString('ada', mb_strtolower((string) json_encode($r)));
        $this->assertSame(0, DB::table('gates_support_tickets')->count());
    }

    public function test_a_guest_is_not_offered_the_tool(): void
    {
        $this->assertNotContains('request_refund', array_column(SupportContext::fromSession()->tools(), 'name'));
        $this->assertContains('request_refund', array_column(self::member()->tools(), 'name'));
    }

    /** A yes counts only as an answer to a proposal, and only when it is plainly a yes. */
    public function test_consent_is_read_from_the_reply_and_the_proposal(): void
    {
        $proposed = [['role' => 'user', 'content' => 'my votes did not arrive, I want a refund'],
                     ['role' => 'assistant', 'content' => 'Shall I file a refund request for AFG-PVOTE-REFUND1 (₦2,000)?']];

        foreach (['yes', 'Yes please', 'ok', 'go ahead', 'please do'] as $yes) {
            $this->assertTrue(SupportAgentService::confirmsProposal($yes, $proposed), $yes);
        }
        foreach (['no', 'yes but not now', "don't", 'wait', 'what is a refund request?'] as $notYes) {
            $this->assertFalse(SupportAgentService::confirmsProposal($notYes, $proposed), $notYes);
        }
        $nothingProposed = [['role' => 'assistant', 'content' => 'Voting closes at 23:59.']];
        $this->assertFalse(SupportAgentService::confirmsProposal('ok', $nothingProposed), 'a stray ok consents to nothing');
        $this->assertFalse(SupportAgentService::confirmsProposal('yes', []), 'no proposal, no consent');
    }
}
