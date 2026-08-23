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
 * WHY THE SECTIONS ARE SMALLER THAN THEY WERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The sidebar showed every group expanded at once — thirty-six links, of which the two or
 * three anybody wanted were somewhere in the middle. Collapsing helps only if the sections
 * are small enough that opening one is not a second scroll: "Moderation" held eight items
 * and "Content" ten, and neither name told you which one you wanted.
 *
 * So the largest groups are split by what the work actually IS — entries, judging,
 * outreach — rather than by which permission happens to cover them.
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

            // ── was one eight-item "Moderation" group ────────────────────────
            [
                'key' => 'entries', 'label' => 'Entries', 'gate' => 'moderation',
                'tip' => 'Approve profiles, nominations and community content before it goes live.',
                'items' => [
                    ['page' => 'profiles',    'label' => 'Profiles',         'href' => '/admin/profiles'],
                    ['page' => 'nominations', 'label' => 'Nominations',      'href' => '/admin/nominations'],
                    ['page' => 'nominees',    'label' => 'Nominees',         'href' => '/admin/nominees'],
                    ['page' => 'moderation',  'label' => 'Moderation Queue', 'href' => '/admin/moderation'],
                ],
            ],
            [
                'key' => 'judging', 'label' => 'Judging', 'gate' => 'moderation',
                'tip' => 'Interviews and the nominee questionnaire — programme work, not governance.',
                'items' => [
                    ['page' => 'interviews',     'label' => 'Interviews',     'href' => '/admin/interviews'],
                    ['page' => 'questionnaires', 'label' => 'Questionnaires', 'href' => '/admin/questionnaires'],
                ],
            ],
            [
                'key' => 'outreach', 'label' => 'Outreach', 'gate' => 'moderation',
                'tip' => 'Email to nominees, and the support queue people write into.',
                'items' => [
                    ['page' => 'campaigns', 'label' => 'Campaigns',     'href' => '/admin/campaigns'],
                    ['page' => 'support',   'label' => 'Support Queue', 'href' => '/admin/support'],
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

            // ── was one ten-item "Content" group ─────────────────────────────
            [
                'key' => 'content', 'label' => 'Content', 'gate' => 'content',
                'tip' => 'Public-facing content — events, blog, legacy and opportunities.',
                'items' => [
                    ['page' => 'events',        'label' => 'Events',        'href' => '/admin/events'],
                    ['page' => 'posts',         'label' => 'Blog Posts',    'href' => '/admin/posts'],
                    ['page' => 'legacy',        'label' => 'Legacy Events', 'href' => '/admin/legacy'],
                    ['page' => 'opportunities', 'label' => 'Opportunities', 'href' => '/admin/opportunities'],
                    ['page' => 'media',         'label' => 'Media',         'href' => '/admin/media'],
                ],
            ],
            [
                'key' => 'shop', 'label' => 'Shop', 'gate' => 'content',
                'tip' => 'Products and the orders placed against them.',
                'items' => [
                    ['page' => 'products',    'label' => 'Shop Products', 'href' => '/admin/products'],
                    ['page' => 'shop_orders', 'label' => 'Shop Orders',   'href' => '/admin/shop/orders'],
                ],
            ],
            [
                'key' => 'site', 'label' => 'Site', 'gate' => 'content',
                'tip' => 'Forms, partner enquiries and the legal documents.',
                'items' => [
                    ['page' => 'forms',    'label' => 'Forms',             'href' => '/admin/forms'],
                    ['page' => 'partners', 'label' => 'Partner Enquiries', 'href' => '/admin/partners'],
                    ['page' => 'legal',    'label' => 'Legal & policies',  'href' => '/admin/legal'],
                ],
            ],

            [
                'key' => 'data', 'label' => 'Data', 'gate' => 'data',
                'tip' => 'Every dataset collected across the platform — browse, view details and export.',
                'items' => [
                    ['page' => 'data',          'label' => 'All data',            'href' => '/admin/data'],
                    ['page' => 'analytics',     'label' => 'Analytics',           'href' => '/admin/analytics'],
                    ['page' => 'registrations', 'label' => 'Event Registrations', 'href' => '/admin/registrations'],
                ],
            ],

            // ── was one seven-item "Finance" group ───────────────────────────
            [
                'key' => 'finance', 'label' => 'Finance', 'gate' => 'finance',
                'tip' => 'Every naira the platform has taken, and what it owes out.',
                'items' => [
                    ['page' => 'finance',      'label' => 'Revenue',      'href' => '/admin/finance'],
                    ['page' => 'payouts',      'label' => 'Referral payouts', 'href' => '/admin/payouts'],
                    ['page' => 'partner-orgs', 'label' => 'Partner Orgs', 'href' => '/admin/partner-orgs'],
                ],
            ],
            [
                'key' => 'payments', 'label' => 'Payments', 'gate' => 'finance',
                'tip' => 'What the gateway did, and everything that needs chasing.',
                'items' => [
                    ['page' => 'payments',          'label' => 'Payment Triage', 'href' => '/admin/payments'],
                    ['page' => 'payments-ledger',   'label' => 'Gateway Ledger', 'href' => '/admin/payments/ledger'],
                    ['page' => 'payments-disputes', 'label' => 'Disputes',       'href' => '/admin/payments/disputes'],
                    ['page' => 'refunds',           'label' => 'Refunds',        'href' => '/admin/refunds'],
                    ['page' => 'vote-delivery',     'label' => 'Vote Delivery',  'href' => '/admin/vote-delivery'],
                ],
            ],

            [
                'key' => 'configuration', 'label' => 'Configuration', 'gate' => 'configuration',
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
