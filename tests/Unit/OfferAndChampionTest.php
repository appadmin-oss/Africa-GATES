<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DailyChampion;
use AfricaGates\Services\OfferLink;
use AfricaGates\Services\SpotlightMail;
use AfricaGates\Services\SupporterSpotlight;
use AfricaGates\Services\VoteService;
use AfricaGates\Support\AwardCampaign;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE OFFER PAGE AND THE CHAMPION OF THE DAY, ANNOUNCED (owner, 17 Nov 2026: "create an our
 * offer page … they get the gift if they become champion of the day. The champion is announced
 * at the end of the day and is shareable. Once they vote, a link is sent with all the possible
 * benefits"; decided: most votes wins, the gift is claimed by contacting us, and the page shows
 * only what the award's settings say).
 *
 * What these try to break: a champion announced before the day's payments have settled, or
 * rewritten afterwards; a tie given to whoever came last; the gift given twice to one person in
 * a campaign; a claim code that can be made up; somebody else's claim code shown to a stranger;
 * a personal link whose signature is not checked; a section of promises drawn when the host
 * wrote nothing; a link in the settings that runs script; the sandbox announced.
 *
 * Ids below 255 (TINYINT clamp on MySQL); one ballot row per vote.
 */
final class OfferAndChampionTest extends TestCase
{
    private const P = 96, Y = 9601, C = 9611, A = 9621, B = 9622;

    protected function setUp(): void
    {
        parent::setUp();
        SupporterSpotlight::$ttl = 0;
        DB::table('gates_award_programmes')->insertOrIgnore(['id' => self::P, 'slug' => 'offer-award', 'title' => 'Offer Award', 'is_active' => 1]);
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => self::Y, 'programme_id' => self::P, 'year' => 2026, 'status' => 'voting',
            'voting_open' => '2026-11-01 00:00:00', 'voting_close' => '2026-12-20 00:00:00']);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => self::C, 'cycle_id' => self::Y, 'slug' => 'teachers', 'title' => 'Best Teacher']);
        DB::table('gates_nominees')->insert(['id' => self::A, 'name' => 'Chiamaka Eze', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_nominees')->insert(['id' => self::B, 'name' => 'Ibrahim Musa', 'category_id' => self::C, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
    }

    protected function tearDown(): void
    {
        SupporterSpotlight::$ttl = 60;
        Carbon::setTestNow();
        unset($_SESSION['vote_cast'], $_SESSION['vote_key']);
        parent::tearDown();
    }

    /** A time in Lagos, in the app's zone. */
    private function lagos(string $at): Carbon
    {
        return Carbon::parse($at, 'Africa/Lagos')->setTimezone(\AfricaGates\Support\Clock::timezone());
    }

    private function order(string $email, int $votes, int $nominee, string $lagosAt, string $name = ''): int
    {
        $at = $this->lagos($lagosAt)->toDateTimeString();
        $id = (int) DB::table('gates_donations')->insertGetId(['donor_name' => 'Supporter', 'donor_email' => $email, 'amount_naira' => $votes * 200,
            'status' => 'confirmed', 'payment_ref' => 'oc-' . bin2hex(random_bytes(5)), 'tier' => 'paid-vote', 'created_at' => $at]);
        for ($i = 0; $i < $votes; $i++) {
            DB::table('gates_votes')->insert(['nominee_id' => $nominee, 'category_id' => self::C, 'vote_type' => 'paid', 'weight' => 1,
                'voter_email_hash' => 'paidvote:' . $id . ':' . bin2hex(random_bytes(5)), 'donation_id' => $id, 'voter_name' => $name, 'show_name' => $name !== '' ? 1 : 0,
                'voted_at' => $at]);
        }
        return $id;
    }

    private function campaign(array $doc): void
    {
        DB::table('gates_award_programmes')->where('id', self::P)->update(['campaign_json' => json_encode($doc)]);
    }

    // ── the announcement ────────────────────────────────────────────────────────

    public function test_the_day_is_announced_the_morning_after_and_never_rewritten(): void
    {
        $this->order('ada@example.test', 5, self::A, '2026-11-16 10:00:00', 'Ada Obi');
        $this->order('bola@example.test', 3, self::B, '2026-11-16 22:00:00', 'Bola Ade');

        // Both halves of the rule, each on its own: which days are due, and the seal's own check.
        $this->assertSame(['2026-11-12', '2026-11-13', '2026-11-14'], DailyChampion::due($this->lagos('2026-11-16 08:59:00')));
        $this->assertSame(['2026-11-13', '2026-11-14', '2026-11-15'], DailyChampion::due($this->lagos('2026-11-16 09:00:00')));
        $this->assertNull(DailyChampion::seal(self::P, self::Y, '2026-11-16', $this->lagos('2026-11-17 08:59:00')), 'sealed before the payments had the night to settle');
        $this->assertSame(0, DailyChampion::sweep($this->lagos('2026-11-16 23:30:00')), 'announced while the day was still running');
        $this->assertSame(0, DailyChampion::sweep($this->lagos('2026-11-17 08:59:00')), 'announced before the payments had the night to settle');
        $this->assertSame(1, DailyChampion::sweep($this->lagos('2026-11-17 09:01:00')));
        $d = DailyChampion::day(self::Y, '2026-11-16');
        $this->assertSame('Ada Obi', $d->display);
        $this->assertSame(5, (int) $d->votes);
        $this->assertSame('Chiamaka Eze', $d->backing);

        // A refund afterwards does not quietly hand the announced day to somebody else.
        DB::table('gates_donations')->where('donor_email', 'ada@example.test')->update(['refunded_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(0, DailyChampion::sweep($this->lagos('2026-11-17 12:00:00')));
        $this->assertSame('Ada Obi', DailyChampion::day(self::Y, '2026-11-16')->display, 'an announced day rewritten');
    }

    public function test_a_tie_goes_to_whoever_got_there_first_and_the_gift_is_given_once(): void
    {
        $this->order('late@example.test', 4, self::A, '2026-11-15 20:00:00', 'Late Lade');
        $this->order('early@example.test', 4, self::B, '2026-11-15 08:00:00', 'Early Ebi');
        $this->order('early@example.test', 6, self::B, '2026-11-16 09:30:00', 'Early Ebi');
        DailyChampion::sweep($this->lagos('2026-11-17 10:00:00'));

        $d15 = DailyChampion::day(self::Y, '2026-11-15');
        $d16 = DailyChampion::day(self::Y, '2026-11-16');
        $this->assertSame('Early Ebi', $d15->display, 'a tie given to whoever came last');
        $this->assertSame(1, (int) $d15->gift);
        $this->assertSame('Early Ebi', $d16->display);
        $this->assertSame(0, (int) $d16->gift, 'the gift given twice to one person in a campaign');
    }

    public function test_the_sandbox_is_never_announced(): void
    {
        DB::table('gates_award_programmes')->where('id', self::P)->update(['is_active' => 0]);
        $this->order('ada@example.test', 5, self::A, '2026-11-16 10:00:00', 'Ada Obi');
        $this->assertSame(0, DailyChampion::sweep($this->lagos('2026-11-17 10:00:00')));
    }

    public function test_somebody_who_asked_off_the_spotlight_is_unnamed_on_past_days_too(): void
    {
        $this->order('ada@example.test', 5, self::A, '2026-11-16 10:00:00', 'Ada Obi');
        DailyChampion::sweep($this->lagos('2026-11-17 10:00:00'));
        DB::table('gates_spotlight_hidden')->insert(['person_key' => VoteService::voterHash('ada@example.test'), 'created_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(DailyChampion::day(self::Y, '2026-11-16')->display);
        $html = ChromeRender::html('/vote/offer-award/champion/2026-11-16');
        $this->assertStringNotContainsString('Ada Obi', $html);
        $this->assertStringContainsString('A proud supporter', $html);
    }

    // ── the claim ───────────────────────────────────────────────────────────────

    public function test_a_claim_code_checks_out_and_cannot_be_made_up(): void
    {
        $this->order('ada@example.test', 5, self::A, '2026-11-16 10:00:00', 'Ada Obi');
        DailyChampion::sweep($this->lagos('2026-11-17 10:00:00'));
        $d = DailyChampion::day(self::Y, '2026-11-16');
        $code = DailyChampion::code($d);
        $this->assertNotNull(DailyChampion::byCode($code));
        $this->assertNotNull(DailyChampion::byCode(strtolower($code)), 'a code typed in lower case refused');
        $this->assertNull(DailyChampion::byCode('CD' . $d->id . '-0000000000'));

        $ok = ChromeRender::page('/champions/' . $code);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('This claim is genuine', (string) $ok->getBody());
        $this->assertStringContainsString('carries the Champion’s gift', (string) $ok->getBody());
        $this->assertSame(404, ChromeRender::page('/champions/CD' . $d->id . '-0000000000')->getStatusCode());
    }

    public function test_only_the_champion_is_shown_the_code_and_how_to_claim(): void
    {
        $this->campaign(['claim_contact' => '0803 123 4567', 'champion_gifts' => ['Seven-day CACENTRE guest membership']]);
        $this->order('ada@example.test', 5, self::A, '2026-11-16 10:00:00', 'Ada Obi');
        DailyChampion::sweep($this->lagos('2026-11-17 10:00:00'));
        $code = DailyChampion::code(DailyChampion::day(self::Y, '2026-11-16'));

        $public = ChromeRender::html('/vote/offer-award/champion/2026-11-16');
        $this->assertStringContainsString('Ada Obi, Champion of the Day', $public);
        $this->assertStringNotContainsString($code, $public, 'a stranger shown the claim code');
        $this->assertStringContainsString('/vote/offer-award/champion/2026-11-16.png', $public, 'no card for the link preview');

        $mine = ChromeRender::html(OfferLink::path('offer-award', VoteService::voterHash('ada@example.test')) === '' ? '' : '/vote/offer-award/champion/2026-11-16?' . parse_url(OfferLink::path('offer-award', VoteService::voterHash('ada@example.test')), PHP_URL_QUERY));
        $this->assertStringContainsString($code, $mine);
        $this->assertStringContainsString('https://wa.me/2348031234567', $mine, 'a local number not turned into the form WhatsApp needs');
        $this->assertStringContainsString('Seven-day CACENTRE guest membership', $mine);

        // A forged signature is somebody else's link: nothing personal.
        $forged = ChromeRender::html('/vote/offer-award/champion/2026-11-16?k=' . VoteService::voterHash('ada@example.test') . '&s=0000000000000000');
        $this->assertStringNotContainsString($code, $forged);

        $png = ChromeRender::page('/vote/offer-award/champion/2026-11-16.png');
        $this->assertSame(200, $png->getStatusCode());
        $this->assertSame('image/png', $png->getHeaderLine('Content-Type'));
    }

    public function test_the_champion_email_carries_the_announcement_and_the_code(): void
    {
        $this->campaign(['claim_contact' => 'claims@example.test', 'champion_gifts' => ['A training opportunity']]);
        $this->order('ada@example.test', 5, self::A, '2026-11-16 10:00:00', 'Ada Obi');
        DailyChampion::sweep($this->lagos('2026-11-17 10:00:00'));
        $row = DailyChampion::day(self::Y, '2026-11-16');
        $prog = DB::table('gates_award_programmes')->where('id', self::P)->first();
        [, , $plain] = SpotlightMail::compose('champion', ['key' => $row->person_key, 'name' => 'Ada Obi', 'votes' => 5, 'for' => 'Chiamaka Eze', 'row' => $row],
            $prog, AwardCampaign::of($prog), 'https://ag.test');
        $this->assertStringContainsString('https://ag.test/vote/offer-award/champion/2026-11-16', $plain);
        $this->assertStringContainsString(DailyChampion::code($row), $plain);
        $this->assertStringContainsString('claims@example.test', $plain);
        $this->assertStringContainsString('A training opportunity', $plain);
    }

    // ── the offer page ──────────────────────────────────────────────────────────

    public function test_the_page_promises_only_what_the_award_wrote(): void
    {
        $bare = ChromeRender::html('/vote/offer-award/offer');
        $this->assertStringContainsString('What your support unlocks', $bare);
        foreach (['Where your vote goes', 'What your votes unlock', 'The Champion’s gift', 'Community Opportunity Award', 'Give to the project', 'How we account for it'] as $s) {
            $this->assertStringNotContainsString($s, $bare, '"' . $s . '" drawn with nothing written');
        }

        $this->campaign(['beneficiary' => 'CACENTRE', 'share_pct' => 30, 'champion_gifts' => ['Seven-day CACENTRE guest membership'],
            'mobiliser_gifts' => ['A training opportunity'], 'ladder' => [['votes' => 5, 'benefit' => 'A learning resource']],
            'opportunity' => 'Judged by a panel.', 'capital_title' => 'Ogidi Omo', 'capital_note' => 'Targets, not commitments.', 'capital_url' => '/giving',
            'accountability' => 'Reported monthly.']);
        $full = ChromeRender::html('/vote/offer-award/offer');
        foreach (['What your votes unlock', 'A learning resource', 'Seven-day CACENTRE guest membership', 'A training opportunity', 'Community Opportunity Award',
                  'Ogidi Omo', 'href="/giving"', 'Reported monthly.'] as $s) {
            $this->assertStringContainsString($s, $full, $s . ' missing');
        }
    }

    public function test_the_personal_link_shows_their_own_standing_and_only_when_signed(): void
    {
        $this->campaign(['ladder' => [['votes' => 3, 'benefit' => 'A learning resource'], ['votes' => 10, 'benefit' => 'A guest pass']]]);
        $this->order('ada@example.test', 4, self::A, '2026-11-16 10:00:00');
        $link = OfferLink::path('offer-award', VoteService::voterHash('ada@example.test'));
        $html = ChromeRender::html($link);
        $this->assertStringContainsString('You’ve given 4 votes in Offer Award', $html);
        $this->assertStringContainsString('6 more votes unlock A guest pass', $html);
        $this->assertMatchesRegularExpression('#is-got.*?A learning resource.*?Unlocked#s', $html);
        $forged = ChromeRender::html('/vote/offer-award/offer?k=' . VoteService::voterHash('ada@example.test') . '&s=0000000000000000');
        $this->assertStringNotContainsString('You’ve given', $forged, 'an unsigned key read somebody\'s standing');
    }

    public function test_the_ballot_hands_a_free_voter_their_own_link(): void
    {
        $_SESSION['vote_cast'][self::A] = time();
        $_SESSION['vote_key'] = VoteService::voterHash('voter@example.test');
        DB::table('gates_votes')->insert(['nominee_id' => self::A, 'category_id' => self::C, 'vote_type' => 'standard', 'weight' => 1,
            'voter_email_hash' => VoteService::voterHash('voter@example.test'), 'voter_name' => 'V', 'show_name' => 0, 'voted_at' => date('Y-m-d H:i:s')]);
        $html = ChromeRender::html('/vote/offer-award/' . self::A . '-chiamaka-eze', ['vote_cast' => [self::A => time()], 'vote_key' => VoteService::voterHash('voter@example.test')]);
        $this->assertStringContainsString(htmlspecialchars(OfferLink::path('offer-award', VoteService::voterHash('voter@example.test'))), $html);
    }

    public function test_a_link_in_the_settings_cannot_run_script(): void
    {
        $this->assertSame('/gift/ogidi-omo', AwardCampaign::safeUrl('/gift/ogidi-omo'));
        $this->assertSame('https://example.org/give', AwardCampaign::safeUrl('https://example.org/give'));
        foreach (['javascript:alert(1)', '//evil.example/x', 'http://plain.example', 'data:text/html,x'] as $bad) {
            $this->assertSame('', AwardCampaign::safeUrl($bad), $bad . ' accepted');
        }
        $this->assertNotNull(AwardCampaign::save(self::P, ['capital_url' => 'javascript:alert(1)']), 'a script link saved');
        $this->assertNull(AwardCampaign::claimLink(['claim_contact' => 'javascript:alert(1)']));
        $this->assertSame('mailto:claims@example.test', AwardCampaign::claimLink(['claim_contact' => 'claims@example.test'])['href']);

        $this->assertNull(AwardCampaign::save(self::P, ['champion_gifts' => "One\n\nTwo\n", 'mobiliser_gifts' => '']));
        $this->assertSame(['One', 'Two'], AwardCampaign::forProgramme(self::P)['champion_gifts']);
    }
}
