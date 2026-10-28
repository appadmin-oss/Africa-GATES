<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Translator;

/**
 * The member account's sections — AccountPage.dc.html's `TABS` — and the one place that
 * decides their order, their addresses and the number beside each.
 *
 * On a tablet and up they are the rail beside every account page; on a phone they are the
 * list on the account's first screen, each pushed as its own screen with a back button
 * (REFERENCE §7.2). Both read this list, so a section cannot be in one and missing from the
 * other.
 *
 * Every section is an ADDRESS: `/account?tab=…` for the ones drawn by the account page
 * itself, and the account's own pages (`/account/points`, `/account/notifications`) where
 * they already had one — a bookmark, a mail link and Back all keep working.
 */
final class AccountRail
{
    /**
     * key => [label, href, glyph path, tone]. `tone` names the glyph's colour family; the
     * tile behind it is neutral (docs/handoff/PHASE-ACCOUNT.md, AC-2).
     */
    public const SECTIONS = [
        'overview'   => ['Overview',   '/account',                 'M3 9.5 12 3l9 6.5V20H3z', 'ink'],
        'wallet'     => ['Wallet',     '/account/wallet',          'M3 7h18v12H3zM3 7l3-3h12l3 3M16 13h2', 'gold'],
        'referral'   => ['Referrals',  '/account?tab=referral',    'M16 11a4 4 0 1 0-8 0M3 21a9 9 0 0 1 18 0M19 8v6M22 11h-6', 'live'],
        'challenges' => ['Challenges', '/account?tab=challenges',  'M6 4h12v4a6 6 0 0 1-12 0zM9 20h6M12 14v6M6 6H3v1a4 4 0 0 0 4 4M18 6h3v1a4 4 0 0 1-4 4', 'gold'],
        'purchases'  => ['Purchases',  '/account?tab=purchases',   'M5.5 8h13l-1 12h-11zM9 8V7a3 3 0 0 1 6 0v1', 'info'],
        'activity'   => ['Activity',   '/account/notifications',   'M3 12h4l3-7 4 14 3-7h4', 'green'],
        // The Ads Manager (Services\Ads). It was reachable from its own pages only, so an
        // advertiser who left it had no way back.
        'ads'        => ['Ads',        '/account/ads',             'M3 10v4h3l6 5V5L6 10zM16 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12', 'info'],
        'saved'      => ['Saved',      '/account?tab=saved',       'M6 3h12v18l-6-4-6 4z', 'gold'],
        'security'   => ['Security',   '/account?tab=security',    'M12 2.4l7.6 2.7v5.8c0 4.7-3.1 8.3-7.6 10-4.5-1.7-7.6-5.3-7.6-10V5.1z', 'ink'],
        'settings'   => ['Settings',   '/account?tab=settings',    'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v3M12 19v3M2 12h3M19 12h3', 'ink'],
    ];

    /**
     * Pages under a section rather than sections of their own, with the title they carry.
     * Voting points are a pocket of the Wallet (owner, 17 Oct 2026: "the voting points and
     * so on is wallet"), so /account/points is the points pocket's own screen and the rail
     * marks Wallet while it is open.
     */
    public const SUB_PAGES = ['points' => ['Voting points', 'wallet'], 'history' => ['Transaction history', 'wallet'], 'withdraw' => ['Withdraw', 'wallet'], 'receipt' => ['Receipt', 'wallet']];

    /**
     * The rail's groups, named for the job (Account Wallet v2, 21 Oct 2026): the overview
     * alone, then Money, Taking part and You. A section missing from every group is still
     * drawn, under the last one, so a new section can never fall out of the rail.
     */
    public const GROUPS = [
        ''            => ['overview'],
        'Money'       => ['wallet', 'referral', 'purchases', 'ads'],
        'Taking part' => ['challenges', 'activity', 'saved'],
        'You'         => ['security', 'settings'],
    ];

    /** The tabs `/account?tab=` itself draws. The others are their own pages. */
    public const PAGE_TABS = ['overview', 'referral', 'challenges', 'purchases', 'saved', 'security', 'settings'];

    /**
     * Sections drawn only for the members they concern. None today: the community return was
     * one, a nominee's alone, until it became a pocket of the Wallet — which every member
     * has, because referral commission is money too. Kept as the mechanism, empty.
     *
     * @var list<string>
     */
    public const CONDITIONAL = [];

