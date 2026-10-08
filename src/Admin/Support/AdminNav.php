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
 * page, not per sidebar group** — so the groups are now named for the job ("Daily work",
 * "Money", "Monitoring") and each PAGE carries its own gate.
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
 * The handoff's rail names 37 pages; the console has more. Each of the rest is a CHILD of
 * the page it belongs under — Shortlists under Awards, Disputes under Refunds & disputes,
 * the judging rubric under Judges & rubric — and is drawn as the "also here" strip under
 * that page's header, and in the palette. That is CLAUDE.md's "a sub-page is linked from
 * the page it belongs under": the rail stays scannable and every page stays findable
 * (`AdminIaTest`).
 *
 * Labels and order are §2.2's, exactly. Purpose lines are the HTML's `static SUBS`,
 * verbatim except where a sentence describes host organisations, which are not built
 * (owner, 4 Oct 2026) — those are listed in docs/handoff/PHASE-ADMIN.md as deviations.
 */
final class AdminNav
{
    /** The one item above the groups. */
    private const HOME = [
        'page' => 'dashboard', 'label' => 'Home', 'href' => '/admin/dashboard', 'icon' => 'home',
        'sub'  => 'Every job waiting on a person, across everything your role can reach, most urgent first.',
    ];

    /**
     * README §2.2, in order. `sub` is the page's purpose line; `tip` is the group's,
     * used where a page has none of its own (the HTML does the same).
     *
     * @var list<array{key:string, label:string, tip:string, items:list<array<string,mixed>>}>
     */
    private const GROUPS = [
        ['key' => 'daily', 'label' => 'Daily work', 'tip' => 'Every job waiting on a person.', 'items' => [
            // The handoff routes this to /admin/moderation. In this codebase that path is the
            // COMMUNITY moderation queue; the nomination review the handoff draws (nominee,
            // nominator, approve/reject) is the review desk. The codebase wins on routing
            // (README §0) — recorded as a question for the owner.
            ['page' => 'review', 'label' => 'Review queue', 'href' => '/admin/nominations/review', 'icon' => 'moderation',
             'sub' => 'Nominations wait here until a person approves them. Target: under 4 hours.',
             'children' => [
                 ['page' => 'moderation', 'label' => 'Moderation queue', 'href' => '/admin/moderation'],
             ]],
            ['page' => 'payments', 'label' => 'Payment issues', 'href' => '/admin/payments', 'icon' => 'payments',
             'sub' => 'Our records against the gateway\'s. Fix the ones that disagree before someone pays twice.'],
            ['page' => 'alerts', 'label' => 'Alerts', 'href' => '/admin/alerts', 'icon' => 'bell',
             'sub' => 'Everything the platform noticed on its own, most severe first. Each one says what to do next.'],
            ['page' => 'support', 'label' => 'Support tickets', 'href' => '/admin/support', 'icon' => 'support',
             'sub' => 'Questions from voters, nominees and vendors. Oldest open first.'],
        ]],
        ['key' => 'programmes', 'label' => 'Programmes', 'tip' => 'Each edition from nominations to sealed results.', 'items' => [
            ['page' => 'programmes', 'label' => 'Awards', 'href' => '/admin/programmes', 'icon' => 'shortlists',
             'sub' => 'Every award on the platform.',
             'children' => [
                 ['page' => 'shortlists',     'label' => 'Shortlists',     'href' => '/admin/shortlists'],
                 ['page' => 'result-release', 'label' => 'Result release', 'href' => '/admin/result-release'],
             ]],
            ['page' => 'events', 'label' => 'Events & stands', 'href' => '/admin/events', 'icon' => 'stand_presets',
             'sub' => 'Every event on the platform, with tickets and stands.',
             'children' => [
                 ['page' => 'stand_presets', 'label' => 'Stand presets',       'href' => '/admin/stand-presets'],
                 ['page' => 'registrations', 'label' => 'Event registrations', 'href' => '/admin/registrations'],
             ]],
            ['page' => 'challenges', 'label' => 'Challenges', 'href' => '/admin/challenges', 'icon' => 'challenges',
             'sub' => 'Paid challenges and where each one stands.'],
        ]],
        ['key' => 'entries', 'label' => 'Entries', 'tip' => 'Nominations, nominees and profiles waiting on a person.', 'items' => [
            ['page' => 'nominations', 'label' => 'Nominations', 'href' => '/admin/nominations', 'icon' => 'nominations',
             'sub' => 'Every nomination, with the decision and who made it.'],
            ['page' => 'nominees', 'label' => 'Nominees', 'href' => '/admin/nominees', 'icon' => 'nominees',
             'sub' => 'Everyone on a ballot, and whether they have claimed their profile.',
             'children' => [
                 ['page' => 'campaigns', 'label' => 'Campaigns', 'href' => '/admin/campaigns'],
             ]],
            ['page' => 'profiles', 'label' => 'Profiles', 'href' => '/admin/profiles', 'icon' => 'profiles',
             'sub' => 'People and organisations with an Africa GATES profile.'],
            ['page' => 'interviews', 'label' => 'Interviews', 'href' => '/admin/interviews', 'icon' => 'interviews',
             'sub' => 'Nominee interviews, from booking to a published transcript.',
             'children' => [
                 ['page' => 'questionnaires', 'label' => 'Questionnaires', 'href' => '/admin/questionnaires'],
                 ['page' => 'invitations',    'label' => 'Invitations',    'href' => '/admin/questionnaires/invitations'],
             ]],
        ]],
        ['key' => 'money', 'label' => 'Money', 'tip' => 'Every naira taken and owed.', 'items' => [
            ['page' => 'finance', 'label' => 'Revenue', 'href' => '/admin/finance', 'icon' => 'finance',
             'sub' => 'What has been taken and what the platform kept.'],
            ['page' => 'payouts', 'label' => 'Payouts', 'href' => '/admin/payouts', 'icon' => 'payouts',
             'sub' => 'Money going out to partners and referrers.',
             'children' => [
                 ['page' => 'partner-orgs', 'label' => 'Partner organisations', 'href' => '/admin/partner-orgs'],
             ]],
            ['page' => 'payments-ledger', 'label' => 'Ledger', 'href' => '/admin/payments/ledger', 'icon' => 'payments-ledger',
             'sub' => 'Every payment exactly as the gateway reported it.'],
            ['page' => 'refunds', 'label' => 'Refunds & disputes', 'href' => '/admin/refunds', 'icon' => 'refunds',
             'sub' => 'Refund requests and card chargebacks.',
             'children' => [
                 ['page' => 'payments-disputes', 'label' => 'Disputes', 'href' => '/admin/payments/disputes'],
             ]],
            ['page' => 'vote-delivery', 'label' => 'Vote delivery', 'href' => '/admin/vote-delivery', 'icon' => 'vote-delivery',
             'sub' => 'Paid votes that have not reached their nominee yet.',
             'children' => [
                 ['page' => 'vote-recovery', 'label' => 'Vote recovery', 'href' => '/admin/vote-recovery'],
             ]],
            ['page' => 'vendor-policy', 'label' => 'Vendor rules', 'href' => '/admin/vendor-policy', 'icon' => 'vendor-policy'],
        ]],
        ['key' => 'publishing', 'label' => 'Publishing', 'tip' => 'Everything the public sees.', 'items' => [
            ['page' => 'posts', 'label' => 'Blog', 'href' => '/admin/posts', 'icon' => 'posts', 'sub' => 'The blog.'],
            ['page' => 'media', 'label' => 'Media', 'href' => '/admin/media', 'icon' => 'media'],
            ['page' => 'awards_page', 'label' => 'Awards page', 'href' => '/admin/awards-page', 'icon' => 'awards_page'],
            ['page' => 'opportunities', 'label' => 'Opportunities', 'href' => '/admin/opportunities', 'icon' => 'opportunities',
             'sub' => 'Fellowships, grants and calls listed on the site.'],
            ['page' => 'forms', 'label' => 'Forms', 'href' => '/admin/forms', 'icon' => 'forms',
             'sub' => 'Submissions from the site’s forms.',
             'children' => [
                 ['page' => 'partners', 'label' => 'Partner enquiries', 'href' => '/admin/partners'],
             ]],
            ['page' => 'products', 'label' => 'Shop', 'href' => '/admin/products', 'icon' => 'products',
             'children' => [
                 ['page' => 'shop_orders', 'label' => 'Shop orders', 'href' => '/admin/shop/orders'],
             ]],
            ['page' => 'legal', 'label' => 'Legal', 'href' => '/admin/legal', 'icon' => 'legal',
             'sub' => 'Terms and policies. Each version is kept, and acceptances are counted.'],
            ['page' => 'legacy', 'label' => 'Legacy vault', 'href' => '/admin/legacy', 'icon' => 'legacy'],
        ]],
        ['key' => 'monitoring', 'label' => 'Monitoring', 'tip' => 'Is everything the platform depends on working?', 'items' => [
            ['page' => 'providers', 'label' => 'Integrations', 'href' => '/admin/settings/providers', 'icon' => 'webhooks',
             'sub' => 'Each check asks the provider a real question and prints their answer. Every check is a read, so running them sends nothing and is safe during an event.'],
            ['page' => 'mail-health', 'label' => 'Email health', 'href' => '/admin/settings/mail', 'icon' => 'campaigns',
             'sub' => 'Is email sending, since when, why not, and what to change.'],
            ['page' => 'audit', 'label' => 'Audit log', 'href' => '/admin/audit', 'icon' => 'audit',
             'sub' => 'Every change made in this console, with who, when and why. Entries can\'t be edited.'],
            ['page' => 'integrity', 'label' => 'Integrity', 'href' => '/admin/integrity', 'icon' => 'integrity',
             'sub' => 'Votes and scores the checks have questioned, and what was decided.',
             'children' => [
                 ['page' => 'judging-audit', 'label' => 'Judging audit', 'href' => '/admin/judging-audit'],
             ]],
            ['page' => 'analytics', 'label' => 'Analytics', 'href' => '/admin/analytics', 'icon' => 'analytics',
             'sub' => 'How people move from arriving to a counted vote.'],
            ['page' => 'data', 'label' => 'All data', 'href' => '/admin/data', 'icon' => 'data',
             'sub' => 'Every dataset the platform keeps. Exports are logged.'],
        ]],
        ['key' => 'settings', 'label' => 'Settings', 'tip' => 'People, roles and keys. Superadmin only.', 'items' => [
            ['page' => 'admins', 'label' => 'People & roles', 'href' => '/admin/admins', 'icon' => 'admins',
             'sub' => 'Who can sign in to this console and what each role reaches.'],
            ['page' => 'judges', 'label' => 'Judges & rubric', 'href' => '/admin/judges', 'icon' => 'judges',
             'sub' => 'Judging panels and who still owes a scorecard.',
             'children' => [
                 ['page' => 'rubric', 'label' => 'Judging rubric', 'href' => '/admin/rubric'],
             ]],
            ['page' => 'settings', 'label' => 'Site & keys', 'href' => '/admin/settings', 'icon' => 'settings'],
            ['page' => 'webhooks', 'label' => 'Webhooks', 'href' => '/admin/webhooks', 'icon' => 'webhooks',
             'sub' => 'Where the platform sends events, and whether they arrive.'],
            ['page' => 'ai', 'label' => 'AI & interview bot', 'href' => '/admin/ai-prompts', 'icon' => 'ai',
             'children' => [
                 ['page' => 'attendee', 'label' => 'Interview bot', 'href' => '/admin/attendee'],
             ]],
            ['page' => 'sandbox', 'label' => 'Test data', 'href' => '/admin/sandbox', 'icon' => 'sandbox'],
        ]],
    ];

