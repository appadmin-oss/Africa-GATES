<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHICH PHOTOGRAPH A NOMINEE'S PAGE SHOWS, AND THE ACCOUNT IT BELONGS TO — ONE ANSWER.
 *
 * Owner, 5 Nov 2026: "photos uploaded are not reflecting on the nominee page … that photo should
 * be connected to their Africa GATES account". Every public surface (the ballot, cards, the
 * leaderboard, share images) reads `gates_nominees.photo_path`, and NOTHING a nominee or a
 * nominator uploaded ever reached it:
 *
 *   · the nomination form stored its portrait on `gates_nominations.nominee_photo_path`, with a
 *     comment saying it would "on approval, seed the profile avatar" — and approval built the
 *     nominee row field by field and left the photo out;
 *   · a nominee adding a photograph on their own page (NomineeDetails, "It is what people see on
 *     your ballot page") filed it in `gates_nominee_files`, which no public page reads.
 *
 * Only an administrator uploading from the console ever set the column. So the photo shown is
 * decided HERE, in one order, and every writer calls {@see sync()} rather than setting the
 * column itself:
 *
 *   1. the nominee's OWN most recent photograph — it is their face, and they chose it;
 *   2. otherwise whatever is already set (an administrator's upload, or the nominator's);
 *   3. otherwise the nominator's photograph from the nomination.
 *
 * A photograph the nominee REMOVES stops being used at once: if it was the one showing, the
 * page falls back down the same order rather than keeping a picture somebody took down.
 *
 * ── AND THE ACCOUNT ───────────────────────────────────────────────────────────
 * A nominee page is held by one Africa GATES account (`gates_nominee_details.user_id`). The
 * photo the nominee uploads is that account's photo too (`gates_users.photo_path`), shown as
 * their avatar wherever they are signed in; a photo somebody ELSE supplied (a nominator, staff)
 * only fills an account that has none, because putting a stranger's choice of picture on a
 * person's own account is not ours to do. Changing it later is the nominee's: a new upload.
 */
final class NomineePhoto
{
    /** The nominee's own live photographs, newest first. @return list<string> */
    private static function own(int $nomineeId): array
    {
        if (!SchemaHas::table('gates_nominee_files')) return [];
        return DB::table('gates_nominee_files')->where('nominee_id', $nomineeId)->where('kind', 'photo')
            ->whereNull('removed_at')->whereNotNull('path')->orderByDesc('id')->pluck('path')->map('strval')->all();
    }

    /** Every photograph the nominee ever uploaded, removed ones included. @return list<string> */
    private static function everOwn(int $nomineeId): array
    {
        if (!SchemaHas::table('gates_nominee_files')) return [];
        return DB::table('gates_nominee_files')->where('nominee_id', $nomineeId)->where('kind', 'photo')
            ->whereNotNull('path')->pluck('path')->map('strval')->all();
    }

    /** The nominator's photograph, from the nomination this nominee was approved from. */
    private static function nominators(int $nomineeId, ?object $nom = null): string
    {
        if ($nom !== null) return trim((string) ($nom->nominee_photo_path ?? ''));
        if (!SchemaHas::column('gates_nominations', 'nominee_photo_path')) return '';
        $nid = SchemaHas::table('gates_nominee_details')
            ? (int) DB::table('gates_nominee_details')->where('nominee_id', $nomineeId)->value('nomination_id') : 0;
        if ($nid > 0) {
            $p = trim((string) DB::table('gates_nominations')->where('id', $nid)->value('nominee_photo_path'));
            if ($p !== '') return $p;
        }
        // No details row (approved before those existed, or never emailed): the approved
        // nomination in the same category under the same name — the match approval itself uses.
        $n = DB::table('gates_nominees')->where('id', $nomineeId)->first(['name', 'category_id']);
        if (!$n) return '';
        foreach (DB::table('gates_nominations')->where('category_id', $n->category_id)->where('status', 'approved')
                     ->whereNotNull('nominee_photo_path')->where('nominee_photo_path', '!=', '')
                     ->orderBy('id')->get(['nominee_name', 'nominee_photo_path']) as $r) {
            if (mb_strtolower(\AfricaGates\Support\Name::title((string) $r->nominee_name)) === mb_strtolower((string) $n->name)) {
                return trim((string) $r->nominee_photo_path);
            }
        }
        return '';
    }

    /**
     * Decide and store the photo for one nominee, and hand it to the account that holds them.
     * Returns the path now showing ('' for none). `$nom` is the nomination when the caller has
     * it in hand (approval), so nothing is looked up twice.
     */
    public static function sync(int $nomineeId, ?object $nom = null): string
    {
        $row = DB::table('gates_nominees')->where('id', $nomineeId)->first(['id', 'photo_path']);
        if (!$row) return '';
        $current = trim((string) ($row->photo_path ?? ''));
        $own = self::own($nomineeId);

        if ($own !== []) {
            $want = $own[0];
            $fromNominee = true;
        } elseif ($current !== '' && !in_array($current, self::everOwn($nomineeId), true)) {
            $want = $current;                       // staff's or the nominator's: kept
            $fromNominee = false;
        } else {
            $want = self::nominators($nomineeId, $nom);   // nothing, or theirs was removed
            $fromNominee = false;
        }

        if ($want !== $current) {
            DB::table('gates_nominees')->where('id', $nomineeId)->update(['photo_path' => $want !== '' ? $want : null]);
        }
        if ($want !== '') self::toAccount($nomineeId, $want, $fromNominee);
        return $want;
    }

    /**
     * The account holding this nominee gets the photo: always when the nominee uploaded it,
     * only into an empty account photo when somebody else did.
     */
    private static function toAccount(int $nomineeId, string $path, bool $fromNominee): void
    {
        if (!SchemaHas::table('gates_nominee_details') || !SchemaHas::column('gates_users', 'photo_path')) return;
        $userId = (int) DB::table('gates_nominee_details')->where('nominee_id', $nomineeId)->value('user_id');
        if ($userId < 1) return;
        $q = DB::table('gates_users')->where('id', $userId);
        if (!$fromNominee) $q->where(static fn ($w) => $w->whereNull('photo_path')->orWhere('photo_path', ''));
        if ($q->update(['photo_path' => $path]) > 0 && (int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $_SESSION['user_photo'] = $path;
        }
    }

    /** A member account's photo, or '' — for the session at sign-in. */
    public static function forUser(int $userId): string
    {
        if ($userId < 1 || !SchemaHas::column('gates_users', 'photo_path')) return '';
        return trim((string) DB::table('gates_users')->where('id', $userId)->value('photo_path'));
    }
}
