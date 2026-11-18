<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\ShopPos;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The till: in-person sales from the shop's own stock, and the cash drawer for an event or a
 * day (Services\ShopPos). One screen, because at a table they are one job — open the drawer,
 * sell, count up.
 */
final class ShopTillController
{
    /** Blank basket rows drawn on the sale form. */
    public const ROWS = 6;

    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $sid = (int) ($req->getQueryParams()['session'] ?? 0);
        $recent = [];
        if (ShopPos::ready()) {
            $recent = DB::table('gates_orders')->where('channel', 'pos')->orderByDesc('id')->limit(15)
                ->get(['id', 'reference', 'subtotal_naira', 'pos_method', 'status', 'created_at', 'name'])
                ->map(static fn ($r): array => (array) $r)->all();
        }
        return $this->view->render($res, 'admin/shop/till.twig', [
            'page_title' => 'Shop till — Admin',
            'admin_page' => 'shop-till',
            'ready'      => ShopPos::ready(),
            'open'       => ShopPos::openSessions(),
            'sessions'   => ShopPos::recentSessions(),
            'summary'    => $sid > 0 ? ShopPos::summary($sid) : null,
            'catalogue'  => ShopPos::ready() ? ShopPos::catalogue() : [],
            'methods'    => ShopPos::METHODS,
            'rows'       => self::ROWS,
            'recent'     => $recent,
        ]);
    }

    public function open(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $r = ShopPos::open((string) ($b['label'] ?? ''), (int) preg_replace('/\D/', '', (string) ($b['float'] ?? '0')), $admin);
        if ($r['ok']) $this->audit->record($admin, 'shop.till.open', 'cash_session', (int) $r['id']);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        return $res->withHeader('Location', '/admin/shop/till' . ($r['ok'] ? '?session=' . $r['id'] : ''))->withStatus(302);
    }

    public function sale(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $basket = [];
        foreach ((array) ($b['item'] ?? []) as $i => $key) {
            if (!preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) continue;
            $basket[] = ['product_id' => (int) $m[1], 'variant_id' => (int) $m[2], 'qty' => (int) (($b['qty'] ?? [])[$i] ?? 0)];
        }
        $sid = (int) ($b['session_id'] ?? 0);
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $r = ShopPos::sell($basket, (string) ($b['method'] ?? ''), $sid > 0 ? $sid : null, (string) ($b['buyer_name'] ?? ''),
                           (string) ($b['buyer_email'] ?? ''), (string) ($b['note'] ?? ''), $admin);
        if ($r['ok']) $this->audit->record($admin, 'shop.till.sale', 'order', (int) $r['order_id']);
        // A sale that took a count below zero still went through (a queue must not stall); the
        // message names the options, so it is said as an error the operator will read.
        $_SESSION[$r['ok'] && ($r['below_zero'] ?? []) === [] ? 'flash_ok' : 'flash_error'] = $r['message'];
        return $res->withHeader('Location', '/admin/shop/till' . ($sid > 0 ? '?session=' . $sid : ''))->withStatus(302);
    }

    public function close(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $sid = (int) ($b['session_id'] ?? 0);
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $raw = trim((string) ($b['counted'] ?? ''));
        $r = $raw === '' || !preg_match('/^\d+$/', $raw)
            ? ['ok' => false, 'message' => 'Type what is in the drawer, in whole naira.']
            : ShopPos::close($sid, (int) $raw, (string) (($b['note'] ?? '') ?: ($b['_reason'] ?? '')), $admin);
        if ($r['ok']) $this->audit->record($admin, 'shop.till.close', 'cash_session', $sid);
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['message'];
        return $res->withHeader('Location', '/admin/shop/till?session=' . $sid)->withStatus(302);
    }
}
