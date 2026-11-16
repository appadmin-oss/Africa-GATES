<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShareKit;
use AfricaGates\Services\SupporterSpotlight;
use AfricaGates\Services\VoteService;
use AfricaGates\Support\AwardCampaign;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Make the voting page more celebratory … start highlighting top voters to celebrate them …
 * the voter needs to feel a reason to truly vote" (owner, 16 Nov 2026), with the Afrovanguard
 * Community Wealth & Impact Framework as the brief. Services\SupporterSpotlight and
 * Support\AwardCampaign.
 *
 * What a celebration of supporters must get RIGHT is the thing that makes it worth having:
 * the person crowned is really the biggest backer. So most of what is here is about who
 * counts as one person and which votes count at all — the same answers the score gives.
 *
 * Ids stay under 256: `gates_award_programmes.id` is narrow on MySQL and INSERT IGNORE clamps
 * an oversized id rather than refusing it (CLAUDE.md).
 */
final class SupporterSpotlightTest extends TestCase
{
    private const P = 61, Y = 6101, C = 6111, A = 6121, B = 6122;

    protected function setUp(): void
    {
        parent::setUp();
        SupporterSpotlight::$ttl = 0;
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'spot-award', 'title' => 'Spot Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-3 days')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'choirs', 'title' => 'Best Choir']);
        foreach ([self::A => 'Chiamaka Eze', self::B => 'Ibrahim Musa'] as $id => $name) {
            DB::table('gates_nominees')->insert(['id' => $id, 'name' => $name, 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        }
    }

    protected function tearDown(): void
    {
        SupporterSpotlight::$ttl = 60;
        parent::tearDown();
    }

    private function order(string $email, int $votes, int $nominee, string $at, string $name = '', bool $show = false, array $don = [], int $fraud = 0): int
    {
        $id = (int) DB::table('gates_donations')->insertGetId($don + ['donor_name' => $name ?: 'Supporter', 'donor_email' => $email,
            'amount_naira' => $votes * 200, 'status' => 'confirmed', 'payment_ref' => 'sp-' . bin2hex(random_bytes(5)), 'tier' => 'paid-vote',
            'created_at' => $at]);
        // One ledger row per vote, as the mint writes them — never a counter alone.
        for ($i = 0; $i < $votes; $i++) {
            DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'paid', 'weight' => 1,
                'voter_email_hash' => 'paidvote:' . $id . ':' . bin2hex(random_bytes(5)), 'donation_id' => $id,
                'voter_name' => $name ?: 'Supporter', 'show_name' => $show ? 1 : 0, 'voted_at' => $at, 'fraud_flag' => $fraud]);
        }
        return $id;
    }

    private function free(string $email, int $nominee, string $at, string $name = '', bool $show = false): void
    {
        DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'standard', 'weight' => 1,
            'voter_email_hash' => VoteService::voterHash($email), 'voter_name' => $name, 'show_name' => $show ? 1 : 0, 'voted_at' => $at]);
    }

    /** Noon today in Lagos, as the storage clock writes it. */
    private function now(): Carbon
    {
        return Carbon::now('Africa/Lagos')->setTime(12, 0)->setTimezone(date_default_timezone_get());
    }

    public function test_a_supporter_is_one_person_however_they_split_their_giving(): void
    {
        $now = $this->now(); $at = $now->toDateTimeString();
        // Three orders and a free vote from one address: one supporter of 61, not four of 20.
        foreach ([20, 20, 20] as $q) $this->order('adaeze@example.test', $q, self::A, $at, 'Adaeze Okafor', true);
        $this->free('adaeze@example.test', self::A, $at, 'Adaeze Okafor', true);
        $this->order('tunde@example.test', 30, self::B, $at, 'Tunde Bakare', true);

        $s = SupporterSpotlight::forCycle(self::Y, $now);
        $this->assertSame(2, $s['people']);
        $this->assertSame('Adaeze Okafor', $s['top'][0]['name']);
        $this->assertSame(61, $s['top'][0]['votes'], 'split orders were counted as separate people');
        $this->assertSame('Chiamaka Eze', $s['top'][0]['for']);
        $this->assertSame(61, SupporterSpotlight::votesBy(self::Y, 'Adaeze@Example.test '), 'a supporter’s own total, by the address they vote with');
    }

    public function test_only_settled_verified_chosen_votes_are_celebrated(): void
    {
        $now = $this->now(); $at = $now->toDateTimeString();
        $this->order('real@example.test', 10, self::A, $at, 'Real Person', true);
        $this->order('refunded@example.test', 500, self::A, $at, 'Refunded', true, ['refunded_at' => $at]);
        $this->order('inflight@example.test', 400, self::A, $at, 'In Flight', true, ['refund_requested_at' => $at]);
        $this->order('pending@example.test', 300, self::A, $at, 'Pending', true, ['status' => 'pending']);
        $this->order('fraud@example.test', 200, self::A, $at, 'Fraud', true, [], 1);
        // An operator's grant is support nobody chose to give.
        for ($i = 0; $i < 100; $i++) {
            DB::table('gates_votes')->insert(['nominee_id' => self::A, 'category_id' => self::C, 'vote_type' => 'bonus', 'weight' => 1,
                'voter_email_hash' => 'bonus:9:' . $i, 'voter_name' => 'Grant', 'show_name' => 1, 'voted_at' => $at]);
        }
        $s = SupporterSpotlight::forCycle(self::Y, $now);
        $this->assertSame(1, $s['people'], 'refunded, in-flight, unconfirmed, flagged or granted votes were celebrated');
        $this->assertSame('Real Person', $s['champion']['name']);
        $this->assertSame(10, $s['votes']);
    }

    /**
     * Somebody who did not ask to be named is still ranked: leaving them out would crown the
     * second-biggest backer as the biggest. And a name typed on the order without consent is
     * never used.
     */
    public function test_the_biggest_backer_is_ranked_first_even_unnamed(): void
    {
        $now = $this->now(); $at = $now->toDateTimeString();
        $this->order('quiet@example.test', 90, self::B, $at, 'Quiet Person', false);
        $this->order('named@example.test', 60, self::A, $at, 'Named Person', true);
        $s = SupporterSpotlight::forCycle(self::Y, $now);
        $this->assertNull($s['top'][0]['name'], 'a name nobody agreed to show was published');
        $this->assertSame(90, $s['top'][0]['votes']);
        $this->assertNull($s['champion']['name']);
        $this->assertSame('Named Person', $s['top'][1]['name']);
    }

    /** "Today" is a West Africa Time day, and a tie goes to whoever got there first. */
    public function test_champion_of_the_day_is_a_lagos_day_and_ties_go_to_the_first(): void
    {
        $now = $this->now();
        $lagosMidnight = Carbon::now('Africa/Lagos')->startOfDay()->setTimezone(date_default_timezone_get());
        // Yesterday evening in Lagos — a big total that is not today's.
        $this->order('yesterday@example.test', 80, self::A, $lagosMidnight->copy()->subMinutes(30)->toDateTimeString(), 'Last Night', true);
        // Two equal totals today; the earlier one takes it.
        // 00:30 in Lagos is 23:30 UTC the day before: a UTC day would leave it out.
        $this->order('first@example.test', 15, self::A, $lagosMidnight->copy()->addMinutes(30)->toDateTimeString(), 'Got There First', true);
        $this->order('second@example.test', 15, self::B, $lagosMidnight->copy()->addHours(5)->toDateTimeString(), 'Got There Second', true);

        $s = SupporterSpotlight::forCycle(self::Y, $now);
        $this->assertSame('Got There First', $s['champion']['name'], 'yesterday’s votes crowned today, or a tie went the wrong way');
        $this->assertSame(15, $s['champion']['votes']);
        $this->assertSame('Last Night', $s['top'][0]['name'], 'the edition board still counts every day');
    }

    /** Mobilising is its own honour: separate people brought this week, not money. */
    public function test_the_mobiliser_of_the_week_is_whoever_brought_the_most_people(): void
    {
        if (!\AfricaGates\Services\VoteRecruit::ready()) $this->markTestSkipped('no recruit tables');
        $now = $this->now(); $at = $now->toDateTimeString();
        $this->free('ngozi@example.test', self::A, $at, 'Ngozi Eze', true);
        $rid = (int) DB::table('gates_vote_recruits')->insertGetId(['code' => 'ngozi7', 'email_hash' => VoteService::voterHash('ngozi@example.test'), 'nominee_id' => self::A, 'created_at' => $at]);
        foreach (['a', 'b', 'c'] as $i => $who) {
            DB::table('gates_vote_recruit_credits')->insert(['recruit_id' => $rid, 'voter_hash' => VoteService::voterHash("$who@example.test"), 'vote_ref' => "r$i", 'created_at' => $at]);
        }
        // Her own link for the OTHER nominee brought the same friend again: still one person.
        $rid2 = (int) DB::table('gates_vote_recruits')->insertGetId(['code' => 'ngozi8', 'email_hash' => VoteService::voterHash('ngozi@example.test'), 'nominee_id' => self::B, 'created_at' => $at]);
        DB::table('gates_vote_recruit_credits')->insert(['recruit_id' => $rid2, 'voter_hash' => VoteService::voterHash('c@example.test'), 'vote_ref' => 'r9', 'created_at' => $at]);
        $old = (int) DB::table('gates_vote_recruits')->insertGetId(['code' => 'old9', 'email_hash' => VoteService::voterHash('old@example.test'), 'nominee_id' => self::A, 'created_at' => $at]);
        foreach (range(1, 9) as $i) {
            DB::table('gates_vote_recruit_credits')->insert(['recruit_id' => $old, 'voter_hash' => "x$i", 'vote_ref' => "o$i", 'created_at' => $now->copy()->subDays(9)->toDateTimeString()]);
        }
        $m = SupporterSpotlight::forCycle(self::Y, $now)['mobiliser'];
        $this->assertSame('Ngozi Eze', $m['name']);
        $this->assertSame(3, $m['people'], 'one person brought twice was counted twice, or last week counted');
    }

    // ── the campaign ─────────────────────────────────────────────────────────

    public function test_a_campaign_states_only_what_the_host_set(): void
    {
        $blank = AwardCampaign::of(null);
        $this->assertSame('', AwardCampaign::impact($blank, 200));
        $this->assertSame('', AwardCampaign::celebrating($blank, 'Chiamaka Eze'));

        // No share without a named beneficiary, and a ladder row needs both halves.
        $this->assertNotNull(AwardCampaign::save(self::P, ['share_pct' => '30']));
        $this->assertNotNull(AwardCampaign::save(self::P, ['ladder_votes' => ['5'], 'ladder_benefit' => ['']]));
        $this->assertNotNull(AwardCampaign::save(self::P, ['ladder_votes' => ['5', '5'], 'ladder_benefit' => ['a', 'b']]));
        $this->assertNull(AwardCampaign::save(self::P, ['community' => 'Alimosho', 'beneficiary' => 'CACENTRE', 'share_pct' => '30',
            'spotlight' => '1', 'ladder_votes' => ['10', '5', ''], 'ladder_benefit' => ['Book club', 'Learning resource', '']]));

        $c = AwardCampaign::forProgramme(self::P);
        $this->assertSame([5, 10], array_column($c['ladder'], 'votes'), 'the ladder is lowest first');
        $this->assertSame('Alimosho is celebrating its own: Chiamaka Eze', AwardCampaign::celebrating($c, 'Chiamaka Eze'));
        $this->assertSame(['have' => [['votes' => 5, 'benefit' => 'Learning resource']], 'next' => ['votes' => 10, 'benefit' => 'Book club', 'more' => 3]],
            AwardCampaign::standing($c, 7));

        // Only the beneficiary's share is stated, rounded DOWN, and only when votes are paid.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'paid_voting_enabled'], ['value' => '1']);
        $this->assertSame('Of every ₦250 vote, ₦75 goes to CACENTRE.', AwardCampaign::impact($c, 250));
        $this->assertSame('Of every ₦199 vote, ₦59 goes to CACENTRE.', AwardCampaign::impact($c, 199), 'rounded up — a promise of money the share does not give');

        $kit = ShareKit::for(['name' => 'Chiamaka Eze', 'category' => 'Best Choir', 'programme' => 'Spot Award', 'url' => 'https://x.test/v', 'community' => 'Alimosho']);
        $this->assertStringStartsWith('Alimosho is celebrating its own: Chiamaka Eze.', $kit['text']);
    }

    /** The page: nothing drawn for a blank campaign; everything drawn once it is set. */
    /**
     * The Spotlight is on for EVERY award (owner: "do not restrict the scope"), with no
     * campaign typed at all, and a host may switch it off. Nothing typed means no typed claim:
     * no rally line of anybody's, no beneficiary, no ladder — only the people and their votes.
     */
    public function test_every_award_celebrates_its_supporters_unless_the_host_turns_it_off(): void
    {
        $at = Carbon::now()->toDateTimeString();
        $this->order('adaeze@example.test', 12, self::A, $at, 'Adaeze Okafor', true);
        $this->free('free@example.test', self::B, $at, '', false);

        $blank = $this->page();
        $this->assertStringContainsString('class="vp-why"', $blank, 'an award with no campaign typed does not celebrate anybody');
        $this->assertStringContainsString('class="vp-spot"', $blank);
        $this->assertStringContainsString('Join 2 people backing their own.', $blank);
        $this->assertStringNotContainsString('would make you today’s champion', $blank, 'the contest with your neighbour is back');
        $this->assertStringNotContainsString('for CACENTRE', $blank, 'a beneficiary nobody named');
        // The wall is everybody — the unnamed free voter too.
        $this->assertSame(2, substr_count($blank, 'class="vp-wall__p"'), 'somebody who voted is missing from the wall');
        $this->assertStringContainsString('A proud supporter', $blank);
        $this->assertStringContainsString('vp-body vp-body--one', $blank, 'one category fell into the category rail’s column on a desktop');

        $this->assertNull(AwardCampaign::save(self::P, ['community' => 'Alimosho', 'rally' => 'Who will celebrate our own, if not us?',
            'ladder_votes' => ['5'], 'ladder_benefit' => ['A learning resource']]));
        $html = $this->page();
        $this->assertStringContainsString('Who will celebrate our own, if not us?', $html);
        $this->assertStringContainsString('Alimosho is celebrating its own', $html);
        $this->assertStringContainsString('Join 2 people celebrating Alimosho’s own.', $html);
        $this->assertMatchesRegularExpression('~Champion of the day</span>\s*<b class="vp-hon__n">Adaeze Okafor</b>~', $html);
        $this->assertStringContainsString('A learning resource', $html);

        $this->assertNull(AwardCampaign::save(self::P, ['community' => 'Alimosho', 'spotlight_off' => '1']));
        $this->assertStringNotContainsString('class="vp-spot"', $this->page(), 'the host could not switch the Spotlight off');
    }

    /** Right after giving, where they stand: their place among everybody, by the board's rule. */
    public function test_a_supporter_is_told_where_they_stand(): void
    {
        $at = Carbon::now()->toDateTimeString();
        $this->order('big@example.test', 50, self::A, $at, 'Big', true);
        $this->order('me@example.test', 20, self::A, $at, 'Me', true);
        $this->free('me@example.test', self::A, $at, 'Me', true);
        $this->free('small@example.test', self::B, $at);
        $this->assertSame(['votes' => 21, 'rank' => 2, 'people' => 3], SupporterSpotlight::standingOf(self::Y, 'me@example.test'));
        $this->assertSame(['votes' => 0, 'rank' => 0, 'people' => 3], SupporterSpotlight::standingOf(self::Y, 'nobody@example.test'));
    }

    private function page(): string
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/vote/spot-award');
        $res = $b->build()->get(\AfricaGates\Controllers\VoteController::class)->program($req, new \Slim\Psr7\Response(), ['program' => 'spot-award']);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    // ── accounts, taking down, and the messages (owner, 16 Nov 2026, second pass) ─

    private function member(string $email, string $name, ?string $photo = '/assets/img/honour/teacher-480.webp', int $verified = 1): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => $email, 'status' => 'active',
            'email_verified' => $verified, 'points' => 0, 'created_at' => Carbon::now()->toDateTimeString(), 'photo_path' => $photo]);
    }

    /**
     * An account holder is shown with their own name and photo by default — even with
     * "show my name" unticked — and is anonymous everywhere once they ask. Somebody without a
     * VERIFIED account is still named only by consent: an unverified address proves nothing.
     */
    public function test_an_account_holder_is_shown_by_default_and_can_take_it_down(): void
    {
        $now = $this->now(); $at = $now->toDateTimeString();
        $uid = $this->member('adaeze@example.test', 'Adaeze Okafor');
        $this->member('claims@example.test', 'Not Theirs', null, 0);
        $this->order('adaeze@example.test', 40, self::A, $at, 'typed at checkout', false);
        $this->free('free@example.test', self::B, $at, '', false);
        $this->member('free@example.test', 'Free Member', '/assets/img/honour/nurse-480.webp');
        $this->order('claims@example.test', 30, self::B, $at, '', false);

        $top = SupporterSpotlight::forCycle(self::Y, $now)['top'];
        $this->assertSame('Adaeze Okafor', $top[0]['name'], 'an account holder was not shown');
        $this->assertSame('/assets/img/honour/teacher-480.webp', $top[0]['photo']);
        $this->assertSame($uid, $top[0]['uid']);
        $this->assertArrayNotHasKey('email', $top[0], 'an address reached the page');
        $this->assertNull($top[1]['name'], 'an UNVERIFIED account was used to name somebody');
        $this->assertSame('Free Member', $top[2]['name'], 'a free voter’s account was not recognised by its address');

        $this->assertTrue(SupporterSpotlight::hide(VoteService::voterHash('adaeze@example.test')));
        $top = SupporterSpotlight::forCycle(self::Y, $now)['top'];
        $this->assertNull($top[0]['name'], 'a take-down did not take');
        $this->assertNull($top[0]['photo']);
        $this->assertSame(40, $top[0]['votes'], 'taking yourself down took your votes with you');
    }

    /** One tap off: a forged link is refused, a signed one or a signed-in member is obeyed. */
    public function test_taking_yourself_off_needs_the_signed_link_or_your_own_session(): void
    {
        $key = VoteService::voterHash('adaeze@example.test');
        $url = \AfricaGates\Services\SpotlightMail::hideUrl('https://site.test', $key);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame($key, $q['k']);

        $forged = \Tests\Support\ChromeRender::page('/spotlight/hide', [], [], 'POST', ['_token' => 'test-token', 'k' => VoteService::voterHash('someone@example.test'), 't' => $q['t']]);
        $this->assertSame(403, $forged->getStatusCode(), 'somebody else was taken off with my link');

        $show = \Tests\Support\ChromeRender::html('/spotlight/hide?k=' . rawurlencode($key) . '&t=' . $q['t']);
        $this->assertStringContainsString('Take me off', $show);
        $this->assertFalse(DB::table('gates_spotlight_hidden')->where('person_key', $key)->exists(), 'OPENING the link took them down — a mail scanner would');
        $ok = \Tests\Support\ChromeRender::page('/spotlight/hide', [], [], 'POST', ['_token' => 'test-token', 'k' => $key, 't' => $q['t']]);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertTrue(DB::table('gates_spotlight_hidden')->where('person_key', $key)->exists());

        $uid = $this->member('ngozi@example.test', 'Ngozi Eze');
        \Tests\Support\ChromeRender::page('/spotlight/hide', ['user_id' => $uid], [], 'POST', ['_token' => 'test-token']);
        $this->assertTrue(DB::table('gates_spotlight_hidden')->where('person_key', VoteService::voterHash('ngozi@example.test'))->exists(), 'a signed-in member could not take themselves off');
    }

    /**
     * The messages: yesterday's champion once the Lagos day is over, the top supporters on
     * arrival — each once, each with the way off, never to somebody who asked to be off,
     * and never computed with votes cast after the day it is about.
     */
    public function test_the_people_celebrated_are_told_once_with_the_way_off(): void
    {
        $this->assertNull(AwardCampaign::save(self::P, ['community' => 'Alimosho', 'spotlight' => '1']));
        $lagosMidnight = Carbon::now('Africa/Lagos')->startOfDay()->setTimezone(date_default_timezone_get());
        $yesterday = $lagosMidnight->copy()->subHours(6)->toDateTimeString();
        $this->order('adaeze@example.test', 20, self::A, $yesterday, 'Adaeze Okafor', true);
        // Bigger, but cast TODAY: it must not crown anybody for yesterday.
        $this->order('tunde@example.test', 50, self::B, $lagosMidnight->copy()->addHours(1)->toDateTimeString(), 'Tunde Bakare', true);
        $this->order('quiet@example.test', 5, self::B, $yesterday, '', false);
        SupporterSpotlight::hide(VoteService::voterHash('quiet@example.test'));

        $mailer = new class extends \AfricaGates\Services\OtpService {
            public array $sent = [];
            public function __construct() {}
            public function sendBranded(...$a): array { $this->sent[] = $a; return ['success' => true]; }
        };
        $now = $lagosMidnight->copy()->addHours(3);
        $n = \AfricaGates\Services\SpotlightMail::sweep($mailer, $now, 'https://site.test');
        $to = array_map(static fn ($m) => $m[0] . ' | ' . $m[1], $mailer->sent);
        $this->assertContains('adaeze@example.test | You were Alimosho’s Champion of the Day', $to, 'yesterday’s champion was not told, or today’s votes decided yesterday');
        $this->assertNotContains('tunde@example.test | You were Alimosho’s Champion of the Day', $to);
        $this->assertContains('tunde@example.test | You’re in the Supporter Spotlight for Spot Award', $to);
        foreach ($mailer->sent as $m) {
            $this->assertNotSame('quiet@example.test', $m[0], 'somebody who asked to be off was written to');
            $this->assertStringContainsString('/spotlight/hide?k=', $m[3], 'a message without the way off');
        }
        $this->assertSame(0, \AfricaGates\Services\SpotlightMail::sweep($mailer, $now, 'https://site.test'), 'told twice');
        $this->assertSame($n, count($mailer->sent));
    }

    /** The band says what the beneficiary has received from verified votes, and the nudge. */
    public function test_the_campaign_card_reports_real_progress_and_a_next_step(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'paid_voting_enabled'], ['value' => '1']);
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'vote_price_naira'], ['value' => '200']);
        $at = Carbon::now()->toDateTimeString();
        $this->order('adaeze@example.test', 25, self::A, $at, 'Adaeze Okafor', true);
        $this->order('refund@example.test', 100, self::A, $at, 'Refunded', true, ['refunded_at' => $at]);
        $this->assertNull(AwardCampaign::save(self::P, ['community' => 'Alimosho', 'rally' => 'Who will celebrate our own, if not us?',
            'beneficiary' => 'CACENTRE', 'share_pct' => '30', 'spotlight' => '1']));
        $html = $this->page();
        $this->assertStringContainsString('<b>₦1,500</b><span>for CACENTRE so far</span>', $html, '25 settled votes × ₦60 — a refunded order was counted, or the figure is not the ledger’s');
        // One settled supporter (the refunded order is nobody): an invitation, never a contest.
        $this->assertStringContainsString('Join the first person celebrating Alimosho’s own.', $html);
        $this->assertStringNotContainsString('would make you today’s champion', $html);
        $this->assertStringContainsString('href="#vp-race"', $html);
    }
}
