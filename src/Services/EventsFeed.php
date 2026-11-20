<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\{EventTime, Ics};
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The public events calendar as data — `/api/events` and `/events.ics`.
 *
 * Other sites read it. The Afrovanguard site (afrovanguard.org.ng) shows Africa GATES events
 * on its home page, its /events/ page and its members' portal calendar, and before this
 * existed it asked this host for a WordPress events API that was never here: every request
 * was a 404 and no event ever crossed.
 *
 * The SAME rules as the /events page, from {@see EventsFront}: published only, never an
 * event of an inactive programme (the sandbox), and "upcoming" and "past" split exactly as
 * browse() splits them. A feed that disagreed with the page about what is on would be the
 * second reader of one fact.
 *
 * Times go out in UTC with a `Z`, as stored, with the event's own zone beside them — the
 * reader formats for its own audience. `when` is already formatted in that zone for a reader
 * that would rather not.
 */
final class EventsFeed
{
    public const MAX = 50;

    /**
     * @param 'upcoming'|'past' $scope
     * @return list<array<string,mixed>>
     */
    public static function events(string $scope, int $limit, string $base, ?string $now = null): array
    {
        $now   = $now ?? Carbon::now()->toDateTimeString();
        $limit = max(1, min(self::MAX, $limit));
        $q = EventsFront::liveOnly(DB::table('gates_site_events as e')->where('e.status', 'published'));
        if ($scope === 'past') {
            $q->where('e.event_date', '<', $now)
              ->where(static fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '<=', $now))
              ->orderByDesc('e.event_date')->orderByDesc('e.id');
        } else {
            // Upcoming, plus anything still running — the page shows only a streamed one as
            // "Live now", but a calendar elsewhere should still hold a two-day summit on day two.
            $q->where(static function ($w) use ($now): void {
                $w->where('e.event_date', '>=', $now)
                  ->orWhere(static fn ($l) => $l->where('e.event_date', '<', $now)->where('e.end_date', '>', $now));
            })->orderBy('e.event_date')->orderBy('e.id');
        }
        $rows = $q->limit($limit)->get(['e.*'])->map(fn ($r) => (array) $r)->all();

        $ids   = array_column($rows, 'id');
        $hosts = EventsFront::hosts($ids);
        $base  = rtrim($base, '/');
        return array_map(static fn (array $e): array => self::item($e, $hosts, $now, $base), $rows);
    }

    /** One event for another site. @param array<int,string> $hosts */
    private static function item(array $e, array $hosts, string $now, string $base): array
    {
        $r     = EventsFront::row($e, $hosts, $now);
        $abs   = static fn (string $u): string => $u === '' || preg_match('~^https?://~i', $u) ? $u : $base . '/' . ltrim($u, '/');
        $utc   = static fn (?string $v): string => trim((string) $v) === '' ? '' : str_replace(' ', 'T', substr(trim((string) $v), 0, 19)) . 'Z';
        $text  = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) ($e['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) > 600) $text = rtrim(mb_substr($text, 0, 597)) . '…';
        $rsvp = EventsFront::link((string) ($e['rsvp_url'] ?? ''));

        return [
            'id'           => $r['id'],
            'slug'         => $r['slug'],
            'title'        => $r['title'],
            'tagline'      => $r['tagline'],
            'description'  => $text,
            'url'          => $abs($r['url']),
            'calendar_url' => $abs($r['url'] . '/calendar.ics'),
            'rsvp_url'     => $rsvp !== '' ? $rsvp : $abs($r['url']),
            'starts_at'    => $utc($e['event_date'] ?? ''),
            'ends_at'      => $utc($e['end_date'] ?? ''),
            'timezone'     => EventTime::zone($e),
            'when'         => $r['when'],
            'all_day'      => false,
            'venue'        => trim((string) ($e['venue'] ?? '')),
            'location'     => trim((string) ($e['location'] ?? '')),
            'host'         => $r['host'],
            'state'        => $r['state'],
            'tag'          => $r['tag'],
            'price'        => $r['from_text'],
            'cover_image'  => $abs((string) $r['image']),
            'livestream'   => $r['livestream'],
            'recording'    => $r['recording'],
        ];
    }

    /** Every upcoming event as one calendar to subscribe to. */
    public static function ics(string $base): string
    {
        $specs = [];
        foreach (self::events('upcoming', self::MAX, $base) as $x) {
            $specs[] = [
                'uid'         => Ics::uid('event-' . $x['id'] . '-' . $x['slug']),
                'title'       => $x['title'],
                'description' => $x['tagline'] !== '' ? $x['tagline'] : mb_substr($x['description'], 0, 300),
                'location'    => implode(', ', array_filter([$x['venue'], $x['location']])),
                'url'         => $x['url'],
                // Ics reads a bare stored value in this process's zone, which is the zone it
                // was stored in; hand it back the stored form.
                'starts_at'   => str_replace(['T', 'Z'], [' ', ''], $x['starts_at']),
                'ends_at'     => str_replace(['T', 'Z'], [' ', ''], $x['ends_at']),
            ];
        }
        return Ics::calendar($specs);
    }
}
