<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\AwardWording;
use AfricaGates\Support\NomineeKind;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The words one award uses about its own people — and the door that writes them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS FEATURE SHIPPED IN, WHICH IS THE ONE WORTH GUARDING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_award_programmes.wording_json` had a reader (`AwardWording::of()`), a
 * validator, a cap under the column's byte ceiling, per-field house fallbacks, and a
 * `save()` with a considered refusal for every way it can go wrong. Every piece was
 * complete and correct in isolation.
 *
 * And nothing called `save()`. There was no form, no route, no link — so the column
 * could only ever answer with the house words, and the nomination form for an award
 * about choirs went on asking for "the nominee's full name" for ever.
 *
 * That is §18's shape, and this codebase has paid for it over a donor's stop button:
 * `manageUrl()` built the link to cancel a monthly gift, `byToken()` resolved it, the
 * page rendered, the controller explained at length why it had to be a link rather
 * than a login — and no receipt and no template ever contained the URL. The
 * distinguishing question is never "does this work?", because every piece did. It is
 * **who is ever handed this?**
 *
 * So the test that matters here is not that `save()` stores a document. It is that a
 * route reaches it and a template links to the route. Both are asserted below, and
 * they are asserted on the REAL router and the REAL template rather than on a string
 * somebody could keep true while deleting the page.
 */
final class AwardWordingTest extends TestCase
{
    private int $programmeId = 0;
    private int $cycleId     = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'tok';

        $this->programmeId = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'carol', 'title' => 'The Carol Awards', 'is_active' => 1,
            'subtitle' => 'For the choirs of the continent',
        ]);
        $this->cycleId = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $this->programmeId, 'year' => 2026, 'status' => 'nominations',
        ]);
        DB::table('gates_award_categories')->insert([
            'cycle_id' => $this->cycleId, 'slug' => 'best-carol', 'title' => 'Carol of the Year',
            'sort_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token'],
              $_SESSION['flash_ok'], $_SESSION['flash_error'], $_SESSION['award_wording_old']);
        parent::tearDown();
    }

    private function controller(): \AfricaGates\Admin\Controllers\ProgrammesController
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $builder->build()->get(\AfricaGates\Admin\Controllers\ProgrammesController::class);
    }

    private function view(): string
    {
        $req = (new ServerRequestFactory())->createServerRequest(
            'GET', '/admin/programmes/' . $this->programmeId . '/wording');

        return (string) $this->controller()
            ->wording($req, new Response(), ['id' => (string) $this->programmeId])->getBody();
    }

    private function save(array $body): Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/programmes/' . $this->programmeId . '/wording')
            ->withParsedBody($body);

        /** @var Response $r */
        $r = $this->controller()->wordingSave($req, new Response(), ['id' => (string) $this->programmeId]);
        return $r;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // THE DOOR — the half that was missing
    // ══════════════════════════════════════════════════════════════════════════

    public function test_a_route_reaches_the_writer_and_a_template_links_to_the_route(): void
    {
        // ASKED OF THE ROUTER, not of `routes.php` as text. The API group is mounted
        // twice and the admin group's own proxy variable is shared with two other
        // groups, so a parser reads a hundred and ninety-two routes that exist as
        // absent and reports routes that do not exist as present.
        $paths = [];
        foreach ($this->routes() as $route) {
            foreach ($route->getMethods() as $m) $paths[] = $m . ' ' . $route->getPattern();
        }

        $this->assertContains('GET /admin/programmes/{id:[0-9]+}/wording', $paths,
            'the wording screen has no route, so the column has a reader and no writer');
        $this->assertContains('POST /admin/programmes/{id:[0-9]+}/wording', $paths,
            'the wording screen cannot be saved');

        // And a page nobody can reach without typing its URL is a page nobody reaches.
        // `AdminIaTest` asks this of every admin page; it is asked HERE too because
        // this one page is the only door to the column, so losing the link is losing
        // the feature rather than losing a shortcut.
        $form = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/admin/programmes/form.twig');
        $this->assertStringContainsString('/wording', $form,
            'the programme form no longer links to the wording page, which is its only door');
    }

    /** @return list<\Slim\Interfaces\RouteInterface> */
    private function routes(): array
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $container = $builder->build();
        $app = \Slim\Factory\AppFactory::createFromContainer($container);
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        return array_values($app->getRouteCollector()->getRoutes());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // THE SCREEN
    // ══════════════════════════════════════════════════════════════════════════

    public function test_every_field_in_the_table_is_on_the_form_with_its_own_cap(): void
    {
        $html = $this->view();

        // DERIVED from the table, so a field added to `FIELDS` and forgotten here
        // fails rather than posting happily and never being read.
        foreach (AwardWording::FIELDS as $key => $f) {
            $this->assertStringContainsString('name="' . $key . '"', $html,
                "`$key` is in the field table and not on the form");
            $this->assertStringContainsString($f['label'], $html);
        }

        // The `maxlength` is the writer's own cap. A form that stops at one number
        // while `save()` refuses at another is this codebase's most expensive shape,
        // so the number is never typed here — it is read from the same table.
        $this->assertStringContainsString(
            'maxlength="' . AwardWording::FIELDS['nominee_noun']['max'] . '"', $html);
    }

    public function test_the_boxes_are_empty_where_the_award_has_said_nothing(): void
    {
        $html = $this->view();

        // The house word belongs in the PLACEHOLDER, not in the value. Prefilled, an
        // operator who clears one box to "reset it" has stored an empty string, and
        // every award that touched this screen would store seven words identical to
        // the defaults — at which point `isCustom()` says yes about every award.
        $this->assertStringContainsString('placeholder="nominee"', $html);
        $this->assertStringNotContainsString('name="nominee_noun" value="nominee"',
            str_replace(["\n", '  '], ' ', $html));
    }

    public function test_the_preview_uses_a_real_category_from_this_award(): void
    {
        // A preview built on "Category name" shows an operator a sentence that will
        // never be printed, and how it reads with their own words in it is the one
        // thing they are on this page to judge.
        $this->assertStringContainsString('Carol of the Year', $this->view());
    }

    public function test_the_page_states_which_words_the_award_is_using(): void
    {
        $this->assertStringContainsString('house words', $this->view());

        $this->save(['nominee_noun' => 'choir']);
        $this->assertStringContainsString('its own words', $this->view());
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVING
    // ══════════════════════════════════════════════════════════════════════════

    public function test_saving_reaches_the_store_and_the_nomination_form_reads_it(): void
    {
        $this->save([
            'nominee_noun'        => 'choir',
            'nominee_noun_plural' => 'choirs',
            'who_question'        => 'Which choir are you putting forward?',
            'reason_question'     => 'Why {name} for {category}?',
        ]);

        $row = DB::table('gates_award_programmes')->where('id', $this->programmeId)->first();
        $w   = AwardWording::of($row);

        $this->assertSame('choir', $w['nominee_noun']);
        $this->assertSame('Which choir are you putting forward?', $w['who_question']);
        // Untouched fields keep the house word rather than becoming empty strings.
        $this->assertSame(AwardWording::FIELDS['evidence_hint']['default'], $w['evidence_hint']);
    }

    public function test_a_refusal_hands_everything_back_rather_than_emptying_the_form(): void
    {
        $long = str_repeat('a', AwardWording::FIELDS['nominee_noun']['max'] + 5);

        $this->save(['nominee_noun' => $long, 'nominee_noun_plural' => 'choirs']);

        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
        // A refusal that empties seven boxes is a refusal somebody answers by giving
        // up — and the OTHER six values were never the problem.
        $this->assertSame('choirs',
            $_SESSION['award_wording_old'][$this->programmeId]['nominee_noun_plural'] ?? null);

        // And nothing was written.
        $row = DB::table('gates_award_programmes')->where('id', $this->programmeId)->first();
        $this->assertSame('nominee', AwardWording::of($row)['nominee_noun']);
    }

    public function test_the_rejected_values_are_keyed_to_their_own_award(): void
    {
        // Two awards edited in two tabs must not prefill each other. That is the
        // `reg_old` fault the registration form shipped with: one session key for two
        // branches, and a rejected organisation name appearing in a person's Full
        // name box. Nothing threw and nothing looked wrong, which is why it needs
        // keying rather than remembering.
        $other = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Principal Awards', 'is_active' => 1,
        ]);

        $this->save(['nominee_noun' => str_repeat('a', 99)]);

        $this->assertArrayHasKey($this->programmeId, $_SESSION['award_wording_old']);
        $this->assertArrayNotHasKey($other, $_SESSION['award_wording_old']);
    }

    public function test_no_kinds_ticked_means_every_kind_rather_than_none(): void
    {
        $this->save(['nominee_noun' => 'choir', AwardWording::KINDS_KEY => []]);

        $row = DB::table('gates_award_programmes')->where('id', $this->programmeId)->first();

        // An award accepting nobody cannot be nominated for and nothing on any screen
        // would say why — the kind chips would simply all be missing.
        $this->assertSame(
            array_keys(NomineeKind::ALL),
            AwardWording::of($row)[AwardWording::KINDS_KEY]);
    }

    public function test_a_narrower_set_of_kinds_is_stored_and_read_back(): void
    {
        $this->save([
            'nominee_noun' => 'choir',
            AwardWording::KINDS_KEY => ['organisation', 'nonsense'],
        ]);

        $row = DB::table('gates_award_programmes')->where('id', $this->programmeId)->first();

        $this->assertSame(['organisation'], AwardWording::of($row)[AwardWording::KINDS_KEY]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // THE DRAFT
    // ══════════════════════════════════════════════════════════════════════════

    public function test_the_draft_endpoint_writes_nothing_and_says_why_it_cannot(): void
    {
        // No provider is configured in the suite, so this pins the shape a deployment
        // with no AI key meets: a refusal an operator can act on, and — the half that
        // matters — no change to the stored document. An operator who pressed Draft
        // and got silence would retype seven phrases believing the button is dead.
        $before = DB::table('gates_award_programmes')->where('id', $this->programmeId)
            ->value('wording_json');

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/ai/award-wording')
            ->withParsedBody(['programme_id' => $this->programmeId]);

        $res = (new \AfricaGates\Admin\Controllers\AiAssistController())
            ->awardWording($req, new Response());

        $body = json_decode((string) $res->getBody(), true);

        $this->assertFalse($body['ok'] ?? true);
        $this->assertNotEmpty($body['error'] ?? '', 'a refusal with no sentence is a dead button');
        $this->assertSame($before, DB::table('gates_award_programmes')
            ->where('id', $this->programmeId)->value('wording_json'),
            'the draft endpoint stored something — it may only fill the boxes');
    }

    public function test_the_capability_is_declared_and_is_not_in_the_public_disclosure(): void
    {
        $cap = \AfricaGates\Services\AiCapability::all()['admin.award_wording'] ?? null;

        $this->assertNotNull($cap, 'undeclared, so the gateway refuses every call');
        $this->assertTrue($cap->advisory, 'a draft decides nothing; an operator saves it');
        // FALSE here, and this is NOT the misreading `nomination.category_fit` shipped
        // with. The flag means "processes content submitted by the PUBLIC". What goes
        // out here is an award's own title and description, written by an operator in
        // this console — no visitor's words are in the payload, so it does not belong
        // in the disclosure `AiPrivacy` generates from this flag.
        $this->assertFalse($cap->publicContent);
        $this->assertSame(\AfricaGates\Services\AiCapability::FAIL_ANNOUNCE, $cap->onFailure,
            'silence would have an operator retype seven phrases believing the button is dead');
    }
}
