<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;

class ProfilesController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $status = (string)($p['status'] ?? '');
        $tier   = (string)($p['tier'] ?? '');
        $q      = (string)($p['q'] ?? '');
        $page   = max(1, (int)($p['page'] ?? 1));
        $per    = 25;

        $base = DB::table('gates_profiles');
        \AfricaGates\Services\ProfileMergeService::notMerged($base);   // hide merge tombstones
        if ($status) $base->where('status', $status);
        if ($tier)   $base->where('cpi_tier', $tier);
        if ($q !== '') $base->where(function ($x) use ($q) {
            $x->where('display_name','like',"%$q%")->orWhere('email','like',"%$q%")->orWhere('category','like',"%$q%");
        });
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('id')->offset(($page-1)*$per)->limit($per)
            ->get(['id','slug','display_name','email','category','profile_type','country_code','region','cpi_score','cpi_tier','status','verification_tier','completeness_pct','registered_at']);

        return $this->view->render($res, 'admin/profiles/index.twig', [
            'page_title'  => 'Profiles — Africa GATES Admin',
            'admin_page'  => 'profiles',
            'rows'        => $rows->map(fn($r)=>(array)$r)->all(),
            'total'       => $total,
            'page'        => $page,
            'per'         => $per,
            'filters'     => ['status' => $status, 'tier' => $tier, 'q' => $q],
            'counts'      => [
                'all'       => (int)\AfricaGates\Services\ProfileMergeService::notMerged(DB::table('gates_profiles'))->count(),
                'pending'   => (int)\AfricaGates\Services\ProfileMergeService::notMerged(DB::table('gates_profiles')->where('status','pending'))->count(),
                'approved'  => (int)\AfricaGates\Services\ProfileMergeService::notMerged(DB::table('gates_profiles')->where('status','approved'))->count(),
                'suspended' => (int)\AfricaGates\Services\ProfileMergeService::notMerged(DB::table('gates_profiles')->where('status','suspended'))->count(),
            ],
            'merged'      => \AfricaGates\Services\ProfileMergeService::recentlyMerged(),
        ]);
    }

    /**
     * Merge duplicate registry profiles into one survivor (reversible). Reassigns
     * linked nominees, CPI history and community references, then tombstones the
     * folded profiles via ProfileMergeService. Admin+ only — it moves the CPI
     * rollup, an integrity decision.
     */
    public function merge(Request $req, Response $res): Response
    {
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/profiles';
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can merge profiles (it moves linked nominees and the CPI rollup).';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $b        = (array) $req->getParsedBody();
        $keepId   = (int) ($b['keep_id'] ?? 0);
        $mergeIds = array_map('intval', (array) ($b['merge_ids'] ?? []));
        if (!$keepId) {
            $_SESSION['flash_error'] = 'Choose which profile to keep before merging.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $r = \AfricaGates\Services\ProfileMergeService::mergeProfiles($keepId, $mergeIds, (int)($_SESSION['admin_id'] ?? 0) ?: null);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? sprintf('Merged %d duplicate profile%s into one. The CPI rollup refreshes on the next recompute.', $r['merged'], $r['merged'] === 1 ? '' : 's')
            : ($r['error'] ?? 'The merge could not be completed.');
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** Undo a profile merge — restore a tombstoned profile and its moved rows. Admin+ only. */
    public function unmerge(Request $req, Response $res): Response
    {
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/profiles';
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            $_SESSION['flash_error'] = 'Only an admin can undo a profile merge.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $mergedId = (int) (((array) $req->getParsedBody())['merged_id'] ?? 0);
        if (!$mergedId) {
            $_SESSION['flash_error'] = 'Choose which merged profile to restore.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $r = \AfricaGates\Services\ProfileMergeService::unmerge($mergedId, (int)($_SESSION['admin_id'] ?? 0) ?: null);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? sprintf('Profile merge undone — the profile is live again and %d row%s moved back.', $r['restored'], $r['restored'] === 1 ? '' : 's')
            : ($r['error'] ?? 'The merge could not be undone.');
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    public function edit(Request $req, Response $res, array $args): Response
    {
        $row = DB::table('gates_profiles')->where('id', (int)$args['id'])->first();
        if (!$row) throw new \Slim\Exception\HttpNotFoundException($req);
        return $this->view->render($res, 'admin/profiles/edit.twig', [
            'page_title' => 'Edit Profile — Admin',
            'admin_page' => 'profiles',
            'profile'    => (array)$row,
            // Flash renders from the Twig globals via the layout — do not shadow them.
            // (The old 'flash_err' key was also a typo: the template uses flash_error.)
        ]);
    }

    public function update(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $b = (array)$req->getParsedBody();
        $patch = [
            'display_name'      => trim((string)($b['display_name'] ?? '')),
            'category'          => trim((string)($b['category']     ?? '')),
            'profile_type'      => (string)($b['profile_type']      ?? 'individual'),
            'bio'               => trim((string)($b['bio']          ?? '')),
            'country_code'      => strtoupper(substr((string)($b['country_code'] ?? 'NG'), 0, 2)),
            'website'           => trim((string)($b['website']      ?? '')),
            'instagram_handle'  => trim((string)($b['instagram_handle'] ?? '')),
            'twitter_handle'    => trim((string)($b['twitter_handle']   ?? '')),
            'cpi_score'         => max(0, min(1000, (int)($b['cpi_score'] ?? 0))),
            'cpi_tier'          => (string)($b['cpi_tier']  ?? 'unranked'),
            'status'            => (string)($b['status']    ?? 'pending'),
            'verification_tier' => (string)($b['verification_tier'] ?? 'none'),
            'completeness_pct'  => max(0, min(100, (int)($b['completeness_pct'] ?? 0))),
            'updated_at'        => Carbon::now()->toDateTimeString(),
        ];
        // Integrity fields are admin+ only — a moderator may edit descriptive
        // fields and moderate status, but never rewrite the score/verification.
        if (!\AfricaGates\Admin\Support\Permissions::canManageIntegrity((string)($_SESSION['admin_role'] ?? ''))) {
            unset($patch['cpi_score'], $patch['cpi_tier'], $patch['verification_tier'], $patch['completeness_pct']);
        }
        try {
            DB::table('gates_profiles')->where('id',$id)->update($patch);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = \AfricaGates\Admin\Support\ActionError::dbMessage($e);
            return $res->withHeader('Location', '/admin/profiles/' . $id)->withStatus(302);
        }
        $this->audit->record((int)$_SESSION['admin_id'], 'profile.update', 'profile', $id, ['fields' => array_keys($patch)]);
        $_SESSION['flash_ok'] = 'Profile updated.';
        return $res->withHeader('Location', '/admin/profiles/' . $id)->withStatus(302);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $action = $args['action'] ?? '';
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'approved', 'pending' => 'pending'];
        if (!isset($map[$action])) throw new \Slim\Exception\HttpNotFoundException($req);
        DB::table('gates_profiles')->where('id',$id)->update([
            'status' => $map[$action],
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->audit->record((int)$_SESSION['admin_id'], "profile.$action", 'profile', $id);
        $_SESSION['flash_ok'] = "Profile " . $map[$action] . ".";
        $back = $req->getServerParams()['HTTP_REFERER'] ?? '/admin/profiles';
        return $res->withHeader('Location', $back)->withStatus(302);
    }
}
