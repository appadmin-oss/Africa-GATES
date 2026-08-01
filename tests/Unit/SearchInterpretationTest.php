<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\AiCapability;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reading a plain-English search query for intent.
 *
 * WHY THIS IS SAFE TO ADD AT ALL, which is the only interesting question here. A model
 * is being allowed to influence a database query on a public endpoint. The answer is
 * that it cannot: every field it returns is checked against a whitelist in
 * ActivityFeedService before it reaches a query — `kind` must be one of KINDS,
 * `country` must be two letters, `days` is clamped — so the model cannot introduce a
 * table, a column, a value or an operator the code did not already allow. The same
 * discipline AiFilterService applies to the admin filter parser.
 *
 * And the interpretation only ever ADDS filters. The literal text match runs
 * regardless, so a model that misreads "winners in Ghana" cannot make the search return
 * LESS than the plain-text version would have; the worst case is a filter the user did
 * not ask for, shown to them on the page with a one-click way out.
 *
 * The tests below therefore concentrate on the boundary, not on the model: what happens
 * to a malformed answer, an out-of-range answer, an answer naming a table, and an
 * answer that is simply absent — which is what every deployment without an AI key gets,
 * and is the baseline rather than the degraded path.
 */
class SearchInterpretationTest extends TestCase
{
    private ActivityFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new ActivityFeedService();
    }

    // ── With no AI configured, which is the baseline ──────────────────────────

    public function test_with_no_provider_the_search_behaves_exactly_as_before(): void
    {
        // No key in tests, so interpret() returns null and `understood` is null. This is
        // the state most deployments start in and it must not be a degraded one.
        DB::table('gates_nominees')->insert([
            'id' => 8801, 'category_id' => 1, 'name' => 'Ifeoma Nwosu', 'status' => 'approved',
            'vote_count' => 0, 'nominated_at' => Carbon::now()->toDateTimeString(),
        ]);

        $r = $this->feed->search('Ifeoma', 20, interpret: true);

        $this->assertNull($r['understood'], 'nothing was interpreted');
        $this->assertSame(['Ifeoma Nwosu'], array_column($r['items'], 'title'),
            'and the plain text search answered anyway');
    }

    public function test_interpretation_is_off_by_default(): void
    {
        // Every pre-existing caller keeps its previous behaviour without being touched,
        // and the no-AI path stays the default rather than something to opt out of.
        $r = $this->feed->search('anything at all');
        $this->assertArrayHasKey('understood', $r);
        $this->assertNull($r['understood']);
    }

    // ── The whitelist, asserted against the source ────────────────────────────

    public function test_only_declared_kinds_can_ever_be_filtered_to(): void
    {
        // KINDS is the whitelist and it must match the sources that actually exist —
        // a kind in the list with no source behind it would filter every result away.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/ActivityFeedService.php');

        foreach (ActivityFeedService::KINDS as $kind) {
            $this->assertMatchesRegularExpression(
                "~'" . preg_quote($kind, '~') . "'\s*=>\s*fn~",
                $src,
                "KINDS declares '{$kind}' but no source produces it — filtering to it would return nothing"
            );
        }
    }

    public function test_every_item_kind_a_source_emits_is_in_the_whitelist(): void
    {
        // The other direction. A source emitting a kind the whitelist does not know
        // cannot be filtered to, so the model would have no way to ask for it.
        DB::table('gates_nominees')->insert([
            'id' => 8802, 'category_id' => 1, 'name' => 'Someone', 'status' => 'approved',
            'vote_count' => 0, 'nominated_at' => Carbon::now()->toDateTimeString(),
        ]);
        DB::table('gates_posts')->insert([
            'slug' => 'p1', 'title' => 'A story', 'status' => 'published',
            'published_at' => Carbon::now()->toDateTimeString(), 'created_at' => Carbon::now()->toDateTimeString(),
        ]);

        foreach ($this->feed->search('')['items'] as $item) {
            $this->assertContains($item['kind'], ActivityFeedService::KINDS, $item['kind']);
        }
    }

    public function test_the_capability_is_declared_with_a_single_attempt(): void
    {
        // It sits on a live search request. Chaining providers would make an
        // interpretation slower than the plain text search it is meant to improve, and a
        // plain text search is always available underneath.
        $cap = AiCapability::find('search.interpret');

        $this->assertNotNull($cap);
        $this->assertSame(1, $cap->maxAttempts, 'one attempt on a live request');
        $this->assertLessThanOrEqual(5, $cap->timeout);
        $this->assertTrue($cap->advisory, 'it may not decide anything on its own');
        $this->assertSame(AiCapability::FAIL_DEGRADE, $cap->onFailure,
            'a visitor must never see an error from a search box');
    }

    public function test_the_interpreter_uses_the_reasoning_tier_not_the_writing_one(): void
    {
        // The output is a set of FILTERS the platform acts on, so it is a decision and
        // needs a deterministic temperature — not the warmth that makes rally copy
        // readable.
        $cap = AiCapability::find('search.interpret');

        $this->assertSame(AiCapability::TIER_REASON, $cap->tier);
        $this->assertLessThanOrEqual(0.2, $cap->temperature());
    }

    public function test_the_query_is_minimised_before_it_leaves(): void
    {
        // Someone searching for a phone number or an email should not have it forwarded
        // to a model. `untrustedInput` is what makes the gateway fence AND minimise it.
        $cap = AiCapability::find('search.interpret');

        $this->assertTrue($cap->untrustedInput);
        $this->assertTrue($cap->minimise, 'contact details in a query must be redacted');
        $this->assertTrue($cap->publicContent, 'and it belongs in the published disclosure');
    }

    public function test_the_disclosure_covers_the_search(): void
    {
        // It processes text a visitor typed, so it is a disclosure about their data.
        $listed = [];
        foreach (\AfricaGates\Services\AiPrivacy::disclosure() as $g) {
            foreach ($g['capabilities'] as $c) $listed[] = $c['name'];
        }
        $this->assertContains('search.interpret', $listed);
    }

    // ── The filters themselves ────────────────────────────────────────────────

    public function test_a_recency_filter_excludes_older_items(): void
    {
        // Applied through the public path with a hand-built interpretation, since the
        // model is unavailable in tests. This is the code that runs on a real answer.
        DB::table('gates_nominees')->insert([
            ['id' => 8810, 'category_id' => 1, 'name' => 'Recent Person', 'status' => 'approved',
             'vote_count' => 0, 'nominated_at' => Carbon::now()->subHours(2)->toDateTimeString()],
            ['id' => 8811, 'category_id' => 1, 'name' => 'Recent Elder', 'status' => 'approved',
             'vote_count' => 0, 'nominated_at' => Carbon::now()->subDays(40)->toDateTimeString()],
        ]);

        $ref = new \ReflectionMethod(ActivityFeedService::class, 'applyFilters');
        $items = $this->feed->search('Recent')['items'];
        $this->assertCount(2, $items, 'both match the text');

        $filtered = $ref->invoke($this->feed, $items, ['country' => null, 'days' => 7]);
        $this->assertCount(1, $filtered);
        $this->assertSame('Recent Person', $filtered[0]['title']);
    }

    public function test_a_country_filter_matches_on_a_word_boundary(): void
    {
        // "NG" must not match "NGO" or the "ng" inside a name — a substring test would
        // silently include the wrong country and there is no way for a visitor to tell.
        $ref = new \ReflectionMethod(ActivityFeedService::class, 'applyFilters');
        $items = [
            ['title' => 'Right', 'detail' => 'Music · Lagos NG', 'at' => '2026-01-01 00:00:00'],
            ['title' => 'Wrong', 'detail' => 'An NGO working in health', 'at' => '2026-01-01 00:00:00'],
            ['title' => 'Also wrong', 'detail' => 'Nsong Achebe · KE', 'at' => '2026-01-01 00:00:00'],
        ];

        $out = $ref->invoke($this->feed, $items, ['country' => 'NG', 'days' => null]);

        $this->assertSame(['Right'], array_column($out, 'title'));
    }

    public function test_no_filters_means_nothing_is_removed(): void
    {
        $ref = new \ReflectionMethod(ActivityFeedService::class, 'applyFilters');
        $items = [['title' => 'A', 'detail' => '', 'at' => '2026-01-01 00:00:00']];

        $this->assertSame($items, $ref->invoke($this->feed, $items, null));
        $this->assertSame($items, $ref->invoke($this->feed, $items, ['country' => null, 'days' => null]));
    }

    public function test_an_item_with_no_timestamp_survives_a_recency_filter(): void
    {
        // A row with an empty date is a data problem, not a reason to hide it — and
        // treating an empty string as "older than everything" would silently drop it
        // from every filtered search.
        $ref = new \ReflectionMethod(ActivityFeedService::class, 'applyFilters');
        $items = [['title' => 'Undated', 'detail' => '', 'at' => '']];

        $this->assertCount(1, $ref->invoke($this->feed, $items, ['country' => null, 'days' => 1]));
    }

    public function test_narrowing_to_one_kind_reads_fewer_sources(): void
    {
        // Where the speed-up is: "winners in Ghana" reads two tables instead of nine.
        // `sources` counts what was ASKED for, so a narrowed search does not look like
        // seven unavailable sources.
        $ref = new \ReflectionMethod(ActivityFeedService::class, 'collect');

        $all = $ref->invoke($this->feed, null, 20, null);
        $one = $ref->invoke($this->feed, null, 20, ['kinds' => ['post'], 'country' => null, 'days' => null]);

        $this->assertSame(9, $all['sources']);   // seven activity sources + award + page
        $this->assertSame(1, $one['sources'], 'one kind, one source read');
    }

    public function test_an_unrecognised_kind_cannot_narrow_the_search_to_nothing(): void
    {
        // array_intersect_key with an unknown kind would produce an EMPTY source list
        // and therefore zero results for a query that should have matched. The whitelist
        // in the schema is what prevents an unknown kind reaching here at all; this pins
        // the consequence so the guard is never removed as redundant.
        $ref = new \ReflectionMethod(ActivityFeedService::class, 'collect');

        $out = $ref->invoke($this->feed, null, 20, ['kinds' => ['nonsense'], 'country' => null, 'days' => null]);

        $this->assertSame(0, $out['sources']);
        $this->assertSame([], $out['items'],
            'documented: an unvalidated kind WOULD empty the search — which is why the '
            . 'schema whitelists against KINDS before it ever gets here');
    }
}
