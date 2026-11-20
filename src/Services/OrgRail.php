<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Translator;

/**
 * The organisation console's sections — one list, read by the rail AND by the pages.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A SERVICE AND NOT A `{% set %}` IN THE TEMPLATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The console that was destroyed on 3 Oct 2026 built its rail from an array literal inside
 * the template, and four of the guard tests that died with it were about WHICH SECTIONS A
 * GIVEN ORGANISATION IS OFFERED — a donation partner gets the money and not the stands, a
 * vendor gets the stands and not the appeals, a vendor who later takes donations gets both.
 * Those are not presentation rules. A vendor shown an Appeals section is being told they
 * may run charitable appeals, which `OrgCampaign` will refuse; a donation partner shown
 * Stand fees is being shown an invoice schedule that will never bill them.
 *
 * So the gate lives here, next to the counts, and the SECTION'S OWN PAGE asks the same
 * function the rail does. A rail offering a section the page then refuses to draw is the
 * two-lists-claiming-one-thing shape this codebase has paid for repeatedly, and it fails
 * silently: the rail looks complete and a tab leads to nothing.
 *
 * ── WHAT DECIDES IT ─────────────────────────────────────────────────────────
 *
 * `is_vendor` is what the organisation signed up as; `has_money` is whether any gift has
 * ever arrived. Neither alone is enough. A vendor who later takes donations has both
 * halves, and branching on `kind` alone would hand them a console that has quietly decided
 * what they are — which is the reasoning `OrgDashboardController` already spells out for
 * passing both arrays unconditionally.
 *
 * @see \AfricaGates\Services\AccountRail  the member console's equivalent, same shape
 */
final class OrgRail
{
    /**
     * key => [label, href, icon path, tone, gate]
     *
     * `tone` is an `Accent` family name, never a colour. The gate is read by
     * {@see self::offers()}; `always` is a section every organisation has.
     *
     * @var array<string,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public const SECTIONS = [
        'overview'  => ['Overview', '/org', 'M3 9.5 12 3l9 6.5V20H3z', 'ink', 'always'],
        'page'      => ['Your page', '/org?tab=page',
            'M4 4h16v12H4zM8 20h8M12 16v4', 'green', 'money'],
        'appeals'   => ['Appeals', '/org?tab=appeals',
            'M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9z', 'live', 'money'],
        'donations' => ['Donations', '/org?tab=donations',
            'M12 3v18M8 7h6a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h7', 'gold', 'money'],
        'payouts'   => ['Getting paid', '/org?tab=payouts',
            'M3 7h18v10H3zM3 11h18M7 15h3', 'green', 'money'],
        'documents' => ['Documents', '/org?tab=documents',
            'M7 3h7l4 4v14H7zM14 3v4h4', 'ink', 'documents'],
        'stands'    => ['Stands', '/org?tab=stands',
            'M4 9h16l-1 11H5zM4 9l2-5h12l2 5M9 20v-5h6v5', 'info', 'stands'],
        'catalogue' => ['What you sell', '/org?tab=catalogue',
            'M5.5 8h13l-1 12h-11zM9 8V7a3 3 0 0 1 6 0v1', 'info', 'vendor'],
        'fees'      => ['Stand fees', '/org?tab=fees',
            'M6 3h12v18l-6-4-6 4z', 'gold', 'fees'],
    ];

    /** Every section the console draws from `/org` itself. */
    public const PAGE_TABS = ['overview', 'page', 'appeals', 'donations', 'payouts',
                              'documents', 'stands', 'catalogue', 'fees'];

    /** The lede under each section's title. Where it states a fact, it states one. */
    private const LEADS = [
        'page'      => 'What a donor sees at your own address. Everything here is yours to '
                     . 'switch on, write and reorder.',
        'appeals'   => 'What you are raising for, and how far each one has got.',
        'donations' => 'Every gift that has cleared, and what reached you after fees.',
        'payouts'   => 'Where the money goes, when it goes, and what you can request now.',
        'documents' => 'What we need on file before money can move, and what is already here.',
        'stands'    => 'Where you have applied to trade, and anything offered to you.',
        'catalogue' => 'What you are bringing. This is what visitors see beside your stand.',
        'fees'      => 'What a pitch costs, and what has been invoiced to you.',
    ];

    public static function valid(string $tab): string
    {
        return in_array($tab, self::PAGE_TABS, true) ? $tab : 'overview';
    }

