<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Every form control must have a programmatic accessible name.
 *
 * A browser sweep found four real defects that no PHPUnit test could see, because
 * they are absences rather than errors — the page renders perfectly and is simply
 * unusable with a screen reader:
 *
 *  • The `reason` textarea — the single most important field on the platform — had
 *    `id="nWhy"` and no `<label for="nWhy">` anywhere. Announced with no name.
 *  • The three reference-URL inputs had no ids and shared one group label, which
 *    cannot name three separate controls. Announced as "edit text" three times with
 *    nothing to tell them apart.
 *  • The evidence file input's label had no `for` and the input had no `id`.
 *  • The shop card's cover was a SECOND unnamed link to the same product, so screen
 *    readers announced a nameless "link" and keyboard users hit a dead stop on every
 *    card.
 *
 * A `<label>` sitting visually above a field is not a label. That is exactly why
 * these survived review: the pages look correct.
 */
class FormAccessibilityTest extends TestCase
{
    private function render(string $class, string $method, string $path): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get($class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return (string) $ctrl->$method($req, new Response())->getBody();
    }

    private function openForNominations(): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'rising', 'title' => 'Rising Voices', 'is_active' => 1,
            'sort_order' => 1, 'description' => 'For emerging talent.',
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => $pid, 'year' => (int) date('Y'), 'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        DB::table('gates_award_categories')->insert([
            'cycle_id' => $cid, 'slug' => 'newcomer', 'title' => 'Newcomer', 'sort_order' => 1,
        ]);
    }

    /**
     * Controls with no accessible name, judged the way a browser judges it.
     *
     * @return list<string>
     */
    private function unnamedControls(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $x = new \DOMXPath($doc);

        // Which ids a <label for="…"> points at.
        $labelled = [];
        foreach ($x->evaluate('//label[@for]') as $l) {
            $labelled[$l->getAttribute('for')] = true;
        }

        $out = [];
        foreach ($x->evaluate('//input | //select | //textarea') as $el) {
            $type = strtolower($el->getAttribute('type'));
            if (in_array($type, ['hidden', 'submit', 'button', 'reset'], true)) continue;
            if ($el->getAttribute('aria-label') !== '' || $el->getAttribute('aria-labelledby') !== '') continue;
            $id = $el->getAttribute('id');
            if ($id !== '' && isset($labelled[$id])) continue;
            // A control wrapped in its own <label> is named by it.
            $wrapped = false;
            for ($p = $el->parentNode; $p !== null; $p = $p->parentNode) {
                if ($p->nodeName === 'label') { $wrapped = true; break; }
            }
            if ($wrapped) continue;
            $out[] = $el->nodeName . '[name=' . ($el->getAttribute('name') ?: '?') . ']';
        }
        return $out;
    }

    public function test_the_nomination_wizard_has_no_unlabelled_field(): void
    {
        // The form that matters most. Five of its controls had no accessible name.
        $this->openForNominations();

        $html = $this->render(\AfricaGates\Controllers\NominationController::class, 'form', '/nominate');
        $this->assertStringContainsString('x-data', $html, 'the wizard must render, or this proves nothing');

        $this->assertSame([], $this->unnamedControls($html));
    }

    public function test_the_reason_textarea_is_named_by_its_visible_heading(): void
    {
        // aria-labelledby the heading rather than an invented aria-label: the
        // accessible name should be the text a sighted user actually reads.
        $this->openForNominations();

        $html = $this->render(\AfricaGates\Controllers\NominationController::class, 'form', '/nominate');

        $this->assertMatchesRegularExpression('~id="nWhyHeading"~', $html);
        $this->assertMatchesRegularExpression('~<textarea[^>]*aria-labelledby="nWhyHeading"~', $html);
    }

    public function test_each_reference_url_input_is_named_individually(): void
    {
        // One group label cannot name three controls. Without individual names a
        // screen reader announces "edit text" three times, indistinguishable.
        $this->openForNominations();

        $html = $this->render(\AfricaGates\Controllers\NominationController::class, 'form', '/nominate');

        foreach (['reference_url', 'reference_url_2', 'reference_url_3'] as $n) {
            $this->assertMatchesRegularExpression(
                '~<input[^>]*name="' . $n . '"[^>]*aria-label="[^"]+"|<input[^>]*aria-label="[^"]+"[^>]*name="' . $n . '"~',
                $html,
                "{$n} has no accessible name"
            );
        }
    }

    public function test_the_public_pages_have_no_unlabelled_control(): void
    {
        // Sweep the surfaces a visitor actually meets, so a new form cannot ship
        // without names.
        DB::table('gates_profiles')->insert([
            'slug' => 'ada', 'display_name' => 'Ada Obi', 'email' => 'ada@example.com',
        ]);

        foreach ([
            [\AfricaGates\Controllers\RegistryController::class, 'index', '/registry'],
            [\AfricaGates\Controllers\LeaderboardController::class, 'index', '/leaderboard'],
            [\AfricaGates\Controllers\AwardsController::class, 'index', '/awards'],
        ] as [$class, $method, $path]) {
            $this->assertSame([], $this->unnamedControls($this->render($class, $method, $path)),
                "{$path} has a control with no accessible name");
        }
    }

    public function test_a_decorative_duplicate_link_is_hidden_from_assistive_tech(): void
    {
        // The shop card links to the same product twice: a named title link and an
        // empty cover. The cover is now aria-hidden with tabindex=-1, so screen
        // readers announce one link per product and keyboard users get one stop.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/shop/index.twig');

        $this->assertMatchesRegularExpression(
            '~<a class="sh-card__cover"[^>]*aria-hidden="true"[^>]*tabindex="-1"~',
            $src
        );
    }
}
