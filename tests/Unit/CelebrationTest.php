<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Slim\Views\Twig;
use Tests\TestCase;

/**
 * THE WINNER'S MOMENT, AND THE FOUR WAYS A CELEBRATION BECOMES A FAULT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A CELEBRATION ON A RESULT PAGE IS NOT DECORATION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It fires on the page that tells somebody they have won an award, which makes every
 * way it can misfire a way of saying something untrue or unkind:
 *
 *   · ON A HELD RESULT. `/results/{id}` for a withheld award names nobody, deliberately —
 *     "half a result on a public page is the version people screenshot". Confetti over
 *     that page announces a winner the platform is refusing to announce.
 *
 *   · ON A DELAYED ONE. A cycle past its results date with nothing decided renders the
 *     holding page, and a celebration there tells the family refreshing it that the
 *     award has been given.
 *
 *   · ON EVERY VISIT. A burst that replays each time is a page somebody cannot read.
 *
 *   · INSTEAD OF THE PAGE. Nothing here may reveal the name or the index: they are the
 *     server's output, complete before a byte of JavaScript arrives. This codebase has
 *     shipped a dead camera, a mute door, a stop-link nobody was handed and a nominee's
 *     read-aloud blocked by a policy line — every one of them a feature that looked
 *     present and was not. A result page must not be able to join them, so the failure
 *     of this script is a page with no confetti and never a page with no winner.
 *
 * ── AND THE FIFTH, WHICH IS A HEADER ────────────────────────────────────────
 * `Permissions-Policy` denies `autoplay` site-wide, and both mobile browsers gate audible
 * playback on a gesture this page never had. A celebration that tried to play a sound
 * would fail silently on every device — exactly how the door's greeting went unheard for
 * months. So it makes no sound at all, and that is asserted rather than assumed.
 */
final class CelebrationTest extends TestCase
{
    private const JS      = 'public/assets/js/celebrate.js';
    private const PARTIAL = 'templates/partials/celebrate.twig';
    private const PAGE    = 'templates/pages/results/show.twig';
    private const LIB     = 'public/assets/js/vendor/canvas-confetti-1.9.3.js';

    private static function src(string $rel): string
    {
        $p = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($p, $rel . ' is missing');
        return (string) file_get_contents($p);
    }

    /** `slug` is NOT NULL and UNIQUE per cycle — a fixture that omits it passes on
     *  neither driver, and a shared literal collides with the next test's. */
    private function category(string $title): int
    {
        return (int) DB::table('gates_award_categories')->insertGetId([
            'cycle_id' => 1, 'title' => $title, 'slug' => 'c-' . bin2hex(random_bytes(5)),
        ]);
    }

    private function promoted(int $categoryId, string $name, string $status): int
    {
        return (int) DB::table('gates_nominees')->insertGetId([
            'category_id' => $categoryId, 'name' => $name,
            'status' => $status === '' ? 'approved' : $status,
            'vote_count' => 0,
        ]);
    }

    private function vote(string $email, int $categoryId, int $nomineeId): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => $nomineeId, 'category_id' => $categoryId,
            // The same hash the service looks up by — `sha256(lower(trim(email)))`.
            'voter_email_hash' => hash('sha256', strtolower(trim($email))),
            'vote_type' => 'standard', 'voted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The nominee's own public page, promoted or not. */
    private function nominee(string $awardKind): string
    {
        $_SESSION = ['csrf_token' => 'tok'];
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(Twig::class)->fetch('pages/vote-nominee.twig', [
            'nominee' => ['id' => 42, 'programme_id' => 3, 'name' => 'Ada Obi', 'category' => 'Innovation',
                          'tagline' => 'A leader', 'vote_count' => 5, 'programme_title' => 'STEM'],
            'firstName' => 'Ada', 'others' => [], 'AV' => [['#eee', '#333']],
            'flag' => '🇳🇬', 'ctry' => 'Nigeria',
            'award_kind' => $awardKind, 'backer_count' => 276, 'roll_of_honour' => [],
            'gates_page' => 'vote', 'has_hero' => false,
        ]);
    }

