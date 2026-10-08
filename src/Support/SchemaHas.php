<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * "Does this database actually have that table / column?" — asked once per process.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE QUESTION IS ASKED AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This platform deploys to shared hosting with no shell, where migrations are applied by
 * an operator running `db:migrate` or opening `/__setup/migrate`. The admin layout carries
 * a banner counting steps "not yet recorded as applied", and on a live site that count has
 * been in the dozens. So the schema the code is WRITTEN against and the schema it RUNS
 * against are routinely different, and the gap is the normal state of a deployment between
 * a pull and somebody remembering to migrate. {@see OptionalColumn} is the writing half of
 * the same problem; this is the asking half.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY IT IS ONE CLASS AND NOT A PRIVATE HELPER PER SERVICE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It was three: `MergeService::hasCol()`, `MergeJournal::hasCol()` and
 * `AnalyticsService::hasCol()`, all the same four lines — and they had diverged on the one
 * thing that matters. The two merge helpers memoise; the analytics one did not, and the
 * analytics dashboard asks twenty-four of these questions per render.
 *
 * `DB::schema()->hasColumn()` is not free. It fetches the table's column listing every
 * call — `information_schema.columns` on MySQL — so those twenty-four were twenty-four
 * extra round trips on one admin page, on every load, for the life of the screen. Nothing
 * about that is visible: the page renders, the figures are right, and it is simply slower
 * than it looks, which is the shape of fault nobody ever files.
 *
 * ── AND `false` IS NEVER MEMOISED FROM A THROW ──────────────────────────────
 *
 * A transient failure — a dropped connection, a permissions blip — must not be recorded as
 * "this column does not exist" for the rest of the process. On a merge that would silently
 * skip a table's reassignment and journal nothing for it, which is a partial merge that
 * reports success. The catch returns false and remembers nothing.
 */
final class SchemaHas
{
    /** @var array<string,bool> */
    private static array $memo = [];

    /** Is $col present on $table? False when the schema cannot be read at all. */
    public static function column(string $table, string $col): bool
    {
        $k = 'c:' . $table . '.' . $col;
        if (isset(self::$memo[$k])) return self::$memo[$k];

        try { return self::$memo[$k] = DB::schema()->hasColumn($table, $col); }
        catch (\Throwable) { return false; }
    }

    /** Is $table present? False when the schema cannot be read at all. */
    public static function table(string $table): bool
    {
        $k = 't:' . $table;
        if (isset(self::$memo[$k])) return self::$memo[$k];

        try { return self::$memo[$k] = DB::schema()->hasTable($table); }
        catch (\Throwable) { return false; }
    }

    /**
     * Drop what has been remembered.
     *
     * The schema is stable within a request, which is what makes the memo safe — but not
     * within a MIGRATION run, where the whole point is that it changes. Nothing in
     * `database/migrations/` uses this class (they ask `Schema` directly, and must keep
     * doing so); this exists so a test that adds a column mid-process can say so rather
     * than inherit an answer from before.
     */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
