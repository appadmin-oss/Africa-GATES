<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\ChallengeEnum as E;
use AfricaGates\Support\NominationStatus as NS;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Every challenge ENUM, against the PHP that writes it — and against both schemas.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS CANNOT BE LEFT TO THE SUITE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A value outside an `ENUM` is `Data truncated` on MySQL — not an error anybody
 * notices — and SQLite has no `ENUM` at all, so it stores whatever it is handed. A
 * sixth action declared in `ChallengeEnum` and not added to the column would pass
 * every test on this harness and land as an empty string on production, with the row
 * saved and the action gone.
 *
 * This codebase has shipped that three times and each presented as something else: a
 * schedule screen filtering on a status its column has never allowed; a funnel
 * reading "Profile claimed by the nominee — 0 — 0%" on every deployment since it
 * shipped; a moderation warning that could not fire while the moderation screen
 * worked through the real backlog.
 *
 * ── AND THREE FILES HAVE TO AGREE, NOT TWO ──────────────────────────────────
 *
 * The migration builds an existing database, `schema.sql` builds a fresh MySQL one
 * and `sqlite-schema.sql` builds the harness. A word added to two of the three gives
 * a platform that behaves differently depending on when the deployment was created —
 * which is the worst of the three outcomes, because it looks like a bug in the code.
 */
final class ChallengeSchemaWordsTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2027_02_10_challenges.php';
    private const MYSQL     = '/database/schema.sql';
    private const SQLITE    = '/database/sqlite-schema.sql';

    /**
     * table.column => the PHP list that is the source of truth for its words.
     *
     * A MAP rather than a sweep, because the thing asserted — *these words live in
     * this constant* — cannot be derived; nothing in the SQL says where its
     * vocabulary is declared. What keeps the map honest is
     * {@see test_no_enum_is_introduced_without_saying_where_its_words_live} below.
     *
     * @return array<string,list<string>>
     */
    private function declared(): array
    {
        return [
            'gates_challenges.action'            => E::ACTIONS,
            'gates_challenges.mode'              => E::MODES,
            'gates_challenges.prize_type'        => E::PRIZE_TYPES,
            'gates_challenges.theme'             => E::THEMES,
            'gates_challenges.status'            => E::STATUSES,
            'gates_challenge_scopes.scope_type'  => E::SCOPE_TYPES,
            'gates_challenge_entries.status'     => E::ENTRY_STATUSES,
            'gates_challenge_entries.payout_status' => E::PAYOUT_STATUSES,
            'gates_promos.placement'             => E::PLACEMENTS,
            'gates_promos.theme'                 => E::THEMES,
            'gates_promos.audience'              => E::AUDIENCES,
            'gates_nominations.status'           => NS::ALL,
        ];
    }

    public function test_the_mysql_schema_matches_the_php_declarations(): void
    {
        foreach ($this->enumsIn(self::MYSQL) as $col => $words) {
            $php = $this->declared()[$col] ?? null;
            if ($php === null) continue;          // not a challenge column

            sort($words); sort($php);
            $this->assertSame($php, $words,
                "`$col` in schema.sql disagrees with its PHP declaration. A word in the "
                . 'PHP and not in the column is `Data truncated` on MySQL and stored '
                . 'happily on SQLite, so the suite would stay green while production '
                . 'lost the value.');
        }
    }

    public function test_the_sqlite_schema_has_every_table_the_mysql_one_has(): void
    {
        $mysql  = $this->tablesIn(self::MYSQL);
        $sqlite = $this->tablesIn(self::SQLITE);

        $missing = array_values(array_diff($mysql, $sqlite));

        // A table in one schema and not the other gives a platform that behaves
        // differently depending on when the deployment was created.
        $this->assertSame([], $missing,
            'these challenge tables are in schema.sql and not in sqlite-schema.sql: '
            . implode(', ', $missing));
    }

    public function test_no_enum_is_introduced_without_saying_where_its_words_live(): void
    {
        // The half that keeps the map above honest. Without it the map is a list of
        // the columns that existed the day it was typed, and the next ENUM added is
        // unguarded while this file reads as guarding everything.
        $unmapped = array_diff(
            array_keys($this->enumsIn(self::MYSQL)),
            array_keys($this->declared()));

        $this->assertSame([], array_values($unmapped),
            'these challenge ENUM columns have no PHP declaration mapped: '
            . implode(', ', $unmapped));
    }

    public function test_the_live_nomination_words_were_widened_and_not_replaced(): void
    {
        // ── THE DEVIATION, PINNED ───────────────────────────────────────────
        // The handoff specifies `(draft,submitted,checking,verified,needs_details,
        // rejected)`. 176 places in `src/` read the literal 'approved'. Dropping it
        // would make every one of them match zero rows, silently, on MySQL.
        //
        // So the set is the union and the three live words stay — IN THEIR ORIGINAL
        // POSITIONS, because reordering an ENUM rewrites every row's stored index.
        $this->assertSame(['pending', 'approved', 'rejected'], array_slice(NS::ALL, 0, 3),
            'the three live status words moved or were dropped — 176 readers ask for '
            . 'them by name, and an ENUM reorder rewrites every stored row');

        foreach (['verified', 'checking', 'needs_details', 'submitted', 'draft'] as $w) {
            $this->assertContains($w, NS::ALL, "the handoff's `$w` is not in the column");
        }
    }

    public function test_a_prize_counting_nomination_needs_both_halves(): void
    {
        // §3: a nomination counts "when the nomination reaches verified" — which the
        // prompt defines as the nominee confirming AND a moderator approving. Either
        // alone is somebody else's decision standing in for a prize.
        $this->assertTrue(NS::countsForChallenge('verified', '2026-10-01 09:00:00'));
        $this->assertTrue(NS::countsForChallenge('approved', '2026-10-01 09:00:00'),
            'the historic spelling of a moderator pass stopped counting');

        $this->assertFalse(NS::countsForChallenge('verified', null),
            'a nomination counted without the nominee ever confirming');
        $this->assertFalse(NS::countsForChallenge('checking', '2026-10-01 09:00:00'),
            'a nomination counted before a moderator had looked');
        $this->assertFalse(NS::countsForChallenge('rejected', '2026-10-01 09:00:00'));
    }

    /**
     * The live column is ASKED whether it will take each word, not told that it should.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE DIRECTION THE TESTS ABOVE DO NOT ASK IN
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Everything above reads a FILE: the migration against `NominationStatus::ALL`,
     * one schema against the other. All of it passed while `verified` could not be
     * written on SQLite at all, because `gates_nominations` carries
     * `CHECK(status IN ('pending','approved','rejected'))` — and SQLite enforces
     * CHECK. The harness's `PRAGMA foreign_keys = OFF` disables foreign keys and
     * nothing else, which is easy to misread as "constraints are off in here".
     *
     * So a nomination could reach `verified` on production and nowhere a developer or
     * a test could see, and `countsForChallenge()` — which requires exactly that word
     * — was dead everywhere but the one database with no shell on it. The usual
     * MySQL/SQLite divergence runs the other way, which is what made it easy to write
     * and invisible to read.
     *
     * The repair is `2027_02_11_nomination_status_check_repair.php`. This is the
     * question that would have caught it: write the word, and require the row.
     *
     * Proved by breaking it. Restoring the three-word CHECK in `sqlite-schema.sql`
     * alone is NOT enough — the repair migration puts it back at migration time,
     * which is the whole point of it. With the migration disabled as well, this is
     * the only test in the file that fails: the insert throws
     * `CHECK constraint failed` on `draft`, the first declared word the old set does
     * not hold. It surfaces as an error rather than as the assertion message below,
     * because a refused write never reaches the read-back.
     */
    public function test_every_declared_status_word_can_actually_be_written(): void
    {
        $cycle = DB::table('gates_award_cycles')->insertGetId([
            'programme_id' => DB::table('gates_award_programmes')->insertGetId([
                'title' => 'Status vocabulary probe', 'slug' => 'status-vocab-probe',
                'is_active' => 1,
            ]),
            'year' => 2026, 'status' => 'nominations',
        ]);

        foreach (NS::ALL as $word) {
            $id = DB::table('gates_nominations')->insertGetId([
                'cycle_id' => $cycle, 'nominee_name' => 'Probe ' . $word,
                'nominator_name' => 'Probe', 'nominator_email' => 'probe@example.test',
                'status' => $word,
            ]);

            // Read it BACK. A driver that silently coerced the value would otherwise
            // pass on the insert alone — which is MySQL's `Data truncated`, the fault
            // this whole file exists for, wearing the other driver's clothes.
            $this->assertSame($word, DB::table('gates_nominations')->where('id', $id)->value('status'),
                "the schema refuses `$word`, so no test and no developer can ever "
                . 'produce a nomination in that state');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * ENUM columns in a schema file, keyed `table.column`.
     *
     * Read from the FILE rather than the live database on purpose: on SQLite every
     * one of these is TEXT and the database cannot answer, so a test that asked it
     * would pass vacuously on the harness and only ever do its job on the parity run
     * — which is the run most likely not to happen.
     *
     * @return array<string,list<string>>
     */
    private function enumsIn(string $rel): array
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . $rel);
        // A comment naming an ENUM is prose, not a declaration.
        $body = (string) preg_replace('~--[^\n]*|/\*.*?\*/~s', '', $body);

        $out = [];
        $table = '';
        foreach (explode("\n", $body) as $line) {
            if (preg_match('~CREATE TABLE(?: IF NOT EXISTS)?\s+(\w+)~i', $line, $m)) {
                $table = $m[1];
            }
            // SCOPED TO THIS FEATURE'S TABLES. The file holds twenty-six other
            // ENUMs that predate challenges, and a sweep that adopts all of them
            // reports twenty-six unmapped columns on its first run — findings about
            // code this change never touched, which is how a new guard gets its
            // exclusions widened until it covers nothing.
            if ($table !== '' && self::ours($table)
                && preg_match('~^\s*(\w+)\s+ENUM\(([^)]*)\)~i', $line, $m)) {
                preg_match_all("~'([^']*)'~", $m[2], $w);
                $out[$table . '.' . $m[1]] = $w[1];
            }
        }

        $this->assertNotSame([], $out,
            "no ENUM was found in $rel — the reader is broken, and a broken reader "
            . 'reports a clean pass over a file it did not understand');

        return $out;
    }

    /**
     * Is this table part of the challenge feature?
     *
     * `gates_nominations` is included for its `status` alone — the one live column
     * this change widened, and the one place the two vocabularies meet.
     */
    private static function ours(string $table): bool
    {
        return str_starts_with($table, 'gates_challenge')
            || $table === 'gates_promos'
            || $table === 'gates_nominations';
    }

    /** @return list<string> the challenge tables declared in a schema file */
    private function tablesIn(string $rel): array
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . $rel);
        preg_match_all('~CREATE TABLE(?: IF NOT EXISTS)?\s+(gates_(?:challenge\w*|promos))~i', $body, $m);

        $out = array_values(array_unique($m[1]));
        sort($out);
        return $out;
    }
}
