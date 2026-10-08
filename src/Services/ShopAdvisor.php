<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The buying specialist's hands: everything it can look up about the shop, deterministically.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY EVERY ANSWER IS COMPUTED HERE AND NOT WRITTEN BY THE MODEL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The support assistant is good at problems: a payment that has not landed, a vote that did not
 * appear. It cannot help somebody DECIDE — which is most of what anybody wants from a shop, and
 * the whole of what a buying specialist is for.
 *
 * The tempting version of that feature is a model that has read the catalogue and talks about
 * it. That version is unsafe in a specific, expensive way: a language model asked "do you have
 * this in navy, extra large" will answer "yes" more often than the stock allows, because
 * agreement is the likelier continuation of the sentence. And an assistant that promises a size
 * the shop cannot ship does not create a support ticket — it creates a paid order somebody has
 * to be telephoned about, or a refund.
 *
 * So the division is strict, and it is the same one the rest of this platform uses for money:
 *
 *   • THE ARITHMETIC AND THE FACTS ARE HERE, read from `gates_products` and
 *     `gates_product_variants` through {@see ShopCatalogue} — the same code the product page
 *     and the checkout use, so the three cannot disagree about what is in stock.
 *   • THE MODEL ONLY CHOOSES WHICH QUESTION TO ASK and how to word the answer. It is handed
 *     facts it did not invent, and it cannot reach past them.
 *
 * The consequence worth stating plainly: with no AI key configured at all, every method here
 * still returns a correct, complete answer. The assistant becomes terser, not wrong. That is
 * the same standard as the rest of the support agent, and it is why the tests below assert
 * behaviour rather than mocking a model.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT DELIBERATELY CANNOT DO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no method that adds to a basket, takes an address, or starts a payment. A specialist
 * that can transact is a specialist that can be talked into transacting, and the buyer needs to
 * see the price, the delivery charge and the total on a page they control before they pay.
 * {@see handoff()} returns a URL that opens the product with the exact option preselected —
 * which is the useful half, and leaves the deciding where it belongs.
 */
final class ShopAdvisor
{
    /** How many products a recommendation returns. More than a handful is a list, not advice. */
    public const SUGGEST = 4;

    /** Longest a free-text need may be before it is truncated. */
    private const MAX_NEED = 200;

    // ══ 1. finding something ═════════════════════════════════════════════════

    /**
     * Products that fit a described need, a budget, or both.
     *
     * ── WHY IT SCORES RATHER THAN FILTERS ────────────────────────────────────
     *
     * A `LIKE` over the name is how "something for my mother who likes bright colours" returns
     * nothing at all. The words people use to describe a need are not the words in a product
     * title, so an empty result would be a wrong answer rather than an honest one — the shop
     * does have something for her.
     *
     * So the budget is a HARD constraint (offering something somebody cannot afford is worse
     * than offering nothing) and everything else is a SCORE. A product always comes back if the
     * catalogue has anything in range, and the reason it was chosen comes back with it so the
     * assistant can say why rather than asserting taste it does not have.
     *
     * @param array{need?:string, budget?:?int, category?:string, in_stock?:bool} $want
     * @return array{
     *   items: list<array<string,mixed>>, considered:int, budget:?int,
     *   note: string, widened: bool
     * }
     */
    public static function suggest(array $want = []): array
    {
        $need     = self::clean((string) ($want['need'] ?? ''));
        $budget   = ($want['budget'] ?? null) !== null ? max(0, (int) $want['budget']) : null;
        $category = trim((string) ($want['category'] ?? ''));
        $inStock  = (bool) ($want['in_stock'] ?? true);

        $rows = self::live();
        $considered = count($rows);
        if ($rows === []) {
            return ['items' => [], 'considered' => 0, 'budget' => $budget,
                    'note' => 'The shop has nothing on sale at the moment.', 'widened' => false];
        }

        $pool = $rows;
        if ($category !== '') {
            $byCat = array_values(array_filter($pool,
                static fn (array $p): bool => strcasecmp((string) $p['category'], $category) === 0));
            // Only narrow if the category actually has something. A named category that is empty
            // should widen back rather than answer "nothing" — the request was for a gift, and
            // the category was the buyer's guess at where to look.
            if ($byCat !== []) $pool = $byCat;
        }

        if ($inStock) {
            $buyable = array_values(array_filter($pool, static fn (array $p): bool => !$p['sold_out']));
            if ($buyable !== []) $pool = $buyable;
        }

        $widened = false;
        if ($budget !== null) {
            $afford = array_values(array_filter($pool,
                static fn (array $p): bool => (int) $p['from_naira'] <= $budget));
            if ($afford === []) {
                // Nothing in range. Say so and show the cheapest things there are, rather than
                // silently offering something over budget as though it fitted.
                $widened = true;
                usort($pool, static fn (array $a, array $b): int => $a['from_naira'] <=> $b['from_naira']);
            } else {
                $pool = $afford;
            }
        }

        $scored = [];
        foreach ($pool as $p) {
            $s = self::score($p, $need, $budget);
            $scored[] = ['p' => $p, 'score' => $s['score'], 'why' => $s['why']];
        }
        // Score first, then price ascending, then id — a stable order, so the same question does
        // not get a different shortlist each time it is asked.
        usort($scored, static function (array $a, array $b): int {
            return [$b['score'], $a['p']['from_naira'], $a['p']['id']]
               <=> [$a['score'], $b['p']['from_naira'], $b['p']['id']];
        });

        $items = [];
        foreach (array_slice($scored, 0, self::SUGGEST) as $row) {
            $items[] = $row['p'] + ['why' => $row['why']];
        }

        $note = $widened && $budget !== null
            ? 'Nothing is under ₦' . number_format($budget) . '. These are the closest.'
            : '';

        return ['items' => $items, 'considered' => $considered, 'budget' => $budget,
                'note' => $note, 'widened' => $widened];
    }

