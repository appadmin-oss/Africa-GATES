<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHICH EDITION THIS IS — `gates_award_cycles.edition_number` (Phase 5, REFERENCE §11).
 *
 * The redesign says "11th Edition · 2026" everywhere an award is named, and there was no
 * column that could answer it: a cycle had a `year` and a free-text `edition_label`, and
 * `AwardService` counted editions with `COUNT(*)`. A count is the wrong answer for the one
 * award that matters most — a programme that ran for ten years before it came to this
 * platform is in its 11th edition the first time it appears here, and `COUNT(*)` calls it
 * the 1st. So the number is STORED, and an operator corrects it on the cycle screen.
 *
 * ── THE BACKFILL IS A BEST GUESS, AND SAYS SO NOWHERE PUBLIC ──────────────────
 *
 * Existing rows are numbered 1…n per programme in (year, id) order — the only order the
 * data has. That is exactly the answer the page used to imply, so nothing anybody has
 * already read changes; it becomes correctable instead of fixed. Only rows still NULL are
 * written, so re-running this, or running it after an operator has typed a number, never
 * overwrites a number a person chose.
 *
 * SMALLINT UNSIGNED on MySQL, not TINYINT: a ceiling of 255 is the `sort_order` bug waiting
 * (CLAUDE.md), and a century-old prize exists. Nullable, because the reader
 * (`Support\EditionName`) falls back to the order when it is missing — a cycle inserted by
 * a path that predates the column still has a name.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasColumn('gates_award_cycles', 'edition_number')) {
    DB::statement('ALTER TABLE gates_award_cycles ADD COLUMN edition_number '
        . ($sqlite ? 'INTEGER NULL' : 'SMALLINT UNSIGNED NULL DEFAULT NULL'));
    echo "  + gates_award_cycles.edition_number\n";
} else {
    echo "  = gates_award_cycles.edition_number already present\n";
}

$filled = 0;
$byProgramme = [];
foreach (DB::table('gates_award_cycles')->orderBy('programme_id')->orderBy('year')->orderBy('id')
             ->get(['id', 'programme_id', 'edition_number']) as $c) {
    $p = (int) $c->programme_id;
    $byProgramme[$p] = ($byProgramme[$p] ?? 0) + 1;
    if ($c->edition_number === null) {
        $filled += DB::table('gates_award_cycles')->where('id', (int) $c->id)->whereNull('edition_number')
            ->update(['edition_number' => $byProgramme[$p]]);
    }
}
echo "  numbered {$filled} edition(s)\nedition number OK\n";
