<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE ONE DOOR A SEED CAN COME THROUGH ON A HOST WITH NO SHELL.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A SEED NEEDS A RUNNER AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `database/seeds/*.php` each return a closure taking a PDO. The October handoff's own
 * instruction is to run one from a command line — "then on prod MySQL" — and there is no
 * command line on production. There never has been: the whole of `Maintenance` exists
 * because of it, and `MigrateCommand` is reached by an operator opening a URL.
 *
 * So a seed with no runner is this codebase's oldest fault, the one CLAUDE.md names over
 * and over: a mechanism that is complete and correct on every side and has no route in.
 * The Chrome extension, `votes:recover`, the donor's own cancellation link — each worked
 * perfectly and could not be reached. A seed that can only run on a developer's laptop
 * seeds a developer's laptop.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * "WAITING" IS NOT "FAILED", AND THE DIFFERENCE IS THE WHOLE DESIGN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Celebrate Nigeria seed refuses to run until an operator has created the Alimosho
 * Awards and opened an edition — deliberately, because a seed that invents an award
 * accepts real nominations into something nobody announced. That refusal is a
 * `RuntimeException`, and it will be thrown on every deploy until the operator does their
 * part, which may be days.
 *
 * If that counted as a failure, two things would break and neither would look like this:
 *
 *   · the migration that runs the seed would THROW, and `MigrateCommand` does not record
 *     a file that threw — so it re-runs next deploy, and because its own guards are now
 *     true, EVERY MIGRATION DATED AFTER IT NEVER APPLIES. That is a shipped fault in this
 *     repo's history, three times over, and it is why this never throws upward.
 *   · `/__cron/run` would answer `ok:false` for ever, and the webcron services that drive
 *     it react to a persistent failure by DISABLING THE JOB — which stops the tasks that
 *     were working.
 *
 * So a `RuntimeException` out of a seed means "not yet, ask again", and anything else is
 * a real failure that is reported as one. The distinction is on the EXCEPTION TYPE
 * rather than on a message, because a message is a sentence somebody will reword.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT ASKS AGAIN, WHICH IS WHY THE MIGRATION IS NOT THE ONLY CALLER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A migration runs once. If the award was not ready at that moment, a migration-only
 * route means the seed never runs at all and nobody is told — the failure is a challenge
 * that simply is not there, with a green deploy behind it. `Maintenance`'s `seeds` task
 * calls {@see sweep()} on every tick, so the seed lands within the hour of the operator
 * finishing their part, and `/__cron/run?task=seeds` makes it immediate for somebody who
 * has just finished and does not want to wait.
 */
final class SeedRunner
{
    /** Where the seed files live. One directory, no nesting, no runner config. */
    public const DIR = __DIR__ . '/../../database/seeds';

    /**
     * The settings key that records a seed as applied.
     *
     * In `gates_settings` and not a table of its own: there are fewer than ten of these
     * for the life of the platform, and a table would be a migration, an admin screen and
     * a backup concern for a handful of booleans. The prefix is what makes them findable.
     */
    private const DONE_PREFIX = 'seed_ran_';

    /** @return list<string> every seed's name, in filename order, which is date order */
    public static function names(): array
    {
        $out = [];

        foreach (glob(self::DIR . '/*.php') ?: [] as $path) {
            $out[] = basename($path, '.php');
        }

        sort($out);

        return $out;
    }

    /** Has this seed already been applied to THIS database? */
    public static function done(string $name): bool
    {
        try {
            return DB::table('gates_settings')
                ->where('key_name', self::DONE_PREFIX . $name)
                ->exists();
        } catch (\Throwable $e) {
            // No settings table yet means no seed has ever run here.
            return false;
        }
    }

