<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{CookiePrefs, PaymentService, ShopPricing, ShopCatalogue, ShopShipping,
                         StockAlert, CurrencyService};

/**
 * Public storefront — ShopPage.dc.html, Phase 7 §8.12 (rebuilt 8 Oct 2026).
 *
 * Products come straight from gates_products (admin-managed), never hardcoded, so prices
 * and stock are whatever the admin set. Checkout and order pages live in
 * ShopCheckoutController, which this file does not touch: the cart this page builds is a
 * PREVIEW and every naira is decided again by `ShopCheckoutController::priceCart()`.
 *
 * ── EVERY FILTER IS IN THE URL, AND THE URL IS A LIST ────────────────────────
 *
 * `?c[]=&col[]=&sz[]=&min=&max=&stock=1&feat=1&sort=&q=&n=` (§8.12). A plain GET form, so a
 * filtered shop works with no script, survives Back, and can be sent to somebody. The old
 * controller cast `c` to a string, so the spec's `c[]` arrived as "Array" and an empty shop
 * (GAPS §3.13); {@see listParam()} reads a scalar and a list alike.
 *
 * `n` is "Load more" (+4 a step, 8 to start, ShopPage.dc.html). A depth rather than a page
 * number, so the reloaded URL shows everything the buyer had already scrolled past.
 *
 * ── DELIVER-TO AND DISPLAY CURRENCY ARE COOKIES, WRITTEN HERE ───────────────
 *
 * `ag_region` and `ag_currency` lost their only writer when the old layout's `data-cookie`
 * listener was destroyed. They are written now by the server, from `?deliver=` and `?cur=`
 * on this GET, and only as long as the visitor allows Preferences
 * ({@see CookiePrefs::allows()}): refused, each is a session cookie — the choice still
 * works for this visit, which is what the visitor asked for, and is not remembered for the
 * next one, which is the part they refused. Same rule as the language cookie.
 */
class ShopController
{
    /** "Load more": how many cards to start with, and how many each press adds (DC: 8, +4). */
    public const FIRST = 8;
    public const STEP  = 4;

    /** The orderings the toolbar offers (ShopPage.dc.html), in its order. */
    public const SORT_OPTIONS = ['featured' => 'Featured', 'new' => 'Newest',
                                 'cheap' => 'Price, low to high', 'dear' => 'Price, high to low'];

    /**
     * What a bounced checkout says, keyed by every code ShopCheckoutController emits.
     *
     * Rendered by the server rather than by a script map, so a buyer whose script did not
     * load is still told why they are back on the shop. `ShopCheckoutNoticeTest` holds the
     * two lists together: the controller emitted twelve codes once and the old map had ten,
     * and the two missing were `mismatch` and `gone` — an empty box for a customer who had
     * just been debited.
     *
     * `mismatch` is `wait`, never `err`: the gateway says money MOVED, and wording that as a
     * failure is how a debited customer becomes a chargeback.
     */
    public const CHECKOUT_NOTICES = [
        'busy'        => ['err',  'Checkout is busy right now. Nothing was charged — please try again in a moment.'],
        'empty'       => ['err',  'Your cart is empty, so there was nothing to pay for.'],
        'region'      => ['err',  'Choose the region we are delivering to, so delivery can be priced.'],
        'gone'        => ['err',  'Something in your cart sold out while you were checking out. Nothing was charged.'],
        'noship'      => ['err',  'One item in your cart does not deliver to that region. Nothing was charged.'],
        'unavailable' => ['err',  'That way to pay is unavailable right now. Nothing was charged — please try another.'],
        'email'       => ['err',  'Enter an email address we can send your receipt to.'],
        'details'     => ['err',  'Add your name and a delivery address so the courier can find you.'],
        'error'       => ['err',  'We could not save your order. Nothing was charged — please try again.'],
        'codegone'    => ['err',  'That discount code ran out before your order went through. Nothing was charged.'],
        'start'       => ['err',  'We could not start the payment. Nothing was charged — please try again.'],
        'failed'      => ['err',  'That payment did not complete, so nothing was charged.'],
        'mismatch'    => ['wait', 'Your payment arrived but the amount needs a person to check it. Keep your reference — we will be in touch, and nothing more will be taken.'],
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly ?PaymentService $payments = null,
        private readonly ?CurrencyService $currency = null,
    ) {}

