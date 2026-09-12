<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\InterviewsController;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The interview screen, grouped by when each part of it is used.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG WITH ELEVEN OPEN SECTIONS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Six screens of scroll, every section expanded, in an order that was correct and useless:
 * it followed the life of a sitting, so whichever moment you opened the page in, most of it
 * was addressed to a different one.
 *
 * An operator opening this to paste a transcript read past the panel picker, the invitation
 * text, the guest list, the question pack, the extension key and the recording bot to reach
 * the box they came for. An operator opening it an hour before the call read past the
 * transcript box, the model's reading of a transcript that does not exist, and
 * "Close the sitting" — which is not reversible and was the last thing on the page.
 *
 * The sections are the same sections. They are now in three groups by the moment they
 * belong to, and the group matching the sitting's actual state is the one that starts open.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT IS TESTED BY RENDERING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A `{% set %}` inside a Twig block is invisible to every other block and renders as null,
 * silently — this platform has lost a whole navigation and every vote page's share link to
 * exactly that. `phase` is used by three separate `<details>` tags, and if it resolved to
 * null the page would still return 200 with complete HTML: three closed groups, no error,
 * and a screen that looks deliberately collapsed. Only rendering it catches that.
 */
final class InterviewScreenPhaseTest extends TestCase
{
    private int $nominee = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nominee = (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => 1, 'name' => 'Ada Nwosu', 'status' => 'approved', 'vote_count' => 0,
        ]);
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = 'superadmin';
        $_SESSION['csrf_token'] = 'test-token';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['csrf_token']);
        parent::tearDown();
    }

    /** @param array<string,mixed> $over */
    private function sitting(array $over = []): int
    {
        // `$over +` and not `+ $over`: PHP's array union keeps the LEFT operand's value for
        // a duplicate key, so writing the defaults first silently discarded every override
        // and made four of these tests assert against the same unmodified sitting.
        return (int) DB::table('gates_interviews')->insertGetId($over + [
            'nominee_id'    => $this->nominee,
            'status'        => 'confirmed',
            'scheduled_at'  => date('Y-m-d H:i:s', strtotime('+3 days')),
            'duration_mins' => 30,
            'timezone'      => 'Africa/Lagos',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    private function render(int $id): string
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $b->build()->get(InterviewsController::class);

        $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/interviews/' . $id);
        $res = $ctrl->show($req, (new ResponseFactory())->createResponse(), ['id' => $id]);

        $this->assertSame(200, $res->getStatusCode(),
            'show() redirected instead of rendering — it refused before reaching the template');

        return (string) $res->getBody();
    }

    /** Which of the three groups carries the `open` attribute. @return list<string> */
    private function openGroups(string $html): array
    {
        // Matched on the details tag itself rather than on a phase name, because `open` is
        // the whole assertion: a group without it is a heading the operator has to press.
        preg_match_all('~<details[^>]*>\s*<summary>\s*<span class="is-ph__i">(\d)</span>~s',
                       $html, $all, PREG_SET_ORDER);
        preg_match_all('~<details[^>]*\sopen[^>]*>\s*<summary>\s*<span class="is-ph__i">(\d)</span>~s',
                       $html, $open, PREG_SET_ORDER);

        $this->assertCount(3, $all, 'the three phase groups are not all rendering');

        return array_map(static fn (array $m): string => $m[1], $open);
    }

    // ══ the three phases ═════════════════════════════════════════════════════

    public function test_an_upcoming_sitting_opens_on_arranging_it(): void
    {
        $html = $this->render($this->sitting());

        $this->assertSame(['1'], $this->openGroups($html));
        $this->assertStringContainsString('Before the call', $html);
        // And the sections are all still THERE — collapsed, not removed. This is the
        // failure mode of tidying a long screen, and find-in-page is what an operator
        // reaches for when a section they know exists is not where they left it.
        $this->assertStringContainsString('Close the sitting', $html);
        $this->assertStringContainsString('The recording bot', $html);
    }

    public function test_a_live_sitting_opens_on_the_call(): void
    {
        $html = $this->render($this->sitting(['status' => 'live']));

        $this->assertSame(['2'], $this->openGroups($html));
    }

    public function test_a_finished_sitting_opens_on_what_comes_after(): void
    {
        foreach (['done', 'no_show', 'cancelled'] as $status) {
            $html = $this->render($this->sitting(['status' => $status]));

            $this->assertSame(['3'], $this->openGroups($html), $status . ' opened the wrong group');
        }
    }

    public function test_a_transcript_beats_a_live_status(): void
    {
        // Somebody catching up after the call: the row was never moved off `live`, and the
        // work they are here to do is publishing what they just pasted in — not the
        // extension key for a conversation that has already happened.
        // gates_nominee_interviews, not a table called gates_transcripts — the transcript
        // is nominee evidence and lives with the rest of it. `transcript` is NOT NULL with
        // no default, which is the schema-not-the-fixture check CLAUDE.md asks for.
        $t = (int) DB::table('gates_nominee_interviews')->insertGetId([
            'nominee_id'        => $this->nominee,
            'transcript'        => 'They described the seed bank at length.',
            'transcript_source' => 'human',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $html = $this->render($this->sitting(['status' => 'live', 'transcript_id' => $t]));

        $this->assertSame(['3'], $this->openGroups($html));
    }

    // ══ the summary line is about the interview, not the template ════════════

    public function test_an_unscheduled_sitting_says_so_on_the_group_heading(): void
    {
        $html = $this->render($this->sitting(['scheduled_at' => null, 'status' => 'draft']));

        // "no time set yet" is a fact about this sitting. A count of the sections inside
        // the group would be a fact about the template, and useless to a reader.
        $this->assertStringContainsString('no time set yet', $html);
    }

    public function test_a_scheduled_sitting_with_no_link_says_which_is_missing(): void
    {
        $html = $this->render($this->sitting());

        $this->assertStringContainsString('no joining link yet', $html);
    }

    public function test_a_cancelled_sitting_says_cancelled_rather_than_no_transcript(): void
    {
        $html = $this->render($this->sitting(['status' => 'cancelled']));

        $this->assertStringContainsString('cancelled', $html);
        $this->assertStringNotContainsString('no transcript yet', $html);
    }

    // ══ and it works without JavaScript ══════════════════════════════════════

    public function test_the_groups_need_no_script(): void
    {
        $html = $this->render($this->sitting());

        // <details>, not a tab strip. The admin CSP has no 'unsafe-inline', so a tab strip
        // would need an external file and a delegated listener to be openable at all — and
        // a screen whose sections are unreachable without JavaScript is worse than one that
        // scrolls.
        $this->assertStringContainsString('<details', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onchange=', $html);
    }
}
