<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DemoSeeder;
use AfricaGates\Services\GlobeBand;
use AfricaGates\Services\HomeFront;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE HOMEPAGE (Phase 4 — HomePageV3.dc.html + WeAreAfrica.dc.html), AND WHAT IT IS NOT
 * ALLOWED TO SAY.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY FIGURE IS COUNTED, AND THE DC'S ARE THE ONES IT MUST NEVER PRINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The design arrives over demo data — "214 editions open", "1.2M verified voters", a
 * commendation from a "National Honours Office", "Ngozi in Enugu just joined". This repo
 * has shipped that shape twice already (StatsService's "1,247 profiles", the globe band's
 * sixteen invented cities). So this file holds the two halves: that each number on the page
 * is a count `HomeFront` made from the platform's own rows, and that no placeholder from
 * the DC reaches the template or the response.
 *
 * And the sandbox: DemoSeeder mints real rows — nominees, votes — under an inactive
 * programme, and the front page is the first place a stranger would see them.
 *
 * Rules re-asserted from the destroyed page's guards (inventory pages--home.md):
 * `PageRenderSmokeTest::test_home_renders_…` (it renders, with real data) and the four
 * rendered-page CSP checks.
 */
final class HomePageTest extends TestCase
{
    private const TEMPLATES = ['templates/pages/home.twig', 'templates/partials/we-are-africa.twig',
                               'templates/partials/site-footer.twig'];

    protected function setUp(): void
    {
        parent::setUp();
        HomeFront::forget();
        GlobeBand::forget();
        DB::table('gates_award_programmes')->update(['is_active' => 0]);
        DB::table('gates_cache')->delete();
    }

