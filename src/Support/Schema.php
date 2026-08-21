<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * schema.org JSON-LD, built in PHP rather than hand-assembled in Twig.
 *
 * ── WHY NOT IN THE TEMPLATE ──────────────────────────────────────────────────
 * A nominee called O'Brien, an event titled `Gala "2026"`, a venue with a newline in
 * it — any of them breaks hand-written JSON in a template, and the failure is silent:
 * Google drops the block and nobody notices for a quarter. Built here, `json_encode`
 * owns the escaping and the structure is checkable by a test.
 *
 * ── AND WHY THESE THREE TYPES ────────────────────────────────────────────────
 * Not everything benefits. The three that do, on this platform:
 *
 *   · Event — the only one with a commercial rich result. Date, venue and PRICE show
 *     in the search listing itself, which is the difference between an impression and
 *     a click on a page that sells tickets.
 *   · Person — hundreds of nominee pages. People search NAMES, and a name is the one
 *     query where a small site can outrank a large one, because the large one has no
 *     page for it.
 *   · ItemList — the leaderboard. "Africa GATES winners 2026" spikes hard around
 *     results day and resolves to a ranked list, which is exactly what this markup
 *     describes.
 *
 * Organization/WebSite/FAQPage/Article already exist in the templates; this does not
 * duplicate them.
 */
final class Schema
{
    /**
     * An event that may or may not sell tickets.
     *
     * @param array<string,mixed> $e   a gates_site_events row
     * @param list<array{name?:string,price?:int|string,url?:string,available?:bool}> $tiers
     * @return array<string,mixed>
     */
    public static function event(array $e, string $siteUrl, array $tiers = [], string $image = ''): array
    {
        $slug = trim((string) ($e['slug'] ?? ''));
        $url  = rtrim($siteUrl, '/') . '/events' . ($slug !== '' ? '/' . rawurlencode($slug) : '');

        $out = [
            '@context'   => 'https://schema.org',
            '@type'      => 'Event',
            'name'       => self::text($e['title'] ?? ''),
            'url'        => $url,
            // Google warns on a missing eventStatus and on a missing attendance mode; both
            // are cheap to state and both change how the result renders.
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];

        $start = self::iso($e['event_date'] ?? null);
        if ($start !== null) $out['startDate'] = $start;
        $end = self::iso($e['ends_at'] ?? null);
        if ($end !== null) $out['endDate'] = $end;

        $desc = self::text($e['summary'] ?? $e['description'] ?? '');
        if ($desc !== '') $out['description'] = mb_substr($desc, 0, 500);

        if ($image !== '') $out['image'] = [self::absolute($image, $siteUrl)];

        $venue = self::text($e['venue'] ?? '');
        if ($venue !== '') {
            $out['location'] = [
                '@type'   => 'Place',
                'name'    => $venue,
                'address' => self::text($e['address'] ?? $venue),
            ];
        }

        $out['organizer'] = [
            '@type' => 'Organization',
            'name'  => 'Africa GATES',
            'url'   => rtrim($siteUrl, '/'),
        ];

        // Offers are what produce the price in the listing. Only real, priced tiers —
        // inventing a free offer for an event that has none is a rich result that lies.
        $offers = [];
        foreach ($tiers as $t) {
            $price = (int) ($t['price'] ?? 0);
            if ($price < 1) continue;
            $offers[] = array_filter([
                '@type'         => 'Offer',
                'name'          => self::text($t['name'] ?? ''),
                'price'         => (string) $price,
                'priceCurrency' => 'NGN',
                'url'           => $url,
                'availability'  => ($t['available'] ?? true)
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/SoldOut',
            ], static fn($v) => $v !== '' && $v !== null);
        }
        if ($offers !== []) $out['offers'] = $offers;

        return $out;
    }

    /**
     * A nominee or a registry profile.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function person(array $p, string $siteUrl, string $pageUrl, string $image = ''): array
    {
        $out = [
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => self::text($p['name'] ?? $p['display_name'] ?? ''),
            'url'      => $pageUrl,
        ];

        $desc = self::text($p['tagline'] ?? $p['story'] ?? $p['bio'] ?? '');
        if ($desc !== '') $out['description'] = mb_substr($desc, 0, 500);

        if ($image !== '') $out['image'] = self::absolute($image, $siteUrl);

        $org = self::text($p['organisation'] ?? $p['nominee_org'] ?? '');
        if ($org !== '') {
            $out['affiliation'] = ['@type' => 'Organization', 'name' => $org];
        }

        $country = trim((string) ($p['country_code'] ?? ''));
        if ($country !== '') {
            $out['nationality'] = ['@type' => 'Country', 'name' => strtoupper($country)];
        }

        // The award is the reason the page exists, so it is the thing worth stating.
        $cat = self::text($p['category_title'] ?? '');
        if ($cat !== '') {
            $out['award'] = 'Africa GATES nominee — ' . $cat;
        }

        return $out;
    }

    /**
     * A ranked list — the leaderboard.
     *
     * @param list<array{name?:string,url?:string}> $items already in rank order
     * @return array<string,mixed>
     */
    public static function itemList(string $name, array $items, string $siteUrl, int $limit = 25): array
    {
        $elements = [];
        $pos = 0;
        foreach ($items as $i) {
            if ($pos >= $limit) break;   // a 400-entry list in the head helps nobody
            $label = self::text($i['name'] ?? '');
            if ($label === '') continue;
            $pos++;
            $el = ['@type' => 'ListItem', 'position' => $pos, 'name' => $label];
            $u = trim((string) ($i['url'] ?? ''));
            if ($u !== '') $el['url'] = self::absolute($u, $siteUrl);
            $elements[] = $el;
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'name'            => self::text($name),
            'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
            'numberOfItems'   => count($elements),
            'itemListElement' => $elements,
        ];
    }

    /** Collapse whitespace and strip markup — schema values are plain text. */
    private static function text(mixed $v): string
    {
        $s = trim(strip_tags((string) $v));

        return (string) preg_replace('/\s+/', ' ', $s);
    }

    /**
     * A stored (UTC) datetime as ISO-8601 WITH the offset.
     *
     * Google reads a bare local datetime as the searcher's own timezone, so an event
     * at 18:00 WAT advertises itself as 18:00 wherever the reader is. The offset is
     * the whole point of emitting this rather than the stored string.
     */
    private static function iso(mixed $v): ?string
    {
        $s = trim((string) $v);
        if ($s === '' || str_starts_with($s, '0000-00-00')) return null;

        try {
            return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(DisplayTime::zone()))
                ->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Schema requires absolute URLs; a relative one is silently ignored. */
    private static function absolute(string $url, string $siteUrl): string
    {
        if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
    }
}
