<?php
/**
 * HOW a profile's CPI was arrived at, kept beside the number itself.
 *
 * ── THE PAGE WAS DESCRIBING A FORMULA THAT HAD NOT RUN ──────────────────────
 *
 * `gates_profiles.cpi_score` is printed on the public registry, the registry index and a
 * person's own profile page, under the heading "Cultural Power Index", beside two bars
 * reading "Community vote 45%" and "Independent jury panel 55%", above a sentence saying
 * the score "recomputes every 6 hours from organic verified votes (45%) and the
 * independent judge panel (55%)".
 *
 * For most of the registry none of that produced the number. A profile with no judged
 * nomination falls to `CpiService::baselineScore()` — 50% verification tier, 30% profile
 * completeness, 20% page views — and gets a tier off the same ladder, so filling in a
 * profile and collecting views was published as GOLD with a gold star, under a paragraph
 * crediting a jury panel that had never seen them.
 *
 * That is this codebase's most expensive shape (index §19): not a column nobody reads, but
 * a column a SCREEN has already made a promise about. The number was never wrong for what
 * it is; the page was wrong about what it is.
 *
 * ── WHY A STORED COLUMN AND NOT A QUESTION ASKED AT RENDER ──────────────────
 *
 * The basis is decided inside the recompute, which is the only place that knows which
 * nominee scores cleared quorum and which were dropped as provisional. Re-deriving it in
 * the profile controller would be a second reader of the one fact the number depends on —
 * and the registry index would have to ask it for every row on the page. One writer, one
 * column, travelling with the score it describes.
 *
 * ── THREE VALUES, BECAUSE "NOT JUDGED" IS TWO DIFFERENT SITUATIONS ──────────
 *
 *   judged   — at least one nomination reached quorum. The 45/55 sentence is true.
 *   pending  — nominated, no panel has finished. A profile-strength figure MEANWHILE,
 *              and the distinction matters to the person: "we have not judged you yet"
 *              is not "you were not nominated".
 *   baseline — no nomination at all. Verification, completeness and reach.
 *
 * VARCHAR, not ENUM, deliberately. A value outside an ENUM is `Data truncated` on MySQL
 * and silently accepted on SQLite, and this project has shipped that divergence twice —
 * see the note on `gates_event_invites.audience` in CLAUDE.md. Nothing here is worth that
 * risk for three short strings.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_profiles')) {
    echo "  = gates_profiles absent; nothing to do\n";
    return;
}

if (DB::schema()->hasColumn('gates_profiles', 'cpi_basis')) {
    echo "  = gates_profiles.cpi_basis present\n";
    return;
}

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// 'baseline' as the default because it is the honest answer for every row that exists
// today: until the next recompute writes a basis, nothing has established that any of
// these scores came from a panel. The recompute runs every six hours.
DB::statement($sqlite
    ? "ALTER TABLE gates_profiles ADD COLUMN cpi_basis TEXT NOT NULL DEFAULT 'baseline'"
    : "ALTER TABLE gates_profiles ADD COLUMN cpi_basis VARCHAR(16) NOT NULL DEFAULT 'baseline'");

echo "  + gates_profiles.cpi_basis added\n";
