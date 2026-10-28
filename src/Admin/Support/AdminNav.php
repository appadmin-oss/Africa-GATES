<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

/**
 * The admin console's navigation, in one place — rebuilt from the admin handoff (README
 * §2.2) on 4 Oct 2026.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * GROUPED BY TASK, GATED BY PAGE — AND THE GATE IS NOT TYPED HERE AT ALL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The rail used to be seven sections, one per permission gate, and that file argued
 * seven was a floor: "a section can carry only one gate, so fewer sections would move a
 * page to a different gate". The handoff's rule 7 removes the premise — **access is per
 * page, not per sidebar group** — so the groups are named for the job ("Awards", "Engage",
 * "Money") and each PAGE carries its own gate.
 *
 * And that gate is not typed here. The old tree typed one per section while the guard
 * ({@see \AfricaGates\Admin\Middleware\SectionGuardMiddleware}) read the PATH, and the
 * two had drifted on fifteen pages: the rail offered Handbook, Support tickets, Audit
 * log, Integrity, Payouts and ten more to roles the guard then bounced, because those
 * paths were never mapped and an unmapped path fails closed to superadmin. So here every
 * item's gate is {@see Permissions::sectionForPath()} of its own href, and visibility is
 * {@see Permissions::canOpen()} — the guard's own function. The rail, the palette, Home's
 * shortcuts and its "needs a person" list all ask it; none of them can offer a door the
 * guard will close, by construction rather than by care.
 *
 * Moving a page between groups therefore changes nothing about who can open it, which is
 * exactly what rule 7 asks. `AdminNavTest` diffs every page's gate against the mapping
 * recorded before this rebuild: only the three `health` pages may differ (an
 * owner-approved widening, GAPS §8d).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE TAIL IS LINKED FROM ITS PARENT, NOT ADDED TO THE RAIL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The rail names the pages AdminConsole v2 (README §10b) puts in it; the console has more.
 * Each of the rest is a CHILD of the page it belongs under — the rubric under Judges,
 * Disputes under Refunds, Email health under Integrations — and is drawn as the "also
 * here" strip under that page's header, and in the palette. That is CLAUDE.md's "a
 * sub-page is linked from the page it belongs under": the rail stays scannable and every
 * page stays findable (`AdminIaTest`).
 *
 * Groups and labels are v2's. Where v2 names a page that does not exist yet (Inbox,
 * Check-in) the rail leaves it out until it is routed; the list of
 * deviations is in docs/handoff/PHASE-ADMIN.md.
 */
final class AdminNav
{
    /** The one item above the groups. */
    private const HOME = [
        'page' => 'dashboard', 'label' => 'Home', 'href' => '/admin/dashboard', 'icon' => 'home',
        'sub'  => 'Every job waiting on a person, across everything your role can reach, most urgent first.',
    ];

    /**
     * The rail's top items after Home — v2's Workspace (README §10b). Not a group: no
     * label, never collapsed, because these are the pages a day starts from.
     */
    private const TOP = [
        ['page' => 'calendar', 'label' => 'Calendar', 'href' => '/admin/calendar', 'icon' => 'cal',
         'sub' => 'Every deadline, event day and draw across programmes, in date order.'],
    ];

