<?php
/**
 * The nominee's organisation or school — carried forward, and backfilled.
 *
 * ── THE DATA WAS ALREADY BEING COLLECTED AND THEN THROWN AWAY ────────────────
 *
 * `gates_nominations.nominee_org` is asked for on the public nomination form and used
 * by the triage service. But `gates_nominees` had no such column, so the moment a
 * nomination was APPROVED into a nominee the organisation was dropped on the floor —
 * every downstream surface (the ballot, the leaderboard, the share flier) had no way
 * to show it, because by then it no longer existed on the row being rendered.
 *
 * That is why this is an additive column plus a BACKFILL rather than just a column. A
 * new field that only populates for future approvals would leave every existing
 * nominee blank, and on a live cycle mid-voting that is the whole field.
 *
 * ── THE BACKFILL IS DELIBERATELY CONSERVATIVE ────────────────────────────────
 *
 * Matching is by (category, exact name) against approved nominations, and only where
 * that pair identifies EXACTLY ONE nomination carrying a non-empty organisation. Two
 * nominations for the same name in one category, or a name that also exists elsewhere,
 * are skipped rather than guessed at: attributing the wrong school to a child on a
 * public ballot is worse in every direction than leaving the line off.
 *
 * Idempotent — it only ever writes where the nominee's organisation is still empty, so
 * re-running cannot overwrite an admin's manual correction.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasColumn('gates_nominees', 'organisation')) {
    DB::statement('ALTER TABLE gates_nominees ADD COLUMN organisation '
        . ($sqlite ? 'TEXT' : 'VARCHAR(200)') . ' NULL DEFAULT NULL');
    echo "  + gates_nominees.organisation added\n";
} else {
    echo "  = gates_nominees.organisation already present\n";
}

// ── Backfill from the nomination each nominee came from ──────────────────────
if (DB::schema()->hasColumn('gates_nominations', 'nominee_org')) {
    $filled = 0; $ambiguous = 0;

    $blank = DB::table('gates_nominees')
        ->where(function ($w) { $w->whereNull('organisation')->orWhere('organisation', ''); })
        ->get(['id', 'category_id', 'name']);

    foreach ($blank as $n) {
        $matches = DB::table('gates_nominations')
            ->where('category_id', $n->category_id)
            ->where('status', 'approved')
            ->whereNotNull('nominee_org')
            ->where('nominee_org', '!=', '')
            // Case-insensitive on the name only; the category already narrows it hard.
            ->whereRaw('LOWER(nominee_name) = ?', [mb_strtolower(trim((string) $n->name))])
            ->limit(2)
            ->pluck('nominee_org');

        if ($matches->count() !== 1) {
            if ($matches->count() > 1) $ambiguous++;
            continue;   // zero matches, or ambiguous — leave blank rather than guess
        }
        DB::table('gates_nominees')->where('id', $n->id)
            ->update(['organisation' => mb_substr(trim((string) $matches->first()), 0, 200)]);
        $filled++;
    }

    echo "  backfilled {$filled} nominee organisation(s)"
       . ($ambiguous > 0 ? "; skipped {$ambiguous} ambiguous (same name twice in a category)" : '')
       . "\n";
} else {
    echo "  · gates_nominations.nominee_org absent — nothing to backfill from\n";
}

echo "nominee organisation OK\n";
