<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdMarket;
use AfricaGates\Services\Ads\AdPlanner;
use AfricaGates\Services\Ads\AdRateCard;
use AfricaGates\Services\Ads\AdRules;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The ad market (owner, 3 Nov 2026: "cost should also vary for high-impact positions … the
 * better the chances of getting people, the higher the cost. Make it dynamic … achieve targets").
 *
 * What it holds: a price moves only on a measurement, up where people act on ads and where
 * the week is nearly booked, down where they do not and where it is empty, inside the stated
 * floor and ceiling; one price a day; a booking keeps the day's price; paid campaigns are
 * served before trials; event pages are their own placement; and a goal becomes a plan that
 * is filled cheapest-per-result first and says when it cannot be met.
 */
final class AdMarketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function base(string $p, ?int $naira): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_' . $p], ['value' => $naira === null ? '' : (string) $naira]);
        AdMarket::forget();
    }

    /** $days days of history for a placement: slots drawn, views seen, clicks. */
    private function history(string $p, int $days, int $slots, int $views, int $clicks): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $d = date('Y-m-d', time() - $i * 86400);
            DB::table('gates_ad_inventory')->insert(['placement' => $p, 'day' => $d, 'slots' => $slots]);
            DB::table('gates_ad_stats')->insert(['ad_id' => 9000 + crc32($p) % 100, 'placement' => $p, 'day' => $d, 'views' => $views, 'clicks' => $clicks]);
        }
        AdMarket::forget();
    }

    /** A paid campaign on one placement with a budget, running for $days days. @return int set id */
    private function paid(string $p, int $budgetKobo, int $days = 7, int $rateKobo = 100000): int
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Ada', 'email' => 'a' . bin2hex(random_bytes(4)) . '@example.test', 'status' => 'active']);
        $cid = (int) DB::table('gates_ad_campaigns')->insertGetId(['user_id' => $uid, 'name' => 'Paid', 'advertiser' => 'Paid Co', 'objective' => 'traffic',
            'trial' => 0, 'status' => 'submitted', 'paid_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $sid = (int) DB::table('gates_ad_sets')->insertGetId(['campaign_id' => $cid, 'placements' => $p, 'budget_kobo' => $budgetKobo,
            'rates' => json_encode([$p => $rateKobo]), 'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => date('Y-m-d H:i:s', time() + $days * 86400), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        DB::table('gates_ads')->insert(['ad_set_id' => $sid, 'headline' => 'Paid ad', 'body' => 'A paying advertiser', 'cta' => 'learn_more',
            'url' => '/events', 'review' => 'approved', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return $sid;
    }

    public function test_nothing_moves_until_it_is_measured(): void
    {
        $this->base('discover', 1500);
        $this->history('vote_hub', 14, 400, 200, 1);   // the rest of the site, measured
        $this->history('discover', 3, 100, 50, 5);     // a few days, a lucky handful of taps
        $this->assertSame(1500, AdRateCard::naira('discover'), 'a price moved on a guess');
        $this->assertNull(AdRateCard::naira('vote_hub'), 'no base is still not on sale');
        $this->assertSame('new', array_column(AdMarket::card(), null, 'key')['discover']['tier']);
    }

    public function test_a_place_where_people_act_costs_more_and_one_where_they_do_not_costs_less(): void
    {
        $this->base('event_page', 1000);
        $this->base('vote_hub', 1000);
        $this->history('event_page', 14, 400, 200, 6);   // 3% tap rate
        $this->history('vote_hub', 14, 400, 200, 1);     // 0.5%
        $hi = AdMarket::quote('event_page');
        $lo = AdMarket::quote('vote_hub');
        $this->assertGreaterThan(1000, $hi['cpm'], 'the high-impact place sold at the base');
        $this->assertLessThan(1000, $lo['cpm']);
        $this->assertLessThanOrEqual((int) (1000 * AdMarket::CEIL), $hi['cpm']);
        $this->assertGreaterThanOrEqual((int) (1000 * AdMarket::FLOOR), $lo['cpm']);
        $card = array_column(AdMarket::card(), null, 'key');
        $this->assertSame('premium', $card['event_page']['tier']);
        $this->assertSame('event_page', AdMarket::card()[0]['key'], 'the place most likely to reach people is not listed first');

        // Fixed mode sells at the base again, without losing it.
        DB::table('gates_settings')->updateOrInsert(['key_name' => AdMarket::MODE_KEY], ['value' => 'fixed']);
        AdMarket::forget();
        $this->assertSame(1000, AdRateCard::naira('event_page'));
    }

    public function test_a_nearly_booked_week_costs_more_than_an_empty_one(): void
    {
        $this->base('discover', 1000);
        $this->history('discover', 10, 1000, 500, 4);
        $empty = AdMarket::quote('discover');
        $this->assertSame(AdMarket::D_MIN, $empty['demand'], 'empty space is discounted to fill it');

        // 500 seen views a day × 7 = 3,500 for the week; a paid budget for ~3,500 views on it.
        $this->paid('discover', 3500 * 100, 7, 100000);
        DB::table('gates_ad_prices')->delete();
        AdMarket::forget();
        $full = AdMarket::quote('discover');
        $this->assertGreaterThan(0.95, $full['sell']);
        $this->assertGreaterThan($empty['cpm'], $full['cpm'], 'a sold-out week was priced like an empty one');
    }

    public function test_no_price_leaves_the_stated_floor_and_ceiling(): void
    {
        $this->base('discover', 1000);
        $this->base('vote_hub', 1000);
        $this->history('discover', 10, 1000, 500, 250);      // half of viewers tap: off the scale
        $this->history('vote_hub', 10, 1000, 500, 1);
        $this->history('events', 10, 2000, 2000, 2);          // most of the site's views, almost no taps
        $this->paid('discover', 7000 * 100, 7);              // and the week is sold twice over
        $this->assertSame((int) (1000 * AdMarket::CEIL), AdRateCard::naira('discover'), 'past the ceiling the policy states');
        $this->assertGreaterThanOrEqual((int) (1000 * AdMarket::FLOOR), AdRateCard::naira('vote_hub'));
    }

    public function test_one_price_a_day_and_a_booking_keeps_it(): void
    {
        $this->base('discover', 2000);
        $this->history('discover', 10, 500, 300, 6);
        $this->history('vote_hub', 10, 500, 300, 1);
        $first = AdRateCard::naira('discover');
        DB::table('gates_ad_stats')->where('placement', 'discover')->update(['clicks' => 30]);   // the afternoon's figures change
        AdMarket::forget();
        $this->assertSame($first, AdRateCard::naira('discover'), 'two advertisers on one day were quoted two prices');
        $this->assertSame(['discover' => $first * 100], AdRateCard::snapshot(['discover']), 'a booking is not at the day’s price');

        $this->base('discover', 4000);                               // the operator changes the base
        $this->assertNotSame($first, AdRateCard::naira('discover'), 'a stored price outlived the base it was worked from');
    }

    public function test_paying_campaigns_are_served_before_trials(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi', 'email' => 'k' . bin2hex(random_bytes(4)) . '@example.test', 'status' => 'active']);
        $r = AdCampaigns::createTrial($uid, ['objective' => 'traffic', 'advertiser' => 'Trial Co', 'category' => 'general', 'placements' => ['discover'],
            'headline' => 'Free trial ad', 'body' => 'A business on its trial.', 'cta' => 'learn_more', 'url' => 'https://trial.example.ng', 'standards' => '1'], '/uploads/ads/t.jpg');
        $set = DB::table('gates_ad_sets')->where('campaign_id', $r['id'])->first();
        DB::table('gates_ads')->where('ad_set_id', $set->id)->update(['review' => 'approved']);
        DB::table('gates_ad_sets')->where('id', $set->id)->update(['starts_at' => date('Y-m-d H:i:s', time() - 3600), 'ends_at' => date('Y-m-d H:i:s', time() + 86400 * 6)]);
        $this->paid('discover', 500000);

        $this->assertSame('Paid Co', AdDelivery::pick('discover', ['reader' => true])['advertiser'] ?? null,
            'a free trial took a slot a paying campaign had room for');
    }

    public function test_event_pages_are_their_own_place_and_old_bookings_keep_them(): void
    {
        $this->assertArrayHasKey('event_page', AdRules::PLACEMENTS);
        $this->assertStringContainsString("forRequest('event_page'", ChromeRender::code('src/Controllers/EventsController.php'));

        $sid = $this->paid('events', 100000);
        ob_start();
        require dirname(__DIR__, 2) . '/database/migrations/2027_05_05_ad_market.php';
        ob_end_clean();
        $s = DB::table('gates_ad_sets')->where('id', $sid)->first();
        $this->assertSame('events,event_page', $s->placements, 'a booking for event pages lost them in the split');
        $this->assertSame(100000, json_decode($s->rates, true)['event_page'] ?? null, 'and must keep the rate it was booked at');
    }

    public function test_a_goal_becomes_a_plan_filled_cheapest_first_and_says_when_it_cannot(): void
    {
        $this->base('event_page', 1000);
        $this->base('vote_hub', 1000);
        $this->history('event_page', 14, 400, 200, 8);   // 4%: dearer per view, cheaper per visit
        $this->history('vote_hub', 14, 400, 200, 1);     // 0.5%

        $p = AdPlanner::plan('visits', 50, 14);
        $this->assertTrue($p['feasible']);
        $this->assertSame('event_page', $p['rows'][0]['key'], 'the plan did not start where a visit costs least');
        $this->assertGreaterThanOrEqual(50, $p['results']);
        $this->assertSame(array_sum(array_column($p['rows'], 'cost_kobo')), $p['cost_kobo']);

        $big = AdPlanner::plan('visits', 100000, 7);
        $this->assertFalse($big['feasible'], 'a plan promised more than the site can deliver');
        $this->assertGreaterThan(0, $big['short']);
        foreach ($big['rows'] as $r) {
            $daily = AdMarket::supplyDaily($r['key']);
            $this->assertLessThanOrEqual((int) floor($daily * 7 * AdMarket::SHARE_MAX), $r['views'], 'one advertiser planned on a whole page');
        }
    }

    public function test_advertise_states_the_market_and_answers_a_plan(): void
    {
        $this->base('event_page', 2000);
        $this->history('event_page', 14, 400, 200, 8);
        $this->history('vote_hub', 14, 400, 200, 1);     // premium is relative to the rest of the site
        $html = ChromeRender::html('/advertise?goal=visits&n=40&days=14');
        $price = AdRateCard::naira('event_page');
        $this->assertStringContainsString('₦' . number_format($price), $html, 'the page printed a price the market is not charging');
        $this->assertStringContainsString('Premium', $html);
        $this->assertStringContainsString('Plan for a result', $html);
        $this->assertStringContainsString('<table class="am-rates am-plan__t">', $html, 'the plan did not render');
        $this->assertStringContainsString((int) round(100 * AdMarket::CEIL) . '%', $html, 'the ceiling is typed, not read');

        $legal = \AfricaGates\Services\LegalDocument::advertisingHtml();
        $this->assertStringContainsString(number_format(AdMarket::MIN_VIEWS), $legal, 'the policy does not say how a price is set');
    }

    public function test_the_console_sees_the_month_against_its_target(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => AdMarket::TARGET_KEY], ['value' => '500000']);
        $sid = $this->paid('discover', 1000000);
        $ad = (int) DB::table('gates_ads')->where('ad_set_id', $sid)->value('id');
        DB::table('gates_ad_stats')->insert(['ad_id' => $ad, 'placement' => 'discover', 'day' => date('Y-m-d'), 'views' => 1000, 'clicks' => 10]);
        $rev = AdMarket::revenue();
        $this->assertSame(100000, $rev['earned_kobo'], '1,000 views at ₦1,000 per 1,000 is ₦1,000 earned');
        $this->assertSame(900000, $rev['booked_kobo']);
        $this->assertSame(50000000, $rev['target_kobo']);
        $this->assertNotEmpty(array_filter(AdMarket::advice(null, $rev + ['on_track' => false]), static fn ($a) => str_contains($a, 'target')));
    }
}
