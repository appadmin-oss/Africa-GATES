<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHO RUNS AN AWARD, WHICH IS NOT WHO PAID FOR IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT `gates_programme_sponsors`
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The October handoff asks for "Hosted by Okun Alimosho" beside a logo on the Alimosho
 * Awards pages and on any challenge scoped to them. The sponsors table is sitting right
 * there with a `name`, a `logo_path` and a `tier`, and putting a host in it would have
 * been one insert.
 *
 * It would also have been a FALSE DISCLOSURE. That table's own docblock says what it is
 * for in as many words — "who paid to be named beside an award", a question "a journalist,
 * a regulator or a losing nominee may ask" — and its `amount_naira` column exists so the
 * answer can be given honestly. A host is the body that RUNS the award; writing one in as
 * a sponsor tells every future reader of that table that money changed hands when it did
 * not, on the one record built to be read years later by somebody checking exactly that.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT LIVES ON THE PROGRAMME RATHER THAN ON THE CHALLENGE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A challenge is scoped to an award cycle, which belongs to a programme, so the host is
 * already reachable from a challenge by the chain the rest of this feature walks. Storing
 * it on `gates_challenges` as well would be two copies of one fact, and the copy on the
 * challenge is the one nobody updates when the host's name changes — the challenge is
 * over by then, and its page is still up.
 *
 * It is NULLABLE and almost every programme will leave it so. A continental award has no
 * host but Africa GATES itself, and a line reading "Hosted by Africa GATES" on an Africa
 * GATES page is noise. Nothing renders when the name is empty.
 */

$host = [
    'host_name'      => ['TEXT NULL',          'VARCHAR(160) NULL'],
    'host_logo_path' => ['TEXT NULL',          'VARCHAR(255) NULL'],
    'host_url'       => ['TEXT NULL',          'VARCHAR(255) NULL'],
];

$sqlite = DB::connection()->getDriverName() === 'sqlite';

foreach ($host as $col => [$sqliteType, $mysqlType]) {
    if (DB::schema()->hasColumn('gates_award_programmes', $col)) {
        echo "  = gates_award_programmes.{$col} already present\n";
        continue;
    }

    // Spelled out per driver rather than with one portable type: `VARCHAR(160)` is a real
    // ceiling on MySQL and an ignored hint on SQLite, and this repo has already paid for
    // assuming the two agree about widths.
    DB::statement('ALTER TABLE gates_award_programmes ADD COLUMN ' . $col . ' '
        . ($sqlite ? $sqliteType : $mysqlType));
    echo "  + gates_award_programmes.{$col}\n";
}
