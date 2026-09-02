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
            // Said out loud rather than rendered as an empty table. "Nothing scored yet"
            // and "the query failed" look identical on a screen and mean opposite things.
            'failed'     => $failed,
        ]);
    }
}