    private function programme(string $slug, string $title, array $cycle): int
    {
        $p = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => $title, 'is_active' => 1, 'sort_order' => 1,
        ]);

        return (int) DB::table('gates_award_cycles')->insertGetId($cycle + [
            'programme_id' => $p, 'year' => 2026, 'status' => 'upcoming',
        ]);
    }

    private function at(int $days): string
    {
        return date('Y-m-d H:i:s', time() + $days * 86400);
    }

    private function home(): string
    {
        HomeFront::forget();
        GlobeBand::forget();
        DB::table('gates_cache')->delete();

        return ChromeRender::html('/');
    }

    // ══ it renders, as the shell's page ══════════════════════════════════════

    public function test_the_homepage_renders_with_one_h1_and_the_brand_bar(): void
    {
        $res = ChromeRender::page('/');
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();

        $this->assertSame(1, preg_match_all('~<h1\b~', $html), 'the homepage must carry exactly one <h1>');
        $this->assertStringContainsString('Where Africa recognises its people', $html);
        $this->assertStringContainsString('ag-appbar--brand', $html, 'the phone bar is the DC\'s logo bar');
        // The tab destination is lit.
        $this->assertMatchesRegularExpression('~<a[^>]+aria-current="page"[^>]*>.*?Home~s', $html);
    }

    /** §8.1's order, for the sections that are drawn. */
    public function test_the_sections_come_in_the_specified_order(): void
    {
        $id = $this->programme('open', 'Open Awards', ['voting_open' => $this->at(-1), 'voting_close' => $this->at(4)]);
        DB::table('gates_award_categories')->insert(['cycle_id' => $id, 'slug' => 'a', 'title' => 'A']);
        $html = $this->home();

        $order = ['id="hm-h1"', 'class="hm-stats"', 'id="waa-h"', 'id="hm-now"', 'id="hm-host"', 'class="ag-foot"'];
        $last = -1;
        foreach ($order as $hook) {
            $at = strpos($html, $hook);
            $this->assertNotFalse($at, "$hook is not on the page");
            $this->assertGreaterThan($last, $at, "$hook is out of §8.1's order");
            $last = $at;
        }
    }

    // ══ the numbers are counts ═══════════════════════════════════════════════

    /**
     * Open is COMPUTED from the windows, never read off the stored status: a cycle whose
     * nominations window opened an hour ago and whose sweep has not run is open.
     */
    public function test_the_open_counts_are_computed_from_the_windows(): void
    {
        $this->programme('noms', 'Noms', ['status' => 'upcoming',
            'nominations_open' => $this->at(-1), 'nominations_close' => $this->at(9)]);
        $this->programme('vote', 'Vote', ['status' => 'nominations',
            'voting_open' => $this->at(-1), 'voting_close' => $this->at(3)]);
        $this->programme('shut', 'Shut', ['status' => 'judging',
            'voting_open' => $this->at(-9), 'voting_close' => $this->at(-2)]);

        $s = HomeFront::stats();
        $this->assertSame(1, $s['nominations_open']);
        $this->assertSame(1, $s['voting_open']);
    }

    /** SUM(weight), and never the sandbox's rehearsal votes. */
    public function test_votes_cast_is_the_weight_and_excludes_the_sandbox(): void
    {
        $c = $this->programme('live', 'Live', ['voting_open' => $this->at(-1), 'voting_close' => $this->at(3)]);
        $cat = (int) DB::table('gates_award_categories')->insertGetId(['cycle_id' => $c, 'slug' => 'a', 'title' => 'A']);
        $n = (int) DB::table('gates_nominees')->insertGetId(['category_id' => $cat, 'name' => 'Ada', 'status' => 'approved']);
        DB::table('gates_votes')->insert(['nominee_id' => $n, 'category_id' => $cat, 'voter_email_hash' => 'x', 'weight' => 25]);
        DB::table('gates_votes')->insert(['nominee_id' => $n, 'category_id' => $cat, 'voter_email_hash' => 'y', 'weight' => 1]);

        DemoSeeder::seed(0);
        $this->assertGreaterThan(0, (int) DB::table('gates_votes')->where('nominee_id', '!=', $n)->count(),
            'the sandbox minted no vote, so the exclusion below proves nothing');

        HomeFront::forget();
        $this->assertSame(26, HomeFront::stats()['votes']);
    }

    /** A memo has no observable behaviour but the questions it does not ask again. */
    public function test_the_cycle_pass_is_read_once_per_request(): void
    {
        $this->programme('a', 'A', ['voting_open' => $this->at(-1), 'voting_close' => $this->at(3)]);
        HomeFront::forget();

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        HomeFront::currentCycles();
        $first = count(DB::connection()->getQueryLog());
        DB::connection()->flushQueryLog();
        HomeFront::currentCycles();
        HomeFront::voting();
        $again = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertGreaterThan(0, $first, 'the first call must reach the database');
        $this->assertSame(0, $again, 'the stats and the voting list each re-read the cycles');
    }

    // ══ nothing from the DC, nothing from the sandbox ════════════════════════

    public function test_no_demo_figure_from_the_design_is_in_the_templates_or_the_page(): void
    {
        $retired = ['214', '1.2M', 'verified voters', 'Thandiwe', 'National Honours Office', 'Ngozi',
                    'just joined', 'unsplash', 'Soko Bank', 'Mathare', '₦6.8M', 'Kano', 'In their own words',
                    'One place to recognise people'];
        // What a READER sees: an asset hash or a nonce may contain "214" by chance.
        $html = strip_tags((string) preg_replace('~<(script|style)\b.*?</\1>~s', '', $this->home()));
        foreach (self::TEMPLATES as $t) {
            $src = ChromeRender::source($t);
            foreach ($retired as $s) $this->assertStringNotContainsStringIgnoringCase($s, $src, "$t carries the DC's \"$s\"");
        }
        foreach ($retired as $s) $this->assertStringNotContainsStringIgnoringCase($s, $html, "the homepage prints the DC's \"$s\"");
    }

    public function test_the_sandbox_never_reaches_the_homepage(): void
    {
        DemoSeeder::seed(0);
        $html = $this->home();

        $this->assertStringNotContainsString('DEMO', $html);
        $this->assertStringNotContainsString(DemoSeeder::PROGRAMME_SLUG, $html);
        $this->assertStringNotContainsString(DemoSeeder::MAIL_DOMAIN, $html);
        $this->assertSame(0, HomeFront::stats()['voting_open'], 'the rehearsal is counted as an award voting now');
    }

    // ══ the hero's record card is a decided award, or nothing ════════════════

    public function test_with_no_published_result_there_is_no_record_card(): void
    {
        $html = $this->home();
        $this->assertStringNotContainsString('class="hm-rec"', $html,
            'a record card was drawn with no decided award behind it — that is an invented recognition');
        // GAPS Q8: no stock photograph in its place.
        $this->assertStringContainsString('africa-silhouette.svg', $html);
    }

    // ══ the featured campaign ════════════════════════════════════════════════

    public function test_the_featured_campaign_is_the_open_one_closing_soonest_from_an_org_that_can_receive(): void
    {
        $ok  = (int) DB::table('gates_partner_orgs')->insertGetId(['slug' => 'ok', 'name' => 'Okay Org', 'status' => 'approved', 'subaccount_code' => 'A1']);
        $off = (int) DB::table('gates_partner_orgs')->insertGetId(['slug' => 'off', 'name' => 'Suspended Org', 'status' => 'suspended', 'subaccount_code' => 'A2']);
        $camp = static fn (int $org, string $slug, string $close, string $status = 'live') => DB::table('gates_org_campaigns')->insert([
            'org_id' => $org, 'slug' => $slug, 'title' => 'Appeal ' . $slug, 'target_naira' => 1000,
            'status' => $status, 'closes_on' => $close]);
        $camp($off, 'soonest-but-suspended', date('Y-m-d', time() + 86400));
        $camp($ok, 'later', date('Y-m-d', time() + 20 * 86400));
        $camp($ok, 'sooner', date('Y-m-d', time() + 5 * 86400));
        $camp($ok, 'closed', date('Y-m-d', time() - 86400));
        $camp($ok, 'draft', date('Y-m-d', time() + 2 * 86400), 'draft');

        $c = HomeFront::campaign();
        $this->assertNotNull($c);
        $this->assertSame('Appeal sooner', $c['title']);
        $this->assertSame('/giving/ok/sooner', $c['url']);
        $this->assertStringContainsString('href="/giving/ok/sooner"', $this->home());
    }

    public function test_with_no_open_campaign_the_giving_section_is_not_drawn(): void
    {
        $this->assertNull(HomeFront::campaign());
        $this->assertStringNotContainsString('id="hm-give"', $this->home());
    }

    // ══ the CSP, on the rendered page (rules of the destroyed CspTest methods) ══

    public function test_every_inline_script_and_style_carries_the_nonce_the_header_names(): void
    {
        $res = ChromeRender::page('/');
        $html = (string) $res->getBody();
        $csp = $res->getHeaderLine('Content-Security-Policy');

        preg_match_all('~<script\b(?![^>]*\bsrc=)[^>]*>~', $html, $scripts);
        $this->assertNotEmpty($scripts[0], 'the page rendered no inline scripts — check the fixture');
        foreach ($scripts[0] as $tag) $this->assertMatchesRegularExpression('~nonce="[^"]+"~', $tag, "an inline script with no nonce: $tag");
        preg_match_all('~<style\b[^>]*>~', $html, $styles);
        $this->assertNotEmpty($styles[0], 'the page rendered no <style> — check the fixture');
        foreach ($styles[0] as $tag) $this->assertMatchesRegularExpression('~nonce="[^"]+"~', $tag, "a <style> with no nonce: $tag");

        preg_match('~nonce="([^"]+)"~', $html, $n);
        if ($csp !== '') $this->assertStringContainsString("'nonce-" . $n[1] . "'", $csp);

        $this->assertDoesNotMatchRegularExpression('~<[a-z][^>]*\son[a-z]+\s*=~i', $html,
            'an inline event handler — use data-ag-do and a delegated listener');
    }
}
