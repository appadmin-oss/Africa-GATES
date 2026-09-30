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
    private function render(string $class, string $method, string $path, array $args = []): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get($class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $out  = $args === []
            ? $ctrl->$method($req, new Response())
            : $ctrl->$method($req, new Response(), $args);
        return (string) $out->getBody();
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

    /**
     * The nomination form, which is `/nominate/{slug}` now.
     *
     * `/nominate` used to be the whole wizard; it is the award chooser now, and the
     * form — with its own wording, categories and accepted kinds — is on each award's
     * page. The GUARANTEES below are unchanged: every control has an accessible name,
     * the reason box is named by the heading a sighted person reads, and repeated
     * inputs are named individually. Only the URL moved.
     */
    private function nominationForm(): string
    {
        return $this->render(
            \AfricaGates\Controllers\NominationController::class, 'award', '/nominate/rising',
            ['slug' => 'rising']
        );
    }

    public function test_the_nomination_form_has_no_unlabelled_field(): void
    {
        // The form that matters most. Five of its controls had no accessible name.
        $this->openForNominations();

        $html = $this->nominationForm();
        $this->assertStringContainsString('name="nominee_name"', $html,
            'the form must render, or this proves nothing');

        $this->assertSame([], $this->unnamedControls($html));
    }

    public function test_each_reason_textarea_is_named_by_the_question_above_it(): void
    {
        // The accessible name is the text a sighted person actually reads, and here
        // that text is the award's own question — "Why Ada for Teaching?" — which is a
        // `<label for>` rather than an invented `aria-label`. `for`/`id` is the plainer
        // mechanism and the one that also makes the question clickable.
        $this->openForNominations();

        $html = $this->nominationForm();

        $this->assertMatchesRegularExpression('~<label[^>]*for="nf-why-\d+"~', $html);
        $this->assertMatchesRegularExpression('~<textarea[^>]*id="nf-why-\d+"~', $html);
    }

    public function test_each_evidence_link_input_is_named_individually(): void
    {
        // One group label cannot name five controls. Without individual names a screen
        // reader announces "edit text" five times, indistinguishable — which is why the
        // three `reference_url` boxes this replaced each carried their own.
        $this->openForNominations();

        $html = $this->nominationForm();

        preg_match_all('~<input[^>]*name="evidence_links\[\]"[^>]*>~', $html, $m);
        $this->assertNotEmpty($m[0], 'the evidence inputs must render');

        foreach ($m[0] as $i => $tag) {
            $this->assertMatchesRegularExpression(
                '~aria-label="[^"]+"~', $tag,
                'evidence link input ' . ($i + 1) . ' has no accessible name'
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
