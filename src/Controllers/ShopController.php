<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{PaymentService, ShopPricing, CurrencyService};

/**
 * Public storefront. Products come straight from gates_products (admin-managed) —
 * never hardcoded — so prices and stock are always whatever the admin set.
 * Checkout/order creation lives in ShopCheckoutController (reuses PaymentService).
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

    /** @return array<int,array<string,mixed>> active products, in admin sort order */
    private function activeProducts(): array
    {
        return DB::table('gates_products')
            ->where('is_active', 1)
            ->orderBy('sort_order')->orderByDesc('id')
            ->get()->map(fn($r) => (array)$r)->all();
    }

    public function index(Request $req, Response $res): Response
    {
        $products = $this->activeProducts();
        $region = ShopPricing::currentRegion($req);
        $mults  = ShopPricing::multipliers();
        [$cur, $curEnabled, $rate, $sym] = $this->currencyContext($req);
        $cats = [];
        foreach ($products as &$p) {
            $p['display_price'] = ShopPricing::adjust((int)$p['price_naira'], $region, $mults);
            $p['price_label']   = $this->priceLabel($p['display_price'], $cur, $rate, $sym);
            if (!empty($p['category']) && !in_array($p['category'], $cats, true)) $cats[] = $p['category'];
        }
        unset($p);
        return $this->view->render($res, 'pages/shop/index.twig', [
            'page_title'       => 'Shop — Africa GATES',
            'meta_description' => 'Heritage-grade Africa GATES apparel and keepsakes. Every purchase funds child leadership programmes across the continent.',
            'gates_page'       => 'shop',
            'has_hero'         => false,
            'current_section'  => 'projects',
            'products'         => $products,
            'categories'       => $cats,
            'providers'        => $this->providers(),
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
        }
        unset($r);

        return $this->view->render($res, 'pages/shop/item.twig', [
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