    /**
     * AdminConsole v2's rail (README §10b, 20 Oct 2026), grouped by job, in order. `sub` is
     * the page's purpose line; `tip` is the group's, used where a page has none of its own.
     * Every page the rail does not name is a CHILD of the page it belongs under.
     *
     * @var list<array{key:string, label:string, tip:string, items:list<array<string,mixed>>}>
     */
    private const GROUPS = [
        ['key' => 'awards', 'label' => 'Awards', 'tip' => 'Each edition from nominations to sealed results.', 'items' => [
            ['page' => 'programmes', 'label' => 'Programmes', 'href' => '/admin/programmes', 'icon' => 'trophy',
             'sub' => 'Every award on the platform, and each edition\'s dates and rules.',
             'children' => [
                 ['page' => 'awards_page', 'label' => 'Awards page', 'href' => '/admin/awards-page'],
             ]],
            // The handoff's "Nominations" is the review screen; the full record stays a child.
            // `match`: a nomination's own page (/admin/nominations/42) sits under this item —
            // the only list of nominations since "All nominations" was retired.
            ['page' => 'review', 'label' => 'Nominations', 'href' => '/admin/nominations/review', 'match' => '/admin/nominations', 'icon' => 'noms',
             'sub' => 'Nominations wait here until a person approves them.',
             'children' => [
                 ['page' => 'desk',           'label' => 'Review desk',     'href' => '/admin/nominations/desk'],
                 ['page' => 'nominees',       'label' => 'Nominees',        'href' => '/admin/nominees'],
                 ['page' => 'duplicates',     'label' => 'Duplicates',      'href' => '/admin/nominees/duplicates'],
                 ['page' => 'profiles',       'label' => 'Profiles',        'href' => '/admin/profiles'],
                 ['page' => 'interviews',     'label' => 'Interviews',      'href' => '/admin/interviews'],
                 ['page' => 'questionnaires', 'label' => 'Questionnaires',  'href' => '/admin/questionnaires'],
                 ['page' => 'invitations',    'label' => 'Invitations',     'href' => '/admin/questionnaires/invitations'],
             ]],
            ['page' => 'shortlists', 'label' => 'Shortlists', 'href' => '/admin/shortlists', 'icon' => 'list',
             'sub' => 'Who goes through to judging in each category.'],
            ['page' => 'judges', 'label' => 'Judges', 'href' => '/admin/judges', 'icon' => 'users',
             'sub' => 'Judging panels and who still owes a scorecard.',
             'children' => [
                 ['page' => 'judge-schedule', 'label' => 'Judging schedule', 'href' => '/admin/judges/schedule'],
                 ['page' => 'rubric',        'label' => 'Judging rubric', 'href' => '/admin/rubric'],
                 ['page' => 'judging-audit', 'label' => 'Judging audit',  'href' => '/admin/judging-audit'],
             ]],
            ['page' => 'result-release', 'label' => 'Result release', 'href' => '/admin/result-release', 'icon' => 'flag',
             'sub' => 'What each edition will publish, and the release itself.',
             'children' => [
                 ['page' => 'recognitions', 'label' => 'Recognitions', 'href' => '/admin/recognitions'],
             ]],
        ]],
        ['key' => 'engage', 'label' => 'Engage', 'tip' => 'Events, challenges, giving and forms.', 'items' => [
            ['page' => 'events', 'label' => 'Events', 'href' => '/admin/events', 'icon' => 'ticket',
             'sub' => 'Every event on the platform, with tickets and stands.',
             'children' => [
                 ['page' => 'stand_presets', 'label' => 'Stand presets',       'href' => '/admin/stand-presets'],
                 ['page' => 'registrations', 'label' => 'Event registrations', 'href' => '/admin/registrations'],
                 // The event lead console (§5): only the events the viewer leads.
                 ['page' => 'lead', 'label' => 'Events you lead', 'href' => '/admin/lead'],
             ]],
            ['page' => 'challenges', 'label' => 'Challenges', 'href' => '/admin/challenges', 'icon' => 'bolt',
             'sub' => 'Paid challenges and where each one stands.'],
            ['page' => 'partner-orgs', 'label' => 'Giving', 'href' => '/admin/partner-orgs', 'icon' => 'mail',
             'sub' => 'Organisations raising money on Africa GATES, and their donation pages.',
             'children' => [
                 ['page' => 'community-return', 'label' => 'Community return', 'href' => '/admin/community-return'],
             ]],
            ['page' => 'forms', 'label' => 'Forms', 'href' => '/admin/forms', 'icon' => 'form',
             'sub' => 'Submissions from the site’s forms.',
             'children' => [
                 ['page' => 'partners', 'label' => 'Partner enquiries', 'href' => '/admin/partners'],
             ]],
        ]],
        ['key' => 'audience', 'label' => 'Audience', 'tip' => 'Who we reach, and what we send them.', 'items' => [
            ['page' => 'contacts', 'label' => 'Contacts', 'href' => '/admin/contacts', 'icon' => 'book',
             'sub' => 'Everyone who has voted, given, nominated or bought a ticket.'],
            ['page' => 'campaigns', 'label' => 'Broadcasts', 'href' => '/admin/campaigns', 'icon' => 'mega',
             'sub' => 'Campaigns sent to nominees and the newsletter, to opted-in people only.',
             'children' => [
                 ['page' => 'newsletter', 'label' => 'Newsletter',         'href' => '/admin/campaigns/newsletter'],
                 ['page' => 'greetings',  'label' => 'Seasonal greetings', 'href' => '/admin/campaigns/greetings'],
             ]],
        ]],
        ['key' => 'money', 'label' => 'Money', 'tip' => 'Every naira taken and owed.', 'items' => [
            ['page' => 'payments', 'label' => 'Payments', 'href' => '/admin/payments', 'icon' => 'card',
             'sub' => 'Our records against the gateway\'s. Fix the ones that disagree before someone pays twice.',
             'children' => [
                 ['page' => 'payments-ledger', 'label' => 'Ledger',        'href' => '/admin/payments/ledger'],
                 ['page' => 'vote-delivery',   'label' => 'Vote delivery', 'href' => '/admin/vote-delivery'],
                 ['page' => 'vote-recovery',   'label' => 'Vote recovery', 'href' => '/admin/vote-recovery'],
             ]],
            ['page' => 'refunds', 'label' => 'Refunds', 'href' => '/admin/refunds', 'icon' => 'undo',
             'sub' => 'Refund requests and card chargebacks.',
             'children' => [
                 ['page' => 'payments-disputes', 'label' => 'Disputes', 'href' => '/admin/payments/disputes'],
             ]],
            ['page' => 'payouts', 'label' => 'Payouts', 'href' => '/admin/payouts', 'icon' => 'send',
             'sub' => 'Money going out to partners and referrers.'],
            ['page' => 'finance', 'label' => 'Finance', 'href' => '/admin/finance', 'icon' => 'chart',
             'sub' => 'What has been taken and what the platform kept.',
             'children' => [
                 ['page' => 'vendor-policy', 'label' => 'Vendor rules', 'href' => '/admin/vendor-policy'],
             ]],
            ['page' => 'shop_orders', 'label' => 'Shop orders', 'href' => '/admin/shop/orders', 'icon' => 'bag',
             'sub' => 'Orders from the shop, and what is still to send.',
             'children' => [
                 ['page' => 'products',    'label' => 'Products',            'href' => '/admin/products'],
                 ['page' => 'shop-codes',  'label' => 'Shop discount codes', 'href' => '/admin/shop/codes'],
                 ['page' => 'shipping',    'label' => 'Delivery charges',    'href' => '/admin/shop/shipping'],
             ]],
        ]],
        ['key' => 'content', 'label' => 'Content', 'tip' => 'Everything the public reads.', 'items' => [
            ['page' => 'posts', 'label' => 'Posts', 'href' => '/admin/posts', 'icon' => 'doc', 'sub' => 'The blog.',
             'children' => [
                 ['page' => 'opportunities', 'label' => 'Opportunities', 'href' => '/admin/opportunities'],
                 ['page' => 'legacy',        'label' => 'Legacy vault',  'href' => '/admin/legacy'],
             ]],
            ['page' => 'media', 'label' => 'Media', 'href' => '/admin/media', 'icon' => 'image'],
            ['page' => 'moderation', 'label' => 'Moderation', 'href' => '/admin/moderation', 'icon' => 'shield',
             'sub' => 'Comments, messages and quotes waiting on a person.',
             'children' => [
                 ['page' => 'support', 'label' => 'Support tickets', 'href' => '/admin/support'],
             ]],
        ]],
        ['key' => 'organisation', 'label' => 'Organisation', 'tip' => 'People, roles, health and keys.', 'items' => [
            ['page' => 'admins', 'label' => 'Team', 'href' => '/admin/admins', 'icon' => 'team',
             'sub' => 'Who can sign in to this console and what each role reaches.',
             'children' => [
                 // People › Volunteers in the v7 handoff (§4.1): applications, roles, shifts, hours.
                 ['page' => 'volunteers', 'label' => 'Volunteers', 'href' => '/admin/volunteers'],
                 // Requests from event leads and volunteers (§2.6), decided by finance or an admin.
                 ['page' => 'requests', 'label' => 'Requests', 'href' => '/admin/requests'],
             ]],
            ['page' => 'alerts', 'label' => 'Alerts', 'href' => '/admin/alerts', 'icon' => 'bell',
             'sub' => 'Everything the platform noticed on its own, most severe first. Each one says what to do next.'],
            ['page' => 'handbook', 'label' => 'Handbook', 'href' => '/admin/handbook', 'icon' => 'hb',
             'sub' => 'How this console is organised, who can open what, and the rules it runs on.'],
            ['page' => 'audit', 'label' => 'Audit log', 'href' => '/admin/audit', 'icon' => 'clock',
             'sub' => 'Every change made in this console, with who, when and why. Entries can\'t be edited.',
             'children' => [
                 ['page' => 'integrity', 'label' => 'Integrity', 'href' => '/admin/integrity'],
                 ['page' => 'analytics', 'label' => 'Analytics', 'href' => '/admin/analytics'],
                 ['page' => 'data',      'label' => 'All data',  'href' => '/admin/data'],
             ]],
            ['page' => 'providers', 'label' => 'Integrations', 'href' => '/admin/settings/providers', 'icon' => 'plug',
             'sub' => 'Each check asks the provider a real question and prints their answer. Every check is a read, so running them sends nothing and is safe during an event.',
             'children' => [
                 ['page' => 'mail-health', 'label' => 'Email health', 'href' => '/admin/settings/mail'],
                 ['page' => 'webhooks',    'label' => 'Webhooks',     'href' => '/admin/webhooks'],
             ]],
            ['page' => 'settings', 'label' => 'Settings', 'href' => '/admin/settings', 'icon' => 'gear',
             'children' => [
                 ['page' => 'legal',    'label' => 'Legal',         'href' => '/admin/legal'],
                 ['page' => 'ai',       'label' => 'AI prompts',    'href' => '/admin/ai-prompts'],
                 ['page' => 'attendee', 'label' => 'Interview bot', 'href' => '/admin/attendee'],
                 ['page' => 'sandbox',  'label' => 'Test data',     'href' => '/admin/sandbox'],
                 ['page' => 'ops-scripts', 'label' => 'Operations scripts', 'href' => '/admin/assistant/scripts'],
             ]],
        ]],
    ];

