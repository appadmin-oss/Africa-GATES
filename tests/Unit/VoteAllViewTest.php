<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * `/vote/{programme}` opens on ALL: every nominee in the edition, ranked by votes.
 *
 * The page drew one category and nothing else, so a voter had to tap through every
 * category chip to learn who was in it — and the award itself is decided across the
 * whole edition (the community half is scored against the edition's field, see
 * EditionScaleTest), which no view on the page showed. All is now the first view and
 * the one drawn without `?cat=`; a category chip filters to one category.
 *
 * Two things here would be invisible on a screen that looks right:
 *   · A nominee is drawn TWICE — in All and in their category — with a different rank
 *     in each. The live poll used to patch the first `[data-nominee]` it found, which
 *     is now the All row, with the CATEGORY's rank: a page that loads right and is
 *     wrong ten seconds later. Each list is patched from its own race.
 *   · All is a ranking of public VOTES, not the overall standing (the judges' half is
 *     not in it), so the page must not present it as the award's result.
 */
final class VoteAllViewTest extends TestCase
{
    private function seed(bool $twoCategories = true): void
    {
        DB::table('gates_award_programmes')->insertOrIgnore([
            'id' => 201, 'slug' => 'all-view-award', 'title' => 'All View Award', 'is_active' => 1,
        ]);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 201, 'programme_id' => 201, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 211, 'cycle_id' => 201, 'slug' => 'choirs', 'title' => 'Best Choir']);
        $rows = [
            [2101, 'Choir Low',  211, 10],
            [2102, 'Choir High', 211, 90],
        ];
        if ($twoCategories) {
            DB::table('gates_award_categories')->insertOrIgnore(['id' => 212, 'cycle_id' => 201, 'slug' => 'soloists', 'title' => 'Best Soloist']);
            $rows[] = [2103, 'Soloist Mid', 212, 50];
        }
        foreach ($rows as [$id, $name, $cat, $votes]) {
            DB::table('gates_nominees')->insert([
                'id' => $id, 'name' => $name, 'category_id' => $cat, 'status' => 'approved',
                'vote_count' => $votes, 'organic_vote_count' => $votes,
            ]);
        }
    }

    private function controller(): \AfricaGates\Controllers\VoteController
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $builder->build()->get(\AfricaGates\Controllers\VoteController::class);
    }

    /** @param array<string,string> $query */
    private function page(array $query = []): string
    {
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/vote/all-view-award')->withQueryParams($query);
        $res = $this->controller()->program($req, new \Slim\Psr7\Response(), ['program' => 'all-view-award']);
        $this->assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    /** The `<section data-view="…">` opening tags, keyed by view. */
    private function views(string $html): array
    {
        preg_match_all('~<section class="vp-view" data-view="([^"]+)"[^>]*>~', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) $out[$x[1]] = $x[0];
        return $out;
    }

    /** Nominee names in the order a list draws them. */
    private function names(string $html, string $race): array
    {
        $this->assertSame(1, preg_match('~<ol class="vp-race" data-race="' . $race . '">(.*?)</ol>~s', $html, $m), "no list for $race");
        preg_match_all('~class="vp-row__n">([^<]+)<~', $m[1], $n);
        return $n[1];
    }

    public function test_the_page_opens_on_all_with_every_nominee_ranked_across_the_edition(): void
    {
        $this->seed();
        $html = $this->page();
        $v = $this->views($html);

        $this->assertArrayHasKey('all', $v);
        $this->assertStringNotContainsString(' hidden', $v['all'], 'All is the view drawn on arrival');
        $this->assertStringContainsString(' hidden', $v['211']);
        $this->assertStringContainsString(' hidden', $v['212']);

        // Across categories, by votes: the soloist (50) sits between the two choirs.
        $this->assertSame(['Choir High', 'Soloist Mid', 'Choir Low'], $this->names($html, 'all'));
        // Each All row says which category it is in, or the merged list is unreadable.
        $this->assertSame(1, preg_match('~data-race="all">.*?Soloist Mid</span>.*?vp-row__meta">\s*Best Soloist~s', $html));
        // Its chip is first and current; a category's chip is a link to that view.
        $this->assertMatchesRegularExpression('~<a class="ag-chip vp-chip" href="/vote/all-view-award" data-view-link="all" aria-current="true">~', $html);
        $this->assertStringContainsString('href="/vote/all-view-award?cat=212"', $html);
    }

    public function test_a_category_link_opens_on_that_category_alone(): void
    {
        $this->seed();
        $html = $this->page(['cat' => '212']);
        $v = $this->views($html);

        $this->assertStringContainsString(' hidden', $v['all']);
        $this->assertStringNotContainsString(' hidden', $v['212']);
        $this->assertStringContainsString(' hidden', $v['211']);
        $this->assertMatchesRegularExpression('~data-view-link="212" aria-current="true"~', $html);
        // A category list ranks within the category: the choirs are #1 and #2 among themselves.
        $this->assertSame(['Choir High', 'Choir Low'], $this->names($html, '211'));

        // A ?cat= this page does not have falls back to All rather than to an empty view.
        $v2 = $this->views($this->page(['cat' => '999']));
        $this->assertStringNotContainsString(' hidden', $v2['all']);
    }

    public function test_one_category_has_no_all_to_offer(): void
    {
        $this->seed(false);
        $html = $this->page();
        $v = $this->views($html);

        $this->assertArrayNotHasKey('all', $v);
        $this->assertStringNotContainsString(' hidden', $v['211']);
        $this->assertStringNotContainsString('data-view-link="all"', $html);
    }

    public function test_all_is_labelled_as_votes_and_not_as_the_result(): void
    {
        $this->seed();
        $html = $this->page();
        $this->assertStringContainsString('ranked by public votes', $html);
        $this->assertStringContainsString('Public votes are one part of the score; the judges decide the rest.', $html);
    }

    public function test_the_live_tallies_carry_the_edition_ranks_and_the_page_patches_each_list_from_its_own(): void
    {
        $this->seed();
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/vote/all-view-award/tallies');
        $res = $this->controller()->tallies($req, new \Slim\Psr7\Response(), ['program' => 'all-view-award']);
        $d = json_decode((string) $res->getBody(), true);

        $this->assertTrue($d['ok']);
        $ranks = array_column($d['overall'], 'rank', 'id');
        $this->assertSame([2102 => 1, 2103 => 2, 2101 => 3], $ranks, 'the edition ranks, not a category\'s');
        $byCat = [];
        foreach ($d['categories'] as $c) $byCat[$c['category_id']] = array_column($c['nominees'], 'rank', 'id');
        $this->assertSame(1, $byCat[212][2103], 'within their own category the soloist leads');

        // The client must look a row up INSIDE the list it is patching. An unscoped lookup
        // finds the All row first and writes a category rank into it.
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/vote.js');
        $this->assertStringContainsString("\$('[data-nominee=\"' + x.id + '\"]', list)", $js);
        $this->assertStringContainsString("patch(\$('[data-race=\"all\"]'), d.overall)", $js);
        $this->assertStringNotContainsString("var row = \$('[data-nominee=\"' + x.id + '\"]'); if (!row) return;", $js);
    }
}
