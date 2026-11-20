<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ChallengesController;
use AfricaGates\Services\ChallengeService as CS;
use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * The challenge builder, posted back exactly as it is drawn.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FORM IS THE FIXTURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "New challenge" answered 500 on production. `ChallengeAdminTest` passed throughout,
 * because it calls `create()` with arrays somebody typed — and nobody types an array
 * with a blank `prize_amount`, a blank `slug` and a blank `cap` in it, which is exactly
 * what the browser sends for a new challenge the moment somebody saves before deciding
 * the prize. Three faults were on that one road:
 *
 *   · every blank box became NULL, and `prize_amount` is NOT NULL — the 500;
 *   · the blank slug was taken instead of the title (`??` does not fall back on an
 *     empty string), so with a prize filled in it was refused as "give it a title";
 *   · the dates were drawn in Lagos time and saved as if they were UTC, so every save
 *     moved them an hour, and a published challenge then refused its own unchanged form
 *     because `starts_at` is locked.
 *
 * So these cases render the real form, read every field and its drawn value OUT OF THE
 * HTML, and post that back through the real controller. A field added to the template
 * is in the next run without anybody remembering to add it here.
 */
final class ChallengeBuilderFormTest extends TestCase
{
    private int $cycle = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DisplayTime::forget();
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $p = (int) DB::table('gates_award_programmes')->insertGetId(
            ['title' => 'Builder probe', 'slug' => 'bp-' . bin2hex(random_bytes(3)), 'is_active' => 1]);
        $this->cycle = (int) DB::table('gates_award_cycles')->insertGetId(
            ['programme_id' => $p, 'year' => 2026, 'status' => 'nominations']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_error'], $_SESSION['flash_ok'],
              $_SESSION['challenge_draft']);
        parent::tearDown();
    }

    private function ctrl(): ChallengesController
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(ChallengesController::class);
    }

    private function formHtml(int $id = 0): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/challenges/' . ($id ?: 'new'));
        $res = $this->ctrl()->form($req, new Response(), $id ? ['id' => $id] : []);
        self::assertSame(200, $res->getStatusCode());
        return (string) $res->getBody();
    }

    /**
     * Every control in the builder's main form, with the value the browser would send
     * untouched: an input's `value`, a select's selected option (or its first), a
     * textarea's text, a checkbox only when checked.
     *
     * @return array<string,mixed>
     */
    private function fields(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8">' . $html);
        $xp = new \DOMXPath($dom);
        $form = null;
        foreach ($xp->query('//form') as $f) {
            if ($xp->query('.//*[@name="title"]', $f)->length > 0) { $form = $f; break; }
        }
        self::assertNotNull($form, 'the builder form was not found');

        $out = [];
        foreach ($xp->query('.//input|.//select|.//textarea', $form) as $el) {
            /** @var \DOMElement $el */
            $name = $el->getAttribute('name');
            if ($name === '' || $name === '_token' || $el->hasAttribute('disabled')) continue;
            $type = strtolower($el->getAttribute('type'));
            if (in_array($type, ['submit', 'button'], true)) continue;
            if (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) continue;

            $value = match ($el->nodeName) {
                'textarea' => $el->textContent,
                'select'   => (function () use ($xp, $el) {
                    $sel = $xp->query('.//option[@selected]', $el);
                    $opt = $sel->length ? $sel->item(0) : $xp->query('.//option', $el)->item(0);
                    return $opt ? ($opt->hasAttribute('value') ? $opt->getAttribute('value') : $opt->textContent) : '';
                })(),
                default => $el->hasAttribute('value') ? $el->getAttribute('value') : ($type === 'checkbox' ? 'on' : ''),
            };
            if (str_ends_with($name, '[]')) {
                if ($el->nodeName === 'select') continue; // nothing selected in a multiple select sends nothing
                $out[substr($name, 0, -2)][] = $value;
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    private function post(array $body, int $id = 0): Response
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/challenges/' . ($id ?: 'new'))
            ->withParsedBody($body);
        return $this->ctrl()->save($req, new Response(), $id ? ['id' => $id] : []);
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** The 500: a new challenge saved with nothing but a title and a kicker typed in. */
    public function test_the_new_form_saves_as_drawn_with_only_a_title_and_a_kicker(): void
    {
        $body = ['title' => 'Teachers Day Thank-you', 'kicker' => 'A thank-you challenge'] + $this->fields($this->formHtml());
        $body['title'] = 'Teachers Day Thank-you';
        $body['kicker'] = 'A thank-you challenge';
        self::assertSame('', $body['prize_amount'] ?? null, 'the premise: a new form posts a blank prize');
        self::assertSame('', $body['slug'] ?? null, 'the premise: a new form posts a blank web address');

        $res = $this->post($body);

        self::assertSame(302, $res->getStatusCode());
        self::assertArrayNotHasKey('flash_error', $_SESSION, (string) ($_SESSION['flash_error'] ?? ''));
        $c = CS::bySlug('teachers-day-thank-you');
        self::assertNotNull($c, 'the web address did not come from the title');
        self::assertSame(E::ST_DRAFT, $c->status);
        self::assertSame(0, (int) $c->prize_amount);
        self::assertSame('Africa/Lagos', $c->timezone);
    }

    /** A blank title is still refused, with words, not a 500. */
    public function test_a_new_form_with_no_title_is_refused_in_words(): void
    {
        $body = $this->fields($this->formHtml());
        $body['title'] = '';
        $res = $this->post($body);
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('Give it a title first.', $_SESSION['flash_error'] ?? null);
    }

    /**
     * Open a saved challenge and press save without touching anything: nothing moves.
     * Before, every date drifted an hour per save.
     */
    public function test_saving_the_form_unchanged_moves_no_date(): void
    {
        $this->post(['title' => 'Round trip', 'kicker' => 'K', 'starts_at' => '2026-10-01T00:00:00',
                     'ends_at' => '2026-10-15T23:59:59', 'timezone' => 'Africa/Lagos']);
        $c = CS::bySlug('round-trip');
        self::assertSame('2026-09-30 23:00:00', $c->starts_at, '00:00 in Lagos is 23:00 UTC the day before');
        self::assertSame('2026-10-15 22:59:59', $c->ends_at);

        for ($i = 0; $i < 3; $i++) {
            $res = $this->post($this->fields($this->formHtml((int) $c->id)), (int) $c->id);
            self::assertSame(302, $res->getStatusCode());
        }
        $after = CS::find((int) $c->id);
        self::assertSame('2026-09-30 23:00:00', $after->starts_at, 'opening the form and saving moved the start');
        self::assertSame('2026-10-15 22:59:59', $after->ends_at, 'opening the form and saving moved the close');
    }

    /** A published challenge saved from its own form, untouched, is not "changing the rules". */
    public function test_a_published_challenge_saves_from_its_own_form(): void
    {
        $this->post(['title' => 'Live one', 'kicker' => 'K', 'action' => E::ACTION_NOMINATE, 'target' => '10',
                     'mode' => E::MODE_FIRST, 'cap' => '11', 'prize_type' => E::PRIZE_CASH_EACH,
                     'prize_amount' => '6000', 'prize_currency' => '₦', 'terms_version' => '1.0',
                     'starts_at' => date('Y-m-d\TH:i:s', strtotime('-1 day')),
                     'ends_at' => date('Y-m-d\TH:i:s', strtotime('+14 days')),
                     'scope' => [E::SCOPE_CYCLE . ':' . $this->cycle]]);
        $c = CS::bySlug('live-one');
        \AfricaGates\Services\ChallengeAdmin::setScopes((int) $c->id, [['scope_type' => E::SCOPE_CYCLE, 'scope_id' => $this->cycle]]);
        $pub = \AfricaGates\Services\ChallengeAdmin::publish((int) $c->id);
        self::assertTrue($pub['ok'] ?? false, json_encode($pub));
        unset($_SESSION['flash_error']);

        $body = $this->fields($this->formHtml((int) $c->id));
        $body['summary'] = 'A better summary';
        $this->post($body, (int) $c->id);

        self::assertArrayNotHasKey('flash_error', $_SESSION, (string) ($_SESSION['flash_error'] ?? ''));
        self::assertSame('A better summary', CS::find((int) $c->id)->summary);
    }

    /**
     * The flier's columns arrive by migration, which an operator applies by opening a
     * URL. Until then the builder still saves — it does not name a column the database
     * does not have.
     */
    public function test_the_builder_saves_on_a_database_without_the_flier_columns(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') self::markTestSkipped('drops a column; SQLite only');
        DB::statement('ALTER TABLE gates_challenges DROP COLUMN headline');
        \AfricaGates\Support\SchemaHas::forget();
        try {
            $body = $this->fields($this->formHtml());
            $body['title'] = 'Before the migration';
            $body['kicker'] = 'K';
            $body['headline'] = 'A line';
            $res = $this->post($body);
            self::assertSame(302, $res->getStatusCode());
            self::assertNotNull(CS::bySlug('before-the-migration'));
        } finally {
            \AfricaGates\Support\SchemaHas::forget();
        }
    }
}