    /**
     * Run one seed.
     *
     * @return array{status:string, note:string} status is 'done', 'skipped', 'waiting'
     *                                           or 'failed' — never a bare boolean,
     *                                           because 'waiting' and 'failed' are the
     *                                           two the caller must tell apart.
     */
    public static function run(string $name, bool $force = false): array
    {
        $path = self::DIR . '/' . $name . '.php';

        if (!is_file($path)) {
            return ['status' => 'failed', 'note' => 'no such seed: ' . $name];
        }

        if (!$force && self::done($name)) {
            return ['status' => 'skipped', 'note' => 'already applied'];
        }

        /** @var mixed $seed */
        $seed = require $path;

        if (!is_callable($seed)) {
            return ['status' => 'failed', 'note' => $name . ' does not return a callable'];
        }

        try {
            $pdo = DB::connection()->getPdo();

            // ── NO REFUSAL ON AN OPEN TRANSACTION, AND THAT WAS A FINDING ───────
            //
            // This used to refuse outright when `$pdo->inTransaction()`, reasoning that a
            // nested `beginTransaction()` is a PDO fatal. The reasoning was right and the
            // remedy was in the wrong place: the test harness opens a transaction per
            // case on MySQL, so the refusal made the seed unrunnable from any test there
            // — eleven cases red on the parity run, every one of them green on SQLite,
            // where that harness opens none.
            //
            // A seed now owns its transaction only when there is not one already (see the
            // note in `2026_10_01_celebrate_nigeria.php`). That keeps atomicity on the
            // standalone run, which is the one that reaches production, and nests safely
            // under a caller that has its own.
            $seed($pdo);
        } catch (\PDOException $e) {
            // ── CAUGHT BEFORE `RuntimeException`, AND THE ORDER IS THE BUG FIX ───────
            //
            // `QueryException extends PDOException extends RuntimeException`, so a
            // single `catch (RuntimeException)` swallows every database failure as "not
            // yet, ask again". Measured: pointing this at an unreachable server reported
            // `waiting — SQLSTATE[HY000] [2002] Connection refused`, and a sweep built on
            // that reports a healthy platform for ever while the seed never lands.
            //
            // A seed's own refusal is a bare `RuntimeException` thrown by the seed; a
            // broken connection, a missing column and a constraint violation are all
            // PDOExceptions and are failures. The two are told apart by CLASS, never by
            // reading the message: a message is a sentence somebody will reword.
            return ['status' => 'failed', 'note' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            // Not yet. See the class docblock: this is the seed saying an operator has
            // not done their part, and it must not be recorded, must not throw, and must
            // not be counted as a failure anywhere.
            return ['status' => 'waiting', 'note' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'note' => $e->getMessage()];
        }

        self::mark($name);

        return ['status' => 'done', 'note' => 'applied'];
    }

    /**
     * Every unapplied seed, in order.
     *
     * Returns the number APPLIED on this pass, so `Maintenance` reports 0 — "ran, nothing
     * to do" — on the ordinary tick where everything is already in. A seed that is still
     * waiting is not an error and is not counted.
     *
     * @return int
     */
    public static function sweep(): int
    {
        $n = 0;

        foreach (self::names() as $name) {
            if (self::done($name)) {
                continue;
            }

            if (self::run($name)['status'] === 'done') {
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string,array{status:string,note:string}> for the operator's screen */
    public static function status(): array
    {
        $out = [];

        foreach (self::names() as $name) {
            $out[$name] = self::done($name)
                ? ['status' => 'done', 'note' => 'applied']
                : ['status' => 'pending', 'note' => 'not applied to this database'];
        }

        return $out;
    }

    private static function mark(string $name): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => self::DONE_PREFIX . $name],
                [
                    'value'      => Carbon::now()->toDateTimeString(),
                    // Explicit: SQLite's copy of this table has no `ON UPDATE
                    // CURRENT_TIMESTAMP`, so a row written without it on dev carries a
                    // different stamp from the same row on production.
                    'updated_at' => Carbon::now()->toDateTimeString(),
                    // Left NULL deliberately. `updated_by IS NULL` is this platform's
                    // mark of a row no operator touched — the cookie-policy repair keys
                    // off exactly that — and a seed is not an operator.
                ]
            );
        } catch (\Throwable $e) {
            // A seed that applied and could not record it will be attempted again, and
            // every seed here is an upsert — so the cost is a wasted query, not a double
            // write. Swallowing this is safe exactly because of that property, and it is
            // the reason the property is required rather than merely encouraged.
        }
    }
}