    // ══ shared ═══════════════════════════════════════════════════════════════

    /** A query parameter as a clean list: `?c=A`, `?c[]=A&c[]=B` and nothing read alike. */
    private static function listParam(array $q, string $key): array
    {
        $raw = $q[$key] ?? [];
        $out = [];
        foreach (is_array($raw) ? $raw : [$raw] as $v) {
            if (!is_scalar($v)) continue;
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
        }
        return $out;
    }

    /**
     * The delivery region and display currency for THIS request, and the cookies to write.
     *
     * The query wins over the cookie, because it is the visitor's newest answer; it is then
     * written so the next page agrees. Validated against the canonical lists before it is
     * used or stored — a cookie is somebody's typing.
     *
     * @return array{region:string, cur:string, set:list<array{0:string,1:string}>}
     */
    private function choices(Request $req): array
    {
        $q   = $req->getQueryParams();
        $set = [];

        $region = ShopPricing::currentRegion($req);
        $want   = trim((string) (is_scalar($q['deliver'] ?? null) ? $q['deliver'] : ''));
        if ($want !== '' && in_array($want, ShopPricing::regions(), true)) {
            $region = $want;
            $set[]  = [ShopPricing::COOKIE, $want];
        }

        $cur = 'NGN';
        if ($this->currency && $this->currency->enabled()) {
            $cur  = $this->currency->current($req);
            $want = strtoupper(trim((string) (is_scalar($q['cur'] ?? null) ? $q['cur'] : '')));
            if ($want !== '' && $this->currency->isValid($want)) {
                $cur   = $want;
                $set[] = [CurrencyService::COOKIE, $want];
            }
        }
        return ['region' => $region, 'cur' => $cur, 'set' => $set];
    }

    /** Write the remembered choices onto the response — see the class note for the rule. */
    private static function remember(Request $req, Response $res, array $set): Response
    {
        if ($set === []) return $res;
        $keep   = CookiePrefs::allows($req, CookiePrefs::PREFERENCES);
        $secure = (bool) (session_get_cookie_params()['secure'] ?? false);
        foreach ($set as [$name, $value]) {
            $parts = [$name . '=' . rawurlencode($value), 'Path=/'];
            if ($keep) $parts[] = 'Max-Age=' . (365 * 86400);
            $parts[] = 'HttpOnly';
            $parts[] = 'SameSite=Lax';
            if ($secure) $parts[] = 'Secure';
            $res = $res->withAddedHeader('Set-Cookie', implode('; ', $parts));
        }
        return $res;
    }

    /** [code, enabled, NGN→code rate, symbol] for the display currency. */
    private function currencyContext(string $cur): array
    {
        if (!$this->currency || !$this->currency->enabled() || $cur === 'NGN') {
            return [$cur === 'NGN' || !$this->currency ? 'NGN' : $cur, (bool) $this->currency?->enabled(), 1.0, '₦'];
        }
        return [$cur, true, $this->currency->rate($cur), $this->currency->symbol($cur)];
    }

    /** A NGN price in the display currency — the DC's `fmt()`. */
    private static function priceLabel(int $ngn, string $cur, float $rate, string $sym): string
    {
        if ($cur === 'NGN') return '₦' . number_format($ngn);
        $v = $ngn * $rate;
        return $sym . ($sym !== '$' && $sym !== '£' && $sym !== '€' ? ' ' : '') . number_format($v, $v < 100 ? 2 : 0);
    }

    /** Whether a product's options are priced differently, so a card can say "From". */
    private static function varies(array $variants): bool
    {
        if (count($variants) < 2) return false;
        $seen = [];
        foreach ($variants as $v) $seen[(int) $v['price_naira']] = true;
        return count($seen) > 1;
    }

