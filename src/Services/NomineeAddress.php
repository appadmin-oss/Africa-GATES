<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Which email address, if any, belongs to a nominee.
 *
 * ── WHY THIS IS ITS OWN CLASS ────────────────────────────────────────────────
 *
 * `gates_nominees` has no email column — it is the ballot row. The address lives on the
 * NOMINATION that produced it, is optional there, and there is no foreign key between
 * the two tables. So answering "where do we write to this person" is a real piece of
 * reasoning with two sources and a tie-break, and it was private to
 * {@see NomineeBroadcast}.
 *
 * The invitation run needs the same answer. Copying it would put two implementations of
 * "who do we email" in a codebase whose own notes record what that costs: two readers of
 * one fact drift, and the way they drift on a mail sender is that one of them writes to
 * somebody the other already did — or worse, to the wrong person entirely.
 *
 * ── AMBIGUITY IS AN ANSWER, NOT A FAILURE ────────────────────────────────────
 *
 * The return is a LIST, and the count carries meaning the caller must respect:
 *
 *   0  unreachable — there is no address, and no message can be sent
 *   1  the address
 *   >1 two different people were nominated under the same name, and which of them is
 *      meant is not knowable from here. NEVER pick one. An invitation carrying somebody
 *      else's name, category and personal reference is worse than no invitation, and it
 *      cannot be recalled once it has arrived.
 */
final class NomineeAddress
{
    /**
     * @param object $nominee needs `name`, and `profile_id` when it has one
     * @return list<string> see the note above on what the count means
     */
    public static function candidates(object $nominee, int $cycleId): array
    {
        // A real link beats a name match: if the linked profile has an address, use it and
        // stop, so a same-name nomination elsewhere cannot make this ambiguous.
        if (!empty($nominee->profile_id)) {
            $e = DB::table('gates_profiles')->where('id', $nominee->profile_id)->value('email');
            if (is_string($e) && $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return [EmailOptOut::normalise($e)];
            }
        }

        // LOWER() on both sides: approval passes the name through Name::title() and the
        // nomination keeps whatever was typed.
        $rows = DB::table('gates_nominations')
            ->where('cycle_id', $cycleId)
            ->where('status', 'approved')
            ->whereNotNull('nominee_email')->where('nominee_email', '!=', '')
            ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [strtolower(trim((string) $nominee->name))])
            ->pluck('nominee_email')->all();

        $out = [];
        foreach ($rows as $e) {
            $e = EmailOptOut::normalise((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[$e] = true;
        }

        return array_keys($out);
    }

    /** The single unambiguous address, or '' for none and for more than one. */
    public static function one(object $nominee, int $cycleId): string
    {
        $found = self::candidates($nominee, $cycleId);

        return count($found) === 1 ? $found[0] : '';
    }
}
