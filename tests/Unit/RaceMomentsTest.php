<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CommunityBoard;
use AfricaGates\Services\MilestoneCard;
use AfricaGates\Services\NomineeMilestones;
use AfricaGates\Services\SupporterSpotlight;
use AfricaGates\Services\VoteService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * THE RACE, WHILE IT IS LIVE — for the people in it (owner, 16 Nov 2026: "build them all"):
 * milestone updates to the backers who asked (NomineeMilestones), the nominee's milestone cards
 * (MilestoneCard), the first supporters (SupporterSpotlight::firstBackers), the community pride
 * board (CommunityBoard), the printable poster, and "why I back them" on the ballot.
 *
 * What these try to break: switching it on announcing everything already true; a milestone
 * told twice, or told to somebody who asked after it happened; more than three to anybody; a
 * stranger signing an address that never voted up for somebody's campaign; "leading" in a tie;
 * a top three in a field of three; first supporters ordered by money rather than time, or named
 * without consent; a community carried up the board by one buyer; the sandbox anywhere; a card
 * for a milestone nobody reached.
 *
 * Ids below 255 (TINYINT clamp on MySQL); one ballot row per vote.
 */
final class RaceMomentsTest extends TestCase
{
    private const P = 91, Y = 9101, C = 9111, A = 9121, B = 9122, D = 9123, E = 9124;

    protected function setUp(): void
    {
        parent::setUp();
        SupporterSpotlight::$ttl = 0;
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'race-award', 'title' => 'Race Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-5 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+5 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
        foreach ([self::A => 'Chiamaka Eze', self::B => 'Ibrahim Musa', self::D => 'Zainab Lawal', self::E => 'Segun Ade'] as $id => $name) {
            DB::table('gates_nominees')->insert(['id' => $id, 'name' => $name, 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
    }

    protected function tearDown(): void
    {
        SupporterSpotlight::$ttl = 60;
        unset($_SESSION['vote_follow']);
        parent::tearDown();
    }

    private function free(string $email, int $nominee, string $at = '-2 days', string $name = '', int $show = 0): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'standard', 'weight' => 1,
            'voter_email_hash' => VoteService::voterHash($email), 'voter_name' => $name, 'show_name' => $show, 'voted_at' => date('Y-m-d H:i:s', strtotime($at))]);
        DB::table('gates_nominees')->where('id', $nominee)->increment('vote_count');
    }