    /** Gateways that can take money right now. Empty means checkout is not open. */
    private function providers(): array
    {
        if (!$this->payments) return [];
        return array_map(static fn (array $p): array => ['id' => $p['id'], 'label' => $p['label']],
                         $this->payments->enabledProviders());
    }

    /**
     * One card's worth of a product, in the shape the grid, "You may also like" and the
     * cart all read — one decoration, so a price or a stock word cannot differ between them.
     */
    private static function card(array $p, string $region, array $mults, string $cur, float $rate, string $sym): array
    {
        $p['display_price'] = ShopPricing::adjust((int) $p['price_naira'], $region, $mults);
        $p['price_label']   = self::priceLabel($p['display_price'], $cur, $rate, $sym);
        $p['variants']      = ShopCatalogue::variants((int) $p['id'], $p['display_price']);
        $p['stock_note']    = ShopCatalogue::stockNote($p);
        $p['sold_out']      = $p['stock_note'] === 'Sold out';
        // "Only N left" is the one stock line a card prints (DC `hasStock`: `low` only).
        $p['low']           = str_starts_with($p['stock_note'], 'Only ');
        $p['dots']          = ShopCatalogue::swatchDots($p['variants'], 4);
        $p['colour_count']  = count($p['dots']['dots']) + (int) $p['dots']['more'];
        $axes               = ShopCatalogue::axesFromVariants($p['variants']);
        // The question the card's action names: "Choose size", "Choose colour". Never "Add"
        // for something that needs a choice (§8.12).
        $p['choose']        = $axes !== [] ? strtolower((string) ($axes[count($axes) > 1 ? 1 : 0]['name'] ?? 'option')) : '';
        $p['price_from']    = self::varies($p['variants']);
        $p['subtitle']      = trim((string) ($p['subtitle'] ?? ''));
        return $p;
    }

    // ══ /shop ════════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        $q   = $req->getQueryParams();
        $ch  = $this->choices($req);
        $region = $ch['region'];
        $mults  = ShopPricing::multipliers();
        [$cur, $curEnabled, $rate, $sym] = $this->currencyContext($ch['cur']);

        // The multiplier this region applies, so the price filter's numbers and the SQL it
        // drives are the same money. Asked of ShopPricing rather than read off its table.
        $mult = ShopPricing::adjust(1000, $region, $mults) / 1000;
        $n    = max(self::FIRST, min(200, (int) ($q['n'] ?? self::FIRST)));
        $sortWanted = (string) (is_scalar($q['sort'] ?? null) ? $q['sort'] : '');
        // The DC's words for the two price orders, so a link built from the design works.
        $sortWanted = ['low' => 'cheap', 'high' => 'dear'][$sortWanted] ?? $sortWanted;

        $filter = [
            'q'          => trim((string) (is_scalar($q['q'] ?? null) ? $q['q'] : '')),
            'categories' => self::listParam($q, 'c'),
            'colours'    => self::listParam($q, 'col'),
            'sizes'      => self::listParam($q, 'sz'),
            'sort'       => $sortWanted,
            // Absent means "not filtering", which is different from 0.
            'min'        => isset($q['min']) && is_scalar($q['min']) && trim((string) $q['min']) !== '' ? (int) $q['min'] : null,
            'max'        => isset($q['max']) && is_scalar($q['max']) && trim((string) $q['max']) !== '' ? (int) $q['max'] : null,
            'in_stock'   => !empty($q['stock']),
            'featured'   => !empty($q['feat']),
            'mult'       => $mult,
        ];
        $found = ShopCatalogue::browse($filter + ['limit' => $n]);

        $products = array_map(fn (array $p): array => self::card($p, $region, $mults, $cur, $rate, $sym), $found['rows']);

        // The promotion row is the first FEATURED product, whatever it is. The DC draws a
        // framed citation there; this shop's operator decides what is featured, and a row
        // naming a product the catalogue does not hold would be a promise with no page.
        $promo = null;
        try {
            $f = DB::table('gates_products')->where('is_active', 1)->where('is_featured', 1)
                ->orderBy('sort_order')->orderByDesc('id')->first();
            if ($f) $promo = self::card((array) $f, $region, $mults, $cur, $rate, $sym);
        } catch (\Throwable) {}

