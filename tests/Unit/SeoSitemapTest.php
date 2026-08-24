<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SitemapService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The sitemap must list real, fetchable content — and nothing else.
 *
 * ── WHAT WENT WRONG, AND WHY A TEST IS THE RIGHT GUARD ───────────────────────
 *
 * The old `/sitemap.xml` was a literal fifteen-path array in `routes.php`. Its
 * defects were all of the invisible kind: nobody notices a sitemap that omits every
 * nominee, nobody notices `lastmod` set to `date('Y-m-d')` on every row, and nobody
 * notices `/register` in it 301-ing to `/account/register` — the file still parses,
 * still returns 200, and Search Console still says "success".
 *
 * So the assertions here are about the things that go wrong SILENTLY:
 *
 *   • a merged nominee, whose page is a redirect, appearing anyway;
 *   • an unapproved profile, whose page is a 404, appearing anyway;
 *   • `lastmod` being invented for a page with no date behind it;
 *   • a section quietly returning an empty `<urlset>` instead of a 404;
 *   • an `&` in an image URL making the whole document unparseable.
 *
 * Each test states the bug it catches, and several were written by breaking the
 * service first to confirm they fail.
 */
final class SeoSitemapTest extends TestCase
{
    private const BASE = 'https://afg.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => 1, 'slug' => 'carol', 'title' => 'Carol Awards', 'is_active' => 1,
            'created_at' => '2026-01-04 09:00:00',
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting',
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 1, 'cycle_id' => 1, 'title' => 'Music', 'slug' => 'music',
        ]);
    }

    private function svc(): SitemapService
    {
        // No CacheService: each test wants the query it just seeded, not a cached
        // answer from the test before it.
        return new SitemapService(null);
    }

    private function nominee(int $id, string $name, string $status = 'approved', ?int $mergedInto = null): void
    {
        DB::table('gates_nominees')->insertOrIgnore(array_filter([
            'id' => $id, 'category_id' => 1, 'name' => $name, 'status' => $status,
            'merged_into' => $mergedInto, 'nominated_at' => '2026-02-11 12:00:00',
        ], static fn($v) => $v !== null));
    }

    private function profile(string $slug, string $name, string $status): void
    {
        DB::table('gates_profiles')->insert([
            'slug' => $slug, 'display_name' => $name, 'status' => $status,
            'email' => $slug . '@example.test',
            'updated_at' => '2026-03-02 08:00:00', 'registered_at' => '2026-03-01 08:00:00',
        ]);
    }

    // ── The pages that can rank are actually in it ───────────────────────────

    public function test_a_nominee_ballot_is_listed_at_its_canonical_url(): void
    {
        $this->nominee(101, 'Amara Okonkwo');

        $paths = array_column($this->svc()->urls('nominees'), 'path');

        // The id-led segment, not a bare slug: /vote/{programme}/{id}-{name} is the
        // only shape VoteController serves without redirecting.
        $this->assertContains('/vote/carol/101-amara-okonkwo', $paths);
    }

    public function test_the_index_names_a_file_per_non_empty_section(): void
    {
        $this->nominee(101, 'Amara Okonkwo');

        $xml = $this->svc()->index(self::BASE);

        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString(self::BASE . '/sitemap-nominees.xml', $xml);
        $this->assertStringContainsString(self::BASE . '/sitemap-core.xml', $xml);
        // Blog has no rows in this fixture. An empty section must not be advertised:
        // Search Console reports it as a sitemap containing zero URLs, which reads
        // as a broken feed rather than as an empty one.
        $this->assertStringNotContainsString('/sitemap-blog.xml', $xml);
    }

    // ── The rows that must NOT be in it ──────────────────────────────────────

    /**
     * A merged nominee's ballot 302s to its survivor. Listing it fills the report
     * with "page with redirect", which is a quality signal against the whole file.
     */
    public function test_a_merged_nominee_is_not_listed(): void
    {
        $this->nominee(101, 'Amara Okonkwo');
        $this->nominee(102, 'Amara O.', 'approved', 101);

        $paths = array_column($this->svc()->urls('nominees'), 'path');

        $this->assertContains('/vote/carol/101-amara-okonkwo', $paths);
        $this->assertNotContains('/vote/carol/102-amara-o', $paths);
    }

    /** A pending or rejected nominee has no public page at all. */
    public function test_a_nominee_awaiting_review_is_not_listed(): void
    {
        $this->nominee(103, 'Not Yet Approved', 'pending');
        $this->nominee(104, 'Turned Down', 'rejected');

        $paths = array_column($this->svc()->urls('nominees'), 'path');

        $this->assertSame([], $paths, 'only publicly viewable statuses belong in a sitemap');
    }

    /**
     * ProfileService::getBySlug() requires `approved`. Anything else is a 404, and
     * a sitemap of 404s is the fastest way to have the file discounted entirely.
     */
    public function test_only_approved_registry_profiles_are_listed(): void
    {
        $this->profile('live-one', 'Live One', 'approved');
        $this->profile('waiting',  'Waiting',  'pending');

        $paths = array_column($this->svc()->urls('registry'), 'path');

        $this->assertContains('/registry/live-one', $paths);
        $this->assertNotContains('/registry/waiting', $paths);
    }

    /**
     * `/register` 301s to `/account/register`, and the hand-written sitemap
     * advertised the redirect for as long as it existed.
     */
    public function test_the_core_section_points_at_destinations_not_redirects(): void
    {
        $paths = array_column($this->svc()->urls('core'), 'path');

        $this->assertContains('/account/register', $paths);
        $this->assertNotContains('/register', $paths);
    }

    // ── lastmod is true or absent ───────────────────────────────────────────

    /**
     * The bug: `date('Y-m-d')` on every row, every day. A `lastmod` that always says
     * "today" carries no information, and Google's documentation is explicit that it
     * ignores the value when it does not trust it — so the honest move on a page with
     * no date column is to omit the element.
     */
    public function test_no_lastmod_is_invented_for_pages_with_no_date_behind_them(): void
    {
        foreach ($this->svc()->urls('core') as $u) {
            $this->assertArrayNotHasKey('lastmod', $u, $u['path'] . ' has no date column to report');
        }

        $xml = (string) $this->svc()->section('core', self::BASE);
        $this->assertStringNotContainsString('<lastmod>', $xml);
    }

    public function test_lastmod_comes_from_the_row(): void
    {
        $this->nominee(101, 'Amara Okonkwo');

        $rows = $this->svc()->urls('nominees');

        $this->assertSame('2026-02-11', $rows[0]['lastmod'] ?? null);
    }

    // ── Shape and safety ────────────────────────────────────────────────────

    public function test_every_section_is_parseable_xml(): void
    {
        $this->nominee(101, 'Amara Okonkwo');
        $this->profile('live-one', 'Live One', 'approved');

        $prev = libxml_use_internal_errors(true);
        foreach (SitemapService::sectionKeys() as $key) {
            $xml = $this->svc()->section($key, self::BASE);
            if ($xml === null) continue;
            $this->assertInstanceOf(
                \SimpleXMLElement::class, simplexml_load_string($xml),
                "sitemap-{$key}.xml does not parse"
            );
        }
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($this->svc()->index(self::BASE)));
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }

    /**
     * An unescaped `&` — from a Cloudinary transform query, or a name — makes the
     * whole document a parse error, and the report says "could not read" with no
     * line number. So the escape has to survive a value that carries one.
     */
    public function test_an_ampersand_in_a_row_cannot_break_the_document(): void
    {
        $this->nominee(101, 'Sanwo & Sons');
        DB::table('gates_site_events')->insert([
            'slug' => 'gala&2026', 'title' => 'Gala "2026" & After',
            'status' => 'published', 'cover_image' => 'https://cdn.test/x.png?a=1&b=2',
            'event_date' => '2026-12-20', 'created_at' => '2026-04-01 10:00:00',
        ]);

        $prev = libxml_use_internal_errors(true);
        $xml  = (string) $this->svc()->section('events', self::BASE);
        $doc  = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $this->assertInstanceOf(\SimpleXMLElement::class, $doc, 'an & in a row broke the XML');
        $this->assertStringContainsString('&amp;', $xml, 'the & should be escaped, not dropped');
    }

    public function test_an_unknown_section_or_a_page_past_the_end_is_not_a_url(): void
    {
        $this->nominee(101, 'Amara Okonkwo');

        $this->assertNull($this->svc()->section('nominees', self::BASE, 2), 'page 2 of a 1-page section');
        $this->assertNull($this->svc()->section('admin', self::BASE), 'a section that does not exist');
        $this->assertNull($this->svc()->section('nominees', self::BASE, 0), 'page 0 is not a page');
    }

    /** Absolute URLs, always: a <loc> without a host is invalid per the protocol. */
    public function test_locs_are_absolute(): void
    {
        $this->nominee(101, 'Amara Okonkwo');

        $xml = (string) $this->svc()->section('nominees', self::BASE);

        preg_match_all('~<loc>(.*?)</loc>~', $xml, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $loc) {
            $this->assertStringStartsWith('https://', $loc);
        }
    }

    /**
     * The help corpus lives in a PHP file rather than a table, and it is the one
     * section whose `lastmod` can be exact — the mtime of the file that holds it.
     */
    public function test_help_answers_are_listed_with_the_corpus_file_date(): void
    {
        $rows  = $this->svc()->urls('help');
        $paths = array_column($rows, 'path');

        $this->assertContains('/help', $paths);
        $this->assertContains('/help/c/payments', $paths);
        $this->assertContains('/help/paid-but-no-votes', $paths);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) ($rows[0]['lastmod'] ?? ''));
    }

    /**
     * A deployment sitting between a `git pull` and a migration must still serve a
     * sitemap. One missing table is one missing section, not a 500 — a fetch error
     * makes Search Console stop trusting the file.
     */
    public function test_a_missing_table_costs_one_section_not_the_sitemap(): void
    {
        DB::statement('DROP TABLE gates_judges');

        $this->assertSame([], $this->svc()->urls('judges'));
        $this->assertStringContainsString('<sitemapindex', $this->svc()->index(self::BASE));
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE FUNDRAISING SURFACE, WHICH WAS IN NO SECTION AT ALL
    // ════════════════════════════════════════════════════════════════════════
    //
    // /donate, every organisation's own appeal page and every live campaign were listed
    // nowhere. Not a ranking nicety: this is the half of the platform whose whole job is to
    // be FOUND by somebody searching for a cause, and the page an organisation asks its own
    // supporters to share. An appeal no search engine has been told about reaches only the
    // people who already had the link.

    /** @return array{org:int, camp:int} a partner that can actually receive money */
    private function receivableOrg(string $slug = 'borehole-trust'): array
    {
        $orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => $slug, 'name' => 'Borehole Trust', 'legal_name' => 'Borehole Trust Ltd',
            'kind' => \AfricaGates\Services\PartnerOrg::KIND_PARTNER,
            'entity_type' => \AfricaGates\Services\PartnerOrg::ENTITY_BUSINESS,
            'cac_number' => 'RC5544',
            'status' => \AfricaGates\Services\PartnerOrg::STATUS_APPROVED,
            // The column listReceivable() requires. Without it the org cannot take money and
            // its page is not advertised — which is the behaviour, not an oversight.
            'subaccount_code' => 'ACCT_' . $slug,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $campId = (int) DB::table('gates_org_campaigns')->insertGetId([
            'org_id' => $orgId, 'slug' => 'clean-water', 'title' => 'Clean water for Ikorodu',
            'target_naira' => 2500000, 'shortfall_policy' => 'same_purpose',
            'status' => \AfricaGates\Services\OrgCampaign::STATUS_LIVE,
            'closes_on' => date('Y-m-d', strtotime('+45 days')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['org' => $orgId, 'camp' => $campId];
    }

    /** @return list<string> */
    private function paths(string $section): array
    {
        return array_map(
            static fn (array $u): string => (string) $u['path'],
            (new SitemapService())->urls($section)
        );
    }

    public function test_the_donate_hub_and_the_application_are_listed(): void
    {
        $paths = $this->paths('donate');

        $this->assertContains('/donate', $paths);
        // The one page aimed at a charity searching "how do we take donations online", and
        // it was reachable only from a panel at the foot of /donate.
        $this->assertContains('/gift/apply', $paths);
    }

    public function test_a_receivable_organisation_and_its_live_appeal_are_listed(): void
    {
        $this->receivableOrg();
        $paths = $this->paths('donate');

        $this->assertContains('/donate/borehole-trust', $paths);
        $this->assertContains('/donate/borehole-trust/clean-water', $paths);
    }

    /**
     * An organisation that cannot take money is not advertised.
     *
     * Its page 404s on purpose — somebody following a link to a suspended charity must be
     * told the appeal is closed, not quietly redirected into giving to a different
     * organisation. Advertising a 404 to a crawler is how a whole section loses credibility.
     */
    public function test_an_organisation_that_cannot_receive_money_is_not_advertised(): void
    {
        $ids = $this->receivableOrg('suspended-trust');
        DB::table('gates_partner_orgs')->where('id', $ids['org'])
          ->update(['subaccount_code' => null]);

        foreach ($this->paths('donate') as $p) {
            $this->assertStringNotContainsString('suspended-trust', $p,
                'the sitemap advertises an appeal page that answers 404');
        }
    }

    /** A closed appeal 404s too, so it is not listed either. */
    public function test_a_closed_appeal_is_not_advertised(): void
    {
        $ids = $this->receivableOrg('closed-trust');
        DB::table('gates_org_campaigns')->where('id', $ids['camp'])
          ->update(['status' => \AfricaGates\Services\OrgCampaign::STATUS_CLOSED]);

        $paths = $this->paths('donate');
        $this->assertContains('/donate/closed-trust', $paths, 'the organisation itself is still open');
        $this->assertNotContains('/donate/closed-trust/clean-water', $paths,
            'a closed appeal answers 404 and must not be advertised');
    }

    /**
     * The checkout return paths are never listed.
     *
     * They are steps in a transaction, they carry a payment reference, and a crawler
     * arriving at one has nothing to see and a receipt to mangle.
     */
    public function test_no_checkout_step_is_advertised(): void
    {
        $this->receivableOrg();

        foreach ($this->paths('donate') as $p) {
            foreach (['/callback', '/redirect', '/success'] as $step) {
                $this->assertStringNotContainsString($step, $p,
                    'a payment step is in the sitemap: ' . $p);
            }
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE PAGES THAT EXISTED AND WERE NEVER LISTED
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Each of these returns 200, is indexable, and was in no section.
     *
     * The shop and the feed are two of the platform's four public surfaces. /status is what
     * somebody searches when they think the site is down, and finding a real answer there
     * instead of nothing is the entire point of having built it. Cookies and refunds are the
     * two policies a person most often looks for BEFORE paying — and were the two a search
     * engine had never been told about.
     */
    public function test_the_public_surfaces_and_policies_are_all_listed(): void
    {
        $core = $this->paths('core');

        foreach (['/shop', '/pulse', '/status', '/cookies', '/refunds', '/vendor-terms'] as $p) {
            $this->assertContains($p, $core, $p . ' returns 200 and is in no sitemap section');
        }
    }

    /** An open call for stands is a public page with prices and a deadline on it. */
    public function test_a_published_call_for_stands_is_listed_with_its_event(): void
    {
        $ev = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Lagos Market Day', 'slug' => 'lagos-market-day',
            'event_date' => date('Y-m-d H:i:s', strtotime('+50 days')), 'status' => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        \AfricaGates\Services\StandType::save($ev, ['name' => 'Food pitch', 'category' => 'food',
            'price_naira' => '35000', 'quota' => '12', 'size_preset' => '3x3']);
        $c = \AfricaGates\Services\StandCall::save($ev,
            ['closes_at' => date('Y-m-d H:i:s', strtotime('+20 days'))]);
        \AfricaGates\Services\StandCall::open((int) $c['id'], 1);

        $paths = $this->paths('events');
        $this->assertContains('/events/lagos-market-day', $paths);
        $this->assertContains('/events/lagos-market-day/stands', $paths,
            'a trader searching for a market stall finds nothing');
    }

    /**
     * A DRAFT call is not a public fact and is not advertised.
     *
     * Its terms are still being written, and publishing half of them is how a quota gets
     * quoted before it is decided.
     */
    public function test_a_draft_call_for_stands_is_not_listed(): void
    {
        $ev = (int) DB::table('gates_site_events')->insertGetId([
            'title' => 'Draft Fair', 'slug' => 'draft-fair',
            'event_date' => date('Y-m-d H:i:s', strtotime('+50 days')), 'status' => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        \AfricaGates\Services\StandType::save($ev, ['name' => 'Pitch', 'category' => 'food',
            'price_naira' => '1000', 'quota' => '2']);
        \AfricaGates\Services\StandCall::save($ev,
            ['closes_at' => date('Y-m-d H:i:s', strtotime('+20 days'))]);

        $this->assertNotContains('/events/draft-fair/stands', $this->paths('events'));
    }
}