    /**
     * WHETHER THIS ORGANISATION IS OFFERED THIS SECTION.
     *
     * The one answer. The rail asks it to decide what to draw and each section's view asks
     * it before drawing itself, so a tab can never lead somewhere that refuses to render.
     *
     * `$hasDocuments` is whether anything is REQUIRED of them, not whether anything is
     * filed — an organisation with nothing outstanding still needs the section to see what
     * is on file and when it expires.
     */
    public static function offers(string $tab, bool $isVendor, bool $hasMoney,
                                  bool $hasDocuments, bool $hasStands): bool
    {
        // A vendor PAYS for a pitch and has no donation rows; a donation partner has no
        // stand. A vendor who later takes gifts has both halves, which is why this is two
        // facts and not one `kind`.
        $money = !$isVendor || $hasMoney;

        return match (self::SECTIONS[$tab][4] ?? '') {
            'always'    => true,
            'money'     => $money,
            'documents' => $hasDocuments,
            'stands'    => $hasStands || $isVendor,
            'vendor'    => $isVendor,
            // Stand fees answer "what does a pitch cost me", which is only the question for
            // somebody whose console is not about incoming money.
            'fees'      => !$money,
            default     => false,
        };
    }

    /**
     * The sections this organisation is offered, with their counts, the current one marked.
     *
     * @param array<string,int|string> $counts    key => the number beside it
     * @param list<string>             $attention keys whose number asks to be looked at
     * @return list<array{key:string,label:string,href:string,d:string,tone:string,n:string,attention:bool,current:bool}>
     */
    public static function tabs(string $current, bool $isVendor, bool $hasMoney,
                                 bool $hasDocuments, bool $hasStands,
                                 array $counts = [], array $attention = []): array
    {
        $out = [];
        foreach (self::SECTIONS as $k => [$label, $href, $d, $tone, $_gate]) {
            if (!self::offers($k, $isVendor, $hasMoney, $hasDocuments, $hasStands)) continue;

            $n = $counts[$k] ?? '';
            $out[] = [
                'key' => $k, 'label' => Translator::t($label), 'href' => $href,
                'd' => $d, 'tone' => $tone,
                'n' => ($n === 0 || $n === '0') ? '' : (is_int($n) ? number_format($n) : (string) $n),
                'attention' => in_array($k, $attention, true) && $n !== '' && $n !== 0,
                'current' => $k === $current,
            ];
        }

        return $out;
    }

    public static function title(string $tab): string
    {
        return $tab === 'overview'
            ? Translator::t('Your organisation')
            : Translator::t(self::SECTIONS[$tab][0] ?? 'Your organisation');
    }

    public static function lead(string $tab): string
    {
        $l = self::LEADS[$tab] ?? '';

        return $l === '' ? '' : Translator::t($l);
    }

    /**
     * THE COUNT BESIDE A SECTION, AND WHY ONE OF THEM IS A PHRASE.
     *
     * A bare number is a count of what is THERE. On Documents that is the opposite of what
     * the organisation needs to know: "3" beside Documents reads as three filed, when the
     * three that matter are the ones still missing. So the outstanding ones say so in a
     * word — the rule the destroyed `test_the_rail_says_what_is_outstanding_in_a_word`
     * held — and `attention` is what paints it as a chore rather than a tally.
     *
     * @param array<string,mixed> $f the figures the console already loaded
     * @return array{counts:array<string,int|string>, attention:list<string>}
     */
    public static function countsFrom(array $f): array
    {
        $missing = (int) ($f['missing'] ?? 0);
        $offers  = (int) ($f['offers'] ?? 0);
        $filed   = (int) ($f['documents'] ?? 0);

        $counts = [
            'appeals'   => (int) ($f['appeals'] ?? 0),
            'donations' => (int) ($f['donations'] ?? 0),
            'catalogue' => (int) ($f['items'] ?? 0),
            'documents' => $missing > 0
                ? Translator::t('%n% to do', ['%n%' => (string) $missing])
                : ($filed > 0 ? Translator::t('done') : ''),
            'stands'    => $offers > 0
                ? Translator::t('%n% offered', ['%n%' => (string) $offers])
                : (int) ($f['stands'] ?? 0),
        ];

        $attention = [];
        if ($missing > 0) $attention[] = 'documents';
        if ($offers > 0)  $attention[] = 'stands';

        return ['counts' => $counts, 'attention' => $attention];
    }
}