    /**
     * Is this exact thing available — this product, in this colour, in this size?
     *
     * The single most important method here, because it is the question a model will otherwise
     * answer optimistically. It resolves the buyer's words to a real variant row and reports
     * what the checkout would report, so the two cannot disagree.
     *
     * Answers about the PAIR, not about either half: "Navy" being in stock is not an answer to
     * "navy in extra large", and treating it as one is how somebody is told yes and then refused
     * at the basket.
     *
     * @return array{
     *   ok: bool, found: bool, product?: array<string,mixed>, asked: array<string,string>,
     *   available?: bool, price_naira?: int, left?: ?int, label?: string,
     *   alternatives: list<array<string,mixed>>, message: string, url?: string
     * }
     */
    public static function availability(string $product, string $option = '', string $option2 = ''): array
    {
        $p = self::findOne($product);
        $asked = ['product' => self::clean($product), 'option' => self::clean($option),
                  'option2' => self::clean($option2)];

        if ($p === null) {
            return ['ok' => true, 'found' => false, 'asked' => $asked, 'alternatives' => [],
                    'message' => 'There is nothing in the shop by that name.'];
        }

        $row  = DB::table('gates_products')->where('id', (int) $p['id'])->first();
        $base = (int) $p['from_naira'];
        $vs   = ShopCatalogue::variants((int) $p['id'], (int) ($row->price_naira ?? $base));
        $url  = '/shop/' . (string) $p['slug'];

        // No options at all: the product itself is the thing.
        if ($vs === []) {
            $left = ShopCatalogue::available($row, 0);
            $can  = $left === null || $left > 0;
            return ['ok' => true, 'found' => true, 'product' => $p, 'asked' => $asked,
                    'available' => $can, 'price_naira' => (int) $p['from_naira'],
                    'left' => $left, 'label' => '', 'alternatives' => [], 'url' => $url,
                    'message' => $can
                        ? $p['name'] . ' is available at ₦' . number_format((int) $p['from_naira']) . '.'
                        : $p['name'] . ' is sold out.'];
        }

        // Match the buyer's words against both axes, in either order — somebody says "XL navy"
        // as readily as "navy XL", and a specialist that only understood one order would be
        // wrong half the time for no reason the buyer could see.
        $want = array_values(array_filter([$asked['option'], $asked['option2']]));
        $hit  = self::matchVariant($vs, $want);

        $alts = self::alternativesFor($vs, $hit);

        if ($hit === null) {
            // WHY IT DID NOT MATCH decides what to say, and the two cases are opposite.
            //
            // "Adire" on a Colour × Size product is not an unavailable combination — it is a
            // colour that exists in four sizes, and the buyer has answered one of two questions.
            // Reporting that as "not made" is worse than useless: it tells somebody a thing that
            // is on sale is not on sale, and they stop looking. What is needed is the OTHER
            // question. (Found by asking the advisor for a colour on its own and reading the
            // answer, which is the case a buyer types first.)
            $open = self::openQuestionFor($vs, $want);
            if ($open !== null && ($open['exhausted'] ?? false)) {
                // Made, but gone in every combination. Say that, and point at the thing that can
                // actually be done about it.
                return ['ok' => true, 'found' => true, 'product' => $p, 'asked' => $asked,
                        'available' => false, 'alternatives' => $alts, 'url' => $url,
                        'message' => $p['name'] . ' in ' . $open['given'] . ' is sold out in every '
                                   . mb_strtolower($open['axis']) . '. The product page can take '
                                   . 'their email and tell them when it is back.'];
            }
            if ($open !== null) {
                return ['ok' => true, 'found' => true, 'product' => $p, 'asked' => $asked,
                        'available' => null, 'alternatives' => $open['choices'], 'url' => $url,
                        'needs' => $open['axis'],
                        'message' => $p['name'] . ' comes in ' . $open['axis'] . ' too — '
                                   . 'ask which ' . mb_strtolower($open['axis']) . ' they want ('
                                   . implode(', ', array_column($open['choices'], 'label')) . ').'];
            }

            return ['ok' => true, 'found' => true, 'product' => $p, 'asked' => $asked,
                    'available' => false, 'alternatives' => $alts, 'url' => $url,
                    'message' => $want === []
                        ? $p['name'] . ' comes in options — ask which one they want.'
                        : 'That combination is not one ' . $p['name'] . ' is made in.'];
        }

        $can = !$hit['sold_out'];
        $lab = ShopCatalogue::describe($hit);

        return ['ok' => true, 'found' => true, 'product' => $p, 'asked' => $asked,
                'available' => $can, 'price_naira' => (int) $hit['price_naira'],
                'left' => $hit['stock'], 'label' => $lab,
                'alternatives' => $can ? [] : $alts, 'url' => $url . '?option=' . rawurlencode($lab),
                'message' => $can
                    ? $p['name'] . ' in ' . $lab . ' is available at ₦'
                      . number_format((int) $hit['price_naira']) . '.'
                    : $p['name'] . ' in ' . $lab . ' is sold out.'];
    }

