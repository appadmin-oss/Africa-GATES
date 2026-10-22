<?php
/**
 * The voting-stage shortlist: a rule that computes it, and a snapshot that fixes it.
 *
 * ── WHY TWO TABLES AND NOT ONE FLAG ON THE NOMINEE ───────────────────────────
 *
 * The obvious shape is `gates_nominees.shortlisted TINYINT`. It cannot work. A shortlist
 * derived from votes is a moving object: a rule of "top 10" names a different ten every
 * time a ballot lands, so a boolean written once is stale within the minute and a boolean
 * recomputed on read means nobody can be told they are on the list. Neither is a shortlist.
 *
 * So the two halves are stored apart, because they are different KINDS of fact:
 *
 *   gates_shortlist_rules   — the POLICY. "Top 10, ties included, organic votes only,
 *                             and nobody below 5 votes." Editable, and editing it changes
 *                             only what the preview shows.
 *   gates_shortlists        — the ACT. A named admin, at a named moment, published one.
 *   gates_shortlist_entries — the RESULT, frozen: who, at what rank, on how many votes.
 *
 * Publishing is what makes a shortlist real, and it is deliberately a separate act from
 * saving the rule. An organiser tunes the threshold and watches the preview move; nothing
 * is announced, nothing is emailed, no nominee is told anything, until they publish.
 *
 * ── WHY THE ENTRIES CARRY THEIR OWN VOTE COUNTS ──────────────────────────────
 *
 * `vote_count` and `organic_vote_count` are copied into the entry rather than joined from
 * the nominee. Votes keep arriving after publication — during judging, and on the archived
 * cycle years later — so a joined figure would make the published PDF and the screen
 * disagree, and would eventually make the published shortlist look wrong against its own
 * rule. The snapshot has to say what was true when it was taken, or it is not evidence.
 *
 * `tied_at_cut` records that this entry sat exactly on the boundary. When somebody asks
 * six months later why eleven names appear under a rule that says ten, the row answers.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────────
 *
 * A rule is per CATEGORY, with `category_id NULL` meaning "the default for this cycle".
 * Categories are not the same size — a category with 4 nominees and one with 90 cannot
 * share "top 10" sensibly — so a per-category override has to exist. But making every
 * category require its own row would mean an organiser with 30 categories doing the same
 * thing 30 times, so the cycle-level default carries the common case.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_shortlist_rules')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_shortlist_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL,
            -- NULL = the default for every category in this cycle.
            category_id INTEGER NULL,
            -- top_n | top_pct | min_votes
            mode TEXT NOT NULL DEFAULT 'top_n',
            -- N, a percentage, or a vote floor, depending on mode.
            threshold INTEGER NOT NULL DEFAULT 10,
            -- A floor that applies UNDER top_n/top_pct: 'top 10' in a category where
            -- nine nominees have zero votes must not shortlist nine people nobody chose.
            min_votes INTEGER NOT NULL DEFAULT 1,
            -- include | exclude — what happens to everyone level with the cut line.
            tie_mode TEXT NOT NULL DEFAULT 'include',
            -- 1 = count organic votes only, excluding purchased bonus votes.
            organic_only INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NULL,
            updated_by INTEGER NULL
        )" : "
        CREATE TABLE gates_shortlist_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cycle_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            mode VARCHAR(16) NOT NULL DEFAULT 'top_n',
            threshold INT UNSIGNED NOT NULL DEFAULT 10,
            min_votes INT UNSIGNED NOT NULL DEFAULT 1,
            tie_mode VARCHAR(8) NOT NULL DEFAULT 'include',
            organic_only TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            updated_by BIGINT UNSIGNED NULL,
            KEY idx_rule_cycle(cycle_id, category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_shortlist_rules created\n";

    // One rule per scope. Without this an organiser who double-submits the form gets two
    // rules for one category and the preview silently picks whichever the engine returns
    // first — the same class of bug as two nav entries lighting at once, but with a
    // shortlist behind it. SQLite treats NULLs as distinct in a UNIQUE index, so the
    // cycle-level default is guarded by a second, partial index.
    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_rule_cycle ON gates_shortlist_rules(cycle_id, category_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_rule_cat ON gates_shortlist_rules(cycle_id, category_id) WHERE category_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_rule_cycle_default ON gates_shortlist_rules(cycle_id) WHERE category_id IS NULL');
    } else {
        DB::statement('ALTER TABLE gates_shortlist_rules ADD UNIQUE KEY uq_rule_scope(cycle_id, category_id)');
    }
}

if (!DB::schema()->hasTable('gates_shortlists')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_shortlists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cycle_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            -- The rule as it read AT PUBLICATION, not a pointer to a row that can be
            -- edited afterwards. A published shortlist has to keep describing itself.
            rule_json TEXT NULL,
            rule_text TEXT NULL,
            entry_count INTEGER NOT NULL DEFAULT 0,
            considered INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'published',
            published_at TEXT NULL,
            published_by INTEGER NULL,
            withdrawn_at TEXT NULL,
            withdrawn_by INTEGER NULL,
            note TEXT NULL
        )" : "
        CREATE TABLE gates_shortlists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cycle_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            rule_json TEXT NULL,
            rule_text VARCHAR(400) NULL,
            entry_count INT UNSIGNED NOT NULL DEFAULT 0,
            considered INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'published',
            published_at TIMESTAMP NULL DEFAULT NULL,
            published_by BIGINT UNSIGNED NULL,
            withdrawn_at TIMESTAMP NULL DEFAULT NULL,
            withdrawn_by BIGINT UNSIGNED NULL,
            note VARCHAR(400) NULL,
            KEY idx_sl_cat(category_id, status),
            KEY idx_sl_cycle(cycle_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_shortlists created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sl_cat ON gates_shortlists(category_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sl_cycle ON gates_shortlists(cycle_id, status)');
    }
}

if (!DB::schema()->hasTable('gates_shortlist_entries')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_shortlist_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shortlist_id INTEGER NOT NULL,
            nominee_id INTEGER NOT NULL,
            rank_no INTEGER NOT NULL DEFAULT 0,
            -- Frozen. See the note at the top of this file.
            vote_count INTEGER NOT NULL DEFAULT 0,
            organic_vote_count INTEGER NOT NULL DEFAULT 0,
            -- The name AS PUBLISHED. A nominee renamed afterwards must not silently
            -- rewrite a document somebody has already been sent.
            nominee_name TEXT NULL,
            country_code TEXT NULL,
            tied_at_cut INTEGER NOT NULL DEFAULT 0
        )" : "
        CREATE TABLE gates_shortlist_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            shortlist_id BIGINT UNSIGNED NOT NULL,
            nominee_id BIGINT UNSIGNED NOT NULL,
            rank_no INT UNSIGNED NOT NULL DEFAULT 0,
            vote_count INT UNSIGNED NOT NULL DEFAULT 0,
            organic_vote_count INT UNSIGNED NOT NULL DEFAULT 0,
            nominee_name VARCHAR(200) NULL,
            country_code CHAR(2) NULL,
            tied_at_cut TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_sle_list(shortlist_id, rank_no),
            KEY idx_sle_nominee(nominee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_shortlist_entries created\n";

    if ($sqlite) {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sle_list ON gates_shortlist_entries(shortlist_id, rank_no)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sle_nominee ON gates_shortlist_entries(nominee_id)');
    }
}
