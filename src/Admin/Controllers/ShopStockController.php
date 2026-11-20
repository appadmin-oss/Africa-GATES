<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\ShopStock;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Stock: what is on the shelf, what is running out, counting it, and where it went.
 *
 * Three jobs on one screen because they are one job — the Monday look at the shelf:
 *
 *  · RUNNING LOW. Everything at or under its level, out-of-stock first. Levels are per option,
 *    with one shop-wide default for the ones nobody set (ShopStock::lowLevel()).
 *  · THE STOCK-TAKE. Every counted option with the number the system expects; type what is on
 *    the shelf and save, and each difference is written as a `recount` with your name.
 *  · ADJUST. Received a delivery, found damage, gave one away — a reason-coded entry, never a
 *    typed-over number.
 *
 * Each product's ledger is one click away (`/admin/shop/products/{id}/stock`).
 */
final class ShopStockController
{
    /** At most this many rows in one stock-take save. Named in the refusal. */
    public const COUNT_MAX = 1000;

    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $q     = $req->getQueryParams();
        $show  = in_array($q['show'] ?? '', ['low', 'all', 'tracked'], true) ? (string) $q['show'] : 'tracked';
        $find  = mb_substr(trim((string) ($q['q'] ?? '')), 0, 80);
        $ready = SchemaHas::table('gates_shop_stock_moves');

        $rows = [];
        $error = null;
        try {
            $rows = ShopStock::rows($show === 'tracked', $find);
            if ($show === 'low') $rows = array_values(array_filter($rows, static fn ($r) => in_array($r['state'], ['low', 'out'], true)));
        } catch (\Throwable $e) {
            error_log('[shop] stock screen: ' . $e->getMessage());
            $error = 'The stock list could not be read just now.';
        }

