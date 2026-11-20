<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\VoteRecruit;
use AfricaGates\Services\VoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "Bring your people" (Services\VoteRecruit): a voter's own link for a nominee, and the count of
 * people who voted through it.
 *
 *   A PERSON, ONCE     counted by the ledger's own voter hash, once however often they vote
 *   NOT YOURSELF       the link's owner voting through their own link brings nobody
 *   THE SAME NOMINEE   a link for one nominee credits nothing for another
 *   MONEY THAT LANDED  a paid order counts when its votes are minted, never at checkout
 *   NO TALLY           it writes to its own tables only; nothing here touches a vote count
 */
final class VoteRecruitTest extends TestCase
{
    private function h(string $email): string { return VoteService::voterHash($email); }

    public function test_a_person_counts_once_and_the_owner_never(): void
    {
        $mine = VoteRecruit::forVoter($this->h('ada@afrovanguard.example'), 7);
        $this->assertNotNull($mine);
        $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{8}$/', $mine['code'], 'readable aloud: no 0/O, 1/I/L');
        $this->assertSame(0, $mine['brought']);

        $this->assertTrue(VoteRecruit::credit($mine['code'], 7, $this->h('bola@afrovanguard.example'), 'vote:1'));
        $this->assertFalse(VoteRecruit::credit($mine['code'], 7, $this->h('bola@afrovanguard.example'), 'vote:2'), 'the same person twice');
        $this->assertFalse(VoteRecruit::credit($mine['code'], 7, $this->h('ada@afrovanguard.example'), 'vote:3'), 'the owner');
        $this->assertFalse(VoteRecruit::credit($mine['code'], 8, $this->h('chidi@afrovanguard.example'), 'vote:4'), 'another nominee');
        $this->assertFalse(VoteRecruit::credit('nosuchcode', 7, $this->h('chidi@afrovanguard.example'), 'vote:5'));
        $this->assertTrue(VoteRecruit::credit(strtoupper($mine['code']), 7, $this->h('chidi@afrovanguard.example'), 'vote:6'), 'a code typed in capitals');

        $this->assertSame(2, VoteRecruit::broughtByCode($mine['code']));
        $again = VoteRecruit::forVoter($this->h('ada@afrovanguard.example'), 7);
        $this->assertSame($mine['code'], $again['code'], 'one link per voter per nominee');
        $this->assertSame(2, $again['brought']);
    }

    public function test_a_paid_order_counts_when_minted_not_at_checkout(): void
    {
        $code = VoteRecruit::forVoter($this->h('ada@afrovanguard.example'), 9)['code'];
        VoteRecruit::pend('AFG-TEST-0001', $code);
        $this->assertSame(0, VoteRecruit::broughtByCode($code), 'nothing at checkout');

        $id = (int) DB::table('gates_donations')->insertGetId([
            'donor_name' => '', 'donor_email' => 'dele@afrovanguard.example', 'amount_naira' => 500, 'tier' => 'paid-vote',
            'bonus_votes' => 5, 'votes_used' => 0, 'intent_nominee_id' => 9, 'payment_ref' => 'AFG-TEST-0001', 'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertTrue(VoteRecruit::creditPaid($id));
        $this->assertFalse(VoteRecruit::creditPaid($id), 'a replayed mint counts nobody twice');
        $this->assertSame(1, VoteRecruit::broughtByCode($code));
    }

    public function test_the_hooks_run_only_after_the_vote_has_counted(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/ApiController.php');
        $refuse = strpos($api, "if(!\$r['success']) return \$this->err(\$res,\$r['message'],\$r['code']);");
        $credit = strpos($api, 'VoteRecruit::credit(');
        $this->assertNotFalse($refuse); $this->assertNotFalse($credit);
        $this->assertGreaterThan($refuse, $credit, 'a refused vote credits nobody');

        $mint = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/PaidVoteService.php');
        $this->assertMatchesRegularExpression("/VoteRecruit::creditPaid\(\(int\) \\\$don->id\); \} catch \(\\\\Throwable\) \{\}\s+return \['ok' => true, 'minted' => \\\$qty\];/", $mint,
            'credited at the successful end of the mint, and unable to fail it');

        // It writes nowhere near a tally.
        $svc = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/VoteRecruit.php');
        preg_match_all("/DB::table\('([a-z_]+)'\)->(?:insert|update|increment|delete)/", $svc, $w);
        foreach ($w[1] as $t) $this->assertStringStartsWith('gates_vote_recruit', $t);
    }

    /** A nominee standing in an open vote, and the page that shows the link once it is held. */
    private function openNominee(): array
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId(['slug' => 'bring-' . bin2hex(random_bytes(3)), 'title' => 'Teachers Prize', 'is_active' => 1]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId(['programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'voting',
            'nominations_open' => date('Y-m-d H:i:s', time() - 40 * 86400), 'nominations_close' => date('Y-m-d H:i:s', time() - 20 * 86400),
            'voting_open' => date('Y-m-d H:i:s', time() - 86400), 'voting_close' => date('Y-m-d H:i:s', time() + 20 * 86400)]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $cid, 'slug' => 'toy', 'title' => 'Teacher of the Year', 'sort_order' => 1]);
        $nid = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => 'Adaeze Okafor', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        $slug = (string) DB::table('gates_award_programmes')->where('id', $pid)->value('slug');
        return [$nid, '/vote/' . $slug . '/' . $nid . '-adaeze-okafor'];
    }

    public function test_the_ballot_hands_back_the_voters_own_link_and_its_count(): void
    {
        [$nid, $path] = $this->openNominee();
        $this->assertStringNotContainsString('vb-bring', ChromeRender::html($path), 'nobody has voted here: no link');

        $mine = VoteRecruit::forVoter($this->h('ada@afrovanguard.example'), $nid);
        VoteRecruit::credit($mine['code'], $nid, $this->h('bola@afrovanguard.example'), 'vote:1');
        $html = ChromeRender::html($path, ['vote_recruit' => [$nid => $mine['code']]]);
        $this->assertStringContainsString('Bring your people', $html);
        $this->assertMatchesRegularExpression('~value="[^"]*/vote/bring-[a-f0-9]+/' . $nid . '\?via=' . $mine['code'] . '"~', $html, 'the short link, carrying the code');
        $this->assertStringContainsString('You’ve brought 1 person.', $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html, 'the same share buttons every nominee gets');
    }
}
