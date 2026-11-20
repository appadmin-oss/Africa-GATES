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

    /**
     * Whether this request is the console fetching a page AHEAD of a click (console-nav.js,
     * on hover or touch). Nothing about it is a visit: a prefetch must not reorder the
     * palette's Recent or tell a colleague "Ada is viewing this" about a link Ada only
     * passed the pointer over. Read by every recorder of "who looked at what".
     */
    public static function prefetch(): bool
    {
        return ($_SERVER['HTTP_X_CONSOLE_PREFETCH'] ?? '') === '1';
    }

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
            'top'         => $nav['top'],
            'groups'      => self::rail($nav['groups'], $current, $counts),
            'current'     => $item['page'] ?? null,
            'current_page'=> $page['page'] ?? null,
            'label'       => $page['label'] ?? null,
            // The crumb's first half: the rail group the page sits in ("Money / Refunds").
            'group_label' => (string) ($item['group'] ?? ''),
            'tabs'        => self::tabs($role, $item['page'] ?? null, $counts),
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
            'create'      => self::create($role),
            'recent'      => self::recent($role, $path, $page),
            'handbook'    => Permissions::canOpen($role, '/admin/handbook'),
            'assistant'   => Permissions::canOpen($role, '/admin/assistant'),
        ];
    }

    /**
     * The rail's groups. Every group starts OPEN — the nav opens in full (owner, 20 Oct
     * 2026, over README §10b's idle-collapse) — and a person may close any of them; that
     * choice is kept in the browser (`ac2.col`, admin.js), except that the group holding
     * the current page is always open, so arriving anywhere shows where you are. A closed
     * group shows the sum of its counts, red if any of them is urgent.
     *
     * Every count comes from {@see HomeBoard::counts()}, the same read as Home, so the rail
     * and Home cannot disagree about how many are waiting.
     *
     * @param list<array<string,mixed>> $groups
     * @param array{item:?array<string,mixed>} $current
     * @param array<string,?int> $counts
     * @return list<array<string,mixed>>
     */
    private static function rail(array $groups, array $current, array $counts): array
    {
        $cur = $current['item']['page'] ?? null;
        $curPage = $current['page']['page'] ?? null;      // a sub-page standing in the rail
        foreach ($groups as &$g) {
            $sum = 0; $urgent = false; $here = false;
            foreach ($g['items'] as &$i) {
                [$n, $hot] = self::itemCount((string) $i['page'], $counts);
                $i['n'] = $n; $i['urgent'] = $hot && $n > 0;
                $sum += $n; $urgent = $urgent || $i['urgent'];
                $here = $here || $i['page'] === $cur || $i['page'] === $curPage;
            }
            unset($i);
            $g['here']   = $here;
            $g['count']  = $sum;
            $g['urgent'] = $urgent;
            // The rail opens in full (owner, 20 Oct 2026): every group starts expanded and
            // closes only when a person closes it (`ac2.col`, admin.js). The group holding
            // this page is always open, whatever was chosen before.
            $g['open']   = true;
        }
        unset($g);
        return $groups;
    }

    /**
     * A rail page's waiting count and whether it is urgent. Payments is urgent whenever it
     * has any: each one is money somebody paid with nothing to show for it. Alerts is
     * urgent only while one of them is high.
     *
     * @param array<string,?int> $counts
     * @return array{0:int, 1:bool}
     */
    private static function itemCount(string $page, array $counts): array
    {
        return match ($page) {
            'review'     => [(int) ($counts['review'] ?? 0), false],
            'payments'   => [(int) ($counts['payments'] ?? 0), true],
            'alerts'     => [(int) ($counts['alerts'] ?? 0), (int) ($counts['alerts_high'] ?? 0) > 0],
            'moderation' => [(int) ($counts['moderation'] ?? 0), false],
            default      => [0, false],
        };
    }

    /**
     * The top bar's New menu (README §10b): grouped Awards · Engage · Content, each item a
     * page that starts the thing. Only real create flows are listed, and only those this
     * role can open — the judges' routes also carry a superadmin-only RoleMiddleware, so
     * the guard's answer is not enough there. A group left empty is not drawn.
     *
     * @return list<array{label:string, items:list<array{label:string, href:string, icon:string}>}>
     */
    private static function create(string $role): array
    {
        $menu = [
            'Awards'  => [['New programme', '/admin/programmes/new', 'trophy'],
                          ['Invite judge', '/admin/judges/new', 'users', 'superadmin'],
                          ['Release results', '/admin/result-release', 'flag']],
            'Engage'  => [['New event', '/admin/events/new', 'ticket'],
                          ['New challenge', '/admin/challenges/new', 'bolt'],
                          ['New broadcast', '/admin/campaigns', 'mega']],
            'Content' => [['New post', '/admin/posts/new', 'doc'],
                          ['Upload media', '/admin/media', 'image']],
        ];
        $out = [];
        foreach ($menu as $label => $rows) {
            $items = [];
            foreach ($rows as $r) {
                if (isset($r[3]) && $role !== $r[3]) continue;
                if (!Permissions::canOpen($role, $r[1])) continue;
                $items[] = ['label' => $r[0], 'href' => $r[1], 'icon' => $r[2]];
            }
            if ($items !== []) $out[] = ['label' => $label, 'items' => $items];
        }
        return $out;
    }

    /**
     * The phone's tab bar (README §10b): Home · Review · Money · Alerts, then More, which
     * opens the whole rail as a sheet. A tab this role cannot open is left out rather than
     * drawn disabled, so a bar of three is honest about what the role reaches.
     *
     * @param array<string,?int> $counts
     * @return list<array{label:string, href:string, icon:string, on:bool, n:int, urgent:bool}>
     */
    private static function tabs(string $role, ?string $current, array $counts): array
    {
        $out = [];
        foreach ([['Home', '/admin/dashboard', 'home', 'dashboard'],
                  ['Review', '/admin/nominations/review', 'noms', 'review'],
                  ['Money', '/admin/payments', 'card', 'payments'],
                  ['Alerts', '/admin/alerts', 'bell', 'alerts']] as [$label, $href, $icon, $page]) {
            if (!Permissions::canOpen($role, $href)) continue;
            [$n, $hot] = self::itemCount($page, $counts);
            $out[] = ['label' => $label, 'href' => $href, 'icon' => $icon, 'on' => $current === $page,
                      'n' => $n, 'urgent' => $hot && $n > 0];
        }
        return $out;
    }

    /** A pin of a page that carries a rail count shows that count. */
    private static function pinCount(string $href, array $counts): ?int
    {
        $path = (string) parse_url($href, PHP_URL_PATH);
        return match ($path) {
            '/admin/nominations/review' => $counts['review'] ?? null,
            '/admin/payments'           => $counts['payments'] ?? null,
            '/admin/alerts'             => $counts['alerts'] ?? null,
            '/admin/moderation'         => $counts['moderation'] ?? null,
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

        if ($page !== null && $page['href'] === $path && session_status() === PHP_SESSION_ACTIVE && !self::prefetch()) {
            $list = array_values(array_filter($list, static fn ($r): bool => is_array($r) && ($r['href'] ?? '') !== $path));
            array_unshift($list, ['page' => (string) $page['page'], 'label' => (string) $page['label'], 'href' => $path]);
            $_SESSION['cn_recent'] = array_slice($list, 0, self::RECENT + 1);
        }
        return array_slice($shown, 0, self::RECENT - 1);
    }
}
