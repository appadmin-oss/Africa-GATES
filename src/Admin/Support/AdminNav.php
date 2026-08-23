<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

/**
 * The admin navigation, in one place.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A CLASS AND NOT MARKUP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * It used to be thirty-six hand-written `<a>` tags in `admin/layout.twig`, each repeating
 * its own active-state test and its own inline SVG. Three consequences, all of which had
 * already happened:
 *
 *   · A new page could be built, routed and permissioned and still not appear in the nav,
 *     because adding it there was a separate manual step nothing checked.
 *   · The same icon could not be shown anywhere else without copying its path data.
 *   · Nothing could ask "what else is in this section?", so a second level of navigation
 *     was impossible to build without writing the tree a second time.
 *
 * One tree fixes all three. {@see \Tests\Unit\AdminNavTest} then holds the properties that
 * matter: every page a controller declares has an entry, every entry points at a real
 * route, and no entry moves a page into a section its permission gate does not cover.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY SEVEN SECTIONS, AND WHY THAT IS THE FLOOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This was thirty-six links in one flat rail, then twelve collapsed groups, and the
 * twelve were wrong for a reason worth writing down: NN/g's study with WhatUsersDo (179
 * participants, six live sites) measured discoverability dropping by roughly half when
 * main navigation is hidden — on desktop as well as mobile, with users about 39% slower
 * on desktop. Twelve accordions is hidden navigation. The documented resolution is a
 * HYBRID: a few destinations visible, the tail behind something else.
 *
 * So the tail moved to two other places — the in-page sub-nav under the page title, and
 * the Cmd+K palette — and the rail holds seven headings.
 *
 * Seven is the floor, not a taste call. There are exactly seven distinct
 * `admin_sections` gates, and a section can only carry one gate, so fewer sections would
 * mean moving a page to a different gate. That is an access change, and it must never
 * ride along inside a navigation change.
 *
 * Sections are therefore uneven — two items in Programmes, ten in Content. That is fine
 * and deliberate: only one is open at a time, the sub-nav repeats it inside the page, and
 * the palette reaches any of the thirty-seven pages in one keystroke. Labels use task
 * vocabulary rather than the org chart ("Money", not "Finance & Payments"), which is what
 * information-foraging predicts people scan for.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY NOTHING CROSSED A PERMISSION BOUNDARY WHILE THAT HAPPENED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every item keeps the exact `admin_sections` gate it had before. Splitting a group is a
 * presentation change; moving an item between gates would silently grant or remove access,
 * which is not a navigation decision and must never ride along inside one. `gate` is
 * recorded per SECTION and asserted against the original mapping in the test.
 */