    /**
     * What one thing would actually cost, delivered — the number a buyer is deciding on.
     *
     * Assembled from {@see ShopShipping}, which is the same service the checkout charges from.
     * Quoting a price without delivery is how an assistant is trusted and then contradicted at
     * the last screen, and the contradiction is the part people remember.
     *
     * @return array{
     *   ok: bool, found: bool, name?: string, goods_naira?: int, qty?: int,
     *   shipping_naira?: int, shipping_why?: string, total_naira?: int,
     *   free_over?: ?int, short_by?: int, message: string
     * }
     */
    public static function quote(string $product, string $option = '', string $option2 = '',
                                 int $qty = 1, string $region = ''): array
    {
        $qty = max(1, min(20, $qty));
        $a   = self::availability($product, $option, $option2);

        if (!($a['found'] ?? false)) {
            return ['ok' => true, 'found' => false, 'message' => $a['message']];
        }
        if (($a['price_naira'] ?? null) === null) {
            // Found, but no single price to quote — the option is still open.
            return ['ok' => true, 'found' => true, 'name' => (string) $a['product']['name'],
                    'message' => $a['message']];
        }

        $goods = (int) $a['price_naira'] * $qty;
        // One line, shaped the way the CHECKOUT shapes them — including `ships_free`, which is
        // what decides whether a basket of envelope-sized keepsakes is charged for delivery at
        // all. Calling the same service with the same shape is the only way the quote here and
        // the charge at the basket cannot disagree.
        $ship = ShopShipping::quote(
            [['ships_free' => (bool) $a['product']['ships_free']]],
            $region,
            $goods
        );

        $name = (string) $a['product']['name']
              . (($a['label'] ?? '') !== '' ? ' (' . $a['label'] . ')' : '');

        return ['ok' => true, 'found' => true, 'name' => $name,
                'goods_naira' => $goods, 'qty' => $qty,
                'shipping_naira' => (int) ($ship['naira'] ?? 0),
                'shipping_why'   => (string) ($ship['why'] ?? ''),
                'total_naira'    => $goods + (int) ($ship['naira'] ?? 0),
                'free_over'      => $ship['free_over'] ?? null,
                'short_by'       => (int) ($ship['short_by'] ?? 0),
                'message' => $name . ' × ' . $qty . ' comes to ₦'
                           . number_format($goods + (int) ($ship['naira'] ?? 0))
                           . ' delivered' . ($region !== '' ? ' to ' . $region : '') . '.'];
    }

