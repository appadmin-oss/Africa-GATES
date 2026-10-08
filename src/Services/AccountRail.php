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
        'points'     => ['Points',     '/account/points',          'M12 2l2.9 6.3 6.8.7-5.1 4.6 1.5 6.7L12 16.9 5.9 20.3l1.5-6.7L2.3 9l6.8-.7z', 'green'],
        'referral'   => ['Referrals',  '/account?tab=referral',    'M16 11a4 4 0 1 0-8 0M3 21a9 9 0 0 1 18 0M19 8v6M22 11h-6', 'live'],
        'challenges' => ['Challenges', '/account?tab=challenges',  'M6 4h12v4a6 6 0 0 1-12 0zM9 20h6M12 14v6M6 6H3v1a4 4 0 0 0 4 4M18 6h3v1a4 4 0 0 1-4 4', 'gold'],
        'purchases'  => ['Purchases',  '/account?tab=purchases',   'M5.5 8h13l-1 12h-11zM9 8V7a3 3 0 0 1 6 0v1', 'info'],
        'activity'   => ['Activity',   '/account/notifications',   'M3 12h4l3-7 4 14 3-7h4', 'green'],
        'saved'      => ['Saved',      '/account?tab=saved',       'M6 3h12v18l-6-4-6 4z', 'gold'],
        'security'   => ['Security',   '/account?tab=security',    'M12 2.4l7.6 2.7v5.8c0 4.7-3.1 8.3-7.6 10-4.5-1.7-7.6-5.3-7.6-10V5.1z', 'ink'],
        'settings'   => ['Settings',   '/account?tab=settings',    'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v3M12 19v3M2 12h3M19 12h3', 'ink'],
    ];

    /** The tabs `/account?tab=` itself draws. The others are their own pages. */
    public const PAGE_TABS = ['overview', 'referral', 'challenges', 'purchases', 'saved', 'security', 'settings'];

    /** The lede under each section's title (the DC's `pageLead`, where it states a fact). */
    private const LEADS = [
        'points'     => 'Earned by taking part, and spent on votes.',
        'referral'   => 'Share your link. When people you invite join and take part, it counts for you.',
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

    public static function title(string $tab): string
    {
        return $tab === 'overview' ? Translator::t('Your account') : Translator::t(self::SECTIONS[$tab][0] ?? 'Your account');
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
        try { if (PointsService::enabled()) $c['points'] = PointsService::balance($id); } catch (\Throwable) {}
        try { $c['referral'] = (int) (ReferralService::stats($id)['referrals'] ?? 0); } catch (\Throwable) {}
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
