<?php
/**
 * How a name is said, worked out once and kept.
 *
 * ── WHY THIS IS A TABLE AND NOT A SETTING ───────────────────────────────────
 *
 * The door could already be told how to say a name: `door_welcome_says` in `gates_settings`
 * is a `Written = Spoken` list an operator types. It is the right mechanism and it was the
 * wrong ONLY mechanism — somebody had to sit down and write an entry for every guest,
 * having no idea which of three hundred names the voice would get wrong. So it stayed
 * empty, and every Nigerian name was read by English rules: silent finals, long and short
 * vowels, schwa in the unstressed syllables. Ngozi came out of an English voice as
 * something no Igbo speaker would answer to.
 *
 * This is where the platform keeps what it has worked out ITSELF, so the operator's list
 * goes back to being what it should have been — the place you correct the handful it got
 * wrong, not the place you teach it everything from nothing.
 *
 * A row per NAME rather than per guest: a gala with nine Ngozis is one row and one lookup,
 * and the same row serves next year's gala without asking again.
 *
 * ── THE KEY IS FOLDED ───────────────────────────────────────────────────────
 *
 * `name_key` is `DoorWelcome::fold()` of the written form: tone marks and sub-dots
 * stripped, lower-cased, letters only. Most names arrive with no marks at all and a few
 * arrive with all of them, and they are the same person — a key that told them apart would
 * ask twice and store two answers for one name.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (DB::schema()->hasTable('gates_name_says')) {
    echo "  = gates_name_says present\n";
    return;
}

DB::statement($sqlite ? <<<'SQL'
    CREATE TABLE IF NOT EXISTS gates_name_says (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name_key TEXT NOT NULL,
      written  TEXT NOT NULL,
      said     TEXT NOT NULL,
      source   TEXT NOT NULL DEFAULT 'rule',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
SQL : <<<'SQL'
    CREATE TABLE IF NOT EXISTS gates_name_says (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      /* The folded key. 190 so the UNIQUE index fits in InnoDB's 767-byte prefix
         under utf8mb4 — a name longer than that is not a name. */
      name_key VARCHAR(190) NOT NULL,
      written  VARCHAR(80)  NOT NULL,
      /* Long enough for a respelling of a long name, and no longer: this is read
         aloud, and AzureVoice caps the whole line at 240 characters anyway. */
      said     VARCHAR(80)  NOT NULL,
      /* Which of them said so. `rule` is the offline respelling, `ai` is a model
         that was asked, `hand` is a person — kept apart so the admin screen can
         show where an answer came from, and so a bad batch can be cleared
         without touching anything a person wrote. */
      source   ENUM('rule','ai','hand') NOT NULL DEFAULT 'rule',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

// UNIQUE, because the whole point is asking once. Two rows for one name would make the
// answer depend on which the reader happened to find first.
DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_name_says ON gates_name_says (name_key)');

echo "  + gates_name_says created\n";
