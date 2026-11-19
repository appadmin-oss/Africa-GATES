<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Duplicate an event as a new DRAFT — "the same ceremony, next year" (11 Nov 2026).
 *
 * Eventbrite and Tito both offer this, and an awards platform needs it more than either: the
 * ceremony recurs every edition with the same venue, the same ticket ladder, the same refund
 * terms and ticket design, and rebuilding it by hand is how last year's refund policy or
 * a tier's capacity comes to be typed differently from what the organiser meant.
 *
 * WHAT IS COPIED is the event's DESIGN: its page, its ticket tiers, its policies, its
 * series (so the event plan can compare it with the one it was copied from). WHAT NEVER IS:
 * anybody's ticket, payment, check-in, invite or lead — people belong to an occurrence — and
 * a tier's access code, which is a secret somebody was handed for the original event and
 * must not start opening a second one. The copy is a draft, so nothing is public until
 * somebody has set its dates and published it.
 */
final class EventCopy
{
    /** Columns that belong to one occurrence, not to the design. */
    private const NOT_COPIED = ['id', 'slug', 'status', 'created_at', 'spotlight_rank', 'recording_url', 'livestream_url'];

    /** @return array{ok:bool, id?:int, message:string} */
    public static function copy(int $eventId): array
    {
        $src = DB::table('gates_site_events')->where('id', $eventId)->first();
        if (!$src) return ['ok' => false, 'message' => 'That event no longer exists.'];
        $row = array_diff_key((array) $src, array_flip(self::NOT_COPIED));
        $row['title'] = mb_substr(trim((string) $src->title) . ' (copy)', 0, 200);
        $row['status'] = 'draft';
        $row['slug'] = self::freeSlug((string) $src->slug);
        if (SchemaHas::column('gates_site_events', 'created_at')) $row['created_at'] = date('Y-m-d H:i:s');

        $newId = 0;
        DB::connection()->transaction(function () use ($row, $eventId, &$newId) {
            $newId = (int) DB::table('gates_site_events')->insertGetId($row);
            if (SchemaHas::table('gates_event_tiers')) {
                $now = date('Y-m-d H:i:s');
                foreach (DB::table('gates_event_tiers')->where('event_id', $eventId)->orderBy('id')->get() as $t) {
                    $tier = array_diff_key((array) $t, array_flip(['id', 'created_at', 'updated_at']));
                    $tier['event_id'] = $newId;
                    if (array_key_exists('access_code', $tier)) $tier['access_code'] = null;
                    if (array_key_exists('created_at', (array) $t)) $tier['created_at'] = $now;
                    if (array_key_exists('updated_at', (array) $t)) $tier['updated_at'] = $now;
                    DB::table('gates_event_tiers')->insert($tier);
                }
            }
        });
        return ['ok' => true, 'id' => $newId, 'message' => 'Copied as a draft. Set its dates, then publish it when it is ready.'];
    }

    /** `ceremony` → `ceremony-copy`, `ceremony-copy-2`, … never one already taken. */
    private static function freeSlug(string $slug): string
    {
        $base = mb_substr(preg_replace('/-copy(-\d+)?$/', '', $slug) ?: 'event', 0, 170) . '-copy';
        $try = $base;
        for ($i = 2; DB::table('gates_site_events')->where('slug', $try)->exists(); $i++) $try = $base . '-' . $i;
        return $try;
    }

    /**
     * Why an event may not be deleted, or null. An event somebody holds a ticket for is a
     * record of their purchase: deleting it took away their ticket page while their money
     * had already moved — the list's own confirm said "ticket holders will lose the event
     * page", which is a description of the fault rather than a defence against it.
     */
    public static function inUse(int $eventId): ?string
    {
        if (!SchemaHas::table('gates_event_registrations')) return null;
        $n = (int) DB::table('gates_event_registrations')->where('event_id', $eventId)->count();
        return $n > 0 ? $n . ' registration' . ($n === 1 ? '' : 's') . ' belong' . ($n === 1 ? 's' : '') . ' to it' : null;
    }
}
