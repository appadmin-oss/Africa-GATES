<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\OtpService;

class NominationsController
{
    public function __construct(
        private readonly Twig         $view,
        private readonly AuditService $audit,
        private readonly ?OtpService  $mailer = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $status = (string)($p['status'] ?? 'pending');
        $q = (string)($p['q'] ?? '');
        $page = max(1, (int)($p['page'] ?? 1));
        $per = 25;

        $base = DB::table('gates_nominations as n')
            ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'n.cycle_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->select(['n.*', 'p.title as programme_title', 'c.year as cycle_year']);
        if ($status) $base->where('n.status', $status);
        if ($q !== '') $base->where(function ($x) use ($q) {
            $x->where('n.nominee_name','like',"%$q%")
              ->orWhere('n.nominator_name','like',"%$q%")
              ->orWhere('n.nominator_email','like',"%$q%");
        });
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('n.id')->offset(($page-1)*$per)->limit($per)->get();

        return $this->view->render($res, 'admin/nominations/index.twig', [
            'page_title'  => 'Nominations — Admin',
            'admin_page'  => 'nominations',
            'rows'        => $rows->map(fn($r)=>(array)$r)->all(),
            'total'       => $total,
            'page'        => $page,
            'per'         => $per,
            'filters'     => ['status' => $status, 'q' => $q],
            'counts'      => [
                'pending'  => (int)DB::table('gates_nominations')->where('status','pending')->count(),
                'approved' => (int)DB::table('gates_nominations')->where('status','approved')->count(),
                'rejected' => (int)DB::table('gates_nominations')->where('status','rejected')->count(),
            ],
        ]);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $action = $args['action'] ?? '';
        $nom = DB::table('gates_nominations')->where('id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);

        // Reject / pending — no category needed
        if ($action === 'reject' || $action === 'pending') {
            $status = ['reject' => 'rejected', 'pending' => 'pending'][$action];
            DB::table('gates_nominations')->where('id', $id)->update(['status' => $status]);
            $this->audit->record((int)$_SESSION['admin_id'], "nomination.$action", 'nomination', $id);

            // Email the nominator when their nomination is rejected
            if ($action === 'reject' && $this->mailer && filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
                $html = <<<HTML
<p>Hi <strong>{$nom->nominator_name}</strong>,</p>
<p>Thank you for taking the time to nominate <strong>{$nom->nominee_name}</strong> for the Africa GATES 2026 awards.</p>
<p>After review, our moderation team was unable to approve this nomination at this time. This may be because the submission did not fully meet the eligibility criteria for the selected category, or because sufficient supporting detail was not provided.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;background:#fef2f2;border-left:4px solid #fca5a5;border-radius:0 8px 8px 0;padding:12px 16px">
  <tr><td style="font-size:14px;color:#7f1d1d">
    Nominee: <strong>{$nom->nominee_name}</strong><br>
    Status: <strong>Not approved</strong>
  </td></tr>
</table>
<p>You are welcome to submit a revised nomination with additional supporting evidence. If you believe this decision was made in error, please reply to this email.</p>
HTML;
                $this->mailer->sendBranded(
                    $nom->nominator_email,
                    "Update on your nomination of {$nom->nominee_name}",
                    $html,
                    "Hi {$nom->nominator_name},\n\nYour nomination of {$nom->nominee_name} was not approved after review. You may resubmit with additional supporting evidence.\n\n— Africa GATES"
                );
            }

            $_SESSION['flash_ok'] = 'Nomination ' . $status . '.';
            return $res->withHeader('Location', '/admin/nominations?status=' . $nom->status)->withStatus(302);
        }

        // Approve flow — admin must pick a category (POST body category_id)
        if ($action !== 'approve') throw new \Slim\Exception\HttpNotFoundException($req);
        $b = (array)$req->getParsedBody();
        $catId = (int)($b['category_id'] ?? $nom->category_id ?? 0);
        if (!$catId) {
            $_SESSION['flash_error'] = 'Choose a category before approving.';
            return $res->withHeader('Location', '/admin/nominations/' . $id)->withStatus(302);
        }
        $cat = DB::table('gates_award_categories')->where('id', $catId)->first();
        if (!$cat || (int)$cat->cycle_id !== (int)$nom->cycle_id) {
            $_SESSION['flash_error'] = 'That category is not part of this nomination\'s cycle.';
            return $res->withHeader('Location', '/admin/nominations/' . $id)->withStatus(302);
        }

        DB::table('gates_nominations')->where('id', $id)->update([
            'status' => 'approved',
            'category_id' => $catId,
        ]);

        // Normalised dedup (case-insensitive + trimmed) so "Dr. Jane Doe" and
        // "dr. jane doe " don't both become separate nominees that split votes.
        $normName = strtolower(trim((string)$nom->nominee_name));
        $exists = DB::table('gates_nominees')->where('category_id', $catId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normName])->exists();
        if (!$exists) {
            // Best-effort auto-link to an existing registry profile so this
            // nominee's votes + judge scores roll up into the CPI leaderboard.
            // Match by nominee email (exact) first, then by display name.
            $profileId = null;
            $nomEmail = strtolower(trim((string)($nom->nominee_email ?? '')));
            if ($nomEmail !== '') {
                $profileId = DB::table('gates_profiles')->where('status', 'approved')
                    ->whereRaw('LOWER(email) = ?', [$nomEmail])->value('id');
            }
            if (!$profileId) {
                $profileId = DB::table('gates_profiles')->where('status', 'approved')
                    ->whereRaw('LOWER(display_name) = ?', [strtolower(trim((string)$nom->nominee_name))])
                    ->value('id');
            }
            DB::table('gates_nominees')->insert([
                'category_id'  => $catId,
                'profile_id'   => $profileId ?: null,
                'name'         => $nom->nominee_name,
                'tagline'      => mb_substr((string)$nom->reason, 0, 200),
                'country_code' => $nom->country_code,
                'status'       => 'approved',
                'vote_count'   => 0,
                'nominated_at' => Carbon::now()->toDateTimeString(),
            ]);
        }

        $this->audit->record((int)$_SESSION['admin_id'], 'nomination.approve', 'nomination', $id, ['category_id' => $catId]);

        // Email the nominator — their nomination was approved and is now live
        if ($this->mailer && filter_var($nom->nominator_email ?? '', FILTER_VALIDATE_EMAIL)) {
            $prog  = DB::table('gates_award_programmes')
                ->join('gates_award_cycles', 'gates_award_cycles.programme_id', '=', 'gates_award_programmes.id')
                ->where('gates_award_cycles.id', $nom->cycle_id)
                ->value('gates_award_programmes.title') ?? 'Africa GATES Awards';
            $html = <<<HTML
<p>Hi <strong>{$nom->nominator_name}</strong>,</p>
<p>Great news — your nomination of <strong>{$nom->nominee_name}</strong> has been <span style="color:#15803d;font-weight:700">approved</span> and is now live on the Africa GATES platform.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr><td style="font-size:14px;color:#166534;line-height:1.7">
    Nominee: <strong>{$nom->nominee_name}</strong><br>
    Programme: <strong>{$prog}</strong><br>
    Category: <strong>{$cat->title}</strong><br>
    Status: <strong style="color:#15803d">Live — open for community voting</strong>
  </td></tr>
</table>
<p>Share the voting page with your network to help <strong>{$nom->nominee_name}</strong> gather votes. Community votes account for 45% of the final score — every vote counts.</p>
<p style="text-align:center;margin:24px 0">
  <a href="https://afg.afrovanguard.org.ng/vote" style="display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px">
    View the voting page →
  </a>
</p>
HTML;
            $this->mailer->sendBranded(
                $nom->nominator_email,
                "✅ Your nomination of {$nom->nominee_name} is approved and live",
                $html,
                "Hi {$nom->nominator_name},\n\nYour nomination of {$nom->nominee_name} for {$prog} — {$cat->title} has been approved and is now live.\n\nShare the vote page: https://afg.afrovanguard.org.ng/vote\n\n— Africa GATES"
            );
        }

        $_SESSION['flash_ok'] = 'Nomination approved and added to "' . $cat->title . '".';
        return $res->withHeader('Location', '/admin/nominations?status=approved')->withStatus(302);
    }

    /** Review page — preview the nomination + pick a category before approving. */
    public function review(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $nom = DB::table('gates_nominations as n')
            ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'n.cycle_id')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->select(['n.*', 'p.title as programme_title', 'p.icon_emoji', 'c.year'])
            ->where('n.id', $id)->first();
        if (!$nom) throw new \Slim\Exception\HttpNotFoundException($req);
        $cats = DB::table('gates_award_categories')->where('cycle_id', $nom->cycle_id)->orderBy('sort_order')->get()->map(fn($r) => (array)$r)->all();
        return $this->view->render($res, 'admin/nominations/review.twig', [
            'page_title' => 'Review nomination — Admin',
            'admin_page' => 'nominations',
            'nom' => (array)$nom,
            'categories' => $cats,
            'flash_error' => $_SESSION['flash_error'] ?? null,
        ]);
    }
}
