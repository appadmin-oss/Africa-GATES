<?php
/**
 * Demote every "announced standing" that was sealed without anything being announced.
 *
 * ── THE BUG ──────────────────────────────────────────────────────────────────
 *
 * `CycleMaterialiser` advances a cycle on its declared dates, unattended. Entering
 * `results` fires two side effects: it promotes the winners, and it used to seal the
 * standing as announced ({@see \AfricaGates\Services\SnapshotService::captureRelease()}).
 *
 * It also has a staleness rule, and that rule is right: a boundary passed more than
 * `ANNOUNCE_GRACE_DAYS` ago means the state is being corrected long after the fact, so
 * every outbound notification is withheld — "a months-old result must not email
 * congratulations now". The two decisions never referred to each other. So on that path
 * the platform deliberately told nobody, and sealed the standing as the announcement
 * anyway.
 *
 * The consequence is not visible on any screen, which is what let it run. A programme
 * whose results run late passes `results_date` with panels still unfinished and votes
 * still reconciling. The sweep advances it, suppresses the announcements, and seals
 * whatever the arithmetic gave at that minute. {@see \AfricaGates\Services\PublicResults::category()}
 * then lays that seal over every subsequent view, so the published figures stop moving
 * while the scoring behind them carries on: a judge completing a scorecard changes
 * nothing anybody can see. The symptom reported is "the score is not changing", which
 * names the scorer — the one part of it that was working correctly.
 *
 * The seal itself is fixed at the source (that call is now gated on the announcement
 * actually going out). This repairs the rows it already wrote.
 *
 * ── HOW AN UNANNOUNCED SEAL IS IDENTIFIED, WITHOUT GUESSING ──────────────────
 *
 * `gates_cycle_transitions` is an idempotency ledger with UNIQUE (cycle_id, to_status),
 * so there is exactly one row per cycle per phase, and it records `notify` — whether the
 * announcements fired. A `results` row with `notify = 0` is the platform's own contemporaneous
 * record that it announced nothing. That is evidence, not a heuristic: no timestamps are
 * compared and no standing is reconstructed. A cycle with no ledger row is left alone,
 * because absence of a record is not a record of absence.
 *
 * ── WHY DEMOTED AND NOT DELETED ──────────────────────────────────────────────
 *
 * `gates_vote_snapshots` is a hash chain. Deleting a row breaks every link after it, and
 * {@see \AfricaGates\Services\SnapshotService::verify()} would report tampering for the
 * life of the archive. The capture is a true record of what the arithmetic gave that
 * day; only its LABEL was wrong. `capture_kind` is stored beside the hash and not inside
 * it (the payload is `cycleId|nomineeId|votes|cpi|at`), so relabelling leaves every link
 * verifiable.
 *
 * `unannounced` rather than folding these back into `routine`: a routine row is a capture
 * nobody claimed anything about, and these were claimed. Keeping the distinction means
 * this is auditable afterwards, and means re-running the repair cannot pick up rows a
 * later, genuine release seals.
 *
 * `ReleasedStanding::forCycle()` filters on `capture_kind = 'release'`, so a demoted row
 * simply stops being found and the page recomputes and says so — which is the honest
 * answer for a cycle nobody has released. `captureRelease()` refuses to seal twice by
 * looking for the same value, so demoting also un-blocks the real seal when the operator
 * does release.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();

if (!$schema->hasTable('gates_vote_snapshots') || !$schema->hasTable('gates_cycle_transitions')) {
    echo "  = snapshots or transitions ledger not present yet\n";
    return;
}

// Written by 2026_12_06_snapshot_release_standing.php. Without it nothing was ever
// sealed, so there is nothing to demote.
if (!$schema->hasColumn('gates_vote_snapshots', 'capture_kind')) {
    echo "  = gates_vote_snapshots.capture_kind not present — nothing was ever sealed\n";
    return;
}

if (!$schema->hasColumn('gates_cycle_transitions', 'notify')) {
    // Without the flag there is no evidence either way, and a repair that guessed which
    // standings were announced would be exactly the thing ReleasedStanding refuses to do.
    echo "  = gates_cycle_transitions.notify not present — cannot tell announced from not, skipping\n";
    return;
}

// The cycles this platform recorded itself as NOT having announced.
$unannounced = DB::table('gates_cycle_transitions')
    ->where('to_status', 'results')
    ->where('notify', 0)
    ->pluck('cycle_id')
    ->map(static fn ($v): int => (int) $v)
    ->all();

if ($unannounced === []) {
    echo "  = no cycle entered results with its announcements suppressed\n";
    return;
}

// Chunked, because `whereIn` over an unbounded list is one of the two ways a migration
// that works on a fixture fails on a real database (the other is holding every row in
// memory to count it).
$demoted = 0;
foreach (array_chunk($unannounced, 200) as $chunk) {
    $demoted += DB::table('gates_vote_snapshots')
        ->whereIn('cycle_id', $chunk)
        ->where('capture_kind', 'release')
        ->update(['capture_kind' => 'unannounced']);
}

if ($demoted > 0) {
    printf("  + demoted %d sealed row(s) across %d cycle(s) that announced nothing\n",
        $demoted, count($unannounced));
    echo "    those results publish live figures again, labelled as recomputed, until somebody releases them\n";
} else {
    echo "  = nothing to demote; no suppressed cycle carried a release seal\n";
}
