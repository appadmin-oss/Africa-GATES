<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * "Write this column only if the database actually has it."
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 *
 * This platform deploys to shared cPanel hosting where migrations are applied by
 * hand — by an operator running `db:migrate`, or by opening `/__setup/migrate`. That
 * is not reliable, and the console says so out loud: the admin layout carries a
 * banner counting migration steps "not yet recorded as applied", which has been in
 * the dozens on a live site.
 *
 * So the schema the code is written against and the schema the code RUNS against are
 * routinely different, and the gap is not a rare edge case — it is the normal state
 * of a deployment between a `git pull` and someone remembering to migrate.
 *
 * ── THE BUG THIS IS A FIX FOR ────────────────────────────────────────────────
 *
 * Adding `show_name` to the paid-vote flow broke paid voting completely on exactly
 * such a database. Reading the column was written defensively (`$don->show_name ?? 0`
 * — a missing column yields null, which means "private", the safe direction). WRITING
 * it was not:
 *
 *   • the checkout INSERT into `gates_donations` threw, so the order was never
 *     created and the buyer got a generic error instead of a gateway — nobody could
 *     pay at all;
 *   • `mint()`'s INSERT into `gates_votes` threw, so a payment that HAD completed
 *     never turned into votes — the worst of the two, because the money was taken.
 *
 * Both were inside a `try`, so neither surfaced as anything an operator could act on.
 * The lesson is narrow and worth stating: a nullable read degrades on its own, a
 * write never does.
 *
 * ── SO: OMIT, DON'T FAIL ─────────────────────────────────────────────────────
 *
 * When the column is absent the key is dropped from the row and the insert succeeds
 * without it. The feature that column powers is silently unavailable until someone
 * migrates — which is right, because a supporters list that is missing a name is a
 * cosmetic loss, and a checkout that cannot take money is not.
 *
 * This is only for columns whose absence is SURVIVABLE. A column the record would be
 * meaningless without should fail loudly instead; that is a broken deployment, not a
 * degraded one.
 */
final class OptionalColumn
{
    /**
     * @var array<string,bool> per-process memo. The schema cannot change inside one
     *      request, and `hasColumn()` is a real query on every driver — on the paid
     *      path that would be two extra round trips per checkout.
     */
    private static array $memo = [];

    /** Does $table have $col? False on any error, so the caller degrades rather than throws. */
    public static function on(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (isset(self::$memo[$k])) return self::$memo[$k];
        try {
            return self::$memo[$k] = DB::schema()->hasColumn($table, $col);
        } catch (\Throwable) {
            // NOT memoised: a transient driver failure must not pin this to false for
            // the rest of the process.
            return false;
        }
    }

    /**
     * Drop any key from $row whose column $table does not have.
     *
     * The row is written as one literal at the call site — including the optional
     * keys, so the intent stays readable — and filtered on the way out.
     *
     * @param array<string,mixed> $row
     * @param list<string>        $optional keys that may be filtered out; every other
     *                                      key is passed through untouched, so a typo
     *                                      in a REQUIRED column still fails loudly
     * @return array<string,mixed>
     */
    public static function filter(string $table, array $row, array $optional): array
    {
        foreach ($optional as $col) {
            if (array_key_exists($col, $row) && !self::on($table, $col)) {
                unset($row[$col]);
            }
        }
        return $row;
    }

    /** Test seam: forget what we learned about the schema. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
