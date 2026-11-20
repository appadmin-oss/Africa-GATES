<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SupporterRecord;
use AfricaGates\Services\SupporterSpotlight;
use AfricaGates\Services\VoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "MY SUPPORT" AND THE CERTIFICATE (SupporterRecord, /account/supporting,
 * /account/supporting/certificate/{cycle}, /certificates/{code}).
 *
 * What these try to break: a member's record missing votes that are provably theirs (an order
 * paid from their address, their points, their wallet) or including ones that are not (a
 * stranger's, a refunded order, a fraud-flagged row, the sandbox); a badge bought with money;
 * "every edition" for somebody who skipped one; a certificate for an edition that earned none,
 * or one that checks out with a forged code; the record missing from the account rail.
 *
 * Ids below 255 (TINYINT clamp on MySQL); one ballot row per vote.
 */
final class SupporterRecordTest extends TestCase
{
    private const P = 93, Y = 9301, Y0 = 9300, C = 9311, C0 = 9310, A = 9321, B = 9322, OLD = 9320;
    private const SANDBOX = 94, SY = 9401, SC = 9411, SN = 9421;
    private const EMAIL = 'amina@example.test';

    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SupporterSpotlight::$ttl = 0;
        $year = (int) date('Y');
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'pride-award', 'title' => 'Pride Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => $year, 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-5 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+5 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
        DB::table('gates_nominees')->insert(['id' => self::A, 'name' => 'Chiamaka Eze', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_nominees')->insert(['id' => self::B, 'name' => 'Ibrahim Musa', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        $this->uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Amina Bello', 'email' => self::EMAIL, 'status' => 'active', 'email_verified' => 1, 'points' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    }

    protected function tearDown(): void
    {
        SupporterSpotlight::$ttl = 60;
        parent::tearDown();
    }

    private function vote(string $hash, int $nominee, int $cat = self::C, string $at = '-2 days', string $type = 'standard', ?int $don = null, int $fraud = 0): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => $cat, 'vote_type' => $type, 'weight' => 1, 'voter_email_hash' => $hash,
            'donation_id' => $don, 'fraud_flag' => $fraud, 'voter_name' => '', 'show_name' => 0, 'voted_at' => date('Y-m-d H:i:s', strtotime($at))]);
    }

    private function order(string $email, int $votes, int $nominee, string $at = '-1 day', bool $refunded = false): void
    {
        $row = ['donor_name' => 'Supporter', 'donor_email' => $email, 'amount_naira' => $votes * 200, 'status' => 'confirmed',
            'payment_ref' => 'sr-' . bin2hex(random_bytes(5)), 'tier' => 'paid-vote', 'created_at' => date('Y-m-d H:i:s', strtotime($at))];
        if ($refunded) $row['refunded_at'] = date('Y-m-d H:i:s');
        $id = (int) DB::table('gates_donations')->insertGetId($row);
        for ($i = 0; $i < $votes; $i++) $this->vote('paidvote:' . $id . ':' . bin2hex(random_bytes(5)), $nominee, self::C, $at, 'paid', $id);
    }

    private function strangers(int $n, int $votes = 1): void
    {
        // One free vote per address per category (the ledger's unique key); the rest are bought.
        for ($i = 0; $i < $n; $i++) {
            $this->vote(VoteService::voterHash("s$i@example.test"), self::B);
            if ($votes > 1) $this->order("s$i@example.test", $votes - 1, self::B);
        }
    }

    public function test_the_record_holds_every_vote_provably_theirs_and_nothing_else(): void
    {
        $this->vote(VoteService::voterHash(self::EMAIL), self::A);
        $this->order(self::EMAIL, 3, self::A);
        $this->order(self::EMAIL, 5, self::A, '-1 day', true);                 // refunded: not theirs to count
        $this->vote('points:' . $this->uid . ':' . bin2hex(random_bytes(4)), self::B, self::C, '-1 day', 'bonus');
        $this->vote('walletvote:' . $this->uid . ':' . bin2hex(random_bytes(4)), self::B, self::C, '-1 day', 'paid');
        $this->vote('points:' . ($this->uid + 1) . ':x1', self::B, self::C, '-1 day', 'bonus');   // somebody else's points
        $this->vote('points:' . $this->uid . ':fraud', self::B, self::C, '-1 day', 'bonus', null, 1);   // fraud-flagged
        $this->vote(VoteService::voterHash('stranger@example.test'), self::A);

        // The sandbox: an inactive programme.
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::SANDBOX, 'slug' => 'sandbox', 'title' => 'Sandbox', 'is_active' => 0]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::SY, 'programme_id' => self::SANDBOX, 'year' => (int) date('Y'), 'status' => 'voting', 'voting_open' => date('Y-m-d H:i:s', strtotime('-5 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::SC, 'cycle_id' => self::SY, 'slug' => 'x', 'title' => 'Sandbox category']);
        DB::table('gates_nominees')->insert(['id' => self::SN, 'name' => 'Demo Person', 'category_id' => self::SC, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        $this->vote(VoteService::voterHash(self::EMAIL), self::SN, self::SC);

        $r = SupporterRecord::forMember($this->uid, self::EMAIL);
        $this->assertSame(1, $r['editions_n'], 'the sandbox reached a member\'s record');
        $byName = [];
        foreach ($r['editions'][0]['nominees'] as $n) $byName[$n['name']] = $n['votes'];
        $this->assertSame(['Chiamaka Eze' => 4, 'Ibrahim Musa' => 2], $byName, 'free + paid for A, points + wallet for B — no refund, no fraud, nobody else');
        $this->assertSame(2, $r['backed']);
    }

    public function test_badges_are_earned_by_time_judgement_and_people_never_by_money(): void
    {
        // A thousand bought votes, cast late, for somebody who did not win: no badge.
        $this->order(self::EMAIL, 40, self::A, '-1 day');
        $earned = fn () => array_column(array_filter(SupporterRecord::forMember($this->uid, self::EMAIL)['badges'], fn ($b) => $b['earned']), 'key');
        $this->assertSame([], $earned(), 'money bought a badge');
        $all = SupporterRecord::forMember($this->uid, self::EMAIL)['badges'];
        $this->assertSame(array_keys(SupporterRecord::BADGES), array_column($all, 'key'), 'the badges not yet earned are not shown with how to earn them');
        $this->assertSame(SupporterRecord::BADGES['mobiliser'][1], $all[2]['d']);

        // Early: within 48 hours of voting opening.
        $this->vote(VoteService::voterHash(self::EMAIL), self::B, self::C, '-4 days -12 hours');
        $this->assertSame(['early'], $earned());

        // A winner.
        DB::table('gates_nominees')->where('id', self::A)->update(['status' => 'winner']);
        $this->assertSame(['early', 'winner'], $earned());

        // A mobiliser: ten people credited to their own link.
        $rid = (int) DB::table('gates_vote_recruits')->insertGetId(['code' => 'SRC' . random_int(100, 999), 'email_hash' => VoteService::voterHash(self::EMAIL), 'nominee_id' => self::A]);
        for ($i = 0; $i < 9; $i++) DB::table('gates_vote_recruit_credits')->insert(['recruit_id' => $rid, 'voter_hash' => hash('sha256', "r$i")]);
        $this->assertNotContains('mobiliser', $earned(), 'nine people made a mobiliser');
        DB::table('gates_vote_recruit_credits')->insert(['recruit_id' => $rid, 'voter_hash' => hash('sha256', 'r9')]);
        $this->assertContains('mobiliser', $earned());
    }

    public function test_every_edition_means_every_one_since_their_first(): void
    {
        $year = (int) date('Y');
        $earned = fn () => array_column(array_filter(SupporterRecord::forMember($this->uid, self::EMAIL)['badges'], fn ($b) => $b['earned']), 'key');
        // Two years ago (backed), last year (skipped, then backed), this year.
        foreach ([[self::Y0 - 1, self::C0 - 1, self::OLD - 1, $year - 2, '-730 days', 'Grace Obi'], [self::Y0, self::C0, self::OLD, $year - 1, '-370 days', 'Tunde Bakare']] as [$cy, $ca, $no, $yr, $open, $name]) {
            DB::table('gates_award_cycles')->insertOrIgnore(['id' => $cy, 'programme_id' => self::P, 'year' => $yr, 'status' => 'archived',
                'voting_open' => date('Y-m-d H:i:s', strtotime($open)), 'voting_close' => date('Y-m-d H:i:s', strtotime($open . ' +20 days'))]);
            DB::table('gates_award_categories')->insertOrIgnore(['id' => $ca, 'cycle_id' => $cy, 'slug' => 'teachers-' . $yr, 'title' => 'Best Teacher']);
            DB::table('gates_nominees')->insert(['id' => $no, 'name' => $name, 'category_id' => $ca, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
        $this->vote(VoteService::voterHash(self::EMAIL), self::OLD - 1, self::C0 - 1, '-725 days');
        $this->assertNotContains('loyal', $earned(), 'one edition is every edition');
        $this->vote(VoteService::voterHash(self::EMAIL), self::A, self::C, '-1 day');
        $this->assertNotContains('loyal', $earned(), 'an edition skipped in the middle');
        $this->vote(VoteService::voterHash(self::EMAIL), self::OLD, self::C0, '-365 days');
        $this->assertContains('loyal', $earned());
        $r = SupporterRecord::forMember($this->uid, self::EMAIL);
        $this->assertSame([self::Y, self::Y0, self::Y0 - 1], array_column($r['editions'], 'cycle_id'), 'the newest edition is not first');
        $this->assertSame('Did not place', $r['editions'][1]['nominees'][0]['result'], 'a finished edition\'s result');
        $this->assertSame('Voting open', $r['editions'][0]['nominees'][0]['result']);
    }

    public function test_a_certificate_is_earned_checkable_and_cannot_be_forged(): void
    {
        $this->strangers(12, 2);
        $this->vote(VoteService::voterHash(self::EMAIL), self::A);
        $this->assertNull(SupporterRecord::certificateFor($this->uid, self::Y), 'a certificate for number 13 of 13');
        $this->assertFalse(SupporterRecord::forMember($this->uid, self::EMAIL)['editions'][0]['certificate']);

        $this->order(self::EMAIL, 5, self::A);   // now 6 votes: number 1 of 13
        $this->assertNull(SupporterRecord::certificateFor($this->uid, self::Y), 'a certificate for a place on a board still being voted on');
        $this->closeVoting();
        $c = SupporterRecord::certificateFor($this->uid, self::Y);
        $this->assertNotNull($c);
        $this->assertSame('Amina Bello', $c['name']);
        $this->assertSame(['Number 1 of 13 supporters'], $c['why']);
        $this->assertTrue(SupporterRecord::forMember($this->uid, self::EMAIL)['editions'][0]['certificate']);
        $this->assertSame([$this->uid, self::Y], SupporterRecord::verify($c['code']));

        $forged = $this->uid . '-' . self::Y . '-' . str_repeat('a', 12);
        $this->assertNull(SupporterRecord::verify($forged));
        $other = (int) DB::table('gates_users')->insertGetId(['name' => 'Someone Else', 'email' => 'else@example.test', 'status' => 'active', 'email_verified' => 1, 'points' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(SupporterRecord::verify($other . '-' . self::Y . '-' . substr($c['code'], -12)), 'one member\'s signature checks out for another');

        $ok = ChromeRender::page('/certificates/' . $c['code']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('This certificate is genuine', (string) $ok->getBody());
        $this->assertStringContainsString('Amina Bello', (string) $ok->getBody());
        $bad = ChromeRender::page('/certificates/' . $forged);
        $this->assertSame(404, $bad->getStatusCode());
        $this->assertStringNotContainsString('Amina', (string) $bad->getBody());

        $page = ChromeRender::page('/account/supporting/certificate/' . self::Y, ['user_id' => $this->uid]);
        $this->assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        $this->assertStringContainsString('Certificate of community recognition', $html);
        $this->assertStringContainsString('/certificates/' . $c['code'], $html);
        $this->assertStringContainsString('<svg', $html, 'the QR to check it');

        // A closed account's certificate stops checking out.
        DB::table('gates_users')->where('id', $this->uid)->update(['status' => 'closed']);
        $this->assertSame(404, ChromeRender::page('/certificates/' . $c['code'])->getStatusCode());
    }

    private function closeVoting(): void
    {
        DB::table('gates_award_cycles')->where('id', self::Y)->update(['status' => 'judging',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-10 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
    }

    public function test_top_ten_needs_more_than_ten_people(): void
    {
        $this->strangers(9, 1);
        $this->vote(VoteService::voterHash(self::EMAIL), self::A);
        $this->order(self::EMAIL, 5, self::A);   // number 1 of 10
        $this->closeVoting();
        $this->assertNull(SupporterRecord::certificateFor($this->uid, self::Y), 'top ten of ten is everybody');
        $this->vote(VoteService::voterHash('eleventh@example.test'), self::B);
        $this->assertSame(['Number 1 of 11 supporters'], SupporterRecord::certificateFor($this->uid, self::Y)['why']);
    }

    public function test_an_edition_that_earned_nothing_prints_nothing(): void
    {
        $this->strangers(12, 2);
        $this->closeVoting();
        $this->vote(VoteService::voterHash(self::EMAIL), self::A);
        $res = ChromeRender::page('/account/supporting/certificate/' . self::Y, ['user_id' => $this->uid]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/account/supporting', $res->getHeaderLine('Location'));
    }

    public function test_points_rank_a_member_by_their_stronger_key(): void
    {
        $this->strangers(10, 2);
        $this->vote(VoteService::voterHash(self::EMAIL), self::A);
        for ($i = 0; $i < 4; $i++) $this->vote('points:' . $this->uid . ':' . bin2hex(random_bytes(4)), self::A, self::C, '-1 day', 'bonus');
        $this->assertSame(1, SupporterSpotlight::standingOf(self::Y, self::EMAIL)['votes'], 'without the id, the email key');
        $st = SupporterSpotlight::standingOf(self::Y, self::EMAIL, $this->uid);
        $this->assertSame(4, $st['votes']);
        $this->assertSame(1, $st['rank']);
        $this->closeVoting();
        $this->assertSame(['Number 1 of 12 supporters'], SupporterRecord::certificateFor($this->uid, self::Y)['why'] ?? null, 'a member who backed with points ranked by their address alone');
    }

    public function test_the_page_is_in_the_account_and_says_what_it_holds(): void
    {
        $this->assertSame(302, ChromeRender::page('/account/supporting')->getStatusCode(), 'drawn for nobody');
        $empty = ChromeRender::html('/account/supporting', ['user_id' => $this->uid]);
        $this->assertStringContainsString('You have not backed anybody yet', $empty);
        $this->assertMatchesRegularExpression('#<a class="acx-tab" href="/account/supporting" aria-current="page"#', $empty, 'My support is not in the account rail');

        $this->vote(VoteService::voterHash(self::EMAIL), self::A, self::C, '-4 days -12 hours');
        $html = ChromeRender::html('/account/supporting', ['user_id' => $this->uid]);
        $this->assertStringContainsString('Chiamaka Eze', $html);
        $this->assertStringContainsString('/vote/pride-award/' . self::A . '-chiamaka-eze/backed.png', $html, 'no "I backed" card');
        $this->assertMatchesRegularExpression('#spr-b__i--on.*?Early backer.*?Earned#s', $html);
        $this->assertStringContainsString('Not yet', $html, 'the badges still to earn');
        $this->assertStringContainsString('Never by how much you spent', $html);
    }
}