final class AdminNav
{
    /**
     * Sections, in sidebar order.
     *
     * `gate` is the `admin_sections` key that must be present for the section to appear;
     * null means always visible. `page` is the value a controller passes as `admin_page`,
     * and doubles as the icon id (`#ic-<page>`) in `admin/partials/nav-icons.twig`.
     *
     * @return list<array{key:string, label:string, gate:?string, tip:string,
     *                    items:list<array{page:string, label:string, href:string}>}>
     */
    public static function sections(): array
    {
        return [
            [
                'key' => 'overview', 'label' => 'Overview', 'gate' => null,
                'tip' => 'Dashboard and key platform metrics.',
                'items' => [
                    ['page' => 'dashboard', 'label' => 'Dashboard',    'href' => '/admin/dashboard'],
                    ['page' => 'assistant', 'label' => 'AI Assistant', 'href' => '/admin/assistant'],
                ],
            ],
            [
                'key' => 'entries', 'label' => 'Entries & panel', 'gate' => 'moderation',
                'tip' => 'Everything that moves a nomination from arriving to being judged.',
                'items' => [
                    ['page' => 'profiles',       'label' => 'Profiles',         'href' => '/admin/profiles'],
                    ['page' => 'nominations',    'label' => 'Nominations',      'href' => '/admin/nominations'],
                    ['page' => 'nominees',       'label' => 'Nominees',         'href' => '/admin/nominees'],
                    ['page' => 'moderation',     'label' => 'Moderation Queue', 'href' => '/admin/moderation'],
                    ['page' => 'interviews',     'label' => 'Interviews',       'href' => '/admin/interviews'],
                    ['page' => 'questionnaires', 'label' => 'Questionnaires',   'href' => '/admin/questionnaires'],
                    ['page' => 'campaigns',      'label' => 'Campaigns',        'href' => '/admin/campaigns'],
                    ['page' => 'support',        'label' => 'Support Queue',    'href' => '/admin/support'],
                ],
            ],
            [
                'key' => 'programmes', 'label' => 'Programmes', 'gate' => 'programmes',
                'tip' => 'Award programmes, cycles, categories and the judging panel.',
                'items' => [
                    ['page' => 'programmes',  'label' => 'Awards & Cycles', 'href' => '/admin/programmes'],
                    ['page' => 'awards_page', 'label' => 'Awards Page',     'href' => '/admin/awards-page'],
                ],
            ],
            [
                'key' => 'content', 'label' => 'Content', 'gate' => 'content',
                'tip' => 'Everything the public sees — pages, events, shop and legal.',
                'items' => [
                    ['page' => 'events',        'label' => 'Events',            'href' => '/admin/events'],
                    ['page' => 'posts',         'label' => 'Blog Posts',        'href' => '/admin/posts'],
                    ['page' => 'legacy',        'label' => 'Legacy Events',     'href' => '/admin/legacy'],
                    ['page' => 'opportunities', 'label' => 'Opportunities',     'href' => '/admin/opportunities'],
                    ['page' => 'media',         'label' => 'Media',             'href' => '/admin/media'],
                    ['page' => 'products',      'label' => 'Shop Products',     'href' => '/admin/products'],
                    ['page' => 'shop_orders',   'label' => 'Shop Orders',       'href' => '/admin/shop/orders'],
                    ['page' => 'forms',         'label' => 'Forms',             'href' => '/admin/forms'],
                    ['page' => 'partners',      'label' => 'Partner Enquiries', 'href' => '/admin/partners'],
                    ['page' => 'legal',         'label' => 'Legal & policies',  'href' => '/admin/legal'],
                ],
            ],
            [
                'key' => 'money', 'label' => 'Money', 'gate' => 'finance',
                'tip' => 'Every naira taken, what is owed out, and everything needing chasing.',
                'items' => [
                    ['page' => 'finance',           'label' => 'Revenue',           'href' => '/admin/finance'],
                    ['page' => 'payouts',           'label' => 'Referral payouts',  'href' => '/admin/payouts'],
                    ['page' => 'partner-orgs',      'label' => 'Partner Orgs',      'href' => '/admin/partner-orgs'],
                    ['page' => 'payments',          'label' => 'Payment Triage',    'href' => '/admin/payments'],
                    ['page' => 'payments-ledger',   'label' => 'Gateway Ledger',    'href' => '/admin/payments/ledger'],
                    ['page' => 'payments-disputes', 'label' => 'Disputes',          'href' => '/admin/payments/disputes'],
                    ['page' => 'refunds',           'label' => 'Refunds',           'href' => '/admin/refunds'],
                    ['page' => 'vote-delivery',     'label' => 'Vote Delivery',     'href' => '/admin/vote-delivery'],
                ],
            ],
            [
                'key' => 'data', 'label' => 'Data', 'gate' => 'data',
                'tip' => 'Every dataset collected — browse, view details and export.',
                'items' => [
                    ['page' => 'data',          'label' => 'All data',            'href' => '/admin/data'],
                    ['page' => 'analytics',     'label' => 'Analytics',           'href' => '/admin/analytics'],
                    ['page' => 'registrations', 'label' => 'Event Registrations', 'href' => '/admin/registrations'],
                ],
            ],
            [
                'key' => 'configuration', 'label' => 'System', 'gate' => 'configuration',
                'tip' => 'Admin accounts, site settings and integrations — superadmin only.',
                'items' => [
                    ['page' => 'admins',   'label' => 'Admins',       'href' => '/admin/admins'],
                    ['page' => 'settings', 'label' => 'Settings',     'href' => '/admin/settings'],
                    ['page' => 'webhooks', 'label' => 'Webhooks',     'href' => '/admin/webhooks'],
                    ['page' => 'judges',   'label' => 'Judges Panel', 'href' => '/admin/judges'],
                ],
            ],
        ];
    }

    /**
     * The sections this admin may see.
     *
     * @param  list<string> $allowed the request's `admin_sections`
     * @return list<array<string,mixed>>
     */
    public static function visible(array $allowed): array
    {
        return array_values(array_filter(
            self::sections(),
            static fn (array $s): bool => $s['gate'] === null || in_array($s['gate'], $allowed, true)
        ));
    }

    /**
     * The section a page belongs to, or null for a page with no nav entry.
     *
     * A detail page — `/admin/interviews/12` — passes its LIST's `admin_page`, so it
     * lights the same entry and carries the same sub-nav. That is deliberate: a person
     * three levels into a record still wants the way back out.
     *
     * @return array<string,mixed>|null
     */
    public static function sectionFor(string $page): ?array
    {
        foreach (self::sections() as $s) {
            foreach ($s['items'] as $i) {
                if ($i['page'] === $page) return $s;
            }
        }
        return null;
    }

    /**
     * The other pages in this page's section — the in-page second level.
     *
     * Empty for a lone page, and the template omits the strip entirely rather than drawing
     * a bar with one tab in it.
     *
     * @return list<array{page:string, label:string, href:string}>
     */
    public static function siblings(string $page): array
    {
        $s = self::sectionFor($page);

        return $s === null || count($s['items']) < 2 ? [] : $s['items'];
    }

    /** Every page key in the tree. @return list<string> */
    public static function pages(): array
    {
        $out = [];
        foreach (self::sections() as $s) {
            foreach ($s['items'] as $i) $out[] = $i['page'];
        }
        return $out;
    }
}
