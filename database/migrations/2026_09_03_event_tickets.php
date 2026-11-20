<?php
/**
 * Ticket tiers a person can actually buy, each with its own attendee limit.
 *
 * ── WHAT WAS THERE, AND WHY IT COULD NOT DO THIS ─────────────────────────────
 *
 * `gates_site_events` carried three columns for ticketing: `capacity` (one number for
 * the whole event), `price_naira` (one price), and `ticket_tiers` — a JSON blob the
 * admin screen wrote and the detail page printed. Nothing read a price out of that
 * blob to charge anybody, because nothing charged anybody at all: registration was a
 * free RSVP that inserted a name and an email.
 *
 * So "we cannot set an attendee limit per pricing tier" was exactly right, and it was
 * not a missing input on a form. A tier was a paragraph of display text. There was
 * nowhere for a limit to live, nothing counting against it, and no purchase to count.
 *
 * The registrations table has carried `amount_naira`, `reference` and `tier` since it
 * was created and NOTHING HAS EVER WRITTEN THEM. The same shape this codebase keeps
 * finding: three columns describing a feature nobody built.
 *
 * ── WHY TIERS BECOME ROWS ────────────────────────────────────────────────────
 *
 * A per-tier limit is a COUNT against a total, and counting rows against a number
 * inside a JSON blob means reading every registration into PHP, decoding the blob, and
 * matching a free-text tier name — which is also how "VIP" and "V.I.P." become two
 * tiers with one limit between them. A real row gives the count a foreign key, lets
 * the database enforce one slug per event, and makes a tier something an ORDER can
 * point at rather than something a string resembles.
 *
 * It also makes the money reconcilable. {@see \AfricaGates\Services\GatewayLedger}
 * already looks in `gates_event_registrations` for a Paystack reference; until now it
 * would never find one, because none was ever stored.
 *
 * ── NOTHING EXISTING IS THROWN AWAY ──────────────────────────────────────────
 *
 * Whatever an operator has typed into the `ticket_tiers` blob is IMPORTED into rows,
 * once, keyed by slug so re-running changes nothing. An event with a `price_naira` and
 * no blob gets a single "Standard" tier at that price, and one with neither gets a free
 * "General admission" — because the registration flow now goes through a tier, and an
 * event whose tiers were empty would otherwise become unregisterable the moment this
 * ran. A migration that silently closes an open event is worse than one that fails.
 *
 * The event's own `capacity` stays and keeps its old meaning: the ceiling for the whole
 * room, checked in ADDITION to each tier's own. An organiser who sells 40 early-bird
 * and 40 standard into a hall of 60 needs both numbers to be true.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

// ── 1 · the tiers ────────────────────────────────────────────────────────────
if (!DB::schema()->hasTable('gates_event_tiers')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_event_tiers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT NULL,
            price_naira INTEGER NOT NULL DEFAULT 0,
            capacity INTEGER NULL,
            sale_starts_at TEXT NULL,
            sale_ends_at TEXT NULL,
            min_per_order INTEGER NOT NULL DEFAULT 1,
            max_per_order INTEGER NOT NULL DEFAULT 10,
            access_code TEXT NULL,
            perks TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )" : "
        CREATE TABLE gates_event_tiers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            -- Zero is a real price, not a missing one: a free tier inside a paid event
            -- (press, sponsors, students) is the ordinary case and must not be
            -- indistinguishable from an unconfigured one.
            price_naira INT UNSIGNED NOT NULL DEFAULT 0,
            -- NULL = as many as the room allows. The point of this whole migration.
            capacity INT UNSIGNED NULL,
            sale_starts_at TIMESTAMP NULL DEFAULT NULL,
            sale_ends_at TIMESTAMP NULL DEFAULT NULL,
            min_per_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            max_per_order SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            -- Set = the tier is hidden until somebody types this. How a sponsor or
            -- speaker allocation is kept out of the public list without a second event.
            access_code VARCHAR(60) NULL,
            perks TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            -- One slug per event, in the database rather than in code: two tiers with
            -- the same slug would share a capacity count and neither limit would hold.
            UNIQUE KEY uq_tier_event_slug (event_id, slug),
            KEY idx_tier_event (event_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + gates_event_tiers created\n";
} else {
    echo "  = gates_event_tiers already present\n";
}

if ($sqlite) {
    DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_tier_event_slug ON gates_event_tiers (event_id, slug)");
    DB::statement("CREATE INDEX IF NOT EXISTS idx_tier_event ON gates_event_tiers (event_id, is_active, sort_order)");
    echo "  + event tier indexes ensured\n";
}

// ── 2 · registrations become orders ──────────────────────────────────────────
if (DB::schema()->hasTable('gates_event_registrations')) {
    foreach ([
        // Which tier, by id rather than by the free-text `tier` name that is already
        // there — a name can be edited after somebody has bought against it.
        'tier_id'      => $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED NULL',
        // One row can be several seats. A person booking for their team should not need
        // four email addresses they do not have.
        'quantity'     => $sqlite ? 'INTEGER' : 'SMALLINT UNSIGNED NULL',
        // pending (a hold at the gateway) · confirmed · cancelled · waitlisted.
        // A free tier is confirmed immediately; a paid one is pending until the gateway
        // says otherwise, which is the same rule every other checkout here follows.
        'status'       => $sqlite ? 'TEXT' : "VARCHAR(16) NULL",
        'provider'     => $sqlite ? 'TEXT' : 'VARCHAR(24) NULL',
        'provider_ref' => $sqlite ? 'TEXT' : 'VARCHAR(120) NULL',
        // What the attendee shows at the door. Short, unambiguous when read aloud over
        // a bad phone line, and unique — see EventTicketService::freshCode().
        'ticket_code'  => $sqlite ? 'TEXT' : 'VARCHAR(20) NULL',
        'confirmed_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'checked_in_at'=> $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'checked_in_by'=> $sqlite ? 'INTEGER' : 'BIGINT UNSIGNED NULL',
        // When a pending hold stops holding a seat. Without this, one abandoned checkout
        // takes a seat out of a sold-out tier permanently.
        'hold_expires_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'cancelled_at' => $sqlite ? 'TEXT' : 'TIMESTAMP NULL',
        'notes'        => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
    ] as $col => $type) {
        if (!DB::schema()->hasColumn('gates_event_registrations', $col)) {
            DB::statement("ALTER TABLE gates_event_registrations ADD COLUMN {$col} {$type} DEFAULT NULL");
            echo "  + gates_event_registrations.{$col} added\n";
        } else {
            echo "  = gates_event_registrations.{$col} already present\n";
        }
    }

    if ($sqlite) {
        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_tier ON gates_event_registrations (tier_id, status)");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_reg_ticket ON gates_event_registrations (ticket_code)");
    } else {
        try { DB::statement("CREATE INDEX idx_reg_tier ON gates_event_registrations (tier_id, status)"); }
        catch (\Throwable) {}
        try { DB::statement("CREATE UNIQUE INDEX uq_reg_ticket ON gates_event_registrations (ticket_code)"); }
        catch (\Throwable) {}
    }
    echo "  + registration indexes ensured\n";

    // Every row that predates this is a free RSVP that was accepted, so it is confirmed.
    // Leaving them NULL would make them invisible to a status-aware attendee list — the
    // organiser would open the screen after upgrading and find an empty room.
    $backfilled = DB::table('gates_event_registrations')->whereNull('status')->update([
        'status' => 'confirmed', 'quantity' => 1,
    ]);
    if ($backfilled > 0) echo "  + {$backfilled} existing registration(s) marked confirmed\n";
}

// ── 2b · the one-registration-per-email rule has to go ───────────────────────
//
// `UNIQUE(event_id, email)` was right for a free RSVP: a second insert meant somebody
// had pressed the button twice, and the controller answered "you are already on the
// list". With tickets that have a price it becomes a trap, and the worst kind — the
// failure is invisible and it happens to people who are trying to give you money.
//
// Somebody starts a paid checkout, changes their mind on the gateway's page, comes back
// an hour later to try again — and can never register for that event again, because a
// cancelled row still occupies their (event, email) pair. Buying a second ticket for a
// colleague is refused the same way.
//
// Replaced by a plain index: the lookups it served are still fast, and duplicate HOLDS
// are prevented where the decision actually belongs, in EventTicketService::reserve(),
// which reuses a live hold for the same person and tier rather than stacking a second.
if (DB::schema()->hasTable('gates_event_registrations')) {
    if ($sqlite) {
        // SQLite cannot drop a UNIQUE declared inline in CREATE TABLE — it is an
        // auto-index — so the table is rebuilt. Guarded on the constraint still being
        // there, so a second run is a no-op rather than a second rebuild.
        $unique = false;
        foreach (DB::select("PRAGMA index_list(gates_event_registrations)") as $ix) {
            if ((string) ($ix->origin ?? '') !== 'u') continue;
            $cols = array_map(
                static fn ($c): string => (string) $c->name,
                DB::select("PRAGMA index_info(" . $ix->name . ")")
            );
            sort($cols);
            if ($cols === ['email', 'event_id']) { $unique = true; break; }
        }

        if ($unique) {
            // The rebuild is done from the table's OWN `CREATE TABLE` statement with the
            // UNIQUE clause cut out of it — NOT from `CREATE TABLE … AS SELECT`.
            //
            // That shortcut is what a first version of this did, and it silently destroyed
            // the primary key: `AS SELECT` copies values and infers columns, so
            // `id INTEGER PRIMARY KEY AUTOINCREMENT` became a plain nullable column. Every
            // insert then wrote `id = NULL` while `insertGetId()` still returned the implicit
            // rowid, so the caller held an id that matched no row — and every follow-up
            // `where('id', …)` found nothing. Nothing errored; the feature simply did not work.
            //
            // Reusing the original DDL keeps the key, the types, the defaults and every other
            // constraint exactly as they were, and changes only the one clause being removed.
            $ddl = (string) (DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='gates_event_registrations'"
            )->sql ?? '');

            $rebuilt = (string) preg_replace(
                '/,\s*UNIQUE\s*\(\s*event_id\s*,\s*email\s*\)/i', '', $ddl, 1
            );

            if ($ddl === '' || $rebuilt === $ddl) {
                // The clause is not where we expected it. Leaving the table alone is the only
                // safe answer — a half-understood rebuild of a table holding paid tickets is
                // worse than a constraint that is merely inconvenient. Said out loud so it is
                // not discovered as a mystery later.
                echo "  ! could not locate UNIQUE(event_id, email) in the table definition — "
                   . "left as it is; a second registration per email will still be refused\n";
            } else {
                DB::statement('PRAGMA foreign_keys = OFF');
                DB::statement(str_replace(
                    'gates_event_registrations', 'gates_event_registrations_new', $rebuilt
                ));
                $cols = implode(', ', array_map(
                    static fn ($c): string => (string) $c->name,
                    DB::select("PRAGMA table_info(gates_event_registrations)")
                ));
                DB::statement("INSERT INTO gates_event_registrations_new ({$cols})
                               SELECT {$cols} FROM gates_event_registrations");
                DB::statement("DROP TABLE gates_event_registrations");
                DB::statement("ALTER TABLE gates_event_registrations_new RENAME TO gates_event_registrations");
                DB::statement('PRAGMA foreign_keys = ON');
                echo "  + gates_event_registrations rebuilt without UNIQUE(event_id, email)\n";
            }
        } else {
            echo "  = gates_event_registrations already allows a second registration\n";
        }

        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_event_email ON gates_event_registrations (event_id, email)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_tier ON gates_event_registrations (tier_id, status)");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_reg_ticket ON gates_event_registrations (ticket_code)");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_reg_ref ON gates_event_registrations (reference)");
    } else {
        try {
            DB::statement("ALTER TABLE gates_event_registrations DROP INDEX uq_evreg_event_email");
            echo "  + UNIQUE(event_id, email) dropped from gates_event_registrations\n";
        } catch (\Throwable) {
            echo "  = UNIQUE(event_id, email) already gone\n";
        }
        foreach ([
            'idx_reg_event_email' => '(event_id, email)',
            'idx_reg_ref'         => '(reference)',
        ] as $name => $cols) {
            try { DB::statement("CREATE INDEX {$name} ON gates_event_registrations {$cols}"); }
            catch (\Throwable) {}
        }
    }
}

// ── 3 · import whatever the JSON blob already said ───────────────────────────
if (DB::schema()->hasTable('gates_site_events') && DB::schema()->hasTable('gates_event_tiers')) {
    $now = Carbon::now()->toDateTimeString();
    $made = 0;

    // Slug::make() folds accented letters rather than deleting them. A bare character class
    // turns "Ìbàdàn VIP" into "bdn-vip", which is the kind of quiet mangling this platform
    // cannot afford: most of the names it handles carry accents.
    $slugify = static fn (string $s): string =>
        \AfricaGates\Support\Slug::make($s, 60) ?: 'tier';

    foreach (DB::table('gates_site_events')->get() as $ev) {
        // Already has real tiers — an operator may have edited them since, and
        // re-importing the blob over their work would be the migration undoing a
        // decision somebody made on a screen.
        if (DB::table('gates_event_tiers')->where('event_id', (int) $ev->id)->exists()) continue;

        $blob = json_decode((string) ($ev->ticket_tiers ?? '[]'), true);
        $rows = [];
        $order = 0;

        if (is_array($blob)) {
            foreach ($blob as $t) {
                if (!is_array($t)) continue;
                $name = trim((string) ($t['name'] ?? $t['title'] ?? ''));
                if ($name === '') continue;
                // The blob was display text, so a price could be "₦25,000" or "25000"
                // or "Free". Digits are the only part that can be charged.
                $priceRaw = (string) ($t['price_naira'] ?? $t['price'] ?? $t['amount'] ?? '0');
                $price = (int) preg_replace('/\D+/', '', $priceRaw);
                $rows[] = [
                    'event_id' => (int) $ev->id,
                    'slug' => $slugify($name),
                    'name' => mb_substr($name, 0, 120),
                    'description' => mb_substr(trim((string) ($t['description'] ?? $t['note'] ?? '')), 0, 500) ?: null,
                    'price_naira' => $price,
                    // Deliberately NULL, not the event capacity divided up. Nobody told
                    // us how the room splits, and inventing a limit would close a tier
                    // that was open yesterday.
                    'capacity' => null,
                    'perks' => is_array($t['perks'] ?? null) ? json_encode(array_values($t['perks'])) : null,
                    'is_active' => 1,
                    'sort_order' => $order++,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            // No blob. One tier, so the event stays registerable — at its own price when
            // it had one, free when it did not.
            $price = (int) ($ev->price_naira ?? 0);
            $rows[] = [
                'event_id' => (int) $ev->id,
                'slug' => $price > 0 ? 'standard' : 'general',
                'name' => $price > 0 ? 'Standard' : 'General admission',
                'description' => null,
                'price_naira' => $price,
                'capacity' => null,
                'perks' => null,
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // Two blob entries can slugify the same ("VIP" and "V.I.P."), and the unique
        // index would reject the second — taking the whole event's import with it.
        $seen = [];
        foreach ($rows as $r) {
            $slug = $r['slug'];
            $n = 2;
            while (isset($seen[$slug])) $slug = mb_substr($r['slug'], 0, 55) . '-' . $n++;
            $seen[$slug] = true;
            $r['slug'] = $slug;
            try { DB::table('gates_event_tiers')->insert($r); $made++; }
            catch (\Throwable $e) {
                error_log('[migrate] event tier import failed for event ' . $ev->id . ': ' . $e->getMessage());
            }
        }
    }

    if ($made > 0) echo "  + {$made} ticket tier(s) imported from the old JSON field\n";
    else           echo "  = no ticket tiers needed importing\n";

    // Point existing registrations at a tier where the old free-text name still matches,
    // so an organiser's historical numbers land in the right column rather than in none.
    $linked = 0;
    foreach (DB::table('gates_event_registrations')->whereNull('tier_id')->get() as $reg) {
        $name = trim((string) ($reg->tier ?? ''));
        $tier = $name !== ''
            ? DB::table('gates_event_tiers')->where('event_id', (int) $reg->event_id)
                ->where(function ($q) use ($name, $slugify): void {
                    $q->where('name', $name)->orWhere('slug', $slugify($name));
                })->first()
            : DB::table('gates_event_tiers')->where('event_id', (int) $reg->event_id)
                ->orderBy('sort_order')->first();
        if ($tier) {
            DB::table('gates_event_registrations')->where('id', (int) $reg->id)
                ->update(['tier_id' => (int) $tier->id]);
            $linked++;
        }
    }
    if ($linked > 0) echo "  + {$linked} existing registration(s) linked to a tier\n";
}

echo "event tickets OK\n";
