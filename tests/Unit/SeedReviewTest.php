<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ChallengesController;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\SeedReview;
use AfricaGates\Support\SeedRunner;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * A seed checked, and corrected, before it runs.
 *
 * The Celebrate Nigeria seed writes a PUBLISHED challenge whose rules lock the moment
 * they exist, and the hourly sweep used to run it the first hour an Alimosho edition was
 * open. So the handoff's figures went live as typed, with no moment at which anybody
 * could change one. These cases hold the three halves of the repair: the clock does not
 * run it unapproved, what the operator types is what is written, and an approval covers
 * exactly the figures that were on the screen when it was given.
 */
final class SeedReviewTest extends TestCase
{
    private const SEED = '2026_10_01_celebrate_nigeria';
    private const SLUG = 'celebrate-nigeria-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $p = (int) DB::table('gates_award_programmes')->insertGetId(
            ['slug' => 'alimosho-awards', 'title' => 'Alimosho Awards', 'is_active' => 1]);
        $cy = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $p, 'year' => 2026, 'status' => 'nominations',
            'nominations_open' => '2026-01-01 00:00:00', 'nominations_close' => '2099-12-31 23:59:59',
        ]);
        DB::table('gates_award_categories')->insert(['cycle_id' => $cy, 'slug' => 'impact', 'title' => 'Impact']);
        DB::table('gates_settings')->whereIn('key_name', ['seed_ran_' . self::SEED, 'seed_last_' . self::SEED])->delete();
        SeedReview::reset(self::SEED);
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_ok'], $_SESSION['flash_error'],
              $_SESSION['seed_old'], $_SESSION['seed_errors']);
        parent::tearDown();
    }

    /** Every field as the form would post it, from the values it would draw. */
    private function form(array $over = []): array
    {
        $v = SeedReview::values(self::SEED);
        foreach ($v as $k => $x) {
            if (is_array($x)) $v[$k] = implode("\n", $x);
        }
        return $over + $v;
    }

    private static function controller(): ChallengesController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(ChallengesController::class);
    }

    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_clock_does_not_add_it_until_somebody_has_checked_it(): void
    {
        $this->assertTrue(SeedReview::required(self::SEED));
        $this->assertFalse(SeedReview::ready(self::SEED));

        SeedRunner::sweep();
        $this->assertNull(CS::bySlug(self::SLUG), 'the hourly sweep published figures nobody had looked at');
        $this->assertSame('review', SeedRunner::status()[self::SEED]['status']);

        SeedReview::approve(self::SEED);
        SeedRunner::sweep();
        $this->assertNotNull(CS::bySlug(self::SLUG), 'approved, and the sweep still did not add it');
    }

    /** What the operator typed is what is published — numbers, words, window, and the sentences built from them. */
    public function test_the_edited_values_are_the_ones_written(): void
    {
        $r = SeedReview::save(self::SEED, $this->form([
            'title' => 'Celebrate Alimosho', 'target' => '5', 'cap' => '20', 'prize_amount' => '5,000',
            'ends_at' => '2026-10-20T23:59:59',
        ]));
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        SeedReview::approve(self::SEED);
        $this->assertSame('done', SeedRunner::run(self::SEED)['status']);

        $c = CS::bySlug(self::SLUG);
        $this->assertSame('Celebrate Alimosho', $c->title);
        $this->assertSame(5, (int) $c->target);
        $this->assertSame(20, (int) $c->cap);
        $this->assertSame(5000, (int) $c->prize_amount);
        $this->assertSame('₦5,000 each', $c->prize_label);
        $this->assertSame('2026-10-20 22:59:59', (string) $c->ends_at, '23:59:59 in Lagos is 22:59:59 UTC');
        $this->assertSame('2026-09-30 23:00:00', (string) $c->starts_at, 'an untouched field kept the handoff value');
        $this->assertSame('Nominate 5 different people for the Alimosho Awards. The first 20 people to get 5 '
            . 'nominees verified win ₦5,000 each.', $c->summary, 'the summary still promised the old numbers');
        $this->assertSame('Know {target} people who make Alimosho proud?', $c->headline,
            'the flier line must keep its placeholder, so it follows the row');
    }

    /** An approval covers the figures that were on the screen, and no others. */
    public function test_changing_a_value_after_approval_withdraws_the_approval(): void
    {
        SeedReview::save(self::SEED, $this->form());
        SeedReview::approve(self::SEED);
        $this->assertTrue(SeedReview::ready(self::SEED));

        SeedReview::save(self::SEED, $this->form(['cap' => '12']));
        $this->assertFalse(SeedReview::ready(self::SEED), 'the approval survived a change to the prize count');

        SeedReview::save(self::SEED, $this->form(['cap' => '12']));
        SeedReview::approve(self::SEED);
        SeedReview::save(self::SEED, $this->form(['cap' => '12']));
        $this->assertTrue(SeedReview::ready(self::SEED), 'saving the same figures again is not a change');
    }

    public function test_a_figure_that_cannot_be_right_is_refused_and_nothing_is_stored(): void
    {
        SeedReview::save(self::SEED, $this->form(['prize_amount' => '7000']));
        $r = SeedReview::save(self::SEED, $this->form([
            'cap' => '0', 'title' => '  ', 'starts_at' => '2026-10-10T00:00:00', 'ends_at' => '2026-10-09T00:00:00',
            'prize_amount' => '8000',
        ]));
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('cap', $r['errors']);
        $this->assertArrayHasKey('title', $r['errors']);
        $this->assertArrayHasKey('ends_at', $r['errors']);
        $this->assertSame(7000, SeedReview::values(self::SEED)['prize_amount'], 'a refused form stored part of itself');
    }

    /** Only what differs is stored, so a correction to the handoff's default still reaches an untouched field. */
    public function test_only_what_was_changed_is_kept(): void
    {
        SeedReview::save(self::SEED, $this->form(['cap' => '15']));
        $this->assertSame(['cap'], SeedReview::edited(self::SEED));
        SeedReview::reset(self::SEED);
        $this->assertSame([], SeedReview::edited(self::SEED));
        $this->assertSame(11, SeedReview::values(self::SEED)['cap']);
    }

    // ══ The screen ═══════════════════════════════════════════════════════════

    public function test_the_screen_draws_the_values_and_the_sentence_they_make(): void
    {
        SeedReview::save(self::SEED, $this->form(['cap' => '15']));
        $html = (string) self::controller()->seedForm(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/challenges/seeds/' . self::SEED),
            new Response(), ['name' => self::SEED])->getBody();

        $this->assertStringContainsString('name="cap" value="15"', $html);
        $this->assertStringContainsString('The original was: 11', $html);
        $this->assertStringContainsString('The first 15 people to get 10 different nominees verified each win ₦6,000.', $html,
            'the preview is not the sentence the page will publish');
        $this->assertStringContainsString('value="2026-10-01T00:00:00"', $html, 'the opening time is not drawn in Lagos time');
        $this->assertStringContainsString('Add the challenge', $html);
    }

    public function test_add_saves_approves_and_publishes_in_one_press(): void
    {
        $ctl = self::controller();
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/challenges/seeds/' . self::SEED)
            ->withParsedBody($this->form(['prize_amount' => '6500', 'add' => '1']));
        $res = $ctl->seedSave($req, new Response(), ['name' => self::SEED]);

        $this->assertSame('/admin/challenges', $res->getHeaderLine('Location'), (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame(6500, (int) CS::bySlug(self::SLUG)->prize_amount);

        // After that, its terms are published: the form refuses to change them.
        unset($_SESSION['flash_ok']);
        $ctl->seedSave((new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withParsedBody($this->form(['prize_amount' => '9000'])), new Response(), ['name' => self::SEED]);
        $this->assertStringContainsString('already been added', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame(6500, (int) CS::bySlug(self::SLUG)->prize_amount);
    }

    public function test_a_refused_form_comes_back_with_what_was_typed_and_why(): void
    {
        $ctl = self::controller();
        $ctl->seedSave((new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withParsedBody($this->form(['cap' => '0', 'title' => 'Kept title'])), new Response(), ['name' => self::SEED]);
        $html = (string) $ctl->seedForm((new ServerRequestFactory())->createServerRequest('GET', '/'),
            new Response(), ['name' => self::SEED])->getBody();
        $this->assertStringContainsString('value="Kept title"', $html, 'the typed values were thrown away');
        $this->assertStringContainsString('Between 1 and 9,999.', $html);
    }
}