    /**
     * Pages the shell reaches OUTSIDE the rail: the assistant's full page, from its pane. Gated like everything else.
     */
    private const ELSEWHERE = [
        ['page' => 'assistant', 'label' => 'AI assistant', 'href' => '/admin/assistant', 'icon' => 'assistant'],
    ];

    /** The guard's own gate for a path; null means unmapped, i.e. superadmin-only. */
    public static function gateOf(string $href): ?string
    {
        return Permissions::sectionForPath((string) (parse_url($href, PHP_URL_PATH) ?? $href));
    }

    /** @param array<string,mixed> $i @return array<string,mixed> */
    private static function dress(array $i, string $group, string $tip): array
    {
        $i['gate']     = self::gateOf($i['href']);
        $i['group']    = $group;
        $i['sub']      = $i['sub'] ?? $tip;
        $i['children'] = array_map(static fn (array $c): array => $c + ['gate' => self::gateOf($c['href'])],
                                   $i['children'] ?? []);
        return $i;
    }

    /** The Workspace items after Home, dressed. @return list<array<string,mixed>> */
    public static function top(): array
    {
        return array_map(static fn (array $i): array => self::dress($i, '', (string) $i['sub']), self::TOP);
    }

    /** The Home item, dressed. @return array<string,mixed> */
    public static function home(): array
    {
        return self::dress(self::HOME, '', self::HOME['sub']);
    }

