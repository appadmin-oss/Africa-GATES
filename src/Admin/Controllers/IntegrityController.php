<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\CollusionService;
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

        return $this->view->render($res, 'admin/integrity.twig', [
            'page_title' => 'Integrity',
            'admin_page' => 'integrity',
            'cycles'     => $cycles,
            'cycle_id'   => $cycleId,
            'bias'       => $bias,
            'collusion'  => $collusion,
            'anomalies'  => $anomalies,
            // The narrative, but never instead of the tables. It is written from the same
            // numbers shown below it, so a reader who distrusts it can check every claim.
            'brief'      => IntegrityBriefService::brief(),
            'axes'       => JudgeBiasService::AXES,
            'min_obs'    => JudgeBiasService::MIN_OBSERVATIONS,
            'min_effect' => JudgeBiasService::MIN_EFFECT,
        ]);
    }

    /**
     * Cycles worth checking, judging first.
     *
     * @return list<array<string,mixed>>
     */
    private function cycles(): array
    {
        try {
            $rows = DB::table('gates_award_cycles as c')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
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
