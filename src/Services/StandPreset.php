<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Slug;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The stand sizes the organisation offers, priced, and editable without a deploy.
 *
 * ── WHY THIS REPLACES A CONST ────────────────────────────────────────────────
 *
 * {@see StandType::SIZES} had the right instinct — a market is built from stock parts, not
 * arbitrary rectangles — and three problems that only surface once somebody runs an event:
 * the price was typed again for every event and drifted; the labels were metric in a market
 * that hires in feet; and adding one was a code change and a deploy on a host with no SSH,
 * so it never happened and the organiser entered a custom size with a remembered price.
 *
 * The const stays where it is as the size vocabulary the free-form editor still offers. This
 * is the priced catalogue, and it is a table.
 *
 * ── FEET, AND WHY THE UNIT IS STORED ─────────────────────────────────────────
 *
 * Centimetres do the arithmetic: integer cm makes a floor-area sum across a hundred pitches
 * exact, which is why StandType chose them and why that does not change.
 *
 * But 6ft is 182.88cm, and a preset entered as 6 × 6 ft and stored only as 183 × 183 reads
 * back as "1.83 × 1.83 m". A size is a PUBLISHED TERM — it goes on the application, the
 * acceptance and the floor plan — and a vendor who was promised a 6ft pitch cannot recognise
 * 1.83m as the thing they applied for. So `unit` records how it was entered, and
 * {@see label()} prints it that way. The centimetres are for the hall; the unit is for the
 * person.
 *
 * Rounding is to the nearest centimetre, deliberately, rather than storing feet as a float.
 * 0.12cm of error on a pitch is nothing; a float area is how "we are 4m² over" appears on a
 * plan that is fine.
 *
 * ── APPLYING ONE IS A COPY ───────────────────────────────────────────────────
 *
 * {@see applyTo()} writes the preset's terms onto a new `gates_stand_types` row for one
 * event and leaves the QUOTA to the organiser — how many fit is a fact about a room, not
 * something a catalogue can know.
 *
 * A copy and never a reference. A preset repriced next year must not rewrite the terms of a
 * call that already ran, which is the same one-way door {@see StandCall::open()} closes on
 * the criteria.
 */
final class StandPreset
{
    /** Exact, so a conversion is never re-derived slightly differently somewhere else. */
    public const CM_PER_FOOT = 30.48;

    public const UNITS = ['ft' => 'Feet', 'm' => 'Metres'];

    // ─────────────────────────────── conversion ─────────────────────────────

    /** Feet → centimetres, rounded to the centimetre. */
    public static function feetToCm(float $ft): int
    {
        return (int) round(max(0.0, $ft) * self::CM_PER_FOOT);
    }

    /** Centimetres → feet, to one decimal. 183cm → 6.0, not 6.003937... */
    public static function cmToFeet(int $cm): float
    {
        return round(max(0, $cm) / self::CM_PER_FOOT, 1);
    }

    /**
     * A dimension written in the unit it was bought in.
     *
     * `rtrim` on the decimal so 6.0 prints as "6" — a vendor reading "6.0 ft" is reading a
     * number that came out of a database, and it invites the question of what the .0 means.
     */
    public static function dim(int $cm, string $unit): string
    {
        if ($unit === 'ft') {
            $ft = self::cmToFeet($cm);
            return rtrim(rtrim(number_format($ft, 1, '.', ''), '0'), '.');
        }

        return StandType::metres($cm);
    }

    /** "6 × 6 ft" / "3 × 3 m". The one string a vendor has to be able to recognise. */
    public static function label(?object $p): string
    {
        $w = (int) ($p->width_cm ?? 0);
        $d = (int) ($p->depth_cm ?? 0);
        if ($w < 1 || $d < 1) return '—';

        $unit = self::unitOf($p);

        return self::dim($w, $unit) . ' × ' . self::dim($d, $unit) . ' ' . $unit;
    }

    /**
     * The same label for a `gates_stand_types` row.
     *
     * Stand types have no `unit` column — they predate this — so a copied row would lose the
     * feet it was created in. Rather than a migration that guesses at every existing row,
     * the unit is INFERRED: a dimension that is a whole number of feet but not a whole
     * number of 5cm steps almost certainly came from a foot measurement. 183 is 6ft exactly
     * and is not a round metric number; 300 is 3m exactly and is not a round foot number.
     */
    public static function labelForType(?object $t): string
    {
        $w = (int) ($t->width_cm ?? 0);
        $d = (int) ($t->depth_cm ?? 0);
        if ($w < 1 || $d < 1) return '—';

        return self::looksImperial($w) && self::looksImperial($d)
            ? self::dim($w, 'ft') . ' × ' . self::dim($d, 'ft') . ' ft'
            : StandType::sizeLabel($t);
    }