    /**
     * Every group, every item, every gate — unfiltered. The handbook and the tests read
     * this; a screen draws {@see forRole()}.
     *
     * @return list<array{key:string, label:string, tip:string, items:list<array<string,mixed>>}>
     */
    public static function groups(): array
    {
        $out = [];
        foreach (self::GROUPS as $g) {
            $g['items'] = array_map(static fn (array $i): array => self::dress($i, $g['label'], $g['tip']), $g['items']);
            $out[] = $g;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the shell's off-rail destinations, dressed */
    public static function elsewhere(): array
    {
        return array_map(static fn (array $i): array => $i + ['gate' => self::gateOf($i['href'])], self::ELSEWHERE);
    }

    /**
     * What this role is shown: Home, then each group with only the pages the guard will
     * let it open (children too). A group left with nothing is not drawn.
     *
     * @return array{home: ?array<string,mixed>, top: list<array<string,mixed>>, groups: list<array<string,mixed>>}
     */
    public static function forRole(string $role): array
    {
        $home = Permissions::canOpen($role, self::HOME['href']) ? self::home() : null;
        $top = array_values(array_filter(self::top(), static fn (array $i): bool => Permissions::canOpen($role, $i['href'])));
        $groups = [];
        foreach (self::groups() as $g) {
            $items = [];
            foreach ($g['items'] as $i) {
                $kids = array_values(array_filter($i['children'],
                    static fn (array $c): bool => Permissions::canOpen($role, $c['href'])));
                if (Permissions::canOpen($role, $i['href'])) {
                    $i['children'] = $kids;
                    $items[] = $i;
                    continue;
                }
                // The role cannot open this item, but may open pages under it — Analytics
                // under Audit log for an admin, Legal under Settings. Each stands in the rail
                // in its own right, at the same single level, or it would be reachable from
                // nowhere: not the rail, not a strip, not ⌘K, which is built from this list.
                foreach ($kids as $c) {
                    $items[] = $c + ['icon' => $i['icon'], 'group' => $g['label'], 'sub' => $i['sub'], 'children' => []];
                }
            }
            if ($items !== []) { $g['items'] = $items; $groups[] = $g; }
        }
        return ['home' => $home, 'top' => $top, 'groups' => $groups];
    }

    /**
     * Every destination this role can open — rail items, their children, and the shell's
     * off-rail pages — flat, for the palette. `group` is what the palette prints beside
     * a page so "Disputes" says where it lives.
     *
     * @return list<array{page:string, label:string, href:string, group:string}>
     */
    public static function destinations(string $role): array
    {
        $nav = self::forRole($role);
        $out = [];
        if ($nav['home']) $out[] = ['page' => 'dashboard', 'label' => 'Home', 'href' => self::HOME['href'], 'group' => 'Home'];
        foreach ($nav['top'] as $t) $out[] = ['page' => $t['page'], 'label' => $t['label'], 'href' => $t['href'], 'group' => 'Workspace'];
        foreach ($nav['groups'] as $g) {
            foreach ($g['items'] as $i) {
                $out[] = ['page' => $i['page'], 'label' => $i['label'], 'href' => $i['href'], 'group' => $g['label']];
                foreach ($i['children'] as $c) {
                    $out[] = ['page' => $c['page'], 'label' => $c['label'], 'href' => $c['href'],
                              'group' => $g['label'] . ' · ' . $i['label']];
                }
            }
        }
        foreach (self::elsewhere() as $e) {
            if (Permissions::canOpen($role, $e['href'])) {
                $out[] = ['page' => $e['page'], 'label' => $e['label'], 'href' => $e['href'], 'group' => 'Console'];
            }
        }
        return $out;
    }

    /**
     * Which rail item and which page a request is on.
     *
     * By PATH first — exact, then the longest href the path sits under — and only then by
     * the `admin_page` a controller passed. Several controllers pass a neighbour's key so
     * the old rail lit the right section (the integrations check passes `settings`), and
     * the review desk passes `nominations`; the path is the fact, the key is a hint.
     *
     * @return array{item: ?array<string,mixed>, page: ?array<string,mixed>, exact: bool}
     */
    public static function current(string $path, ?string $adminPage = null): array
    {
        $path = rtrim((string) (parse_url($path, PHP_URL_PATH) ?? $path), '/');
        if ($path === '/admin' || $path === '') $path = '/admin/dashboard';

        $candidates = [];
        $home = self::home();
        $candidates[] = [$home, $home];
        foreach (self::top() as $t) $candidates[] = [$t, $t];
        foreach (self::groups() as $g) {
            foreach ($g['items'] as $i) {
                $candidates[] = [$i, $i];
                foreach ($i['children'] as $c) $candidates[] = [$i, $c];
            }
        }

        $best = null; $bestLen = -1; $exact = false;
        foreach ($candidates as [$item, $page]) {
            $href = $page['href'];
            if ($path === $href) { return ['item' => $item, 'page' => $page, 'exact' => true]; }
            $pre = (string) ($page['match'] ?? $href);
            if (str_starts_with($path, $pre . '/') && strlen($pre) > $bestLen) {
                $best = [$item, $page]; $bestLen = strlen($pre);
            }
        }
        if ($best !== null) return ['item' => $best[0], 'page' => $best[1], 'exact' => $exact];

        if ($adminPage !== null && $adminPage !== '') {
            foreach ($candidates as [$item, $page]) {
                if ($page['page'] === $adminPage) return ['item' => $item, 'page' => $page, 'exact' => false];
            }
        }
        return ['item' => null, 'page' => null, 'exact' => false];
    }

    /**
     * The "also here" strip for the page a request is on: its rail item and that item's
     * children this role can open. Empty when there is nothing else to show — a strip
     * with one entry in it is noise.
     *
     * @return list<array{page:string, label:string, href:string, on:bool}>
     */
    public static function related(string $role, string $path, ?string $adminPage = null): array
    {
        $cur = self::current($path, $adminPage);
        if ($cur['item'] === null) return [];
        $item = $cur['item'];
        $kids = array_values(array_filter($item['children'] ?? [],
            static fn (array $c): bool => Permissions::canOpen($role, $c['href'])));
        if ($kids === []) return [];

        $out = [];
        foreach (array_merge([$item], $kids) as $p) {
            if (!Permissions::canOpen($role, $p['href'])) continue;
            $out[] = ['page' => $p['page'], 'label' => $p['label'], 'href' => $p['href'],
                      'on' => $cur['page'] !== null && $cur['page']['page'] === $p['page']];
        }
        return count($out) > 1 ? $out : [];
    }

    /** Every page key the console names — rail, children and off-rail. @return list<string> */
    public static function pages(): array
    {
        $out = [self::HOME['page']];
        foreach (self::TOP as $t) $out[] = $t['page'];
        foreach (self::GROUPS as $g) {
            foreach ($g['items'] as $i) {
                $out[] = $i['page'];
                foreach ($i['children'] ?? [] as $c) $out[] = $c['page'];
            }
        }
        foreach (self::ELSEWHERE as $e) $out[] = $e['page'];
        return $out;
    }

    /** Every href the console names, keyed by page. @return array<string,string> */
    public static function hrefs(): array
    {
        $out = [self::HOME['page'] => self::HOME['href']];
        foreach (self::TOP as $t) $out[$t['page']] = $t['href'];
        foreach (self::GROUPS as $g) {
            foreach ($g['items'] as $i) {
                $out[$i['page']] = $i['href'];
                foreach ($i['children'] ?? [] as $c) $out[$c['page']] = $c['href'];
            }
        }
        foreach (self::ELSEWHERE as $e) $out[$e['page']] = $e['href'];
        return $out;
    }

    /** The rail's own items — Home plus the groups' items, not children. @return list<array<string,mixed>> */
    public static function railItems(): array
    {
        $out = [self::home(), ...self::top()];
        foreach (self::groups() as $g) foreach ($g['items'] as $i) $out[] = $i;
        return $out;
    }
}
