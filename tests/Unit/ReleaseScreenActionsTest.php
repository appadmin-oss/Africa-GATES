<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\ResultReleaseController;
use AfricaGates\Services\SnapshotService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * THE THREE POSTS ON THE RELEASE SCREEN, AND WHETHER A PERSON CAN REACH THEM.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `docs/CODEBASE-INDEX.md` §18 names the shape: a mechanism with no route in. Two of this
 * screen's actions were exactly that, in different ways, and both were complete on every
 * side except the one that matters.
 *
 * `POST /admin/result-release/recount` had a route, a controller, a report naming every
 * nominee whose counter moved, a refusal for the nominee whose ballot rows are missing —
 * and the CSS for its button sitting in the template with no form above it. Every figure
 * the release screen ranks an award by is a cached counter over `gates_votes`, and the one
 * action that makes the cache agree with the ledger was reachable only by crafting a
 * request by hand, against a host with no SSH.
 *
 * `SnapshotService::verify()` was worse, because the platform PUBLISHES a promise about
 * it: "the result is written down and sealed… there is no quiet edit available", and "the
 * chain is re-verified automatically every day… a break is raised as a failure, to
 * people." The daily half was true — the maintenance sweep runs it and throws. Its only
 * other caller was a console command on a host with no shell, so an operator asked by a
 * nominee whether the numbers had been altered could not find out.
 *
 * So the tests are in two halves: the ROUTE IN (a form posting to each, carrying what the
 * middleware and the controller need) and the ANSWER (what the check reports, including
 * when it is bad news).
 */