        $notice = null;
        $code = (string) (is_scalar($q['checkout'] ?? null) ? $q['checkout'] : '');
        if (isset(self::CHECKOUT_NOTICES[$code])) {
            $notice = ['code' => $code, 'tone' => self::CHECKOUT_NOTICES[$code][0],
                       'text' => self::CHECKOUT_NOTICES[$code][1],
                       'ref'  => trim((string) (is_scalar($q['ref'] ?? null) ? $q['ref'] : ''))];
        }

        $view = $this->view->render($res, 'pages/shop/index.twig', [
            'page_title'       => 'Shop — Africa GATES',
            'meta_description' => 'Africa GATES apparel and keepsakes from African makers. Every purchase funds child leadership programmes across the continent.',
            'gates_page'       => 'shop',
            'products'         => $products,
            'promo'            => $promo,
            'found'            => $found,
            'facets'           => ShopCatalogue::facets($filter),
            'shown'            => $n,
            'step'             => self::STEP,
            'sorts'            => self::SORT_OPTIONS,
            'price_range'      => ShopCatalogue::priceRange($mult),
            'notice'           => $notice,
        ] + $this->common($region, $mults, $cur, $curEnabled, $rate, $sym));

        return self::remember($req, $view, $ch['set']);
    }

    /**
     * What every shop screen carries for the cart, the delivery line and the selectors.
     *
     * One array, because the index and the product page both open the same cart drawer and
     * a field one of them forgot to pass is a cart that silently loses its region.
     */
    private function common(string $region, array $mults, string $cur, bool $curEnabled, float $rate, string $sym): array
    {
        $rates = ShopShipping::rates();
        return [
            'providers'        => $this->providers(),
            // A bounced checkout's own values, so the cart reopens populated. Read-and-clear.
            'checkout_retry'   => ShopCheckoutController::takeRetry(),
            'region'           => $region,
            'regions'          => ShopPricing::regions(),
            'region_priced'    => ShopPricing::isActive($mults),
            'ship_rate'        => (int) ($rates[$region] ?? 0),
            'ship_active'      => ShopShipping::isActive($rates),
            'ship_free_over'   => ShopShipping::freeOver(),
            'currency'         => $cur,
            'currency_enabled' => $curEnabled,
            'currencies'       => CurrencyService::CURRENCIES,
            'fx'               => ['cur' => $cur, 'rate' => $rate, 'sym' => $sym],
        ];
    }

    // ══ /shop/{slug} ═════════════════════════════════════════════════════════

    public function item(Request $req, Response $res, array $args): Response
    {
        $slug    = (string) ($args['slug'] ?? '');
        $product = DB::table('gates_products')->where('slug', $slug)->where('is_active', 1)->first();
        if (!$product) throw new \Slim\Exception\HttpNotFoundException($req);

        $ch     = $this->choices($req);
        $region = $ch['region'];
        $mults  = ShopPricing::multipliers();
        [$cur, $curEnabled, $rate, $sym] = $this->currencyContext($ch['cur']);

        $p = self::card((array) $product, $region, $mults, $cur, $rate, $sym);
        $deliveryRegions = !empty($p['delivery_regions']) ? (json_decode((string) $p['delivery_regions'], true) ?: []) : [];

        // "You may also like" — same category first, topped up with anything else.
        $related = DB::table('gates_products')->where('is_active', 1)
            ->where('category', $p['category'])->where('id', '!=', $p['id'])
            ->orderByDesc('id')->limit(4)->get()->map(fn ($r) => (array) $r)->all();
        if (count($related) < 4) {
            $exclude = array_merge([$p['id']], array_column($related, 'id'));
            $more = DB::table('gates_products')->where('is_active', 1)->whereNotIn('id', $exclude)
                ->orderByDesc('id')->limit(4 - count($related))->get()->map(fn ($r) => (array) $r)->all();
            $related = array_merge($related, $more);
        }
        $related = array_map(fn (array $r): array => self::card($r, $region, $mults, $cur, $rate, $sym), $related);

        $blurb = trim(strip_tags((string) ($p['description'] ?? '')));
        $meta  = $blurb !== ''
            ? (mb_strlen($blurb) > 160 ? rtrim(mb_substr($blurb, 0, 157)) . '…' : $blurb)
            : ($p['name'] . ' — from the Africa GATES shop. Every purchase funds child leadership programmes.');

        // Each variant priced in the display currency too, so the buy bar's total and the
        // "Charged in Naira" line come from the server's arithmetic and not the browser's.
        $variants = array_map(static function (array $v) use ($cur, $rate, $sym): array {
            $v['price_label'] = self::priceLabel((int) $v['price_naira'], $cur, $rate, $sym);
            return $v;
        }, $p['variants']);

        $view = $this->view->render($res, 'pages/shop/item.twig', [
            'page_title'       => $p['name'] . ' — Africa GATES Shop',
            'meta_description' => $meta,
            'gates_page'       => 'shop',
            'product'          => $p,
            'variants'         => $variants,
            // The QUESTIONS, inverted out of the combination rows: a buyer answers "which
            // colour" and "which size", not "which of these twelve". See ShopCatalogue::axes().
            'axes'             => ShopCatalogue::axesFromVariants($p['variants']),
            // How many people are already waiting on each option — "you and eleven others".
            'waiting'          => StockAlert::waitingByVariant((int) $p['id']),
            'gallery'          => ShopCatalogue::images((int) $p['id'], $p['cover_path'] ?? null, (string) $p['name']),
            'related'          => $related,
            'delivery_regions' => $deliveryRegions,
            'detail_sections'  => ShopCatalogue::detailSections((string) ($p['details'] ?? '')),
        ] + $this->common($region, $mults, $cur, $curEnabled, $rate, $sym));

        return self::remember($req, $view, $ch['set']);
    }

    // ══ restock alerts ═══════════════════════════════════════════════════════

    /**
     * POST /shop/{slug}/notify-me — ask to be told when a sold-out thing is back.
     *
     * The answer is deliberately identical whether or not the address was already on the
     * list: "you are already signed up" would be a way to test which addresses are, and this
     * list is a record of what somebody wanted to buy.
     *
     * JSON for the page's script; a plain form post (no script) is redirected back to the
     * product with the answer in the session, so the alert works with scripting off.
     */
    public function notifyMe(Request $req, Response $res, array $args): Response
    {
        $wantsJson = str_contains(strtolower($req->getHeaderLine('Accept')), 'application/json');
        $slug = (string) ($args['slug'] ?? '');
        $answer = function (array $payload) use ($res, $wantsJson, $slug): Response {
            if (!$wantsJson) {
                if (isset($_SESSION) && is_array($_SESSION)) {
                    $_SESSION[$payload['success'] ? 'flash_ok' : 'flash_error'] = $payload['message'];
                }
                return $res->withHeader('Location', '/shop/' . rawurlencode($slug))->withStatus(303);
            }
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json');
        };

        $product = DB::table('gates_products')->where('slug', $slug)->where('is_active', 1)->first();
        if (!$product) return $answer(['success' => false, 'message' => 'That product is not available.']);

        $b  = (array) $req->getParsedBody();
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        $r = StockAlert::want(
            (int) $product->id,
            max(0, (int) ($b['variant_id'] ?? 0)),
            trim((string) ($b['email'] ?? '')),
            trim((string) ($b['name'] ?? '')),
            $ip !== '' ? hash('sha256', $ip) : ''
        );

        return $answer(['success' => (bool) $r['ok'], 'message' => (string) $r['message']]);
    }

    /**
     * GET /shop/back-in-stock/stop/{token} — one click, no account.
     *
     * The page never says whether the token was real — it confirms either way, because the
     * difference is a way to test tokens and the outcome for the reader is identical.
     */
    public function stopAlert(Request $req, Response $res, array $args): Response
    {
        StockAlert::stop((string) ($args['token'] ?? ''));

        return $this->view->render($res, 'pages/shop/alert-stopped.twig', [
            'page_title' => 'Alert stopped — Africa GATES',
            'gates_page' => 'shop',
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
