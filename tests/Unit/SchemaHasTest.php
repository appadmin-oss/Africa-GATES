<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * ONE SCHEMA PROBE, MEMOISED, AND THE COUNT THAT PROVES IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS REPLACED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "Does this database actually have that column?" is a question this platform has to ask,
 * because there is no shell on production and migrations are applied by an operator
 * opening a URL — the admin layout counts unapplied steps and that count has been in the
 * dozens on a live site.
 *
 * The four lines that ask it existed three times: `MergeService::hasCol()`,
 * `MergeJournal::hasCol()` and `AnalyticsService::hasCol()`. They had drifted on the one
 * thing that matters. The merge pair memoised. The analytics one did not — and that class
 * asks twenty-four of these per dashboard render, each of which fetches the table's whole
 * column listing (`information_schema.columns` on MySQL). Twenty-four extra round trips on
 * one admin page, on every load, and nothing to see: the page draws and the figures are
 * right. It is simply slower than it looks, which is the shape of fault nobody files.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE QUERY COUNT IS THE ASSERTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A memo has no observable behaviour — same answers, fewer questions. So the only test
 * that can tell a memoised probe from an unmemoised one counts the queries, which is what
 * `EditionScaleTest` does for the scorer's per-cycle memo for the same reason. Asserting
 * "it returns true" would pass on the version this replaced.
 */
final class SchemaHasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A memo is per PROCESS, and the suite is one process. Without this the first test
        // to run seeds every later one's answers and the counting tests measure nothing.
        SchemaHas::forget();
    }

    /** @return int queries issued while $fn ran */
    private function queries(callable $fn): int
    {
        $conn = DB::connection();
        $conn->flushQueryLog();
        $conn->enableQueryLog();
        try { $fn(); } finally { $n = count($conn->getQueryLog()); $conn->disableQueryLog(); }
        return $n;
    }

    // ══ the answers ══════════════════════════════════════════════════════════

    public function test_it_answers_the_question(): void
    {
        $this->assertTrue(SchemaHas::table('gates_nominees'));
        $this->assertFalse(SchemaHas::table('gates_no_such_table'));

        $this->assertTrue(SchemaHas::column('gates_nominees', 'vote_count'));
        $this->assertFalse(SchemaHas::column('gates_nominees', 'no_such_column'));
        $this->assertFalse(SchemaHas::column('gates_no_such_table', 'anything'),
            'a column on a table that is not there is not there');
    }

    // ══ and asks it once ═════════════════════════════════════════════════════

    public function test_the_same_column_is_asked_about_once(): void
    {
        $first = $this->queries(static fn () => SchemaHas::column('gates_nominees', 'vote_count'));
        $this->assertGreaterThan(0, $first,
            'the first call must actually reach the database, or this proves nothing');

        $again = $this->queries(static function (): void {
            for ($i = 0; $i < 24; $i++) SchemaHas::column('gates_nominees', 'vote_count');
        });

        $this->assertSame(0, $again,
            'twenty-four repeats of one question is the analytics dashboard, and it used '
            . 'to send twenty-four column listings to the database per render');
    }

    public function test_a_negative_answer_is_remembered_too(): void
    {
        SchemaHas::column('gates_nominees', 'no_such_column');

        $this->assertSame(0, $this->queries(static function (): void {
            for ($i = 0; $i < 5; $i++) SchemaHas::column('gates_nominees', 'no_such_column');
        }), 'a column that is absent is absent for the rest of the request too');
    }

    public function test_tables_and_columns_do_not_share_a_memo_key(): void
    {
        // `gates_votes` the table exists; a column called `gates_votes` on it does not.
        // Keyed carelessly the two questions collide and the second inherits the first's
        // answer — which on a merge means reassigning a column that is not there.
        $this->assertTrue(SchemaHas::table('gates_votes'));
        $this->assertFalse(SchemaHas::column('gates_votes', 'gates_votes'));
        $this->assertTrue(SchemaHas::table('gates_votes'));
    }

    public function test_forget_makes_it_ask_again(): void
    {
        SchemaHas::column('gates_nominees', 'vote_count');
        SchemaHas::forget();

        $this->assertGreaterThan(0,
            $this->queries(static fn () => SchemaHas::column('gates_nominees', 'vote_count')),
            'a migration run changes the schema mid-process, so there has to be a way to '
            . 'stop trusting an answer from before it');
    }

    // ══ nobody keeps a fourth copy ═══════════════════════════════════════════

    /**
     * THE DUPLICATE MUST NOT COME BACK.
     *
     * Three copies of one four-line probe is how the memo came to be present in two of
     * them and absent in the third, and the difference was worth twenty-four queries a
     * page. A fourth copy would be indistinguishable from the fix by reading any one file.
     *
     * Scoped to `src/`: a migration asks `Schema` directly and MUST keep doing so, because
     * its whole purpose is changing the answer.
     */
    public function test_no_service_keeps_its_own_schema_probe(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/src';

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;

            $rel = str_replace(dirname(__DIR__, 2) . '/', '', $f->getPathname());
            if (str_ends_with($rel, 'src/Support/SchemaHas.php')) continue;

            $src = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'], ' ',
                (string) file_get_contents($f->getPathname()));

            // A named helper whose body is a schema probe — the shape that was tripled.
            // A bare inline `DB::schema()->hasColumn()` is not in scope: forty-odd of
            // those exist, each asking once about its own table, and rewriting them all
            // would be a churn with no fault behind it.
            if (preg_match(
                '~function\s+has(?:Col|Column|Table)?\s*\([^)]*\)[^{]*\{[^}]{0,200}?'
                . 'schema\(\)->has(?:Column|Table)~s', $src) === 1) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            "A per-class schema probe is the thing SchemaHas replaced, and the copies had\n"
            . "already drifted on whether they memoise. Call SchemaHas::column() /\n"
            . "SchemaHas::table().\n\n  " . implode("\n  ", $offenders));
    }
}
