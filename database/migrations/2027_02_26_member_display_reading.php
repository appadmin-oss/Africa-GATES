<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * DISPLAY & READING, KEPT ON THE MEMBER AS WELL AS ON THE DEVICE.
 *
 * REFERENCE §7.5 / §10: the settings live in `localStorage["ag-a11y"]` "plus the member
 * profile when signed in". The device half has existed since the first chrome build; the
 * profile half never did (GAPS §3.7), so somebody who needs 150% text and high contrast
 * set it again on every phone, every laptop and after every cleared browser — the person
 * a settings screen exists for is exactly the person least able to find it twice.
 *
 * ── ONE NULLABLE COLUMN, NOT A PREFERENCES TABLE ────────────────────────────
 *
 * Eight small values read once per page for one member already identified by the session,
 * never filtered or sorted on. A table would be a join for nothing. NULL means "never
 * saved", which is a different answer from "saved as the defaults": the browser then keeps
 * what it already had and offers it up, rather than being reset to standard text by a
 * sign-in. {@see \AfricaGates\Services\DisplayReadingPrefs} is the one reader and writer.
 *
 * ── VARCHAR(255), AND WHY THAT IS NOT A GUESS ──────────────────────────────
 *
 * The writer normalises to the eight known keys with integer and boolean values only, so
 * the longest document it can produce is under 120 bytes. A free TEXT column would let a
 * future writer store anything; this one cannot hold a document the reader would choke on.
 * It is ASCII by construction, so the MySQL utf8mb4 byte arithmetic never bites.
 *
 * Added only where absent, so a database that already has it (a fresh build from the
 * corrected schema files) skips cleanly — there is no constraint here to repair later.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_users') && !DB::schema()->hasColumn('gates_users', 'display_json')) {
    DB::statement('ALTER TABLE gates_users ADD COLUMN display_json '
        . ($sqlite ? 'TEXT' : 'VARCHAR(255)') . ' NULL DEFAULT NULL');
    echo "  + gates_users.display_json added\n";
} else {
    echo "  = gates_users.display_json already present\n";
}

echo "member display & reading OK\n";
