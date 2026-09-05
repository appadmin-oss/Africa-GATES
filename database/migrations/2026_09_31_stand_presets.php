<?php
/**
 * Stand presets: the stock parts an organiser builds a market from, priced.
 *
 * ── WHAT WAS WRONG WITH THE CONST ────────────────────────────────────────────
 *
 * `StandType::SIZES` is a hardcoded array of seven sizes. It is the right idea — a market is
 * built from stock parts, not arbitrary rectangles — and wrong in three ways that only show
 * up when somebody actually runs an event:
 *
 *   · No price. The size is a preset and the money is typed again for every event, so the
 *     6 × 6 that cost ₦10,000 in March quietly becomes ₦12,000 in June because somebody
 *     remembered it differently. The price IS part of the offer.
 *   · Metric only, in a market that hires in feet. Gazebo stock here is quoted 6ft and 12ft.
 *   · In code. This platform deploys to cPanel by upload with no SSH, so "add a size" is a
 *     developer task and a deploy — which means it does not happen, and the organiser puts
 *     it in as a custom size with the price typed from memory.
 *
 * ── FEET STORED AS CENTIMETRES, WITH THE UNIT REMEMBERED ─────────────────────
 *
 * `width_cm`/`depth_cm` stay the arithmetic, because integer centimetres make floor-area
 * sums across a hundred pitches exact — the reason StandType chose them.
 *
 * But 6ft is 182.88cm, and a preset entered as "6 × 6 ft" stored only as 183 × 183 renders
 * back as "1.83 × 1.83 m". A vendor who was promised a 6ft pitch cannot recognise that, and a
 * size is a PUBLISHED TERM: it goes on the application, the acceptance and the floor plan.
 * So `unit` records how it was entered and every label is written in it. The centimetres are
 * for the floor plan; the unit is for the human.
 *
 * ── PRESETS ARE GLOBAL, STAND TYPES STAY PER EVENT ───────────────────────────
 *
 * A preset is what the organisation offers. A stand type is what one event has, at a QUOTA —
 * "how many of these exist in this hall" is the one number that cannot be a preset, because
 * it is a fact about a room. So applying a preset copies its terms onto a new
 * `gates_stand_types` row and leaves the quota for the organiser, which is exactly the split
 * the operator described: presets saved once, quantities added per event.
 *
 * Copies, not references. A preset repriced next year must not rewrite the terms of a call
 * that already ran — the same one-way door {@see StandCall::open()} closes on criteria.
 *
 * Idempotent + driver-aware. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();
use Illuminate\Database\Capsule\Manager as DB;

$sqlite = DB::connection()->getDriverName() === 'sqlite';

if (!DB::schema()->hasTable('gates_stand_presets')) {
    DB::statement($sqlite ? "
        CREATE TABLE gates_stand_presets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL,
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'general',
            note TEXT NULL,
            width_cm INTEGER NOT NULL DEFAULT 0,
            depth_cm INTEGER NOT NULL DEFAULT 0,
            -- 'ft' or 'm' — how the organiser entered it, and therefore how every
            -- label prints it. See the note at the top of this file.
            unit TEXT NOT NULL DEFAULT 'm',
            price_naira INTEGER NOT NULL DEFAULT 0,
            deposit_naira INTEGER NOT NULL DEFAULT 0,
            -- The usual number of these in a hall. A STARTING POINT for the quota box,
            -- never the quota itself: how many fit is a fact about a room.
            default_quota INTEGER NOT NULL DEFAULT 0,
            includes_power INTEGER NOT NULL DEFAULT 0,
            step_free INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NULL,
            updated_at TEXT NULL,
            updated_by INTEGER NULL
        )" : "
        CREATE TABLE gates_stand_presets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL,
            name VARCHAR(160) NOT NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'general',
            note VARCHAR(400) NULL,
            width_cm INT UNSIGNED NOT NULL DEFAULT 0,
            depth_cm INT UNSIGNED NOT NULL DEFAULT 0,
            unit VARCHAR(4) NOT NULL DEFAULT 'm',
            price_naira INT UNSIGNED NOT NULL DEFAULT 0,
            deposit_naira INT UNSIGNED NOT NULL DEFAULT 0,
            default_quota INT UNSIGNED NOT NULL DEFAULT 0,
            includes_power TINYINT(1) NOT NULL DEFAULT 0,
            step_free TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL,
            updated_by BIGINT UNSIGNED NULL,
            UNIQUE KEY uq_preset_slug (slug),
            KEY idx_preset_live (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  + gates_stand_presets created\n";

    if ($sqlite) {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_preset_slug ON gates_stand_presets(slug)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_preset_live ON gates_stand_presets(is_active, sort_order)');
    }
}

// ── the seed ────────────────────────────────────────────────────────────────
//
// The two the operator priced, then the metric stock the const already carried so nothing
// that was previously offerable stops being offerable. Only ever inserted into an EMPTY
// table: re-seeding over an organiser's edits would reset prices they had corrected, and
// this file runs on every deploy.
if ((int) DB::table('gates_stand_presets')->count() === 0) {
    // 6ft is 182.88cm and 12ft is 365.76cm. Rounded to the centimetre, which is a
    // millimetre-and-a-bit of error on a pitch and exact enough for a floor plan; the `unit`
    // column is what keeps the LABEL honest.
    $now  = date('Y-m-d H:i:s');
    $rows = [
        ['six-by-six-ft',    '6 × 6 ft stand',  'general', 183, 183, 'ft', 10_000, 0,  1,
         'The standard single pitch. One trestle or a small rail, one or two people behind it.'],
        ['twelve-by-six-ft', '12 × 6 ft stand', 'general', 366, 183, 'ft', 35_000, 0,  2,
         'Double frontage. Hot food, a fashion rail, a demonstration table — anything that '
         . 'needs a queue to form in front of it without blocking the aisle.'],

        ['table-only', 'Table only',        'books',   180,  75, 'm', 0, 0, 10,
         'A trestle and the space to stand behind it. Books, crafts, leaflets.'],
        ['pitch-2x2',  'Small pitch',       'general', 200, 200, 'm', 0, 0, 11,
         'One person, goods on a table. No room for a queue to form inside it.'],
        ['pitch-3x3',  'Standard gazebo',   'general', 300, 300, 'm', 0, 0, 12,
         'One hired gazebo, two staff, stock behind.'],
        ['pitch-6x3',  'Double gazebo',     'food',    600, 300, 'm', 0, 0, 13,
         'Two gazebos side by side. Hot food, fashion rails, demonstrations.'],
        ['pitch-6x6',  'Corner block',      'general', 600, 600, 'm', 0, 0, 14,
         'Open on two sides. Usually an anchor vendor or a sponsor.'],
    ];

    foreach ($rows as [$slug, $name, $cat, $w, $d, $unit, $price, $quota, $sort, $note]) {
        DB::table('gates_stand_presets')->insert([
            'slug' => $slug, 'name' => $name, 'category' => $cat, 'note' => $note,
            'width_cm' => $w, 'depth_cm' => $d, 'unit' => $unit,
            'price_naira' => $price, 'deposit_naira' => 0, 'default_quota' => $quota,
            'includes_power' => 0, 'step_free' => 0,
            'sort_order' => $sort, 'is_active' => 1, 'created_at' => $now,
        ]);
    }
    echo '  + ' . count($rows) . " stand presets seeded\n";
} else {
    echo "  = gates_stand_presets already has rows — not re-seeding over edits\n";
}
