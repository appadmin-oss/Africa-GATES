<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The owner's own conversation, replayed (16 Oct 2026), graded the way support agents
 * are graded: on what the platform looked up and what it said, every turn, not on
 * whether one turn read well. With no model at all, the deterministic road must still act.
 *
 *   "My payment didn't go through"
 *   "Can you help check out the payments I've made. One of my votes did not go through
 *    and I need a refund"
 *   "Hurry"
 *   "When will you finish? You do not seem to be doing anything"
 *
 * The first time this ran for real, every reply after the first was "I will check your
 * payments, please hold on", nothing was checked, and it ended in a ticket.
 */
final class GeeScenarioTest extends TestCase
{
    private const REF = 'AFG-PVOTE-SCENE1';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_donations')->insert([
            'donor_name' => 'Chioma O', 'donor_email' => 'chioma@example.com', 'amount_naira' => 2000,
            'status' => 'confirmed', 'payment_ref' => self::REF, 'tier' => 'paid-vote',
            'bonus_votes' => 10, 'votes_used' => 0,
        ]);
    }

    public function test_every_turn_of_the_owners_conversation_looks_up_their_payments(): void
    {
        $ctx   = new SupportContext(viewerId: 42, viewerEmail: 'chioma@example.com');
        $agent = new SupportAgentService(null);   // no provider: the rules choose, the tools speak
        $turns = ["Can you help check out the payments I've made. One of my votes did not go through and I need a refund",
                  'Hurry',
                  'When will you finish? You do not seem to be doing anything'];
        $history = [['role' => 'user', 'content' => "My payment didn't go through"],
                    ['role' => 'assistant', 'content' => 'Sorry about that.']];

        foreach ($turns as $message) {
            $r = $agent->ask($message, $history, $ctx, [], false);
            $this->assertContains('my_transactions', $r['used'], "\"$message\" did not look up the member's own payments");
            $this->assertFalse(SupportAgentService::promisesInsteadOfActing($r['reply']), "\"$message\" was answered with a promise: {$r['reply']}");
            $found = json_encode($r['results']);
            $this->assertStringContainsString(self::REF, $found, 'the undelivered order was not in front of the answer');
            $this->assertStringContainsString('NOT delivered', $found, 'the lookup did not say the votes never arrived');
            $history[] = ['role' => 'user', 'content' => $message];
            $history[] = ['role' => 'assistant', 'content' => $r['reply']];
        }
    }
}
