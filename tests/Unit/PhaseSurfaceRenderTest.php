<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Every lifecycle-rendering surface must show the right state for the phase it
 * is actually in. Two bugs these tests exist to prevent from returning:
 *
 *  • /awards/{slug} read `a.cycle_status`, which the service never returned, so
 *    `status` was permanently 'upcoming' and the "Cast a vote" / "Submit a
 *    nomination" CTAs never rendered in ANY phase. Twig's non-strict mode made
 *    that silent — no error, green suite, dead page.
 *  • /nominate fell back to listing EVERY programme when none were open (Twig's
 *    `default` filter fires on an empty value), so users completed four steps
 *    and were rejected at submit.
 */
class PhaseSurfaceRenderTest extends TestCase
{
    private function seedProgramme(string $slug, array $cycle): void
    {
        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => $slug, 'title' => ucfirst($slug) . ' Awards', 'is_active' => 1,
            'sort_order' => 1, 'description' => 'A programme.',
        ]);
        $cid = (int) DB::table('gates_award_cycles')->insertGetId(array_merge([
            'programme_id' => $pid, 'year' => (int) date('Y'),
        ], $cycle));
        DB::table('gates_award_categories')->insert([
            'cycle_id' => $cid, 'slug' => 'music', 'title' => 'Music', 'sort_order' => 1,
        ]);
    }

    /** @return array{0:int,1:string} */
    private function render(string $class, string $method, string $path, array $args = []): array
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $container = $builder->build();

        $ctrl = $container->get($class);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $out  = $args === [] ? $ctrl->$method($req, new Response()) : $ctrl->$method($req, new Response(), $args);
        return [$out->getStatusCode(), (string) $out->getBody()];
    }

    private function openVoting(): array
    {
        return [
            'status'       => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('+20 days')),
        ];
    }

    private function openNominations(): array
    {
        return [
            'status'            => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-2 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
            'voting_open'       => date('Y-m-d H:i:s', strtotime('+12 days')),
            'voting_close'      => date('Y-m-d H:i:s', strtotime('+30 days')),
        ];
    }

    // ── /awards/{slug} ───────────────────────────────────────────────────────

    public function test_programme_page_offers_voting_when_voting_is_open(): void
    {
        $this->seedProgramme('creative', $this->openVoting());

        [$status, $body] = $this->render(
            \AfricaGates\Controllers\AwardsController::class, 'programme',
            '/awards/creative', ['p' => 'creative']
        );

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Cast a vote', $body, 'the voting CTA must render during voting');
        $this->assertStringContainsString('/vote/creative', $body, 'and deep-link to THIS programme, not the hub');
        $this->assertStringContainsString('Voting open', $body, 'the phase must be stated');
    }

    public function test_programme_page_offers_nominating_when_nominations_are_open(): void
    {
        $this->seedProgramme('impact', $this->openNominations());

        [, $body] = $this->render(
            \AfricaGates\Controllers\AwardsController::class, 'programme',
            '/awards/impact', ['p' => 'impact']
        );

        $this->assertStringContainsString('Submit a nomination', $body);
        $this->assertStringNotContainsString('Cast a vote', $body, 'and must not offer voting yet');
    }

    public function test_programme_page_offers_results_when_published(): void
    {
        $this->seedProgramme('legacy', [
            'status'       => 'results',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-40 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-20 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
        ]);

        [, $body] = $this->render(
            \AfricaGates\Controllers\AwardsController::class, 'programme',
            '/awards/legacy', ['p' => 'legacy']
        );

        $this->assertStringContainsString('See the results', $body);
        $this->assertStringContainsString('Results published', $body);
        $this->assertStringNotContainsString('Cast a vote', $body, 'a finished cycle is not votable');
    }

    public function test_programme_page_states_the_phase_even_with_no_action_available(): void
    {
        // Shortlisting: nothing to do, but the page must not be a dead end.
        $this->seedProgramme('gap', [
            'status'            => 'shortlisting',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'voting_open'       => date('Y-m-d H:i:s', strtotime('+10 days')),
            'voting_close'      => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        [, $body] = $this->render(
            \AfricaGates\Controllers\AwardsController::class, 'programme',
            '/awards/gap', ['p' => 'gap']
        );

        $this->assertStringContainsString('Shortlisting', $body);
        $this->assertStringContainsString('Voting opens', $body, 'and say when the next action arrives');
        $this->assertStringNotContainsString('Cast a vote', $body);
    }

    public function test_programme_page_does_not_hardcode_a_cycle_year(): void
    {
        $this->seedProgramme('yearly', $this->openVoting());
        DB::table('gates_award_cycles')->update(['year' => 2031]);

        [, $body] = $this->render(
            \AfricaGates\Controllers\AwardsController::class, 'programme',
            '/awards/yearly', ['p' => 'yearly']
        );

        $this->assertStringContainsString('2031 Cycle', $body);
        $this->assertStringNotContainsString('2026 Cycle', $body, 'the eyebrow used to be a literal');
    }

    // ── /nominate ────────────────────────────────────────────────────────────

    public function test_nominate_shows_the_wizard_when_a_programme_is_open(): void
    {
        $this->seedProgramme('impact', $this->openNominations());

        [$status, $body] = $this->render(
            \AfricaGates\Controllers\NominationController::class, 'form', '/nominate'
        );

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Who are you nominating?', $body, 'the wizard must be present');
        $this->assertStringContainsString('Impact Awards', $body, 'and list the open programme');
        $this->assertStringNotContainsString('Nominations are closed right now', $body);
    }

    public function test_nominate_never_offers_a_closed_programme(): void
    {
        // One open, one mid-voting. Only the open one may be pickable — offering
        // the other guarantees a rejection after four completed steps.
        $this->seedProgramme('impact', $this->openNominations());
        $this->seedProgramme('creative', $this->openVoting());

        [, $body] = $this->render(
            \AfricaGates\Controllers\NominationController::class, 'form', '/nominate'
        );

        $this->assertStringContainsString('Impact Awards', $body);
        $this->assertStringNotContainsString('Creative Awards', $body,
            'a programme in its voting phase must not appear in the nomination picker');
    }

    public function test_nominate_replaces_the_wizard_with_a_real_closed_state(): void
    {
        // Nothing open at all: the form must not be in the DOM, because there is
        // nothing the user could fill in that would be accepted.
        $this->seedProgramme('creative', $this->openVoting());

        [$status, $body] = $this->render(
            \AfricaGates\Controllers\NominationController::class, 'form', '/nominate'
        );

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Nominations are closed right now', $body);
        $this->assertStringNotContainsString('Who are you nominating?', $body,
            'the wizard must be absent, not merely hidden');
        // And it must still tell the visitor what IS happening, plus a next step.
        $this->assertStringContainsString('Voting open', $body, 'report the other programmes\' phases');
        $this->assertStringContainsString('/leaderboard', $body, 'and always offer a next action');
    }

    public function test_nominate_closed_state_names_the_next_opening_date(): void
    {
        $this->seedProgramme('future', [
            'status'            => 'upcoming',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('+30 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+60 days')),
        ]);

        [, $body] = $this->render(
            \AfricaGates\Controllers\NominationController::class, 'form', '/nominate'
        );

        $this->assertStringContainsString('Nominations are closed right now', $body);
        $this->assertStringContainsString(date('j F Y', strtotime('+30 days')), $body,
            'a visitor should not have to guess when to come back');
    }

    public function test_a_stale_status_column_does_not_open_the_nomination_wizard(): void
    {
        // The column still says 'nominations'; the close date passed a week ago.
        $this->seedProgramme('stale', [
            'status'            => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('-7 days')),
            'voting_open'       => date('Y-m-d H:i:s', strtotime('+7 days')),
            'voting_close'      => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        [, $body] = $this->render(
            \AfricaGates\Controllers\NominationController::class, 'form', '/nominate'
        );

        $this->assertStringContainsString('Nominations are closed right now', $body,
            'the published close date must bind the UI, not just the write path');
        $this->assertStringNotContainsString('Who are you nominating?', $body);
    }
}
