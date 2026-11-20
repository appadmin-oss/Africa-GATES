<?php
/**
 * Remove arrivals already recorded that were never a person reading a page.
 *
 * ── WHAT WAS COUNTED, AND WHY A REPAIR ──────────────────────────────────────
 *
 * The tracker wrote a row before the handler ran and never looked at the answer, so a
 * request for a path this site does not have was an arrival. This domain used to host a
 * WordPress site with an events-calendar plugin, and feed readers, aggregators and
 * scanners still poll its old addresses. On production `/feed`, `/events/feed` and
 * `/wp-json/tribe/events/v1/events` were three of the top four landing pages, nearly
 * thirty thousand arrivals between them and not one of which did anything. Every one was
 * a 404. One SOURCE was a serialised PHP array: a known exploit probe sent in the Referer
 * header, recorded as if it were a website that had sent us a visitor.
 *
 * The writer is fixed (VisitTracker::discard(), isPageNavigation(), referrerIsForged()).
 * That protects every arrival from now on and does nothing for the rows already in the
 * table. They are the table's biggest figures, and the analytics page ranks by them.
 *
 * ── WHAT THIS CAN AND CANNOT FIND ────────────────────────────────────────────
 *
 * The status code and the request headers were never stored, so the rule the writer now
 * applies cannot be applied backwards. What can be recognised from a stored row is the
 * shape of a path this site has never served (WordPress's own prefixes, its feeds, a
 * `.php` file, the files scanners hunt for) and a source that is not a host name. Bots
 * that requested real pages with a browser's User-Agent stay in the old figures, so
 * those figures stay high until they age out of the retention window.
 *
 * Idempotent: a second run finds nothing. NEVER exit/die here (include()d in a loop).
 */
require __DIR__ . '/../bootstrap.php';
\AfricaGates\Support\Clock::boot();

use Illuminate\Database\Capsule\Manager as DB;

if (!DB::schema()->hasTable('gates_visits')) {
    echo "  · gates_visits absent — nothing to repair\n";
    echo "visit noise repair OK\n";
    return;
}

/** A path no route here has ever answered. */
$notAPage = static function (string $p): bool {
    $p = strtolower($p);
    if ($p === '/feed' || str_ends_with($p, '/feed') || str_contains($p, '/feed/')) return true;
    if (str_ends_with($p, '.php') || str_contains($p, '.php/')) return true;
    foreach (['/wp-', '/wp/', '/wordpress', '/xmlrpc', '/.env', '/.git', '/cgi-bin', '/vendor/', '/phpmyadmin'] as $prefix) {
        if (str_starts_with($p, $prefix)) return true;
    }
    return false;
};

/**
 * A source carrying code rather than a name: the characters a serialised array or an
 * injected statement needs, and that no host name and no organiser's tag contains.
 */
$forgedSource = static fn (string $s): bool => preg_match('/[|{}"\';<>]/', $s) === 1;

$gone = 0;
try {
    $ids = [];
    DB::table('gates_visits')->select(['id', 'landing_path', 'source'])->orderBy('id')
        ->chunk(1000, function ($rows) use (&$ids, $notAPage, $forgedSource): void {
            foreach ($rows as $r) {
                if ($notAPage((string) ($r->landing_path ?? '')) || $forgedSource((string) ($r->source ?? ''))) {
                    $ids[] = (int) $r->id;
                }
            }
        });
    foreach (array_chunk($ids, 500) as $chunk) {
        $gone += DB::table('gates_visits')->whereIn('id', $chunk)->delete();
    }
} catch (\Throwable $e) {
    echo "  ! visit noise repair: " . $e->getMessage() . "\n";
}

echo "  · removed {$gone} arrival(s) that were never a page\n";
echo "visit noise repair OK\n";
