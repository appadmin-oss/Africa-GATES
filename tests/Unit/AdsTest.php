<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Ads\AdCampaigns;
use AfricaGates\Services\Ads\AdDelivery;
use AfricaGates\Services\Ads\AdRateCard;
use AfricaGates\Services\Ads\AdReview;
use AfricaGates\Services\Ads\AdRules;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Ads, the Meta model (owner, 27 Oct 2026): campaign → ad set → ad, reviewed before it runs,
 * labelled, a published rate card, and a free trial that is views and never money.
 *
 * What is held, and why each is worth a test:
 *   · the trial is one per account, and its seven days start when a person approves it;
 *   · nothing an advertiser types reaches an href unchecked, and never a ballot (D15);
 *   · the nominee rules: an offer tied to voting is refused, an advertiser in an OPEN award
 *     is kept off the vote page and nowhere else;
 *   · a view is counted once per drawn ad, so a replayed beacon buys nothing, and a forged
 *     token counts nothing;
 *   · delivery stops at the trial's views and respects the frequency cap; there is no location targeting in the beta;
 *   · spend is views × the BOOKED rate, never past the budget;
 *   · only the four approved pages may draw an ad. A ballot, a nominee and a result never;
 *   · a regulated kind of ad (food, health, money) is flagged in the beta and refused after it
 *     without its approvals, and the policy states which, from the same table;
 *   · an empty slot advertises advertising, honestly: never "Sponsored", never counted.
 */
