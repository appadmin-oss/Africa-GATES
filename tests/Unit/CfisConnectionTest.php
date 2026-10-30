<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CfisCatalogue;
use AfricaGates\Services\CfisReturns;
use AfricaGates\Services\CfisTags;
use AfricaGates\Services\CommunityReturnMilestone as Milestone;
use AfricaGates\Services\CommunityReturnService as Ret;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Africa GATES into CACENTRE finance's revenue sharing (owner, 31 Oct 2026).
 *
 * What these try to break: a payment finance cannot read the kind of (it read `purpose`, and
 * only `donation` was one of its codes); a paid vote with no award on it; a caller's own tags
 * overwritten; a return reported before it is signed, or not reported again when it is
 * reversed; and a failed push that moves the watermark and so never sends again.
 */
final class CfisConnectionTest extends TestCase
{
    /** @var list<array{url:string, claims:array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 1, 'slug' => 'teachers', 'title' => 'Teachers', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting',
            'voting_open' => Carbon::now()->subDays(5)->toDateTimeString(), 'voting_close' => Carbon::now()->addDays(5)->toDateTimeString()]);
        DB::table('gates_award_categories')->insert(['id' => 1, 'cycle_id' => 1, 'slug' => 'science', 'title' => 'Science']);
        DB::table('gates_nominees')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Ada', 'status' => 'approved']);
        $this->assertTrue(Milestone::saveEditionRules(1, ['model' => 'pool', 'full_pct' => 20, 'milestone_naira' => 10000])['ok']);
        foreach (['cfis_url' => 'https://cfis.test', 'cfis_source' => 'afg', 'cfis_secret' => str_repeat('s', 40)] as $k => $v) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $k], ['value' => $v]);
        }
        $this->sent = [];
        CfisReturns::$transport = function (string $url, string $token): array {
            $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
            $this->sent[] = ['url' => $url, 'claims' => $claims, 'sig' => explode('.', $token)[2]];
            return ['ok' => true];
        };
    }

    protected function tearDown(): void
    {
        CfisReturns::$transport = null;
        parent::tearDown();
    }

    private function paidVote(string $ref, int $naira, bool $accrue = true): int
    {
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Chidi', 'donor_email' => 'chidi@example.com',
            'amount_naira' => $naira, 'tier' => 'paid-vote', 'bonus_votes' => 0, 'votes_used' => 1, 'intent_nominee_id' => 1,
            'payment_ref' => $ref, 'status' => 'confirmed', 'created_at' => Carbon::now()->toDateTimeString()]);
        if ($accrue) $this->assertTrue(Ret::accrue($id)['ok']);
        return $id;
    }

    public function test_every_purpose_this_platform_charges_is_a_kind_finance_knows(): void
    {
        $finance = ['voting', 'ticket_sales', 'sponsorship', 'donation', 'merchandise'];
        foreach (CfisTags::KINDS as $purpose => $kind) {
            $this->assertContains($kind, $finance, $purpose . ' maps to a code finance does not have');
        }
        // Every purpose a checkout actually sends is mapped: read them out of the controllers.
        $src = '';
        foreach (glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') as $f) $src .= file_get_contents($f);
        preg_match_all("/'purpose'\\s*=>\\s*'([a-z-]+)'/", $src, $m);
        foreach (array_unique($m[1]) as $purpose) {
            $this->assertArrayHasKey($purpose, CfisTags::KINDS, "a checkout sends purpose '$purpose' and finance would refuse it");
        }
    }

    public function test_a_paid_vote_carries_its_award_edition_and_category_and_a_callers_own_tags_win(): void
    {
        $this->paidVote('AFG-PV1', 1000, false);
        $t = CfisTags::for('AFG-PV1', ['purpose' => 'paid-vote']);
        $this->assertSame('voting', $t['cfis_kind']);
        $this->assertSame(['programme' => 'teachers', 'edition' => 'teachers-2026', 'category' => 'science'], $t['cfis_labels']);

        $this->assertSame(['cfis_kind' => 'ticket_sales', 'cfis_labels' => ['event' => 'gala']], CfisTags::for('AFG-EVT1', ['purpose' => 'event', 'event' => 'gala']));
        $this->assertSame([], CfisTags::for('X', ['purpose' => 'unheard-of']), 'nothing invented');

        $meta = ['purpose' => 'paid-vote', 'cfis_kind' => 'donation'];
        $meta += CfisTags::for('AFG-PV1', $meta);
        $this->assertSame('donation', $meta['cfis_kind'], 'the caller said what it is');
    }

    public function test_returns_are_reported_signed_by_reference_and_again_when_reversed(): void
    {
        $a = $this->paidVote('AFG-A', 10000);   // 20% → ₦2,000
        $this->paidVote('AFG-B', 5000);         // → ₦1,000
        $this->assertSame(2, CfisReturns::push());
        $this->assertSame('https://cfis.test/api/returns/afg', $this->sent[0]['url']);
        $rows = array_column($this->sent[0]['claims']['returns'], 'amount_minor', 'reference');
        $this->assertSame(['returns'], array_values(array_unique(array_column($this->sent[0]['claims']['returns'], 'to'))), 'an open pool is returns');
        $this->assertSame(['AFG-A' => 200000, 'AFG-B' => 100000], $rows);
        $body = explode('.', CfisCatalogue::sign(str_repeat('s', 40), ['x' => 1]));
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $body[1], str_repeat('s', 40), true)), '+/', '-_'), '='), $body[2], 'signed with the platform\'s secret');

        $this->assertSame(0, CfisReturns::push(), 'nothing changed, nothing sent');

        Ret::reverse($a, 'refunded');
        $this->assertSame(1, CfisReturns::push());
        $this->assertSame([['reference' => 'AFG-A', 'amount_minor' => 0, 'to' => 'returns']], $this->sent[1]['claims']['returns'], 'a refund reports 0, not silence');
    }

    public function test_a_refused_push_is_sent_again_next_time(): void
    {
        $this->paidVote('AFG-C', 10000);
        CfisReturns::$transport = fn () => ['ok' => false, 'error' => 'Signature does not match.'];
        try {
            CfisReturns::push();
            $this->fail('a refusal was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Signature does not match', $e->getMessage());
        }
        CfisReturns::$transport = fn () => ['ok' => true];
        $this->assertSame(1, CfisReturns::push(), 'the watermark did not move on the refusal');
    }

    public function test_nothing_is_sent_where_finance_is_not_configured(): void
    {
        $this->paidVote('AFG-D', 10000);
        DB::table('gates_settings')->where('key_name', 'cfis_secret')->delete();
        $this->assertSame(0, CfisReturns::push());
        $this->assertSame([], $this->sent);
    }

    public function test_a_pool_nobody_reached_is_reported_again_as_reserves(): void
    {
        $this->paidVote('AFG-E', 10000);
        $this->paidVote('AFG-F', 5000);
        $this->assertSame(2, CfisReturns::push());

        // Voting closes; nobody reached the 100-vote target, so the pool goes to the reserves.
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results', 'voting_close' => Carbon::now()->subDay()->toDateTimeString()]);
        $r = \AfricaGates\Services\CommunityReturnPool::settle(1, 1);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame(0, (int) \AfricaGates\Services\CommunityReturnPool::settlement(1)->winners);

        $this->assertSame(2, CfisReturns::push(), 'the settlement alone sends the edition again');
        $last = end($this->sent)['claims']['returns'];
        $this->assertSame(['reserves'], array_values(array_unique(array_column($last, 'to'))));
        $this->assertSame(['AFG-E' => 200000, 'AFG-F' => 100000], array_column($last, 'amount_minor', 'reference'), 'the same money, now to the reserves');
        $this->assertSame(0, CfisReturns::push(), 'and only once');
    }

    public function test_a_pool_somebody_reached_stays_returns(): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => 1, 'category_id' => 1, 'voter_email_hash' => 'h', 'vote_type' => 'standard', 'weight' => 150, 'fraud_flag' => 0]);
        $this->paidVote('AFG-G', 10000);
        CfisReturns::push();
        DB::table('gates_award_cycles')->where('id', 1)->update(['status' => 'results', 'voting_close' => Carbon::now()->subDay()->toDateTimeString()]);
        $this->assertTrue(\AfricaGates\Services\CommunityReturnPool::settle(1, 1)['ok']);
        CfisReturns::push();
        $this->assertSame('returns', end($this->sent)['claims']['returns'][0]['to']);
    }
}