    private function order(string $email, int $votes, int $nominee, string $at = '-1 day'): void
    {
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Supporter', 'donor_email' => $email, 'amount_naira' => $votes * 200,
            'status' => 'confirmed', 'payment_ref' => 'rm-' . bin2hex(random_bytes(5)), 'tier' => 'paid-vote', 'created_at' => date('Y-m-d H:i:s', strtotime($at))]);
        for ($i = 0; $i < $votes; $i++) {
            DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'paid', 'weight' => 1,
                'voter_email_hash' => 'paidvote:' . $id . ':' . bin2hex(random_bytes(5)), 'donation_id' => $id, 'voter_name' => 'Supporter', 'show_name' => 0,
                'voted_at' => date('Y-m-d H:i:s', strtotime($at))]);
        }
        DB::table('gates_nominees')->where('id', $nominee)->increment('vote_count', $votes);
    }

    /** @return array{0:callable, 1:\ArrayObject} */
    private function outbox(): array
    {
        $box = new \ArrayObject();
        return [static function (string $to, string $s, string $h, string $p) use ($box): bool { $box[] = ['to' => $to, 'subject' => $s, 'plain' => $p]; return true; }, $box];
    }

    // ── milestones ───────────────────────────────────────────────────────────────

    public function test_switching_it_on_announces_nothing_and_each_milestone_is_recorded_once(): void
    {
        for ($i = 0; $i < 10; $i++) $this->free("early$i@example.test", self::A);
        $first = NomineeMilestones::detect(self::Y);
        $this->assertNotEmpty($first);
        $this->assertTrue(collect($first)->every(fn ($e) => $e->quiet), 'what was already true was announced');
        $this->assertSame(0, DB::table('gates_nominee_milestones')->where('nominee_id', '>', 0)->where(fn ($q) => $q->where('quiet', 0)->orWhereNull('notified_at'))->count(),
            'the first look STORED something as news');
        $this->assertSame([], NomineeMilestones::detect(self::Y), 'a milestone recorded twice');

        // After the first look, a NEW milestone is news — even in a cycle that started with nothing.
        for ($i = 0; $i < 15; $i++) $this->free("later$i@example.test", self::A);
        $new = NomineeMilestones::detect(self::Y);
        $this->assertSame(['people-25'], array_map(fn ($e) => $e->kind . '-' . $e->value, $new));
        $this->assertFalse($new[0]->quiet);
    }

    public function test_the_lead_is_outright_and_the_top_three_needs_a_field_of_four(): void
    {
        NomineeMilestones::detect(self::Y);   // the first look, quietly
        $this->free('a1@example.test', self::A); $this->free('b1@example.test', self::B);
        $tied = array_map(fn ($e) => $e->nominee_id . ':' . $e->kind, NomineeMilestones::detect(self::Y));
        $this->assertNotContains(self::A . ':lead', $tied, 'a tie called a lead');
        $this->assertContains(self::A . ':top3', $tied);
        $this->free('a2@example.test', self::A);
        $this->assertContains(self::A . ':lead', array_map(fn ($e) => $e->nominee_id . ':' . $e->kind, NomineeMilestones::detect(self::Y)));
        DB::table('gates_nominees')->where('id', self::E)->update(['status' => 'pending']);
        DB::table('gates_nominee_milestones')->delete();
        $this->assertNotContains('top3', array_map(fn ($e) => $e->kind, NomineeMilestones::detect(self::Y)), 'a top three in a field of three');
    }

    public function test_only_an_address_that_voted_for_them_may_follow(): void
    {
        $this->free('free@example.test', self::A);
        $this->order('buyer@example.test', 2, self::A);
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Member', 'email' => 'member@example.test', 'status' => 'active', 'email_verified' => 1, 'points' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_votes')->insert(['nominee_id' => self::A, 'category_id' => self::C, 'vote_type' => 'bonus', 'weight' => 1,
            'voter_email_hash' => 'points:' . $uid . ':' . bin2hex(random_bytes(4)), 'voter_name' => '', 'show_name' => 0, 'voted_at' => date('Y-m-d H:i:s')]);
        $this->free('other@example.test', self::B);

        foreach (['free@example.test', 'BUYER@example.test', 'member@example.test'] as $ok) $this->assertTrue(NomineeMilestones::follow(self::A, $ok)['ok'], $ok . ' backed them and was refused');
        foreach (['stranger@example.test', 'other@example.test', 'not-an-email'] as $no) $this->assertFalse(NomineeMilestones::follow(self::A, $no)['ok'], $no . ' was signed up');
        $this->assertTrue(NomineeMilestones::follow(self::A, 'free@example.test')['ok'], 'following twice is an error');
        $this->assertSame(3, DB::table('gates_nominee_follows')->where('nominee_id', self::A)->count());
    }

    public function test_a_follower_hears_the_best_new_milestone_once_and_never_more_than_three(): void
    {
        NomineeMilestones::detect(self::Y);   // the first look
        $this->free('fan@example.test', self::A);
        $this->assertTrue(NomineeMilestones::follow(self::A, 'fan@example.test')['ok']);
        DB::table('gates_nominee_follows')->update(['created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        [$send, $box] = $this->outbox();

        // A burst: the top 3 AND the lead at once — told the lead only.
        $this->assertSame(1, NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send));
        $this->assertSame('Chiamaka Eze is now leading Best Teacher', $box[0]['subject']);
        $this->assertStringContainsString('Your vote is part of that.', $box[0]['plain']);
        $this->assertStringContainsString('at most 2 more', $box[0]['plain']);
        $this->assertStringContainsString('Unsubscribe', $box[0]['plain']);
        $this->assertSame(0, NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send), 'told twice');
        // Two overlapping ticks: the second sees the milestone as untold. The claim still stops it.
        DB::table('gates_nominee_milestones')->where('nominee_id', self::A)->where('kind', 'lead')->update(['notified_at' => null]);
        $this->assertSame(0, NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send), 'an overlapping tick sent it again');

        // Three more milestones: only two more messages, ever.
        for ($i = 0; $i < 9; $i++) $this->free("x$i@example.test", self::A);
        NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send);           // 10 supporters
        for ($i = 0; $i < 15; $i++) $this->free("y$i@example.test", self::A);
        NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send);           // 25
        for ($i = 0; $i < 25; $i++) $this->free("z$i@example.test", self::A);
        NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send);           // 50
        $this->assertCount(3, $box, 'more than three messages to one follower in an edition');

        // Somebody who follows AFTER a milestone is not told of it.
        $this->free('late@example.test', self::B);
        NomineeMilestones::detect(self::Y);   // B's milestones happen BEFORE the follow
        $this->assertTrue(NomineeMilestones::follow(self::B, 'late@example.test')['ok']);
        DB::table('gates_nominee_milestones')->where('nominee_id', self::B)->update(['reached_at' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'notified_at' => null, 'quiet' => 0]);
        [$send2, $box2] = $this->outbox();
        NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send2);
        $this->assertNotContains('late@example.test', array_column($box2->getArrayCopy(), 'to'), 'told of a milestone from before they asked');
    }

    public function test_nothing_is_sent_once_voting_has_closed_or_for_the_sandbox(): void
    {
        NomineeMilestones::detect(self::Y);
        $this->free('fan@example.test', self::A);
        NomineeMilestones::follow(self::A, 'fan@example.test');
        DB::table('gates_nominee_follows')->update(['created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        [$send, $box] = $this->outbox();
        DB::table('gates_award_cycles')->where('id', self::Y)->update(['voting_close' => date('Y-m-d H:i:s', strtotime('-1 hour')), 'status' => 'judging']);
        $this->assertSame(0, NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send));
        DB::table('gates_award_cycles')->where('id', self::Y)->update(['voting_close' => date('Y-m-d H:i:s', strtotime('+5 days')), 'status' => 'voting']);
        DB::table('gates_award_programmes')->where('id', self::P)->update(['is_active' => 0]);
        $this->assertSame(0, NomineeMilestones::sweep(null, Carbon::now(), 'https://site.test', $send));
        $this->assertCount(0, $box);
    }

    public function test_a_card_exists_only_for_a_milestone_reached(): void
    {
        $this->assertNull(MilestoneCard::spec(self::A, 'lead', 1));
        NomineeMilestones::detect(self::Y);
        $this->free('a@example.test', self::A);
        NomineeMilestones::detect(self::Y);
        $s = MilestoneCard::spec(self::A, 'lead', 1);
        $this->assertSame('In the lead', $s['title']);
        $this->assertSame('Chiamaka Eze', $s['lead']);
        $this->assertNull(MilestoneCard::spec(self::A, 'nonsense', 1));
        $this->assertSame(404, \Tests\Support\ChromeRender::page('/vote/race-award/' . self::B . '-ibrahim-musa/milestone/lead-1.png')->getStatusCode(), 'a lead minted for a nominee who is not leading');
    }

    // ── first supporters ─────────────────────────────────────────────────────────

    public function test_the_first_supporters_are_in_by_when_never_by_how_much(): void
    {
        $this->free('ada@example.test', self::A, '-4 days', 'Ada Obi', 1);
        $this->free('shy@example.test', self::A, '-3 days', 'Shy Person', 0);       // did not ask to be named
        $this->order('big@example.test', 500, self::A, '-1 day');                  // the most money, last
        $f = SupporterSpotlight::firstBackers(self::A);
        $this->assertSame(3, $f['count']);
        $this->assertSame(['Ada Obi'], array_column($f['named'], 'name'), 'named without consent, or in money order');
        $this->assertSame(0, SupporterSpotlight::firstBackers(99999)['count']);
    }

    // ── the community pride board ────────────────────────────────────────────────

    public function test_communities_are_ranked_by_people_not_by_money_and_the_sandbox_is_not_one(): void
    {
        DB::table('gates_award_programmes')->insert(['id' => 92, 'slug' => 'rich-award', 'title' => 'Rich Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 9201, 'programme_id' => 92, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-5 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+5 days'))]);
        DB::table('gates_award_categories')->insert(['id' => 9211, 'cycle_id' => 9201, 'slug' => 'x', 'title' => 'X']);
        DB::table('gates_nominees')->insert(['id' => 9221, 'name' => 'Rich Nominee', 'category_id' => 9211, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Big', 'donor_email' => 'big@example.test', 'amount_naira' => 200000, 'status' => 'confirmed', 'payment_ref' => 'rich', 'tier' => 'paid-vote', 'created_at' => date('Y-m-d H:i:s')]);
        for ($i = 0; $i < 1000; $i++) DB::table('gates_votes')->insert(['nominee_id' => 9221, 'category_id' => 9211, 'vote_type' => 'paid', 'weight' => 1,
            'voter_email_hash' => 'paidvote:' . $id . ':' . $i, 'donation_id' => $id, 'voter_name' => 'Big', 'show_name' => 0, 'voted_at' => date('Y-m-d H:i:s')]);
        for ($i = 0; $i < 3; $i++) $this->free("p$i@example.test", self::A);
        \AfricaGates\Support\AwardCampaign::save(self::P, ['community' => 'Alimosho']);

        $board = CommunityBoard::current();
        $this->assertSame(['Alimosho', 'Rich Award'], array_column($board, 'label'), '1,000 bought votes from one person outranked three people');
        $this->assertSame(['rank' => 1, 'of' => 2], CommunityBoard::placeOf(self::P));
        DB::table('gates_award_programmes')->where('id', 92)->update(['is_active' => 0]);
        $this->assertSame(['Alimosho'], array_column(CommunityBoard::current(), 'label'), 'the sandbox is on the board');
    }

    // ── the poster and the ballot ────────────────────────────────────────────────

    public function test_the_poster_prints_a_qr_to_the_ballot_and_the_ballot_asks_why(): void
    {
        $res = \Tests\Support\ChromeRender::page('/vote/race-award/' . self::A . '-chiamaka-eze/poster');
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertStringContainsString('<h1 class="po__name">Vote for Chiamaka Eze</h1>', $html);
        $this->assertStringContainsString('<svg', $html, 'no QR code on the poster');
        $this->assertStringContainsString('/vote/race-award/' . self::A . '-chiamaka-eze</p>', $html);
        $this->assertStringContainsString('@page { size: A4 portrait;', (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components/poster.css'));
        $this->assertSame(404, \Tests\Support\ChromeRender::page('/vote/race-award/99999-nobody/poster')->getStatusCode());

        $ballot = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/vote-nominee.twig');
        $this->assertStringContainsString("'Why people back %first%'", $ballot);
        $this->assertStringContainsString("'+ Say why you back %first% (optional)'", $ballot);
        $this->assertStringContainsString('action="/vote/follow"', $ballot);
        $this->assertStringContainsString("'Among the first to back %first%'", $ballot);
    }
}