    /** The lede under each section's title (the DC's `pageLead`, where it states a fact). */
    private const LEADS = [
        'points'     => 'Earned by taking part, and spent on votes.',
        'referral'   => 'Share your link. When people you invite join and take part, it counts for you.',
        'wallet'     => 'Money this platform owes you, where it came from, and how to take it out.',
        'challenges' => 'The challenges you have entered, and how far each has got.',
        'purchases'  => 'Tickets and orders, with where each one is up to.',
        'activity'   => 'What has happened to the things you nominated, voted for and bought.',
        'saved'      => 'Conversations you saved to come back to.',
        'security'   => 'How you sign in, and the devices that can.',
        'settings'   => 'Your details, what you care about, and how the site reads for you.',
    ];

    public static function valid(string $tab): string
    {
        return in_array($tab, self::PAGE_TABS, true) ? $tab : 'overview';
    }

    /**
     * The sections with their counts, the current one marked.
     *
     * @param array<string,int|string> $counts key => the number beside it ('' or 0 for none)
     * @param list<string>             $attention keys whose number asks to be looked at
     * @return list<array{key:string,label:string,href:string,d:string,tone:string,n:string,attention:bool,current:bool}>
     */
    public static function tabs(string $current, array $counts = [], array $attention = []): array
    {
        $out = [];
        foreach (self::SECTIONS as $k => [$label, $href, $d, $tone]) {
            if (in_array($k, self::CONDITIONAL, true) && $k !== $current && !isset($counts[$k])) continue;
            $n = $counts[$k] ?? '';
            $out[] = [
                'key' => $k, 'label' => Translator::t($label), 'href' => $href, 'd' => $d, 'tone' => $tone,
                'n' => ($n === 0 || $n === '0') ? '' : (is_int($n) ? number_format($n) : (string) $n),
                'attention' => in_array($k, $attention, true) && $n !== '' && $n !== 0,
                'current' => $k === $current,
            ];
        }

        return $out;
    }

    /**
     * The tabs from tabs(), in their groups.
     *
     * @param  list<array<string,mixed>> $tabs
     * @return list<array{label:string, items:list<array<string,mixed>>}>
     */
    public static function groups(array $tabs): array
    {
        $byKey = [];
        foreach ($tabs as $t) $byKey[$t['key']] = $t;
        $out = [];
        foreach (self::GROUPS as $label => $keys) {
            $items = [];
            foreach ($keys as $k) {
                if (isset($byKey[$k])) { $items[] = $byKey[$k]; unset($byKey[$k]); }
            }
            if ($items !== []) $out[] = ['label' => $label === '' ? '' : Translator::t($label), 'items' => $items];
        }
        if ($byKey !== [] && $out !== []) {
            array_push($out[count($out) - 1]['items'], ...array_values($byKey));
        }
        return $out;
    }

    public static function title(string $tab): string
    {
        return $tab === 'overview' ? Translator::t('Your account')
            : Translator::t(self::SECTIONS[$tab][0] ?? self::SUB_PAGES[$tab][0] ?? 'Your account');
    }

    public static function lead(string $tab): string
    {
        $l = self::LEADS[$tab] ?? '';

        return $l === '' ? '' : Translator::t($l, ['%per%' => (string) PointsService::pointsPerVote()]);
    }

    /**
     * The numbers beside the sections, read for whichever account page is drawing the rail.
     * Each figure is the same call the section's own view makes, so the two cannot differ.
     *
     * @return array{counts:array<string,int|string>, attention:list<string>}
     */
    public static function countsFor(object $user, ?CommunityService $community = null): array
    {
        $id = (int) $user->id; $email = (string) $user->email;
        $c = [];
        try { $c['referral'] = (int) (ReferralService::stats($id)['referrals'] ?? 0); } catch (\Throwable) {}
        // No naira beside Wallet: the balance is the wallet's own headline, and a second
        // copy in the rail is a figure the hide-balance eye cannot reach (v2, no repeated facts).
        try { $c['challenges'] = count(ChallengeService::minePublic($id)); } catch (\Throwable) {}
        try { $c['purchases'] = count(MemberActivityService::ordersFor($email, 10)) + count(MemberActivityService::ticketsFor($email, 10)); } catch (\Throwable) {}
        try {
            $alerts = (new AlertService())->forMember($id, $email);
            $c['activity'] = count(array_filter($alerts, static fn ($a) => $a['unread']));
        } catch (\Throwable) {}
        try { if ($community) $c['saved'] = count($community->bookmarkedThreads($id, 12)); } catch (\Throwable) {}

        return ['counts' => $c, 'attention' => ['activity']];
    }
}
