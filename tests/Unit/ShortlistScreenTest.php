<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShortlistRule;
use AfricaGates\Services\ShortlistService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The shortlist screens, rendered through the real container.
 *
 * The cut algorithm is pinned by {@see ShortlistCutTest} and the freeze by
 * {@see ShortlistPublishTest}. What can only be seen by rendering is whether the page an
 * organiser actually looks at agrees with them — most of all the CUT LINE, which is
 * positioned by index because a Twig `{% set %}` flag does not survive a loop iteration.
 * That is a template-only failure: every unit test stays green while the line is drawn
 * above every excluded row.
 */
final class ShortlistScreenTest extends TestCase
{
    private const CYCLE = 1;
    private const CAT   = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';

        DB::table('gates_award_programmes')->insert([
            'id' => 1, 'slug' => 'gates', 'title' => 'Africa GATES', 'is_active' => 1, 'sort_order' => 1,
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => self::CYCLE, 'programme_id' => 1, 'year' => 2026, 'status' => 'voting',
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => self::CAT, 'cycle_id' => self::CYCLE, 'slug' => 'health',
            'title' => 'Community Health', 'sort_order' => 1,
        ]);
        foreach ([[1, 'Amina', 90], [2, 'Bello', 80], [3, 'Chidi', 70], [4, 'Dami', 60]] as [$i, $n, $v]) {
            DB::table('gates_nominees')->insert([
                'id' => $i, 'category_id' => self::CAT, 'name' => $n, 'status' => 'approved',
                'vote_count' => $v, 'organic_vote_count' => $v, 'country_code' => 'NG',
            ]);
        }
    }

    private function render(string $method, array $args = [], string $path = '/admin/shortlists'): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        $res = $b->build()->get(\AfricaGates\Admin\Controllers\ShortlistsController::class)->{$method}(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
            (new ResponseFactory())->createResponse(),
            $args
        );

        $this->assertSame(200, $res->getStatusCode(), "{$method} did not render");
        return (string) $res->getBody();
    }

    public function test_the_index_lists_every_category_with_its_live_count(): void
    {
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 1);

        $html = $this->render('index');

        $this->assertStringContainsString('Community Health', $html);
        $this->assertStringContainsString('Top 2 by votes', $html, 'the rule must be stated, not just stored');
        $this->assertStringContainsString('not published', $html);
        $this->assertStringContainsString('/admin/shortlists/category/1', $html);
    }

    /**
     * THE TEMPLATE-ONLY ONE. Exactly one cut line, and it sits after the last included
     * nominee — not above every excluded one, which is what a `{% set %}` flag produces.
     */
    public function test_exactly_one_cut_line_is_drawn_and_it_sits_at_the_boundary(): void
    {
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 1);

        $html = $this->render('category', ['catId' => self::CAT], '/admin/shortlists/category/1');

        $this->assertSame(1, substr_count($html, 'class="ad-cut"'),
            'a flag-based cut line would draw one above every excluded row');

        // Two nominees above the line, two below it.
        $before = substr($html, 0, strpos($html, 'class="ad-cut"') ?: 0);
        $after  = substr($html, strpos($html, 'class="ad-cut"') ?: 0);

        foreach (['Amina', 'Bello'] as $in)  $this->assertStringContainsString($in, $before, "{$in} should be above the line");
        foreach (['Chidi', 'Dami'] as $out)  $this->assertStringContainsString($out, $after, "{$out} should be below the line");

        $this->assertStringContainsString('2 above', $html);
    }

    /** When everybody qualifies there is no boundary, so no line may be drawn. */
    public function test_no_cut_line_is_drawn_when_the_whole_field_advances(): void
    {
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 50, 1), 1);

        $html = $this->render('category', ['catId' => self::CAT], '/admin/shortlists/category/1');

        $this->assertStringNotContainsString('class="ad-cut"', $html);
        $this->assertStringContainsString('4 of 4', $html);
    }

    public function test_the_published_list_and_the_live_preview_are_both_shown_and_labelled(): void
    {
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 1);
        ShortlistService::publish(self::CYCLE, self::CAT, 1);

        // A vote lands after publication.
        DB::table('gates_nominees')->where('id', 4)->update(['vote_count' => 999, 'organic_vote_count' => 999]);

        $html = $this->render('category', ['catId' => self::CAT], '/admin/shortlists/category/1');

        $this->assertStringContainsString('Published shortlist', $html);
        $this->assertStringContainsString('Preview — live', $html,
            'the two must be distinguishable or somebody acts on the wrong one');
        $this->assertStringContainsString('Republish', $html);
        $this->assertStringContainsString('Download PDF', $html);
    }

    /** A nested <form> would be dropped by the browser, so the override control must not be one. */
    public function test_the_inherit_control_is_a_formaction_button_and_not_a_nested_form(): void
    {
        ShortlistService::saveRule(self::CYCLE, self::CAT, new ShortlistRule('top_n', 2, 1), 1);

        $html = $this->render('category', ['catId' => self::CAT], '/admin/shortlists/category/1');

        $this->assertStringContainsString('formaction="/admin/shortlists/category/1/inherit"', $html);
        $this->assertStringContainsString('own rule', $this->render('index'),
            'the index must show that this category overrides the cycle');
    }

    /** Every write on these screens must carry a token, or CSRF rejects the whole feature. */
    public function test_every_form_carries_a_csrf_token(): void
    {
        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 1);
        ShortlistService::publish(self::CYCLE, self::CAT, 1);

        foreach ([$this->render('index'),
                  $this->render('category', ['catId' => self::CAT], '/admin/shortlists/category/1')] as $html) {
            preg_match_all('~<form[^>]*method="post"~i', $html, $posts);
            $this->assertSame(count($posts[0]), substr_count($html, 'name="_token"'),
                'a POST form without a token is a page whose buttons all fail');
        }
    }

    /**
     * The PDF route is a download, not a page. It must come back as a PDF with a
     * Content-Disposition, and must refuse when nothing has been published.
     */
    public function test_the_pdf_route_serves_a_document_only_once_something_is_published(): void
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $b->build()->get(\AfricaGates\Admin\Controllers\ShortlistsController::class);

        $call = fn () => $c->pdf(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/shortlists/category/1.pdf'),
            (new ResponseFactory())->createResponse(),
            ['catId' => self::CAT]
        );

        $before = $call();
        $this->assertSame(302, $before->getStatusCode(), 'there is no document until one is published');

        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 1);
        ShortlistService::publish(self::CYCLE, self::CAT, 1);

        $res = $call();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('community-health-2026-shortlist.pdf',
            $res->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF-', (string) $res->getBody());
    }

    /**
     * An editor may read and tune; they may not publish. The screen has to agree with the
     * route guard, or it offers a button that bounces them.
     */
    public function test_an_editor_is_not_offered_the_publish_button(): void
    {
        $_SESSION['admin_role'] = 'editor';

        $html = $this->render('category', ['catId' => self::CAT], '/admin/shortlists/category/1');

        $this->assertStringNotContainsString('/publish', $html, 'the UI must not offer a 403');
        $this->assertStringContainsString('Save rule for this category', $html, 'but tuning is still theirs');
    }

    public function test_a_category_that_does_not_exist_redirects_rather_than_erroring(): void
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');

        $res = $b->build()->get(\AfricaGates\Admin\Controllers\ShortlistsController::class)->category(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/shortlists/category/999'),
            (new ResponseFactory())->createResponse(),
            ['catId' => 999]
        );

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/shortlists', $res->getHeaderLine('Location'));
    }

    // ══ the public side ══════════════════════════════════════════════════════

    /**
     * The badge on the public programme page comes from the PUBLISHED snapshot.
     *
     * A badge derived from the live threshold would appear and disappear as votes landed —
     * telling a nominee they were shortlisted and then that they were not, on a page they
     * never reloaded. So: nothing before publication, the badge after it, and it survives a
     * vote that would have knocked them below the line.
     */
    public function test_the_public_badge_appears_only_after_publication_and_then_holds(): void
    {
        DB::table('gates_award_cycles')->where('id', self::CYCLE)->update([
            'voting_open'  => date('Y-m-d H:i:s', time() - 86400),
            'voting_close' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $render = function (): string {
            $b = new ContainerBuilder();
            $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
            $res = $b->build()->get(\AfricaGates\Controllers\VoteController::class)->program(
                (new ServerRequestFactory())->createServerRequest('GET', '/vote/gates'),
                (new ResponseFactory())->createResponse(),
                ['program' => 'gates']
            );
            return (string) $res->getBody();
        };

        // `class="vp-short"` and not the bare class name: the rule that styles the badge
        // is in the page's own <style> block on every render, so a substring test on the
        // name alone would pass whether or not a single card carried it.
        $this->assertStringNotContainsString('class="vp-short"', $render(),
            'nothing may be badged before a shortlist is published');

        ShortlistService::saveRule(self::CYCLE, null, new ShortlistRule('top_n', 2, 1), 1);
        ShortlistService::publish(self::CYCLE, self::CAT, 1);

        $html = $render();
        $this->assertSame(2, substr_count($html, 'class="vp-short"'),
            'exactly the two published nominees carry the badge');

        // Dami overtakes everybody. The published list — and the badge — do not move.
        DB::table('gates_nominees')->where('id', 4)->update(['vote_count' => 9999, 'organic_vote_count' => 9999]);

        $after = $render();
        $this->assertSame(2, substr_count($after, 'class="vp-short"'),
            'a badge that moves with the live tally un-tells a nominee they were shortlisted');
    }

    /** A cycle with no categories is a normal state, not an empty page with no explanation. */
    public function test_a_cycle_with_no_categories_says_so(): void
    {
        DB::table('gates_award_categories')->delete();

        $this->assertStringContainsString('no categories yet', $this->render('index'));
    }
}
