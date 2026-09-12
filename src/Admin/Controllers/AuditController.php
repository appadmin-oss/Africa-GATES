<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\AuditTargets;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Who did what, to which record, from where.
 *
 * ── THE HOLE THIS FILLS ──────────────────────────────────────────────────────
 *
 * `gates_audit_log` is written from 124 places across every admin controller. It could
 * be read two ways, and neither answered a question:
 *
 *   • the dashboard's last twelve rows — under a minute of activity on a busy morning;
 *   • `/admin/data/audit-log`, the generic table dump, where the admin is an integer,
 *     the target is an integer, `meta` is not a column, and the search box covers the
 *     action string alone.
 *
 * So on a host with no shell there was no way to ask: what has this admin been doing;
 * everything that ever happened to this nominee; who changed the payment settings last
 * month; was that run from the same machine as the rest of the session. Every one of
 * those facts was on disk. None could be reached. See {@see AuditService} for the full
 * account, including the two columns — `ip_hash` and `ua` — that have been written on
 * every row since the table shipped and rendered nowhere at all.
 *
 * ── THREE VIEWS, ONE QUERY ───────────────────────────────────────────────────
 *
 * The log ({@see index}), one record's whole history ({@see target}), and one admin's
 * ({@see actor}). All three are {@see AuditService::search()} with different filters —
 * not three queries, because three readers of one table is how they come to disagree
 * about what a row means.
 *
 * ── IT WRITES NOTHING, INCLUDING ITSELF ──────────────────────────────────────
 *
 * Deliberately not audited. A log that records being read fills with rows about itself,
 * and the noise buries the actions somebody opened it to find.
 */