    /**
     * Is this centimetre value a whole number of feet, and not also a tidy metric one?
     *
     * The "not also metric" half matters: 300cm is 9.8ft, so it fails the first test anyway,
     * but 600cm would be caught by a looser rule and 6m is obviously metric. Requiring the
     * value NOT to be a multiple of 10cm keeps every metric size reading metric.
     */
    private static function looksImperial(int $cm): bool
    {
        if ($cm % 10 === 0) return false;

        $ft = $cm / self::CM_PER_FOOT;

        return abs($ft - round($ft)) < 0.02 && round($ft) >= 1;
    }

    private static function unitOf(?object $p): string
    {
        $u = strtolower(trim((string) ($p->unit ?? 'm')));

        return isset(self::UNITS[$u]) ? $u : 'm';
    }

    // ──────────────────────────────── reading ───────────────────────────────

    /** @return list<object> active presets in display order */
    public static function all(bool $includeInactive = false): array
    {
        try {
            $q = DB::table('gates_stand_presets');
            if (!$includeInactive) $q->where('is_active', 1);

            return $q->orderBy('sort_order')->orderBy('name')->get()->all();
        } catch (\Throwable) {
            // The table is created by a migration. A deployment between an upload and a
            // `db:migrate` must still render the stands screen rather than 500 on it.
            return [];
        }
    }

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        try { return DB::table('gates_stand_presets')->where('id', $id)->first(); }
        catch (\Throwable) { return null; }
    }

    /** Square metres, from the centimetres. Same arithmetic as StandType. */
    public static function areaSqm(?object $p): float
    {
        $w = (int) ($p->width_cm ?? 0);
        $d = (int) ($p->depth_cm ?? 0);

        return ($w < 1 || $d < 1) ? 0.0 : round($w * $d / 10000, 2);
    }

    // ──────────────────────────────── writing ───────────────────────────────

    /**
     * Save a preset.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, id:int, message:string}
     */
    public static function save(array $in, int $id = 0, int $adminId = 0): array
    {
        $fail = ['ok' => false, 'id' => $id];

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return $fail + ['message' => 'A preset needs a name.'];

        // Slug::make folds accents rather than deleting them — see SlugTest.
        $slug = Slug::make((string) ($in['slug'] ?? '') ?: $name, 80);
        if ($slug === '') return $fail + ['message' => 'That name does not make a usable key.'];

        $unit = strtolower(trim((string) ($in['unit'] ?? 'm')));
        if (!isset(self::UNITS[$unit])) $unit = 'm';

        [$w, $d] = self::readSize($in, $unit);
        if ($w < 1 || $d < 1) {
            return $fail + ['message' => 'Give the preset a width and a depth.'];
        }
        // A pitch bigger than a tennis court is a typo — almost always feet typed into a
        // metres box, which would then print a size no vendor could be given.
        if ($w > 5000 || $d > 5000) {
            return $fail + ['message' => 'That is over 50 metres on one side. Check the unit.'];
        }

        $price   = self::naira($in['price_naira'] ?? 0);
        $deposit = self::naira($in['deposit_naira'] ?? 0);
        if ($deposit > $price) {
            return $fail + ['message' => 'The deposit cannot be more than the stand price.'];
        }

        $category = (string) ($in['category'] ?? 'general');
        if (!isset(StandType::categories()[$category])) $category = 'general';

        $clash = DB::table('gates_stand_presets')->where('slug', $slug);
        if ($id > 0) $clash->where('id', '!=', $id);
        if ($clash->exists()) {
            return $fail + ['message' => 'A preset with that key already exists.'];
        }

        $row = [
            'slug'           => $slug,
            'name'           => mb_substr($name, 0, 160),
            'category'       => $category,
            'note'           => trim((string) ($in['note'] ?? '')) !== ''
                                ? mb_substr(trim((string) $in['note']), 0, 400) : null,
            'width_cm'       => $w,
            'depth_cm'       => $d,
            'unit'           => $unit,
            'price_naira'    => $price,
            'deposit_naira'  => $deposit,
            'default_quota'  => max(0, min(10_000, (int) ($in['default_quota'] ?? 0))),
            'includes_power' => !empty($in['includes_power']) ? 1 : 0,
            'step_free'      => !empty($in['step_free']) ? 1 : 0,
            'sort_order'     => (int) ($in['sort_order'] ?? 0),
            'is_active'      => isset($in['is_active']) ? (!empty($in['is_active']) ? 1 : 0) : 1,
            'updated_at'     => Carbon::now()->toDateTimeString(),
            'updated_by'     => $adminId ?: null,
        ];

        if ($id > 0) {
            if (!self::find($id)) return $fail + ['message' => 'That preset does not exist.'];
            DB::table('gates_stand_presets')->where('id', $id)->update($row);

            return ['ok' => true, 'id' => $id, 'message' => 'Preset saved. Events already using '
                    . 'it keep the terms they were created with.'];
        }

        $row['created_at'] = Carbon::now()->toDateTimeString();

        return ['ok' => true, 'id' => (int) DB::table('gates_stand_presets')->insertGetId($row),
                'message' => 'Preset added.'];
    }

    /**
     * Read a size from the form, in whichever unit was chosen.
     *
     * The two unit boxes are separate fields rather than one pair reinterpreted, because a
     * shared pair silently changes meaning when somebody flips the unit: "6 × 6" typed as
     * feet and then switched to metres becomes a 36m² pitch nobody asked for.
     *
     * @return array{0:int,1:int} width and depth in centimetres
     */
    private static function readSize(array $in, string $unit): array
    {
        if ($unit === 'ft') {
            return [
                self::feetToCm((float) ($in['width_ft'] ?? $in['width'] ?? 0)),
                self::feetToCm((float) ($in['depth_ft'] ?? $in['depth'] ?? 0)),
            ];
        }

        // Metres in, centimetres stored.
        return [
            (int) round(max(0.0, (float) ($in['width_m'] ?? $in['width'] ?? 0)) * 100),
            (int) round(max(0.0, (float) ($in['depth_m'] ?? $in['depth'] ?? 0)) * 100),
        ];
    }

    /** Strip everything but digits — operators paste "₦10,000" and "10k". */
    private static function naira(mixed $v): int
    {
        $s = trim((string) $v);
        // "10k" and "35K" are how a price list is actually written down.
        if (preg_match('~^([0-9][0-9,.]*)\s*k$~i', $s, $m)) {
            return (int) round(((float) str_replace(',', '', $m[1])) * 1000);
        }

        return max(0, (int) preg_replace('/[^0-9]/', '', $s));
    }

    /** Deactivate rather than delete: an event's stand type names the preset it came from. */
    public static function archive(int $id): array
    {
        if (!self::find($id)) return ['ok' => false, 'message' => 'That preset does not exist.'];

        DB::table('gates_stand_presets')->where('id', $id)->update(['is_active' => 0]);

        return ['ok' => true, 'message' => 'Preset retired. Events already using it are untouched.'];
    }

    public static function restore(int $id): array
    {
        if (!self::find($id)) return ['ok' => false, 'message' => 'That preset does not exist.'];

        DB::table('gates_stand_presets')->where('id', $id)->update(['is_active' => 1]);

        return ['ok' => true, 'message' => 'Preset is offerable again.'];
    }

    // ──────────────────────────────── applying ──────────────────────────────

    /**
     * Copy a preset onto one event as a stand type, with the quota the organiser gave.
     *
     * Goes through {@see StandType::save()} rather than inserting directly, so every rule
     * that guards a stand type still applies — the open-call lock above all. A preset that
     * could write past a locked call would let somebody add a cheaper pitch after seeing who
     * had applied for the expensive one.
     *
     * @return array{ok:bool, id:int, message:string}
     */
    public static function applyTo(int $eventId, int $presetId, int $quota, int $adminId = 0): array
    {
        $p = self::find($presetId);
        if (!$p) return ['ok' => false, 'id' => 0, 'message' => 'That preset does not exist.'];

        if ($quota < 1) {
            return ['ok' => false, 'id' => 0,
                    'message' => 'How many of these does this event have? A quota of zero means '
                               . 'nobody can be allocated one.'];
        }

        // A distinct slug per event, so applying the same preset twice — a hall with two
        // rows of 6 × 6 at different prices — is possible rather than a clash.
        $slug = (string) $p->slug;
        $n    = 1;
        while (DB::table('gates_stand_types')->where('event_id', $eventId)->where('slug', $slug)->exists()) {
            $slug = $p->slug . '-' . (++$n);
        }

        // The preset's name AS IT IS. It used to append the size, which produced
        // "6 × 6 ft stand · 6 × 6 ft" for the one preset an organiser is most likely to
        // apply — the dimension is already a column in the table beside it, and a name that
        // repeats it reads like a bug because it is one. The suffix only appears from the
        // second application onward, matching the slug, so two rows of the same preset are
        // distinguishable.
        $name = (string) $p->name . ($n > 1 ? ' (' . $n . ')' : '');

        return StandType::save($eventId, [
            'name'           => $name,
            'slug'           => $slug,
            'category'       => (string) $p->category,
            'description'    => (string) ($p->note ?? ''),
            'price_naira'    => (int) $p->price_naira,
            'deposit_naira'  => (int) $p->deposit_naira,
            'quota'          => $quota,
            'includes_power' => (int) $p->includes_power,
            'step_free'      => (int) $p->step_free,
            'sort_order'     => (int) $p->sort_order,
            // Bypasses StandType::readSize's preset vocabulary and hands it the exact
            // centimetres, which is what a foot-based size needs — 183 is in no SIZES entry.
            'width_cm'       => (int) $p->width_cm,
            'depth_cm'       => (int) $p->depth_cm,
            'size_preset'    => 'custom',
        ]);
    }
}
