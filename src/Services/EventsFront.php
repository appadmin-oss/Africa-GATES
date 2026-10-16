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
 * Everything the events index draws, read once (EVENTS-INDEX, handoff 5 Oct 2026; replaces
 * the 4 Oct featured-card-and-list).
 *
 * ── ONE READ, MANY VIEWS ─────────────────────────────────────────────────────
 *
 * The page is a spotlight, type tiles, carousel rows (this week, online, ceremonies, learn,
 * recordings) and — once anything is filtered — a single results grid. Every one of those is
 * a filter over the same two lists read here: upcoming (soonest first, plus anything live
 * now) and past (latest first). So a row and the grid "See all" opens can never disagree
 * about what is in them, and the calendar is read once whichever view it draws.
 *
 * ── PAST IS NEVER MIXED IN, AND COMING SOON IS A STATE (owner, 4 Oct 2026) ───
 *
 * No upcoming row ever holds a past event; recordings are their own row and `?f=past` is the
 * whole past. An announced event whose tickets are not on sale yet stays in date order among
 * the rest and carries "Coming soon" and the date sales open, from {@see EventSales}, the
 * resolver the detail page's card uses. `?f=soon` lists only those.
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
    /**
     * The quick filters (EVENTS-INDEX §3), then the four the 4 Oct index linked to. Those keep
     * working as a results grid with no chip of their own: a `?f=past` somebody bookmarked or
     * was sent still answers, and "coming soon" and "past" — the owner's two asks of 4 Oct —
     * stay one address away rather than gone.
     */
    public const QUICK  = ['all', 'week', 'weekend', 'online', 'free'];
    public const LEGACY = ['upcoming', 'soon', 'live', 'past'];

    /**
     * Browse by type (§4): six tiles, each a group of kinds, in the DC's order. The tile's
     * cover is the first kind's. A kind belongs to one tile at most; `webinar` is its own.
     */
    public const TYPES = [
        'ceremony'   => ['Award ceremonies',     ['ceremony', 'gala']],
        'learn'      => ['Workshops & training', ['workshop', 'training', 'conference']],
        'webinar'    => ['Webinars',             ['webinar']],
        'livestream' => ['Livestreams',          ['livestream']],
        'community'  => ['Community days',       ['community', 'fundraiser', 'sports']],
        'culture'    => ['Music & culture',      ['concert', 'exhibition']],
    ];

    /** The spotlight holds four (§2). `spotlight_rank` is 1–4; anything else is not featured. */
    public const SPOTLIGHT = 4;

    /** How many upcoming events the page reads. A calendar of more than this is a search. */
    private const MAX_UPCOMING = 60;
    private const MAX_PAST     = 24;

    /**
     * Everything the index draws, from one read of the calendar.
     *
     * `filtering` is true when any of search, chip, type or place is set (or a legacy `f`):
     * the page then draws ONE results grid instead of the type tiles, the rows and the
     * hosting band (§ "Any filter … switches 4–6 for a results grid").
     *
     * @param array<string,mixed> $q the query string
     * @return array<string,mixed>
     */
    public static function browse(array $q, ?string $now = null): array
    {
        $now = $now ?? Carbon::now()->toDateTimeString();
        $f   = strtolower(trim((string) ($q['f'] ?? 'all')));
        if (!in_array($f, [...self::QUICK, ...self::LEGACY], true)) $f = 'all';
        if ($f === 'live') $f = 'online';                       // the old chip's word for it
        $type  = strtolower(trim((string) ($q['type'] ?? '')));
        if (!isset(self::TYPES[$type])) $type = '';
        $place = trim((string) ($q['place'] ?? ''));
        $text  = mb_substr(trim((string) ($q['q'] ?? '')), 0, 80);

        // Upcoming is still to start — or started, still running and being streamed, which
        // is the only way an event is "Live now". No end date, never live: a duration this
        // page invented would put a finished event on the page as happening.
        $up = self::liveOnly(DB::table('gates_site_events as e')->where('e.status', 'published')
            ->where(static function ($w) use ($now): void {
                $w->where('e.event_date', '>=', $now)
                  ->orWhere(static fn ($l) => $l->where('e.event_date', '<', $now)->where('e.end_date', '>', $now)
                      ->whereNotNull('e.livestream_url')->where('e.livestream_url', '!=', ''));
            }))
            ->orderBy('e.event_date')->orderBy('e.id')->limit(self::MAX_UPCOMING)
            ->get(['e.*'])->map(fn ($r) => (array) $r)->all();
        $past = self::liveOnly(DB::table('gates_site_events as e')->where('e.status', 'published')
            ->where('e.event_date', '<', $now)
            ->where(static fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '<=', $now)))
            ->orderByDesc('e.event_date')->orderByDesc('e.id')->limit(self::MAX_PAST)
            ->get(['e.*'])->map(fn ($r) => (array) $r)->all();

        $ids    = array_merge(array_column($up, 'id'), array_column($past, 'id'));
        $hosts  = self::hosts($ids);
        $linked = self::linked($ids);
        $rank   = [];
        $mk = function (array $e) use ($hosts, $linked, $now, &$rank): array {
            $r = (int) ($e['spotlight_rank'] ?? 0);
            if ($r >= 1 && $r <= self::SPOTLIGHT) $rank[(int) $e['id']] = $r;
            return self::card(self::row($e + ['award_linked' => isset($linked[(int) $e['id']])], $hosts, $now), $e, $now);
        };
        $up   = array_map($mk, $up);
        $past = array_map(fn (array $e) => self::card(self::row($e + ['award_linked' => isset($linked[(int) $e['id']])], $hosts, $now), $e, $now), $past);

        $filtering = $f !== 'all' || $type !== '' || $place !== '' || $text !== '';
        $out = [
            'f' => $f, 'type' => $type, 'place' => $place, 'q' => $text, 'filtering' => $filtering,
            'spotlight' => self::spotlight($up, $rank),
            'places'    => self::places($up),
        ];

        if ($filtering) {
            $pool = $f === 'past' ? $past : $up;
            $res  = array_values(array_filter($pool, static fn (array $c): bool =>
                self::matches($c, $f, $type, $place, $text)));
            return $out + ['results' => $res];
        }

        $inType = static fn (string $t): \Closure => static fn (array $c): bool => in_array($c['cover_kind'], self::TYPES[$t][1], true);
        $types = [];
        foreach (self::TYPES as $k => [$label, $kinds]) {
            $types[] = ['key' => $k, 'label' => Translator::t($label), 'kind' => $kinds[0],
                        'n' => count(array_filter($up, $inType($k)))];
        }
        $week = array_values(array_filter($up, static fn (array $c): bool => $c['in_week']));
        $rows = [
            ['id' => 'r-week',   'title' => Translator::t('This week'), 'sub' => Translator::t('Seven days, starting today'),
             'items' => $week, 'all' => '/events?f=week'],
            ['id' => 'r-online', 'title' => Translator::t('Online and livestreams'), 'sub' => Translator::t('Watch from anywhere'),
             'items' => array_values(array_filter($up, static fn (array $c): bool => $c['online'])), 'all' => '/events?f=online'],
            ['id' => 'r-cer',    'title' => Translator::t('Award ceremonies'), 'sub' => Translator::t('Where the winners are announced'),
             'items' => array_values(array_filter($up, $inType('ceremony'))), 'all' => '/events?type=ceremony'],
            ['id' => 'r-learn',  'title' => Translator::t('Learn something'), 'sub' => Translator::t('Workshops, training and talks'),
             'items' => array_values(array_filter($up, $inType('learn'))), 'all' => '/events?type=learn'],
        ];
        $rec = array_values(array_filter($past, static fn (array $c): bool => $c['recording'] !== ''));

        return $out + [
            'types' => $types,
            // A row with no items is not rendered (§5).
            'rows'  => array_values(array_filter($rows, static fn (array $r): bool => $r['items'] !== [])),
            'recordings' => $rec,
            'past_n'     => count($past),
        ];
    }

    /**
     * The spotlight's slides: the events an operator ranked 1–4, in rank order.
     *
     * With none ranked it falls back to the next upcoming events — the page this replaced
     * always led with the next one, and an index whose first block is empty because nobody
     * has set a rank yet reads as a calendar with nothing on it.
     *
     * @param list<array<string,mixed>> $up
     * @param array<int,int>            $rank event id → rank
     * @return list<array<string,mixed>>
     */
    private static function spotlight(array $up, array $rank): array
    {
        $ranked = array_values(array_filter($up, static fn (array $c): bool => isset($rank[$c['id']])));
        usort($ranked, static fn (array $a, array $b): int => [$rank[$a['id']], $a['start']] <=> [$rank[$b['id']], $b['start']]);
        $pick = $ranked !== [] ? $ranked : $up;
        $pick = array_slice($pick, 0, self::SPOTLIGHT);
        $ids  = array_column($pick, 'id');
        $going = [];
        foreach ($ids as $id) $going[$id] = EventTicketService::attendingForEvent((int) $id);

        return array_map(static fn (array $c): array => $c + ['going' => $going[$c['id']] ?? 0], $pick);
    }

    /**
     * The Place select: where the upcoming events actually are, busiest first, and "Online
     * only" when something is streamed. Never a list of cities nothing is happening in.
     *
     * @param list<array<string,mixed>> $up
     * @return list<array{value:string,label:string}>
     */
    private static function places(array $up): array
    {
        $n = [];
        foreach ($up as $c) if ($c['city'] !== '') $n[$c['city']] = ($n[$c['city']] ?? 0) + 1;
        uksort($n, static fn ($a, $b) => [$n[$b], $a] <=> [$n[$a], $b]);
        $out = [];
        foreach (array_slice(array_keys($n), 0, 12) as $city) {
            $out[] = ['value' => (string) $city, 'label' => Translator::t('Near %city%', ['%city%' => (string) $city])];
        }
        if (array_filter($up, static fn (array $c): bool => $c['online'])) {
            $out[] = ['value' => 'online', 'label' => Translator::t('Online only')];
        }

        return $out;
    }

    /** One card against the page's filters. */
    private static function matches(array $c, string $f, string $type, string $place, string $text): bool
    {
        $ok = match ($f) {
            'week'    => $c['in_week'],
            'weekend' => $c['in_week'] && $c['weekend'],
            'online'  => $c['online'],
            'free'    => $c['from'] === 0,
            'soon'    => $c['state'] === 'soon',
            default   => true,          // all, upcoming, past (the pool already decided)
        };
        if (!$ok) return false;
        if ($type !== '' && !in_array($c['cover_kind'], self::TYPES[$type][1], true)) return false;
        if ($place !== '') {
            if (strtolower($place) === 'online' ? !$c['online'] : mb_strtolower($c['city']) !== mb_strtolower($place)) return false;
        }
        if ($text !== '') {
            $hay = mb_strtolower($c['title'] . ' ' . $c['where'] . ' ' . $c['city'] . ' ' . $c['host']);
            if (!str_contains($hay, mb_strtolower($text))) return false;
        }

        return true;
    }

    /**
     * What a CARD needs beyond the row: the facts the filters ask, the status chip (one at
     * most, §Cards), the relative-time words and the full date the spotlight prints.
     *
     * @param array<string,mixed> $c a row()
     * @param array<string,mixed> $e the event row
     * @return array<string,mixed>
     */
    public static function card(array $c, array $e, string $now): array
    {
        $start  = (string) ($e['event_date'] ?? '');
        $end    = (string) ($e['end_date'] ?? '');
        $live   = $c['livestream'] !== '' && $start !== '' && $start < $now && $end !== '' && $end > $now;
        $days   = $c['days'];
        $wday   = $start !== '' ? (int) EventTime::at($e, $start, 'w') : -1;
        $cap    = ($e['capacity'] ?? null) !== null ? (int) $e['capacity'] : null;
        $left   = $cap !== null && $c['state'] === 'open' ? max(0, $cap - EventTicketService::soldForEvent($c['id'])) : null;
        $ebText = trim((string) ($e['early_bird_text'] ?? ''));
        $ebEnd  = trim((string) ($e['early_bird_deadline'] ?? ''));

        // One chip at most, the most useful first. Coming soon and Sold out are the 4 Oct
        // states; the other three are the handoff's. "Selling fast" has no definition this
        // platform could compute honestly, so it is not drawn (docs/handoff/PHASE-EVENTS-INDEX.md).
        [$status, $tone] = match (true) {
            $c['state'] === 'soon'     => [Translator::t('Coming soon'), 'gold'],
            $c['state'] === 'waitlist' => [Translator::t('Waiting list'), 'stone'],
            $c['state'] === 'soldout'  => [Translator::t('Sold out'), 'stone'],
            $left !== null && $left > 0 && $left <= EventSales::LOW_PLACES
                                       => [Translator::t('%n% left', ['%n%' => (string) $left]), 'live'],
            $c['state'] === 'open' && $ebText !== '' && ($ebEnd === '' || $ebEnd > $now)
                                       => [Translator::t('Early bird'), 'gold'],
            default                    => ['', ''],
        };

        $price = match ($c['state']) {
            'ended'  => '',
            'soon'   => $c['opens_text'] !== '' ? Translator::t('On sale %when%', ['%when%' => $c['opens_text']]) : '',
            default  => $c['from'] === null ? '' : ($c['from'] === 0 ? Translator::t('Free')
                         : Translator::t('From %price%', ['%price%' => $c['from_text']])),
        };

        return $c + [
            'start'    => $start,
            'live'     => $live,
            'online'   => $c['livestream'] !== '',
            'in_week'  => $days !== null && $days >= 0 && $days <= 6,
            'weekend'  => $wday === 0 || $wday === 6,
            'relative' => $live ? Translator::t('Happening now') : match (true) {
                $days === null => '',
                $days <= 0     => Translator::t('Today'),
                $days === 1    => Translator::t('Tomorrow'),
                default        => Translator::t('In %n% days', ['%n%' => (string) $days]),
            },
            'full_date'  => $start !== '' ? EventTime::zoned($e, $start, 'l j F Y · H:i') : '',
            'short_date' => $start !== '' ? EventTime::at($e, $start, 'D j M') : '',
            'meta'       => implode(' · ', array_values(array_filter([$live ? Translator::t('Live') : '', $c['where'] ?: $c['city'], $c['time']]))),
            'price'      => $price,
            'status'     => $status,
            'status_tone'=> $tone,
            'left'       => $left,
            'tone'       => $live ? 'live' : CoverKind::resolve('event', $c['cover_kind'])['tone'],
            'kind_label' => $live ? Translator::t('Live now') : Translator::t(CoverKind::resolve('event', $c['cover_kind'])['label']),
            'recorded'   => $start !== '' ? EventTime::at($e, $start, 'j M Y') : '',
        ];
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
            // `award_linked` is the caller's, from linked(): the event table has no programme
            // column (one ceremony honours several awards), so a row cannot know it alone.
            'cover_kind'   => CoverKind::eventKind($e['cover_kind'] ?? null, !empty($e['award_linked'])),
            'award_linked' => !empty($e['award_linked']),
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
     * Which of these events are tied to an award — the fact a NULL `cover_kind` resolves on
     * (an award-linked event is a ceremony; DEFAULT-GRAPHICS §4). One query for a list.
     * Only live programmes count, as everywhere on this page.
     *
     * @param list<int|string> $ids
     * @return array<int,true>
     */
    public static function linked(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || !SchemaHas::table('gates_event_programmes')) return [];

        try {
            $rows = DB::table('gates_event_programmes as ep')
                ->join('gates_award_programmes as p', 'p.id', '=', 'ep.programme_id')
                ->whereIn('ep.event_id', $ids)->where('p.is_active', 1)
                ->distinct()->pluck('ep.event_id');
        } catch (\Throwable) {
            return [];
        }

        return array_fill_keys(array_map('intval', $rows->all()), true);
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