    /** The page, rendered with the container's own Twig — `csrf_token` is a global. */
    private function render(array $r): string
    {
        $_SESSION = ['csrf_token' => 'tok'];
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(Twig::class)->fetch('pages/results/show.twig', [
            'r' => $r, 'gates_page' => 'results', 'has_hero' => false,
        ]);
    }

    /** @param array<string,mixed> $over */
    private static function drawn(array $over = []): array
    {
        $row = [
            'name' => 'Oluwagbemiga Dorcas', 'cpi' => 780, 'community_points' => 415,
            'judge_points' => 365, 'vote_count' => 460, 'unique_voters' => 276,
            'in_running' => true, 'url' => '/vote/x/dorcas',
        ];
        return $over + [
            'programme' => 'Alimosho Incredible Principal Awards', 'edition' => '2026',
            'programme_slug' => 'aipa',
            'category' => ['title' => 'Teachers’ Choice', 'description' => 'd'],
            'winner' => $row, 'rows' => [$row],
            'held' => null, 'dead_heat' => false, 'tie_broken_by_votes' => false,
            'weights' => ['community' => 0.45, 'judge' => 0.55],
            'cohort_max' => 620, 'cohort_max_unique' => 62, 'cohort_max_by' => null,
            'votes' => ['total' => 460, 'organic' => 400],
            'cycle_id' => 7, 'cycle_year' => 2026,
            'slug' => '12-teachers-choice', 'url' => '/results/12-teachers-choice',
            'released_at' => '2026-08-01', 'sealed_at' => '2026-08-01 10:00:00',
            'rank_recomputed' => false, 'community_basis' => 'ideal',
            'community_scope' => 'edition', 'judge_scale' => 'linear', 'basis_from' => 'default',
        ];
    }

    // ───────────────────────── where it may and may not fire ──────────────────

    public function test_a_released_result_celebrates_its_winner(): void
    {
        $html = $this->render(self::drawn());

        $this->assertStringContainsString('data-celebrate="12-teachers-choice"', $html);
        $this->assertStringContainsString('celebrate.js', $html);
        $this->assertStringContainsString('canvas-confetti', $html);
        // The figure and the name are the SERVER'S. Verified here because the count-up
        // reads its target out of the element — if the page rendered a placeholder, the
        // number would animate to nothing and land on nothing.
        $this->assertMatchesRegularExpression('~data-celebrate-figure[^>]*>780<~', $html);
        $this->assertStringContainsString('Oluwagbemiga Dorcas', $html);
    }

    public function test_a_held_result_names_nobody_and_celebrates_nobody(): void
    {
        $html = $this->render(self::drawn(['held' => 'The panel has not finished']));

        $this->assertStringNotContainsString('Oluwagbemiga Dorcas', $html,
            'a held result named its winner');
        $this->assertStringNotContainsString('data-celebrate', $html,
            'confetti over a result the platform is deliberately withholding');
        $this->assertStringNotContainsString('celebrate.js', $html);
        $this->assertStringNotContainsString('canvas-confetti', $html);
    }

    public function test_the_delayed_holding_page_carries_no_celebration(): void
    {
        // A different template entirely — a cycle past its results date with nothing
        // decided. Asserted rather than assumed, because "it is a different file" is
        // exactly the reasoning that stops being true when somebody moves the include
        // into the layout.
        $late = self::src('templates/pages/results/late.twig');
        $this->assertStringNotContainsString('celebrate', $late);
    }

    // ───────────────────────── the nominee's own page ─────────────────────────

    public function test_the_nominee_page_celebrates_only_a_promoted_nominee(): void
    {
        $won  = $this->nominee('winner');
        $none = $this->nominee('');

        $this->assertStringContainsString('data-celebrate="nominee-', $won,
            'a promoted nominee gets no moment on their own page');
        $this->assertStringContainsString('celebrate.js', $won);

        $this->assertStringNotContainsString('data-celebrate', $none,
            'confetti on the page of somebody who has not been promoted');
        $this->assertStringNotContainsString('celebrate.js', $none);
    }

