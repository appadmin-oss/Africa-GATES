<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\ColumnRange;
use Tests\TestCase;

/**
 * Values that fit in dev and do not fit in production.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DIVERGENCE THIS GUARDS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Production is MySQL; dev and this harness are SQLite. **SQLite ignores integer widths
 * entirely** — a column declared `SMALLINT UNSIGNED` stores 100000 without complaint —
 * while MySQL in strict mode rejects the statement with "Out of range value for column".
 *
 * So an admin field that is lower-bounded and not upper-bounded is green here, green in
 * review, and broken in production alone, for whoever typed the long number, with an
 * error naming a column rather than a field. `CLAUDE.md` records that this has already
 * cost a `sort_order` once.
 *
 * Two discount-code fields were in exactly that state: `gates_shop_codes.max_per_email`
 * and `gates_event_codes.max_per_email`, both `SMALLINT UNSIGNED`, both written as
 * `max(1, (int) $input)`.
 *
 * ── WHY THIS TEST READS THE SCHEMA ───────────────────────────────────────────
 *
 * A test asserting "≤ 65535" would keep passing after somebody widened the column to
 * `INT UNSIGNED`, and the clamp would silently go on refusing values the database would
 * now accept — a limit nobody could find the reason for. The invariant is not the number,
 * it is that **the clamp and the column agree**, so the number comes from the migration.
 */
final class ColumnWidthTest extends TestCase
{
    /** The MySQL integer type declared for a column, e.g. 'SMALLINT UNSIGNED'. */
    private function declaredType(string $table, string $column): string
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/database/*.sql') ?: [];
        foreach (glob($root . '/database/migrations/*.php') ?: [] as $m) $files[] = $m;

        foreach ($files as $f) {
            // Widths are only real in the MySQL definitions.
            if (str_contains(basename($f), 'sqlite')) continue;

            // Tracked line by line rather than from the first mention of the table: a dated
            // migration holds BOTH branches in one file, sqlite first, and stopping at the
            // first `CREATE TABLE <name>` reads the branch whose widths are fiction.
            //
            // The type pattern is anchored with \b for the same reason — without it `INT`
            // matches the leading three letters of the sqlite branch's `INTEGER`, and this
            // test reported `gates_shop_codes.max_per_email` as INT while the column MySQL
            // actually creates is SMALLINT UNSIGNED. A schema reader that reads the wrong
            // branch is worse than none: it would have blessed a clamp four orders of
            // magnitude too generous.
            $current = '';
            foreach (explode("\n", (string) file_get_contents($f)) as $line) {
                if (preg_match('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?/i', $line, $t)) $current = $t[1];
                if (preg_match('/ALTER TABLE\s+`?(\w+)`?/i', $line, $t)) $current = $t[1];
                if ($current !== $table) continue;

                if (preg_match('/^\s*(?:ADD COLUMN\s+)?`?' . preg_quote($column, '/')
                        . '`?\s+((?:TINY|SMALL|MEDIUM|BIG)?INT)\b(?:\(\d+\))?(\s+UNSIGNED)?/i', $line, $m)) {
                    return strtoupper($m[1]) . (trim((string) ($m[2] ?? '')) !== '' ? ' UNSIGNED' : '');
                }
            }
        }

        return '';
    }

    /** @return array<string,int> the ceiling this codebase believes each type has */
    private function ceilings(): array
    {
        return [
            'TINYINT'            => ColumnRange::TINYINT,
            'SMALLINT'           => ColumnRange::SMALLINT,
            'MEDIUMINT'          => ColumnRange::MEDIUMINT,
            'INT'                => ColumnRange::INT,
            'TINYINT UNSIGNED'   => ColumnRange::TINYINT_UNSIGNED,
            'SMALLINT UNSIGNED'  => ColumnRange::SMALLINT_UNSIGNED,
            'MEDIUMINT UNSIGNED' => ColumnRange::MEDIUMINT_UNSIGNED,
            'INT UNSIGNED'       => ColumnRange::INT_UNSIGNED,
        ];
    }

    // ══ the constants are the real MySQL maxima ══════════════════════════════

