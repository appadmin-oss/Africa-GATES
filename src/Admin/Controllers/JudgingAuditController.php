<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\JudgingAudit;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * How one award programme judges, and who has been judging it.
 *
 * ── WHY THIS IS NOT THE INTEGRITY PAGE ───────────────────────────────────────
 *
 * {@see IntegrityController} answers "is this cycle's result sound", which is asked in
 * the hours before a result is published and is about one cycle by definition.
 *
 * This answers "how does this award decide, and who has been deciding it" — asked when a
 * result is challenged weeks later, when a panellist is reappointed, or when somebody
 * wants to know whether the award means anything. A programme runs for years, its panel
 * turns over and its rubric changes, so a screen that can only show one cycle cannot
 * answer it. Putting a programme selector on the integrity page instead would have made
 * one screen answer two questions at two altitudes, which is how both get answered badly.
 *
 * The two link to each other rather than repeat each other: bias arithmetic stays on
 * Integrity, where its caveats already live.
 */
final class JudgingAuditController
{
    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res): Response
    {
        $programmes = DB::table('gates_award_programmes')
            ->orderBy('sort_order')->orderBy('title')
            ->get()->map(static fn (object $r): array => (array) $r)->all();

        $wanted = (int) ($req->getQueryParams()['programme'] ?? 0);
        if ($wanted < 1) $wanted = (int) ($programmes[0]['id'] ?? 0);

        // Never throws to the screen. Every part of this reads tables that a given
        // deployment may not have migrated yet, and an audit that 500s is an audit
        // nobody can use to answer the question that brought them here.
        $audit = ['programme' => null, 'cycles' => [], 'judges' => [], 'changes' => [],
                  'conflicts' => [], 'totals' => ['cycles' => 0, 'nominees' => 0, 'judged' => 0,
                                                  'unjudged' => 0, 'judges' => 0, 'scores' => 0]];
        $failed = false;
        if ($wanted > 0) {
            try {
                $audit = JudgingAudit::forProgramme($wanted);
            } catch (\Throwable $e) {
                error_log('[judging-audit] ' . $e->getMessage());
                $failed = true;
            }
        }

        return $this->view->render($res, 'admin/judging-audit.twig', [
            'page_title'   => 'Judging audit',
            'admin_page'   => 'judging-audit',
            'programmes'   => $programmes,
            'programme_id' => $wanted,
            'audit'        => $audit,
            // Said out loud rather than rendered as an empty table. "No changes recorded"
            // and "the query failed" look identical on a screen and mean opposite things.
            'failed'       => $failed,
            // Maps the panel has disputed. Here rather than on an AI screen because the
            // question it raises is a judging one — whether an entry has been read to the
            // panel wrongly — and this is the page somebody opens when asking how an award
            // was decided. A flag nobody looks at is a complaint collected and discarded,
            // which is worse than no button at all.
            'disputed'     => \AfricaGates\Services\JudgeAssist::disputed(25),
        ]);
    }
}
