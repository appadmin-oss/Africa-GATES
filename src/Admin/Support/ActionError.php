<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

/**
 * Shared translation of an unexpected DB write failure into an actionable admin
 * flash message instead of a raw 500.
 *
 * The overwhelmingly common cause of an admin "action 500" is an unapplied
 * migration — a write touches a column/table a stale database doesn't have yet,
 * while reads keep working — so name that first. Callers wrap the write in a
 * try/catch, flash this message, and redirect back; no partial write should be
 * committed (wrap multi-statement writes in a transaction).
 */
final class ActionError
{
    public static function dbMessage(\Throwable $e): string
    {
        try {
            $pending = \AfricaGates\Services\MigrationRunner::status()['pending'] ?? [];
            if (!empty($pending)) {
                return 'This action needs a pending database update that has not been applied yet, so nothing was saved. '
                     . 'A superadmin can apply it from Settings (or run `php bin/console db:migrate`), then try again.';
            }
        } catch (\Throwable) { /* status probe failed — fall through to the generic message */ }
        return 'Something went wrong saving that change, so nothing was saved. Please try again — if it keeps happening, contact support.';
    }
}