    /**
     * Two products side by side, on the facts a buyer actually chooses between.
     *
     * Not a verdict. Which of two shirts is "better" depends on what somebody wants it for, and
     * a specialist that picks for them is guessing — so this returns the differences and lets
     * the assistant put the buyer's own stated need against them.
     *
     * @return array{ok:bool, found:bool, a?:array<string,mixed>, b?:array<string,mixed>,
     *               differences: list<string>, message: string}
     */
    public static function compare(string $first, string $second): array
    {
        $a = self::findOne($first);
        $b = self::findOne($second);

        if ($a === null || $b === null) {
            $missing = $a === null ? $first : $second;
            return ['ok' => true, 'found' => false, 'differences' => [],
                    'message' => 'I could not find “' . self::clean($missing) . '” in the shop.'];
        }
        if ((int) $a['id'] === (int) $b['id']) {
            return ['ok' => true, 'found' => false, 'differences' => [],
                    'message' => 'Those are the same product.'];
        }

        $diff = [];
        if ((int) $a['from_naira'] !== (int) $b['from_naira']) {
            $cheap = $a['from_naira'] < $b['from_naira'] ? $a : $b;
            $dear  = $a['from_naira'] < $b['from_naira'] ? $b : $a;
            $diff[] = $cheap['name'] . ' is ₦'
                    . number_format((int) $dear['from_naira'] - (int) $cheap['from_naira'])
                    . ' less.';
        }
        if ((string) $a['category'] !== (string) $b['category']) {
            $diff[] = $a['name'] . ' is ' . $a['category'] . '; ' . $b['name'] . ' is ' . $b['category'] . '.';
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x['options'] > 0 && $y['options'] === 0) {
                $diff[] = $x['name'] . ' comes in ' . $x['options'] . ' options; '
                        . $y['name'] . ' comes one way only.';
            }
            if ($x['sold_out'] && !$y['sold_out']) {
                $diff[] = $x['name'] . ' is sold out; ' . $y['name'] . ' is available.';
            }
            if ($x['ships_free'] && !$y['ships_free']) {
                $diff[] = $x['name'] . ' includes delivery.';
            }
        }
        if ($diff === []) {
            $diff[] = 'They are the same price, the same category, and both available — so it is '
                    . 'a matter of which one they prefer.';
        }

