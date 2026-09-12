<?php
/**
 * "Tell me when it's back" — the shop's answer to a sold-out page.
 *
 * ── WHY THIS IS THE SAME GAP THE EVENT WAITLIST JUST CLOSED ──────────────────
 *
 * A sold-out ticket used to say "fully booked" and stop there, which throws away the most
 * motivated person in the room. A sold-out product does exactly the same thing, and worse:
 * stock comes back far more often than a seat does. Somebody who wanted a Large enough to
 * arrive on the page the week it ran out is the easiest sale the shop will ever make, and
 * until now the page's entire answer to them was a greyed-out button.
 *
 * ── PER VARIANT, NOT PER PRODUCT ─────────────────────────────────────────────
 *
 * Somebody who wants L does not want to be told M is back. `variant_id` is 0 rather than NULL
 * for "the product itself" — deliberately, because MySQL treats NULLs as DISTINCT in a unique
 * index, so `(1, NULL, ada@x)` could be inserted twice and the same person would be emailed
 * twice about the same restock. 0 makes the unique index mean what it says on both engines.
 *
 * ── AND THE UNSUBSCRIBE IS A TOKEN, NOT AN ACCOUNT ───────────────────────────
 *
 * Same doctrine as an event ticket and the nominee questionnaire: the person who asked has no
 * account, and requiring one to STOP receiving mail would be the worst possible place to put a
 * registration wall. A 32-hex token this platform generated, in the email, one click.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_stock_alerts')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_stock_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            variant_id INTEGER NOT NULL DEFAULT 0,
            email TEXT NOT NULL,
            name TEXT NULL,
            token TEXT NOT NULL,
            ip_hash TEXT NULL,
            created_at TEXT NULL,
            notified_at TEXT NULL,
            cancelled_at TEXT NULL
        )" : "
        CREATE TABLE gates_stock_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            -- 0 = the product itself. NOT NULL on purpose: see the note above about MySQL
            -- treating NULLs as distinct in a unique index.
            variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(160) NULL,
            -- The whole credential for the unsubscribe link. Unique so a link identifies one
            -- request and cannot be walked to somebody else's.
            token CHAR(32) NOT NULL,
            -- Hashed, never the address itself. Enough to rate-limit a script filling the
            -- table, and useless for identifying anybody afterwards.
            ip_hash CHAR(64) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            -- Stamped when the mail went out. A row keeps its history rather than being
            -- deleted, so a restock cannot notify the same person twice and an operator can
            -- see that demand existed even after it was met.
            notified_at TIMESTAMP NULL DEFAULT NULL,
            cancelled_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_alert_who (product_id, variant_id, email),
            UNIQUE KEY uq_alert_token (token),
            KEY idx_alert_pending (product_id, variant_id, notified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_stock_alerts created\n";
} else {
    echo "  = gates_stock_alerts already present\n";
}

if ($sqlite) {
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_alert_who ON gates_stock_alerts (product_id, variant_id, email)");
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_alert_token ON gates_stock_alerts (token)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_alert_pending ON gates_stock_alerts (product_id, variant_id, notified_at)");
}

echo "stock alerts OK\n";
