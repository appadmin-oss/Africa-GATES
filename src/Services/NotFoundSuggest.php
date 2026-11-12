<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SearchWords;

/**
 * "Were you looking for …" on the 404 page (10 Nov 2026).
 *
 * People arrive on broken addresses: a WhatsApp forward cut short, a flier typed by hand, a
 * nominee link whose id changed in a merge. The address usually still holds what they wanted —
 * `/vote/lagos-youth/99-chiamka-eze` is a name, `/awards/lagos-yuoth` an award — and the page
 * offered only "Back to home". So the words in the path are searched, with the same correction
 * Discover and the palette use, and up to four answers are offered with the search box filled.
 *
 * CHEAP AND REFUSING BY DEFAULT. A 404 is also what every scanner on the internet requests, so
 * nothing is searched for an asset, a file extension, a known probe (wp-, .php, .env) or a path
 * with no word of three letters; at most three words; the search index's own limits apply.
 */
final class NotFoundSuggest
{
    /** Path segments that are the site's own structure, never what somebody was looking for. */
    private const STRUCTURE = ['vote', 'votes', 'awards', 'award', 'registry', 'results', 'result', 'events', 'event',
        'discover', 'giving', 'gift', 'donate', 'nominate', 'nominee', 'nominees', 'profile', 'profiles', 'help',
        'blog', 'shop', 'community', 'account', 'the', 'and', 'for', 'of', 'in', 'www', 'http', 'https', 'index', 'page'];

    /** The words worth searching for in a broken path, or [] when it is not worth it. @return list<string> */
    public static function words(string $path): array
    {
        return array_slice(array_merge(...array_values(self::segments($path) ?: [[]])), 0, 3);
    }

    /**
     * The words of each path segment, the LAST segment first: it is the most specific part of
     * an address (`/vote/lagos-youth/99-chiamka-eze` is about Chiamaka, not about Lagos).
     *
     * @return list<list<string>>
     */
    private static function segments(string $path): array
    {
        $path = strtolower(rawurldecode(parse_url($path, PHP_URL_PATH) ?: ''));
        if ($path === '' || $path === '/') return [];
        // Scanners and files: never a search.
        if (preg_match('~(^/(assets|uploads|min|dist|vendor|cgi-bin)/)|wp-|xmlrpc|\.(php\d?|env|git|ini|sql|bak|zip|js|css|png|jpe?g|gif|svg|webp|ico|txt|xml|json|map)$~', $path)) return [];
        $out = [];
        $seen = [];
        foreach (array_reverse(explode('/', $path)) as $seg) {
            $words = [];
            foreach (preg_split('~[\-_.+ ]+~', $seg, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                if (mb_strlen($w) < 3 || preg_match('/\d/', $w) || in_array($w, self::STRUCTURE, true) || isset($seen[$w])) continue;
                $seen[$w] = true;
                $words[] = $w;
            }
            if ($words !== []) $out[] = array_slice($words, 0, 3);
        }
        return $out;
    }

    /**
     * Up to four places the visitor may have meant, each {title, meta, url}, and the query used.
     *
     * @return array{q:string, items:list<array{title:string, meta:string, url:string}>}
     */
    public static function for(string $path): array
    {
        $segs = self::segments($path);
        if ($segs === []) return ['q' => '', 'items' => []];
        // Most specific first: the last segment's words, then all of them, then the last
        // segment's first word alone (a slug carries words the page never printed). Each is
        // tried as typed and then corrected; the first that finds anything is the answer.
        // At most three tries reach the index — a 404 is also what every scanner requests.
        $tries = array_values(array_unique(array_filter([
            implode(' ', $segs[0]),
            implode(' ', array_slice(array_merge(...$segs), 0, 3)),
            $segs[0][0] ?? '',
        ])));
        $q = $tries[0];
        $r = ['items' => []];
        try {
            $feed = new ActivityFeedService();
            $disc = new Discover($feed);
            foreach (array_slice($tries, 0, 3) as $try) {
                $r = $feed->search($try, 8, interpret: false);
                if ($r['items'] === [] && ($fix = $disc->suggest($try)) !== null) {
                    $r = $feed->search($fix, 8, interpret: false);
                    $try = $fix;
                }
                $q = $try;
                if ($r['items'] !== []) break;
            }
        } catch (\Throwable) {
            return ['q' => $tries[0], 'items' => []];
        }
        if ($r['items'] === []) $q = $tries[0];
        $items = [];
        $seen = [];
        foreach ($r['items'] as $i) {
            $url = (string) ($i['url'] ?? '');
            if ($url === '' || $url === $path || isset($seen[$url])) continue;
            $seen[$url] = true;
            $items[] = ['title' => (string) ($i['title'] ?? ''), 'meta' => (string) ($i['detail'] ?? ''), 'url' => $url];
            if (count($items) === 4) break;
        }
        // Best name match first, as everywhere search ranks.
        usort($items, static fn ($a, $b) => SearchWords::score($b['title'], $q) <=> SearchWords::score($a['title'], $q));
        return ['q' => $q, 'items' => $items];
    }
}
