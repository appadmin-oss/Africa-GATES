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

    public static function find(int $id): ?object
    {
        if ($id < 1) return null;
        return DB::table('gates_stand_types')->where('id', $id)->first();
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

        $row = [
            'event_id'       => $eventId,
            'slug'           => $slug,
            'name'           => mb_substr($name, 0, 160),
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
            $existing = self::find($typeId);
            if (!$existing || (int) $existing->event_id !== $eventId) {
                return $fail + ['message' => 'That stand type does not belong to this event.'];
            }
            DB::table('gates_stand_types')->where('id', $typeId)->update($row);
            return ['ok' => true, 'id' => $typeId, 'message' => 'Saved.'];
        }

        $row['created_at'] = date('Y-m-d H:i:s');
        return ['ok' => true, 'id' => (int) DB::table('gates_stand_types')->insertGetId($row),
                'message' => 'Stand type added.'];
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
