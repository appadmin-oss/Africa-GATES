<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

use AfricaGates\Admin\Services\ConsoleAlerts;
use AfricaGates\Admin\Services\ConsolePins;
use AfricaGates\Admin\Services\HomeBoard;

/**
 * Everything the console shell draws around a page, for the request in hand.
 *
 * `{{ console_shell() }}` in `admin/layout.twig` — a FUNCTION, not a global, for the
 * reason `cron_health` is one: the container builds its globals for every request,
 * public pages included, and only console pages draw a rail. Nothing here is queried
 * until the admin layout asks, and it asks once.
 *
 * One place answers every question the shell has — where am I, what may I open, what is
 * pinned, how many are waiting, is anything on fire — so the rail, the palette, the top
 * bar and the page header cannot each reach a different answer.
 */
final class ConsoleShell
{
    /** Pages remembered for the palette's "Recent" (the HTML keeps four). */
    private const RECENT = 4;

    /** @return array<string,mixed> */
    public static function context(?string $adminPage = null): array
    {
        $role    = (string) ($_SESSION['admin_role'] ?? '');
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/dashboard');
        $path    = (string) (parse_url($uri, PHP_URL_PATH) ?: '/admin/dashboard');

        $nav     = AdminNav::forRole($role);
        $current = AdminNav::current($path, $adminPage);
        $counts  = HomeBoard::counts($role);

        // The pin toggle pins THIS view — path and query — so a filtered list is its own pin.
        $here    = ConsolePins::normalise($uri) ?? ConsolePins::normalise($path);
        $pins    = ConsolePins::forAdmin($adminId, $role);
        $pinned  = null;
        foreach ($pins as $p) if ($here !== null && $p['href'] === $here) $pinned = $p;

        $page    = $current['page'];
        $item    = $current['item'];
        // The purpose line belongs to the page it describes, not to a record inside it:
        // "Everyone on a ballot…" over one nominee's edit form would be a sentence about
        // the wrong thing.
        $sub     = ($page !== null && $page['href'] === $path) ? ($page['sub'] ?? ($item['sub'] ?? null)) : null;

        return [
            'role'        => $role,
            'role_label'  => $role !== '' ? Permissions::label($role) : '',
            'role_blurb'  => Permissions::ROLES[$role]['blurb'] ?? '',
            'can_write'   => Permissions::canWrite($role),
            'name'        => (string) ($_SESSION['admin_name'] ?? 'Admin'),
            'email'       => (string) ($_SESSION['admin_email'] ?? ''),
            'initials'    => self::initials((string) ($_SESSION['admin_name'] ?? '')),
            'home'        => $nav['home'],
            'groups'      => self::fold($nav['groups'], $current),
            'current'     => $item['page'] ?? null,
            'current_page'=> $page['page'] ?? null,
            'label'       => $page['label'] ?? null,
            'sub'         => $sub,
            'related'     => AdminNav::related($role, $path, $adminPage),
            'here'        => $here,
            'pinned'      => $pinned,
            'pins'        => array_map(static function (array $p) use ($counts): array {
                $p['count'] = self::pinCount($p['href'], $counts);
                return $p;
            }, $pins),
            'counts'      => $counts,
            'alert_pill'  => ConsoleAlerts::pill($role),
            'sidebar_closed' => ConsolePins::sidebarClosed($adminId),
            'destinations'   => AdminNav::destinations($role),
            'recent'      => self::recent($role, $path, $page),
            'handbook'    => Permissions::canOpen($role, '/admin/handbook'),
            'assistant'   => Permissions::canOpen($role, '/admin/assistant'),
        ];
    }

    /**
     * §2.1's overflow: a group with more than five pages shows four and a "··· N more"
     * row — and is forced open when the current page is beyond the fourth, so arriving
     * anywhere shows where you are without a click.
     *
     * @param list<array<string,mixed>> $groups
     * @param array{item:?array<string,mixed>} $current
     * @return list<array<string,mixed>>
     */
    private static function fold(array $groups, array $current): array
    {
        $cur = $current['item']['page'] ?? null;
        foreach ($groups as &$g) {
            $n = count($g['items']);
            $g['fold'] = $n > AdminNav::FOLD_OVER;
            $at = array_search($cur, array_column($g['items'], 'page'), true);
            $g['forced_open'] = $g['fold'] && $at !== false && $at >= AdminNav::FOLD_SHOW;
            $g['hidden_count'] = $g['fold'] ? $n - AdminNav::FOLD_SHOW : 0;
            foreach ($g['items'] as $k => &$i) $i['folded'] = $g['fold'] && $k >= AdminNav::FOLD_SHOW;
            unset($i);
        }
        unset($g);
        return $groups;
    }

    /** A pin of a page that carries a rail count shows that count. */
    private static function pinCount(string $href, array $counts): ?int
    {
        $path = (string) parse_url($href, PHP_URL_PATH);
        return match ($path) {
            '/admin/nominations/review' => $counts['review'] ?? null,
            '/admin/payments'           => $counts['payments'] ?? null,
            '/admin/alerts'             => $counts['alerts'] ?? null,
            default                     => null,
        };
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('~\s+~', trim($name)) ?: [];
        $out = '';
        foreach (array_slice(array_values(array_filter($parts)), 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
        return $out !== '' ? $out : 'A';
    }

    /**
     * The palette's "Recent" — kept in the SESSION, not the browser: it is a console
     * convenience that needs no cookie-policy row, and it ends with the sign-in.
     *
     * @param array<string,mixed>|null $page
     * @return list<array{page:string, label:string, href:string}>
     */
    private static function recent(string $role, string $path, ?array $page): array
    {
        $list = is_array($_SESSION['cn_recent'] ?? null) ? $_SESSION['cn_recent'] : [];
        $shown = array_values(array_filter($list, static fn ($r): bool =>
            is_array($r) && ($r['href'] ?? '') !== $path && Permissions::canOpen($role, (string) ($r['href'] ?? ''))));

        if ($page !== null && $page['href'] === $path && session_status() === PHP_SESSION_ACTIVE) {
            $list = array_values(array_filter($list, static fn ($r): bool => is_array($r) && ($r['href'] ?? '') !== $path));
            array_unshift($list, ['page' => (string) $page['page'], 'label' => (string) $page['label'], 'href' => $path]);
            $_SESSION['cn_recent'] = array_slice($list, 0, self::RECENT + 1);
        }
        return array_slice($shown, 0, self::RECENT - 1);
    }
}
