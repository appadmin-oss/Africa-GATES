<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\ResultRelease;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * What the release will do, before it does it — and what it did, after.
 *
 * ── WHY THIS IS NOT THE INTEGRITY PAGE EITHER ────────────────────────────────
 *
 * {@see IntegrityController} answers "is this cycle's result sound" — collusion, bias,
 * anomalies, the things that make a result unsafe. {@see JudgingAuditController} answers
 * "how does this award decide, and who has been deciding it".
 *
 * Neither shows the SCORES. Every number that decides an award came out of
 * `NomineeScoringService::scoreCategory()`, which had three callers — the promotion, a
 * snapshot writer, and a console command on a host with no shell — and no screen at all.
 * An operator could see who had been crowned and not one figure behind it.
 *
 * This is that table. Per category, every scored nominee in the order the award is
 * decided in, with the reason beside anybody who is out of the running.
 *
 * ── IT DECIDES NOTHING ───────────────────────────────────────────────────────
 *
 * READ-ONLY TO LOOK AT. A screen that could crown somebody by being looked at is not an
 * audit of a release, so `index()` writes nothing and what it shows is what the promotion
 * will do — it asks the promotion's own comparator rather than a copy of it.
 *
 * Two POSTs, and both are deliberate exceptions rather than drift: {@see recount()}
 * rebuilds a category's counters from the ballots, and {@see release()} publishes an
 * edition. Neither can be reached by a GET, because as links they could be fired by a
 * prefetch or a reload on the numbers an award is decided by.
 */
