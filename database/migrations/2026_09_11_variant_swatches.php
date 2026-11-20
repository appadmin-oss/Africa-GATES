<?php
/**
 * Colour, as a colour — and a second question, so a shirt can have both a colour and a size.
 *
 * ── WHY A SWATCH IS NOT DECORATION ───────────────────────────────────────────
 *
 * Options were one axis of free text, so a colour was the WORD "Navy" in a button. Nobody
 * buys clothing that way. "Navy", "Indigo" and "Midnight" are three words for a decision
 * somebody makes with their eyes in a quarter of a second, and a shop that makes them read
 * instead is a shop where they pick wrong or do not pick at all. So a variant can carry:
 *
 *   swatch        one hex, or TWO separated by a slash for a two-tone item. Two rather than
 *                 one because striped, panelled and trimmed goods are common and a single
 *                 flat square misrepresents them — and misrepresenting a colour is a return.
 *   swatch_image  a photograph of THIS colour. When set, choosing the colour switches the
 *                 gallery to it, which is the only honest way to show a fabric: a hex square
 *                 cannot tell somebody what a wax print looks like.
 *
 * The text label never goes away. It is what a screen reader announces, what anybody who
 * cannot distinguish those two greens relies on, and what the packing list and the order
 * email say — a receipt reading "you bought the ■" is not a receipt.
 *
 * ── AND WHY A SECOND AXIS, RATHER THAN "M · NAVY" IN ONE LABEL ───────────────
 *
 * You can already express a combination by naming a variant "M · Navy". It falls apart the
 * moment you want it shown properly:
 *
 *   • Twelve buttons instead of three colours and four sizes, and the buyer has to find
 *     their pair in a list rather than answer two questions.
 *   • A colour cannot be a swatch, because the label is not a colour any more.
 *   • "Navy is sold out in M" is unsayable: every combination is an unrelated string, so the
 *     page cannot grey out a size once a colour is chosen.
 *
 * So the ROW is still one sellable combination — which is right, because stock, price and SKU
 * all belong to the combination and not to the colour — and it now carries the two answers
 * separately. `label` is the first axis, `label2` the second. Nothing changes for a product
 * with one axis: `label2` is NULL and the page asks one question, exactly as before.
 *
 * ── EVERY COLUMN IS NULLABLE AND EVERY DEFAULT IS THE OLD BEHAVIOUR ──────────
 *
 * A product nobody edits after this migration must look and sell identically. There is no
 * backfill, because there is nothing to backfill TO: a swatch cannot be guessed from the word
 * "Navy" without inventing a colour, and inventing one is worse than showing the word.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_product_variants')) {
    echo "  ! gates_product_variants missing — run 2026_09_08_shop_variants first\n";
    echo "variant swatches OK\n";
    return;
}

foreach ([
    // '#1b2a4a' or '#1b2a4a/#e8d8b7'. VALIDATED IN PHP on write and again on read, because
    // it reaches a `style` attribute — the same rule as the ticket's accent colour.
    'swatch'       => $sqlite ? 'TEXT' : 'VARCHAR(20) NULL',
    'swatch_image' => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
    // The second question. On the variant AND on the product (below) for the same reason the
    // first axis is: the product names the question, the variants answer it.
    'axis2'        => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
    'label2'       => $sqlite ? 'TEXT' : 'VARCHAR(80) NULL',
] as $col => $type) {
    if (!DB::schema()->hasColumn('gates_product_variants', $col)) {
        DB::statement("ALTER TABLE gates_product_variants ADD COLUMN {$col} {$type} DEFAULT NULL");
        echo "  + gates_product_variants.{$col} added\n";
    } else {
        echo "  = gates_product_variants.{$col} already present\n";
    }
}

if (DB::schema()->hasTable('gates_products')) {
    foreach ([
        'variant_axis2' => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_products', $col)) {
            DB::statement("ALTER TABLE gates_products ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_products.{$col} added\n";
        } else {
            echo "  = gates_products.{$col} already present\n";
        }
    }
}

echo "variant swatches OK\n";
