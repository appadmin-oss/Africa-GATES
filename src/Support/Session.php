<?php
declare(strict_types=1);

namespace AfricaGates\Support;

final class Session
{
    /**
     * Rotate the PHP session id (preserving session data) to defeat session
     * fixation. Called on every privilege transition (login). No-op when there
     * is no active session, so it is safe to call from CLI/tests.
     */
    public static function rotate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
