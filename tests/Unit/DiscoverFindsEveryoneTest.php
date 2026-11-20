<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "Profiles and profile pages are not searchable. The discover page is faulty." (owner,
 * 10 Nov 2026).
 *
 * Two faults, measured against the real page:
 *  · The People tab is "Most nominated this month", and a SEARCH kept the month — so a
 *    nominee entered in October could not be found by name in November, and on the first
 *    of each month the tab emptied, while the footer promised it searched "everyone
 *    standing in a category".
 *  · No tab searched the registry. A registered profile was findable only as a "Joined"
 *    update on the Live tab; People listed nominees and Organisations listed award hosts.
 */
final class DiscoverFindsEveryoneTest extends TestCase
{
    private function main(string $uri): string
    {
        $res = ChromeRender::page($uri);
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $a = (int) strpos($html, '<main');
        return substr($html, $a, (int) strpos($html, '</main>') - $a);
    }

    private function section(string $main, string $key): string
    {
        preg_match('~<section class="dv-sec dv-only dv-only--' . $key . '"(.*?)</section>~s', $main, $m);
        $this->assertNotEmpty($m, "the $key section is on the page");
        return $m[1];
    }

    private function profile(string $name, string $slug, string $type, string $status = 'approved'): void
    {
        DB::table('gates_profiles')->insert(['display_name' => $name, 'slug' => $slug, 'profile_type' => $type,
            'category' => 'Music', 'location_city' => 'Ibadan', 'country_code' => 'NG', 'email' => $slug . '@mail.example.org',
            'status' => $status, 'registered_at' => Carbon::now()->subDays(40)->toDateTimeString()]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_award_programmes')->insert(['id' => 212, 'slug' => 'dv-find', 'title' => 'Harmattan Awards', 'is_active' => 1, 'sort_order' => 1]);
        DB::table('gates_award_cycles')->insert(['id' => 2212, 'programme_id' => 212, 'year' => 2026, 'status' => 'voting']);
        DB::table('gates_award_categories')->insert(['id' => 22112, 'cycle_id' => 2212, 'slug' => 'poet', 'title' => 'Poet of the Year']);
        // Entered LAST month: the month list does not show them, a search must.
        DB::table('gates_nominees')->insert(['category_id' => 22112, 'name' => 'Adaeze Okonkwo', 'status' => 'approved', 'country_code' => 'NG',
            'nominated_at' => Carbon::now()->startOfMonth()->subDays(3)->toDateTimeString()]);
        $this->profile('Tolu Bakare', 'tolu-bakare', 'individual');
        $this->profile('Ibadan Arts Trust', 'ibadan-arts-trust', 'organisation');
        $this->profile('Not Yet Approved', 'not-yet-approved', 'individual', 'pending');
    }

    public function test_a_search_finds_a_nominee_entered_before_this_month(): void
    {
        $people = $this->section($this->main('/discover?tab=people&q=Adaeze'), 'people');
        $this->assertStringContainsString('Adaeze Okonkwo', $people);
        $this->assertStringNotContainsString('Most nominated this month', $people, 'a search is not the month list');
        $this->assertStringNotContainsString('Adaeze', $this->section($this->main('/discover?tab=people'), 'people'),
            'browsing, the month list is still the month');
    }

    public function test_registered_profiles_are_found_on_people_and_organisations(): void
    {
        $main = $this->main('/discover?q=Tolu');
        $this->assertStringContainsString('href="/registry/tolu-bakare"', $this->section($main, 'people'));

        $orgs = $this->section($this->main('/discover?tab=orgs&q=Arts%20Trust'), 'orgs');
        $this->assertStringContainsString('href="/registry/ibadan-arts-trust"', $orgs);
        $this->assertStringNotContainsString('tolu-bakare', $orgs, 'a person is not an organisation');

        // Browsing, each tab lists the registry under its own list.
        $this->assertStringContainsString('href="/registry/tolu-bakare"', $this->section($this->main('/discover?tab=people'), 'people'));
        $this->assertStringContainsString('href="/registry/ibadan-arts-trust"', $this->section($this->main('/discover?tab=orgs'), 'orgs'));
    }

    public function test_every_word_is_matched_somewhere_and_the_best_name_match_comes_first(): void
    {
        // Name and city: the phrase appears nowhere, both words do.
        $this->assertStringContainsString('href="/registry/tolu-bakare"', $this->section($this->main('/discover?tab=people&q=Tolu%20Ibadan'), 'people'));
        $this->assertStringNotContainsString('tolu-bakare', $this->section($this->main('/discover?tab=people&q=Tolu%20Kano'), 'people'),
            'every word must match; one missing word is no match');

        $this->profile('Ada', 'ada', 'individual');
        $this->profile('Adaeze Nnaji', 'adaeze-nnaji', 'individual');
        $people = $this->section($this->main('/discover?tab=people&q=Ada'), 'people');
        $this->assertLessThan(strpos($people, 'href="/registry/adaeze-nnaji"'), strpos($people, 'href="/registry/ada"'), 'the exact name first');
    }

    public function test_each_tab_is_its_own_page_to_a_search_engine_and_a_search_is_not_indexed(): void
    {
        $html = (string) ChromeRender::page('/discover?tab=people')->getBody();
        $this->assertStringContainsString('<title>People — Discover — Africa GATES</title>', $html);
        $this->assertMatchesRegularExpression('~<link rel="canonical" href="[^"]+/discover\?tab=people">~', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);

        $q = (string) ChromeRender::page('/discover?tab=people&q=Tolu')->getBody();
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $q);
        $this->assertMatchesRegularExpression('~<link rel="canonical" href="[^"]+/discover\?tab=people">~', $q, 'a search points at its tab');
    }

    public function test_a_profile_page_is_a_profile_page_to_a_search_engine(): void
    {
        $html = (string) ChromeRender::page('/registry/ibadan-arts-trust')->getBody();
        $this->assertMatchesRegularExpression('~<link rel="canonical" href="[^"]+/registry/ibadan-arts-trust">~', $html);
        $this->assertStringContainsString('<meta property="og:type" content="profile">', $html);
        $this->assertStringContainsString('"@type":"ProfilePage"', $html);
        $this->assertStringContainsString('"mainEntity":{"@type":"Organization"', $html, 'an organisation is not a Person');
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    public function test_a_profile_not_approved_is_never_listed(): void
    {
        $this->assertStringNotContainsString('not-yet-approved', $this->main('/discover?q=Approved'));
        $this->assertStringNotContainsString('not-yet-approved', $this->main('/discover?tab=people'));
    }
}
