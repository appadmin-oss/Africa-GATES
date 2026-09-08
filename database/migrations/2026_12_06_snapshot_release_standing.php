<?php
/**
 * Give a snapshot row enough to BE a published result, and mark the one taken at release.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS EXISTS TO CLOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_vote_snapshots` is the hash-chained record of every standing this platform has
 * computed, and the help centre publishes a promise about it: "the result is written down
 * and sealed… there is no quiet edit available. There is only an edit that announces
 * itself."
 *
 * Meanwhile `PublicResults::category()` never read it. It re-ran the whole calculation
 * from scratch on every page view — so a published result page showed what TODAY'S rules
 * produce, not what the nominee was awarded, and `r.winner` on that page is the
 * RECOMPUTED top rather than the frozen `status = 'winner'`.
 *
 * That is harmless while the rules never change. They changed three times in one week
 * (the community half split into people and tally, the judge half went linear, the
 * denominator moved from the category to the edition). Measured on a real released
 * nominee — 1,955 votes, panel 7.9, top of his category:
 *
 *     announced under the rules of the day :  693
 *     what the page computed afterwards    :  885
 *
 * Nobody edited anything. The seal is intact. He simply opens his own result page and
 * finds a different number, and if a rules change reorders his category the page starts
 * naming somebody else as the winner of an award already given.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE COLUMNS, AND WHY THEY ARE OUTSIDE THE HASH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A row held `vote_count`, `judge_score` and `cpi_score` — enough to publish a ranking and
 * a headline score, and NOT enough to publish the working: the community/judge split, the
 * denominator, the number of people behind a nominee. A released page that reads the seal
 * for the score and recomputes the working would be describing today's arithmetic under
 * yesterday's number, which is the same fault one layer down.
 *
 * So the working travels on the row. The hash payload is deliberately UNCHANGED —
 * `cycleId|nomineeId|votes|cpi|at`, exactly as {@see \AfricaGates\Services\SnapshotService}
 * has always computed it — so every existing link still verifies and the chain does not
 * fork. That is the same choice already made for `judge_score`, which is stored and
 * unhashed because the integer `cpi_score` encodes it exactly.
 *
 * These columns are NULLABLE on purpose. Every row captured before today has no working
 * recorded, and a zero would be a lie about a nominee's score. Null means "not recorded
 * at the time", which is what the release screen and the public page must be able to say.
 *
 * `capture_kind` marks the capture taken at promotion — the moment the platform decided
 * who won. Routine captures continue after release (a cycle in `results` is still
 * captured), so without this the announced standing would have to be guessed at from
 * timestamps. It is VARCHAR and not an ENUM: this codebase has shipped an out-of-range
 * ENUM value twice, and MySQL answers that with `Data truncated` rather than an error
 * anybody notices.
 */

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_vote_snapshots')) {
    echo "  = gates_vote_snapshots absent — nothing to widen\n";
    return;
}

$add = [
    // The working, so a sealed row can be published in full rather than in part.
    'community_points'  => $sqlite ? 'INTEGER'   : 'INT NULL',
    'judge_points'      => $sqlite ? 'INTEGER'   : 'INT NULL',
    'cohort_max'        => $sqlite ? 'INTEGER'   : 'INT UNSIGNED NULL',
    'unique_voters'     => $sqlite ? 'INTEGER'   : 'INT UNSIGNED NULL',
    'cohort_max_unique' => $sqlite ? 'INTEGER'   : 'INT UNSIGNED NULL',
    // ── THE OUTCOME, NOT ONLY THE ARITHMETIC ─────────────────────────────────
    //
    // Whether a nominee was RANKED at the announcement, and where. Without these two a
    // sealed page would have to re-derive who was in the running from live facts — the
    // judge quorum, the published shortlist, whether anybody had voted — and every one of
    // those can move after a release. A nominee who was below quorum on the day and has
    // since been judged would then be ranked into a standing that never contained them.
    //
    // `standing_rank` and NOT `rank`: RANK is a reserved word in MySQL 8 (it is a window
    // function) and needs quoting everywhere it appears, while SQLite accepts it bare —
    // exactly the divergence that passes the suite and fails production.
    'standing_rank'     => $sqlite ? 'INTEGER'   : 'INT UNSIGNED NULL',
    'in_running'        => $sqlite ? 'INTEGER'   : 'TINYINT(1) NULL',
    // Which capture this is. 'routine' for the scheduled sweep, 'release' for the one
    // taken at promotion — the standing as announced.
    'capture_kind'      => $sqlite
        ? "TEXT NOT NULL DEFAULT 'routine'"
        : "VARCHAR(16) NOT NULL DEFAULT 'routine'",
];

foreach ($add as $col => $type) {
    if (DB::schema()->hasColumn('gates_vote_snapshots', $col)) {
        echo "  = gates_vote_snapshots.{$col} present\n";
        continue;
    }
    DB::statement("ALTER TABLE gates_vote_snapshots ADD COLUMN {$col} {$type}");
    echo "  + gates_vote_snapshots.{$col} added\n";
}

// ── FINDING THE ANNOUNCED STANDING HAS TO BE ONE INDEXED LOOKUP ──────────────
//
// A published result page reads it on every view. Without this the lookup is a scan of
// the whole archive — which grows by a row per nominee per active cycle every six hours,
// for the life of the platform — on the one page an award is read from.
DB::statement('CREATE INDEX IF NOT EXISTS idx_snap_cycle_kind
               ON gates_vote_snapshots (cycle_id, capture_kind, id)');
echo "  + idx_snap_cycle_kind ensured\n";
