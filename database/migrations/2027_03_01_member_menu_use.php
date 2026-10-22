<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * THE MENU'S MOST-USED TILES, KEPT ON THE MEMBER (owner, 4 Oct 2026 — GAPS §8e).
 *
 * The four squares in the phone Menu rank the destinations a member opens most, by frequency
 * and recency (docs/handoff/MENU-SHEET.md). Kept on the member so the ranking follows them
 * between devices; a guest's stays in their own browser, and only with Preferences allowed.
 *
 * ── ONE NULLABLE COLUMN, NOT A TABLE ────────────────────────────────────────
 *
 * The same reasoning as `display_json` beside it: one small document read once per page for
 * a member the session already identified, never filtered, sorted or joined on. A table
 * would be a join and an index for nothing — and an index is exactly the statement this
 * codebase has shipped broken three times (CLAUDE.md, `CREATE INDEX IF NOT EXISTS`). There
 * is no index here at all.
 *
 * ── VARCHAR(1024), AND WHY THAT IS NOT A GUESS ─────────────────────────────
 *
 * `MenuShortcuts::normalise()` keeps only the fifteen catalogue keys, each a rounded score
 * and an epoch second; the longest document it can produce is under 700 ASCII bytes, so the
 * utf8mb4 byte arithmetic never applies and nothing can be truncated. An epoch integer and
 * not a DATETIME, so no `T`-separated or zoned stamp can reach a strict-mode column.
 *
 * Added only where absent, so a database built fresh from the corrected schema files skips
 * cleanly; there is no constraint here for a later repair to correct.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_users') && !DB::schema()->hasColumn('gates_users', 'menu_use_json')) {
    DB::statement('ALTER TABLE gates_users ADD COLUMN menu_use_json '
        . ($sqlite ? 'TEXT' : 'VARCHAR(1024)') . ' NULL DEFAULT NULL');
    echo "  + gates_users.menu_use_json added\n";
} else {
    echo "  = gates_users.menu_use_json already present\n";
}

echo "member menu use OK\n";
