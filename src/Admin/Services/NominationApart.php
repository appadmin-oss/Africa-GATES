<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\NominationTriageService;
use AfricaGates\Support\SchemaHas;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A reviewer's "Not a duplicate" ruling, kept as the PAIRS it was about.
 *
 * The ruling used to be a flag on one nomination, and the queue then dropped that
 * nomination from every duplicate group — so ruling "these two are different people"
 * also silently cleared every later entry with the same name, including a genuine
 * repeat of either. A ruling covers exactly the entries the reviewer had in front of
 * them: the same-name entries in the edition at the moment they ruled. Anything that
 * arrives afterwards is flagged again, because nobody has looked at it.
 */
final class NominationApart
{
    public static function key(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    /**
     * Record the ruling against every same-name entry in the edition right now.
     * Returns how many pairs were written (0 where the table is not migrated yet).
     */
    public static function record(object $nom, int $adminId): int
    {
        if (!SchemaHas::table('gates_nomination_apart')) return 0;
        $id = (int) $nom->id;
        $key = NominationTriageService::nameKey((string) $nom->nominee_name);
        $n = 0;
        foreach (DB::table('gates_nominations')->where('cycle_id', (int) $nom->cycle_id)
                     ->where('id', '!=', $id)->get(['id', 'nominee_name']) as $o) {
            if (NominationTriageService::nameKey((string) $o->nominee_name) !== $key) continue;
            $n += DB::table('gates_nomination_apart')->insertOrIgnore([
                'pair_key'     => self::key($id, (int) $o->id),
                'nomination_a' => min($id, (int) $o->id),
                'nomination_b' => max($id, (int) $o->id),
                'ruled_by'     => $adminId > 0 ? $adminId : null,
                'created_at'   => Carbon::now()->toDateTimeString(),
            ]);
        }
        return $n;
    }

    /**
     * The ruled pairs among these nominations, keyed by {@see key()}.
     *
     * @param list<int> $ids
     * @return array<string,true>
     */
    public static function among(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || !SchemaHas::table('gates_nomination_apart')) return [];
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::table('gates_nomination_apart')->whereIn('nomination_a', $chunk)
                             ->get(['nomination_a', 'nomination_b']) as $p) {
                    $out[self::key((int) $p->nomination_a, (int) $p->nomination_b)] = true;
                }
            }
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * Of these nominations flagged by the old one-row ruling, the ones with no pair
     * recorded — ruled before pairs existed. They keep the old meaning (out of every
     * group) so nothing already ruled starts flagging again overnight.
     *
     * @param list<int> $flagged
     * @return array<int,true>
     */
    public static function legacy(array $flagged): array
    {
        $flagged = array_values(array_unique(array_filter(array_map('intval', $flagged))));
        if ($flagged === []) return [];
        if (!SchemaHas::table('gates_nomination_apart')) return array_fill_keys($flagged, true);
        $paired = [];
        try {
            foreach (array_chunk($flagged, 500) as $chunk) {
                foreach (DB::table('gates_nomination_apart')->whereIn('nomination_a', $chunk)->pluck('nomination_a') as $a) $paired[(int) $a] = true;
                foreach (DB::table('gates_nomination_apart')->whereIn('nomination_b', $chunk)->pluck('nomination_b') as $b) $paired[(int) $b] = true;
            }
        } catch (\Throwable) {}
        $out = [];
        foreach ($flagged as $f) if (!isset($paired[$f])) $out[$f] = true;
        return $out;
    }
}
