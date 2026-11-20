<?php
/**
 * A shop that can sell a t-shirt: variants, real stock, a gallery, shipping and codes.
 *
 * ── WHY VARIANTS ARE THE HEADLINE ────────────────────────────────────────────
 *
 * `gates_products` has one price and one stock number. The shop's first and largest category
 * is Apparel. You cannot sell apparel without a size, so today a buyer picks a t-shirt, pays,
 * and then somebody has to email them to ask what size they are — which means the order is not
 * actually complete when the money arrives, and a "sold out" number is meaningless because it
 * counts shirts rather than shirts-in-a-size.
 *
 * A variant is a row: its own SKU, its own stock, and a price DELTA rather than a price. The
 * delta matters — an XXL costs ₦1,500 more, and when the base price changes for a sale the
 * delta still holds, whereas eight absolute prices would silently keep the old ones.
 *
 * ── AND WHY STOCK NEEDED MORE THAN A COLUMN ──────────────────────────────────
 *
 * `stock` already existed and nothing checked it. A buyer could add a sold-out item, pay for
 * it, and the confirmation would floor the number at zero and say nothing — an oversell with
 * no record that it happened. Stock lives on the variant when there are variants, and the
 * order row now carries `stock_short` so the one case that can still slip through (two people
 * paying for the last item inside the same payment window) surfaces on the orders screen
 * instead of becoming a support ticket three days later.
 *
 * ── THE MONEY COLUMNS, AND ONE DELIBERATE NAMING COMPROMISE ──────────────────
 *
 * `gates_orders.subtotal_naira` is what is CHARGED, and it always has been: it is what goes to
 * the gateway and what confirmation checks the verified amount against. Renaming it would mean
 * touching the verification comparison, and getting that wrong makes every order in flight
 * unconfirmable. So it keeps its name and its meaning, and the breakdown arrives alongside it
 * — `goods_naira`, `shipping_naira`, `discount_naira` — which is what a receipt needs and what
 * the finance screens can total. subtotal = goods + shipping − discount, always.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1 · variants ─────────────────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_product_variants')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            sku TEXT NULL,
            label TEXT NOT NULL,
            axis TEXT NULL,
            price_delta_naira INTEGER NOT NULL DEFAULT 0,
            stock INTEGER NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_product_variants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            -- The code on the label in a stockroom. Optional, because a small operation
            -- numbers nothing and requiring one would stop them using variants at all.
            sku VARCHAR(60) NULL,
            -- What the buyer picks: 'M', 'XL', 'Indigo'. Free text rather than a lookup
            -- table, because an organisation selling both shirts and prints needs both
            -- 'Large' and 'A2' and a fixed size list would fit neither.
            label VARCHAR(80) NOT NULL,
            -- Which question this answers — 'Size', 'Colour'. One axis per product for now;
            -- naming it is what lets the page say 'Size' above the buttons instead of
            -- 'Options', and leaves room for a second axis without a schema change.
            axis VARCHAR(40) NULL,
            -- A DELTA, not a price. An XXL costs ₦1,500 more than the base, and when the
            -- base changes for a sale the delta still holds — eight absolute prices would
            -- silently keep the old ones.
            price_delta_naira INT NOT NULL DEFAULT 0,
            -- NULL = untracked, exactly as gates_products.stock means. 0 = sold out, which
            -- is a different statement and must not be flattened into the same value.
            stock INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_variant_product (product_id, is_active, sort_order),
            KEY idx_variant_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_product_variants created\n";
} else {
    echo "  = gates_product_variants already present\n";
}

if ($sqlite) {
    DB::statement("CREATE INDEX IF NOT EXISTS idx_variant_product ON gates_product_variants (product_id, is_active, sort_order)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_variant_sku ON gates_product_variants (sku)");
}

// ── 2 · a gallery, not one cover ─────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_product_images')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_product_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            path TEXT NOT NULL,
            alt TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL
        )" : "
        CREATE TABLE gates_product_images (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            path VARCHAR(400) NOT NULL,
            -- Its own alt text. A gallery where every image is described as the product name
            -- tells a screen-reader user nothing about which one they are on.
            alt VARCHAR(300) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pimg_product (product_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_product_images created\n";
} else {
    echo "  = gates_product_images already present\n";
}

if ($sqlite) {
    DB::statement("CREATE INDEX IF NOT EXISTS idx_pimg_product ON gates_product_images (product_id, sort_order)");
}

// ── 3 · shop discount codes ──────────────────────────────────────────────────
//
// Its own table rather than a `scope` column on gates_event_codes: a shop code targets
// products and categories, an event code targets ticket tiers, and one nullable column per
// target type on a shared table is how a table stops describing anything. What the two DO
// share — the limits and the arithmetic — is shared in code, in AfricaGates\Support\PromoCode.
if (!DB::schema()->hasTable('gates_shop_codes')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_shop_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            label TEXT NULL,
            kind TEXT NOT NULL DEFAULT 'percent',
            amount INTEGER NOT NULL DEFAULT 0,
            product_ids TEXT NULL,
            categories TEXT NULL,
            min_spend_naira INTEGER NULL,
            free_shipping INTEGER NOT NULL DEFAULT 0,
            max_uses INTEGER NULL,
            max_per_email INTEGER NOT NULL DEFAULT 1,
            used_count INTEGER NOT NULL DEFAULT 0,
            starts_at TEXT NULL,
            ends_at TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_shop_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL,
            label VARCHAR(120) NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'percent',
            amount INT UNSIGNED NOT NULL DEFAULT 0,
            -- JSON lists, or NULL for everything. Two ways of naming a target because
            -- organisers think in both: '20% off the tote' and '20% off Apparel'.
            product_ids TEXT NULL,
            categories TEXT NULL,
            -- 'Spend ₦20,000 and get 10% off' is the most common promotion there is, and
            -- without this it cannot be expressed at all.
            min_spend_naira INT UNSIGNED NULL,
            -- Free delivery is a discount buyers understand better than a percentage, and it
            -- costs the seller a known amount rather than a share of revenue.
            free_shipping TINYINT(1) NOT NULL DEFAULT 0,
            max_uses INT UNSIGNED NULL,
            max_per_email SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            starts_at TIMESTAMP NULL DEFAULT NULL,
            ends_at TIMESTAMP NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_shop_code (code),
            KEY idx_shop_code_active (code, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_shop_codes created\n";
} else {
    echo "  = gates_shop_codes already present\n";
}

if ($sqlite) {
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_shop_code ON gates_shop_codes (code)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_shop_code_active ON gates_shop_codes (code, is_active)");
}

// ── 4 · what a product can say about itself ──────────────────────────────────
if (DB::schema()->hasTable('gates_products')) {
    foreach ([
        // One line under the name. A product page whose only prose is a paragraph makes the
        // buyer read to find out what the thing is.
        'subtitle'     => $sqlite ? 'TEXT' : 'VARCHAR(200) NULL',
        // Materials, sizing, washing. The three questions a support inbox answers about
        // apparel, every time, forever.
        'details'      => $sqlite ? 'TEXT' : 'TEXT NULL',
        // Which axis the variants answer, cached on the product so the browse grid can say
        // "4 sizes" without reading every variant row.
        'variant_axis' => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
        // Delivery included in the price. Some keepsakes are posted in an envelope and
        // charging ₦3,500 to send one is how a ₦4,000 order gets abandoned.
        'ships_free'   => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
        // Front of the shop. Distinct from `tag`, which is a badge — a product can be a
        // bestseller without being what you want at the top of the page this month.
        'is_featured'  => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
        // Counted for "most popular" sorting, from paid orders only. Denormalised because
        // the alternative is scanning every order's items_json on every browse request.
        'sold_count'   => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_products', $col)) {
            DB::statement("ALTER TABLE gates_products ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_products.{$col} added\n";
        } else {
            echo "  = gates_products.{$col} already present\n";
        }
    }
}

// ── 5 · what an order remembers ──────────────────────────────────────────────
if (DB::schema()->hasTable('gates_orders')) {
    foreach ([
        // The breakdown behind `subtotal_naira`, which is and remains the CHARGED amount.
        // See the note at the top of this file: renaming it would mean touching the
        // amount-parity check that decides whether an order can be confirmed at all.
        'goods_naira'    => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        'shipping_naira' => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        'discount_naira' => $sqlite ? 'INTEGER' : 'INT UNSIGNED NULL',
        'discount_code'  => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
        // Where the order is in the real world, which is a different question from whether
        // it was paid. An order screen that only knows 'paid' cannot answer "has it shipped".
        'fulfilment'     => $sqlite ? 'TEXT' : "VARCHAR(20) NULL",
        'tracking_note'  => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
        'fulfilled_at'   => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        // Set when the paid transition found less stock than the order needs. The oversell
        // window is small — two buyers inside one payment window — but it is not zero, and
        // silently flooring the number at 0 is how it becomes a support ticket on Friday.
        'stock_short'    => $sqlite ? 'INTEGER' : 'TINYINT(1) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_orders', $col)) {
            DB::statement("ALTER TABLE gates_orders ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_orders.{$col} added\n";
        } else {
            echo "  = gates_orders.{$col} already present\n";
        }
    }

    // Existing paid orders had no shipping and no discount, so their goods total IS their
    // charged total. Backfilled rather than left NULL, or every historical order would read
    // as "₦0 of goods" on the new receipt.
    try {
        DB::table('gates_orders')->whereNull('goods_naira')
            ->update(['goods_naira' => DB::raw('subtotal_naira'),
                      'shipping_naira' => 0, 'discount_naira' => 0]);
        echo "  ~ existing orders backfilled with their goods total\n";
    } catch (\Throwable $e) {
        echo "  ! could not backfill order totals: " . $e->getMessage() . "\n";
    }

    if ($sqlite) {
        DB::statement("CREATE INDEX IF NOT EXISTS idx_order_fulfil ON gates_orders (status, fulfilment)");
    } else {
        try { DB::statement("CREATE INDEX idx_order_fulfil ON gates_orders (status, fulfilment)"); }
        catch (\Throwable) {}
    }
}

echo "shop variants OK\n";
