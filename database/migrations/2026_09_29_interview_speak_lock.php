<?php
/**
 * THE BOT'S TURN TO SPEAK, CLAIMED ATOMICALLY.
 *
 * ── THE RACE THIS CLOSES ─────────────────────────────────────────────────────
 *
 * {@see \AfricaGates\Services\InterviewBot::poll()} has two callers and they are not
 * coordinated: the cron sweep on every tick, and Attendee's webhook the moment a nominee
 * stops speaking. That is by design — the webhook makes 'auto' mode fast and the sweep
 * makes it reliable — and it means both can be inside `turn()` for the same sitting at
 * the same moment.
 *
 * The pacing that stops the bot talking over itself lived in `live_meta`, a JSON column,
 * read and rewritten in PHP. Two processes read "last spoke: 40 seconds ago", both decide
 * they may speak, and both do. In a live interview that is the bot asking two questions
 * over each other while a nominee tries to answer, and the second write silently discards
 * the first's counter.
 *
 * A JSON blob cannot be compare-and-swapped portably. Two plain columns can, in one
 * statement the database serialises for us:
 *
 *     UPDATE gates_interviews
 *        SET bot_speaking_at = :now, bot_said_count = bot_said_count + 1
 *      WHERE id = :id
 *        AND bot_said_count < :cap
 *        AND (bot_speaking_at IS NULL OR bot_speaking_at < :gap_ago)
 *
 * Affected rows is the answer. One process gets 1 and speaks; the other gets 0 and stays
 * quiet. This is the same optimistic-claim shape {@see \AfricaGates\Services\QueueService}
 * uses to stop two workers running one job, for the same reason.
 *
 * ── ONE COLUMN, TWO JOBS, AND THAT IS DELIBERATE ─────────────────────────────
 *
 * `bot_speaking_at` is both the lock and the cooldown stamp. Keeping them apart would mean
 * releasing the lock on success and then separately recording when the utterance happened
 * — two writes, and a crash between them leaves a sitting either permanently locked or
 * with no gap enforced. Because the claim window IS the minimum gap, a process that dies
 * mid-utterance self-heals after the gap expires and nothing has to be released.
 *
 * ── AND WHY THE COUNTER LEFT THE JSON ────────────────────────────────────────
 *
 * The per-sitting utterance cap is what bounds a stuck 'auto' loop. A cap enforced by a
 * counter that can be lost to a concurrent write is not a cap. Moving it into the same
 * atomic statement makes it one — and it is `INT NOT NULL DEFAULT 0`, so every existing
 * sitting starts from zero rather than from null arithmetic.
 */
require __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$pdo    = DB::connection()->getPdo();

/** Add a column only if it is missing, so a re-run is a no-op on both engines. */
$add = static function (string $table, string $column, string $sqliteType, string $mysqlType)
    use ($sqlite, $pdo): void {
    try {
        if (DB::schema()->hasColumn($table, $column)) return;
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} " . ($sqlite ? $sqliteType : $mysqlType));
    } catch (\Throwable $e) {
        echo "*** could not add {$table}.{$column}: {$e->getMessage()}\n";
    }
};

// When the current — or last — utterance was claimed. Null means the bot has never
// spoken in this sitting.
$add('gates_interviews', 'bot_speaking_at', 'TEXT', 'TIMESTAMP NULL DEFAULT NULL');

// How many times it has spoken. NOT NULL DEFAULT 0 so the atomic increment never has to
// reason about null, and so every sitting that predates this feature starts at zero.
$add('gates_interviews', 'bot_said_count', 'INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0');

echo "  interview speak lock: ready\n";
