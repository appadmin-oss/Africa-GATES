<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\VoteFront;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Owner, 1 Nov 2026: an award set to paid voting was described as "One free vote per category,
 * plus optional contributed votes" and "Anyone who confirms their vote with an email code". With
 * free voting switched off there is no free vote, and the page people decide from must say so.
 */
final class PaidOnlyVoteCopyTest extends TestCase
{
    private function set(string $k, string $v): void
    {
        DB::table('gates_settings')->where('key_name', $k)->delete();
        DB::table('gates_settings')->insert(['key_name' => $k, 'value' => $v]);
    }

    /** @return array{facts:string, faq:string} */
    private function copy(): array
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'm-' . bin2hex(random_bytes(3)), 'title' => 'Music Awards', 'is_active' => 1]);
        $v = VoteFront::programme(['id' => $pid, 'title' => 'Music Awards', 'slug' => 'm', 'subtitle' => '', 'cycle' => null, 'phase' => []], [], null);
        return ['facts' => implode(' | ', array_map(static fn ($f) => $f['k'] . ': ' . $f['v'], $v['facts'])),
                'faq' => implode(' | ', array_map(static fn ($f) => implode(' ', $f), $v['faq']))];
    }

    public function test_paid_only_voting_never_promises_a_free_vote(): void
    {
        $this->set('paid_voting_enabled', '1');
        $this->set('paid_voting_disable_free', '1');
        $c = $this->copy();
        $this->assertStringContainsString('contribution that helps fund the awards', $c['facts']);
        $this->assertStringContainsString('contribution of ₦', $c['faq']);
        // Owner: "the mindset is not that we want their money" — no price-list or purchase words.
        foreach (['Paid voting', 'paying for', 'buy'] as $w) $this->assertStringNotContainsStringIgnoringCase($w, $c['facts'] . $c['faq']);
        $this->assertStringNotContainsString('free vote per category', $c['facts']);
        $this->assertStringNotContainsString('email code', $c['facts']);
        $this->assertStringNotContainsString('One free vote', $c['faq']);
    }

    public function test_free_and_mixed_voting_keep_their_own_words(): void
    {
        $this->set('paid_voting_enabled', '1');
        $this->set('paid_voting_disable_free', '');
        $this->assertStringContainsString('One free vote per category, plus optional contributed votes', $this->copy()['facts']);
        $this->set('paid_voting_enabled', '');
        $this->assertStringContainsString('Free, one vote per category', $this->copy()['facts']);
    }
}
