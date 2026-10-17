<?php
declare(strict_types=1);

use AfricaGates\Support\SchemaIndex;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A NOMINATION NAMES TWO OR THREE CATEGORIES, EACH WITH ITS OWN REASON, AND KEEPS
 * THE EVIDENCE IT WAS GIVEN.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE, AND WHAT IT COST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_nominations` carried ONE nullable `category_id` and ONE `reason`. A nominator
 * who thought somebody belonged in two categories had to pick one and hope, and a panel
 * later reading the row could not tell which categories had been considered at all.
 *
 * The evidence was worse, and it is the reason this migration exists rather than a
 * column being widened. `NominationController::submit()` accepted a supporting document,
 * uploaded it, and then put its URL **into a string in an email to operators**. There is
 * no column for it, nothing on the review desk shows it, and the AI triage — whose whole
 * job is to help a moderator judge a nomination — has never seen one. The file is stored
 * on disk and reachable only by whoever still has the alert in their inbox. That is this
 * repository's §17 shape with the polarity reversed: not a declared field with no reader,
 * but a READER with no field.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE THINGS THIS DOES, AND WHY EACH IS ITS OWN TABLE OR COLUMN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 1. `gates_nominations.nominee_kind` — person | organisation | business.
 *
 *    A COLUMN and not a lookup, because it is one of three fixed values that the form
 *    branches on, the award's accepted-types list filters on, and the AI reads. The
 *    three words are declared once in `Support\NomineeKind` and every writer takes them
 *    from there: an ENUM whose values are typed in two places is how
 *    `gates_event_invites.audience` shipped with `'principal','child','judge'` and was
 *    corrected one commit later to a set production never received.
 *
 * 2. `gates_nomination_categories` — the 2-to-3 categories, each with its reason.
 *
 *    A table, because the count is variable and each row carries its own text. The
 *    UNIQUE on (nomination_id, category_id) is the rule "you cannot name the same
 *    category twice" expressed where it cannot be forgotten, rather than in a validator
 *    the next door skips — and this codebase has TWO nomination doors that have already
 *    diverged once on exactly that kind of rule.
 *
 * 3. `gates_nomination_evidence` — links and files, one row each.
 *
 *    A table rather than three more `reference_url_N` columns. The existing three are
 *    the argument: they cap the nominator at three links, they cannot hold a file, and
 *    `NominationTriageService` reads them only as a COUNT (`(int) !empty($nom->reference_url)
 *    + …`) because there is nowhere to put what they are. Rows carry a kind, a label and
 *    a size, so a panel can see what was offered and the AI can read it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * `reason` STAYS, AND IT IS A DENORMALISED COPY WITH ONE WRITER
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * About twenty readers — the review desk, the AI triage, the aftercare mail, the
 * moderation log, the approval path that seeds `gates_nominees.story` — take the
 * nomination's reason from `gates_nominations.reason`. Emptying it would blank all of
 * them on the same deploy.
 *
 * So it keeps holding the PRIMARY category's reason, written only by the submit service,
 * and `gates_nomination_categories` is authoritative for every reason including that one.
 * That is the `vote_count` / `gates_votes` arrangement, and this repository has a scar
 * from it: five suites wrote the counter and no ballot rows and scored a different rule
 * for years. So `NominationReasons` is the one reader, it reads the TABLE, and
 * `NominationCategoriesTest` asserts the copy still matches the row it was copied from.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BACK-FILL IS THE POINT OF RUNNING THIS ON A LIVE DATABASE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every existing nomination with a category becomes one row in the new table, carrying
 * its existing reason. Without it, the review desk and the judging stage would show
 * "no categories" for every nomination ever submitted — the new code reading the new
 * table, and the old rows living in the old columns. A migration that leaves the
 * previous data unreachable is a migration that deleted it.
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1. nominee_kind ─────────────────────────────────────────────────────────

if (DB::schema()->hasTable('gates_nominations')
    && !DB::schema()->hasColumn('gates_nominations', 'nominee_kind')) {
    // 'person' as the default, because that is what every existing row is: the form
    // has only ever asked for a person's full name and offered an optional
    // organisation field beside it. Back-filling anything else would be a guess
    // recorded as a fact.
    DB::statement($sqlite
        ? "ALTER TABLE gates_nominations ADD COLUMN nominee_kind TEXT NOT NULL DEFAULT 'person'"
        : "ALTER TABLE gates_nominations ADD COLUMN nominee_kind
             ENUM('person','organisation','business') NOT NULL DEFAULT 'person'
             AFTER nominee_name");
    echo "  + gates_nominations.nominee_kind\n";
}

// ── 2. the categories and their reasons ─────────────────────────────────────

if (!DB::schema()->hasTable('gates_nomination_categories')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nomination_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nomination_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            -- The nominator's case for THIS category. >= 40 characters, enforced by
            -- Services\\NominationRules, which both doors call.
            reason TEXT NOT NULL,
            -- 0 is the primary category: the one `gates_nominations.category_id` and
            -- `.reason` carry a copy of, and the one a single-category screen shows.
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (nomination_id, category_id)
        )" : "
        CREATE TABLE gates_nomination_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nomination_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            reason TEXT NOT NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_nomcat (nomination_id, category_id),
            KEY idx_nomcat_cat (category_id),
            CONSTRAINT fk_nomcat_nom FOREIGN KEY(nomination_id)
                REFERENCES gates_nominations(id) ON DELETE CASCADE,
            CONSTRAINT fk_nomcat_cat FOREIGN KEY(category_id)
                REFERENCES gates_award_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_nomination_categories\n";
}

// SQLite got its UNIQUE inline; the secondary index is the one both drivers need and
// `SchemaIndex::ensure()` is how it is written — a raw `CREATE INDEX IF NOT EXISTS`
// here is a 1064 on MySQL, and MigrateCommand does not record a file that threw, so
// the statement above it would re-run on every deploy for ever.
SchemaIndex::ensure('gates_nomination_categories', 'idx_nomcat_nom', ['nomination_id']);

// ── 3. the evidence ─────────────────────────────────────────────────────────

if (!DB::schema()->hasTable('gates_nomination_evidence')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nomination_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nomination_id INTEGER NOT NULL,
            -- 'link' | 'file'. Support\\EvidenceKind holds both words.
            kind TEXT NOT NULL DEFAULT 'link',
            -- A link's address, or a stored file's public path. One of the two is set
            -- and the other is null; `kind` says which, so nothing has to guess from
            -- the shape of a string.
            url TEXT NULL,
            path TEXT NULL,
            label TEXT NULL,
            mime TEXT NULL,
            bytes INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )" : "
        CREATE TABLE gates_nomination_evidence (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nomination_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('link','file') NOT NULL DEFAULT 'link',
            url VARCHAR(600) NULL,
            path VARCHAR(400) NULL,
            label VARCHAR(200) NULL,
            mime VARCHAR(100) NULL,
            -- INT UNSIGNED, not the TINYINT this codebase has been bitten by: the cap
            -- is 10 MB, which is 10,485,760 — four orders of magnitude past 255.
            bytes INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            CONSTRAINT fk_nomev_nom FOREIGN KEY(nomination_id)
                REFERENCES gates_nominations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_nomination_evidence\n";
}

SchemaIndex::ensure('gates_nomination_evidence', 'idx_nomev_nom', ['nomination_id']);

// ── 3b. what the analysis thinks of each category ───────────────────────────
//
// A SEPARATE TABLE and not two more columns on gates_nomination_categories, for a
// reason that only shows up once you ask what the analysis is for: it does not only
// score the categories the nominator chose, it says which category the work actually
// fits — and that is very often one they did not choose. There is no row on their
// table to put that on.
//
// Keeping it apart also keeps the two kinds of writing distinguishable on the screen
// that matters. `gates_name_says.source` is the scar here: three paths wrote where a
// respelling came from, and the settings screen labelled every row "worked out"
// because it derived the answer from WHERE IT LOOKED rather than from what was
// stored — so a model's guess and a person's decision were presented as the same
// kind of answer, on the screen whose job is deciding which to trust. A nominator's
// reason and a model's opinion of it are not the same kind of answer either.
if (!DB::schema()->hasTable('gates_nomination_category_fit')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_nomination_category_fit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nomination_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL,
            -- 0-100, advisory. NEVER a decision: a panel scores, this ranks a queue.
            fit INTEGER NULL,
            -- Was this one the nominator chose, or one the analysis suggested?
            chosen INTEGER NOT NULL DEFAULT 0,
            note TEXT NULL,
            model TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (nomination_id, category_id)
        )" : "
        CREATE TABLE gates_nomination_category_fit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nomination_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            -- TINYINT UNSIGNED holds 0-100 exactly, and 100 is the ceiling this
            -- column can ever carry. Stated because the last TINYINT in this
            -- codebase met a 9800 and stored 255.
            fit TINYINT UNSIGNED NULL,
            chosen TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(600) NULL,
            model VARCHAR(40) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_nomfit (nomination_id, category_id),
            CONSTRAINT fk_nomfit_nom FOREIGN KEY(nomination_id)
                REFERENCES gates_nominations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_nomination_category_fit\n";
}

SchemaIndex::ensure('gates_nomination_category_fit', 'idx_nomfit_nom', ['nomination_id']);

// ── 4. the award's own wording ──────────────────────────────────────────────

if (DB::schema()->hasTable('gates_award_programmes')
    && !DB::schema()->hasColumn('gates_award_programmes', 'wording_json')) {
    // ONE JSON DOCUMENT, for the reason `gates_partner_orgs.brand_json` is one: every
    // value in it is read once per page for one programme already loaded by id or
    // slug, and nothing filters or sorts on any of it. A new phrase therefore needs
    // no migration.
    //
    // And the column is the constraint, which is the other half of that lesson: TEXT
    // is 65,535 BYTES on MySQL while the per-field caps count CHARACTERS, so
    // `Support\AwardWording::save()` refuses above its own byte limit rather than
    // letting strict mode throw or a lenient host truncate — a truncated JSON
    // document does not parse, and the whole award would silently revert to the
    // house wording.
    DB::statement($sqlite
        ? "ALTER TABLE gates_award_programmes ADD COLUMN wording_json TEXT NULL"
        : "ALTER TABLE gates_award_programmes ADD COLUMN wording_json TEXT NULL AFTER terms");
    echo "  + gates_award_programmes.wording_json\n";
}

// ── 5. the back-fill ────────────────────────────────────────────────────────

$moved = 0;
if (DB::schema()->hasTable('gates_nomination_categories')) {
    // Chunked, because this is every nomination the platform has ever taken and a
    // single `get()` on a large table is how a migration runs a host out of memory
    // halfway through — which, since MigrateCommand does not record a file that
    // threw, would re-run this from the start on the next deploy.
    DB::table('gates_nominations')
        ->whereNotNull('category_id')
        ->orderBy('id')
        ->chunkById(500, function ($rows) use (&$moved): void {
            $now  = \Illuminate\Support\Carbon::now()->toDateTimeString();
            $rowsToInsert = [];

            // ONE QUERY PER CHUNK, not one per row. Idempotence is the requirement —
            // a re-run after a partial failure must not duplicate, and the UNIQUE
            // would otherwise abort the whole chunk — but asking it row by row makes
            // this migration cost one round trip per nomination the platform has ever
            // taken. There is no shell on production: migrations are applied by an
            // operator opening a URL, so that cost is paid inside a web request that
            // can time out. `MigrateCommand` does not record a file that threw, so a
            // timeout halfway leaves the file to re-run from the start on the next
            // deploy — and a back-fill that cannot finish in one request never
            // finishes at all.
            //
            // `whereIn` over the chunk's own ids, never `where($col, $array)`: two
            // arguments mean `= $array`, the driver reads the array's FIRST element,
            // and the query runs, under-selects and says nothing.
            $ids  = array_map(static fn ($n) => $n->id, $rows->all());
            $seen = [];
            foreach (DB::table('gates_nomination_categories')
                        ->whereIn('nomination_id', $ids)
                        ->get(['nomination_id', 'category_id']) as $have) {
                $seen[$have->nomination_id . ':' . $have->category_id] = true;
            }

            foreach ($rows as $n) {
                if (isset($seen[$n->id . ':' . $n->category_id])) continue;

                // A reason is NOT NULL here and some historic rows have an empty one —
                // the >= 40 rule is new. They are carried across as they are rather than
                // padded: a placeholder would read, to a panel, as something the
                // nominator wrote.
                $rowsToInsert[] = [
                    'nomination_id' => $n->id,
                    'category_id'   => $n->category_id,
                    'reason'        => (string) ($n->reason ?? ''),
                    'sort_order'    => 0,
                    'created_at'    => $n->created_at ?? $now,
                ];
            }

            if ($rowsToInsert !== []) {
                DB::table('gates_nomination_categories')->insert($rowsToInsert);
                $moved += count($rowsToInsert);
            }
        });
}

echo "  · {$moved} existing nomination(s) carried into gates_nomination_categories\n";
echo "nomination categories and evidence OK\n";