    public function test_the_nominee_page_does_not_put_two_clocks_on_one_number(): void
    {
        // The backer count already carries `data-ag-count`, which ag-motion drives when
        // the laurel section reveals. Naming it as the celebration's figure as well would
        // be two animations writing to one element, and the loser is whichever finishes
        // second — a count that lands on the wrong number in front of the person it is
        // about.
        $won = $this->nominee('winner');
        $this->assertStringContainsString('data-ag-count', $won);
        $this->assertStringNotContainsString('data-celebrate-figure', $won);
    }

    // ───────────────────────── the member's dashboard ─────────────────────────

    public function test_the_query_behind_the_dashboard_panel_actually_runs(): void
    {
        // THE POINT OF THIS TEST IS THE CATCH. backedWinners() swallows a database error
        // and returns [], which is right for an ornament on a records screen and is also
        // indistinguishable from "nobody won" — the first version selected `n.slug`, a
        // column gates_nominees does not have, and would have shown an empty panel for
        // ever without a single error anywhere. So this proves a row comes BACK.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        // A category each: one vote per person per category is enforced by a UNIQUE key.
        $cat = $this->category('Teachers’ Choice');
        $win = $this->promoted($cat, 'Oluwagbemiga Dorcas', 'winner');
        $this->vote($email, $cat, $win);

        $other = $this->category('Still Judging');
        $this->vote($email, $other, $this->promoted($other, 'Not Promoted Yet', 'approved'));

        $got = \AfricaGates\Services\MemberActivityService::backedWinners($email);

        $this->assertCount(1, $got, 'the query came back empty — it may not be running at all');
        $this->assertSame('Oluwagbemiga Dorcas', $got[0]['nominee']);
        $this->assertSame('winner', $got[0]['kind']);
        $this->assertSame('Teachers’ Choice', $got[0]['category']);
        $this->assertSame($win, $got[0]['id']);
    }

    public function test_a_nominee_nobody_has_been_told_about_is_not_congratulated(): void
    {
        // 'winner' is written by CycleMaterialiser inside the promotion, so a nominee who
        // is merely leading, or approved, or pending, must never reach this panel. A
        // member told "someone you backed won" before the announcement IS the announcement.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        // A CATEGORY EACH. `gates_votes` is UNIQUE on (voter_email_hash, category_id) —
        // one vote per person per category is the platform's rule — so a fixture that
        // votes twice in one category is not a stricter test, it is an impossible one.
        foreach (['pending', 'approved'] as $status) {
            $cat = $this->category('Undecided ' . $status);
            $this->vote($email, $cat, $this->promoted($cat, 'Someone ' . $status, $status));
        }

        $this->assertSame([], \AfricaGates\Services\MemberActivityService::backedWinners($email));
    }

    public function test_somebody_elses_vote_is_not_your_celebration(): void
    {
        $mine   = 'mine-' . bin2hex(random_bytes(4)) . '@example.test';
        $theirs = 'theirs-' . bin2hex(random_bytes(4)) . '@example.test';
        $cat = $this->category('Craft');
        $this->vote($theirs, $cat, $this->promoted($cat, 'Their Winner', 'winner'));

        $this->assertSame([], \AfricaGates\Services\MemberActivityService::backedWinners($mine));
    }

