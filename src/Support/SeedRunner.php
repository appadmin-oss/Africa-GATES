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

    /** The last outcome of a seed that is not done yet: JSON {status, note, at}. */
    private const LAST_PREFIX = 'seed_last_';

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

        $result = self::attempt($seed);
        self::remember($name, $result);

        return $result;
    }

    /**
     * The seed itself, classified.
     *
     * Split out of `run()` so that every exit — waiting, failed, done — passes through
     * `remember()` on the way back. When the outcome was only RETURNED, the one place a
     * waiting seed said why was the migration's output, read once by whoever opened that
     * URL, and the maintenance log; on a host with no shell that is nowhere. A seed could
     * sit "not yet" for a fortnight past the date its challenge opened, with the reason
     * — usually one an operator could fix in a minute — on no screen at all.
     *
     * @return array{status:string, note:string}
     */
    private static function attempt(callable $seed): array
    {
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

    /**
     * Every seed, with what is actually known about it — for the operator's screen.
     *
     * This used to answer `pending — "not applied to this database"` for anything not
     * done, and had no caller. Both halves were the fault: a seed that is waiting has a
     * REASON, the reason is nearly always one an operator can act on ("no edition is open
     * for nominations"), and a status that says only "pending" is a status that sends
     * somebody to a shell this host does not have.
     *
     * @return array<string,array{status:string, note:string, at:?string}>
     *         status: done | waiting | failed | pending (never attempted on this database)
     */
    public static function status(): array
    {
        $out = [];

        foreach (self::names() as $name) {
            if (self::done($name)) {
                $out[$name] = ['status' => 'done', 'note' => 'applied',
                               'at' => self::setting(self::DONE_PREFIX . $name)];
                continue;
            }
            $last = json_decode((string) self::setting(self::LAST_PREFIX . $name), true);
            $out[$name] = is_array($last) && isset($last['status'])
                ? ['status' => (string) $last['status'], 'note' => (string) ($last['note'] ?? ''),
                   'at' => isset($last['at']) ? (string) $last['at'] : null]
                : ['status' => 'pending', 'note' => 'not attempted on this database yet', 'at' => null];
        }

        return $out;
    }

    /** True when at least one seed is not applied — the screen only draws when it is. */
    public static function anyOutstanding(): bool
    {
        foreach (self::names() as $name) {
            if (!self::done($name)) return true;
        }
        return false;
    }

    /**
     * Keep the last outcome. A `done` marks the seed applied; anything else is stored
     * so `status()` can say why — and is overwritten on the next attempt, because a
     * reason from three weeks ago describes a database that no longer exists.
     *
     * @param array{status:string, note:string} $result
     */
    private static function remember(string $name, array $result): void
    {
        if ($result['status'] === 'done') {
            self::mark($name);
        }
        self::put(self::LAST_PREFIX . $name, (string) json_encode([
            'status' => $result['status'],
            // The column is TEXT, but a PDO message can carry a whole statement and its
            // bound values; this is a sentence for a screen, not a dump.
            'note'   => mb_substr($result['note'], 0, 500),
            'at'     => Carbon::now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function setting(string $key): ?string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return $v === null ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function mark(string $name): void
    {
        // A seed that applied and could not record it will be attempted again, and every
        // seed here is an upsert — so the cost of a failed write is a wasted query, not a
        // double write. Swallowing it (in `put()`) is safe exactly because of that
        // property, and it is the reason the property is required rather than encouraged.
        self::put(self::DONE_PREFIX . $name, Carbon::now()->toDateTimeString());
    }

    private static function put(string $key, string $value): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(
                ['key_name' => $key],
                [
                    'value'      => $value,
                    // Explicit: SQLite's copy of this table has no `ON UPDATE
                    // CURRENT_TIMESTAMP`, so a row written without it on dev carries a
                    // different stamp from the same row on production.
                    'updated_at' => Carbon::now()->toDateTimeString(),
                    // Left NULL deliberately. `updated_by IS NULL` is this platform's
                    // mark of a row no operator touched — the cookie-policy repair keys
                    // off exactly that — and a seed is not an operator.
                ]
            );
        } catch (\Throwable) {
        }
    }
}
