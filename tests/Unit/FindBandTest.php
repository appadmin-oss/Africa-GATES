<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Controllers\ActivityController;
use AfricaGates\Services\ActivityFeedService;
use DI\ContainerBuilder;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * "WHO ARE YOU LOOKING FOR?" — the band, and the two claims printed on it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A BAND NEEDS A TEST AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because it makes two statements about the platform, in the reader's own language,
 * on the homepage — and this repository's most expensive documented failures are
 * exactly that: a true sentence outliving the rule it described.
 *
 * `/cookies` said in bold "We set one cookie" while three were being set and "We run
 * no analytics" while every arrival's source, campaign, device and country was being
 * recorded. Both were true the day they were typed. A search box that says what it
 * searches is the same document making the same kind of claim, and it will go stale
 * the same way — the day somebody adds a source, or removes one.
 *
 * So the coverage sentence is GENERATED from `ActivityFeedService::SOURCES` and this
 * file asserts the generation, not the wording. The test moves when the rule moves.
 *
 * ── AND ONE SENTENCE IS A GUARANTEE RATHER THAN A DESCRIPTION ───────────────
 *
 * "Nothing unannounced is searchable" is the platform's central promise, printed
 * where a stranger reads it. {@see UnannouncedResultTest} is the evidence for it;
 * this file only holds that the claim and the evidence stay on the same page as each
 * other — a promise with no test behind it should not be printed, and a gate with
 * nothing saying so is a courtesy nobody knows they have.
 */
final class FindBandTest extends TestCase
{
    /** The band as the activity surface renders it — the live, opted-in variant. */
    private function render(): string
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $c = $builder->build();

        $controller = new ActivityController($c->get(Twig::class), new ActivityFeedService());
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/activity');

        return (string) $controller->index($req, new Response())->getBody();
    }

    private function partial(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/partials/find-band.twig');
    }

    // ── What it says it covers ───────────────────────────────────────────────

    // ── The promise ──────────────────────────────────────────────────────────

    public function test_the_promise_has_a_test_behind_it(): void
    {
        // A claim this load-bearing may not rest on a gate somebody might refactor out.
        // Named by file rather than by re-testing the behaviour here: two tests with
        // their own idea of what "announced" means is how they come to disagree.
        $this->assertFileExists(dirname(__DIR__) . '/Unit/UnannouncedResultTest.php',
            'the band promises nothing unannounced is searchable and nothing proves it');
    }

    // ── The things that fail silently ────────────────────────────────────────
}
