<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\HallOfFame;
use AfricaGates\Services\JudgeRubric;
use AfricaGates\Support\Accent;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\Support\AppTwig;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The hall: everybody this platform has crowned, as people rather than as rows.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THING THAT WOULD HAVE GONE WRONG, AND IS ASSERTED FIRST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The obvious implementation of a hall of fame is `WHERE gates_nominees.status = 'winner'`,
 * and it would print an index nobody was ever given. A published result is the one that was
 * ANNOUNCED: `PublicResults::category()` lays a cycle's SEALED standing back over the live
 * computation, and a real released nominee has already moved 693 → 885 across a week of
 * scoring changes with no record edited and the hash chain intact.
 *
 * So the test that matters here moves the rules between the seal and the read — the only
 * way to tell a sealed figure from a recomputed one — and requires the hall to keep showing
 * the announcement. A hall built on a second query passes every other test in this file.
 */
final class HallOfFameTest extends TestCase
{
    private int $programmeId;
    private int $cycleId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slug = 'hof-' . bin2hex(random_bytes(3));
        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $this->slug, 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->cycleId = $this->cycle(2026);
    }

    private function cycle(int $year): int
    {
        return (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => $year, 'status' => 'results',
            'edition_label' => $year . ' edition',
            'results_date' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
    }

    private function nominee(int $categoryId, string $name, int $votes, string $photo = ''): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name, 'status' => 'approved',
            'organic_vote_count' => $votes, 'vote_count' => $votes,
            'photo_path' => $photo !== '' ? $photo : null,
        ]);
    }

    /**
     * A complete panel. Per-CRITERION rows through the programme's real rubric — there is
     * no `gates_judge_scores` table and a fixture that invents one scores nobody — and
     * WHOLE marks, because `score` is a TINYINT and a fractional one is a tenth apart on
     * SQLite and identical on the production database.
     */
    private function panel(int $categoryId, int $nominee, int $mark): void
    {
        static $n = 0;
        for ($k = 0; $k < 2; $k++) {
            $j = (int) DB::table('gates_judges')->insertGetId([
                'name' => 'Judge ' . (++$n), 'is_active' => 1,
                'email' => 'hof-j' . $n . '-' . bin2hex(random_bytes(3)) . '@example.test',
                'programme_ids' => json_encode([$this->programmeId]),
            ]);
            foreach (JudgeRubric::effective($this->programmeId) as $c) {
                if ((int) $c->is_active !== 1) continue;
                DB::table('gates_judge_criteria_scores')->insert([
                    'judge_id' => $j, 'nominee_id' => $nominee, 'category_id' => $categoryId,
                    'criterion_id' => (int) $c->id, 'score' => $mark,
                    'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00',
                ]);
            }
        }
    }

    /** A decided award. Returns the winner's nominee id. */
    private function award(string $title, string $winner, int $votes,
                          int $sort = 1, string $photo = '', ?int $cycleId = null): int
    {
        $c = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $cycleId ?? $this->cycleId, 'slug' => 'c-' . bin2hex(random_bytes(4)),
            'title' => $title, 'sort_order' => $sort,
        ]);
        $a = $this->nominee($c, $winner, $votes, $photo);
        $b = $this->nominee($c, $winner . ' (runner-up)', (int) ($votes * 0.6));
        $this->panel($c, $a, 9);
        $this->panel($c, $b, 7);

        return $a;
    }

    // ══ what the hall is ═════════════════════════════════════════════════════

    public function test_it_names_the_winner_of_every_announced_award(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1, 'nominees/a.jpg');
        $this->award('Community Impact', 'Amina Bello', 900, 2);

        $hall = HallOfFame::build();
        $names = array_column($hall['people'], 'name');

        $this->assertContains('Oluwagbemiga Dorcas', $names);
        $this->assertContains('Amina Bello', $names);
        $this->assertSame(2, $hall['awards']);

        // A runner-up is not in a hall of fame, and the ranking that decides which is which
        // is the sealed one — so this also proves the hall reads the DRAWN award and not
        // every nominee attached to the category.
        $this->assertNotContains('Oluwagbemiga Dorcas (runner-up)', $names);
    }

    public function test_the_face_travels_with_the_person(): void
    {
        // An awards platform that shows its winners as text is the fault the edition page
        // was built against; a hall of fame doing it would be the same fault on the page
        // where it matters most.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1, 'nominees/a.jpg');

        $p = HallOfFame::build()['people'][0];
        $this->assertSame('nominees/a.jpg', $p['photo']);
    }

    public function test_somebody_who_has_won_twice_is_one_person_and_says_so(): void
    {
        // The single fact a hall knows that a ledger organised by edition cannot: who is
        // in here more than once.
        // TWO NOMINEE ROWS, ONE PROFILE — which is how this platform actually models the
        // same human being across editions. A nominee row belongs to one category, so a
        // fixture that moves a row between editions is testing a shape no cycle produces.
        // `email` is NOT NULL UNIQUE with no default — checked against the schema rather
        // than guessed from a neighbouring fixture, which is the only way this ever passes.
        $profile = (int) DB::table('gates_profiles')->insertGetId([
            'slug' => 'dorcas-' . bin2hex(random_bytes(3)),
            'display_name' => 'Oluwagbemiga Dorcas',
            'email' => 'dorcas-' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        $prev = $this->cycle(2025);
        $now  = $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);
        $then = $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 900, 1, '', $prev);

        DB::table('gates_nominees')->whereIn('id', [$now, $then])
            ->update(['profile_id' => $profile]);

        $hall = HallOfFame::build();
        $dorcas = null;
        foreach ($hall['people'] as $p) if ($p['name'] === 'Oluwagbemiga Dorcas') $dorcas = $p;

        $this->assertNotNull($dorcas, 'the repeat winner is not in the hall at all');
        $this->assertSame(1, $hall['repeat']);
        $this->assertGreaterThan(1, $dorcas['count']);
        // Most recent first, so the card links to the award a reader is likeliest to mean.
        $this->assertSame(2026, (int) $dorcas['wins'][0]['year']);
    }

    public function test_an_unannounced_edition_puts_nobody_in_the_hall(): void
    {
        // Serving a decided-but-unannounced result publicly IS announcing it, and a hall is
        // as public as a result page. The rule this platform breaks hardest when it breaks it.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);
        DB::table('gates_award_cycles')->where('id', $this->cycleId)->update(['status' => 'judging']);

        $this->assertSame([], HallOfFame::build()['people']);
    }

    public function test_a_withheld_award_names_nobody_and_is_counted_instead(): void
    {
        // No nominee met the quorum, so there is no winner to name. A card with an empty
        // face and no name reads as a person nobody knows.
        $c = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'c-' . bin2hex(random_bytes(4)),
            'title' => 'Unjudged', 'sort_order' => 9,
        ]);
        $this->nominee($c, 'Nobody Judged', 500);
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);

        $hall = HallOfFame::build();
        $this->assertSame(['Oluwagbemiga Dorcas'], array_column($hall['people'], 'name'));
        $this->assertGreaterThan(0, $hall['held'],
            'a withheld award vanished — a hall that omits one claims a tidier history '
            . 'than the one that happened');
    }

    public function test_the_counts_come_from_the_hall_and_not_from_a_template(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);
        $one = HallOfFame::build();

        $this->award('Community Impact', 'Amina Bello', 900, 2);
        $two = HallOfFame::build();

        $this->assertSame($one['awards'] + 1, $two['awards']);
        $this->assertSame(count($one['people']) + 1, count($two['people']));
        $this->assertSame(1, $two['programmes']);
    }

    // ══ the one that a second query would fail ═══════════════════════════════

    public function test_the_hall_shows_the_index_that_was_announced_and_not_todays(): void
    {
        // Move the rules between the seal and the read. This is the only way to tell a
        // sealed figure from a recomputed one, and it is what `ReleasedStandingTest` does
        // for the result page itself.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);

        $before = (int) HallOfFame::build()['people'][0]['wins'][0]['cpi'];
        $this->assertGreaterThan(0, $before, 'nothing was scored, so nothing is being proved');

        $sealed = (new \AfricaGates\Services\SnapshotService())->captureRelease($this->cycleId);
        $this->assertGreaterThan(0, $sealed, 'nothing was sealed, so nothing is being proved');

        // Through RuleEngine, which is where a ruleset actually lives — the judge half is
        // `550 × avg/10` by default and the curved form pays 8.0 as 256 of 550 rather than
        // 440, so this moves any real index a long way.
        (new \AfricaGates\Services\RuleEngine())->set('global', null, [
            'community_basis' => \AfricaGates\Services\CpiService::BASIS_RELATIVE,
            'community_scope' => \AfricaGates\Services\CpiService::SCOPE_CATEGORY,
            'judge_scale'     => \AfricaGates\Services\CpiService::SCALE_CURVED,
        ]);

        $after = (int) HallOfFame::build()['people'][0]['wins'][0]['cpi'];

        $this->assertSame($before, $after,
            'the hall re-scored an announced award under rules nobody was given — it is '
            . 'reading the nominee table rather than the sealed standing');
    }

    // ══ the page ═════════════════════════════════════════════════════════════

    private function render(array $hall): string
    {
        $t = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'strict_variables' => true, 'autoescape' => 'html',
        ]);
        AppTwig::equip($t);

        // Rendered against the real layout would drag in the whole site; the block is
        // compiled on its own, which is what a render test of one screen is for.
        return $t->load('pages/results/hall.twig')
                 ->renderBlock('content', ['hall' => $hall, '_site_url' => 'https://example.test']);
    }

    public function test_the_page_draws_the_people_and_marks_a_repeat_winner(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1, 'nominees/a.jpg');
        $hall = HallOfFame::build();
        $hall['people'][0]['count'] = 2;          // as a repeat winner would arrive
        $hall['repeat'] = 1;

        $html = $this->render($hall);

        $this->assertStringContainsString('Oluwagbemiga Dorcas', $html);
        $this->assertStringContainsString('alt="Oluwagbemiga Dorcas"', $html,
            'the portrait has no accessible name');

        // Never colour alone. The ring is an accelerator on top of a worded fact, because
        // roughly one man in twelve cannot separate two of these hues and a screen reader
        // has nothing to announce for an outline.
        $this->assertStringContainsString('Won 2 times', $html);
        $this->assertStringContainsString('hf-a--again', $html);
    }

    public function test_a_winner_with_no_photograph_still_gets_a_card(): void
    {
        // Plenty of nominees never upload one, and an inline <span> with no image collapses
        // to zero height — the trap the edition page records, which a fixture full of
        // photographs never produces.
        $this->award('Community Impact', 'Amina Bello', 900, 2);
        $html = $this->render(HallOfFame::build());

        $this->assertStringContainsString('Amina Bello', $html);
        $this->assertStringContainsString('hf-ph--none', $html);

        // Asserted against the FILE, because the rule lives in `head_styles` and this
        // render compiles `content` alone. An inline <span> with no image collapses to
        // zero height, so the card would draw a name under nothing at all.
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/hall.twig');
        $this->assertMatchesRegularExpression('/\.hf-ph\{[^}]*display:block/', $css,
            'the empty portrait box would collapse to nothing');
    }

    public function test_the_empty_hall_does_not_read_as_a_fault(): void
    {
        // A platform before its first announcement is a platform working correctly.
        $html = $this->render(HallOfFame::build());

        $this->assertStringNotContainsString('hf-wall', $html);
        $this->assertStringContainsString('results archive', $html);
        foreach (['error', 'sorry', 'failed', 'unavailable'] as $wrong) {
            $this->assertStringNotContainsString($wrong, strtolower($html));
        }
    }

    public function test_the_page_spends_its_colour_on_one_role(): void
    {
        // This is the page the gold exists for, and the discipline is that it is the ONLY
        // role on it — a screen wearing four accents has none. AccentTest holds the ceiling
        // across every public template; this pins the intent of this one.
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/hall.twig');
        $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);

        foreach (Accent::roles() as $role) {
            $used = (bool) preg_match('/var\(\s*--ag-' . $role . '-/', $body);
            $this->assertSame($role === Accent::HONOUR, $used,
                "the hall uses the '{$role}' accent");
        }

        // And all four of honour's slots, because that is what the role system is for: the
        // wash is the band, the edge is the ring that owes 3:1, the ink is the index, the
        // fill is the chip.
        foreach (['fill', 'edge', 'ink', 'wash'] as $slot) {
            $this->assertStringContainsString('--ag-honour-' . $slot, $body);
        }
    }

    // ══ the overall standing ═════════════════════════════════════════════════

    /**
     * THE ONE A NAIVE IMPLEMENTATION FAILS.
     *
     * `ResultRelease::overall($cycleId)` with no categories re-scores the whole cycle from
     * today's rules, so a released edition would publish an overall winner under
     * arithmetic nobody was given — the same fault `ReleasedStanding` exists to prevent,
     * one level up from the category it already fixed. The categories are handed in
     * already sealed instead, so the figures being ranked are the announced ones.
     *
     * Proved the only way it can be: the rules move between the seal and the read.
     */
    public function test_the_overall_winner_comes_from_the_sealed_figures(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 2100, 1);
        $this->award('Community Impact', 'Amina Bello', 900, 2);

        $before = \AfricaGates\Services\PublicResults::index()['editions'][0]['overall'];
        $this->assertNotNull($before['winner'], 'nothing was ranked, so nothing is proved');

        $sealed = (new \AfricaGates\Services\SnapshotService())->captureRelease($this->cycleId);
        $this->assertGreaterThan(0, $sealed, 'nothing was sealed, so nothing is proved');

        // The judge half is `550 × avg/10`; the curved form pays 8.0 as 256 of 550 rather
        // than 440, so this moves any real index a long way.
        (new \AfricaGates\Services\RuleEngine())->set('global', null, [
            'community_basis' => \AfricaGates\Services\CpiService::BASIS_RELATIVE,
            'community_scope' => \AfricaGates\Services\CpiService::SCOPE_CATEGORY,
            'judge_scale'     => \AfricaGates\Services\CpiService::SCALE_CURVED,
        ]);

        $after = \AfricaGates\Services\PublicResults::index()['editions'][0]['overall'];

        $this->assertSame($before['winner']['name'], $after['winner']['name'],
            'the edition crowned a different person under rules nobody was given');
        $this->assertSame((int) $before['winner']['cpi'], (int) $after['winner']['cpi'],
            're-scored: the overall standing is being computed live, not from the seal');
    }

    public function test_the_reconstructed_order_is_admitted_and_not_hidden(): void
    {
        // `standing_rank` is the rank WITHIN a category; no overall rank is sealed
        // anywhere, so this order is always re-derived through ResultRelease::order().
        // That is the position the category rank was in before it was sealed, and
        // ReleasedStanding's docblock says why it is not good enough on its own — so the
        // fact travels rather than being quietly presented as the announcement.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);

        $o = \AfricaGates\Services\PublicResults::index()['editions'][0]['overall'];
        $this->assertTrue($o['reconstructed']);

        $w = HallOfFame::build()['people'][0]['wins'][0];
        $this->assertTrue($w['overall']);
        $this->assertTrue($w['overall_reconstructed'],
            'the card cannot tell a reader the order was reconstructed');
    }

    public function test_a_withheld_award_makes_the_edition_winner_provisional(): void
    {
        // A nominee from an award nobody has announced must not appear in a public
        // standing — so only published categories are ranked, and the cost is that the
        // true top of the edition may be sitting in the withheld one. Naming somebody and
        // quietly replacing them later is worse than saying it is not settled.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);

        $clean = \AfricaGates\Services\PublicResults::index()['editions'][0]['overall'];
        $this->assertFalse($clean['provisional']);
        $this->assertSame(0, $clean['held']);

        // A category with votes and no panel at all: nobody meets quorum, so it is held.
        $c = (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => $this->cycleId, 'slug' => 'c-' . bin2hex(random_bytes(4)),
            'title' => 'Unjudged', 'sort_order' => 9,
        ]);
        $this->nominee($c, 'Nobody Judged', 9000);

        $now = \AfricaGates\Services\PublicResults::index()['editions'][0]['overall'];

        $this->assertTrue($now['provisional'], 'a withheld award did not unsettle the edition');
        $this->assertGreaterThan(0, $now['held']);
        $this->assertSame('Oluwagbemiga Dorcas', $now['winner']['name'],
            'a nominee from an unannounced award was named overall winner');

        // And it reaches the card.
        $this->assertTrue(HallOfFame::build()['people'][0]['wins'][0]['overall_provisional']);
    }

    public function test_an_edition_win_outranks_a_category_win_in_the_wall(): void
    {
        // The hall used to sort by recency alone, so the person who led an entire edition
        // sat in the same row as somebody who won one category of it. Topping an edition
        // is the largest thing this platform decides.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 2100, 1);
        $this->award('Community Impact', 'Amina Bello', 900, 2);

        // A LATER edition whose winner did not top it, so recency alone would put them
        // first and only the overall rule can order this correctly.
        $next = $this->cycle(2027);
        $this->award('Design', 'Later Person', 200, 1, '', $next);
        $this->award('Film',   'Later Leader', 1800, 2, '', $next);

        $hall = HallOfFame::build();
        $names = array_column($hall['people'], 'name');

        $lead = array_slice($names, 0, 2);
        sort($lead);
        $this->assertSame(['Later Leader', 'Oluwagbemiga Dorcas'], $lead,
            'a category winner is ahead of somebody who won a whole edition');

        $this->assertSame(2, $hall['overall']);
        foreach ($hall['people'] as $p) {
            $this->assertSame($p['overall_count'] > 0, (bool) $p['wins'][0]['overall'],
                $p['name'] . ': the card leads with the wrong win');
        }
    }

    public function test_a_persons_own_wins_lead_with_the_biggest_one(): void
    {
        // `wins|first` is what a card draws. Somebody who topped one edition and took a
        // single category in a LATER one must not have the smaller, more recent award on
        // their card.
        $profile = (int) DB::table('gates_profiles')->insertGetId([
            'slug' => 'two-' . bin2hex(random_bytes(3)), 'display_name' => 'Two Wins',
            'email' => 'two-' . bin2hex(random_bytes(4)) . '@example.test',
        ]);

        $old = $this->award('Teachers’ Choice', 'Two Wins', 2100, 1);           // 2026: topped it
        $next = $this->cycle(2027);
        $this->award('Film', 'Someone Bigger', 3000, 1, '', $next);             // 2027: leads
        $new = $this->award('Design', 'Two Wins', 400, 2, '', $next);           // 2027: category only

        DB::table('gates_nominees')->whereIn('id', [$old, $new])->update(['profile_id' => $profile]);

        $hall = HallOfFame::build();
        $them = null;
        foreach ($hall['people'] as $p) if ($p['name'] === 'Two Wins') $them = $p;

        $this->assertNotNull($them);
        $this->assertSame(2, $them['count']);
        $this->assertTrue($them['wins'][0]['overall'],
            'the card leads with the later, smaller award');
        $this->assertSame(2026, (int) $them['wins'][0]['year']);
    }

    public function test_the_overall_pass_re_scores_nothing(): void
    {
        // It sorts rows already in memory. Passing a cycle id instead of the drawn
        // categories would make `ResultRelease::overall()` call `forCycle()` and score
        // every category a SECOND time — on a public page, once per edition.
        //
        // The first cut of this test compared two `index()` calls and failed at 54 vs 57:
        // per-process memos (SchemaHas, the sealed-standing cache) warm on the first one,
        // so that comparison measures the memos rather than the pass. This asks the
        // question directly instead, and proves it is not vacuous by showing what the
        // other call shape costs.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1200, 1);
        $this->award('Community Impact', 'Amina Bello', 900, 2);

        $idx     = \AfricaGates\Services\PublicResults::index();   // also warms the memos
        $edition = $idx['editions'][0];
        $awards  = (array) $edition['awards'];
        $cycleId = (int) $edition['cycle_id'];

        $this->assertNotSame([], $awards, 'nothing was drawn, so nothing is proved');

        $count = function (callable $fn): int {
            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();
            $fn();
            $n = count(DB::connection()->getQueryLog());
            DB::connection()->disableQueryLog();

            return $n;
        };

        $withRows = $count(static fn () => \AfricaGates\Services\ResultRelease::overall($cycleId, $awards));
        $this->assertSame(0, $withRows,
            'the overall pass read the database — it is re-scoring rather than sorting');

        // And the shape that WOULD re-score, so the assertion above means something.
        $withoutRows = $count(static fn () => \AfricaGates\Services\ResultRelease::overall($cycleId));
        $this->assertGreaterThan(0, $withoutRows,
            'both call shapes cost nothing, so this test proves nothing about either');
    }
}