        return ['ok' => true, 'found' => true, 'a' => $a, 'b' => $b, 'differences' => $diff,
                'message' => $a['name'] . ' vs ' . $b['name'] . ': ' . implode(' ', $diff)];
    }

    /**
     * A link that opens the product with the option already chosen.
     *
     * The handoff, and the boundary. There is deliberately no method that adds to a basket: the
     * buyer has to see the price, the delivery and the total on a page they control before they
     * pay, and an assistant that filled a basket would have made a decision it cannot be held
     * to. This removes the retyping and leaves the deciding.
     */
    public static function handoff(string $product, string $option = '', string $option2 = ''): array
    {
        $a = self::availability($product, $option, $option2);
        if (!($a['found'] ?? false)) {
            return ['ok' => true, 'url' => '/shop', 'message' => $a['message']];
        }
        return ['ok' => true, 'url' => (string) ($a['url'] ?? '/shop'),
                'available' => (bool) ($a['available'] ?? false),
                'message' => (string) $a['message']];
    }

    // ══ 2. the catalogue, once ═══════════════════════════════════════════════

    /**
     * Every product on sale, flattened into what advice needs.
     *
     * `from_naira` is the cheapest way to own the thing — its base price, or its cheapest option
     * when the options are priced differently. A budget answered against the base price would
     * offer something whose only remaining size costs ₦1,500 more.
     *
     * @return list<array<string,mixed>>
     */
    private static function live(): array
    {
        try {
            $rows = DB::table('gates_products')->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('id')->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $base = (int) $r->price_naira;
            $vs   = ShopCatalogue::variants((int) $r->id, $base);

            $prices = [];
            foreach ($vs as $v) {
                if (!$v['sold_out']) $prices[] = (int) $v['price_naira'];
            }
            // The cheapest BUYABLE option; if none is buyable, the cheapest that exists, so the
            // number still describes the product rather than becoming zero.
            if ($prices === [] && $vs !== []) {
                $prices = array_map(static fn (array $v): int => (int) $v['price_naira'], $vs);
            }
            $from = $prices === [] ? $base : min($prices);

            $note = ShopCatalogue::stockNote(['variants' => $vs, 'stock' => $r->stock ?? null]);

            $out[] = [
                'id'       => (int) $r->id,
                'slug'     => (string) $r->slug,
                'name'     => (string) $r->name,
                'category' => (string) ($r->category ?? ''),
                'subtitle' => trim((string) ($r->subtitle ?? '')),
                'blurb'    => trim((string) ($r->description ?? '')),
                'details'  => trim((string) ($r->details ?? '')),
                'tag'      => trim((string) ($r->tag ?? '')),
                'from_naira'  => $from,
                'base_naira'  => $base,
                'varies'      => count(array_unique(array_map(
                    static fn (array $v): int => (int) $v['price_naira'], $vs))) > 1,
                'options'     => count($vs),
                'colours'     => self::colourNames($vs),
                'sold_out'    => $note === 'Sold out',
                'stock_note'  => $note,
                'featured'    => (int) ($r->is_featured ?? 0) === 1,
                'ships_free'  => (int) ($r->ships_free ?? 0) === 1,
                'sold_count'  => (int) ($r->sold_count ?? 0),
                'url'         => '/shop/' . (string) $r->slug,
            ];
        }
        return $out;
    }

    /** The distinct colour names, when the product has a colour axis. @return list<string> */
    private static function colourNames(array $vs): array
    {
        foreach (ShopCatalogue::axesFromVariants($vs) as $g) {
            if ($g['kind'] === 'swatch') {
                return array_values(array_map(
                    static fn (array $c): string => (string) $c['value'], $g['choices']));
            }
        }
        return [];
    }

    /**
     * Resolve a name a person typed to one product, or null.
     *
     * Exact slug, then exact name, then a containment match, then the best word overlap. In that
     * order because a buyer who types the name exactly must never be handed something else, and
     * because "tote" should find the Àdìrẹ Tote rather than nothing.
     *
     * @return array<string,mixed>|null
     */
    private static function findOne(string $q): ?array
    {
        $q = self::clean($q);
        if ($q === '') return null;
        $rows = self::live();
        if ($rows === []) return null;

        $lq = mb_strtolower($q);
        foreach ($rows as $p) {
            if (mb_strtolower((string) $p['slug']) === $lq) return $p;
        }
        foreach ($rows as $p) {
            if (mb_strtolower((string) $p['name']) === $lq) return $p;
        }
        foreach ($rows as $p) {
            $n = mb_strtolower((string) $p['name']);
            if (str_contains($n, $lq) || str_contains($lq, $n)) return $p;
        }

        // Word overlap, as a last resort. Requires at least one real word in common so that a
        // wrong guess comes back as "not found" rather than as a confident mismatch — being
        // told about the wrong product is worse than being told to look again.
        $words = self::words($lq);
        $best = null; $bestScore = 0;
        foreach ($rows as $p) {
            $hay = self::words(mb_strtolower((string) $p['name'] . ' ' . $p['subtitle']));
            $n = count(array_intersect($words, $hay));
            if ($n > $bestScore) { $bestScore = $n; $best = $p; }
        }
        return $bestScore > 0 ? $best : null;
    }

    /**
     * Find the variant matching the words a buyer used, in any order.
     *
     * @param list<array<string,mixed>> $vs
     * @param list<string> $want
     * @return array<string,mixed>|null
     */
    private static function matchVariant(array $vs, array $want): ?array
    {
        if ($want === []) return null;
        $low = array_map(static fn (string $w): string => mb_strtolower($w), $want);

        // Both answers given: require both to match, in either order.
        if (count($low) >= 2) {
            foreach ($vs as $v) {
                $a = mb_strtolower((string) $v['label']);
                $b = mb_strtolower((string) $v['label2']);
                if (($a === $low[0] && $b === $low[1]) || ($a === $low[1] && $b === $low[0])) {
                    return $v;
                }
            }
            return null;
        }

        // One answer. Only unambiguous when the product asks one question — otherwise "navy"
        // names a colour that exists in four sizes, and picking one of them for the buyer is
        // exactly how somebody receives the wrong size.
        $one = $low[0];
        $matches = [];
        foreach ($vs as $v) {
            if (mb_strtolower((string) $v['label']) === $one
                || mb_strtolower((string) $v['label2']) === $one) {
                $matches[] = $v;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * When the buyer has answered one question of two, which question is still open — and what
     * they can still choose from, given the answer they DID give.
     *
     * Narrowed by their answer on purpose: offering every size when they have said "Adire" would
     * include sizes Adire is sold out in, and an assistant that lists a size and then refuses it
     * has wasted the exchange it exists to shorten.
     *
     * @param list<array<string,mixed>> $vs
     * @param list<string> $want
     * @return array{axis:string, choices:list<array<string,mixed>>}|null
     */
    private static function openQuestionFor(array $vs, array $want): ?array
    {
        if (count($want) !== 1) return null;
        $given = mb_strtolower($want[0]);

        // Which axis did they answer? Only meaningful if the product asks two.
        $hasB = false;
        foreach ($vs as $v) { if ((string) $v['label2'] !== '') { $hasB = true; break; } }
        if (!$hasB) return null;

        foreach ([['label', 'label2', 'axis2'], ['label2', 'label', 'axis']] as [$mine, $other, $otherAxis]) {
            $rows = array_values(array_filter($vs,
                static fn (array $v): bool => mb_strtolower((string) $v[$mine]) === $given));
            if ($rows === []) continue;

            $choices = [];
            foreach ($rows as $v) {
                if ($v['sold_out']) continue;             // do not offer what cannot be bought
                $choices[] = ['label' => (string) $v[$other],
                              'price_naira' => (int) $v['price_naira'],
                              'left' => $v['stock']];
            }
            // The answer EXISTS but is sold out in every combination. Distinct from "not made",
            // and the distinction is the whole difference between a useful sentence and a wrong
            // one: telling somebody the shop does not make Indigo, when it does and has simply
            // run out, is how they stop looking for the thing they came for — and it is also the
            // moment to offer the back-in-stock list.
            if ($choices === []) {
                return ['axis' => trim((string) ($rows[0][$otherAxis] ?? '')) ?: 'options',
                        'choices' => [], 'exhausted' => true,
                        'given' => (string) $rows[0][$mine]];
            }

            return ['axis' => trim((string) ($rows[0][$otherAxis] ?? '')) ?: 'options',
                    'choices' => $choices, 'exhausted' => false,
                    'given' => (string) $rows[0][$mine]];
        }
        return null;
    }

    /**
     * What they could have instead — only things that can actually be bought.
     *
     * Offering a sold-out alternative to somebody who has just been told their choice is sold
     * out is worse than offering nothing.
     *
     * @param list<array<string,mixed>> $vs
     * @return list<array<string,mixed>>
     */
    private static function alternativesFor(array $vs, ?array $hit): array
    {
        $out = [];
        foreach ($vs as $v) {
            if ($v['sold_out']) continue;
            if ($hit !== null && (int) $v['id'] === (int) $hit['id']) continue;
            $out[] = ['label' => ShopCatalogue::describe($v),
                      'price_naira' => (int) $v['price_naira'],
                      'left' => $v['stock']];
            if (count($out) >= 6) break;
        }
        return $out;
    }

    /**
     * How well a product answers a described need, and the reason in words.
     *
     * The reason matters more than the number. An assistant that says "this one" is guessing at
     * taste; one that says "this one, because it is the only thing under ₦20,000 that comes in
     * four colours" has given the buyer something to disagree with.
     *
     * @return array{score:int, why:string}
     */
    private static function score(array $p, string $need, ?int $budget): array
    {
        $score = 0;
        $why   = [];

        $words = self::words(mb_strtolower($need));
        if ($words !== []) {
            $hay = self::words(mb_strtolower(implode(' ', [
                $p['name'], $p['subtitle'], $p['blurb'], $p['category'], $p['details'],
                implode(' ', $p['colours']),
            ])));
            $hits = array_values(array_intersect($words, $hay));
            if ($hits !== []) {
                $score += 4 * count($hits);
                $why[] = 'matches ' . implode(' and ', array_slice($hits, 0, 3));
            }
        }

        if (!$p['sold_out'])                 { $score += 3; }
        if ($p['featured'])                  { $score += 2; $why[] = 'one we put forward'; }
        if ($p['sold_count'] > 0)            { $score += min(3, (int) ($p['sold_count'] / 5)); }
        if ($p['options'] > 1)               { $score += 1; }
        if ($p['colours'] !== [])            { $why[] = count($p['colours']) . ' colours'; }
        if ($p['ships_free'])                { $score += 1; $why[] = 'delivery included'; }
        if ($budget !== null && $p['from_naira'] <= $budget) {
            $score += 2;
            $why[] = 'inside ₦' . number_format($budget);
        }

        return ['score' => $score, 'why' => $why === [] ? '' : ucfirst(implode(', ', $why)) . '.'];
    }

    /**
     * Words worth matching on: three letters or more, and not the ones every sentence contains.
     *
     * Without the stop list, "something for my mother" matches every product that happens to
     * contain the word "for" in its description — which is all of them, so the ranking becomes
     * noise and the advice becomes arbitrary.
     *
     * @return list<string>
     */
    private static function words(string $s): array
    {
        $stop = ['the','and','for','with','that','this','from','they','have','has','was','are',
                 'you','your','our','who','what','something','someone','anything','would','like',
                 'want','need','looking','look','get','buy','gift','present','can','any','one'];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [];
        $out = [];
        foreach ($parts as $w) {
            if (mb_strlen($w) < 3) continue;
            if (in_array($w, $stop, true)) continue;
            $out[$w] = true;
        }
        return array_keys($out);
    }

    /** Trim, collapse whitespace, bound. */
    private static function clean(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $s)), 0, self::MAX_NEED);
    }

    /**
     * Whether the shop has anything to advise about at all.
     *
     * Read by the assistant before it offers to help somebody buy something, because offering
     * shopping help on an empty catalogue is a promise the next sentence has to break.
     */
    public static function open(): bool
    {
        try {
            if (!DB::schema()->hasTable('gates_products')) return false;
            return DB::table('gates_products')->where('is_active', 1)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Categories with something on sale, for the assistant to offer as a starting point. */
    public static function departments(): array
    {
        try {
            return DB::table('gates_products')->where('is_active', 1)
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category')
                ->map(static fn ($c): string => (string) $c)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The delivery rate card as a sentence, so the assistant never invents one.
     *
     * Reads {@see ShopShipping}, which is what the checkout charges from. An assistant that
     * guessed at delivery would be contradicted by the basket, and the buyer would believe the
     * cheaper number.
     */
    public static function deliveryBrief(): array
    {
        $rates = ShopShipping::rates();
        $free  = ShopShipping::freeOver();

        if (!ShopShipping::isActive()) {
            return ['charged' => false, 'free_over' => $free,
                    'message' => 'Delivery is free everywhere at the moment — no charges are set.'];
        }
        $bits = [];
        foreach ($rates as $region => $naira) {
            $bits[] = $region . ' ₦' . number_format((int) $naira);
        }
        return ['charged' => true, 'rates' => $rates, 'free_over' => $free,
                'message' => 'Delivery: ' . implode(', ', $bits)
                    . ($free ? '. Free over ₦' . number_format($free) . '.' : '.')];
    }
}