final class AuditController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    /** The log, filtered. */
    public function index(Request $req, Response $res): Response
    {
        $qp = $req->getQueryParams();

        $filters = [
            'admin'       => ($qp['admin'] ?? '') === '' ? null : (int) $qp['admin'],
            'area'        => trim((string) ($qp['area'] ?? '')),
            'action'      => trim((string) ($qp['action'] ?? '')),
            'target_type' => trim((string) ($qp['type'] ?? '')),
            'target_id'   => (int) ($qp['tid'] ?? 0) ?: null,
            'q'           => trim((string) ($qp['q'] ?? '')),
            'from'        => trim((string) ($qp['from'] ?? '')),
            'to'          => trim((string) ($qp['to'] ?? '')),
            'page'        => max(1, (int) ($qp['page'] ?? 1)),
        ];

        $result = $this->audit->search($filters);
        $facets = $this->audit->facets();

        return $this->view->render($res, 'admin/audit.twig', [
            'page_title' => 'Audit log',
            'admin_page' => 'audit',
            'result'     => $result,
            'facets'     => $facets,
            // What the chosen area actually contains. Thirty-odd areas make a usable
            // select and the hundred actions under them do not, so they are disclosed
            // once an area is picked rather than offered all at once.
            'areaActions' => self::actionsIn($facets, $filters['area']),
            'f'          => $filters,
            'active'     => $this->activeCount($filters),
            'qs'         => $this->qs($qp),
            // Built here, not by string-surgery on `qs` in the template: an action
            // whose encoding differs between http_build_query and Twig's url_encode
            // would silently fail to match and the chip would stop coming off.
            'qs_no_action' => $this->qs(array_diff_key($qp, ['action' => 1])),
            'window'     => $this->window($result['page'], $result['pages']),
            // The form posts back to wherever it is, so re-filtering from the per-admin
            // view keeps that view. Hardcoding /admin/audit dropped the summary card the
            // moment somebody touched a filter, which reads as the page losing its place.
            'form_action' => '/admin/audit',
            // The target of a per-record filter, named — so the heading says whose
            // history this is rather than repeating the id already in the URL.
            'subject'    => $this->subject($filters),
        ]);
    }

    /**
     * Everything that ever happened to one record.
     *
     * A route rather than only a filter, because this is the view somebody arrives at
     * from another screen ("show me this nominee's history") and a link has to be
     * writable by hand from a type and an id.
     */
    public function target(Request $req, Response $res, array $args): Response
    {
        $type = AuditTargets::canonical((string) ($args['type'] ?? ''));
        $id   = (int) ($args['id'] ?? 0);

        return $this->redirect($res, '/admin/audit?' . http_build_query(
            array_filter(['type' => $type, 'tid' => $id > 0 ? $id : null])
        ));
    }

    /** One admin's whole trail, with the summary that makes it readable. */
    public function actor(Request $req, Response $res, array $args): Response
    {
        $adminId = (int) ($args['id'] ?? 0);
        $qp      = $req->getQueryParams();

        $filters = [
            'admin' => $adminId,
            'area'  => trim((string) ($qp['area'] ?? '')),
            'q'     => trim((string) ($qp['q'] ?? '')),
            'from'  => trim((string) ($qp['from'] ?? '')),
            'to'    => trim((string) ($qp['to'] ?? '')),
            'page'  => max(1, (int) ($qp['page'] ?? 1)),
        ];

        $result = $this->audit->search($filters);
        $facets = $this->audit->facets();

        return $this->view->render($res, 'admin/audit.twig', [
            'page_title' => 'Audit log',
            'admin_page' => 'audit',
            'result'     => $result,
            'facets'     => $facets,
            'areaActions' => self::actionsIn($facets, $filters['area']),
            'f'          => $filters + ['action' => '', 'target_type' => '', 'target_id' => null],
            'active'     => $this->activeCount($filters),
            'qs'         => $this->qs($qp + ['admin' => $adminId]),
            'qs_no_action' => $this->qs(array_diff_key($qp + ['admin' => $adminId], ['action' => 1])),
            'window'     => $this->window($result['page'], $result['pages']),
            'subject'    => null,
            'form_action' => '/admin/audit/actor/' . $adminId,
            // Volume, span, and how many distinct networks it came from. The last of
            // those is what `ip_hash` was recorded for and it has never been shown:
            // two networks over a year is ordinary, nine in a week beside a run of
            // destructive actions is a shared or stolen session.
            'actor'      => $this->audit->actorSummary($adminId ?: null),
            'actor_id'   => $adminId,
        ]);
    }

    /**
     * The actions filed under one area, from the facets already fetched.
     *
     * @param array<string,mixed> $facets
     * @return list<array<string,mixed>>
     */
    private static function actionsIn(array $facets, string $area): array
    {
        if ($area === '') return [];
        foreach ($facets['areas'] ?? [] as $a) {
            if (($a['area'] ?? null) === $area) return $a['actions'] ?? [];
        }
        return [];
    }

    /** How many filters are narrowing the view — so "0 results" can be read correctly. */
    private function activeCount(array $f): int
    {
        $n = 0;
        foreach (['admin', 'area', 'action', 'target_type', 'target_id', 'q', 'from', 'to'] as $k) {
            if (($f[$k] ?? null) !== null && ($f[$k] ?? '') !== '') $n++;
        }
        return $n;
    }

    /** The current filters as a query string, minus the page — for the pager links. */
    private function qs(array $qp): string
    {
        unset($qp['page']);
        $qp = array_filter($qp, static fn ($v): bool => $v !== null && $v !== '');
        return $qp === [] ? '' : '&' . http_build_query($qp);
    }

    /** A short run of page numbers around the current one. */
    private function window(int $page, int $pages): array
    {
        $from = max(1, $page - 3);
        $to   = min($pages, $from + 6);
        $from = max(1, $to - 6);
        return range($from, $to);
    }

    /** The named record a per-target filter is about, or null. */
    private function subject(array $f): ?array
    {
        $type = (string) ($f['target_type'] ?? '');
        $id   = (int) ($f['target_id'] ?? 0);
        if ($type === '' || $id < 1) return null;

        return ['label' => AuditTargets::label($type),
                'name'  => AuditTargets::name($type, $id),
                'href'  => AuditTargets::href($type, $id),
                'id'    => $id];
    }

    private function redirect(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }
}
