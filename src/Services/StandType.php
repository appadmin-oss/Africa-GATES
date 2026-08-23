<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What a vendor can apply for: a kind of pitch, at a price, with a published quota.
 *
 * ── NOT A TICKET TIER, ON PURPOSE ────────────────────────────────────────────
 *
 * A ticket tier is bought by whoever pays first. A stand type is APPLIED FOR and allocated,
 * and the difference runs all the way down: a tier sells out, a stand type is oversubscribed;
 * a tier's capacity is inventory, a stand type's quota is a fairness constraint. Sharing the
 * table would mean teaching every ticket query to exclude stands, which is the coupling that
 * eventually produces a stand on sale at the checkout.
 *
 * ── THE QUOTA IS THE POINT ───────────────────────────────────────────────────
 *
 * `quota` is published with the call and locked when it opens. It is what prevents twelve
 * jewellery stalls and no food WITHOUT anybody applying a private preference — the constraint
 * is a number every applicant can see before they apply. §10.1 of the specification works
 * through why unfilled places in one category do not migrate into another.
 */
final class StandType
{
    /**
     * Categories a quota can be set against.
     *
     * A fixed list rather than free text: a quota only means something if two people typing
     * "Food" and "food and drink" land in the same bucket, and a mistyped category is a stand
     * that silently escapes its own cap.
     */
    public const CATEGORIES = [
        'food'     => 'Food and drink',
        'craft'    => 'Craft and handmade',
        'fashion'  => 'Fashion and textiles',
        'beauty'   => 'Beauty and cosmetics',
        'books'    => 'Books and print',
        'services' => 'Services',
        'general'  => 'General',
    ];

