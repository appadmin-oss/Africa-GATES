<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\CoverImage;
use AfricaGates\Services\EventsFront;
use AfricaGates\Support\CoverKind;
use AfricaGates\Support\EventTime;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /og/{subject}/{id}-{ratio}.png — the default cover as a share image (DEFAULT-GRAPHICS §7).
 *
 * Only `event` today: it is the one subject with a share image that is a FALLBACK (an event
 * with no uploaded photo). The other subjects 404 until a page of theirs needs one, rather
 * than serving an image nobody links to.
 *
 * ── THE SAME DOOR AS THE PAGE ───────────────────────────────────────────────
 *
 * The event is read through `EventsFront::liveOnly()` and `status = 'published'`, exactly as
 * the detail page reads it, so a draft or a sandbox event has no share image either — a
 * lookup BY ID is the shape that leaked the sandbox before (CLAUDE.md, "a lookup by id has no
 * containment at all").
 *
 * ── CACHED BY WHAT IT DRAWS ─────────────────────────────────────────────────
 *
 * The spec says "cache by id + updated_at"; this table has no `updated_at`, so the key is a
 * hash of everything the image prints (title, date, time, place, host, kind). A renamed event
 * gets a new image on its next request; an unchanged one is read off disk.
 */
final class CoverImageController
{
    private const CACHE_DIR = '/var/cache/og';

    public function show(Request $req, Response $res, array $args): Response
    {
        $subject = (string) ($args['subject'] ?? '');
        $id      = (int) ($args['id'] ?? 0);
        $ratio   = (string) ($args['ratio'] ?? '');
        if ($subject !== 'event' || $id <= 0 || !in_array($ratio, CoverImage::RATIOS, true)) {
            return $res->withStatus(404);
        }

        $e = EventsFront::liveOnly(DB::table('gates_site_events as e')
            ->where('e.id', $id)->where('e.status', 'published'))->first(['e.*']);
        if (!$e) return $res->withStatus(404);

        $card = self::facts((array) $e);
        $key  = sha1(json_encode([$card, $ratio]) ?: '');
        $dir  = dirname(__DIR__, 2) . self::CACHE_DIR;
        $file = $dir . '/event-' . $id . '-' . $ratio . '-' . substr($key, 0, 16) . '.png';

        $png = is_readable($file) ? (string) file_get_contents($file) : '';
        if ($png === '') {
            $png = (string) (new CoverImage())->png($card, $ratio);
            if ($png === '') return $res->withStatus(404);
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                foreach (glob($dir . '/event-' . $id . '-' . $ratio . '-*.png') ?: [] as $old) @unlink($old);
                @file_put_contents($file, $png, LOCK_EX);
            }
        }

        $res->getBody()->write($png);

        return $res->withHeader('Content-Type', 'image/png')
                   ->withHeader('Cache-Control', 'public, max-age=3600')
                   ->withHeader('ETag', '"' . substr($key, 0, 16) . '"');
    }

    /**
     * What the image prints about an event — also what the cache key hashes, so the two
     * cannot disagree about what counts as a change.
     *
     * @param array<string,mixed> $e a gates_site_events row
     * @return array{subject:string, kind:string, award_linked:bool, title:string, date:string, time:string, place:string, host:string}
     */
    public static function facts(array $e): array
    {
        $id    = (int) ($e['id'] ?? 0);
        $start = (string) ($e['event_date'] ?? '');
        $hosts = $id > 0 ? EventsFront::hosts([$id]) : [];
        $award = $id > 0 && isset(EventsFront::linked([$id])[$id]);

        return [
            'subject'      => 'event',
            'kind'         => CoverKind::eventKind($e['cover_kind'] ?? null, $award),
            'award_linked' => $award,
            'title'        => trim((string) ($e['title'] ?? '')),
            'date'         => $start !== '' ? EventTime::at($e, $start, 'Y-m-d') : '',
            'time'         => $start !== '' ? EventTime::zoned($e, $start, 'H:i') : '',
            'place'        => trim((string) ($e['venue'] ?? '')) ?: trim((string) ($e['location'] ?? '')),
            'host'         => (string) ($hosts[$id] ?? ''),
        ];
    }

    /** The three Google Event images (§7), for the JSON-LD `image` array. @return list<string> */
    public static function eventImages(int $id): array
    {
        return array_map(static fn (string $r): string => '/og/event/' . $id . '-' . $r . '.png',
            ['1200x675', '1200x900', '1200x1200']);
    }
}
