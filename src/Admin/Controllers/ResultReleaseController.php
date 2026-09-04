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
 * No promotion, no demotion, no announcement, no writes. A screen that could crown
 * somebody by being looked at is not an audit of a release, and the promotion has to stay
 * where the phase engine can keep it idempotent. What it shows is what the promotion will
 * do, because it asks the promotion's own comparator rather than a copy of it.
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
            // What to look at first: categories that crown nobody, dead heats, margins
            // thin enough to turn on one mark. Counted here rather than in the template
            // because a template deriving it would be a second opinion about what needs
            // attention.
            'attention'  => ResultRelease::attention($categories),
            // ── THE ONE AWARD FOR THE WHOLE CYCLE ────────────────────────────
            //
            // Passed the categories this page already drew rather than the cycle id: it is
            // computed from the category winners, and letting it re-run `forCycle()` would
            // score every nominee in the cycle a second time to reach the same answer.
            'overall'    => $wanted > 0 && !$failed
                            ? ResultRelease::overall($wanted, $categories)
                            : null,
            // Said out loud rather than rendered as an empty table. "Nothing scored yet"
            // and "the query failed" look identical on a screen and mean opposite things.
            'failed'     => $failed,
            // What the last repair did, said once and then gone. An operator who has just
            // rewritten the numbers an award is decided on must be told what moved.
            'recount_said' => (function (): ?string {
                $m = $_SESSION['flash_ok'] ?? null;
                unset($_SESSION['flash_ok']);
                return is_string($m) && $m !== '' ? $m : null;
            })(),
        ]);
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
}
