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
}
