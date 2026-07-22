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

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $cycleId = (int)($p['cycle'] ?? 0);
        $status  = (string)($p['status'] ?? '');
        $q       = (string)($p['q'] ?? '');
        $page    = max(1, (int)($p['page'] ?? 1));

        // One base query (search + date range), cloned for the count and the page
        // slice so they stay in lockstep.
        $base = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c','c.id','=','n.category_id')
            ->join('gates_award_cycles as cy','cy.id','=','c.cycle_id')
            ->join('gates_award_programmes as p','p.id','=','cy.programme_id');
        if ($cycleId) $base->where('cy.id', $cycleId);
        if ($status)  $base->where('n.status', $status);
        if ($q)       $base->where('n.name','like',"%$q%");
        $dateMeta = Filters::applyDateRange($base, 'n.nominated_at', $p);

        // Fold the profile lookup (1:1, so the count is unaffected) + projection +
        // ordering onto the base, then paginate so nominees beyond the old hard
        // 200-row cap remain reachable.
        $base->leftJoin('gates_profiles as pr','pr.id','=','n.profile_id')
            ->select(['n.id','n.name','n.tagline','n.country_code','n.vote_count','n.status','n.photo_path','n.profile_id','c.title as category','p.title as programme','cy.id as cycle_id','cy.year','pr.slug as profile_slug','pr.display_name as profile_name'])
            ->orderByDesc('n.vote_count');
        $pg    = Paginator::paginate($base, $page, self::PER_PAGE);
        $rows  = $pg['rows'];
        $total = $pg['total']; $pages = $pg['pages']; $page = $pg['page'];

        $cycles = DB::table('gates_award_cycles as c')
            ->join('gates_award_programmes as p','p.id','=','c.programme_id')
            ->select(['c.id','c.year','p.title'])->orderByDesc('c.year')->get();

        // Leading '&' so the existing template's `?page=N{{ qs }}` links keep every
        // active filter (previously `qs` was undefined — pagination dropped filters).
        $qsBuilt = Filters::qs(['cycle' => $cycleId, 'status' => $status, 'q' => $q, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']]);
        return $this->view->render($res, 'admin/nominees/index.twig', [
            'page_title' => 'Nominees — Admin',
            'admin_page' => 'nominees',
            'rows'       => $rows->map(fn($r)=>(array)$r)->all(),
            'cycles'     => $cycles->map(fn($r)=>(array)$r)->all(),
            'filters'    => ['cycle' => $cycleId, 'status' => $status, 'q' => $q, 'range' => $dateMeta['range'], 'from' => $dateMeta['from'], 'to' => $dateMeta['to']],
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'per'        => self::PER_PAGE,
            'qs'         => $qsBuilt !== '' ? '&' . $qsBuilt : '',
        ]);
    }

    /**
     * Merge duplicate nominees into one survivor (counters vote-splitting).
     * Reassigns votes + judge scores + everything else, rebuilds counters and
     * deletes the folded rows via MergeService. Admin+ only — it moves votes,
     * judge scores and the CPI rollup, an award-integrity decision.
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
     * Add or replace a nominee's photo. Multipart upload routed through the
     * hardened UploadService (bytes-sniffed MIME, re-encoded, min-dimension
     * gate) into the `nominees` bucket, then photo_path is repointed. The old
     * file is best-effort removed so replacing does not orphan storage.
     *
     * Not an integrity/award decision, so it stays open to every role that can
     * already reach the nominees screen (the section guard governs that) — no
     * extra canManageIntegrity gate.
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
            // + re-encode keeps the stored file lean.
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

        $old = (string)($nom->photo_path ?? '');
        try {
            DB::table('gates_nominees')->where('id', $id)->update(['photo_path' => $result['path']]);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = ActionError::dbMessage($e);
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // Best-effort cleanup of the replaced file — only within our own nominees
        // bucket, never an arbitrary path, and never the file we just wrote.
        if ($old !== '' && $old !== $result['path'] && str_starts_with($old, '/uploads/nominees/')) {
            $abs = dirname(__DIR__, 3) . '/public' . $old;
            if (is_file($abs)) @unlink($abs);
        }

        $this->audit->record((int)$_SESSION['admin_id'], 'nominee.photo', 'nominee', $id, ['path' => $result['path']]);
        $_SESSION['flash_ok'] = 'Nominee photo updated.';
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
