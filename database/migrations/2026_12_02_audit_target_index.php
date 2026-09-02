<?php
/**
 * The index the audit log needed the moment anything could read it.
 *
 * ── WHY THIS ONLY MATTERS NOW ────────────────────────────────────────────────
 *
 * `gates_audit_log` shipped with three indexes — `admin_id`, `action`, `created_at` —
 * and that was the right set for the only reader it had: the dashboard's `ORDER BY id
 * DESC LIMIT 12`, which touches almost nothing.
 *
 * `/admin/audit` asks a different question. Its most important one, and the reason the
 * screen exists, is "everything that ever happened to this record":
 *
 *     WHERE target_type IN ('site_event','event') AND target_id = ?
 *
 * `target_type` and `target_id` have never been indexed, so that is a FULL TABLE SCAN
 * on a table that grows by every admin action forever. The same columns are grouped on
 * every render of the screen to build the "acted on" filter list.
 *
 * On a young deployment nobody notices. The point at which somebody actually reaches for
 * this — a challenged result, a leaked credential, a year of history to go through — is
 * exactly the point at which the table is large and the operator is in a hurry.
 *
 * ── COLUMN ORDER ─────────────────────────────────────────────────────────────
 *
 * `(target_type, target_id)`, in that order, because a composite index serves any
 * LEFTMOST prefix of its columns: this one answers the per-record lookup AND the
 * type-only filter AND the GROUP BY that builds the filter list. The reverse order would
 * serve only the first, and `target_id` alone is meaningless — id 12 is an event, a
 * nominee and a judge at the same time.
 *
 * Matches the shape already used for `idx_uploads_attached (attached_to_type,
 * attached_to_id)`, which is the same polymorphic pair under a different name.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();
$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!$schema->hasTable('gates_audit_log')) {
    echo "gates_audit_log absent — skipped\n";
    echo "audit target index OK\n";
    return;
}

foreach (['target_type', 'target_id'] as $col) {
    if (!$schema->hasColumn('gates_audit_log', $col)) {
        echo "  ! gates_audit_log.{$col} absent — skipped\n";
        echo "audit target index OK\n";
        return;
    }
}

try {
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_audit_target
                       ON gates_audit_log (target_type, target_id)');
        echo "  + idx_audit_target ensured\n";
    } else {
        // `CREATE INDEX IF NOT EXISTS` is not MySQL syntax; ask first.
        $exists = DB::select('SHOW INDEX FROM gates_audit_log WHERE Key_name = ?', ['idx_audit_target']);
        if ($exists) {
            echo "  = idx_audit_target already present\n";
        } else {
            DB::statement('CREATE INDEX idx_audit_target ON gates_audit_log (target_type, target_id)');
            echo "  + idx_audit_target created — the per-record trail no longer full-scans\n";
        }
    }
} catch (\Throwable $e) {
    echo "  ! idx_audit_target failed: " . $e->getMessage() . "\n";
}

echo "audit target index OK\n";
