<?php
/**
 * Separate organic votes from paid "bonus" votes for CPI integrity.
 *
 * Adds gates_nominees.organic_vote_count — the cohort-normalised CPI community
 * signal — and backfills it from existing organic (vote_type='standard') rows.
 * vote_count stays as the public total-support display (organic + paid boost),
 * so purchased votes remain visible but can no longer move rank. Idempotent.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;

$schema = DB::schema();

if (!$schema->hasColumn('gates_nominees', 'organic_vote_count')) {
    DB::statement('ALTER TABLE gates_nominees ADD COLUMN organic_vote_count INTEGER NOT NULL DEFAULT 0');
    echo "  + gates_nominees.organic_vote_count added\n";

    // Backfill from existing organic vote rows. If vote_type isn't present yet
    // (a pre-weighting DB), every recorded vote is organic.
    if ($schema->hasColumn('gates_votes', 'vote_type')) {
        DB::statement(
            "UPDATE gates_nominees SET organic_vote_count = "
            . "(SELECT COUNT(*) FROM gates_votes v WHERE v.nominee_id = gates_nominees.id AND v.vote_type = 'standard')"
        );
    } else {
        DB::statement(
            "UPDATE gates_nominees SET organic_vote_count = "
            . "(SELECT COUNT(*) FROM gates_votes v WHERE v.nominee_id = gates_nominees.id)"
        );
    }
    echo "  = organic_vote_count backfilled from organic votes\n";
} else {
    echo "  = gates_nominees.organic_vote_count already present\n";
}

echo "organic vote separation OK\n";
