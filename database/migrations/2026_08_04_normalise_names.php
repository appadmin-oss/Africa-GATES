<?php
/**
 * Tidy the names already in the database.
 *
 * ── WHY A MIGRATION AND NOT A DISPLAY FILTER ─────────────────────────────────
 *
 * A nominee's name is rendered in about forty places — the ballot, the registry,
 * the leaderboard, the flier, the OG card, three emails, the admin console, the
 * search index, the supporters list. A Twig filter fixes the ones somebody
 * remembers to change and quietly misses the rest, and the miss is invisible
 * until a nominee sees their own name in block capitals on the one page that
 * mattered to them.
 *
 * The data is the thing that is wrong, so the data is what gets fixed.
 * {@see AfricaGates\Support\Name} now runs on the way in, so this is a one-off
 * catch-up rather than a rule that has to keep being applied.
 *
 * ── ONLY WHERE IT CHANGES SOMETHING ──────────────────────────────────────────
 *
 * Rows are compared before writing, so a database of already-tidy names produces
 * zero UPDATEs and this is free to re-run. The count printed at the end is a
 * real number an operator can sanity-check against what they expected.
 *
 * ── AND ONLY WHERE IT IS SAFE ────────────────────────────────────────────────
 *
 * Name::title() leaves any word that already mixes cases exactly as typed, so
 * deliberate styling (McDonald, deWayne, NneKa) survives. What changes is the
 * output of a stuck caps-lock key.
 *
 * Voter names on `gates_donations` are deliberately NOT touched. They are shown
 * on public supporters lists, they came from a payment form rather than an
 * editorial one, and rewriting how somebody signed their own donation is a
 * different decision from tidying an editorial record — one for a human to make.
 */
require __DIR__ . '/../bootstrap.php';
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Support\Name;

/**
 * Rewrite one text column in place, only where the value actually changes.
 */
$normalise = static function (string $table, string $column) : void {
    if (!DB::schema()->hasTable($table) || !DB::schema()->hasColumn($table, $column)) {
        echo "  = {$table}.{$column} absent, skipped\n";
        return;
    }

    $changed = 0;
    $seen    = 0;

    // Chunked by id. These tables are small today and will not always be, and a
    // migration that loads every nominee into memory is one that starts failing
    // on the deployment where it matters most.
    DB::table($table)->orderBy('id')->select(['id', $column])->chunk(500,
        function ($rows) use ($table, $column, &$changed, &$seen) {
            foreach ($rows as $row) {
                $seen++;
                $old = (string) ($row->{$column} ?? '');
                if (trim($old) === '') continue;
                $new = Name::title($old);
                if ($new === $old || $new === '') continue;
                DB::table($table)->where('id', $row->id)->update([$column => $new]);
                $changed++;
            }
        });

    echo "  + {$table}.{$column}: {$changed} of {$seen} normalised\n";
};

$normalise('gates_nominees', 'name');
// The queue too, so a pending nomination reviewed tomorrow already reads properly
// in the admin list rather than only after it is approved.
$normalise('gates_nominations', 'nominee_name');
$normalise('gates_nominations', 'nominator_name');

echo "name normalisation OK\n";