    /**
     * Off-by-one here is the whole failure mode, so each is asserted against the
     * arithmetic definition rather than a transcribed literal.
     */
    public function test_the_ceilings_are_the_actual_mysql_maxima(): void
    {
        $this->assertSame(2 ** 7 - 1,  ColumnRange::TINYINT);
        $this->assertSame(2 ** 15 - 1, ColumnRange::SMALLINT);
        $this->assertSame(2 ** 23 - 1, ColumnRange::MEDIUMINT);
        $this->assertSame(2 ** 31 - 1, ColumnRange::INT);
        $this->assertSame(2 ** 8 - 1,  ColumnRange::TINYINT_UNSIGNED);
        $this->assertSame(2 ** 16 - 1, ColumnRange::SMALLINT_UNSIGNED);
        $this->assertSame(2 ** 24 - 1, ColumnRange::MEDIUMINT_UNSIGNED);
        $this->assertSame(2 ** 32 - 1, ColumnRange::INT_UNSIGNED);
    }

    public function test_clamping_holds_both_ends(): void
    {
        $this->assertSame(65535, ColumnRange::clamp(999999, ColumnRange::SMALLINT_UNSIGNED, 1));
        $this->assertSame(1,     ColumnRange::clamp(0, ColumnRange::SMALLINT_UNSIGNED, 1));
        $this->assertSame(1,     ColumnRange::clamp(-40, ColumnRange::SMALLINT_UNSIGNED, 1));
        $this->assertSame(12,    ColumnRange::clamp(12, ColumnRange::SMALLINT_UNSIGNED, 1),
            'an ordinary value must pass through untouched');
    }

    public function test_fits_is_the_refusing_half(): void
    {
        $this->assertTrue(ColumnRange::fits(65535, ColumnRange::SMALLINT_UNSIGNED));
        $this->assertFalse(ColumnRange::fits(65536, ColumnRange::SMALLINT_UNSIGNED));
        $this->assertFalse(ColumnRange::fits(-1, ColumnRange::SMALLINT_UNSIGNED));
    }

    // ══ the clamp and the column agree ═══════════════════════════════════════

    /**
     * THE ONE THAT MATTERS. If somebody widens one of these columns, this fails and tells
     * them the clamp beside it is now wrong — rather than leaving a ceiling nobody can
     * find a reason for.
     */
    public function test_each_clamped_field_is_clamped_to_the_width_it_actually_has(): void
    {
        foreach ([
            ['gates_shop_codes',  'max_per_email', 'ColumnRange::SMALLINT_UNSIGNED', 'src/Admin/Controllers/ShopController.php'],
            ['gates_event_codes', 'max_per_email', 'ColumnRange::SMALLINT_UNSIGNED', 'src/Admin/Controllers/EventsController.php'],
            ['gates_shop_codes',  'max_uses',      'ColumnRange::INT_UNSIGNED',      'src/Admin/Controllers/ShopController.php'],
            ['gates_event_codes', 'max_uses',      'ColumnRange::INT_UNSIGNED',      'src/Admin/Controllers/EventsController.php'],
        ] as [$table, $column, $constant, $writer]) {
            $type = $this->declaredType($table, $column);

            $this->assertNotSame('', $type,
                "could not find the MySQL type for {$table}.{$column} — if the column was renamed, "
                . 'this list is stale and the clamp beside it may be guarding nothing');

            $expected = $this->ceilings()[$type] ?? null;
            $this->assertNotNull($expected, "unmapped column type {$type} on {$table}.{$column}");

            $named = (int) constant('AfricaGates\\Support\\' . str_replace('ColumnRange::', 'ColumnRange::', $constant));
            $this->assertSame($expected, $named,
                "{$table}.{$column} is {$type}, but its writer clamps to {$constant} — the schema and "
                . 'the clamp have drifted apart, which is exactly the gap this guards');

            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $writer);
            $this->assertStringContainsString($constant, $src,
                "{$writer} no longer names {$constant} for {$table}.{$column}");
        }
    }

    /**
     * And the writers do not go back to being lower-bounded only. That is the exact shape
     * both fields had, and it reads as careful code — `max(1, …)` looks like validation.
     */
    public function test_the_discount_writers_are_not_lower_bounded_only(): void
    {
        foreach (['src/Admin/Controllers/ShopController.php',
                  'src/Admin/Controllers/EventsController.php'] as $rel) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);

            foreach (['max_per_email', 'max_uses'] as $field) {
                $this->assertDoesNotMatchRegularExpression(
                    "/'{$field}'\s*=>[^,\n]*\bmax\(\s*\d+\s*,\s*\(int\)/",
                    $src,
                    "{$rel} writes {$field} with a floor and no ceiling — that fits in SQLite and is "
                    . 'an out-of-range write on MySQL');
            }
        }
    }
}
