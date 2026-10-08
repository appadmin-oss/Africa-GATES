<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\CoverKind;

use AfricaGates\Support\EventTime;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Everything the events index draws, read once (Phase 7, §8.10; owner, 4 Oct 2026: "there's
 * nothing like upcoming events or coming soon events on the site").
 *
 * ── UPCOMING LEADS, PAST IS ITS OWN SECTION, NEVER MIXED ─────────────────────
 *
 * The DC's "All" list runs ceremonies to come straight into a staff event that has already
 * happened, with only a grey tag between them. The owner asked for upcoming events first, so
 * the index is two lists: everything still to come, soonest first (the first is the featured
 * "Next up"), then a separate "Past events" section — and the `past` filter shows only that.
 *
 * ── COMING SOON IS A STATE OF AN UPCOMING EVENT, NOT A THIRD LIST ────────────
 *
 * An announced event whose tickets are not on sale yet is still upcoming, and still in date
 * order among the rest; it carries the "Coming soon" tag and the date sales open. The state
 * comes from {@see EventSales}, the same resolver the detail page's card uses.
 *
 * ── THE SANDBOX NEVER REACHES THIS PAGE ──────────────────────────────────────
 *
 * The rehearsal lives in a programme with `is_active = 0` (DemoSeeder). An event linked to an
 * inactive programme is excluded by {@see liveOnly()} — the event equivalent of
 * `DemoSeeder::liveAwardOnly()`: it removes only what it can positively prove is not live, so
 * an event linked to nothing (most events) is kept.
 *
 * ── THE ORGANISER IS THE EVENT'S, NOT THE PLATFORM'S ─────────────────────────
 *
 * Hosts are coming (businesses, governments; GAPS §8f). Nothing here says Africa GATES runs an
 * event. Where the linked programme names a host (`host_name`, 2027_02_14_programme_host) the
 * row says "Hosted by …"; otherwise it says nothing about who organises it.
 */
final class EventsFront
{
    public const FILTERS = ['all', 'upcoming', 'soon', 'live', 'past'];

    /** How many upcoming events the page reads. A calendar of more than this is a search. */
    private const MAX_UPCOMING = 60;
    private const MAX_PAST     = 24;

    /**
     * @return array{filter:string, featured:?array, upcoming:list<array>, past:list<array>,
     *               counts:array<string,int>}
     */
    public static function index(string $filter = 'all', ?string $now = null): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $now    = $now ?? Carbon::now()->toDateTimeString();

        $up = self::liveOnly(DB::table('gates_site_events as e')
            ->where('e.status', 'published')->where('e.event_date', '>=', $now))
            ->orderBy('e.event_date')->orderBy('e.id')->limit(self::MAX_UPCOMING)
            ->get(['e.*'])->map(fn ($r) => (array) $r)->all();
        $past = self::liveOnly(DB::table('gates_site_events as e')
            ->where('e.status', 'published')->where('e.event_date', '<', $now))
            ->orderByDesc('e.event_date')->orderByDesc('e.id')->limit(self::MAX_PAST)
            ->get(['e.*'])->map(fn ($r) => (array) $r)->all();

        $hosts = self::hosts(array_merge(array_column($up, 'id'), array_column($past, 'id')));
        $up    = array_map(fn (array $e) => self::row($e, $hosts, $now), $up);
        $past  = array_map(fn (array $e) => self::row($e, $hosts, $now), $past);

        $counts = [
            'upcoming' => count($up),
            'soon'     => count(array_filter($up, static fn (array $r): bool => $r['state'] === 'soon')),
            'live'     => count(array_filter($up, static fn (array $r): bool => $r['livestream'] !== '')),
            'past'     => count($past),
        ];

        $featured = null;
        if ($filter === 'all' && $up !== []) $featured = array_shift($up);

        $up = match ($filter) {
            'soon'  => array_values(array_filter($up, static fn (array $r): bool => $r['state'] === 'soon')),
            'live'  => array_values(array_filter($up, static fn (array $r): bool => $r['livestream'] !== '')),
            'past'  => [],
            default => $up,
        };
        $past = match ($filter) {
            'all', 'past' => $past,
            // Recordings belong with the livestreams; everything else past stays in its section.
            'live'  => array_values(array_filter($past, static fn (array $r): bool => $r['recording'] !== '')),
            default => [],
        };

        return ['filter' => $filter, 'featured' => $featured, 'upcoming' => $up, 'past' => $past, 'counts' => $counts];
    }

    /**
     * Exclude an event linked to an INACTIVE programme (the sandbox). Linked to nothing is
     * kept. Absent join table (an install that has not migrated) excludes nothing.
     */
    public static function liveOnly(object $q, string $idColumn = 'e.id'): object
    {
        if (!SchemaHas::table('gates_event_programmes')) return $q;

        return $q->whereNotExists(static function ($w) use ($idColumn): void {
            $w->selectRaw('1')
              ->from('gates_event_programmes as lo_ep')
              ->join('gates_award_programmes as lo_p', 'lo_p.id', '=', 'lo_ep.programme_id')
              ->where('lo_p.is_active', 0)
              ->whereColumn('lo_ep.event_id', $idColumn);
        });
    }

    /**
     * One event, as both the index row and the detail page's head read it.
     *
     * @param array<string,mixed> $e
     * @param array<int,string>   $hosts
     * @return array<string,mixed>
     */
    public static function row(array $e, array $hosts, string $now, ?array $tiers = null, ?bool $roomFull = null): array
    {
        $id     = (int) $e['id'];
        $tiers  = $tiers ?? EventTicketService::tiers($id);
        if ($roomFull === null) {
            $cap      = ($e['capacity'] ?? null) !== null ? (int) $e['capacity'] : null;
            $roomFull = $cap !== null && EventTicketService::soldForEvent($id) >= $cap;
        }
        $state  = EventSales::state($e, $tiers, $roomFull, $now);
        $opens  = $state === 'soon' ? EventSales::opensAt($id, $now) : null;
        $low    = EventSales::lowest($tiers, $state);
        $start  = (string) ($e['event_date'] ?? '');
        $days   = null;
        try {
            $days = (int) floor((Carbon::parse($start)->startOfDay()->getTimestamp()
                    - Carbon::parse($now)->startOfDay()->getTimestamp()) / 86400);
        } catch (\Throwable) {}

        return [
            'id'         => $id,
            'slug'       => (string) $e['slug'],
            'url'        => '/events/' . rawurlencode((string) $e['slug']),
            'title'      => (string) $e['title'],
            'tagline'    => trim((string) ($e['tagline'] ?? '')),
            'where'      => trim((string) ($e['venue'] ?? '')) ?: trim((string) ($e['location'] ?? '')),
            'city'       => trim((string) ($e['location'] ?? '')),
            'host'       => $hosts[$id] ?? '',
            'state'      => $state,
            'tag'        => self::tag($state, $e),
            'from'       => $low,
            'from_text'  => EventSales::money($low),
            'opens_at'   => $opens,
            'opens_text' => $opens !== null ? EventTime::zoned($e, $opens, 'D j M, H:i') : '',
            'when'       => EventTime::zoned($e, $start, 'D j M Y · H:i'),
            'time'       => EventTime::zoned($e, $start, 'H:i'),
            'date'       => EventTime::at($e, $start, 'j F Y'),
            'mon'        => EventTime::at($e, $start, 'M'),
            'day'        => EventTime::at($e, $start, 'j'),
            'iso'        => $start !== '' ? str_replace(' ', 'T', $start) . 'Z' : '',
            'days'       => $days,
            'image'      => EventTicketDesign::image(['cover_image' => $e['cover_image'] ?? '']),
            // What the default cover draws when there is no image (DEFAULT-GRAPHICS §4–§5):
            // the organiser's KIND, never their accent, and the event's own local day — the
            // ISO string above is UTC, and an evening event elsewhere would tile the wrong date.
            'cover_kind'   => CoverKind::eventKind($e['cover_kind'] ?? null, !empty($e['programme_id'])),
            'award_linked' => !empty($e['programme_id']),
            'cover_date'   => $start !== '' ? EventTime::at($e, $start, 'Y-m-d') : '',
            'livestream' => self::link((string) ($e['livestream_url'] ?? '')),
            'recording'  => self::link((string) ($e['recording_url'] ?? '')),
        ];
    }

    /** The chip on a row: one word, never colour alone. */
    private static function tag(string $state, array $e): string
    {
        return match ($state) {
            'soon'     => Translator::t('Coming soon'),
            'waitlist' => Translator::t('Waiting list'),
            'soldout'  => Translator::t('Sold out'),
            'closed'   => Translator::t('Closed'),
            'ended'    => self::link((string) ($e['recording_url'] ?? '')) !== ''
                            ? Translator::t('Recording') : Translator::t('Past'),
            default    => Translator::t('On sale'),
        };
    }

    /**
     * A stored URL, re-validated on the way OUT: http(s) only, parsed, never trusted because
     * it was checked when it was saved (CLAUDE.md, OrgBrand). '' when it is not a link.
     */
    public static function link(string $raw): string
    {
        $v = trim($raw);
        if ($v === '' || !preg_match('~^https?://~i', $v)) return '';
        $host = parse_url($v, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || filter_var($v, FILTER_VALIDATE_URL) === false) return '';
        return $v;
    }

    /**
     * Who hosts each event, where a linked programme names one. One query for a whole list.
     *
     * @param list<int|string> $ids
     * @return array<int,string>
     */
    public static function hosts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || !SchemaHas::table('gates_event_programmes')
            || !SchemaHas::column('gates_award_programmes', 'host_name')) return [];

        $out = [];
        try {
            $rows = DB::table('gates_event_programmes as ep')
                ->join('gates_award_programmes as p', 'p.id', '=', 'ep.programme_id')
                ->whereIn('ep.event_id', $ids)->where('p.is_active', 1)
                ->whereNotNull('p.host_name')->where('p.host_name', '!=', '')
                ->orderBy('ep.event_id')->orderBy('p.id')
                ->get(['ep.event_id', 'p.host_name']);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) {
            $out[(int) $r->event_id] ??= trim((string) $r->host_name);
        }
        return $out;
    }

    /**
     * What a ceremony's awards have to say on its page: a released edition ("See the
     * results") and a vote still open. Released is the STATUS (CLAUDE.md: a results date is
     * a promise, not an announcement); open voting is the COMPUTED phase.
     *
     * @return array{results:?string, voting:?array{url:string, detail:string}}
     */
    public static function awards(int $eventId): array
    {
        $out = ['results' => null, 'voting' => null];
        if (!SchemaHas::table('gates_event_programmes')) return $out;
        try {
            $cycles = DB::table('gates_event_programmes as ep')
                ->join('gates_award_programmes as p', 'p.id', '=', 'ep.programme_id')
                ->join('gates_award_cycles as c', 'c.programme_id', '=', 'p.id')
                ->where('ep.event_id', $eventId)->where('p.is_active', 1)
                ->orderByDesc('c.year')->orderByDesc('c.id')
                ->get(['c.*', 'p.slug as programme_slug']);
        } catch (\Throwable) {
            return $out;
        }
        foreach ($cycles as $c) {
            if ($out['results'] === null && in_array((string) $c->status, ['results', 'archived'], true)) {
                $out['results'] = PublicResults::editionUrl((string) $c->programme_slug, (int) $c->year);
            }
            if ($out['voting'] === null) {
                $st = CyclePolicy::stateFor($c);
                if (($st['phase'] ?? '') === 'voting') {
                    $out['voting'] = ['url' => '/vote', 'detail' => (string) ($st['detail'] ?? '')];
                }
            }
        }
        return $out;
    }
}
