<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Services\UploadService;
use AfricaGates\Admin\Support\ActionError;
use AfricaGates\Support\Filters;
use AfricaGates\Support\Paginator;

class NomineesController
{
    private const PER_PAGE = 60;

    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
        private readonly ?UploadService $uploads = null,
    ) {}

    /** How the list may be ordered. The default is the old one: most votes first. */
    private const SORTS = [
        'votes'  => ['n.vote_count', 'desc'],
        'name'   => ['n.name', 'asc'],
        'newest' => ['n.nominated_at', 'desc'],
    ];

    /**
     * The nominee list's filters, read once, so the page, the CSV and the bulk action all
     * mean the same rows by the same words (a CSV that quietly ignored the category filter
     * would be a second list claiming to be this one).
     *
     * @return array{cycle:int, category:int, status:string, q:string, sort:string}
     */
    private static function filtersFrom(array $p): array
    {
        $status = (string)($p['status'] ?? '');
        $sort = (string)($p['sort'] ?? 'votes');
        return [
            'cycle'    => (int)($p['cycle'] ?? 0),
            'category' => (int)($p['category'] ?? 0),
            // A status outside the column's ENUM is zero rows, not an error (CLAUDE.md) —
            // refused here rather than presented as an empty, measured-looking list.
            'status'   => in_array($status, self::STATUSES, true) ? $status : '',
            'q'        => mb_substr(trim((string)($p['q'] ?? '')), 0, 120),
            'sort'     => isset(self::SORTS[$sort]) ? $sort : 'votes',
        ];
    }

    /** gates_nominees.status, exactly as the column allows it. */
    public const STATUSES = ['pending', 'approved', 'winner', 'runner_up'];

    /** The filtered list, before projection, ordering and paging. */
    private static function filtered(array $f, array $p): array
    {
        $base = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c','c.id','=','n.category_id')
            ->join('gates_award_cycles as cy','cy.id','=','c.cycle_id')
            ->join('gates_award_programmes as p','p.id','=','cy.programme_id');
        \AfricaGates\Services\MergeService::notMerged($base, 'n.merged_into');   // hide merge tombstones from the list
        if ($f['cycle'])    $base->where('cy.id', $f['cycle']);
        if ($f['category']) $base->where('c.id', $f['category']);
        if ($f['status'])   $base->where('n.status', $f['status']);
        // Name, organisation or short line: an operator looking for "the Kano school" types
        // the school, not the teacher.
        if ($f['q'] !== '') {
            $like = \AfricaGates\Support\Like::contains($f['q']);
            $base->where(function ($w) use ($like) {
                $w->whereRaw(\AfricaGates\Support\Like::clause('n.name'), [$like])
                  ->orWhereRaw(\AfricaGates\Support\Like::clause('n.tagline'), [$like]);
                if (\AfricaGates\Support\SchemaHas::column('gates_nominees', 'organisation')) {
                    $w->orWhereRaw(\AfricaGates\Support\Like::clause('n.organisation'), [$like]);
                }
            });
        }
        $dateMeta = Filters::applyDateRange($base, 'n.nominated_at', $p);
        return [$base, $dateMeta];
    }

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $f = self::filtersFrom($p);
        $cycleId = $f['cycle']; $status = $f['status']; $q = $f['q'];
        $page    = max(1, (int)($p['page'] ?? 1));

        // One base query (search + date range), cloned for the count and the page
        // slice so they stay in lockstep.
        [$base, $dateMeta] = self::filtered($f, $p);
        // Status counts for the filtered list (status filter aside), so the chips say how
        // many are waiting before anybody clicks — the "needs attention" number.
        $counts = (clone self::filtered(['status' => ''] + $f, $p)[0])->groupBy('n.status')
            ->selectRaw('n.status as s, COUNT(*) as k')->pluck('k', 's')->all();

        // Fold the profile lookup (1:1, so the count is unaffected) + projection +
        // ordering onto the base, then paginate so nominees beyond the old hard
        // 200-row cap remain reachable.
        $base->leftJoin('gates_profiles as pr','pr.id','=','n.profile_id')
            ->select(['n.id','n.name','n.tagline','n.country_code','n.vote_count','n.status','n.photo_path','n.profile_id','c.title as category','p.title as programme','cy.id as cycle_id','cy.year','pr.slug as profile_slug','pr.display_name as profile_name'])
            ->orderBy(self::SORTS[$f['sort']][0], self::SORTS[$f['sort']][1])->orderBy('n.id');
        $pg    = Paginator::paginate($base, $page, self::PER_PAGE);
        $rows  = $pg['rows'];
        $total = $pg['total']; $pages = $pg['pages']; $page = $pg['page'];

        // Batch the photo galleries for this page in ONE query (gates_uploads
        // rows attached to each nominee), grouped by nominee id — avoids an
        // N+1 while giving each row its extra (non-primary) photos.
        $rowIds  = $rows->map(fn($r)=>(int)$r->id)->all();
        $gallery = [];
        if ($rowIds) {
            try {
                foreach (\AfricaGates\Services\NomineePortrait::uploads($rowIds)
                    ->orderBy('id')->get(['attached_to_id', 'path']) as $u) {
                    $gallery[(int)$u->attached_to_id][] = (string)$u->path;
                }
            } catch (\Throwable) {}
        }
        $rowsOut = $rows->map(function ($r) use ($gallery) {
            $a = (array) $r;
            // Primary first, then any additional gallery images (deduped).
            $paths = [];
            if (!empty($a['photo_path'])) $paths[] = (string)$a['photo_path'];
            foreach ($gallery[(int)$a['id']] ?? [] as $p) { if ($p !== '' && !in_array($p, $paths, true)) $paths[] = $p; }
            $a['photos'] = $paths;
            return $a;
        })->all();

        $cycles = DB::table('gates_award_cycles as c')
            ->join('gates_award_programmes as p','p.id','=','c.programme_id')
            ->select(['c.id','c.year','p.title'])->orderByDesc('c.year')->get();

        // Leading '&' so the existing template's `?page=N{{ qs }}` links keep every
        // active filter (previously `qs` was undefined — pagination dropped filters).
        $keep = ['cycle' => $cycleId ?: '', 'category' => $f['category'] ?: '', 'status' => $status, 'q' => $q,
            'sort' => $f['sort'] === 'votes' ? '' : $f['sort'], 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']];
        $qsBuilt = Filters::qs($keep);
        // The categories a filter can name: the chosen cycle's, or — with no cycle chosen —
        // the category already picked (a link from the programme screen arrives that way).
        $catQ = DB::table('gates_award_categories as c')->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id');
        if ($cycleId) $catQ->where('c.cycle_id', $cycleId);
        elseif ($f['category']) $catQ->where('c.id', $f['category']);
        else $catQ->whereRaw('1 = 0');
        $categories = $catQ->orderBy('c.sort_order')->orderBy('c.title')
            ->get(['c.id', 'c.title', 'p.title as programme', 'cy.year'])->map(fn ($r) => (array) $r)->all();
        return $this->view->render($res, 'admin/nominees/index.twig', [
            'page_title' => 'Nominees — Admin',
            'admin_page' => 'nominees',
            'rows'       => $rowsOut,
            'cycles'     => $cycles->map(fn($r)=>(array)$r)->all(),
            'filters'    => ['cycle' => $cycleId, 'category' => $f['category'], 'status' => $status, 'q' => $q, 'sort' => $f['sort'], 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']],
            'categories' => $categories,
            'counts'     => array_map('intval', $counts),
            'statuses'   => self::STATUSES,
            'export_qs'  => $qsBuilt,
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'per'        => self::PER_PAGE,
            'qs'         => $qsBuilt !== '' ? '&' . $qsBuilt : '',
            'merged'     => \AfricaGates\Services\MergeService::recentlyMerged(),   // tombstones, with an undo action
        ]);
    }

    /**
     * GET /admin/nominees/export.csv — the list on screen, every page of it, as a spreadsheet.
     * Same filters as the page by construction (filtersFrom + filtered). Cells go through
     * Support\Csv, because a nominee's name is typed by a member of the public and a cell
     * beginning "=" is a formula in whatever opens the file.
     */
    public function export(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $f = self::filtersFrom($p);
        [$base] = self::filtered($f, $p);
        $hasOrg = \AfricaGates\Support\SchemaHas::column('gates_nominees', 'organisation');
        $rows = $base->leftJoin('gates_profiles as pr', 'pr.id', '=', 'n.profile_id')
            ->orderBy(self::SORTS[$f['sort']][0], self::SORTS[$f['sort']][1])->orderBy('n.id')
            ->limit(20000)
            ->get(array_merge(['n.id', 'n.name', 'n.tagline', 'n.country_code', 'n.vote_count', 'n.status', 'n.nominated_at',
                'c.title as category', 'p.title as programme', 'cy.year', 'pr.slug as profile_slug'],
                $hasOrg ? ['n.organisation'] : []));
        $out = fopen('php://temp', 'w+');
        \AfricaGates\Support\Csv::put($out, ['ID', 'Name', 'Short line', 'Organisation', 'Programme', 'Edition', 'Category',
            'Country', 'Status', 'Votes', 'Profile', 'Nominated']);
        foreach ($rows as $r) {
            \AfricaGates\Support\Csv::put($out, [(int)$r->id, $r->name, $r->tagline, $hasOrg ? $r->organisation : '', $r->programme,
                $r->year, $r->category, $r->country_code, str_replace('_', ' ', (string)$r->status), (int)$r->vote_count,
                $r->profile_slug ? '/registry/' . $r->profile_slug : '', $r->nominated_at]);
        }
        rewind($out);
        $res->getBody()->write((string)stream_get_contents($out));
        fclose($out);
        $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'nominee.export', 'nominee', 0, ['rows' => count($rows)] + $f);
        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
                   ->withHeader('Content-Disposition', 'attachment; filename="nominees-' . date('Y-m-d') . '.csv"');
    }

    /**
     * POST /admin/nominees/bulk — approve the ticked nominees, or send them back to
     * pending, in one press. Nobody works through two thousand entries one row at a time.
     *
     * DELIBERATELY NOT winner or runner-up: crowning somebody is an award decision made
     * one name at a time, with a reason on the audit row (see the row's More menu). And a
     * bulk action never touches a crowned nominee — "approve all" on a page that happens
     * to include last year's winner must not quietly uncrown them.
     */
    public function bulk(Request $req, Response $res): Response
    {
        $b = (array)$req->getParsedBody();
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        $to = ['approve' => 'approved', 'pending' => 'pending'][(string)($b['bulk_action'] ?? '')] ?? null;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['ids'] ?? [])))));
        if ($to === null || $ids === []) {
            $_SESSION['flash_error'] = $ids === [] ? 'Tick at least one nominee first.' : 'Choose what to do with the ticked nominees.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $ids = array_slice($ids, 0, 500);
        $q = DB::table('gates_nominees')->whereIn('id', $ids)->whereIn('status', ['pending', 'approved'])->where('status', '!=', $to);
        \AfricaGates\Services\MergeService::notMerged($q, 'merged_into');
        $changing = $q->pluck('id')->map(fn ($i) => (int)$i)->all();
        if ($changing) {
            DB::table('gates_nominees')->whereIn('id', $changing)->update(['status' => $to]);
            foreach ($changing as $id) {
                $this->audit->record((int)($_SESSION['admin_id'] ?? 0), $to === 'approved' ? 'nominee.approve' : 'nominee.remove', 'nominee', $id, ['bulk' => count($changing)]);
            }
            (new \AfricaGates\Services\CacheService())->forgetAwardViews();
        }
        $skipped = count($ids) - count($changing);
        $_SESSION['flash_ok'] = count($changing) . ' nominee' . (count($changing) === 1 ? '' : 's')
            . ($to === 'approved' ? ' approved' : ' returned to pending') . '.'
            . ($skipped ? " $skipped left as they were (already " . ($to === 'approved' ? 'approved' : 'pending') . ', or crowned).' : '');
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** GET /admin/nominees/{id}/edit — the details a nominator typed, correctable. */
    public function edit(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $n = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('n.id', $id)->first(['n.*', 'c.title as category', 'c.cycle_id', 'cy.year', 'p.title as programme']);
        if (!$n) throw new \Slim\Exception\HttpNotFoundException($req);
        $siblings = DB::table('gates_award_categories')->where('cycle_id', $n->cycle_id)->orderBy('sort_order')->orderBy('title')
            ->get(['id', 'title'])->map(fn ($r) => (array)$r)->all();
        // This record's own history: who changed it and when, read off the audit log every
        // nominee action already writes — "who renamed her?" should not need a table dump.
        $history = DB::table('gates_audit_log as l')->leftJoin('gates_admins as a', 'a.id', '=', 'l.admin_id')
            ->where('l.target_type', 'nominee')->where('l.target_id', $id)
            ->orderByDesc('l.id')->limit(15)->get(['l.action', 'l.meta', 'l.created_at', 'a.name as who'])
            ->map(static function ($r) {
                $meta = json_decode((string)$r->meta, true) ?: [];
                return ['action' => (string)$r->action, 'who' => $r->who ?: 'The platform', 'at' => (string)$r->created_at,
                    'changed' => array_values(array_map('strval', (array)($meta['changed'] ?? []))),
                    'reason' => (string)($meta['reason'] ?? '')];
            })->all();
        return $this->view->render($res, 'admin/nominees/edit.twig', [
            'page_title' => 'Edit ' . $n->name . ' — Admin',
            'admin_page' => 'nominees',
            'n'          => (array)$n,
            'siblings'   => $siblings,
            'history'    => $history,
            'has_story'  => \AfricaGates\Support\SchemaHas::column('gates_nominees', 'story'),
            'has_org'    => \AfricaGates\Support\SchemaHas::column('gates_nominees', 'organisation'),
            'url'        => \AfricaGates\Support\NomineeUrl::path((int)$n->id),
        ]);
    }

    /**
     * POST /admin/nominees/{id}/edit
     *
     * There was no way to correct a nominee at all once approved: a misspelt name was on
     * the ballot, the leaderboard and every share card for the rest of the edition, and
     * the only fix was a database nobody can reach. Each change is audited with the old and
     * new value, because a name is what voters searched for.
     *
     * A MOVE STAYS INSIDE THE EDITION. Votes are recorded against a category, and moving a
     * nominee to another cycle would carry its tally into a different award. And a nominee
     * with votes is moved only with the box ticked that says so — one vote per person per
     * category (uq_one_vote) means their voters may already have voted in the new category.
     */
    public function update(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $back = "/admin/nominees/$id/edit";
        $n = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $id)->first(['n.*', 'c.cycle_id']);
        if (!$n) throw new \Slim\Exception\HttpNotFoundException($req);
        $b = (array)$req->getParsedBody();
        $clean = static fn ($v, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)$v)), 0, $max);
        $data = [
            'name'         => $clean($b['name'] ?? '', 200),
            'tagline'      => $clean($b['tagline'] ?? '', 300) ?: null,
            'country_code' => preg_match('/^[A-Za-z]{2}$/', (string)($b['country_code'] ?? '')) ? strtoupper((string)$b['country_code']) : null,
        ];
        if (\AfricaGates\Support\SchemaHas::column('gates_nominees', 'organisation')) $data['organisation'] = $clean($b['organisation'] ?? '', 200) ?: null;
        if (\AfricaGates\Support\SchemaHas::column('gates_nominees', 'story')) {
            $story = trim(str_replace("\r\n", "\n", (string)($b['story'] ?? '')));
            $data['story'] = $story === '' ? null : mb_substr($story, 0, 20000);
        }
        if (mb_strlen($data['name']) < 2) {
            $_SESSION['flash_error'] = 'A nominee needs a name.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $cat = (int)($b['category_id'] ?? $n->category_id);
        if ($cat !== (int)$n->category_id) {
            // Which award somebody stands in is an integrity decision, like crowning them.
            if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
                $_SESSION['flash_error'] = 'Only an admin can move a nominee to another category.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            $ok = DB::table('gates_award_categories')->where('id', $cat)->where('cycle_id', $n->cycle_id)->exists();
            if (!$ok) {
                $_SESSION['flash_error'] = 'A nominee can only move to another category of the same edition.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            if ((int)$n->vote_count > 0 && empty($b['move_with_votes'])) {
                $_SESSION['flash_error'] = $n->name . ' already has ' . number_format((int)$n->vote_count)
                    . ' votes. Tick the box to confirm the move — their votes go with them.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            // Scored against the old category's rubric: those marks mean nothing in the new
            // one, and moving them would hand a nominee a judge half nobody gave them there.
            if (\AfricaGates\Support\SchemaHas::table('gates_judge_criteria_scores')
                && DB::table('gates_judge_criteria_scores')->where('nominee_id', $id)->exists()) {
                $_SESSION['flash_error'] = $n->name . ' has already been scored by the panel in this category, so cannot be moved. '
                    . 'Merge or withdraw instead.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            // One vote per person per category: somebody who voted for them AND for
            // somebody in the new category would hold two votes there.
            $clash = DB::table('gates_votes as a')->join('gates_votes as b', 'b.voter_email_hash', '=', 'a.voter_email_hash')
                ->where('a.nominee_id', $id)->where('b.category_id', $cat)->count();
            if ($clash > 0) {
                $_SESSION['flash_error'] = $clash . ' of ' . $n->name . '’s voters also voted in that category, and a person has one vote per category. '
                    . 'The move would give them two, so it was not made.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            $data['category_id'] = $cat;
        }
        $before = array_intersect_key((array)$n, $data);
        $changed = array_filter($data, fn ($v, $k) => (string)($before[$k] ?? '') !== (string)($v ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            $_SESSION['flash_ok'] = 'Nothing to save — no detail was changed.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        DB::connection()->transaction(function () use ($id, $changed) {
            DB::table('gates_nominees')->where('id', $id)->update($changed);
            // Votes follow their nominee, or the old category keeps ballots for somebody
            // no longer standing in it and the new one has a tally with no rows behind it.
            if (isset($changed['category_id'])) {
                DB::table('gates_votes')->where('nominee_id', $id)->update(['category_id' => $changed['category_id']]);
            }
        });
        $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'nominee.edit', 'nominee', $id, [
            'changed' => array_keys($changed),
            'from' => array_intersect_key($before, $changed),
            'to' => $changed,
        ]);
        (new \AfricaGates\Services\CacheService())->forgetAwardViews();
        $_SESSION['flash_ok'] = 'Saved: ' . implode(', ', array_map(fn ($k) => str_replace(['_id', '_code', '_'], ['', '', ' '], $k), array_keys($changed))) . '.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Merge duplicate nominees into one survivor (counters vote-splitting).
     * Reassigns votes + judge scores + everything else, rebuilds counters and
     * TOMBSTONES the folded rows via MergeService (reversible — see unmerge()).
     * Admin+ only — it moves votes, judge scores and the CPI rollup, an
     * award-integrity decision.
     */
    public function merge(Request $req, Response $res): Response
    {
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can merge nominees (it moves votes and judge scores).';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $b        = (array) $req->getParsedBody();
        $keepId   = (int) ($b['keep_id'] ?? 0);
        $mergeIds = array_map('intval', (array) ($b['merge_ids'] ?? []));
        if (!$keepId) {
            $_SESSION['flash_error'] = 'Choose which nominee to keep before merging.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $r = \AfricaGates\Services\MergeService::mergeNominees($keepId, $mergeIds, (int)($_SESSION['admin_id'] ?? 0) ?: null);
        if ($r['ok']) {
            $_SESSION['flash_ok'] = sprintf(
                'Merged %d duplicate%s into one nominee — %s vote%s now count together. Rankings refresh on the next CPI recompute.',
                $r['merged'], $r['merged'] === 1 ? '' : 's',
                number_format($r['votes']), $r['votes'] === 1 ? '' : 's'
            );
        } else {
            $_SESSION['flash_error'] = $r['error'] ?? 'The merge could not be completed.';
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * "Not the same person": the scan stops suggesting every pair among these. Admin+,
     * the same gate as merge, because it decides what the next admin is shown.
     */
    public function notDuplicate(Request $req, Response $res): Response
    {
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can rule on duplicates.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $b   = (array) $req->getParsedBody();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['merge_ids'] ?? [])))));
        if (count($ids) < 2) {
            $_SESSION['flash_error'] = 'Nothing to mark — a group needs two nominees.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $n = \AfricaGates\Services\MergeSuggestionService::dismiss($ids, (int) ($_SESSION['admin_id'] ?? 0) ?: null,
                                                                (string) ($b['_reason'] ?? ''));
        $this->audit->record((int) ($_SESSION['admin_id'] ?? 0), 'nominee.not_duplicate', 'nominee', $ids[0], ['ids' => $ids]);
        $_SESSION['flash_ok'] = 'Marked as different people. The scan will not suggest ' . ($n === 1 ? 'that pair' : 'those pairs') . ' again.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Undo a merge: restore a tombstoned nominee and move its votes/scores back
     * off the survivor (re-inserting any rows that were dropped as collisions),
     * then rebuild both nominees' counters — via MergeService::unmerge(). Admin+
     * only, same integrity gate as merge.
     */
    public function unmerge(Request $req, Response $res): Response
    {
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can undo a merge (it moves votes and judge scores).';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $mergedId = (int) (((array) $req->getParsedBody())['merged_id'] ?? 0);
        if (!$mergedId) {
            $_SESSION['flash_error'] = 'Choose which merged nominee to restore.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $r = \AfricaGates\Services\MergeService::unmerge($mergedId, (int)($_SESSION['admin_id'] ?? 0) ?: null);
        if ($r['ok']) {
            $_SESSION['flash_ok'] = sprintf('Merge undone — the nominee is live again and %d row%s moved back. Rankings refresh on the next CPI recompute.',
                $r['restored'], $r['restored'] === 1 ? '' : 's');
        } else {
            $_SESSION['flash_error'] = $r['error'] ?? 'The merge could not be undone.';
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * AI-assisted duplicate scan (JSON): groups of nominees that are probably
     * the same person, within a cycle, via MergeSuggestionService (deterministic
     * clustering always; AI refinement when a provider is configured). Admin+
     * only — it's the pre-step to a merge, and each group is returned with a
     * ready-to-submit keep + merge_ids so the UI can offer one-click merge.
     */
    public function duplicateScan(Request $req, Response $res): Response
    {
        $json = function (array $p, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            return $json(['ok' => false, 'error' => 'Only an admin can scan for duplicates.'], 403);
        }
        // Modest per-admin budget. The AI layer now makes ONE call per SCAN, not one
        // per category — a 48-category cycle used to spend 48 of a 500-per-day
        // capability budget on a single click.
        // `names_by_id` is stripped from the response below: it is the raw
        // name-for-every-nominee map that forCycle() needs internally, and shipping
        // it would turn a duplicate report into a bulk export of the whole cycle.
        try {
            if (!(new \AfricaGates\Services\RateLimitService())->check('admin:' . ($_SESSION['admin_id'] ?? '0'), 'dup_scan', 20, 3600)) {
                return $json(['ok' => false, 'error' => 'Too many scans — wait a minute and try again.'], 429);
            }
        } catch (\Throwable) {}

        $cycleId = (int) ($req->getQueryParams()['cycle'] ?? 0);
        if ($cycleId <= 0) {
            // Default to the most recent cycle so the button works with no filter set.
            $cycleId = (int) (DB::table('gates_award_cycles')->orderByDesc('year')->orderByDesc('id')->value('id') ?? 0);
        }
        if ($cycleId <= 0) return $json(['ok' => true, 'groups' => [], 'scanned' => 0, 'ai' => false]);

        // A failure here used to reach the page as HTML that r.json() could not read, and
        // the screen said "Network error — try again" about a fault that would recur on
        // every try. It answers in JSON now, with the reference that finds the log entry.
        try {
            $r = \AfricaGates\Services\MergeSuggestionService::forCycle($cycleId);
        } catch (\Throwable $e) {
            $ref = \AfricaGates\Support\PublicFault::record($e, 'GET /admin/nominees/duplicate-scan');
            return $json(['ok' => false, 'reference' => $ref,
                          'error' => 'The scan failed on the server — reference ' . $ref . '. Nothing was changed.'], 500);
        }
        unset($r['names_by_id']);
        unset($r['rows_by_id']);   // the same, with countries and vote counts — never to the browser
        return $json(['ok' => true] + $r + ['cycle' => $cycleId]);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $action = $args['action'];
        $map = ['winner' => 'winner', 'runner_up' => 'runner_up', 'approve' => 'approved', 'remove' => 'pending'];
        if (!isset($map[$action])) throw new \Slim\Exception\HttpNotFoundException($req);
        // Crowning winners / runner-ups is an award decision — admin+ only, not moderator.
        if (in_array($action, ['winner', 'runner_up'], true)
            && !\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can set award results (winner / runner-up).';
            $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        DB::table('gates_nominees')->where('id', $id)->update(['status' => $map[$action]]);
        $this->audit->record((int)$_SESSION['admin_id'], "nominee.$action", 'nominee', $id);
        $_SESSION['flash_ok'] = 'Nominee updated.';
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * ADD a photo to a nominee's gallery. Multipart upload routed through the
     * hardened UploadService (bytes-sniffed MIME, re-encoded, min-dimension
     * gate) into the `nominees` bucket, which also records a gates_uploads row
     * (attached_to_type='nominee') — that row set IS the gallery. The nominee's
     * photo_path holds the PRIMARY image; the first upload becomes primary.
     *
     * Not an integrity/award decision, so it stays open to every role that can
     * already reach the nominees screen (the section guard governs that).
     */
    public function photo(Request $req, Response $res, array $args): Response
    {
        $id  = (int)$args['id'];
        $nom = DB::table('gates_nominees')->where('id', $id)->first();
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);

        $file = ($req->getUploadedFiles()['photo'] ?? null);
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash_error'] = 'Choose an image to upload.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        if (!$this->uploads) {
            $_SESSION['flash_error'] = 'Image uploads are not available on this server.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        try {
            // 200px min side keeps thumbnails/cards from pixelating; 1200px cap
            // + re-encode keeps the stored file lean. The upload is recorded in
            // gates_uploads attached to this nominee (that's the gallery).
            $result = $this->uploads->uploadImage(
                $file, 'nominees', 1200, 82,
                (int)($_SESSION['admin_id'] ?? 0) ?: null, 'nominee', $id, 200
            );
        } catch (\Throwable $e) {
            // Upload errors are the member's fault (wrong type / too small) far more
            // often than the server's — surface the actual reason, not a raw 500.
            $_SESSION['flash_error'] = 'Could not upload photo — ' . $e->getMessage();
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // The first photo becomes the primary (the one shown on cards / vote page).
        $makePrimary = empty($nom->photo_path);
        if ($makePrimary) {
            try {
                DB::table('gates_nominees')->where('id', $id)->update(['photo_path' => $result['path']]);
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = ActionError::dbMessage($e);
                return $res->withHeader('Location', $back)->withStatus(302);
            }
        }

        \AfricaGates\Services\NomineePortrait::refresh();
        $this->audit->record((int)$_SESSION['admin_id'], 'nominee.photo.add', 'nominee', $id, ['path' => $result['path'], 'primary' => $makePrimary]);
        $_SESSION['flash_ok'] = $makePrimary ? 'Photo added and set as the primary.' : 'Photo added to the gallery.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** Every gallery photo path for a nominee (primary first), from gates_uploads + photo_path. */
    private function galleryFor(int $id, ?string $primary): array
    {
        $paths = [];
        if ($primary) $paths[] = $primary;
        try {
            $rows = \AfricaGates\Services\NomineePortrait::uploads($id)
                ->orderBy('id')->pluck('path');
            foreach ($rows as $p) { $p = (string)$p; if ($p !== '' && !in_array($p, $paths, true)) $paths[] = $p; }
        } catch (\Throwable) {}
        return $paths;
    }

    /** POST /admin/nominees/{id}/photo/primary — promote a gallery photo to the primary shown publicly. */
    public function photoPrimary(Request $req, Response $res, array $args): Response
    {
        $id   = (int)$args['id'];
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        $nom  = DB::table('gates_nominees')->where('id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        $path = trim((string)(((array)$req->getParsedBody())['path'] ?? ''));
        // Only accept a path that is genuinely one of this nominee's gallery images.
        if ($path === '' || !in_array($path, $this->galleryFor($id, (string)($nom->photo_path ?? '')), true)) {
            $_SESSION['flash_error'] = 'That photo is not in this nominee’s gallery.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        try {
            DB::table('gates_nominees')->where('id', $id)->update(['photo_path' => $path]);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = ActionError::dbMessage($e);
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        \AfricaGates\Services\NomineePortrait::refresh();
        $this->audit->record((int)$_SESSION['admin_id'], 'nominee.photo.primary', 'nominee', $id, ['path' => $path]);
        $_SESSION['flash_ok'] = 'Primary photo updated.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** POST /admin/nominees/{id}/photo/delete — remove one gallery photo (and its file); repoint primary if needed. */
    public function photoDelete(Request $req, Response $res, array $args): Response
    {
        $id   = (int)$args['id'];
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        $nom  = DB::table('gates_nominees')->where('id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        $path = trim((string)(((array)$req->getParsedBody())['path'] ?? ''));
        if ($path === '' || !in_array($path, $this->galleryFor($id, (string)($nom->photo_path ?? '')), true)) {
            $_SESSION['flash_error'] = 'That photo is not in this nominee’s gallery.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        try {
            \AfricaGates\Services\NomineePortrait::remove($id, $path);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = ActionError::dbMessage($e);
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        // Best-effort file cleanup — only within our own nominees bucket.
        if (str_starts_with($path, '/uploads/nominees/')) {
            $abs = dirname(__DIR__, 3) . '/public' . $path;
            if (is_file($abs)) @unlink($abs);
        }
        \AfricaGates\Services\NomineePortrait::refresh();
        $this->audit->record((int)$_SESSION['admin_id'], 'nominee.photo.delete', 'nominee', $id, ['path' => $path]);
        $_SESSION['flash_ok'] = 'Photo removed.';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Link / unlink a nominee to a registry profile so its votes + judge
     * scores roll up into the profile's CPI. Accepts profile_slug or
     * profile_id; empty value unlinks.
     */
    public function link(Request $req, Response $res, array $args): Response
    {
        // Relinking a nominee to a different profile changes whose CPI rollup
        // absorbs its votes + judge scores — an integrity/award decision, so it
        // is admin+ only, exactly like winner/runner-up. A moderator moderates,
        // it must not silently re-route scoring.
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can link or unlink a nominee to a profile (it moves the CPI rollup).';
            $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $id = (int)$args['id'];
        $b  = (array)$req->getParsedBody();
        $slug = trim((string)($b['profile_slug'] ?? ''));
        $pid  = (int)($b['profile_id'] ?? 0);

        $profileId = null;
        if ($slug !== '') {
            $profileId = DB::table('gates_profiles')->where('slug', $slug)->value('id');
            if (!$profileId) {
                $_SESSION['flash_error'] = 'No profile found for slug "' . $slug . '".';
                $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
        } elseif ($pid > 0) {
            $profileId = DB::table('gates_profiles')->where('id', $pid)->value('id');
        }

        DB::table('gates_nominees')->where('id', $id)->update(['profile_id' => $profileId ?: null]);
        $this->audit->record((int)$_SESSION['admin_id'], $profileId ? 'nominee.link' : 'nominee.unlink', 'nominee', $id, ['profile_id' => $profileId]);
        $_SESSION['flash_ok'] = $profileId ? 'Nominee linked to profile.' : 'Nominee unlinked.';
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        // Deleting a nominee cascades to its votes + judge scores — an integrity
        // decision beyond moderation, so admin+ only.
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can delete a nominee.';
            $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/nominees';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $id = (int)$args['id'];
        DB::table('gates_nominees')->where('id', $id)->delete();
        $this->audit->record((int)$_SESSION['admin_id'], 'nominee.delete', 'nominee', $id);
        $_SESSION['flash_ok'] = 'Nominee deleted.';
        return $res->withHeader('Location', '/admin/nominees')->withStatus(302);
    }
}