final class ResultReleaseController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $cycles = DB::table('gates_award_cycles as c')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->orderByDesc('c.year')->orderByDesc('c.id')
            ->select('c.id', 'c.year', 'c.status', 'c.edition_label', 'c.results_date',
                     'p.title as programme')
            ->limit(60)
            ->get()->map(static fn (object $r): array => (array) $r)->all();

        $wanted = (int) ($req->getQueryParams()['cycle'] ?? 0);
        if ($wanted < 1) $wanted = (int) ($cycles[0]['id'] ?? 0);

        // Never throws to the screen. This reads the rubric, the shortlist and the scorer,
        // any of which a given deployment may not have migrated — and a release screen
        // that 500s on the morning of a release is worse than one that says so.
        $categories = [];
        $failed     = false;
        if ($wanted > 0) {
            try {
                $categories = ResultRelease::forCycle($wanted);
            } catch (\Throwable $e) {
                error_log('[result-release] ' . $e->getMessage());
                $failed = true;
            }
        }

        $cycle = null;
        foreach ($cycles as $c) if ((int) $c['id'] === $wanted) $cycle = $c;

        return $this->view->render($res, 'admin/result-release.twig', [
            'page_title' => 'Result release',
            'admin_page' => 'result-release',
            'cycles'     => $cycles,
            'cycle_id'   => $wanted,
            'cycle'      => $cycle,
            'categories' => $categories,
            // ── WAS THERE A FREE VOTE TO HAVE ON THIS DEPLOYMENT? ────────────
            //
            // The screen prints an ORGANIC column and, per row, "N bought or awarded".
            // Where the free path answers 403 that column is a stack of zeros and the
            // sub-label fires on every row of every category — so the one signal it
            // exists to give ("this tally was mostly purchased") is given about
            // everybody, which is the same as being given about nobody.
            //
            // Read once, at the top, and used by the overall block; each category
            // carries the same flag on its own drawn result, from the same resolver.
            'paid_only'  => \AfricaGates\Services\PaidVoteService::freeVotingDisabled(),
            // What to look at first: categories that crown nobody, dead heats, margins
            // thin enough to turn on one mark. Counted here rather than in the template
            // because a template deriving it would be a second opinion about what needs
            // attention.
            'attention'  => ResultRelease::attention($categories),
            // The platform's own default basis, so the override notice can say what the
            // cycle would be scored by if the saved setting were removed. Read from the
            // rule engine's constant, never typed: a screen that names a default the
            // engine stopped using is this codebase's most-repeated fault, and this is the
            // page an award is signed off from.
            'basis_default' => \AfricaGates\Services\RuleEngine::DEFAULTS['community_basis'],
            // ── THE ONE AWARD FOR THE WHOLE CYCLE ────────────────────────────
            //
            // Passed the categories this page already drew rather than the cycle id: it is
            // computed from the category winners, and letting it re-run `forCycle()` would
            // score every nominee in the cycle a second time to reach the same answer.
            'overall'    => $wanted > 0 && !$failed
                            ? ResultRelease::overall($wanted, $categories)
                            : null,
            // ── AND WHETHER THIS TABLE IS WHAT THE PUBLIC PAGE PUBLISHES ─────
            //
            // It draws live, deliberately — that is what makes it an audit of the release
            // rather than a report about one. Since sealing shipped, that is no longer
            // what a RELEASED cycle's public page shows, and the two screens disagreed
            // with nothing anywhere to say why: an operator taking the call that begins
            // "my score has changed" had the recomputed figure in front of them while the
            // nominee had the sealed one. Null for a cycle that was never sealed, which is
            // most of them and every cycle before its release.
            'sealed'     => $wanted > 0 && !$failed
                            ? \AfricaGates\Services\ReleasedStanding::divergence($categories, $wanted)
                            : null,
            // Said out loud rather than rendered as an empty table. "Nothing scored yet"
            // and "the query failed" look identical on a screen and mean opposite things.
            'failed'     => $failed,
            // ── WHAT THE LAST ACTION SAID IS NOT PASSED FROM HERE ───────────
            //
            // This handed the template a `recount_said`, read out of
            // `$_SESSION['flash_ok']`, and it was ALWAYS NULL. The container's
            // `Twig::class` factory reads every flash key into a Twig global and
            // `unset()`s them in the same closure, and this controller cannot exist until
            // that closure has run — Twig arrives through its constructor. So the key was
            // gone before the line that read it, and the second render slot the template
            // drew for it never fired once.
            //
            // It cost nobody a message, because the report still shows: the admin layout
            // renders `flash_ok` at the top of every page. That is the platform's one slot
            // for "here is what just happened", and one slot is the point — this screen's
            // own margin note argues it at length about a fact stated in two places. Every
            // POST here writes a flash and redirects; the banner says which.
            //
            // `FlashKeyTest::test_no_controller_re_reads_a_flash_key_the_container_consumes`
            // fails on the next copy of this.
        ]);
    }

    /**
     * POST /admin/result-release/check-record — walk the chain and report what it says.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE PROMISE HAD NO ROUTE IN
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see \AfricaGates\Services\SnapshotService::verify()} re-walks every link in
     * `gates_vote_snapshots` and is the whole substance of what the help centre tells the
     * public: "the result is written down and sealed… there is no quiet edit available.
     * There is only an edit that announces itself", and "the chain is re-verified
     * automatically every day… a break is not a warning in a log somebody might read."
     *
     * The daily half was true — {@see \AfricaGates\Support\Maintenance} runs it and
     * throws, which reaches `gates_cron_log` and the webcron body. What did not exist was
     * any way for a PERSON to ask. Its only other caller is a console command, on a host
     * with no SSH. So an operator about to sign off an award, or answering a nominee who
     * has asked whether the numbers were altered, could not find out.
     *
     * A POST, for the same reason the other two are: as a link this could be fired by a
     * prefetch or a reload, and it walks the whole archive.
     *
     * ── IT REPORTS, AND DOES NOT ACCUSE ──────────────────────────────────────
     *
     * The overwhelmingly likelier cause of a break is two captures having forked the
     * chain — which `UNIQUE(prev_hash)` now forbids, and an archive written before that
     * index can still carry one. "Somebody edited the results" is alarming and usually
     * wrong. It also says nothing is lost, because that is the first thing an operator
     * fears on bad news about a record.
     */
    public function checkRecord(Request $req, Response $res): Response
    {
        // Parenthesised, and the coalesce inside the cast. `(int) $b['cycle'] ?? 0` casts
        // first, so the `??` can never fire and a missing key is a PHP warning instead of
        // a default — the same shape that makes a crafted request noisier than a real one.
        $b    = (array) $req->getParsedBody();
        $cid  = (int) ($b['cycle'] ?? 0);
        $back = '/admin/result-release' . ($cid > 0 ? '?cycle=' . $cid : '');

        try {
            $v = (new \AfricaGates\Services\SnapshotService())->verify();
        } catch (\Throwable $e) {
            error_log('[result-release] chain check: ' . $e->getMessage());
            // flash_error and not flash_ok: the layout draws the first with a warning rule
            // and `role="alert"`, the second with a green tick. "Could not be read" is
            // about this DEPLOYMENT and "does not verify" is about the STANDINGS — opposite
            // facts, and a tick over the first sends nobody to the right place.
            $_SESSION['flash_error'] = 'The record could not be read, so nothing was '
                . 'checked. That is a fault to investigate, not a finding about the '
                . 'standings.';
            return $res->withHeader('Location', $back)->withStatus(303);
        }

        $unchained = (int) $v['unchained'];
        // Counted, never folded in. "Verified 40,000 rows" and "verified 40,000 rows, and
        // there are 900 older ones nothing can vouch for" are different claims, and the
        // second is the true one. See verify()'s own note on `prev_hash`.
        $tail = $unchained > 0
            ? sprintf(' %d older row(s) were written before the chain existed and sit '
                    . 'outside it — they vouch for nothing, in either direction.', $unchained)
            : '';

        if ($v['ok']) {
            $_SESSION['flash_ok'] = sprintf(
                'The record verifies. %d row(s) checked, each following from the one before '
                . 'it, so no standing has been altered since it was written.%s',
                (int) $v['checked'], $tail);
        } else {
            $_SESSION['flash_error'] = sprintf(
                'The record does NOT verify — it stops following from itself at row #%d, '
                . 'after %d good row(s). The likeliest cause is two captures having forked '
                . 'the chain rather than anybody editing a standing. Nothing here is lost: '
                . 'every row is still present.%s',
                (int) $v['broken_at'], (int) $v['checked'], $tail);
        }

        // Swallowed the same way the recount's is: an audit row that cannot be written
        // must not cost the operator the answer they came for. The verdict travels on the
        // row, not just the fact of a check — "who looked, and what did it say then" is
        // the question an audit of a disputed release brings.
        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($_SESSION['admin_id'] ?? 0), 'results.chain_check', 'cycle', $cid,
                ['ok' => $v['ok'], 'checked' => $v['checked'], 'broken_at' => $v['broken_at'],
                 'unchained' => $unchained]);
        } catch (\Throwable) {}

        return $res->withHeader('Location', $back)->withStatus(303);
    }

    /**
     * POST /admin/result-release/recount — rebuild one category's counters from the ballots.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS A POST ON A SCREEN THAT OTHERWISE WRITES NOTHING
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The index above audits a release and touches nothing, deliberately — a screen that
     * could crown somebody by being looked at is not an audit. This is the one write, and
     * it is a POST for exactly that reason: as a link it could be fired by a prefetch, a
     * bookmark or a reload, on the numbers an award is decided by.
     *
     * It repairs a DISCREPANCY and cannot invent support. `gates_votes` is the ledger and
     * the counters are a cache of it; this makes the cache agree.
     *
     * It also cannot DESTROY support. A nominee carrying a stored total with not one ballot
     * row behind it has an absent ledger rather than a drifted counter, and that number is
     * then the only surviving record the support existed; {@see VoteRecount::applyNominee()}
     * refuses those and hands the refusal back. This screen has to say so by name, because
     * a refusal and a category that already agreed look identical from the operator's chair
     * — nothing moved either way — and they call for opposite next steps.
     */
    public function recount(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $catId = (int) ($b['category'] ?? 0);
        $cycle = (int) ($b['cycle'] ?? 0);
        $back  = '/admin/result-release' . ($cycle > 0 ? '?cycle=' . $cycle : '');

        if ($catId < 1) {
            $_SESSION['flash_ok'] = 'No category was named, so nothing was recounted.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $r = \AfricaGates\Services\VoteRecount::category($catId);

        // Named nominee by nominee. "3 rows updated" on the figures an award turns on is
        // not a report anybody can check, and this is the one action on this screen that
        // changes a result.
        if ($r['changed'] === []) {
            $_SESSION['flash_ok'] = 'Recounted ' . $r['checked'] . ' nominee'
                . ($r['checked'] === 1 ? '' : 's') . ' against the ballots — every stored '
                . 'total already agreed, so nothing changed. The missing community half is '
                . 'not a drifted counter; those votes are genuinely not organic.';
        } else {
            $said = $held = [];
            foreach ($r['changed'] as $c) {
                if (isset($c['refused'])) {
                    $held[] = $c['name'] . ' (' . $c['was']['vote_count'] . ' stored)';
                    continue;
                }
                $said[] = $c['name'] . ': ' . $c['was']['organic_vote_count'] . ' → '
                        . $c['now']['organic_vote_count'] . ' organic of '
                        . $c['now']['vote_count'];
            }

            $msg = 'Recounted ' . $r['checked'] . ' nominee'
                 . ($r['checked'] === 1 ? '' : 's') . '; ' . count($said) . ' corrected'
                 . ($said === [] ? '' : ' — ' . implode(' · ', $said)) . '.';

            // The refusal is the more urgent half of the report, so it goes last, where the
            // eye lands. "Nothing moved" is what an operator sees whether the counters were
            // already right or the ballots are gone, and those need opposite next steps.
            if ($held !== []) {
                $msg .= ' Left alone: ' . implode(' · ', $held) . ' — there is not one ballot '
                      . 'row on record for ' . (count($held) === 1 ? 'this nominee' : 'these '
                      . 'nominees') . ', so the stored total is the only surviving record '
                      . 'that the support existed. That is a restore or an import to '
                      . 'investigate, not a counter to rebuild, and a recount would have '
                      . 'erased it.';
            }

            $_SESSION['flash_ok'] = $msg;
        }

        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                (int) ($_SESSION['admin_id'] ?? 0), 'results.recount', 'category', $catId,
                ['checked' => $r['checked'], 'changed' => $r['changed']]);
        } catch (\Throwable) {}

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * PUBLISH AN EDITION, AS AN ACT.
     *
     * ── WHY THIS BUTTON HAS TO EXIST ────────────────────────────────────────
     *
     * Nothing could release a cycle except the date-driven sweep, and the sweep will not
     * revisit one: the transitions ledger's UNIQUE (cycle_id, to_status) is its claim, so
     * once `results` is claimed the side effects never fire again. A programme whose
     * results ran late therefore reached `results` with its announcements deliberately
     * suppressed and its standing deliberately unsealed — the honest state, since nothing
     * had been announced — and there was then no way for anybody to seal it. The public
     * page went on publishing live figures labelled as recomputed, for ever.
     *
     * `CycleService::manualTransitionError()` still refuses a hand-set `results`, and this
     * does not relax it. An operator does not write the status: they ask
     * {@see \AfricaGates\Services\CycleMaterialiser::release()} for a release, and the
     * same quorum-checked promotion and the same seal run as on the scheduled path. That
     * is what keeps the standing tamper-evident while making the release a decision
     * somebody made.
     *
     * Confirmed in the browser through `data-confirm`, because it is the one action here
     * that tells a nominee they won.
     */
    public function release(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $cycle = (int) ($b['cycle'] ?? 0);
        $back  = '/admin/result-release' . ($cycle > 0 ? '?cycle=' . $cycle : '');
        $admin = (int) ($_SESSION['admin_id'] ?? 0);

        if ($cycle < 1) {
            $_SESSION['flash_error'] = 'No edition was named, so nothing was released.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $r = (new \AfricaGates\Services\CycleMaterialiser())->release($cycle, $admin);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = (string) $r['message'];

        // Recorded whatever the outcome. A refused release is the more interesting row:
        // it is somebody trying to publish an edition the platform would not stand behind.
        try {
            (new \AfricaGates\Admin\Services\AuditService())->record(
                $admin, 'results.release', 'cycle', $cycle,
                ['ok' => $r['ok'], 'promoted' => $r['promoted'], 'sealed' => $r['sealed'],
                 'message' => $r['message']]);
        } catch (\Throwable) {}

        return $res->withHeader('Location', $back)->withStatus(302);
    }
}