final class ReleaseScreenActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin_id'] = 1;
        unset($_SESSION['flash_ok'], $_SESSION['flash_error']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['flash_ok'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    // ══ the route in ═════════════════════════════════════════════════════════

    /**
     * EVERY POST ROUTE ON THIS SCREEN IS POSTED TO BY ITS OWN TEMPLATE.
     *
     * The sweep, not the three assertions under it: this is the check that would have
     * failed on the day the recount shipped, and it fails again for the next action
     * somebody routes and forgets to draw.
     *
     * ── AND DELIBERATELY SCOPED TO THIS ONE SCREEN ──────────────────────────
     *
     * An app-wide version cannot be written this way. A parameterised route
     * (`/{id:[0-9]+}/delete`) can never string-match a rendered template, and
     * `src/routes.php` nests its `$app->group()`s — so a regex over `$a->post(...)` cannot
     * tell an `/admin` route from an `/api` one. Two attempts at the wider figure returned
     * 111 and then 39 "unreachable" routes and BOTH were artefacts: nearly every entry was
     * an `/api` route wired by a `fetch()` (`/api/guide` is called from
     * `public/assets/js/gee.js`) or a parameterised admin path. A correct version needs
     * group-aware parsing, which `RouteTableIntegrityTest` already does by booting the
     * router — that is where it belongs, not here.
     *
     * A sweep whose output nobody can act on is worse than no sweep: it is run once,
     * disbelieved, and never run again. These are literal paths on one screen, so the
     * question has an exact answer.
     */
    public function test_every_post_route_on_this_screen_has_a_form_that_posts_to_it(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');
        $tpl    = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/result-release.twig');

        preg_match_all("~\\\$a->post\('(/result-release/[a-z0-9\-/]+)'~", $routes, $m);
        $this->assertGreaterThanOrEqual(3, count($m[1]),
            'the sweep found fewer POST routes than this screen has, so it is reading the '
            . 'wrong file or the wrong shape and would pass for ever');

        foreach ($m[1] as $path) {
            $this->assertStringContainsString('action="/admin' . $path . '"', $tpl,
                "POST /admin{$path} has a route and a controller and nothing posts to it. "
                . 'That is a mechanism with no route in — docs/CODEBASE-INDEX.md §18.');
        }
    }

    /**
     * EVERY POST FORM CARRIES THE CSRF TOKEN, BECAUSE WITHOUT IT THE POST IS REFUSED.
     *
     * `CsrfMiddleware` is added to the whole app in `public/index.php` and refuses any POST
     * whose `_token` does not match the session's. A form drawn without one is a button
     * that renders, is styled, is enabled, and answers "CSRF validation failed." — the
     * same class of failure as having no form at all, minus the clue.
     *
     * And `csrf_token` is a Twig GLOBAL, not something this controller passes, which is
     * why the assertion is on the template rather than on a payload.
     */
    public function test_every_post_form_carries_a_csrf_token(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/result-release.twig');

        preg_match_all('~<form[^>]*method="post"[^>]*>(.*?)</form>~s', $tpl, $m);
        $this->assertNotEmpty($m[0], 'no POST form on the release screen');

        foreach ($m[0] as $i => $form) {
            preg_match('~action="([^"]+)"~', $form, $a);
            $this->assertStringContainsString('name="_token"', $m[1][$i],
                'the form posting to ' . ($a[1] ?? '?') . ' has no _token, so CsrfMiddleware '
                . 'refuses it — a button that renders and cannot work');
        }
    }

    /**
     * The recount form names the category it is drawn under, and the cycle to return to.
     *
     * Without the category the controller answers "no category was named" — honest, and
     * useless. Without the cycle the operator lands on whichever cycle the picker defaults
     * to, which is the newest and not the one they were auditing.
     */
    public function test_the_recount_form_names_its_category_and_its_cycle(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/result-release.twig');
        preg_match('~<form[^>]*action="/admin/result-release/recount"[^>]*>(.*?)</form>~s', $tpl, $m);
        $this->assertNotEmpty($m, 'no recount form');

        $this->assertMatchesRegularExpression('~name="category"[^>]*value="\{\{ c\.category\.id~', $m[1],
            'the recount form does not carry the category it is drawn under');
        $this->assertMatchesRegularExpression('~name="cycle"[^>]*value="\{\{ cycle_id~', $m[1],
            'the recount form does not carry the cycle, so it returns the operator elsewhere');
    }

    /**
     * The two writes are confirmed and the read-only check is not.
     *
     * A dialog on all three teaches an operator to dismiss the one that matters. The
     * recount rewrites the numbers an award is decided by and the release tells a nominee
     * they won; the check reads the archive and writes an audit row.
     */
    public function test_the_writes_are_confirmed_and_the_read_only_check_is_not(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/result-release.twig');

        foreach (['recount', 'release'] as $write) {
            preg_match('~<form[^>]*action="/admin/result-release/' . $write . '"[^>]*>~s', $tpl, $w);
            $this->assertNotEmpty($w, "no {$write} form");
            $this->assertStringContainsString('data-confirm=', $w[0],
                "the {$write} changes what an award says and asks nobody");
        }

        preg_match('~<form[^>]*action="/admin/result-release/check-record"[^>]*>~s', $tpl, $c);
        $this->assertStringNotContainsString('data-confirm=', $c[0],
            'a confirm on a read-only check trains the operator to dismiss the ones on the writes');
    }

    /**
     * THIS SCREEN DOES NOT RE-READ A FLASH KEY THE CONTAINER HAS ALREADY CONSUMED.
     *
     * `index()` passed the template a `recount_said`, read out of `$_SESSION['flash_ok']`,
     * and it was ALWAYS NULL. The container's `Twig::class` factory reads every flash key
     * into a Twig global and `unset()`s them in the same closure, and a controller cannot
     * exist until that closure has run — Twig arrives through its constructor. Measured:
     *
     *     before resolving Twig : 'THE MESSAGE'
     *     global handed to Twig : 'THE MESSAGE'
     *     what a controller sees: NULL
     *
     * So the second slot the template drew for it never fired once. Nothing was lost here,
     * because the admin layout renders the global — but a page whose layout does not, or
     * one that passes the value back under the GLOBAL'S OWN NAME, loses the message
     * entirely: a local variable shadows a global even when it is null.
     *
     * ── AND THE SWEEP IS NOT WIDENED HERE, WHICH IS A CHOICE ─────────────────
     *
     * The same shape is live in eight other controllers, and at least one of them costs a
     * real message: `DonationController::stop()` writes `flash_ok` and redirects, and
     * `giving()` reads the consumed key and passes `'flash_ok' => null`, which shadows the
     * global that holds it — so a donor who stops a monthly gift is shown NOTHING.
     * `FlashKeyTest` already holds this rule for one file
     * (`test_the_org_dashboard_does_not_shadow_the_flash_globals`), which is the
     * enumeration-of-past-failures shape this codebase warns about.
     *
     * Generalising it fails on every one of those sites, and each needs its own template
     * checked before it can be fixed — a half-done repair blanks messages rather than
     * restoring them. That is a separate change, deliberately not folded into this one.
     * This test holds the screen this commit is about, and says plainly what it does not
     * cover, because a sweep that narrows the surface without saying where it stopped
     * reads as having cleared it.
     */
    public function test_the_release_screen_does_not_read_a_consumed_flash_key(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Admin/Controllers/ResultReleaseController.php');

        // Comments stripped first: this file explains the fault at length, and a sweep that
        // makes its own explanation unwritable is the trap the globe band already paid for
        // — a comment naming what was removed must not trip the guard documenting it.
        $code = (string) preg_replace('~//[^\r\n]*|/\*.*?\*/~s', '', $src);

        // \x27 for the quote character, so the whole pattern lives in a SINGLE-quoted PHP
        // string. Written with double quotes, `\$_SESSION` interpolates the real superglobal
        // and PHP folds the array to the string "Array" — a pattern that then matches
        // nothing and a guard that passes for ever. It did, on the first run of this test.
        $this->assertDoesNotMatchRegularExpression(
            '~\$_SESSION\[\x27(?:org_)?flash[a-z_]*\x27\]\s*(?:\?\?|\)|,|\.|;)~', $code,
            'this controller reads a flash key the container consumed before it ran, so the '
            . 'value is null and whatever it feeds never renders');
    }

    // ══ what the check answers ═══════════════════════════════════════════════

    /**
     * An intact chain is reported as intact, and the answer says what that establishes.
     *
     * "OK" is not an answer to "were the numbers altered". The person reading this is
     * about to repeat it to somebody who lost.
     */
    public function test_an_intact_record_is_reported_as_verifying(): void
    {
        $this->chain(3);
        $this->check(7);

        $said = (string) ($_SESSION['flash_ok'] ?? '');
        $this->assertStringContainsString('verifies', $said);
        $this->assertStringContainsString('3 row(s) checked', $said);
        $this->assertStringContainsString('altered', $said,
            'the answer does not say what verifying establishes');
        $this->assertSame('', (string) ($_SESSION['flash_error'] ?? ''),
            'a clean record raised a warning');
    }

    /**
     * A BROKEN chain names the row, refuses to accuse anybody, and is drawn as a warning.
     *
     * `UNIQUE(prev_hash)` now forbids the far likelier cause — two captures forking the
     * chain — but an archive written before that index can still carry one, and "somebody
     * edited the results" is alarming and usually wrong. It also has to say nothing is
     * lost, because the first thing an operator does with bad news about a record is fear
     * that rows are gone.
     *
     * `flash_error` and not `flash_ok`: the layout draws the first with `role="alert"` and
     * the second under a green tick.
     */
    public function test_a_broken_record_names_the_row_without_accusing_anybody(): void
    {
        $this->chain(3);
        // Alter a standing without touching its hash — what a hand-edit on the database
        // looks like, and exactly what the chain exists to catch.
        $id = (int) DB::table('gates_vote_snapshots')->orderBy('id')->skip(1)->value('id');
        DB::table('gates_vote_snapshots')->where('id', $id)->update(['cpi_score' => 999]);

        $this->check(7);

        $said = (string) ($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('does NOT verify', $said);
        $this->assertStringContainsString('#' . $id, $said, 'the break is not located');
        $this->assertStringContainsString('forked', $said,
            'the likelier cause is not offered, so the answer reads as an accusation');
        $this->assertStringContainsString('still present', $said,
            'an operator is left to assume rows have been lost');
        $this->assertSame('', (string) ($_SESSION['flash_ok'] ?? ''),
            'a break was reported under the tick the layout draws for a success');
    }

    /**
     * Rows written before the chain existed are counted and disclaimed, not folded in.
     *
     * "Verified 40,000 rows" and "verified 40,000 rows, and 900 older ones vouch for
     * nothing" are different claims, and the second is the true one.
     */
    public function test_rows_from_before_the_chain_are_disclaimed_in_the_answer(): void
    {
        DB::table('gates_vote_snapshots')->insert([
            'cycle_id' => 7, 'nominee_id' => 1, 'vote_count' => 5, 'cpi_score' => 100,
            'snapshot_at' => '2026-01-01 00:00:00', 'prev_hash' => null, 'hash' => null,
        ]);
        $this->chain(2);

        $this->check(7);

        $said = (string) ($_SESSION['flash_ok'] ?? '');
        $this->assertStringContainsString('verifies', $said);
        $this->assertStringContainsString('1 older row(s)', $said);
        $this->assertStringContainsString('vouch for nothing', $said);
    }

    /**
     * A check that could not RUN says so, and does not report a finding.
     *
     * "The record does not verify" is about the standings; "the record could not be read"
     * is about this deployment. A screen that draws the second as the first sends an
     * operator to investigate an award over a broken table.
     */
    public function test_a_check_that_cannot_run_is_a_fault_and_not_a_finding(): void
    {
        DB::statement('DROP TABLE gates_vote_snapshots');

        $this->check(7);

        $said = (string) ($_SESSION['flash_error'] ?? '');
        $this->assertStringContainsString('could not be read', $said);
        $this->assertStringNotContainsString('does NOT verify', $said,
            'a broken table was reported as a finding about the standings');
    }

    /**
     * The check is audited, so asking the question is itself on the record.
     *
     * Anybody who can press this can also read every standing on the platform. The row
     * carries the VERDICT and not only the fact of a check, because "who looked, and what
     * did it say then" is the question an audit of a disputed release brings.
     */
    public function test_the_check_is_written_to_the_audit_log(): void
    {
        $this->chain(2);
        $this->check(7);

        $row = DB::table('gates_audit_log')->where('action', 'results.chain_check')
            ->orderByDesc('id')->first();
        $this->assertNotNull($row, 'asking whether the results were altered left no trace');
        $this->assertSame(7, (int) $row->target_id);
        $this->assertStringContainsString('"checked":2', (string) $row->meta);
    }

    /**
     * It returns to the cycle it was asked about, with a 303.
     *
     * 303 and not 302: the answer is a fresh GET, and a POST re-sent by a reload would
     * re-walk the whole archive.
     */
    public function test_the_check_returns_to_the_cycle_it_was_asked_about(): void
    {
        $this->chain(1);
        $res = $this->check(42);

        $this->assertSame(303, $res->getStatusCode());
        $this->assertSame('/admin/result-release?cycle=42', $res->getHeaderLine('Location'));
    }

    /**
     * A crafted request with no cycle is answered, not crashed on.
     *
     * The form always carries one. This is the hand-made path, and `(int) $b['cycle'] ?? 0`
     * — which casts before the coalesce — is a PHP warning here rather than a default.
     */
    public function test_a_check_with_no_cycle_still_answers(): void
    {
        $this->chain(1);

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/result-release/check-record');
        $res = (new ResultReleaseController(new Twig(new ArrayLoader([]))))
            ->checkRecord($req, new Response());

        $this->assertSame('/admin/result-release', $res->getHeaderLine('Location'));
        $this->assertStringContainsString('verifies', (string) ($_SESSION['flash_ok'] ?? ''));
    }

    // ══ helpers ══════════════════════════════════════════════════════════════

    private function check(int $cycleId): Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://x/admin/result-release/check-record')
            ->withParsedBody(['cycle' => (string) $cycleId]);
        // The Twig is unused — this action redirects — but the constructor is typed, and a
        // stub loader is honest about that where a null would not be.
        return (new ResultReleaseController(new Twig(new ArrayLoader([]))))
            ->checkRecord($req, new Response());
    }

    /** A real chain of `$n` linked rows, written by the service that writes them. */
    private function chain(int $n): void
    {
        $svc = new SnapshotService();
        $m   = new \ReflectionMethod($svc, 'append');
        for ($i = 1; $i <= $n; $i++) {
            // One row per append, so each link is written against the previous tail exactly
            // as a real capture does.
            $m->invoke($svc, [[
                'cycle_id' => 7, 'nominee_id' => $i, 'vote_count' => 10 * $i,
                'cpi_score' => 100 + $i, 'judge_score' => null,
            ]], SnapshotService::KIND_ROUTINE);
        }
    }
}
