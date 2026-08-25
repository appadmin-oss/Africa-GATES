<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\CollusionService;
use AfricaGates\Services\FraudService;
use AfricaGates\Services\IntegrityBriefService;
use AfricaGates\Services\JudgeAnomalyService;
use AfricaGates\Services\JudgeBiasService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Everything the platform knows about whether its own results can be trusted, on one page.
 *
 * ── WHY IT NEEDED TO BE ONE PAGE ─────────────────────────────────────────────
 *
 * The signals already existed and were scattered: vote fraud on the vote-delivery screen,
 * collusion rings behind the dashboard, judge anomalies inside the judges list, and an AI
 * brief in a box on the dashboard that summarised things the reader could not then go and
 * look at. Four surfaces answering one question badly.
 *
 * The question is asked at a specific moment — somebody has challenged a result, or is
 * about to publish one — and at that moment an organiser needs everything in one place with
 * the caveats attached, not four screens they have to remember exist.
 *
 * ── AND WHY THE CAVEATS ARE NOT OPTIONAL FURNITURE ───────────────────────────
 *
 * Every number here is a question about a named person. Judge bias in particular is
 * arithmetic over small samples: with twenty judges across three axes, some leans look
 * striking by chance, and a screen that shows the striking ones without the denominator is
 * how somebody gets confronted over noise.
 *
 * So {@see JudgeBiasService} returns its comparison count and its own caveat sentence, and
 * this screen prints them next to the findings rather than under them.
 */
final class IntegrityController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $cycleId = (int) (($req->getQueryParams()['cycle'] ?? 0));
        $cycles  = $this->cycles();

        // Default to the cycle the bias check itself would pick, so the page and the
        // dashboard brief never disagree about which cycle they are describing.
        if ($cycleId < 1) $cycleId = (int) ($cycles[0]['id'] ?? 0);

        $bias = $cycleId > 0
            ? JudgeBiasService::forCycle($cycleId)
            : ['findings' => [], 'comparisons' => 0, 'judges' => 0, 'scores' => 0,
               'thin' => [], 'note' => 'There are no cycles to check yet.'];

        $collusion = ['open' => [], 'summary' => []];
        try {
            $svc = new CollusionService();
            $collusion = ['open' => $svc->openFindings(25), 'summary' => $svc->summary()];
        } catch (\Throwable) {
        }

        $anomalies = ['flags' => [], 'judges' => []];
        try {
            $anomalies = (new JudgeAnomalyService())->scanActive();
        } catch (\Throwable) {
        }

        // The fourth signal, and the one this page's own docblock lists FIRST. Every vote
        // has been scored and stamped since fraud detection shipped and none of it had a
        // screen — FraudService::summary() was written for a panel nobody built, and was
        // called from nowhere at all.
        $fraud = [];
        try {
            $fraud = (new FraudService())->summary();
        } catch (\Throwable) {
        }

        return $this->view->render($res, 'admin/integrity.twig', [
            'page_title' => 'Integrity',
            'admin_page' => 'integrity',
            'cycles'     => $cycles,
            'cycle_id'   => $cycleId,
            'bias'       => $bias,
            'collusion'  => $collusion,
            'anomalies'  => $anomalies,
            'fraud'      => $fraud,
            'may_review' => Permissions::canManageIntegrity((string) ($_SESSION['admin_role'] ?? '')),
            // The narrative, but never instead of the tables. It is written from the same
            // numbers shown below it, so a reader who distrusts it can check every claim.
            'brief'      => IntegrityBriefService::brief(),
            'axes'       => JudgeBiasService::AXES,
            'min_obs'    => JudgeBiasService::MIN_OBSERVATIONS,
            'min_effect' => JudgeBiasService::MIN_EFFECT,
        ]);
    }

    /**
     * Mark scored vote attempts as looked at.
     *
     * NOT a verdict on the votes. The flag stays, the risk score is unchanged, and the vote
     * itself is untouched — this records that a person read the queue, which is the only
     * thing an "unreviewed" count can honestly mean. Withdrawing a vote is a different act
     * with its own trail.
     *
     * Without this the counter was monotonic: `reviewed` was read by the summary and
     * written by nothing, so the number could only go up and the registry's Reviewed column
     * was false on every row that had ever existed. A queue nobody can clear is a queue
     * nobody works.
     */
    public function reviewFraud(Request $req, Response $res): Response
    {
        $back = $res->withHeader('Location', '/admin/integrity#fraud')->withStatus(302);

        if (!Permissions::canManageIntegrity((string) ($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'You do not have permission to work the integrity queue.';
            return $back;
        }

        $ids = (array) (((array) $req->getParsedBody())['ids'] ?? []);
        $n   = (new FraudService())->markReviewed(
            array_map('intval', $ids),
            (int) ($_SESSION['admin_id'] ?? 0)
        );

        $_SESSION['flash_ok'] = $n === 0
            ? 'Nothing to mark — those were already reviewed.'
            : $n . ' flagged attempt' . ($n === 1 ? '' : 's') . ' marked as reviewed. The flags stay on the record.';

        return $back;
    }

    /**
     * Cycles worth checking, judging first.
     *
     * @return list<array<string,mixed>>
     */
    private function cycles(): array
    {
        try {
            $q = DB::table('gates_award_cycles as c')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id');

            // The sandbox is not one of the cycles this page is about — and it would be
            // the DEFAULT one. The picker sorts `judging` first and newest id first, and
            // the practice cycle is both, so on a fresh install the integrity page opened
            // on the rehearsal and reported it as the platform's panel.
            \AfricaGates\Services\DemoSeeder::notSandbox($q, 'c.programme_id');

            $rows = $q
                ->orderByRaw("CASE c.status WHEN 'judging' THEN 0 WHEN 'results' THEN 1 "
                           . "WHEN 'shortlisting' THEN 2 ELSE 3 END")
                ->orderByDesc('c.id')
                ->limit(20)
                ->get(['c.id', 'c.year', 'c.status', 'c.edition_label', 'p.title as programme']);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn ($r): array => [
            'id'     => (int) $r->id,
            'label'  => trim(((string) ($r->programme ?? 'Programme')) . ' · '
                           . ((string) ($r->edition_label ?: $r->year))),
            'status' => (string) $r->status,
        ], $rows->all());
    }
}
