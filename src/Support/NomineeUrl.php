<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The canonical ballot URL for a nominee, resolved from an id alone.
 *
 * WHY IT IS SHARED. The shape `/vote/{programme-slug}/{id}-{name-slug}` was built
 * independently in `VoteController::nomineeUrl()` (from an already-loaded name and
 * slug) and in `PaidVoteController::ballotUrl()` (from a nominee row, with its own
 * three-table join). Adding a third copy for transactional email is how the link a
 * buyer receives ends up differing from the link the page they came from uses —
 * and this one goes out over email, where a wrong URL cannot be fixed after the
 * fact.
 *
 * Resolution is by the LEADING ID, so the name segment is cosmetic and a stale
 * slug still lands on the right ballot; an unresolvable nominee degrades to
 * `/vote`, never to a 404.
 */
final class NomineeUrl
{
    /**
     * Absolute ballot URL for a nominee id.
     *
     * Absolute because every caller that needs this — email, an og:image, a gateway
     * callback — is somewhere a relative path is silently useless.
     */
    public static function ballot(int $nomineeId, ?string $base = null): string
    {
        $base = $base !== null ? rtrim($base, '/') : SiteUrl::base();
        $path = self::path($nomineeId);
        return $base . $path;
    }

    /** The path part only. `/vote` when the nominee cannot be resolved. */
    public static function path(int $nomineeId): string
    {
        if ($nomineeId < 1) return '/vote';
        try {
            $row = DB::table('gates_nominees as n')
                ->join('gates_award_categories as cat', 'cat.id', '=', 'n.category_id')
                ->join('gates_award_cycles as c', 'c.id', '=', 'cat.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('n.id', $nomineeId)
                ->select(['n.id', 'n.name', 'p.slug as programme_slug'])
                ->first();
            if (!$row || empty($row->programme_slug)) return '/vote';

            // Slug::idSegment, never a local preg_replace: the hand-rolled
            // `[^a-z0-9]+` versions DELETE accented letters instead of folding them,
            // so "Ọlásùnkànmí Adébáyọ̀" became "l-s-nk-nm-ad-b-y". The link still
            // resolves — the id leads — which is exactly why it survived unnoticed.
            return '/vote/' . $row->programme_slug . '/' . Slug::idSegment((int) $row->id, (string) $row->name);
        } catch (\Throwable) {
            return '/vote';
        }
    }
}