    public function test_two_categories_backed_is_two_lines_and_one_key(): void
    {
        // Backing the same nominee twice is not reachable — one vote per person per
        // category, enforced by a UNIQUE key — so the case that matters is two
        // categories. Both appear, and the panel's celebrate key carries both ids, so a
        // member who backs a second winner next month gets a second moment rather than
        // one that already counts as seen.
        $email = 'backer-' . bin2hex(random_bytes(4)) . '@example.test';
        $ids = [];
        foreach (['Craft', 'Service'] as $title) {
            $cat = $this->category($title);
            $ids[] = $id = $this->promoted($cat, $title . ' Winner', 'winner');
            $this->vote($email, $cat, $id);
        }

        $got = \AfricaGates\Services\MemberActivityService::backedWinners($email);
        $this->assertCount(2, $got);
        $this->assertSame(array_reverse($ids), array_column($got, 'id'),
            'newest first, so a fresh win is the first thing read');
    }

    // ───────────────────────── the guarantees in the script ───────────────────

    public function test_the_script_reveals_nothing_and_therefore_cannot_withhold_it(): void
    {
        $js = self::src(self::JS);

        // It must never set the winner's name or the index from script, and never unhide
        // anything: a celebration that PAINTS the result is a celebration that can fail
        // to paint it.
        $this->assertStringNotContainsString('.hidden = false', $js);
        $this->assertStringNotContainsString("style.display", $js);
        $this->assertStringNotContainsString('innerHTML', $js);

        // The figure is animated by ag-motion.js's counter, not by a copy living here.
        // That one already knows a thousands separator has to survive the animation and
        // that a COMPOSITE figure ("45 / 55") is two quantities rather than a number —
        // a second counter is how one screen counts to 45 and another through 4555.
        $this->assertStringContainsString('window.agCount(figure)', $js,
            'the celebration stopped using the motion system\u{2019}s counter');
        $this->assertStringNotContainsString('function countUp', $js,
            'a second count-up implementation is back in celebrate.js');
        $this->assertStringNotContainsString('requestAnimationFrame', $js,
            'celebrate.js is animating a number itself again');

        // And the one it delegates to is really there and really exported.
        $motion = self::src('public/assets/js/ag-motion.js');
        $this->assertStringContainsString('window.agCount = function', $motion,
            'ag-motion.js no longer exports the counter celebrate.js calls');
    }

    public function test_it_makes_no_sound(): void
    {
        // Not taste. `Permissions-Policy` denies autoplay site-wide and both mobile
        // browsers gate audible playback on a gesture this page never had, so a sound
        // here is a rejected promise nobody ever hears about.
        $js = self::src(self::JS);
        foreach (['new Audio', 'AudioContext', '.play(', '<audio'] as $noisy) {
            $this->assertStringNotContainsString($noisy, $js, "the celebration reaches for {$noisy}");
        }
    }

    public function test_reduced_motion_gets_the_result_and_no_particles(): void
    {
        $js = self::src(self::JS);
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        // Two belts: the module returns before it makes a canvas, and every burst also
        // carries the library's own flag — because the check and the bursts are far
        // enough apart in the file for one to be edited without the other.
        $this->assertMatchesRegularExpression('~if \(quiet \|\| typeof window\.confetti[^)]*\) return;~', $js);
        $this->assertSame(2, substr_count($js, 'disableForReducedMotion: true'),
            'a burst was added without the reduced-motion flag');
        // And the count-up is motion too — honoured by the counter it delegates to,
        // which refuses to run under reduced motion on its own account. Asserted there
        // rather than assumed, because that guard is now in a different file.
        $motion = self::src('public/assets/js/ag-motion.js');
        $this->assertMatchesRegularExpression(
            '~window\.agCount = function \(el\) \{\s*if \(reduced~', $motion,
            'the exported counter runs under reduced motion');
    }

