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

    /**
     * A fixture date as the PAGE will print it — in the display zone, not the server's.
     *
     * ── THE ONE-HOUR-A-DAY FAILURE THIS ENDS ────────────────────────────────
     *
     * These assertions used `date('j M Y', strtotime('+10 days'))`, which is the SERVER's
     * day. The fixtures store their windows in UTC and every template renders them through
     * `when_zoned` into Africa/Lagos — so between 23:00 and midnight UTC the two are
     * different dates and three tests in this file failed, for one hour in twenty-four,
     * for a reason that had nothing to do with what they were testing.
     *
     * Caught at 23:12 UTC: stored `2026-09-03 23:12`, page printed "4 Sep 2026, 00:12",
     * assertion wanted "3 Sep 2026". The page was right.
     *
     * Reading the zone from {@see \AfricaGates\Support\DisplayTime} rather than naming
     * Africa/Lagos here, because that is where the templates read it from: an operator who
     * changes the display zone must not have to fix this file too.
     */
    private function asRendered(string $relative, string $format): string
    {
        return (new \DateTimeImmutable(date('Y-m-d H:i:s', strtotime($relative)),
                                       new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(\AfricaGates\Support\DisplayTime::zone()))
            ->format($format);
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

        // Asserted against the EYEBROW, not against the whole document.
        //
        // Scanning the entire page for "<this year> Cycle" also scanned the
        // announcement bar, whose text is operator-authored and legitimately says
        // things like "Nominations open — 2026 Cycle". Under SQLite the settings
        // read returns nothing and the bar falls back to a year-free default, so
        // this passed; under MySQL the read succeeds, `.env`'s ANNOUNCE_TEXT is
        // used, and the identical, correct product failed. The regression being
        // guarded is a hardcoded year in the PROGRAMME EYEBROW, so that is what
        // this now looks at — and a literal `2026` is replaced by date('Y'), or
        // the test stops testing anything at all next January.
        $this->assertMatchesRegularExpression(
            '/class="eyebrow"[^>]*>[^<]*2031 Cycle/u', $body,
            'the eyebrow must render the cycle\'s own year'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="eyebrow"[^>]*>[^<]*' . date('Y') . ' Cycle/u', $body,
            'the eyebrow used to be a literal'
        );
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
        $this->assertStringContainsString($this->asRendered('+30 days', 'j F Y'), $body,
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

    // ── /vote hub and the nominee ballot ─────────────────────────────────────

    private function seedNominee(string $slug, array $cycle): int
    {
        $this->seedProgramme($slug, $cycle);
        $cat = (int) DB::table('gates_award_categories')->orderByDesc('id')->value('id');
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $cat, 'name' => 'Ada Obi', 'status' => 'approved',
            'vote_count' => 5, 'organic_vote_count' => 5, 'country_code' => 'NG',
        ]);
    }

    public function test_the_hub_never_shows_an_open_badge_beside_an_expired_deadline(): void
    {
        // The reported contradiction: the column says voting, the close date has
        // passed. The hub used to render "Voting open", "0 Days left" and
        // "Voting closes <past date>" all at once, because open/closed came from
        // the status column and the countdown came from voting_close.
        $this->seedProgramme('stale', [
            'status'       => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ]);

        [$status, $body] = $this->render(\AfricaGates\Controllers\VoteController::class, 'index', '/vote');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Between cycles', $body, 'no ballot is open');
        $this->assertStringContainsString('No open ballot', $body, 'and the deadline stat says so');
        $this->assertStringNotContainsString('Days left', $body, 'the misleading clamped counter is gone');
        $this->assertStringNotContainsString('Closing soon', $body,
            'nothing may claim to be closing when nothing is open');
    }

    public function test_the_hub_states_one_consistent_deadline_when_voting_is_open(): void
    {
        $this->seedProgramme('creative', $this->openVoting());

        [, $body] = $this->render(\AfricaGates\Controllers\VoteController::class, 'index', '/vote');

        $this->assertStringContainsString('Voting open', $body);
        $this->assertStringContainsString('Closes first', $body);
        $this->assertStringContainsString($this->asRendered('+10 days', 'j M Y'), $body,
            'the rail must name the real close date');
    }

    public function test_the_hub_labels_the_shortlisting_phase(): void
    {
        // 'shortlisting' was absent from the hub's label map entirely, so it
        // would have fallen through to a capitalised raw value.
        $this->seedProgramme('gap', [
            'status'            => 'shortlisting',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'voting_open'       => date('Y-m-d H:i:s', strtotime('+10 days')),
            'voting_close'      => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        [, $body] = $this->render(\AfricaGates\Controllers\VoteController::class, 'index', '/vote');

        $this->assertStringContainsString('Shortlisting', $body);
        $this->assertStringContainsString('View nominees', $body, 'and offer the one useful action');
    }

    public function test_the_ballot_explains_a_refused_paid_checkout(): void
    {
        // Eight ?paid= reasons were emitted and none were ever rendered.
        $id = $this->seedNominee('creative', $this->openVoting());

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(\AfricaGates\Controllers\VoteController::class);
        $req  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/vote/creative/' . $id . '-ada-obi')
            ->withQueryParams(['paid' => 'closed']);
        $body = (string) $ctrl->nominee($req, new Response(), ['program' => 'creative', 'slug' => $id . '-ada-obi'])->getBody();

        $this->assertStringContainsString('Voting has closed for this category', $body);
        $this->assertStringContainsString('role="alert"', $body, 'the outcome of a just-taken action');
    }

    public function test_the_ballot_shows_a_phase_specific_closed_state(): void
    {
        $id = $this->seedNominee('stale', [
            'status'       => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-30 days')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'results_date' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);

        [, $body] = $this->render(
            \AfricaGates\Controllers\VoteController::class, 'nominee',
            '/vote/stale/' . $id . '-ada-obi',
            ['program' => 'stale', 'slug' => $id . '-ada-obi']
        );

        $this->assertStringContainsString('With the jury', $body, 'name the actual phase, not just "closed"');
        $this->assertStringContainsString('All Stale Awards nominees', $body, 'and offer a way back');
        $this->assertStringNotContainsString('Confirm vote', $body, 'the ballot form must be absent');
    }

    public function test_the_open_ballot_names_its_close_date(): void
    {
        $id = $this->seedNominee('creative', $this->openVoting());

        [, $body] = $this->render(
            \AfricaGates\Controllers\VoteController::class, 'nominee',
            '/vote/creative/' . $id . '-ada-obi',
            ['program' => 'creative', 'slug' => $id . '-ada-obi']
        );

        $this->assertStringContainsString('Voting open', $body);
        $this->assertStringContainsString('Closes <time', $body, 'machine-readable, absolute date');

        // ── THE DATE IS EXPECTED IN THE DISPLAY ZONE, NOT THE SERVER'S ───────
        //
        // This asserted `date('j M Y', strtotime('+10 days'))` — the SERVER's day. The
        // fixture stores `voting_close` in UTC and the template renders it through
        // `when_zoned` into Africa/Lagos, so between 23:00 and midnight UTC the two are
        // different dates and this test failed for one hour in every twenty-four.
        //
        // Caught at 23:12 UTC: stored 2026-09-03 23:12, rendered "4 Sep 2026, 00:12",
        // asserted "3 Sep 2026". The page was right and the assertion was wrong — so the
        // expectation is computed the way the page computes it.
        $this->assertStringContainsString($this->asRendered('+10 days', 'j M Y'), $body);
    }

    public function test_the_ballot_records_the_vote_for_the_hub_tracker(): void
    {
        // The hub reads localStorage['afg_voted_prog_<id>']; nothing in the repo
        // ever wrote it, so "your ballot" was permanently 0 of N.
        $id = $this->seedNominee('creative', $this->openVoting());

        [, $body] = $this->render(
            \AfricaGates\Controllers\VoteController::class, 'nominee',
            '/vote/creative/' . $id . '-ada-obi',
            ['program' => 'creative', 'slug' => $id . '-ada-obi']
        );

        $this->assertStringContainsString("afg_voted_prog_", $body, 'the key the hub reads must be written');
        $this->assertStringContainsString('markVoted()', $body, 'and called on a successful vote');
    }
}
