<?php
/**
 * Keep the whole nomination story, not the first 200 characters of it.
 *
 * ── THE BUG ──────────────────────────────────────────────────────────────────
 *
 * A nominator writes up to 3,000 characters explaining why this person deserves
 * recognition. The form encourages it, the AI "polish my story" helper works on it,
 * and it is stored in full in `gates_nominations.reason`.
 *
 * Then approval did this:
 *
 *     'tagline' => mb_substr((string) $nom->reason, 0, 200),
 *
 * — and `tagline` is what every public surface reads. So the ballot's "Why X is
 * nominated" section has always shown the first 200 characters of somebody's case
 * for a person, cut mid-sentence, with no way to read the rest. Not because the rest
 * was hidden: because the nominee row never had it. The full text sat unread in the
 * nominations table, which nothing public joins to.
 *
 * ── WHY A NEW COLUMN RATHER THAN WIDENING tagline ────────────────────────────
 *
 * `tagline` has a real job: a one-line summary under a name on a leaderboard row, a
 * card, a flier, an activity-feed entry. Those places genuinely need something short,
 * and widening the column would let a 3,000-character essay into all of them.
 *
 * So they are separated by purpose. `story` is the nominator's full text, shown on the
 * ballot. `tagline` stays the short line, and approval now derives it from the story's
 * FIRST SENTENCE rather than cutting at a byte count — a summary that ends where a
 * thought ends instead of mid-word.
 *
 * ── THE BACKFILL, AND WHY IT MATCHES ON NAME ─────────────────────────────────
 *
 * Every nominee approved before this shipped has a truncated tagline and no story.
 * The text they need is in `gates_nominations.reason` — but nothing links the two
 * tables: approval creates a nominee row and never records which nomination it came
 * from. (That is worth fixing separately; it is not fixable retroactively.)
 *
 * So the backfill matches an approved nomination to a nominee by NORMALISED NAME
 * within the SAME CATEGORY, and only where exactly one candidate matches. An
 * ambiguous match is skipped rather than guessed: attaching one person's nomination
 * story to another person's ballot is far worse than leaving a short tagline in place.
 *
 * Idempotent — it only fills a story that is still empty, so re-running cannot
 * overwrite text an administrator has since edited by hand.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasColumn('gates_nominees', 'story')) {
    DB::statement('ALTER TABLE gates_nominees ADD COLUMN story ' . ($sqlite ? 'TEXT' : 'TEXT NULL') . ' DEFAULT NULL');
    echo "  + gates_nominees.story added\n";
} else {
    echo "  = gates_nominees.story already present\n";
}

// ── backfill ────────────────────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_nominations')) {
    echo "  = no nominations table — nothing to backfill\n";
    return;
}

/** Same folding the rest of the platform uses, so "Ọlásùnkànmí" matches itself. */
$key = static function (string $s): string {
    return \AfricaGates\Support\Slug::make($s, 120);
};

$filled = 0; $ambiguous = 0; $unmatched = 0;

try {
    // Only nominees still missing a story, and only where the nomination actually
    // carried more text than the tagline kept — otherwise there is nothing to gain.
    $nominees = DB::table('gates_nominees')
        ->whereNull('story')
        ->select('id', 'name', 'category_id', 'tagline')
        ->get();

    foreach ($nominees as $nominee) {
        $want = $key((string) $nominee->name);
        if ($want === '') { $unmatched++; continue; }

        $candidates = DB::table('gates_nominations')
            ->where('status', 'approved')
            ->where('category_id', $nominee->category_id)
            ->whereNotNull('reason')
            ->select('id', 'nominee_name', 'reason')
            ->get()
            ->filter(fn($r) => $key((string) $r->nominee_name) === $want)
            ->values();

        if ($candidates->count() === 0) { $unmatched++; continue; }
        // More than one nomination for the same name in the same category: two people
        // nominated them, or one person twice. Either way the stories may differ, and
        // publishing the wrong one under somebody's name is not a risk worth taking
        // for a convenience backfill.
        if ($candidates->count() > 1) { $ambiguous++; continue; }

        $reason = trim((string) $candidates[0]->reason);
        if ($reason === '') { $unmatched++; continue; }

        DB::table('gates_nominees')->where('id', $nominee->id)->update(['story' => $reason]);
        $filled++;
    }
} catch (\Throwable $e) {
    echo '  ! backfill skipped: ' . $e->getMessage() . "\n";
    return;
}

echo "  = stories restored: {$filled} filled"
   . ($ambiguous ? ", {$ambiguous} skipped (more than one nomination for that name in that category)" : '')
   . ($unmatched ? ", {$unmatched} with no matching nomination" : '') . "\n";
echo "nominee story OK\n";
