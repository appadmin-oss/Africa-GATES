<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\WebhookService;

/**
 * Manage outbound webhook endpoints (gates_webhooks). Superadmin-gated by the
 * RoleMiddleware('superadmin') group in routes.php. Lets integrations and AI
 * agents subscribe to platform events; each endpoint signs deliveries with its
 * own secret (see WebhookService).
 */
class WebhooksController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $rows = DB::table('gates_webhooks')->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
        $deliveries = DB::table('gates_webhook_deliveries as d')
            ->leftJoin('gates_webhooks as w', 'w.id', '=', 'd.webhook_id')
            ->orderByDesc('d.id')->limit(20)
            ->select('d.*', 'w.url as hook_url')
            ->get()->map(fn ($r) => (array) $r)->all();
        return $this->view->render($res, 'admin/webhooks/index.twig', [
            'page_title' => 'Webhooks — Admin',
            'admin_page' => 'webhooks',
            'rows'       => $rows,
            'deliveries' => $deliveries,
            'events'     => WebhookService::EVENTS,
        ]);
    }

    public function form(Request $req, Response $res, array $args = []): Response
    {
        $id  = (int) ($args['id'] ?? 0);
        $row = $id ? (array) DB::table('gates_webhooks')->where('id', $id)->first() : [];
        $stored = (string) ($row['events'] ?? '*');
        return $this->view->render($res, 'admin/webhooks/form.twig', [
            'page_title' => $id ? 'Edit webhook — Admin' : 'New webhook — Admin',
            'admin_page' => 'webhooks',
            'row'        => $row,
            'is_new'     => !$id,
            'events'     => WebhookService::EVENTS,
            'all_events' => $stored === '' || $stored === '*',
            'sel_events' => ($stored !== '' && $stored !== '*') ? (json_decode($stored, true) ?: []) : [],
        ]);
    }

    public function save(Request $req, Response $res, array $args = []): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $b  = (array) $req->getParsedBody();
        $url = trim((string) ($b['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $_SESSION['flash_error'] = 'Enter a valid http(s) endpoint URL.';
            return $res->withHeader('Location', '/admin/webhooks' . ($id ? "/$id" : '/new'))->withStatus(302);
        }
        // Subscriptions: an explicit allow-list of known events, or '*' (all)
        // when "all events" is ticked or nothing specific is chosen.
        $picked = array_values(array_intersect(array_keys(WebhookService::EVENTS), (array) ($b['events'] ?? [])));
        $events = (isset($b['all_events']) || !$picked) ? '*' : json_encode($picked);

        $data = [
            'url'         => $url,
            'events'      => $events,
            'description' => mb_substr(trim((string) ($b['description'] ?? '')), 0, 200),
            'is_active'   => isset($b['is_active']) ? 1 : 0,
        ];
        if ($id) {
            DB::table('gates_webhooks')->where('id', $id)->update($data);
            $this->audit->record((int) $_SESSION['admin_id'], 'webhook.update', 'webhook', $id);
        } else {
            // Generate the signing secret once, on creation — shown to the admin so
            // they can configure signature verification on the receiving end.
            $data['secret']     = 'whsec_' . bin2hex(random_bytes(24));
            $data['created_at'] = Carbon::now()->toDateTimeString();
            $id = (int) DB::table('gates_webhooks')->insertGetId($data);
            $this->audit->record((int) $_SESSION['admin_id'], 'webhook.create', 'webhook', $id);
        }
        $_SESSION['flash_ok'] = 'Webhook saved.';
        return $res->withHeader('Location', '/admin/webhooks')->withStatus(302);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        DB::table('gates_webhooks')->where('id', $id)->delete();
        DB::table('gates_webhook_deliveries')->where('webhook_id', $id)->delete();
        $this->audit->record((int) $_SESSION['admin_id'], 'webhook.delete', 'webhook', $id);
        $_SESSION['flash_ok'] = 'Webhook deleted.';
        return $res->withHeader('Location', '/admin/webhooks')->withStatus(302);
    }

    public function test(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $r  = WebhookService::ping($id);
        $this->audit->record((int) $_SESSION['admin_id'], 'webhook.test', 'webhook', $id, ['ok' => $r['ok']]);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? 'Test event delivered (HTTP ' . $r['status'] . ').'
            : 'Test failed: ' . ($r['error'] ?? 'unknown error') . '.';
        return $res->withHeader('Location', '/admin/webhooks')->withStatus(302);
    }
}
