<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * REPAIR: `gates_newsletter_issues.period_key` was VARCHAR(20), and a holiday issue's key
 * is longer — `h-independence-day-2026` is 23 characters.
 *
 * Strict-mode MySQL refuses that insert with "Data too long", the newsletter tick's catch
 * swallows it, and the greeting is simply never composed: the Independence Day issue of
 * 1 October 2026 did not go out on production while every test passed, because SQLite
 * stores the column as TEXT and has no width to exceed.
 *
 * A corrected CREATE would fix only fresh databases, so this MODIFYs the existing column
 * to `Newsletter::PERIOD_KEY_MAX` — idempotent, and a no-op on SQLite. The UNIQUE index on
 * the column survives a MODIFY.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$sqlite && DB::schema()->hasTable('gates_newsletter_issues')) {
    DB::statement('ALTER TABLE gates_newsletter_issues MODIFY period_key VARCHAR('
        . \AfricaGates\Services\Newsletter\Newsletter::PERIOD_KEY_MAX . ') NOT NULL');
    echo "  ~ gates_newsletter_issues.period_key widened to VARCHAR("
        . \AfricaGates\Services\Newsletter\Newsletter::PERIOD_KEY_MAX . ")\n";
}
