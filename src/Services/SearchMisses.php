<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SearchWords;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Searches on Discover that found nothing, counted per query (migration
 * 2027_05_17_search_misses.php explains why, and what is never stored).
 *
 * One writer (DiscoverController, after the typo correction has had its try — a search the
 * correction rescued is not a miss) and one reader (the admin Analytics page). A record with
 * no reader is the fault this codebase keeps finding; this one shipped with its reader.
 */
final class SearchMisses
{
    /** A query worth keeping: folded, trimmed, at most 80 characters, and not a contact detail. */
    public static function normalise(string $q): ?string
    {
        $f = trim((string) preg_replace('/\s+/u', ' ', SearchWords::fold($q)));
        if (mb_strlen($f) < 2) return null;
        // People paste emails and phone numbers into search boxes. Neither is ever stored.
        if (str_contains($f, '@') || preg_match('/\d[\d\s\-().]{5,}\d/', $f)) return null;
        return mb_substr($f, 0, 80);
    }

    public static function record(string $q, string $tab): void
    {
        $f = self::normalise($q);
        if ($f === null || !SchemaHas::table('gates_search_misses')) return;
        $now = date('Y-m-d H:i:s');
        try {
            $n = DB::table('gates_search_misses')->where('query', $f)->where('tab', $tab)
                ->update(['hits' => DB::raw('hits + 1'), 'last_at' => $now]);
            if ($n === 0) {
                DB::table('gates_search_misses')->insertOrIgnore(['query' => $f, 'tab' => $tab, 'hits' => 1, 'first_at' => $now, 'last_at' => $now]);
            }
        } catch (\Throwable) {
            // A count that failed must never fail the search page.
        }
    }

    /**
     * The most-repeated misses seen in the window, for the Analytics page.
     *
     * @return list<array{query:string, tab:string, hits:int, last_at:string}>
     */
    public static function top(int $days, int $limit = 15): array
    {
        if (!SchemaHas::table('gates_search_misses')) return [];
        try {
            return DB::table('gates_search_misses')->where('last_at', '>=', date('Y-m-d H:i:s', time() - $days * 86400))
                ->orderByDesc('hits')->orderByDesc('last_at')->limit($limit)->get(['query', 'tab', 'hits', 'last_at'])
                ->map(static fn ($r) => ['query' => (string) $r->query, 'tab' => (string) $r->tab, 'hits' => (int) $r->hits, 'last_at' => (string) $r->last_at])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