final class AdsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function user(string $name = 'Kemi Adebayo'): int
    {
        return (int) DB::table('gates_users')->insertGetId(['name' => $name, 'email' => 'k-' . bin2hex(random_bytes(3)) . '@example.test',
            'phone' => '08031234567', 'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed> */
    private function good(array $over = []): array
    {
        return $over + [
            'objective' => 'traffic', 'advertiser' => 'Mama Put Kitchen', 'name' => '', 'category' => 'general',
            'placements' => ['discover', 'vote_hub', 'events'],
            'headline' => 'Jollof by the tray', 'body' => 'Party trays delivered across Lagos, same day.',
            'cta' => 'shop_now', 'url' => 'https://mamaput.example.ng/trays', 'offer' => '10% off this week', 'offer_code' => 'TRAY10',
            'standards' => '1',
        ];
    }

    /** A trial, created and approved. @return array{campaign:int, ad:int, set:int} */
    private function liveTrial(int $uid, array $over = []): array
    {
        $r = AdCampaigns::createTrial($uid, $this->good($over), '/uploads/ads/x.jpg');
        $this->assertTrue($r['ok'], json_encode($r));
        $set = DB::table('gates_ad_sets')->where('campaign_id', $r['id'])->first();
        $ad  = DB::table('gates_ads')->where('ad_set_id', $set->id)->first();
        $this->assertTrue(AdReview::decide((int) $ad->id, 1, true)['ok']);
        return ['campaign' => (int) $r['id'], 'ad' => (int) $ad->id, 'set' => (int) $set->id];
    }

    public function test_the_trial_is_one_per_account_and_waits_for_a_person(): void
    {
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, $this->good(), '/uploads/ads/x.jpg');
        $this->assertTrue($r['ok']);
        $c = AdCampaigns::detail($r['id'], $uid);
        $this->assertSame('in_review', $c['delivery'], 'nothing runs before a person has looked');
        $this->assertSame(AdRules::TRIAL_VIEWS, $c['view_cap']);
        $this->assertNull($c['starts_at'], 'the seven days have not started while it waits');
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]), 'and nothing is shown');

        $again = AdCampaigns::createTrial($uid, $this->good(), '/uploads/ads/x.jpg');
        $this->assertFalse($again['ok'], 'one trial per account');

        $ad = DB::table('gates_ads')->first();
        $this->assertTrue(AdReview::decide((int) $ad->id, 1, true)['ok']);
        $c = AdCampaigns::detail($r['id'], $uid);
        $this->assertSame('active', $c['delivery']);
        $days = (strtotime((string) $c['ends_at']) - strtotime((string) $c['starts_at'])) / 86400;
        $this->assertEqualsWithDelta(AdRules::TRIAL_DAYS, $days, 0.01, 'the window starts at approval');
    }

    public function test_a_refusal_needs_a_reason_and_the_advertiser_can_fix_it(): void
    {
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, $this->good(), '/uploads/ads/x.jpg');
        $ad = DB::table('gates_ads')->first();
        $this->assertFalse(AdReview::decide((int) $ad->id, 1, false, '')['ok'], 'no silent refusals');
        $this->assertTrue(AdReview::decide((int) $ad->id, 1, false, 'Use a photo of the food.')['ok']);
        $this->assertSame('not_approved', AdCampaigns::detail($r['id'], $uid)['delivery']);

        $fix = AdCampaigns::resubmitAd((int) $ad->id, $uid, $this->good(['headline' => 'Fresh jollof trays']), null);
        $this->assertTrue($fix['ok']);
        $row = DB::table('gates_ads')->where('id', $ad->id)->first();
        $this->assertSame('pending', $row->review, 'a changed ad is checked again');
        $this->assertSame('/uploads/ads/x.jpg', $row->image_path, 'and keeps its image when none is sent');
        $this->assertFalse(AdCampaigns::resubmitAd((int) $ad->id, $this->user('Someone Else'), $this->good(), null)['ok'], 'only by its owner');
    }

    public function test_the_creative_rules_refuse_ballots_and_offers_tied_to_voting(): void
    {
        $bad = [
            'url'   => ['url' => 'https://' . (string) parse_url(\AfricaGates\Support\SiteUrl::base(), PHP_URL_HOST) . '/vote/x/12'],
            'url2'  => ['url' => '/results/4'],
            'url3'  => ['url' => 'javascript:alert(1)'],
            'offer' => ['offer' => 'Free drink if you vote for us'],
            'body'  => ['body' => 'short'],
        ];
        foreach ($bad as $why => $over) {
            $v = AdRules::creative($this->good($over));
            $this->assertNotSame([], $v['errors'], $why . ' should be refused');
        }
        $this->assertSame([], AdRules::creative($this->good(['url' => 'https://elsewhere.example/vote']))['errors'],
            "somebody else's /vote is their business");
        $this->assertContains('Mentions voting or an award', AdRules::flags($this->good(['body' => 'Our award-winning jollof, now in trays.']), 0),
            'a word that needs judgement is flagged for the reviewer, not refused');
    }

    public function test_an_advertiser_in_an_open_award_stays_off_the_vote_page_only(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore(['id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open' => date('Y-m-d H:i:s', strtotime('-1 day')), 'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days'))]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 10, 'cycle_id' => 1, 'slug' => 'c', 'title' => 'Category']);
        DB::table('gates_nominees')->insertOrIgnore(['id' => 1, 'category_id' => 10, 'name' => 'Nominee', 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        $uid = $this->user();
        DB::table('gates_nominee_claims')->insert(['nominee_id' => 1, 'user_id' => $uid, 'status' => 'active', 'method' => 'otp', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue(AdRules::inOpenAward($uid));
        $this->liveTrial($uid);
        $this->assertNull(AdDelivery::pick('vote_hub', ['reader' => true]), 'not on the vote page while their award is open');
        $this->assertNotNull(AdDelivery::pick('discover', ['reader' => true]), 'everywhere else, like anybody');
    }

    public function test_a_view_counts_once_and_a_forged_token_counts_nothing(): void
    {
        $t = $this->liveTrial($this->user());
        $ad = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertNotNull($ad);
        $this->assertNull(AdDelivery::pick('discover', ['reader' => false]), 'a bot is never shown one');

        $this->assertTrue(AdDelivery::seen($ad['token']));
        $this->assertFalse(AdDelivery::seen($ad['token']), 'a replayed beacon buys nothing');
        $forged = preg_replace('/[a-f0-9]{32}$/', str_repeat('0', 32), $ad['token']);
        $this->assertFalse(AdDelivery::seen((string) $forged), 'nor does a forged one');
        $this->assertFalse(AdDelivery::seen($t['ad'] . '.discover.' . date('Ymd') . '.abcdefabcdef.' . str_repeat('a', 32)));

        $tot = AdCampaigns::totals($t['set']);
        $this->assertSame(1, $tot['views']);

        $this->assertSame('https://mamaput.example.ng/trays', AdDelivery::click($ad['token'], true));
        AdDelivery::click($ad['token'], true);
        $this->assertSame(1, AdCampaigns::totals($t['set'])['clicks'], 'a click counts once per drawn ad');

        // The destination is re-checked on the way out: a row can outlive its validation.
        DB::table('gates_ads')->where('id', $t['ad'])->update(['url' => '/vote/x/1']);
        $this->assertSame('/', AdDelivery::click($ad['token'], true));
    }

    public function test_delivery_stops_at_the_trial_views_and_keeps_to_the_frequency_cap(): void
    {
        $t = $this->liveTrial($this->user());
        for ($i = 0; $i < AdRules::FREQUENCY_CAP; $i++) {
            $this->assertNotNull(AdDelivery::pick('discover', ['reader' => true]), 'shown ' . ($i + 1));
        }
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]), 'then the frequency cap');

        $_SESSION = [];
        DB::table('gates_ad_stats')->insert(['ad_id' => $t['ad'], 'placement' => 'discover', 'day' => date('Y-m-d', time() - 86400), 'views' => AdRules::TRIAL_VIEWS, 'clicks' => 0]);
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]), 'the trial ends at its views');
        $this->assertSame('completed', AdCampaigns::detail($t['campaign'], (int) DB::table('gates_ad_campaigns')->value('user_id'))['delivery']);
    }

    /** No location targeting in the beta (owner, 28 Oct 2026): nothing to choose, nothing filtered. */
    public function test_there_is_no_location_targeting_in_the_beta(): void
    {
        $this->assertFalse(DB::schema()->hasColumn('gates_ad_sets', 'countries'));
        $uid = $this->user();
        $html = ChromeRender::html('/account/ads/new', ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringNotContainsString('countries[]', $html);
    }

    public function test_the_advertiser_must_confirm_the_policy(): void
    {
        $r = AdCampaigns::createTrial($this->user(), $this->good(['standards' => '']), '/uploads/ads/x.jpg');
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('standards', $r['errors']);
    }

    /** Hide stops the campaign for this reader; a report is once per network and a person decides. */
    public function test_a_reader_can_hide_or_report_and_a_person_can_take_it_down(): void
    {
        $t = $this->liveTrial($this->user());
        $ad = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertTrue(AdDelivery::hide($ad['token']));
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]), 'hidden for this reader');
        $_SESSION = [];
        $ad = AdDelivery::pick('discover', ['reader' => true]);
        $this->assertNotNull($ad, 'and only for them');

        $this->assertFalse(AdDelivery::report($ad['token'], 'not-a-reason', 'net1'));
        $this->assertTrue(AdDelivery::report($ad['token'], 'misleading', 'net1'));
        AdDelivery::report($ad['token'], 'misleading', 'net1');
        AdDelivery::report($ad['token'], 'offensive', 'net2');
        $rep = AdReview::reported();
        $this->assertCount(1, $rep);
        $this->assertSame(2, $rep[0]['reports'], 'one report per network');

        $_SESSION = [];
        $this->assertNotNull(AdDelivery::pick('discover', ['reader' => true]), 'a report alone never takes an ad down');
        $this->assertFalse(AdReview::takeDown($t['ad'], 1, '')['ok'], 'a takedown needs a reason');
        $this->assertTrue(AdReview::takeDown($t['ad'], 1, 'The prices are not real.')['ok']);
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]));
        $this->assertSame('not_approved', AdCampaigns::detail($t['campaign'], (int) DB::table('gates_ad_campaigns')->value('user_id'))['delivery']);
    }

    public function test_the_library_lists_running_ads_without_counting_them(): void
    {
        $this->liveTrial($this->user());
        $html = ChromeRender::html('/advertise/library');
        $this->assertStringContainsString('Mama Put Kitchen', $html);
        $this->assertStringContainsString('Running since', $html);
        $this->assertStringNotContainsString('data-ad="', $html, 'looking at the library is not a view');
        $this->assertSame(0, (int) DB::table('gates_ad_inventory')->sum('slots'));
    }

    /** The legal directory, and the policy in it, generated from the same rules the site runs. */
    public function test_the_legal_directory_lists_every_policy_and_the_advertising_policy_reads_the_rules(): void
    {
        $dir = ChromeRender::html('/legal');
        $listed = 0;
        foreach (\AfricaGates\Services\LegalService::directory() as $g) {
            foreach ($g['docs'] as $d) { $this->assertStringContainsString(htmlspecialchars($d['title'], ENT_QUOTES | ENT_HTML5), $dir); $listed++; }
        }
        $this->assertGreaterThanOrEqual(6, $listed, 'every published document, each by its stored title');
        $this->assertStringContainsString('Advertising Policy', $dir);
        $this->assertStringContainsString('href="/legal/advertising"', $dir);
        $this->assertStringContainsString('href="/privacy"', $dir, 'a document with its own address is linked there');

        $doc = ChromeRender::html('/legal/advertising');
        $this->assertStringContainsString('ARCON', $doc);
        foreach (AdRules::STANDARDS as [$name]) $this->assertStringContainsString(htmlspecialchars($name, ENT_QUOTES | ENT_HTML5), $doc);
        $this->assertStringContainsString(number_format(AdRules::TRIAL_VIEWS) . ' views over ' . AdRules::TRIAL_DAYS . ' days', $doc, 'the figures are generated');
        $this->assertStringContainsString('/legal/advertising/download/txt', $doc, 'downloads at the address the document lives at');
        $this->assertSame(200, ChromeRender::page('/legal/advertising/download/txt')->getStatusCode());
        $this->assertStringContainsString('href="/legal"', ChromeRender::html('/'), 'the directory is in the footer');
    }

    public function test_paused_and_cancelled_campaigns_are_not_shown(): void
    {
        $uid = $this->user();
        $t = $this->liveTrial($uid);
        $this->assertTrue(AdCampaigns::setStatus($t['campaign'], $uid, AdCampaigns::ST_PAUSED));
        $this->assertNull(AdDelivery::pick('events', ['reader' => true]));
        $this->assertTrue(AdCampaigns::setStatus($t['campaign'], $uid, AdCampaigns::ST_SUBMITTED));
        $this->assertNotNull(AdDelivery::pick('events', ['reader' => true]));
        $this->assertFalse(AdCampaigns::setStatus($t['campaign'], $this->user('Other'), AdCampaigns::ST_CANCELLED), 'only the owner');
        $this->assertTrue(AdCampaigns::setStatus($t['campaign'], $uid, AdCampaigns::ST_CANCELLED));
        $_SESSION = [];
        $this->assertNull(AdDelivery::pick('events', ['reader' => true]));
        $this->assertFalse(AdCampaigns::setStatus($t['campaign'], $uid, AdCampaigns::ST_SUBMITTED), 'a cancelled campaign stays cancelled');
    }

    public function test_spend_is_views_at_the_booked_rate_and_never_past_the_budget(): void
    {
        $this->assertSame(150, AdRateCard::cost(1, 150000), '₦1,500 per 1,000 is ₦1.50 a view');
        $this->assertSame(1, AdRateCard::cost(1, 999), 'rounded up to the kobo, never down to free');
        $this->assertSame('₦1,250.50', AdRateCard::format(125050));

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1500']);
        $this->assertSame(1500, AdRateCard::naira('discover'));
        $this->assertNull(AdRateCard::naira('events'), 'unset is not on sale');

        $uid = $this->user();
        $cid = (int) DB::table('gates_ad_campaigns')->insertGetId(['user_id' => $uid, 'name' => 'Paid', 'advertiser' => 'X', 'objective' => 'awareness',
            'trial' => 0, 'status' => 'submitted', 'paid_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $sid = (int) DB::table('gates_ad_sets')->insertGetId(['campaign_id' => $cid, 'placements' => 'discover',
            'budget_kobo' => 100000, 'rates' => json_encode(AdRateCard::snapshot(['discover'])),
            'starts_at' => date('Y-m-d H:i:s', time() - 3600), 'ends_at' => date('Y-m-d H:i:s', time() + 86400 * 5),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $aid = (int) DB::table('gates_ads')->insertGetId(['ad_set_id' => $sid, 'headline' => 'H', 'body' => 'Body text here', 'cta' => 'learn_more',
            'url' => '/events', 'review' => 'approved', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '9000']);
        DB::table('gates_ad_stats')->insert(['ad_id' => $aid, 'placement' => 'discover', 'day' => date('Y-m-d'), 'views' => 400, 'clicks' => 4]);
        $this->assertSame(60000, AdCampaigns::totals($sid)['spent_kobo'], '400 views at the BOOKED ₦1,500, not today\'s ₦9,000');

        DB::table('gates_ad_stats')->where('ad_id', $aid)->update(['views' => 5000]);
        $this->assertSame(100000, AdCampaigns::totals($sid)['spent_kobo'], 'never charged past the budget');
    }

    public function test_only_the_four_approved_pages_may_draw_an_ad(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $allowed = ['pages/discover.twig', 'pages/vote.twig', 'pages/events.twig', 'pages/events/detail.twig'];
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.twig')) continue;
            $rel = substr($f->getPathname(), strlen($root) + 1);
            // A LIVE slot: an include of the card that is not a preview. Previews (the
            // advertiser's own pages, the library, /advertise) count nothing and are drawn
            // on pages about ads, not beside a choice.
            $src = ChromeRender::source('templates/' . $rel);
            if (preg_match_all("~include 'partials/ad-slot\.twig' with \{([^}]*)\}~", $src, $m)) {
                foreach ($m[1] as $args) {
                    if (!str_contains($args, 'preview: true')) { $found[] = $rel; break; }
                }
            }
        }
        sort($found);
        sort($allowed);
        $this->assertSame($allowed, $found, 'a ballot, a nominee page and a result never carry an ad (D15)');
    }

    public function test_the_vote_page_draws_an_approved_ad_labelled_and_the_manager_shows_it(): void
    {
        $uid = $this->user();
        $this->liveTrial($uid);
        $html = (string) ChromeRender::page('/vote', [], [], 'GET', null, [
            'User-Agent' => 'Mozilla/5.0 Chrome/120', 'Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'en-GB',
        ])->getBody();
        $this->assertStringContainsString('class="ag-ad"', $html);
        $this->assertStringContainsString('Sponsored', $html);
        $this->assertStringContainsString('Mama Put Kitchen', $html, 'who paid is on the ad');
        $this->assertStringContainsString('mamaput.example.ng', $html, 'and where a tap goes');
        $this->assertStringContainsString('action="/ads/report"', $html, 'every ad can be reported');
        $this->assertStringContainsString('action="/ads/hide"', $html);
        $this->assertStringContainsString('rel="sponsored', $html);
        $this->assertStringContainsString('/assets/js/ads.js', $html);

        $mgr = ChromeRender::html('/account/ads', ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringContainsString('Mama Put Kitchen', $mgr);
        $this->assertStringContainsString('Active', $mgr);
        $this->assertStringContainsString('Ads Manager', $mgr);
        $this->assertStringContainsString('Your free trial is used', $mgr);
    }

    public function test_advertise_publishes_the_rate_card_and_the_builder_states_the_rules(): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_cpm_discover'], ['value' => '1500']);
        $html = ChromeRender::html('/advertise');
        $this->assertStringContainsString('₦1,500', $html);
        $this->assertStringContainsString('Free trial only for now', $html, 'a placement with no price says so');
        $this->assertStringContainsString('href="/legal/advertising"', $html);
        $this->assertStringContainsString(number_format(AdRules::TRIAL_VIEWS), $html);
        $this->assertStringContainsString('href="/advertise"', ChromeRender::html('/'), 'reachable from the footer');

        $uid = $this->user();
        $b = ChromeRender::html('/account/ads/new', ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringContainsString('action="/account/ads"', $b);
        $this->assertStringContainsString('enctype="multipart/form-data"', $b);
        $this->assertStringContainsString('maxlength="' . AdRules::BODY_MAX . '"', $b, 'the limit is the constant');
        $this->assertStringContainsString('Send for review', $b);
        $this->assertStringContainsString('name="standards"', $b, 'the policy is confirmed before sending');
        $this->assertStringContainsString('name="arcon_ref"', $b);
        $this->assertStringContainsString('data-ad-preview', $b, 'with a live preview');
    }

    /**
     * The cookie notice and the Marketing category both said "no ads" in so many words, and
     * the day ads ship that is the /cookies fault again (CLAUDE.md: a legal page that states a
     * fact is a fact that will go stale). The promise that stays true is narrower: not based
     * on you.
     */
    public function test_no_page_still_promises_there_are_no_ads(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('no ads', ChromeRender::html('/'));
        $this->assertStringNotContainsStringIgnoringCase('no ads', ChromeRender::html('/cookies'));
        $this->assertStringContainsString('Sponsored', ChromeRender::html('/cookies'), 'and the policy says what does run');
    }

    /**
     * Owner, 28 Oct 2026: approvals are asked for by KIND of ad, and enforced only once the
     * beta ends. In the beta a missing one is a flag the reviewer sees; after it, a refusal
     * the advertiser can fix. An everyday business never needs one.
     */
    public function test_regulated_kinds_of_ad_are_flagged_in_the_beta_and_refused_after_it(): void
    {
        $this->assertTrue(AdRules::inBeta(), 'beta is the default');
        $food = $this->good(['category' => 'food', 'url' => '/events']);
        $this->assertSame([], AdCampaigns::validate($food)['errors'], 'in the beta it is not refused');
        $uid = $this->user();
        $r = AdCampaigns::createTrial($uid, $food, '/uploads/ads/x.jpg');
        $this->assertTrue($r['ok']);
        $flags = (string) DB::table('gates_ads')->value('review_flags');
        $this->assertStringContainsString('No NAFDAC number', $flags, 'but the reviewer is told');
        $this->assertSame('food', DB::table('gates_ad_campaigns')->where('id', $r['id'])->value('category'));

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ads_beta'], ['value' => '0']);
        $this->assertFalse(AdRules::inBeta());
        $e = AdCampaigns::validate($food)['errors'];
        $this->assertArrayHasKey('nafdac_ref', $e, 'after the beta it is refused');
        $this->assertArrayHasKey('arcon_ref', $e);
        $this->assertArrayHasKey('licence_ref', AdCampaigns::validate($this->good(['category' => 'finance']))['errors']);
        $this->assertSame([], AdCampaigns::validate($this->good(['category' => 'food', 'url' => '/events',
            'arcon_ref' => 'ARCON/2026/123', 'nafdac_ref' => 'A1-2345']))['errors'], 'with them it passes');
        $this->assertSame([], AdCampaigns::validate($this->good())['errors'], 'an everyday business needs none');
        $this->assertArrayHasKey('category', AdCampaigns::validate($this->good(['category' => 'weapons']))['errors']);

        // The policy is generated from the same table, so it cannot list a different rule.
        $policy = \AfricaGates\Services\LegalDocument::advertisingHtml();
        foreach (AdRules::CATEGORIES as [$label, , $needs]) {
            if ($needs !== []) $this->assertStringContainsString(htmlspecialchars($label, ENT_QUOTES), $policy);
        }
        $this->assertStringContainsString(htmlspecialchars(AdRules::APPROVALS['nafdac'][0], ENT_QUOTES), $policy);
    }

    /**
     * Owner, 28 Oct 2026: "we'd need promotions for the ads so people know they can create
     * ads". An unsold slot says so, as every publisher's does, and is honest about it: our
     * own notice, never "Sponsored", nothing to count or report, once a day per reader.
     */
    public function test_an_empty_slot_advertises_advertising_and_says_whose_notice_it_is(): void
    {
        $nav = ['User-Agent' => 'Mozilla/5.0 Chrome/120', 'Accept' => 'text/html', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Accept-Language' => 'en-GB'];
        $html = (string) ChromeRender::page('/vote', [], [], 'GET', null, $nav)->getBody();
        $this->assertStringContainsString('class="ag-ad"', $html, 'nothing is booked, so the slot is ours');
        $this->assertStringContainsString('From Africa GATES', $html);
        $this->assertStringContainsString('href="/advertise"', $html);
        $this->assertStringContainsString(number_format(AdRules::TRIAL_VIEWS), $html, 'the trial figures are the rule');
        if (preg_match('{<article class="ag-ad".*?</article>}s', $html, $m) === 1) {
            $this->assertStringNotContainsString('Sponsored', $m[0], 'nobody paid for it');
            $this->assertStringNotContainsString('data-ad=', $m[0], 'it is never counted');
            $this->assertStringNotContainsString('/ads/report', $m[0]);
            $this->assertStringNotContainsString('rel="sponsored', $m[0]);
        } else {
            $this->fail('no ad card drawn');
        }

        $_SESSION = [];
        $this->assertNotNull(AdDelivery::house());
        $this->assertNull(AdDelivery::house(), 'once a day per reader');

        // A bot is shown nothing at all; a real booking always wins the slot.
        $_SESSION = [];
        $bot = (string) ChromeRender::page('/vote', [], [], 'GET', null, ['User-Agent' => 'Googlebot/2.1', 'Accept' => 'text/html'])->getBody();
        $this->assertStringNotContainsString('class="ag-ad"', $bot);
        $_SESSION = [];
        $this->liveTrial($this->user());
        $paid = (string) ChromeRender::page('/vote', [], [], 'GET', null, $nav)->getBody();
        $this->assertStringContainsString('Mama Put Kitchen', $paid);
        $this->assertStringNotContainsString('From Africa GATES', $paid);
    }

    /** The invitation reaches the people likeliest to advertise, where they already look. */
    public function test_the_account_and_the_organisation_console_offer_the_trial_until_it_is_used(): void
    {
        $uid = $this->user();
        $sess = ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid];
        $acc = ChromeRender::html('/account', $sess);
        $this->assertStringContainsString('Promote your business here', $acc);
        $this->assertStringContainsString('href="/account/ads/new"', $acc);
        $this->liveTrial($uid);
        $this->assertStringNotContainsString('Promote your business here', ChromeRender::html('/account', $sess), 'not once it is used');

        $a = \AfricaGates\Services\HelpCentre::bySlug('how-to-advertise');
        $this->assertNotNull($a, 'Gee and the help centre can answer "can I advertise?"');
    }


    // ══ THE ADS MANAGER, OWNER 28 OCT 2026: "IMPROVE THE CAMPAIGN FEATURES" ══════════

    private function mailer(): \AfricaGates\Services\OtpService
    {
        return new class extends \AfricaGates\Services\OtpService {
            public array $sent = [];
            public function __construct() { parent::__construct(['username' => 'u', 'password' => 'p']); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '', string $category = '', string $hero = '', string $unsubscribeUrl = '', array $attachments = [], string $preheader = '', int $heroHeight = 0, string $why = ''): array
            {
                $this->sent[] = compact('to', 'subject', 'plainBody', 'unsubscribeUrl');
                return ['success' => true];
            }
        };
    }

    /**
     * A decision used to reach nobody: the state changed in the Ads Manager and the advertiser
     * found out only by opening it. Both answers are mailed and appear in their notifications,
     * and the refusal carries its reason and the way to fix it.
     */
    public function test_the_advertiser_is_told_what_the_reviewer_decided(): void
    {
        $uid = $this->user();
        $t = $this->liveTrial($uid);
        $m = $this->mailer();
        $this->assertTrue(\AfricaGates\Services\Ads\AdNotice::decided($t['ad'], 'approved', $m));
        $this->assertStringContainsString('approved', $m->sent[0]['subject']);
        $this->assertStringContainsString('/account/ads/' . $t['campaign'], $m->sent[0]['plainBody']);
        $this->assertSame('', $m->sent[0]['unsubscribeUrl'], 'a decision about your own ad is not an announcement');

        AdReview::takeDown($t['ad'], 1, 'The link goes to a ballot.');
        $this->assertTrue(\AfricaGates\Services\Ads\AdNotice::decided($t['ad'], 'taken_down', $m));
        $this->assertStringContainsString('The link goes to a ballot.', $m->sent[1]['plainBody'], 'with the reason');
        $this->assertStringContainsString('#ad-' . $t['ad'], $m->sent[1]['plainBody'], 'and the way to fix it');
        $this->assertFalse(\AfricaGates\Services\Ads\AdNotice::decided($t['ad'], 'approved', null), 'no mailer, no throw');

        $email = (string) DB::table('gates_users')->where('id', $uid)->value('email');
        $alerts = array_column((new \AfricaGates\Services\AlertService())->forMember($uid, $email), 'text');
        $this->assertContains('Your ad needs a change: Jollof by the tray', $alerts, 'and in their notifications');
    }

    /** Any ad can be edited while it can run; the edit goes back to a person and stops showing. */
    public function test_an_approved_ad_can_be_edited_and_goes_back_to_review(): void
    {
        $uid = $this->user();
        $t = $this->liveTrial($uid, ['arcon_ref' => 'ARCON/26/1']);
        $this->assertNotNull(AdDelivery::pick('discover', ['reader' => true]));
        $_SESSION = [];

        $r = AdCampaigns::resubmitAd($t['ad'], $uid, $this->good(['headline' => 'Trays for every party', 'arcon_ref' => 'ARCON/26/1']), null);
        $this->assertTrue($r['ok'], json_encode($r));
        $ad = DB::table('gates_ads')->where('id', $t['ad'])->first();
        $this->assertSame('pending', $ad->review);
        $this->assertSame('Trays for every party', $ad->headline);
        $this->assertSame('ARCON/26/1', $ad->arcon_ref, 'the approval number given is kept');
        $this->assertSame('/uploads/ads/x.jpg', $ad->image_path, 'no new image keeps the old one');
        $this->assertNull(AdDelivery::pick('discover', ['reader' => true]), 'and it stops showing until approved');

        $this->assertFalse(AdCampaigns::resubmitAd($t['ad'], $this->user('Someone Else'), $this->good(), null)['ok'], 'only its owner');
        $this->assertTrue(AdCampaigns::setStatus($t['campaign'], $uid, AdCampaigns::ST_CANCELLED));
        $this->assertFalse(AdCampaigns::resubmitAd($t['ad'], $uid, $this->good(), null)['ok'], 'not once the campaign has ended');

        $html = ChromeRender::html('/account/ads/' . $t['campaign'], ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringNotContainsString('Edit ad', $html, 'an ended campaign offers no edit');
    }

    /** The comparison an advertiser cannot make alone, and never one rival's figure in disguise. */
    public function test_the_benchmark_waits_for_enough_other_campaigns_and_names_nobody(): void
    {
        $mine = $this->liveTrial($this->user());
        $add = function (int $views, int $clicks): void {
            $uid = $this->user('Peer ' . bin2hex(random_bytes(2)));
            $c = AdCampaigns::createTrial($uid, $this->good(), '/uploads/ads/x.jpg');
            $set = (int) DB::table('gates_ad_sets')->where('campaign_id', $c['id'])->value('id');
            $ad = (int) DB::table('gates_ads')->where('ad_set_id', $set)->value('id');
            DB::table('gates_ad_stats')->insert(['ad_id' => $ad, 'placement' => 'discover', 'day' => date('Y-m-d'), 'views' => $views, 'clicks' => $clicks]);
        };
        for ($i = 0; $i < AdCampaigns::BENCH_PEERS - 1; $i++) $add(200, 4);
        $add(50, 50); // too few views to count
        $this->assertNull(AdCampaigns::benchmark($mine['set']), 'four real peers is not enough');
        $add(200, 4);
        $this->assertSame(2.0, AdCampaigns::benchmark($mine['set']));

        $uid = (int) DB::table('gates_ad_campaigns')->where('id', $mine['campaign'])->value('user_id');
        $html = ChromeRender::html('/account/ads/' . $mine['campaign'], ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringContainsString('Average click rate on Africa GATES in the last 30 days: 2%', $html);
        $this->assertStringContainsString('Edit ad', $html);
    }

    /** The results as a spreadsheet: the owner's only, totals only. */
    public function test_the_results_download_as_a_spreadsheet_for_their_owner_only(): void
    {
        $uid = $this->user();
        $t = $this->liveTrial($uid);
        DB::table('gates_ad_stats')->insert(['ad_id' => $t['ad'], 'placement' => 'vote_hub', 'day' => date('Y-m-d'), 'views' => 400, 'clicks' => 8]);
        $sess = ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid];

        $res = ChromeRender::page('/account/ads/' . $t['campaign'] . '/results.csv', $sess);
        $this->assertStringStartsWith('text/csv', $res->getHeaderLine('Content-Type'));
        $csv = (string) $res->getBody();
        $this->assertStringContainsString('day,place,views,clicks,click_rate_pct', $csv);
        $this->assertMatchesRegularExpression('/^' . date('Y-m-d') . ',"?Vote page"?,400,8,2$/m', $csv);

        $other = $this->user('Someone Else');
        $this->assertSame(302, ChromeRender::page('/account/ads/' . $t['campaign'] . '/results.csv',
            ['user_id' => $other, 'member_id' => $other, 'account_id' => $other])->getStatusCode(), 'never another advertiser\'s');

        $html = ChromeRender::html('/account/ads/' . $t['campaign'], $sess);
        $this->assertStringContainsString('By place', $html);
        $this->assertStringContainsString('class="am-bar"', $html);
        $this->assertStringContainsString('results.csv', $html);
    }
}
