<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * THE SEARCH HAS ONE LIST OF SOURCES, AND EVERYTHING READS IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DRIFT THIS WAS WRITTEN FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `ActivityFeedService::KINDS` named seven kinds. `collect()` ran nine sources. The two
 * extra — `award` (programmes and categories) and `page` (the site's own destinations)
 * — were added later with a comment saying they exist "so this searches the SITE, not
 * only its activity", and nobody added them to the whitelist.
 *
 * Because an interpreted `kinds` narrows which sources run, and a kind missing from the
 * whitelist is dropped before it can appear in `kinds`, those two sources could never be
 * asked for. Any query specific enough for the model to narrow switched off the two
 * sources that answer "choral" and "how does voting work". Nothing failed. The search
 * just stopped covering the site for the queries most likely to be interpreted.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND A TEST FOR EXACTLY THIS ALREADY EXISTED AND PASSED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `SearchInterpretationTest::test_every_item_kind_a_source_emits_is_in_the_whitelist`
 * was written to catch this direction. It seeded a nominee and a post, called
 * `search('')`, and asserted every returned item's kind was whitelisted.
 *
 * `search('')` is shorter than MIN_QUERY, so it returns `latest()` and never reaches
 * `collect()` at all. Neither offending source ran. The test asked a FIXTURE a question
 * it could only answer about the rows the fixture had, and the answer was yes.
 *
 * That is `SchemaIndexTest` excusing three 1064s, one more time. So this file asks the
 * DECLARATIONS, statically, in both directions — there is no fixture that can make it
 * vacuous — and the one behavioural test below drives a real query with a term seeded
 * into every source, so "declared and wired" and "actually returns rows" are separate
 * questions with separate answers.
 */
final class SearchSourcesTest extends TestCase
{
    private const SERVICE = '/src/Services/ActivityFeedService.php';

    /** The keys of the `$sources` map inside collect(), read from the source file. */
    private function wired(): array
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . self::SERVICE);

        $from = strpos($src, '$sources = [');
        $this->assertNotFalse($from, 'the source map has been renamed or removed');
        $to = strpos($src, '];', $from);
        $this->assertNotFalse($to);

        preg_match_all("~'([a-z]+)'\s*=>\s*fn~", substr($src, $from, $to - $from), $m);

        return $m[1];
    }

    public function test_every_declared_source_is_wired_to_a_query(): void
    {
        // A kind declared in SOURCES with nothing behind it is worse than missing: it is
        // on the whitelist, so the model may narrow to it, and narrowing to it returns
        // nothing at all. It also prints a noun on the search band for something that is
        // never searched.
        $missing = array_diff(array_keys(ActivityFeedService::SOURCES), $this->wired());

        $this->assertSame([], array_values($missing),
            "declared in ActivityFeedService::SOURCES but no source runs for it:\n  "
          . implode(', ', $missing)
          . "\n\nFiltering to one of these returns nothing, and the search band names it "
          . 'as something this box covers.');
    }

    public function test_every_wired_source_is_declared(): void
    {
        // The direction that actually shipped. A source that runs but is not declared
        // cannot be filtered to, so it is switched off by any query narrow enough to
        // interpret — and it is absent from the sentence the band prints.
        $undeclared = array_diff($this->wired(), array_keys(ActivityFeedService::SOURCES));

        $this->assertSame([], array_values($undeclared),
            "queried by collect() but not declared in ActivityFeedService::SOURCES:\n  "
          . implode(', ', $undeclared)
          . "\n\nThis is the shape that shipped: such a source is silently dropped the "
          . 'moment a query is narrow enough for the model to narrow it.');
    }

    public function test_every_source_declares_what_a_reader_and_the_model_each_need(): void
    {
        foreach (ActivityFeedService::SOURCES as $kind => $s) {
            $this->assertArrayHasKey('noun', $s, $kind);
            $this->assertArrayHasKey('hint', $s, $kind);
            $this->assertArrayHasKey('dated', $s, $kind);

            // `noun` may be null — that means "covered by the sentence's closing clause".
            if ($s['noun'] !== null) {
                $this->assertNotSame('', trim((string) $s['noun']), $kind);
            }
            // `hint` may not. It is what the model is told the kind means, and a kind with
            // no explanation is one the model will never select correctly.
            $this->assertNotSame('', trim((string) $s['hint']), $kind . ' has no hint');
            $this->assertIsBool($s['dated'], $kind);
        }
    }

    public function test_the_model_is_told_about_every_kind_it_may_return(): void
    {
        // The prompt used to explain four kinds by hand and offered no explanation for
        // the other three — nor, of course, for the two that were not on the whitelist.
        $hints = ActivityFeedService::hints();

        foreach (ActivityFeedService::kinds() as $kind) {
            $this->assertStringContainsString($kind . ' — ', $hints,
                "the interpretation prompt never tells the model what '{$kind}' means");
        }
    }

    public function test_a_timeless_source_is_declared_as_one(): void
    {
        // `dated => false` is what keeps a source out of the recency sort's bottom. These
        // items carry `at => ''`, and an empty string sorts BELOW every real timestamp,
        // so a timeless source wrongly marked dated disappears under the fold on any busy
        // week — visible only as "the search stopped finding the choral category".
        foreach (['award', 'org', 'page'] as $kind) {
            $this->assertFalse(ActivityFeedService::SOURCES[$kind]['dated'],
                "{$kind} items carry no timestamp, so they must be signposts");
        }
    }

    public function test_every_source_actually_returns_a_row_for_a_term_it_holds(): void
    {
        // The behavioural half. Declared and wired is not the same as answering, and the
        // static tests above cannot tell the difference — which is exactly how the
        // predecessor test passed while two sources were unreachable.
        $needle = 'Zamfaraxyl';
        $this->seedEverySource($needle);

        $kinds = [];
        foreach ($this->feed()->search($needle, 60)['items'] as $item) $kinds[$item['kind']] = true;

        // `page` is excluded because it CANNOT be seeded: it is a hand-written list of
        // Twig destinations inside the service, not a table — the one source with no
        // rows behind it. That is a kind with a reason, not a source that failed; it is
        // covered by its own test below, against a term the list actually holds.
        $expected = array_diff(array_keys(ActivityFeedService::SOURCES), ['page']);
        $missing  = array_diff($expected, array_keys($kinds));

        $this->assertSame([], array_values($missing),
            "these sources are declared and wired but returned nothing for a term seeded "
          . "into each of them:\n  " . implode(', ', $missing));
    }

    public function test_the_hand_written_page_list_still_answers(): void
    {
        // The site's own destinations are a list in the service, so nothing about the
        // database can prove they work. "how voting works" is a real keyword on the
        // Integrity Center row and is the kind of thing somebody types into a search box
        // rather than a nav.
        $kinds = [];
        foreach ($this->feed()->search('how voting works', 60)['items'] as $item) {
            $kinds[$item['kind']] = true;
        }

        $this->assertArrayHasKey('page', $kinds,
            'the search stopped reaching the site\'s own pages — "how does voting work" '
          . 'returns nothing again, which reads as "we have nothing on that"');
    }

    private function feed(): ActivityFeedService
    {
        return new ActivityFeedService();
    }

    /** One row carrying `$needle` in every source, so a single search must hit them all. */
    private function seedEverySource(string $needle): void
    {
        $now = Carbon::now()->toDateTimeString();

        // An ANNOUNCED cycle, so the `result` source may publish its winner.
        DB::table('gates_award_programmes')->insert([
            'id' => 91, 'slug' => 'p91', 'title' => $needle . ' Programme',
            'is_active' => 1, 'sort_order' => 91,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => 91, 'programme_id' => 91, 'year' => 2026, 'status' => 'results',
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => 91, 'cycle_id' => 91, 'slug' => 'c91',
            'title' => $needle . ' Category', 'sort_order' => 0,
        ]);
        DB::table('gates_nominees')->insert([
            ['id' => 911, 'category_id' => 91, 'name' => $needle . ' Winner',
             'status' => 'winner', 'vote_count' => 0, 'nominated_at' => $now],
            ['id' => 912, 'category_id' => 91, 'name' => $needle . ' Standing',
             'status' => 'approved', 'vote_count' => 0, 'nominated_at' => $now],
        ]);

        DB::table('gates_partner_orgs')->insert([
            'slug' => 'org91', 'name' => $needle . ' Foundation', 'status' => 'approved',
            'created_at' => $now,
        ]);
        DB::table('gates_profiles')->insert([
            'slug' => 'pr91', 'display_name' => $needle . ' Person', 'email' => 'p91@example.test',
            'status' => 'approved', 'registered_at' => $now,
        ]);
        DB::table('gates_cycle_transitions')->insert([
            'cycle_id' => 91, 'from_status' => 'judging', 'to_status' => 'results',
            'created_at' => $now,
        ]);
        DB::table('gates_posts')->insert([
            'slug' => 'po91', 'title' => $needle . ' Post', 'status' => 'published',
            'published_at' => $now, 'created_at' => $now,
        ]);
        DB::table('gates_site_events')->insert([
            'slug' => 'e91', 'title' => $needle . ' Event', 'status' => 'published',
            'event_date' => $now, 'created_at' => $now,
        ]);
        DB::table('gates_threads')->insert([
            'slug' => 't91', 'title' => $needle . ' Thread', 'status' => 'approved',
            'author_name' => 'A Member', 'author_email_hash' => str_repeat('a', 64), 'created_at' => $now,
        ]);
    }
}
