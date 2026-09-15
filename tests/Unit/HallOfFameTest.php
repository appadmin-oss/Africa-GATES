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
        // A REAL repeat winner, built the way this platform actually models one. The first
        // version edited `count` on the payload after `build()` had returned it, and the
        // day the service started deriving anything from that field — the split into
        // overall and category winners — the edit stopped reaching the page while the test
        // went on claiming to exercise it. A fixture that patches a service's output is
        // testing the patch.
        $this->repeatWinner('Oluwagbemiga Dorcas', 'nominees/a.jpg');

        $hall = HallOfFame::build();
        $this->assertSame(1, $hall['repeat'], 'the fixture did not produce a repeat winner');

        $html = $this->render($hall);

        $this->assertStringContainsString('Oluwagbemiga Dorcas', $html);
        $this->assertStringContainsString('alt="Oluwagbemiga Dorcas"', $html,
            'the portrait has no accessible name');

        // NEVER COLOUR ALONE, and never a ring. Roughly one man in twelve cannot separate
        // two of these hues and a screen reader has nothing at all to announce for an
        // outline, so the repetition is a WORD — in the tile for an overall winner, and in
        // a pill on a category card.
        $this->assertMatchesRegularExpression('/won twice|Won 2 times/i', $html,
            'the one fact this page knows that no ledger does is not written anywhere');
    }

    /**
     * One human being who has won in two editions.
     *
     * TWO NOMINEE ROWS, ONE PROFILE — which is how this platform models the same person
     * across editions. A nominee row belongs to one category, so a fixture that moves a
     * row between editions is testing a shape no cycle produces. `gates_profiles.email` is
     * NOT NULL UNIQUE with no default: checked against the schema rather than copied from a
     * neighbouring fixture, which is the only way this ever passes.
     */
    private function repeatWinner(string $name, string $photo = ''): int
    {
        $profile = (int) DB::table('gates_profiles')->insertGetId([
            'slug' => 'rw-' . bin2hex(random_bytes(4)),
            'display_name' => $name,
            'email' => 'rw-' . bin2hex(random_bytes(5)) . '@example.test',
        ]);

        $prev = $this->cycle(2025);
        $now  = $this->award('Teachers’ Choice', $name, 1200, 1, $photo);
        $then = $this->award('Teachers’ Choice', $name,  900, 1, '', $prev);

        DB::table('gates_nominees')->whereIn('id', [$now, $then])
            ->update(['profile_id' => $profile]);

        return $profile;
    }

    public function test_a_winner_with_no_photograph_still_gets_a_card(): void
    {
        // Plenty of nominees never upload one, and an inline <span> with no image collapses
        // to zero height — the trap the edition page records, which a fixture full of
        // photographs never produces.
        $this->award('Community Impact', 'Amina Bello', 900, 2);
        $html = $this->render(HallOfFame::build());

        $this->assertStringContainsString('Amina Bello', $html);

        // WORDED. An initial on a grey square reads as a broken image, and "no photograph"
        // and "the image failed" must be tellable apart on a page whose whole subject is a
        // person. The initial itself is aria-hidden — announcing "A" before somebody's own
        // name reads as a stammer.
        $this->assertStringContainsString('No photograph', $html);
        $this->assertMatchesRegularExpression('/<b aria-hidden="true">A</', $html);

        // Asserted against the FILE, because the rule lives in `head_styles` and this
        // render compiles `content` alone. An inline <span> with no image collapses to
        // zero height, so the card would draw a name under nothing at all.
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/hall.twig');
        $this->assertMatchesRegularExpression('/\.hf-m__ph\{[^}]*display:block/', $css,
            'the small card\'s empty portrait box would collapse to nothing');
        $this->assertMatchesRegularExpression('/\.hf-ph\{[^}]*display:flex/', $css,
            'the large card\'s empty portrait box would collapse to nothing');
    }

    public function test_the_empty_hall_does_not_read_as_a_fault(): void
    {
        // A platform before its first announcement is a platform working correctly.
        $html = $this->render(HallOfFame::build());

        $this->assertStringNotContainsString('hf-major', $html);
        $this->assertStringNotContainsString('hf-minor', $html);
        $this->assertStringContainsString('No award has been announced yet', $html);
        // Said as a fact about the platform's age, not as an apology for a missing page.
        $this->assertStringContainsString('working correctly', $html);
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

        // A FIELD is what a reader can point at, so that is what the ceiling counts. An
        // `edge` is a boundary and an `ink` is a word; the page draws a caution OUTLINE on
        // a provisional caveat and an `action` focus ring, and forbidding either pushes
        // this page toward filling the caveat — a red field under somebody's face, which
        // is an accusation — or dropping a focus ring, which is a WCAG 2.4.7 failure
        // traded for a palette rule.
        foreach (Accent::roles() as $role) {
            $used = (bool) preg_match('/var\(\s*--ag-' . $role . '-(?:fill|wash)\b/', $body);
            $this->assertSame($role === Accent::HONOUR, $used,
                "the hall paints a field in the '{$role}' accent");
        }

        // Three of honour's four slots are named here: the wash is the band, the edge is
        // the boundary that owes 3:1, the ink is the word on it.
        foreach (['edge', 'ink', 'wash'] as $slot) {
            $this->assertStringContainsString('--ag-honour-' . $slot, $body);
        }

        // The FILL is not, and that is the point rather than a gap. The saturated mark
        // arrives through `partials/tile.twig`, which takes its four values inline from
        // `Accent::tileStyle('<meaning>')` — so a tile cannot be built out of a hue
        // somebody liked, and this page cannot hand-roll one. A second implementation of
        // the tile is exactly how one screen's "overall winner" comes to look like
        // another's "voting open".
        $this->assertStringNotContainsString('--ag-honour-fill', $body,
            'the hall is painting its own mark instead of using the tile');
        $this->assertStringContainsString("include 'partials/tile.twig'", $body);
        $this->assertStringContainsString("meaning: 'overall-winner'", $body,
            'the tile is asked for by role or hue rather than by meaning');
    }


    // ══ the hierarchy, which is the whole reason this page was rebuilt ════════

    /**
     * TOPPING AN EDITION AND WINNING ONE CATEGORY OF IT ARE DRAWN APART.
     *
     * The first cut drew eleven identical cards. Those are the two different things this
     * platform decides, and drawing them at one weight throws away the only ranking it
     * makes — the person who led an entire edition sat in the same row as somebody who won
     * one category, with nothing saying which was which.
     *
     * The split is in the SERVICE and not a `filter` in the template, because it is a claim
     * about the data rather than a layout convenience: a claim a template makes is one no
     * test can reach and the next template will make differently.
     */
    public function test_an_edition_winner_and_a_category_winner_are_drawn_apart(): void
    {
        $this->award('Teachers’ Choice',  'Oluwagbemiga Dorcas', 1500, 1);
        $this->award('Community Impact',  'Amina Bello',          900, 2);

        $hall = HallOfFame::build();
        $this->assertCount(1, $hall['overall_people'],
            'nobody is being credited with topping the edition');
        $this->assertSame('Oluwagbemiga Dorcas', $hall['overall_people'][0]['name']);

        $this->assertSame(['Amina Bello'], array_column($hall['category_people'], 'name'));

        // Every person is in exactly one of the two groups. A hall that shows somebody
        // twice reads as two people with one name, which is the opposite of this page's job.
        $this->assertSame(count($hall['people']),
            count($hall['overall_people']) + count($hall['category_people']));
    }

    /**
     * And the hierarchy survives the colour being covered.
     *
     * The thumb test, as a rule anyone can run: cover every coloured element and the page
     * must still read. So the distance between the two sections is carried by weight, size
     * and space — a 2px rule against a 1px one, a 260px portrait against 180, a name above
     * 2rem against 18px, an index above 2rem against 24px.
     */
    public function test_the_two_sections_are_told_apart_without_any_colour(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/hall.twig');

        $this->assertMatchesRegularExpression('/\.hf-h--major\{[^}]*border-bottom:2px/', $css);
        $this->assertMatchesRegularExpression('/\.hf-h--minor\{[^}]*border-bottom:1px/', $css);

        // The portrait, the name and the index each step down between the two cards.
        $this->assertMatchesRegularExpression('/\.hf-card__in\{[^}]*grid-template-columns:260px/', $css);
        $this->assertMatchesRegularExpression('/\.hf-m__n\{[^}]*font-size:18px/', $css);
        $this->assertMatchesRegularExpression('/\.hf-m__i\{[^}]*font:700 24px/', $css);

        // `align-items:start` on the two-up grid. The cards are genuinely different
        // heights and that difference is information — one carries a provisional caveat
        // and a second win. Forcing them level means stretching a portrait or padding a
        // card to hide a fact.
        $this->assertMatchesRegularExpression('/\.hf-major\{[^}]*align-items:start/', $css);

        // `align-self:stretch` with a min-height, NOT `aspect-ratio`, on the large
        // portrait: aspect-ratio gives a grid item a definite height, overrides stretch,
        // and leaves the card's white showing below the picture.
        $this->assertMatchesRegularExpression('/\.hf-ph\{[^}]*align-self:stretch/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.hf-ph\{[^}]*aspect-ratio/', $css);
    }

    // ══ the controls ═════════════════════════════════════════════════════════

    public function test_the_jump_rail_lists_only_letters_somebody_is_filed_under(): void
    {
        // An A–Z with fifteen dead keys is a control that lies about the size of the hall.
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1);
        $this->award('Community Impact', 'Aïcha Traoré',         900, 2);

        $letters = HallOfFame::build()['letters'];

        $this->assertSame(['A', 'O'], $letters);

        // "Aïcha" files under A. `mb_` throughout: a byte-wise substr on a multi-byte name
        // produces half a character, which renders as a replacement glyph in a rail whose
        // whole job is being scannable.
        $this->assertSame(1, HallOfFame::build(40, ['letter' => 'A'])['shown']);
    }

    public function test_every_filter_is_a_value_a_url_can_carry_and_none_is_trusted(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1);
        $this->award('Community Impact', 'Amina Bello',          900, 2);

        $this->assertSame(1, HallOfFame::build(40, ['q' => 'amina'])['shown']);
        $this->assertSame(2, HallOfFame::build(40, ['edition' => '2026'])['shown']);
        $this->assertSame(0, HallOfFame::build(40, ['edition' => '1999'])['shown']);

        // Untrusted, and resolved to the default rather than to an error: a stale or
        // mistyped link is somebody trying to read a hall of fame.
        $v = HallOfFame::build(40, ['letter' => '../../etc/passwd', 'edition' => 'drop'])['view'];
        $this->assertSame('', $v['letter']);
        $this->assertSame(0, $v['edition']);
    }

    public function test_a_filter_never_deletes_the_way_back_out_of_itself(): void
    {
        $this->award('Teachers’ Choice', 'Oluwagbemiga Dorcas', 1500, 1);

        // The chips and the letters come off the WHOLE hall, not the filtered one. A
        // control that removes its own undo is the interaction people report as the site
        // being broken.
        $filtered = HallOfFame::build(40, ['letter' => 'Z']);
        $this->assertSame(0, $filtered['shown']);
        $this->assertNotSame([], $filtered['letters'],
            'the rail emptied itself along with the wall');
        $this->assertNotSame([], $filtered['programme_chips']);
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

    // ── THE TWO-UP LAYOUT'S ARITHMETIC ───────────────────────────────────────

    /** The page's own stylesheet, which lives in `head_styles` in the template. */
    private function hallCss(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/results/hall.twig');
    }

    /**
     * A number out of the stylesheet.
     *
     * FLOAT, and that is not fussiness. This returned `(int)` first, which truncated the
     * card's `1.5rem` padding to `1` — so the arithmetic below subtracted 32px where the
     * layout subtracts 48, and the check came out 16px too generous. Widening the
     * portrait back to 300px, which leaves the name 197px, passed it. The fault was
     * caught only because a neighbouring test happens to pin the 260 by name, which is
     * luck rather than coverage.
     */
    private function cssNumber(string $pattern, string $what): float
    {
        $this->assertMatchesRegularExpression($pattern, $this->hallCss(),
            "could not find {$what} in the hall's stylesheet — this test reads the real "
          . 'numbers rather than repeating them, so a rename here means the arithmetic '
          . 'below is no longer being checked at all. Fix the pattern, never delete it.');
        preg_match($pattern, $this->hallCss(), $m);

        return (float) $m[1];
    }

    public function test_the_cards_go_one_up_before_the_name_column_is_starved(): void
    {
        /*
         * ── THE FAULT, WHICH NO ROUND TEST WIDTH SHOWS ──────────────────────
         *
         * `.hf-card__in` puts a FIXED 260px portrait beside a `1fr` name column, and
         * `.hf-major` puts two of those cards side by side. The fixed track does not
         * shrink, so everything the viewport loses comes out of the name.
         *
         * The single-column switch used to be at 900px. Measured in a browser at the
         * widths just above it:
         *
         *     910  → card 397px → NAME COLUMN 72px
         *     1000 → card 439px → name column 107px
         *     1080 → card 479px → name column 145px
         *
         * At 910 "Oluwagbemiga Dorcas" rendered 142px tall in a 72px strip — a 2.3rem
         * display face broken over five lines, narrower than one word of it. A 1024px
         * iPad and a 1366px laptop both sat inside that band, and 430 and 1280 — the two
         * widths anybody actually checks — both looked perfect.
         *
         * So this asserts the RELATIONSHIP rather than either number: two-up may not
         * begin before the wrapper has stopped growing, because that is the only width
         * at which the card is the size the design was drawn for.
         */
        $wrap  = $this->cssNumber('/\.hf-wrap\{[^}]*max-width:(\d+)px/', "`.hf-wrap`'s max-width");
        $oneUp = $this->cssNumber(
            '/@media \(max-width:(\d+)px\)\{\s*\.hf-major\{[^}]*grid-template-columns:minmax\(0,1fr\)/',
            'the breakpoint where `.hf-major` becomes one column');

        // `max-width: Npx` applies AT N, so two-up begins at N + 1.
        $this->assertGreaterThanOrEqual($wrap, $oneUp + 1,
            "the overall cards go two-up from {$oneUp}px, before `.hf-wrap` reaches its "
          . "full {$wrap}px. Between those widths each card is narrower than the design's, "
          . 'and because the portrait track is a fixed pixel width every pixel lost comes '
          . "out of the person's name. At 910px that column measured 72px wide.");
    }

    public function test_the_name_keeps_a_usable_column_at_the_narrowest_two_up_width(): void
    {
        // The other direction, and the one the breakpoint alone does not catch: widening
        // the portrait back towards the 300px it started at would starve the name again
        // without moving any breakpoint. The comment above `.hf-card__in` records that
        // 300 clipped "Oluwagbemiga" mid-word on the first real name this page drew.
        $wrap     = $this->cssNumber('/\.hf-wrap\{[^}]*max-width:(\d+)px/', "`.hf-wrap`'s max-width");
        $gutter   = $this->cssNumber('/\.hf-wrap\{[^}]*padding:0 var\(--ag-gutter,(\d+)px\)/', "the wrapper's gutter");
        $gridGap  = $this->cssNumber('/\.hf-major\{[^}]*gap:(\d+)px/', "the two-up grid's gap");
        $portrait = $this->cssNumber('/\.hf-card__in\{[^}]*grid-template-columns:(\d+)px/', 'the portrait track');
        // The card's own inner gap and padding are clamped; the widest value is what
        // applies at the widths where the grid is two-up, so that is what is subtracted.
        $cardGap  = $this->cssNumber('/\.hf-card__in\{[^}]*gap:clamp\([^)]*,(\d+)px\)/', "the card's inner gap");
        $padRem   = $this->cssNumber('/\.hf-card__in\{[^}]*padding:clamp\([^)]*,([\d.]+)rem\)/', "the card's padding");

        $content = $wrap - (2 * $gutter);
        $card    = ($content - $gridGap) / 2;
        $name    = (int) round($card - $portrait - $cardGap - (2 * $padRem * 16));

        // 200px holds "Oluwagbemiga" — the longest name this page has actually drawn — on
        // one line at the clamped-down display size, which is what stops `overflow-wrap`
        // breaking a person's name mid-word. The rule above `.hf-card__in` is explicit
        // that a name here is the SUBJECT of the page and may never be cut.
        $this->assertGreaterThanOrEqual(200, $name,
            "at the narrowest two-up width the name's column is {$name}px. The portrait "
          . "track is {$portrait}px and does not shrink, so it has to give the column "
          . 'back — that is the trade the 300 → 260 reduction already made once.');
    }

    public function test_the_portrait_photo_is_taken_out_of_flow(): void
    {
        // Otherwise `min-height` governs nothing. `.hf-ph` is a stretched grid item, so
        // its own height is `auto`; `height:100%` on the image resolved against `auto` to
        // `auto`, the image fell back to the intrinsic ratio of its `width="600"
        // height="750"` attributes, and the IMAGE sized the row.
        //
        // Measured before the fix: 260x326 at 1280 rather than the 290 the rule asks for,
        // and — where the card collapses to one column — 350x438 at 430px, a portrait
        // taller than the visible area of a phone, pushing the name of the person the card
        // is about below the fold. `object-fit:cover` never ran once.
        $css = $this->hallCss();

        $this->assertMatchesRegularExpression(
            '/\.hf-ph img\{[^}]*position:absolute/', $css,
            "the large card's photo is in flow, so it sizes the card instead of filling "
          . 'it — `min-height` and `object-fit:cover` on it are both dead letters');
        $this->assertMatchesRegularExpression('/\.hf-ph img\{[^}]*object-fit:cover/', $css);
        $this->assertMatchesRegularExpression('/\.hf-ph\{[^}]*position:relative/', $css,
            'the photo is absolutely positioned, so its box must be the containing block');
    }

    public function test_a_section_heading_is_not_crushed_against_its_own_caption(): void
    {
        // `.hf-h` is a flex row with `justify-content:space-between`, and the caption asks
        // for 40ch. Measured at 430px the caption took 298px and left the HEADING 76 — so
        // "Overall winners" broke over two lines in a 76px column beside its own gloss,
        // which reads as a broken layout rather than as a heading.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:\d+px\)\{\s*\.hf-h\{[^}]*display:block/',
            $this->hallCss(),
            'the section heading and its caption never stop sharing a row, so at phone '
          . 'width the heading is squeezed into whatever the caption leaves');
    }
}
