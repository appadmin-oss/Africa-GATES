<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\Permissions;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * An admin's pinned views and sidebar state (README §2.1: "Pins are stored per user";
 * the sidebar toggle "persists"). Stored server-side — see the migration
 * `2027_03_01_admin_console_pins.php` for why not the browser.
 *
 * ── A PIN IS A PLACE, AND THE PLACE IS CHECKED EVERY TIME IT IS SHOWN ────────
 *
 * What is stored is a path (with its query, so "nominations waiting review" and "all
 * nominations" are two pins). It is re-checked against the guard on every READ, not
 * only when it was written: a role changes, a page moves gate, and a pin made last month
 * must not become a link the guard bounces — rule 7, "pages the role cannot open MUST NOT
 * render in the sidebar". The row survives; it simply is not drawn until it can be
 * opened again.
 */
final class ConsolePins
{
    /** A sidebar, not a bookmarks manager — past this the rail stops being scannable. */
    public const MAX = 12;

    /**
     * Normalise a requested href to something worth storing: an admin path, its query
     * kept, nothing else. Null for anything that is not one of our console pages.
     */
    public static function normalise(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || strlen($href) > 255) return null;
        $parts = parse_url($href);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) return null;
        $path = (string) ($parts['path'] ?? '');
        if (!preg_match('~^/admin/[a-z0-9/_-]+$~', $path)) return null;
        if (Permissions::isUtilityPath($path)) return null;

        return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }

    /**
     * Pins this admin may currently open, oldest first.
     *
     * @return list<array{id:int, href:string, label:string}>
     */
    public static function forAdmin(int $adminId, string $role): array
    {
        if ($adminId < 1) return [];
        try {
            $rows = DB::table('gates_admin_pins')->where('admin_id', $adminId)
                ->orderBy('id')->limit(self::MAX)->get(['id', 'href', 'label']);
        } catch (\Throwable) {
            return [];   // unmigrated: no pins, never a broken page
        }
        $out = [];
        foreach ($rows as $r) {
            $href = (string) $r->href;
            if (!Permissions::canOpen($role, $href)) continue;
            $out[] = ['id' => (int) $r->id, 'href' => $href, 'label' => (string) $r->label];
        }
        return $out;
    }

    /** The pin for this exact href, or null. */
    public static function find(int $adminId, string $href): ?array
    {
        $h = self::normalise($href);
        if ($h === null || $adminId < 1) return null;
        try {
            $r = DB::table('gates_admin_pins')->where('admin_id', $adminId)->where('href', $h)->first(['id', 'href', 'label']);
        } catch (\Throwable) {
            return null;
        }
        return $r ? ['id' => (int) $r->id, 'href' => (string) $r->href, 'label' => (string) $r->label] : null;
    }

    /**
     * Pin a view. Idempotent: pinning what is already pinned returns the existing pin.
     *
     * @return array{ok:bool, id?:int, why?:string}
     */
    public static function add(int $adminId, string $role, string $href, string $label): array
    {
        $h = self::normalise($href);
        if ($adminId < 1 || $h === null) return ['ok' => false, 'why' => 'That is not a console page.'];
        if (!Permissions::canOpen($role, $h)) return ['ok' => false, 'why' => 'Your role cannot open that page.'];

        $label = trim(preg_replace('~\s+~', ' ', strip_tags($label)) ?? '');
        if ($label === '') $label = 'Pinned view';
        $label = mb_substr($label, 0, 120);

        if ($have = self::find($adminId, $h)) return ['ok' => true, 'id' => $have['id']];

        try {
            if ((int) DB::table('gates_admin_pins')->where('admin_id', $adminId)->count() >= self::MAX) {
                return ['ok' => false, 'why' => 'You have ' . self::MAX . ' pins. Unpin one first.'];
            }
            $id = (int) DB::table('gates_admin_pins')->insertGetId([
                'admin_id' => $adminId, 'href' => $h, 'label' => $label,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            // A unique-key race between two tabs is "already pinned", not a failure.
            $have = self::find($adminId, $h);
            return $have ? ['ok' => true, 'id' => $have['id']] : ['ok' => false, 'why' => 'Pins are not available yet.'];
        }
        return ['ok' => true, 'id' => $id];
    }

    /** Unpin. Only ever this admin's own row. */
    public static function remove(int $adminId, int $id): ?array
    {
        if ($adminId < 1 || $id < 1) return null;
        try {
            $r = DB::table('gates_admin_pins')->where('id', $id)->where('admin_id', $adminId)->first(['id', 'href', 'label']);
            if (!$r) return null;
            DB::table('gates_admin_pins')->where('id', $id)->where('admin_id', $adminId)->delete();
        } catch (\Throwable) {
            return null;
        }
        return ['id' => (int) $r->id, 'href' => (string) $r->href, 'label' => (string) $r->label];
    }

    public static function sidebarClosed(int $adminId): bool
    {
        if ($adminId < 1) return false;
        try {
            return (int) DB::table('gates_admin_prefs')->where('admin_id', $adminId)->value('sidebar_closed') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setSidebarClosed(int $adminId, bool $closed): bool
    {
        if ($adminId < 1) return false;
        try {
            DB::table('gates_admin_prefs')->updateOrInsert(
                ['admin_id' => $adminId],
                ['sidebar_closed' => $closed ? 1 : 0, 'updated_at' => Carbon::now()->toDateTimeString()]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
