<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Support\EmailCanon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * May this reviewer decide this nomination? Not when their own inbox is on it.
 *
 * A reviewer who nominated somebody — or who IS the nominee — approving it is the one
 * conflict of interest a nomination platform cannot explain away, and nothing stopped it:
 * every decision checked the reviewer's role and nothing about who they are. The answer is
 * an exact comparison of inboxes (Support\EmailCanon, so "+tag" and Gmail dots are one
 * person), never of names — two people share a name far more often than an inbox.
 *
 * It refuses deciding, merging, ruling "not a duplicate" and changing the nominee's
 * contact, because each of those moves the nomination; reading it, noting on it and
 * skipping it stay open. A colleague decides it, and the audit log shows who did.
 */
final class ReviewConflict
{
    /** @var array<int,?string> admin id => inbox key, per request */
    private static array $inbox = [];

    /** Actions that move a nomination, and so are refused on a conflict. */
    public const GUARDED = ['decide', 'approve', 'ask', 'reject', 'pending', 'merge', 'apart', 'contact'];

    /** The sentence to show, or null when there is no conflict. */
    public static function of(int $adminId, object|array $nom): ?string
    {
        $nom = (object) $nom;
        $mine = self::inbox($adminId);
        if ($mine === null) return null;
        if (EmailCanon::hash((string) ($nom->nominator_email ?? '')) === $mine) {
            return 'You nominated this person, so a colleague has to decide it.';
        }
        if (EmailCanon::hash((string) ($nom->nominee_email ?? '')) === $mine) {
            return 'This nomination names your own email as the nominee’s, so a colleague has to decide it.';
        }
        return null;
    }

    public static function reset(): void
    {
        self::$inbox = [];
    }

    private static function inbox(int $adminId): ?string
    {
        if ($adminId < 1) return null;
        if (!array_key_exists($adminId, self::$inbox)) {
            try {
                self::$inbox[$adminId] = EmailCanon::hash((string) (DB::table('gates_admins')->where('id', $adminId)->value('email') ?? ''));
            } catch (\Throwable) {
                self::$inbox[$adminId] = null;
            }
        }
        return self::$inbox[$adminId];
    }
}
