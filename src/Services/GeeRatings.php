<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A visitor's thumb on one of Gee's answers, and the operator's 30-day reading of them.
 *
 * Until this, the only measure of whether Gee helped was Gee: the ledger records that a call
 * was made and what it cost, never whether the person who asked got anywhere. The console
 * assistant learned the same lesson first (Admin\Services\AssistantThreads::ratings()).
 *
 * What is kept is deliberately thin — mode, source, the thumb and the page's PATH — and the
 * reason is the privacy note, which promises that what somebody types stays in their tab.
 * The words are never sent here; the path has its query string removed, because a query on
 * this site can carry a payment reference (`/support?ref=AFG-…`).
 */
final class GeeRatings
{
    public const MODES   = ['guide', 'support'];
    /** The sources GuideController and the support road answer with; anything else is ''. */
    public const SOURCES = ['ai', 'agent', 'scripted', 'help', 'support'];

    public static function record(string $mode, string $source, bool $helpful, string $path): bool
    {
        if (!SchemaHas::table('gates_gee_ratings')) return false;
        try {
            DB::table('gates_gee_ratings')->insert([
                'mode'       => in_array($mode, self::MODES, true) ? $mode : 'guide',
                'source'     => in_array($source, self::SOURCES, true) ? $source : '',
                'helpful'    => $helpful ? 1 : 0,
                'path'       => self::path($path),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A site path and nothing after it: no query, no fragment, no host, at most 200 bytes. */
    public static function path(string $p): string
    {
        $p = (string) preg_replace('/[?#].*$/s', '', trim($p));
        if (preg_match('{^/(?!/)[A-Za-z0-9/_.~%-]*$}', $p) !== 1) return '';
        return substr($p, 0, 200);
    }

    /**
     * 30 days: how many thumbs, how many up, and the pages answered worst — at least three
     * ratings and more down than up, most downs first. A page with one unhappy visitor is an
     * anecdote, and naming it would send somebody rewriting a page that is fine.
     *
     * @return array{rated:int, helpful:int, worst:list<array{path:string, down:int, rated:int}>}
     */
    public static function summary(int $days = 30): array
    {
        $none = ['rated' => 0, 'helpful' => 0, 'worst' => []];
        if (!SchemaHas::table('gates_gee_ratings')) return $none;
        try {
            $since = date('Y-m-d H:i:s', time() - $days * 86400);
            $base  = DB::table('gates_gee_ratings')->where('created_at', '>=', $since);
            $worst = (clone $base)->where('path', '!=', '')
                ->selectRaw('path, COUNT(*) AS rated, SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) AS down')
                ->groupBy('path')
                ->havingRaw('COUNT(*) >= 3 AND SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) * 2 > COUNT(*)')
                ->orderByRaw('SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) DESC')
                ->limit(3)->get();
            return [
                'rated'   => (clone $base)->count(),
                'helpful' => (clone $base)->where('helpful', 1)->count(),
                'worst'   => array_map(static fn ($r): array => ['path' => (string) $r->path, 'down' => (int) $r->down, 'rated' => (int) $r->rated], $worst->all()),
            ];
        } catch (\Throwable) {
            return $none;
        }
    }
}
