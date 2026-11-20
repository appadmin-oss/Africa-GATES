<?php
/**
 * Let an operator say why a result is late, on the page people are waiting on.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS EXISTS TO CLOSE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A cycle is ANNOUNCED by `CycleMaterialiser`: one transaction sets `status = 'results'`,
 * promotes the winners and seals the standing. Until that runs, nobody has been crowned.
 *
 * `PublicResults` gated its pages on `status IN ('results','archived')` **or a
 * `results_date` that has passed** — and its own docblock, two lines under that gate, says
 * "a judged-but-unreleased category is a decided award nobody has announced, and serving it
 * publicly is announcing it". The date clause did exactly that. A cycle still in `judging`
 * three days past its results date published a full standing with a named winner, a
 * Cultural Power Index, and no seal — so the page also printed "Recomputed under current
 * rules", the platform admitting on the result page that this was not the announcement.
 * Every figure on it could still move: the panel was open.
 *
 * The gate is `status` alone now. Which creates the second half of the problem, and the
 * reason for this column: the page a nominee, their family and a journalist all refresh on
 * the results date would otherwise go from a wrong answer to NO answer, and "silence is how
 * a withheld award becomes a rumour" is already the rule elsewhere in that class.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A COLUMN AND NOT THE ANNOUNCEMENT BANNER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `announce_text` exists and is sitewide, which makes it the wrong instrument twice over:
 * it says the same thing to somebody buying a ticket as to somebody waiting on an award,
 * and it has to be remembered and taken down by hand. This is per CYCLE, so it appears on
 * that cycle's results and nowhere else, and it stops appearing by itself the moment the
 * cycle is announced — because the condition is derived (a results date that has passed, a
 * cycle not yet released) and not a flag anybody has to clear.
 *
 * NULLABLE, and the delay is stated with or without it. The fact that a result is late is
 * the platform's to admit and does not wait on an operator being at a desk; the note is the
 * part only a person can write. An empty note means the page says what it knows — the date
 * that was promised, and that the award has not been decided yet — rather than inventing a
 * reason or a new date.
 *
 * TEXT and not VARCHAR(n): this is prose written under pressure, on the one screen where
 * being cut off mid-sentence would be read as the platform hiding something. It is escaped
 * where it renders, like every other operator string on a public page.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_award_cycles')) {
    echo "  = gates_award_cycles absent — nothing to widen\n";
    return;
}

if (DB::schema()->hasColumn('gates_award_cycles', 'results_delay_note')) {
    echo "  = gates_award_cycles.results_delay_note present\n";
    return;
}

DB::statement('ALTER TABLE gates_award_cycles ADD COLUMN results_delay_note '
    . ($sqlite ? 'TEXT' : 'TEXT NULL'));

echo "  + gates_award_cycles.results_delay_note added\n";
