<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use League\Csv\Writer;
use AfricaGates\Admin\Services\AuditService;

class PartnersController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $status = (string)($p['status'] ?? '');
        $base = DB::table('gates_partner_enquiries');
        if ($status) $base->where('status', $status);
        $rows = $base->orderByDesc('id')->limit(200)->get()->map(fn($r)=>(array)$r)->all();

        return $this->view->render($res, 'admin/partners/index.twig', [
            'page_title' => 'Partner Enquiries — Admin',
            'admin_page' => 'partners',
            'rows'       => $rows,
            'filters'    => ['status' => $status],
            'counts'     => [
                'new'        => (int)DB::table('gates_partner_enquiries')->where('status','new')->count(),
                'in_review'  => (int)DB::table('gates_partner_enquiries')->where('status','in_review')->count(),
                'converted'  => (int)DB::table('gates_partner_enquiries')->where('status','converted')->count(),
                'closed'     => (int)DB::table('gates_partner_enquiries')->where('status','closed')->count(),
            ],
        ]);
    }

    public function setStatus(Request $req, Response $res, array $args): Response
    {
        $id = (int)$args['id'];
        $status = (string)$args['status'];
        if (!in_array($status, ['new','in_review','converted','closed'], true)) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }
        DB::table('gates_partner_enquiries')->where('id', $id)->update(['status' => $status]);
        $this->audit->record((int)$_SESSION['admin_id'], 'partner.status', 'partner_enquiry', $id, ['status' => $status]);
        $_SESSION['flash_ok'] = 'Status updated.';
        return $res->withHeader('Location', '/admin/partners')->withStatus(302);
    }

    public function exportCsv(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_partner_enquiries')->orderByDesc('id')->get();
        $csv = Writer::createFromString('');
        $csv->insertOne(['id','org_name','contact_name','contact_email','contact_phone','partnership_type','status','created_at','message']);
        foreach ($rows as $r) {
            $csv->insertOne([$r->id, $r->org_name, $r->contact_name, $r->contact_email, $r->contact_phone, $r->partnership_type, $r->status, $r->created_at, $r->message]);
        }
        $this->audit->record((int)$_SESSION['admin_id'], 'partner.export_csv', null, null, ['count' => count($rows)]);

        $body = $csv->toString();
        $res->getBody()->write($body);
        return $res
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="partner-enquiries-' . date('Ymd') . '.csv"');
    }
}