    /**
     * Pages the shell reaches OUTSIDE the rail: the handbook from the account menu, the
     * assistant's full page from its drawer. Gated like everything else.
     */
    private const ELSEWHERE = [
        ['page' => 'handbook',  'label' => 'Handbook',     'href' => '/admin/handbook',  'icon' => 'handbook'],
        ['page' => 'assistant', 'label' => 'AI assistant', 'href' => '/admin/assistant', 'icon' => 'assistant'],
    ];

    /** A group with more than this many pages shows four and folds the rest (§2.1). */
    public const FOLD_OVER = 5;
    public const FOLD_SHOW = 4;

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
     * @return array{home: ?array<string,mixed>, groups: list<array<string,mixed>>}
     */
    public static function forRole(string $role): array
    {
        $home = Permissions::canOpen($role, self::HOME['href']) ? self::home() : null;
        $groups = [];
        foreach (self::groups() as $g) {
            $items = [];
            foreach ($g['items'] as $i) {
                if (!Permissions::canOpen($role, $i['href'])) continue;
                $i['children'] = array_values(array_filter($i['children'],
                    static fn (array $c): bool => Permissions::canOpen($role, $c['href'])));
                $items[] = $i;
            }
            if ($items !== []) { $g['items'] = $items; $groups[] = $g; }
        }
        return ['home' => $home, 'groups' => $groups];
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
            if (str_starts_with($path, $href . '/') && strlen($href) > $bestLen) {
                $best = [$item, $page]; $bestLen = strlen($href);
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
        $out = [self::home()];
        foreach (self::groups() as $g) foreach ($g['items'] as $i) $out[] = $i;
        return $out;
    }
}
