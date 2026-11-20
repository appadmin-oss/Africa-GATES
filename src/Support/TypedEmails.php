<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The email addresses THIS browser has typed into the site this session — and so the only
 * addresses an assistant may answer personal questions about for somebody who is not
 * signed in.
 *
 * The help desk's `when_did_i_vote` and `sign_in_help` answer "did a vote register for this
 * address" and "is this address locked out". Asked about the visitor's own address, that is
 * the help they came for. Asked about anybody else's, it was an oracle: type a colleague's
 * address and learn whether, when and in which category they voted, or that somebody has
 * been hammering their sign-in. A guest has no verified identity — but the browser that
 * just requested a vote code for an address, or just tried to sign in with it, is the one
 * person entitled to ask about it. Hashes only, five at most, for the session's life.
 */
final class TypedEmails
{
    private const KEY = 'ag_typed_emails';
    private const MAX = 5;

    public static function remember(string $email): void
    {
        $e = mb_strtolower(trim($email));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) return;
        $h = hash('sha256', $e);
        $list = array_values(array_filter((array) ($_SESSION[self::KEY] ?? []), 'is_string'));
        $list = array_values(array_diff($list, [$h]));
        $list[] = $h;
        $_SESSION[self::KEY] = array_slice($list, -self::MAX);
    }

    /**
     * May this session ask about this address? The signed-in member's own address, or one
     * this browser typed at the vote code or the sign-in form.
     */
    public static function owns(string $email, ?string $memberEmail = null): bool
    {
        $e = mb_strtolower(trim($email));
        if ($e === '') return false;
        if ($memberEmail !== null && $e === mb_strtolower(trim($memberEmail))) return true;
        foreach (['user_login_email', 'pending_verify_email'] as $k) {
            if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && $e === mb_strtolower(trim($_SESSION[$k]))) return true;
        }
        return in_array(hash('sha256', $e), (array) ($_SESSION[self::KEY] ?? []), true);
    }
}
