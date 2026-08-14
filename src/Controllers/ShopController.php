<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{PaymentService, ShopPricing, ShopCatalogue, ShopShipping,
                         StockAlert, CurrencyService};

/**
 * Public storefront. Products come straight from gates_products (admin-managed) —
 * never hardcoded — so prices and stock are always whatever the admin set.
 * Checkout/order creation lives in ShopCheckoutController (reuses PaymentService).
 *
 * ── WHAT CHANGED HERE ────────────────────────────────────────────────────────
 *
 * The grid used to render EVERY active product and let a client-side Alpine filter hide the
 * ones that did not match a category chip. That works at nine products and stops working at
 * ninety: the page carries the whole catalogue, the "3 items" count describes things nobody
 * asked for, and there is no way to search at all. Filtering, sorting and paging are SQL now
 * ({@see ShopCatalogue::browse()}), so the page is the size of what is on it.
 *
 * And a product knows its variants. Selling apparel without a size meant somebody had to email
 * every buyer to ask what size they were, which means the order was not actually complete when
 * the money arrived.
 */
class ShopController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?PaymentService $payments = null,
        private readonly ?CurrencyService $currency = null,
    ) {}

    /** [code, enabled, NGN→code rate, symbol] for the active display currency. */
    private function currencyContext(Request $req): array
    {
        if (!$this->currency || !$this->currency->enabled()) {
            return ['NGN', false, 1.0, '₦'];
        }
        $cur = $this->currency->current($req);
        return [$cur, true, $this->currency->rate($cur), $this->currency->symbol($cur)];
    }

    /** Format a NGN price into the display currency (rate resolved once per request). */
    private function priceLabel(int $ngn, string $cur, float $rate, string $sym): string
    {
        if ($cur === 'NGN') return '₦' . number_format($ngn);
        $v = $ngn * $rate;
        return $sym . number_format($v, $v < 100 ? 2 : 0);
    }

    /** Enabled gateways for the checkout UI ([] → show "checkout opening soon"). */
    private function providers(): array
    {
        if (!$this->payments) return [];
        $labels = ['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave'];
        return array_map(
            fn($id) => ['id' => $id, 'label' => $labels[$id] ?? ucfirst($id)],
            $this->payments->enabledProviderIds()
        );
    }

    public function index(Request $req, Response $res): Response
    {
        $q      = $req->getQueryParams();
        $region = ShopPricing::currentRegion($req);
        $mults  = ShopPricing::multipliers();
        [$cur, $curEnabled, $rate, $sym] = $this->currencyContext($req);

        $found = ShopCatalogue::browse([
            'q'        => (string) ($q['q'] ?? ''),
            'category' => (string) ($q['c'] ?? ''),
            'sort'     => (string) ($q['sort'] ?? ''),
            'page'     => (int) ($q['page'] ?? 1),
        ]);

        $products = $found['rows'];
        foreach ($products as &$p) {
            $p['display_price'] = ShopPricing::adjust((int) $p['price_naira'], $region, $mults);
            $p['price_label']   = $this->priceLabel($p['display_price'], $cur, $rate, $sym);
            // Read on the grid too, not only on the product page: a card that offers "Add" for
            // something sold out is a promise the checkout then has to break.
            $p['variants']   = ShopCatalogue::variants((int) $p['id'], $p['display_price']);
            $p['stock_note'] = ShopCatalogue::stockNote($p);
            $p['sold_out']   = $p['stock_note'] === 'Sold out';
            $p['choose']     = $p['variants'] !== [];
        }
        unset($p);

        return $this->view->render($res, 'pages/shop/index.twig', [
            'page_title'       => 'Shop — Africa GATES',
            'meta_description' => 'Heritage-grade Africa GATES apparel and keepsakes. Every purchase funds child leadership programmes across the continent.',
            'gates_page'       => 'shop',
            'has_hero'         => false,
            'current_section'  => 'projects',
            'products'         => $products,
            // Every category with something active in it — read from the catalogue rather than
            // from the products on THIS page, or paging to page 2 would make chips disappear.
            'categories'       => ShopCatalogue::categories(),
            'found'            => $found,
            'sorts'            => ShopCatalogue::SORTS,
            'providers'        => $this->providers(),
            'region'           => $region,
            'regions'          => ShopPricing::regions(),
            'region_priced'    => ShopPricing::isActive($mults),
            'ship_rates'       => ShopShipping::rates(),
            'ship_active'      => ShopShipping::isActive(),
            'ship_free_over'   => ShopShipping::freeOver(),
            'currency'         => $cur,
            'currency_enabled' => $curEnabled,
            'currencies'       => CurrencyService::CURRENCIES,
            'fx_rate'          => $rate,
            'fx_symbol'        => $sym,
        ]);
    }

    /**
     * POST /shop/{slug}/notify-me — ask to be told when a sold-out thing is back.
     *
     * ── THE SAME DEAD END THE EVENT WAITLIST CLOSED ──────────────────────────
     *
     * A sold-out product page's entire answer was a greyed-out button. Stock comes back far
     * more often than a seat does, and somebody who wanted a Large enough to arrive on the page
     * the week it ran out is the easiest sale this shop will ever make.
     *
     * The answer is deliberately identical whether or not the address was already on the list.
     * Saying "you are already signed up" would be a way to test which addresses are — and this
     * list is a record of what somebody wanted to buy.
     */
    public function notifyMe(Request $req, Response $res, array $args): Response
    {
        $json = function (array $payload) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json');
        };

        $product = DB::table('gates_products')
            ->where('slug', (string) ($args['slug'] ?? ''))->where('is_active', 1)->first();
        if (!$product) return $json(['success' => false, 'message' => 'That product is not available.']);

        $b  = (array) $req->getParsedBody();
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        $r = StockAlert::want(
            (int) $product->id,
            max(0, (int) ($b['variant_id'] ?? 0)),
            trim((string) ($b['email'] ?? '')),
            trim((string) ($b['name'] ?? '')),
            $ip !== '' ? hash('sha256', $ip) : ''
        );

        return $json(['success' => (bool) $r['ok'], 'message' => (string) $r['message']]);
    }

    /**
     * GET /shop/back-in-stock/stop/{token} — one click, no account.
     *
     * Same doctrine as an event ticket: the person who asked has no login, and requiring one to
     * STOP receiving mail would be the worst possible place to put a registration wall. The
     * page never says whether the token was real — it confirms either way, because the
     * difference is a way to test tokens and the outcome for the reader is identical.
     */
    public function stopAlert(Request $req, Response $res, array $args): Response
    {
        StockAlert::stop((string) ($args['token'] ?? ''));

        return $this->view->render($res, 'pages/shop/alert-stopped.twig', [
            'page_title' => 'You are off that list — Africa GATES',
            'gates_page' => 'shop',
            'has_hero'   => false,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function item(Request $req, Response $res, array $args): Response
    {
        $slug    = (string)($args['slug'] ?? '');
        $product = DB::table('gates_products')->where('slug', $slug)->where('is_active', 1)->first();
        if (!$product) throw new \Slim\Exception\HttpNotFoundException($req);
        $product = (array)$product;
        $region = ShopPricing::currentRegion($req);
        $mults  = ShopPricing::multipliers();
        $product['display_price'] = ShopPricing::adjust((int)$product['price_naira'], $region, $mults);
        [$cur, $curEnabled, $rate, $sym] = $this->currencyContext($req);
        $product['price_label'] = $this->priceLabel($product['display_price'], $cur, $rate, $sym);
        $deliveryRegions = !empty($product['delivery_regions']) ? (json_decode((string)$product['delivery_regions'], true) ?: []) : [];

        // "You may also like" — same category first, topped up with anything else.
        $related = DB::table('gates_products')->where('is_active', 1)
            ->where('category', $product['category'])->where('id', '!=', $product['id'])
            ->orderByDesc('id')->limit(4)->get()->map(fn($r) => (array)$r)->all();
        if (count($related) < 4) {
            $exclude = array_merge([$product['id']], array_column($related, 'id'));
            $more = DB::table('gates_products')->where('is_active', 1)->whereNotIn('id', $exclude)
                ->orderByDesc('id')->limit(4 - count($related))->get()->map(fn($r) => (array)$r)->all();
            $related = array_merge($related, $more);
        }

        $blurb = trim(strip_tags((string)($product['description'] ?? '')));
        $meta  = $blurb !== ''
            ? (mb_strlen($blurb) > 160 ? rtrim(mb_substr($blurb, 0, 157)) . '…' : $blurb)
            : ($product['name'] . ' — from the Africa GATES shop. Every purchase funds child leadership programmes.');

        foreach ($related as &$r) {
            $r['display_price'] = ShopPricing::adjust((int)$r['price_naira'], $region, $mults);
            $r['price_label']   = $this->priceLabel($r['display_price'], $cur, $rate, $sym);
            $r['variants']      = ShopCatalogue::variants((int) $r['id'], $r['display_price']);
            $r['stock_note']    = ShopCatalogue::stockNote($r);
            $r['sold_out']      = $r['stock_note'] === 'Sold out';
            $r['choose']        = $r['variants'] !== [];
        }
        unset($r);

        // Variants priced off the REGION-ADJUSTED base, not the raw one: a delta added to the
        // wrong base would show a size costing less than the product it is a size of.
        $variants = ShopCatalogue::variants((int) $product['id'], (int) $product['display_price']);
        $product['variants']   = $variants;
        $product['stock_note'] = ShopCatalogue::stockNote($product);
        $product['sold_out']   = $product['stock_note'] === 'Sold out';

        return $this->view->render($res, 'pages/shop/item.twig', [
            'variants'  => $variants,
            // How many people are already waiting on each option. Shown as a plain fact next
            // to a sold-out size: "you and eleven others" is a different thing to be told than
            // "sold out", and it is also the number that tells an organiser what to restock.
            'waiting'   => StockAlert::waitingByVariant((int) $product['id']),
            'axis'      => $variants !== [] ? ((string) ($variants[0]['axis'] ?? '') ?: 'Option') : '',
            'gallery'   => ShopCatalogue::images(
                (int) $product['id'], $product['cover_path'] ?? null, (string) $product['name']
            ),
            'ship_rates'     => ShopShipping::rates(),
            'ship_active'    => ShopShipping::isActive(),
            'ship_free_over' => ShopShipping::freeOver(),
            'page_title'       => $product['name'] . ' — Africa GATES Shop',
            'meta_description' => $meta,
            'og_title'         => $product['name'] . ' — Africa GATES',
            'gates_page'       => 'shop',
            'has_hero'         => false,
            'current_section'  => 'projects',
            'product'          => $product,
            'related'          => $related,
            'providers'        => $this->providers(),
            'delivery_regions' => $deliveryRegions,
            'region'           => $region,
            'regions'          => ShopPricing::regions(),
            'region_priced'    => ShopPricing::isActive($mults),
            'currency'         => $cur,
            'currency_enabled' => $curEnabled,
            'currencies'       => CurrencyService::CURRENCIES,
            'fx_rate'          => $rate,
            'fx_symbol'        => $sym,
        ]);
    }
}