        return $this->view->render($res, 'admin/shop/stock.twig', [
            'page_title' => 'Shop stock — Admin',
            'admin_page' => 'shop-stock',
            'rows'       => $rows,
            'low'        => $ready ? ShopStock::low() : [],
            'default'    => ShopStock::lowLevel(),
            'show'       => $show,
            'q'          => $find,
            'ready'      => $ready,
            'error'      => $error,
            'reasons'    => array_intersect_key(ShopStock::REASONS, array_flip(['received', 'damaged', 'giveaway'])),
            'count_max'  => self::COUNT_MAX,
        ]);
    }

    /**
     * The stock-take: counted numbers, per-option levels, and the shop-wide default, in one save.
     *
     * The whole payload is read and validated before anything is written, and a blank counted
     * box means "not counted", never zero — a stock-take of half the shelf must not zero the
     * other half.
     */
    public function count(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $admin = (int) ($_SESSION['admin_id'] ?? 0);
        $note  = mb_substr(trim((string) ($b['note'] ?? '')), 0, 200);
        $back  = '/admin/shop/stock' . (($b['back_show'] ?? '') !== '' ? '?show=' . rawurlencode((string) $b['back_show']) : '');

        $counted = [];
        foreach ((array) ($b['counted'] ?? []) as $key => $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') continue;
            if (!preg_match('/^\d+$/', $raw) || !preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) {
                $_SESSION['flash_error'] = 'A counted number must be a whole number of 0 or more. Nothing was saved.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            $counted[] = [(int) $m[1], (int) $m[2], (int) $raw];
        }
        $levels = [];
        $bulk = trim((string) ($b['bulk_low'] ?? ''));
        foreach ((array) ($b['low'] ?? []) as $key => $raw) {
            if (!preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) continue;
            $raw = $bulk !== '' && !empty($b['bulk_apply']) ? $bulk : trim((string) $raw);
            $was = trim((string) (($b['low_was'] ?? [])[$key] ?? ''));
            if ($raw === $was) continue;                          // untouched
            if ($raw !== '' && !preg_match('/^\d+$/', $raw)) {
                $_SESSION['flash_error'] = 'A low-stock level must be a whole number, or blank for the default. Nothing was saved.';
                return $res->withHeader('Location', $back)->withStatus(302);
            }
            $levels[] = [(int) $m[1], (int) $m[2], $raw === '' ? null : (int) $raw];
        }
        if (count($counted) + count($levels) > self::COUNT_MAX) {
            $_SESSION['flash_error'] = 'At most ' . self::COUNT_MAX . ' rows can be saved at once. Filter the list and save in parts.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $moved = 0; $same = 0;
        foreach ($counted as [$pid, $vid, $n]) {
            $d = ShopStock::set($pid, $vid, $n, 'recount', $note !== '' ? $note : 'Stock-take', $admin);
            $d === 0 ? $same++ : ($d !== null ? $moved++ : null);
        }
        foreach ($levels as [$pid, $vid, $lvl]) ShopStock::setOwnLow($pid, $vid, $lvl);

        $default = trim((string) ($b['default_low'] ?? ''));
        if ($default !== '' && preg_match('/^\d+$/', $default) && (int) $default !== ShopStock::lowLevel()) {
            ShopStock::setLowLevel((int) $default);
            $levels[] = 'default';
        }

        $this->audit->record($admin, 'shop.stock.count', 'product', null,
            ['counted' => count($counted), 'changed' => $moved, 'levels' => count($levels)]);
        $_SESSION['flash_ok'] = count($counted) . ' counted: ' . $moved . ' corrected, ' . $same . ' already right.'
            . ($levels !== [] ? ' ' . count($levels) . ' low-stock level(s) saved.' : '');
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** One reason-coded movement: received, damaged, given away. */
    public function adjust(Request $req, Response $res): Response
    {
        $b      = (array) $req->getParsedBody();
        // One picker, `product:variant`, so the form needs no script; the ids are also accepted
        // separately (the history page posts them for its own product).
        if (preg_match('/^(\d+):(\d+)$/', (string) ($b['pick'] ?? ''), $m)) {
            $b['product_id'] = $m[1];
            $b['variant_id'] = $m[2];
        }
        $pid    = (int) ($b['product_id'] ?? 0);
        $vid    = (int) ($b['variant_id'] ?? 0);
        $qty    = (int) ($b['qty'] ?? 0);
        $reason = (string) ($b['reason'] ?? '');
        $note   = mb_substr(trim((string) ($b['note'] ?? '')), 0, 200);
        $admin  = (int) ($_SESSION['admin_id'] ?? 0);
        $back   = (string) ($b['back'] ?? '') === 'history' ? '/admin/shop/products/' . $pid . '/stock' : '/admin/shop/stock';

        if (!in_array($reason, ['received', 'damaged', 'giveaway'], true) || $qty < 1) {
            $_SESSION['flash_error'] = 'Pick what happened and how many (1 or more).';
            return $res->withHeader('Location', $back)->withStatus(302);
        }
        $slug = (string) (DB::table('gates_products')->where('id', $pid)->value('slug') ?? '');
        if ($slug === '' || ($vid > 0 && !DB::table('gates_product_variants')->where('id', $vid)->where('product_id', $pid)->exists())) {
            $_SESSION['flash_error'] = 'That product or option could not be found.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $ok = ShopStock::move($slug, $vid, $reason === 'received' ? $qty : -$qty, $reason, $note, null, $admin);
        if ($ok) {
            $this->audit->record($admin, 'shop.stock.adjust', 'product', $pid, ['variant' => $vid, 'qty' => $qty, 'reason' => $reason]);
            $_SESSION['flash_ok'] = ShopStock::REASONS[$reason] . ': ' . $qty . ' recorded.';
        } else {
            $_SESSION['flash_error'] = 'Nothing changed — that option is not counted (switch counting on by entering a number in the stock-take), or there was none to take.';
        }
        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** One product's ledger. */
    public function history(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $p  = DB::table('gates_products')->where('id', $id)->first();
        if (!$p) {
            $_SESSION['flash_error'] = 'That product could not be found.';
            return $res->withHeader('Location', '/admin/shop/stock')->withStatus(302);
        }
        $rows = array_values(array_filter(ShopStock::rows(), static fn ($r) => $r['product_id'] === $id));
        return $this->view->render($res, 'admin/shop/stock-history.twig', [
            'page_title' => 'Stock history — ' . (string) $p->name . ' — Admin',
            'admin_page' => 'shop-stock',
            'product'    => (array) $p,
            'now'        => $rows,
            'moves'      => ShopStock::history($id),
            'ready'      => SchemaHas::table('gates_shop_stock_moves'),
            'reasons'    => array_intersect_key(ShopStock::REASONS, array_flip(['received', 'damaged', 'giveaway'])),
        ]);
    }
}