    /**
     * The sizes an organiser picks from, in centimetres.
     *
     * ── A LIST, BECAUSE A MARKET IS BUILT FROM STOCK PARTS ───────────────────
     *
     * Pitches are not arbitrary rectangles. They are whatever the hired gazebos, tables and
     * shell schemes actually are, and on this continent that overwhelmingly means the 3m
     * gazebo and multiples of it. Offering a free pair of numbers as the primary control
     * invites 2.8m — which fits nothing, orders nothing, and is discovered on build day.
     *
     * Custom is still available, because a converted warehouse has odd corners and a
     * specification that cannot describe reality gets worked around rather than followed.
     *
     * Centimetres, so that 1.8m and 2.5m are expressible and the area arithmetic across a
     * hundred pitches stays exact. A float here accumulates the kind of error that reads as
     * "we are 4m² over" when the plan is fine.
     *
     * @var array<string,array{w:int,d:int,label:string,note:string}>
     */
    public const SIZES = [
        'table' => ['w' => 180, 'd' =>  75, 'label' => 'Table only · 1.8 × 0.75 m',
                    'note' => 'A trestle and the space to stand behind it. Books, crafts, leaflets.'],
        '2x2'   => ['w' => 200, 'd' => 200, 'label' => 'Small pitch · 2 × 2 m',
                    'note' => 'One person, goods on a table. No room for a queue to form inside it.'],
        '3x2'   => ['w' => 300, 'd' => 200, 'label' => 'Shallow pitch · 3 × 2 m',
                    'note' => 'Wide frontage against a wall where depth is not available.'],
        '3x3'   => ['w' => 300, 'd' => 300, 'label' => 'Standard gazebo · 3 × 3 m',
                    'note' => 'The default. One hired gazebo, two staff, stock behind.'],
        '4x3'   => ['w' => 400, 'd' => 300, 'label' => 'Wide pitch · 4 × 3 m',
                    'note' => 'A gazebo with working space beside it — cooking, a rail, a counter.'],
        '6x3'   => ['w' => 600, 'd' => 300, 'label' => 'Double gazebo · 6 × 3 m',
                    'note' => 'Two gazebos side by side. Hot food, fashion rails, demonstrations.'],
        '6x6'   => ['w' => 600, 'd' => 600, 'label' => 'Corner block · 6 × 6 m',
                    'note' => 'Open on two sides. Usually an anchor vendor or a sponsor.'],
    ];

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_stand_types')->where('id', $id)->first();
    }

    // ────────────────────────────────── sizes ───────────────────────────────

    /** Square metres of floor one of these occupies. */
    public static function areaSqm(?object $type): float
    {
        $w = (int) ($type->width_cm ?? 0);
        $d = (int) ($type->depth_cm ?? 0);
        if ($w < 1 || $d < 1) return 0.0;
        return round($w * $d / 10000, 2);
    }

    /** "3 × 3 m" — the dimension a vendor was promised, written the way they read it. */
    public static function sizeLabel(?object $type): string
    {
        $w = (int) ($type->width_cm ?? 0);
        $d = (int) ($type->depth_cm ?? 0);
        if ($w < 1 || $d < 1) return '—';
        return self::metres($w) . ' × ' . self::metres($d) . ' m';
    }

    /** Centimetres as metres, without a trailing ".00" on the common whole-metre case. */
    public static function metres(int $cm): string
    {
        $m = $cm / 100;
        return rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');
    }

    /**
     * Which preset a size corresponds to, or 'custom'.
     *
     * Matched on the numbers rather than stored, so a row edited by hand into 3 × 3 still
     * shows as the standard gazebo. Storing the preset key alongside the numbers would let
     * the two disagree, and then one of them is a lie.
     */
    public static function presetFor(?object $type): string
    {
        $w = (int) ($type->width_cm ?? 0);
        $d = (int) ($type->depth_cm ?? 0);
        foreach (self::SIZES as $key => $s) {
            if ($s['w'] === $w && $s['d'] === $d) return $key;
        }
        return 'custom';
    }

    /**
     * Read a size out of submitted form values.
     *
     * A preset wins over the free pair, because the preset is the control the organiser
     * actually operated — the number boxes are only meaningful once they have said "custom",
     * and honouring stale numbers behind a chosen preset is how a 3 × 3 silently becomes
     * whatever was in the boxes last time.
     *
     * @return array{0:int,1:int} width and depth in centimetres
     */
    public static function readSize(array $in, ?object $existing = null): array
    {
        $preset = (string) ($in['size_preset'] ?? '');
        if (isset(self::SIZES[$preset])) {
            return [self::SIZES[$preset]['w'], self::SIZES[$preset]['d']];
        }

        // ── EXACT CENTIMETRES, WHEN THE CALLER ALREADY HAS THEM ─────────────
        //
        // {@see StandPreset::applyTo()} copies a stored pitch, and a foot-based one is 183cm
        // — in no SIZES entry, and a value that would otherwise have to be sent through a
        // metres float and back. Without this branch it fell past both cases to the
        // `$existing` default and every 6ft stand was silently created as 3 × 3 m.
        $wCm = (int) ($in['width_cm'] ?? 0);
        $dCm = (int) ($in['depth_cm'] ?? 0);
        if ($wCm > 0 && $dCm > 0) {
            return [min($wCm, 5000), min($dCm, 5000)];
        }

        // Metres in, centimetres stored. Rounded rather than truncated: 2.999 typed into a
        // browser's number field is somebody who meant 3.
        $w = (int) round(((float) ($in['width_m']  ?? 0)) * 100);
        $d = (int) round(((float) ($in['depth_m']  ?? 0)) * 100);

        if ($w < 1 || $d < 1) {
            return [(int) ($existing->width_cm ?? 300), (int) ($existing->depth_cm ?? 300)];
        }
        // Capped at 50m a side. Not a real pitch above that, and an unbounded number here
        // makes the floor plan render a rectangle the size of a city.
        return [min($w, 5000), min($d, 5000)];
    }

    /** @return array<int,object> */
    public static function forEvent(int $eventId): array
    {
        if ($eventId < 1) return [];
        try {
            return DB::table('gates_stand_types')->where('event_id', $eventId)
                ->orderBy('sort_order')->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Create or edit a stand type.
     *
     * Refuses while the call is open, because price and quota are published terms — §5.1 says
     * changing any of them after applications open means reopening the window, and the
     * cleanest way to enforce that is to make the edit impossible rather than discouraged.
     *
     * @return array{ok:bool,message:string,id:int}
     */
    public static function save(int $eventId, array $in, int $typeId = 0): array
    {
        $fail = ['ok' => false, 'id' => $typeId];

        $call = StandCall::forEvent($eventId);
        if ($call && (string) $call->status === StandCall::STATUS_OPEN) {
            return $fail + ['message' => 'The call for this event is open, so prices and quotas '
                                       . 'are locked. Close it before changing what is on offer.'];
        }

        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return $fail + ['message' => 'A stand type needs a name.'];

        // Slug::make folds accents rather than deleting them — see SlugTest.
        $slug = Slug::make((string) ($in['slug'] ?? '') ?: $name, 80);
        if ($slug === '') return $fail + ['message' => 'That name does not make a usable web address.'];

        $category = (string) ($in['category'] ?? 'general');
        if (!isset(self::CATEGORIES[$category])) $category = 'general';

        $price   = (int) preg_replace('/[^0-9]/', '', (string) ($in['price_naira'] ?? '0'));
        $deposit = (int) preg_replace('/[^0-9]/', '', (string) ($in['deposit_naira'] ?? '0'));
        $quota   = (int) preg_replace('/[^0-9]/', '', (string) ($in['quota'] ?? '0'));

        if ($quota < 1) {
            return $fail + ['message' => 'A quota of zero means nobody can be allocated one. '
                                       . 'Set how many of this stand type exist.'];
        }
        if ($deposit > $price) {
            return $fail + ['message' => 'The deposit cannot be more than the stand price.'];
        }

        $clash = DB::table('gates_stand_types')->where('event_id', $eventId)->where('slug', $slug);
        if ($typeId > 0) $clash->where('id', '!=', $typeId);
        if ($clash->exists()) {
            return $fail + ['message' => 'This event already has a stand type at that address.'];
        }

        $existing = $typeId > 0 ? self::find($typeId) : null;
        [$widthCm, $depthCm] = self::readSize($in, $existing);

        $row = [
            'event_id'       => $eventId,
            'slug'           => $slug,
            'name'           => mb_substr($name, 0, 160),
            // A published term, like the price and the quota. A vendor who applied for
            // 3m × 3m and arrives to find 2m × 2m was sold something else.
            'width_cm'       => $widthCm,
            'depth_cm'       => $depthCm,
            'category'       => $category,
            'description'    => trim((string) ($in['description'] ?? '')) ?: null,
            'price_naira'    => $price,
            'deposit_naira'  => $deposit,
            'quota'          => $quota,
            'includes_power' => !empty($in['includes_power']) ? 1 : 0,
            // §5.8: accessible pitches are inventory, not a favour. Marking them here is what
            // lets a vendor request one on the form instead of asking and hoping.
            'step_free'      => !empty($in['step_free']) ? 1 : 0,
            'sort_order'     => (int) ($in['sort_order'] ?? 0),
        ];

        if ($typeId > 0) {
            if (!$existing || (int) $existing->event_id !== $eventId) {
                return $fail + ['message' => 'That stand type does not belong to this event.'];
            }

            // ── A QUOTA CANNOT BE CUT BELOW WHAT IS ALREADY ALLOCATED ───────
            //
            // Editing a type became possible at the same time as this guard, and it needs
            // one: dropping "how many exist" from 10 to 4 when six vendors already hold
            // offers does not un-offer anybody. It leaves the capacity view reading 6/4,
            // the floor plan short by two pitches, and six businesses holding a place the
            // published quota says does not exist. Refused with the number, because the
            // organiser's real intent is either to withdraw offers first or to leave it.
            $held = self::allocated($typeId);
            if ($held > $quota) {
                return $fail + ['message' => $held . ' vendor(s) already hold a place in this '
                                           . 'type, so the quota cannot go below ' . $held . '. '
                                           . 'Withdraw an offer first if that is what you mean.'];
            }

            DB::table('gates_stand_types')->where('id', $typeId)->update($row);
            return ['ok' => true, 'id' => $typeId, 'message' => 'Saved.'];
        }

        $row['created_at'] = date('Y-m-d H:i:s');
        return ['ok' => true, 'id' => (int) DB::table('gates_stand_types')->insertGetId($row),
                'message' => 'Stand type added.'];
    }

    /**
     * How many places in this type are spoken for.
     *
     * Offered counts as well as accepted: an offer is a promise with a clock on it, and a
     * quota that ignores outstanding offers is a quota that oversells while somebody is
     * still deciding. Same pair {@see StandCall::capacity()} counts.
     */
    public static function allocated(int $typeId): int
    {
        try {
            return (int) DB::table('gates_stand_applications')
                ->where('stand_type_id', $typeId)
                ->whereIn('decision', [StandApplication::DECISION_OFFERED,
                                       StandApplication::DECISION_ACCEPTED])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Remove a stand type.
     *
     * Refused once anybody has applied for it. Deleting it would orphan their application and
     * erase what they applied FOR, which is the one thing a rejected applicant is entitled to
     * see.
     */
    public static function delete(int $typeId): array
    {
        $t = self::find($typeId);
        if (!$t) return ['ok' => false, 'message' => 'That stand type does not exist.'];

        $call = StandCall::forEvent((int) $t->event_id);
        if ($call && (string) $call->status === StandCall::STATUS_OPEN) {
            return ['ok' => false, 'message' => 'The call is open, so what is on offer is locked.'];
        }

        try {
            $applied = (int) DB::table('gates_stand_applications')->where('stand_type_id', $typeId)->count();
        } catch (\Throwable) {
            $applied = 0;
        }
        if ($applied > 0) {
            return ['ok' => false, 'message' => $applied . ' vendor(s) have applied for this stand '
                                              . 'type. Deleting it would erase what they applied for.'];
        }

        DB::table('gates_stand_types')->where('id', $typeId)->delete();
        return ['ok' => true, 'message' => 'Stand type removed.'];
    }
}
