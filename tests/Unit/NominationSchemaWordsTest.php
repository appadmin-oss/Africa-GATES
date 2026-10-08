<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\NominationRules;
use AfricaGates\Support\NomineeKind;
use Tests\TestCase;

/**
 * An ENUM's words, and the PHP that writes them, are one list or they are two bugs.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS CANNOT BE LEFT TO THE SUITE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A value outside an `ENUM` is `Data truncated` on MySQL — not an error anybody
 * notices — and SQLite has no `ENUM` at all, so it stores whatever it is handed. A
 * write of a fourth nominee kind would therefore pass every test on this harness and
 * land as an empty string on the only database that matters, for ever, with the row
 * saved and the kind gone.
 *
 * This codebase has shipped that fault three times and each one presented as something
 * else. `JudgeSchedule` filtered on a status `gates_interviews` has never allowed, so
 * the one screen whose job is listing sittings matched nothing on production.
 * `AnalyticsService` counted `gates_nominee_claims.status = 'approved'` — a word
 * borrowed from the table one stage ABOVE it in the same funnel — and the nomination
 * funnel read "Profile claimed by the nominee — 0 — 0%" on every deployment since it
 * shipped, on the screen an operator uses to decide whether claiming works at all.
 * `gates_comments.status = 'pending'` made the analytics moderation warning
 * unreachable while `/admin/moderation` worked through the real backlog.
 *
 * **A status filter outside its own ENUM is zero rows, not an error — and on a
 * dashboard that zero reads as a measurement.**
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS A MAP AND NOT A SWEEP, AND WHAT STOPS THE MAP GOING STALE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Assert the rule, never the instances" is the standing guidance here, and a bare
 * list of two pairs is exactly the enumeration of past failures that guidance is
 * against. But the thing being asserted — *these words live in this PHP constant* —
 * cannot be derived: nothing in the SQL says where its vocabulary is declared.
 *
 * So the map is the claim, and the SWEEP is the second test: every `ENUM` this
 * migration introduces must appear in the map. A new ENUM column cannot be added
 * without saying where its words are declared, which is the part that would otherwise
 * rot. The map is checked against the migration; the migration is checked against the
 * map.
 */
final class NominationSchemaWordsTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2027_02_02_nomination_categories_evidence.php';

    /**
     * column => the PHP that is the source of truth for its words.
     *
     * @return array<string,list<string>>
     */
    private function declared(): array
    {
        return [
            'nominee_kind' => array_keys(NomineeKind::ALL),
            'kind'         => NominationRules::EVIDENCE_KINDS,
        ];
    }

    public function test_every_enum_word_in_the_migration_is_one_php_declares(): void
    {
        foreach ($this->enumsInMigration() as $column => $words) {
            $php = $this->declared()[$column] ?? null;
            $this->assertNotNull($php, "no PHP declaration is mapped for `$column`");

            // Order is not the claim — a reordered ENUM is the same column. The SET is.
            sort($words);
            sort($php);

            $this->assertSame($php, $words,
                "`$column`'s ENUM and its PHP declaration disagree. A word in the PHP and "
                . 'not in the column is `Data truncated` on MySQL and stored happily on '
                . 'SQLite, so the suite would stay green while production lost the value.');
        }
    }

    public function test_no_enum_is_introduced_without_saying_where_its_words_live(): void
    {
        // The half that keeps the map above honest. Without it the map is a list of
        // the columns that existed the day it was typed, and the next ENUM added to
        // this migration is unguarded while the file reads as guarded.
        $unmapped = array_diff(
            array_keys($this->enumsInMigration()),
            array_keys($this->declared()));

        $this->assertSame([], array_values($unmapped),
            'these ENUM columns have no PHP declaration mapped in this test: '
            . implode(', ', $unmapped));
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The ENUM columns this migration creates, read from the MySQL branch.
     *
     * Read from the migration rather than from the live schema on purpose: on SQLite
     * the column is plain TEXT and the live schema cannot answer at all, so a test
     * that asked the database would pass vacuously on the harness and only ever do
     * its job on the parity run — which is the one run most likely not to happen.
     *
     * @return array<string,list<string>>
     */
    private function enumsInMigration(): array
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . self::MIGRATION);

        // A comment naming an ENUM is prose and must not be read as a declaration —
        // the lesson `GlobeBandTest` records about sweeping what a reader sees, and
        // the one `SchemaIndexTest` learned when it excused three 1064s by asking
        // whether a FILE mentioned the driver rather than whether a STATEMENT did.
        $body = (string) preg_replace('~--[^\n]*|//[^\n]*|/\*.*?\*/~s', '', $body);

        $out = [];
        if (preg_match_all('~(\w+)\s+ENUM\(([^)]*)\)~i', $body, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                preg_match_all("~'([^']*)'~", $hit[2], $w);
                $out[strtolower($hit[1])] = $w[1];
            }
        }

        $this->assertNotSame([], $out,
            'no ENUM was found in the migration — the reader is broken, and a broken '
            . 'reader here reports a clean pass over a file it did not understand');

        return $out;
    }
}
