<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Support\Schema;

/**
 * schema.org output. A malformed block is dropped by Google in silence, so the failure
 * these assertions exist for is never visible on the site — only in the absence of a
 * rich result nobody is watching for.
 */
class SchemaTest extends TestCase
{
    private const SITE = 'https://afg.afrovanguard.org.ng';

    public function test_hostile_text_still_produces_valid_json(): void
    {
        // The real reason this is built in PHP rather than a template.
        $out = Schema::event([
            'slug'  => 'gala-2026',
            'title' => 'Gala "2026" — O’Brien & Sons <b>Ltd</b>',
            'venue' => "Eko Hotel,\nLagos",
        ], self::SITE);

        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);
        $this->assertSame($out, json_decode((string) $json, true), 'round-trip must be lossless');
        // Markup stripped and whitespace collapsed — schema values are plain text.
        $this->assertSame('Gala "2026" — O’Brien & Sons Ltd', $out['name']);
        $this->assertSame('Eko Hotel, Lagos', $out['location']['name']);
    }

    public function test_event_dates_carry_an_offset(): void
    {
        // A bare local datetime is read as the SEARCHER's timezone, so an 18:00 WAT event
        // advertises itself as 18:00 wherever the reader happens to be.
        $out = Schema::event(['slug' => 'x', 'title' => 'X', 'event_date' => '2026-12-05 18:00:00'], self::SITE);
        $this->assertArrayHasKey('startDate', $out);
        $this->assertMatchesRegularExpression('/\+\d{2}:\d{2}$|Z$/', $out['startDate'],
            'startDate must carry an offset');
    }

    public function test_only_real_priced_tiers_become_offers(): void
    {
        // Inventing a free offer for an event that has none is a rich result that lies.
        $out = Schema::event(['slug' => 'x', 'title' => 'X'], self::SITE, [
            ['name' => 'Free', 'price' => 0],
            ['name' => 'Table', 'price' => 380000, 'available' => false],
            ['name' => 'Single', 'price' => 25000],
        ]);

        $this->assertCount(2, $out['offers']);
        $this->assertSame('380000', $out['offers'][0]['price']);
        $this->assertSame('https://schema.org/SoldOut', $out['offers'][0]['availability']);
        $this->assertSame('https://schema.org/InStock', $out['offers'][1]['availability']);
        $this->assertSame('NGN', $out['offers'][0]['priceCurrency']);
    }

    public function test_an_event_with_no_priced_tier_has_no_offers_key(): void
    {
        $out = Schema::event(['slug' => 'x', 'title' => 'X'], self::SITE, [['name' => 'Free', 'price' => 0]]);
        $this->assertArrayNotHasKey('offers', $out);
    }

    public function test_item_list_is_ranked_and_capped(): void
    {
        $items = [];
        for ($i = 1; $i <= 60; $i++) $items[] = ['name' => "Person $i", 'url' => "/registry/p$i"];
        $out = Schema::itemList('Leaderboard', $items, self::SITE);

        // Capped: a 400-entry list in the document head helps nobody.
        $this->assertSame(25, $out['numberOfItems']);
        $this->assertSame(1, $out['itemListElement'][0]['position']);
        $this->assertSame(25, $out['itemListElement'][24]['position']);
        // Relative URLs are silently ignored by consumers, so they must be absolutised.
        $this->assertStringStartsWith(self::SITE, $out['itemListElement'][0]['url']);
    }

    public function test_item_list_skips_nameless_rows_without_gaps_in_position(): void
    {
        $out = Schema::itemList('L', [
            ['name' => 'A', 'url' => '/a'],
            ['name' => '', 'url' => '/blank'],
            ['name' => 'B', 'url' => '/b'],
        ], self::SITE);

        $this->assertSame(2, $out['numberOfItems']);
        $this->assertSame([1, 2], array_column($out['itemListElement'], 'position'));
    }

    public function test_person_omits_what_it_does_not_have(): void
    {
        // An empty description or affiliation key is worse than an absent one.
        $out = Schema::person(['display_name' => 'Ada Obi'], self::SITE, self::SITE . '/registry/ada');

        $this->assertSame('Ada Obi', $out['name']);
        foreach (['description', 'image', 'affiliation', 'nationality', 'award'] as $k) {
            $this->assertArrayNotHasKey($k, $out, "$k should be absent, not empty");
        }
    }

    public function test_person_uses_what_it_does_have(): void
    {
        $out = Schema::person([
            'name' => 'Amara Okonkwo', 'tagline' => 'Choral conductor',
            'organisation' => 'Lagos Chorale', 'country_code' => 'ng',
            'category_title' => 'Best Contemporary Choir',
        ], self::SITE, self::SITE . '/vote/carol/101-amara');

        $this->assertSame('Choral conductor', $out['description']);
        $this->assertSame('Lagos Chorale', $out['affiliation']['name']);
        $this->assertSame('NG', $out['nationality']['name']);
        $this->assertStringContainsString('Best Contemporary Choir', $out['award']);
    }

    public function test_a_legacy_zero_date_is_omitted_not_rendered_as_year_zero(): void
    {
        $out = Schema::event(['slug' => 'x', 'title' => 'X', 'event_date' => '0000-00-00 00:00:00'], self::SITE);
        $this->assertArrayNotHasKey('startDate', $out);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AN APPEAL PAGE
    // ════════════════════════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    private function org(array $over = []): array
    {
        return $over + [
            'slug' => 'borehole-trust', 'name' => 'Borehole Trust',
            'legal_name' => 'Borehole Trust Ltd/Gte', 'cac_number' => 'RC5544',
            'description' => 'Boreholes and hand pumps across Lagos State since 2014.',
        ];
    }

    /** @return array<string,mixed> */
    private function campaign(array $over = []): array
    {
        return $over + [
            'slug' => 'clean-water', 'title' => 'Clean water for Ikorodu',
            'summary' => 'Two boreholes for four schools.', 'target_naira' => 2500000,
        ];
    }

    public function test_an_appeal_names_the_organisation_and_where_to_give(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(), $this->campaign(), 'https://afg.test',
            'https://afg.test/donate/borehole-trust/clean-water');

        $this->assertJson((string) json_encode($j));
        [$ngo, $action] = $j['@graph'];

        $this->assertSame('NGO', $ngo['@type']);
        $this->assertSame('Borehole Trust', $ngo['name']);
        // The registration number: the one fact a stranger deciding whether to give can
        // check independently of us.
        $this->assertSame('RC5544', $ngo['identifier']);

        $this->assertSame('DonateAction', $action['@type']);
        $this->assertSame('Clean water for Ikorodu', $action['name']);
        $this->assertSame('https://afg.test/donate/borehole-trust/clean-water',
                          $action['target']['urlTemplate']);
    }

    /**
     * The organisation is described in its OWN words, not the campaign's.
     *
     * A charity running a borehole fund must not be described to a search engine as "two
     * boreholes for four schools" and nothing else — that is one appeal, not the body.
     */
    public function test_the_organisation_is_not_described_by_one_of_its_appeals(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(), $this->campaign(), 'https://afg.test', 'https://afg.test/x');

        [$ngo, $action] = $j['@graph'];
        $this->assertStringContainsString('since 2014', $ngo['description']);
        $this->assertSame('Two boreholes for four schools.', $action['description']);
    }

    /**
     * The TARGET is published; the running total is not.
     *
     * schema.org has no honest slot for an amount raised, and the nearest ones would put a
     * figure into a search result that is hours or days stale. A fundraising total that
     * drifts from the rows underneath it is the one number nobody forgives, and one cached
     * in somebody else's index is one we cannot correct.
     */
    public function test_the_target_is_published_and_the_running_total_never_is(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(), $this->campaign(), 'https://afg.test', 'https://afg.test/x');

        $action = $j['@graph'][1];
        $this->assertSame('2500000', $action['price']);
        $this->assertSame('NGN', $action['priceCurrency']);

        $blob = (string) json_encode($j);
        foreach (['raised', 'AggregateOffer', 'MonetaryAmount'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $blob,
                'a running total in a search index is a number we cannot correct');
        }
    }

    /** An appeal with no target states no price rather than an empty one. */
    public function test_an_appeal_with_no_target_states_no_price(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(), $this->campaign(['target_naira' => 0]),
            'https://afg.test', 'https://afg.test/x');

        $action = $j['@graph'][1];
        $this->assertArrayNotHasKey('price', $action);
        $this->assertArrayNotHasKey('priceCurrency', $action);
    }

    /** An organisation page with no campaign still describes what the button does. */
    public function test_an_organisation_page_with_no_campaign_still_has_an_action(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(), null, 'https://afg.test', 'https://afg.test/donate/borehole-trust');

        $action = $j['@graph'][1];
        $this->assertSame('Donate to Borehole Trust', $action['name']);
    }

    /** A nameless row produces nothing rather than an empty NGO node. */
    public function test_an_organisation_with_no_name_produces_no_markup(): void
    {
        $this->assertSame([], \AfricaGates\Support\Schema::appeal(
            $this->org(['name' => '']), null, 'https://afg.test', 'https://afg.test/x'));
    }

    /**
     * A partner-supplied title cannot break out of the JSON-LD script element.
     *
     * ── WHY THIS IS A SECURITY TEST AND NOT A TIDINESS ONE ──────────────────
     *
     * Campaign titles and organisation names are written by PARTNERS from their own
     * dashboard. The layout renders this block with JSON_UNESCAPED_SLASHES — chosen so URLs
     * stay readable in view-source — which means `\/` is NOT escaped, so a literal
     * `</script>` inside a JSON string closes the script element early and everything after
     * it lands in the document as markup.
     *
     * `text()` runs strip_tags, so the tag never survives to be encoded. event() and
     * person() have always done this; appeal() was written without it, and this test is why
     * that was caught.
     */
    public function test_a_partner_supplied_title_cannot_close_the_script_element(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(['name' => 'Trust</script><img src=x onerror=alert(1)>']),
            $this->campaign(['title' => 'Appeal</SCRIPT><svg onload=alert(1)>']),
            'https://afg.test', 'https://afg.test/x');

        // Encoded the way the layout encodes it — slashes UNescaped, which is the whole
        // reason this matters.
        $encoded = (string) json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsStringIgnoringCase('</script', $encoded,
            'a partner can close the JSON-LD block and inject markup into the head');
        $this->assertStringNotContainsString('onerror', $encoded);
        $this->assertStringNotContainsString('onload', $encoded);
        $this->assertIsArray(json_decode($encoded, true));
    }

    /** Hostile text in a charity name cannot break the block. */
    public function test_hostile_text_in_an_appeal_still_produces_valid_json(): void
    {
        $j = \AfricaGates\Support\Schema::appeal(
            $this->org(['name' => 'O\'Brien & Sons "Trust"', 'description' => "Line\nbreak <b>bold</b>"]),
            $this->campaign(['title' => 'Gala "2026" — <script>alert(1)</script>']),
            'https://afg.test', 'https://afg.test/x');

        $encoded = json_encode($j);
        $this->assertIsString($encoded);
        $this->assertIsArray(json_decode((string) $encoded, true));
        $this->assertStringNotContainsString('<script>', (string) $encoded);
        $this->assertStringNotContainsString("\n", (string) $j['@graph'][0]['description']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE SAME GUARD OVER EVERY BUILDER, INCLUDING ONES NOT WRITTEN YET
    // ════════════════════════════════════════════════════════════════════════

    /**
     * No builder may emit a value that closes the JSON-LD script element.
     *
     * ── WHY THIS IS BLANKET RATHER THAN PER-METHOD ──────────────────────────
     *
     * layout/gates.twig renders this block with JSON_UNESCAPED_SLASHES, chosen so URLs stay
     * readable in view-source. That single flag is what turns a `</script>` inside any
     * string into a real tag close — with default flags PHP writes `<\/script>`, which is
     * inert, and every hand-written JSON-LD block elsewhere in the templates relies on
     * exactly that.
     *
     * So `Support\Schema` is the ONLY path by which user-supplied text reaches a JSON-LD
     * block with slashes unescaped, and every builder in it has to strip tags. Three did.
     * `appeal()` was written without it and this suite caught it.
     *
     * Driving every public builder from one list means the next one added is covered on the
     * day it is written, rather than the day somebody remembers.
     */
    public function test_no_builder_lets_hostile_text_escape_the_script_element(): void
    {
        $bad = 'X</script><svg onload=alert(1)>';

        $built = [
            'event' => \AfricaGates\Support\Schema::event(
                ['title' => $bad, 'slug' => 'e', 'venue' => $bad, 'address' => $bad,
                 'summary' => $bad, 'event_date' => '2026-05-01 18:00:00'],
                'https://afg.test',
                [['name' => $bad, 'price' => 5000, 'available' => true]]
            ),
            'person' => \AfricaGates\Support\Schema::person(
                ['name' => $bad, 'tagline' => $bad, 'organisation' => $bad,
                 'country_code' => 'NG', 'category_title' => $bad],
                'https://afg.test', 'https://afg.test/p'
            ),
            'itemList' => \AfricaGates\Support\Schema::itemList(
                $bad, [['name' => $bad, 'url' => '/x']], 'https://afg.test'
            ),
            'appeal' => \AfricaGates\Support\Schema::appeal(
                ['slug' => 'o', 'name' => $bad, 'legal_name' => $bad,
                 'cac_number' => $bad, 'description' => $bad],
                ['slug' => 'c', 'title' => $bad, 'summary' => $bad, 'target_naira' => 100],
                'https://afg.test', 'https://afg.test/x'
            ),
        ];

        // Every public builder must be exercised — a method added and not listed here is a
        // method this guard silently does not cover.
        $public = array_values(array_filter(
            get_class_methods(\AfricaGates\Support\Schema::class),
            static fn (string $m): bool => !str_starts_with($m, '__')
        ));
        sort($public);
        $covered = array_keys($built);
        sort($covered);
        $this->assertSame($public, $covered,
            'a Schema builder is not covered by the script-element guard');

        foreach ($built as $name => $j) {
            // Encoded exactly as layout/gates.twig encodes it.
            $encoded = (string) json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsStringIgnoringCase('</script', $encoded,
                "Schema::{$name}() emits text that closes the JSON-LD block");
            $this->assertStringNotContainsString('onload', $encoded,
                "Schema::{$name}() carries an event handler into the head");
            $this->assertIsArray(json_decode($encoded, true),
                "Schema::{$name}() produced unparseable JSON");
        }
    }
}