    public function test_it_plays_once_per_result_and_forgetting_is_not_a_failure(): void
    {
        $js = self::src(self::JS);

        $this->assertStringContainsString("'ag-celebrated:'", $js);
        // localStorage throws in a private window and where site data is blocked. A
        // remembered play that cannot be remembered must play AGAIN, never stop playing:
        // both accessors return/ignore rather than propagating.
        // Read the real function BODIES rather than pattern-matching around them: a
        // `[^}]*` window stops at the first brace, which here is the `try` block's own —
        // so the pattern could only ever describe a function with no try in it, which is
        // the opposite of what is being asserted.
        $this->assertStringContainsString('catch', self::body($js, 'seen'),
            'a blocked localStorage read propagates, so a private window gets no celebration at all');
        $this->assertStringContainsString('return false', self::body($js, 'seen'),
            'an unreadable store must mean "not seen yet", never "seen"');
        $this->assertStringContainsString('catch', self::body($js, 'remember'),
            'a blocked localStorage write propagates and takes the celebration with it');
    }

    /**
     * One JavaScript function body, by brace matching.
     *
     * Brace matching and not a regex: every naive window either stops at the first `}`
     * inside the function or runs to the end of the file, and both produce assertions
     * that pass on source they do not describe.
     */
    private static function body(string $js, string $fn): string
    {
        $at = strpos($js, 'function ' . $fn . '(');
        self::assertNotFalse($at, "no function {$fn}() in celebrate.js");
        $open = strpos($js, '{', $at);
        self::assertNotFalse($open);

        $depth = 0;
        for ($i = $open, $n = strlen($js); $i < $n; $i++) {
            if ($js[$i] === '{') $depth++;
            elseif ($js[$i] === '}' && --$depth === 0) return substr($js, $open, $i - $open + 1);
        }
        self::fail("function {$fn}() is not closed");
    }

    public function test_the_canvas_is_taken_away_again(): void
    {
        $js = self::src(self::JS);
        // Left behind it is a full-screen fixed element over every page scrolled to
        // next. Pointer-transparent, so the only symptom is a phone getting warm.
        $this->assertStringContainsString('removeChild(canvas)', $js);
        $this->assertStringContainsString("setAttribute('aria-hidden', 'true')", $js);
        $this->assertStringContainsString('pointer-events:none', $js);
    }

    public function test_no_blob_worker_is_asked_for(): void
    {
        // canvas-confetti's worker is built from a Blob URL, and this site's CSP has no
        // `worker-src` — which falls back to `script-src`, and that is `'self'` plus a
        // nonce. Asking for it logs a violation on every award page and then falls back
        // to the main thread anyway.
        $js  = self::src(self::JS);
        $csp = self::src('src/Support/Csp.php');

        $this->assertStringContainsString('useWorker: false', $js);
        $this->assertStringNotContainsString('useWorker: true', $js);
        $this->assertStringNotContainsString('worker-src', $csp,
            'the policy gained worker-src — the comment in celebrate.js is now wrong');
    }

    // ───────────────────────── the vendored library ───────────────────────────

    public function test_the_library_is_self_hosted_and_recorded(): void
    {
        $lib = self::src(self::LIB);
        $this->assertStringContainsString('canvas-confetti v1.9.3', $lib,
            'the vendored file is not the version its name claims');

        $partial = self::src(self::PARTIAL);
        // Self-hosted, like every other third party here: the CSP names no CDN in
        // script-src, so a cdn.jsdelivr.net reference is a script that never runs.
        $this->assertStringNotContainsString('cdn.', $partial);
        $this->assertStringNotContainsString('unpkg', $partial);
        $this->assertStringContainsString('/assets/js/vendor/canvas-confetti-1.9.3.js', $partial);

        $prov = self::src('public/assets/js/vendor/PROVENANCE.md');
        $this->assertStringContainsString('canvas-confetti@1.9.3', $prov,
            'a vendored file with no provenance row — nobody can reproduce or update it');
    }

    public function test_both_scripts_are_deferred_and_nonced(): void
    {
        $partial = self::src(self::PARTIAL);
        $this->assertSame(2, substr_count($partial, 'defer'),
            'a celebration script blocks the first paint of a result page');
        // The admin CSP has no 'unsafe-inline' and the public one is nonce-based; a
        // script tag without the nonce is one the browser refuses.
        $this->assertSame(2, substr_count($partial, 'csp_nonce'));
    }
}
