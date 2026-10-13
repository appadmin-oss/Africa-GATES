<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\AfricaRegion;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\NationsLive;
use AfricaGates\Support\ProgrammeHost;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * `/legacy` — THE LEGACY VAULT: every edition, archived (Phase 6 · LegacyVault.dc.html · §8.18).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT AN "EDITION" IS HERE, AND WHERE EACH FACT COMES FROM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Two kinds of record are editions, and the vault is their union:
 *
 *  · an ARCHIVED NIGHT — a published `gates_legacy_events` row (read through
 *    {@see LegacyService}, its one reader). Its date, place, delegates, recap and gallery
 *    are what an operator recorded. Where it names the released cycle it commemorates
 *    (`cycle_id`, 2027_03_06_legacy_edition_link.php) its winners come from that cycle's
 *    SEAL; where it names none it shows no winners, rather than typed ones;
 *  · an ANNOUNCED EDITION with no archived night — a released cycle of a live programme.
 *    Everything about it is the platform's own record: the sealed result
 *    ({@see PublicResults::edition()}), the date it was announced, its categories.
 *
 * A released cycle that an archived night already names appears once, as that night.
 *
 * What is never drawn because nothing records it: a jury chair's quote (the DC's), a venue
 * the operator did not type, a "verified today at 06:00" the chain check does not store.
 * The sealed record states what IS stored — the seal's own fingerprint and when it was made.
 *
 * The sandbox stays out through the programme (`is_active = 1`), the door every public
 * reader of a cycle takes.
 */
final class LegacyVault
{
    /** Editions shown per year group before "Show all N from {year}" (desktop; phone CSS shows 4). */
    public const PER_GROUP = 6;

    /** Year chips: the newest N years by name, the rest under "Earlier". */
    public const YEAR_CHIPS = 4;

    /** @return list<array<string,mixed>> every edition, newest first */
    public static function editions(): array
    {
        $out    = [];
        $linked = [];

        foreach ((new LegacyService())->getAllPublished() as $e) {
            $cycle = self::cycle(isset($e['cycle_id']) ? (int) $e['cycle_id'] : 0);
            if ($cycle) $linked[(int) $cycle->id] = true;
            $date = (string) ($e['event_date'] ?? '');
            $cc   = strtoupper(trim((string) ($e['country_code'] ?? '')));
            $out[] = [
                'kind'      => 'night',
                'slug'      => (string) $e['slug'],
                'url'       => '/legacy/' . rawurlencode((string) $e['slug']),
                'award'     => $cycle ? (string) $cycle->programme : (string) $e['title'],
                'edition'   => $cycle ? EditionName::label($cycle) : '',
                'title'     => (string) $e['title'],
                'tagline'   => (string) ($e['tagline'] ?? ''),
                'date'      => $date,
                'year'      => (int) substr($date, 0, 4),
                'place'     => (string) ($e['location'] ?? ''),
                'countries' => $cc !== '' ? [$cc] : [],
                'regions'   => array_values(array_filter([AfricaRegion::of($cc)])),
                'cover'     => (string) ($e['cover_path'] ?? ''),
                'delegates' => (int) ($e['attendee_count'] ?? 0),
                'awards'    => (int) ($e['award_count'] ?? 0) ?: ($cycle ? self::awardsIn((int) $cycle->id) : 0),
                'cycle_id'  => $cycle ? (int) $cycle->id : null,
            ];
        }

        foreach (self::releasedCycles() as $c) {
            if (isset($linked[(int) $c->id])) continue;
            $ccs = self::countriesIn((int) $c->id);
            $out[] = [
                'kind'      => 'edition',
                'slug'      => PublicResults::editionSlug((string) $c->programme_slug, (int) $c->year),
                'url'       => '/legacy/' . PublicResults::editionSlug((string) $c->programme_slug, (int) $c->year),
                'award'     => (string) $c->programme,
                'edition'   => EditionName::label($c),
                'title'     => (string) $c->programme . ' · ' . EditionName::label($c),
                'tagline'   => (string) ($c->subtitle ?? ''),
                'date'      => (string) ($c->results_date ?? ($c->year . '-12-31')),
                'year'      => (int) $c->year,
                'place'     => '',
                'countries' => $ccs,
                'regions'   => array_values(array_unique(array_filter(array_map([AfricaRegion::class, 'of'], $ccs)))),
                'cover'     => (string) ($c->cover_path ?? ''),
                'delegates' => 0,
                'awards'    => self::awardsIn((int) $c->id),
                'cycle_id'  => (int) $c->id,
            ];
        }

        foreach ($out as $k => $e) $out[$k]['country_names'] = array_map([NationsLive::class, 'name'], $e['countries']);
        usort($out, static fn (array $a, array $b): int => [$b['date'], $b['slug']] <=> [$a['date'], $a['slug']]);
        return $out;
    }

    /**
     * The index page: metrics, year/region facets and the filtered editions grouped by year.
     *
     * @param array{q?:string,year?:string,region?:list<string>|string,sort?:string} $view
     */
    public static function index(array $view = []): array
    {
        $all = self::editions();
        $q = mb_substr(trim((string) ($view['q'] ?? '')), 0, 80);
        $year = trim((string) ($view['year'] ?? ''));
        $regions = $view['region'] ?? [];
        if (!is_array($regions)) $regions = [$regions];
        $regions = array_values(array_intersect(array_keys(AfricaRegion::LABELS), array_map('strval', $regions)));

        // Year facets with counts, newest first.
        $years = [];
        foreach ($all as $e) if ($e['year'] > 0) $years[$e['year']] = ($years[$e['year']] ?? 0) + 1;
        krsort($years);
        $named   = array_slice(array_keys($years), 0, self::YEAR_CHIPS);
        $earlier = array_sum(array_slice($years, self::YEAR_CHIPS, null, true));
        $yearOpts = [['key' => '', 'label' => 'All years', 'n' => count($all)]];
        foreach ($named as $y) $yearOpts[] = ['key' => (string) $y, 'label' => (string) $y, 'n' => $years[$y]];
        if ($earlier > 0) $yearOpts[] = ['key' => 'earlier', 'label' => 'Earlier', 'n' => $earlier];
        $oldestNamed = $named !== [] ? min($named) : 0;
        if ($year !== '' && $year !== 'earlier' && !isset($years[(int) $year])) $year = '';

        $match = static function (array $e) use ($q, $year, $regions, $oldestNamed): bool {
            if ($year === 'earlier' && $e['year'] >= $oldestNamed) return false;
            if ($year !== '' && $year !== 'earlier' && $e['year'] !== (int) $year) return false;
            if ($regions !== [] && array_intersect($regions, $e['regions']) === []) return false;
            if ($q !== '') {
                $hay = mb_strtolower($e['award'] . ' ' . $e['edition'] . ' ' . $e['title'] . ' ' . $e['place'] . ' '
                    . implode(' ', array_map([NationsLive::class, 'name'], $e['countries'])));
                if (!str_contains($hay, mb_strtolower($q))) return false;
            }
            return true;
        };
        $list = array_values(array_filter($all, $match));
        $sort = in_array((string) ($view['sort'] ?? ''), ['old', 'delegates'], true) ? (string) $view['sort'] : 'new';
        if ($sort === 'old') $list = array_reverse($list);
        if ($sort === 'delegates') usort($list, static fn (array $a, array $b): int => $b['delegates'] <=> $a['delegates']);
        $filtered = $q !== '' || $year !== '' || $regions !== [];

        $groups = [];
        foreach ($list as $e) $groups[$e['year']][] = $e;
        krsort($groups);
        $g = [];
        // Grouped by year, newest year first — oldest first when that is what was asked for.
        if ($sort === 'old') ksort($groups);
        foreach ($groups as $y => $items) {
            $g[] = ['year' => (int) $y, 'n' => count($items), 'items' => array_slice($items, 0, $year === (string) $y ? 500 : self::PER_GROUP)];
        }

        $countries = [];
        foreach ($all as $e) foreach ($e['countries'] as $cc) $countries[$cc] = true;

        return [
            'total'    => count($all),
            'shown'    => count($list),
            'filtered' => $filtered,
            'groups'   => $g,
            'years'    => $yearOpts,
            'regions'  => AfricaRegion::LABELS,
            'view'     => ['q' => $q, 'year' => $year, 'region' => $regions, 'sort' => $sort],
            'metrics'  => [
                'editions'  => count($all),
                'countries' => count($countries),
                'awards'    => array_sum(array_column($all, 'awards')),
                'delegates' => array_sum(array_column($all, 'delegates')),
            ],
        ];
    }

    /** One edition's page, or null for a slug that names nothing in the vault. */
    public static function edition(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') return null;
        $all = self::editions();
        $i = null;
        foreach ($all as $k => $e) if ($e['slug'] === $slug) { $i = $k; break; }
        if ($i === null) return null;
        $e = $all[$i];

        $night = $e['kind'] === 'night' ? (new LegacyService())->getBySlug($slug) : null;
        $cycle = $e['cycle_id'] ? self::cycle((int) $e['cycle_id']) : null;
        $result = $cycle ? PublicResults::edition(PublicResults::editionSlug((string) $cycle->programme_slug, (int) $cycle->year)) : null;

        $winners = [];
        foreach (($result['awards'] ?? []) as $a) {
            if (empty($a['winner'])) continue;
            $winners[] = [
                'id'    => (int) $a['winner']['nominee_id'],
                'cat'   => (string) ($a['category']->title ?? ''),
                'name'  => (string) $a['winner']['name'],
                'photo' => (string) ($a['winner']['photo'] ?? ''),
                'cpi'   => (int) $a['winner']['cpi'],
                'url'   => (string) ($a['url'] ?? ''),
            ];
        }
        $overall = null;
        if (!empty($result['overall']['winner'])) {
            $w = $result['overall']['winner'];
            $overall = ['id' => (int) ($w['nominee_id'] ?? 0), 'name' => (string) $w['name'], 'photo' => (string) ($w['photo'] ?? ''),
                        'category' => (string) ($w['category'] ?? ''), 'cpi' => (int) $w['cpi'],
                        'categories' => count($winners),
                        'reconstructed' => !empty($result['overall']['reconstructed'])];
        }

        $gallery = [];
        foreach ((array) ($night['gallery_paths'] ?? []) as $p) if (is_string($p) && trim($p) !== '') $gallery[] = trim($p);

        $place = $e['place'];
        $date  = $e['date'];
        $facts = array_values(array_filter([
            $place,
            $date !== '' ? date('j M Y', (int) strtotime($date)) : '',
            $e['delegates'] > 0 ? Translator::t('%n% delegates', ['%n%' => number_format($e['delegates'])]) : '',
            $e['awards'] > 0 ? ($e['awards'] === 1 ? Translator::t('1 award') : Translator::t('%n% awards', ['%n%' => (string) $e['awards']])) : '',
        ]));

        $host = $cycle ? ProgrammeHost::forCycle((int) $cycle->id) : null;
        $rail = array_values(array_filter([
            $host ? ['k' => 'Host', 'v' => $host['name']] : null,
            $place !== '' ? ['k' => 'Where', 'v' => $place] : null,
            $date !== '' ? ['k' => 'Date', 'v' => date('j M Y', (int) strtotime($date))] : null,
            $e['delegates'] > 0 ? ['k' => 'Delegates', 'v' => number_format($e['delegates'])] : null,
            $result ? ['k' => 'Categories', 'v' => (string) count($result['awards'])] : null,
            $result && (int) ($result['votes'] ?? 0) > 0 ? ['k' => 'Votes counted', 'v' => number_format((int) $result['votes'])] : null,
        ]));

        $timeline = $cycle ? self::timeline((int) $cycle->programme_id, (int) $cycle->id) : [];
        $earlier = $later = null;
        foreach ($timeline as $k => $t) {
            if (!$t['current']) continue;
            $later   = $timeline[$k - 1] ?? null;
            $earlier = $timeline[$k + 1] ?? null;
        }
        if (!$cycle) {
            // A night with no cycle: its neighbours are the vault's own chronology.
            $later   = isset($all[$i - 1]) ? ['label' => $all[$i - 1]['title'], 'note' => (string) $all[$i - 1]['year'], 'url' => $all[$i - 1]['url']] : null;
            $earlier = isset($all[$i + 1]) ? ['label' => $all[$i + 1]['title'], 'note' => (string) $all[$i + 1]['year'], 'url' => $all[$i + 1]['url']] : null;
        }

        return [
            'e'        => $e,
            'tagline'  => $e['tagline'] !== '' ? $e['tagline'] : (string) ($night['excerpt'] ?? ''),
            'recap'    => (string) ($night['full_content'] ?? ''),
            'facts'    => $facts,
            'overall'  => $overall,
            'winners'  => $winners,
            'gallery'  => $gallery,
            'seal'     => $cycle ? self::seal((int) $cycle->id) : null,
            'rail'     => $rail,
            'timeline' => $timeline,
            'earlier'  => $earlier,
            'later'    => $later,
            'result_url' => $result['url'] ?? null,
            'cite'     => self::cite($e),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** A released cycle of a live programme, or null. */
    private static function cycle(int $id): ?object
    {
        if ($id < 1) return null;
        try {
            return DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.id', $id)->where('p.is_active', 1)->whereIn('c.status', PublicResults::RELEASED)
                ->first(['c.*', 'p.title as programme', 'p.slug as programme_slug', 'p.subtitle', 'p.cover_path']) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<object> */
    private static function releasedCycles(): array
    {
        try {
            return DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)->whereIn('c.status', PublicResults::RELEASED)
                ->get(['c.*', 'p.title as programme', 'p.slug as programme_slug', 'p.subtitle', 'p.cover_path'])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Awards conferred = categories the seal decided (a winner sealed at rank one). */
    private static function awardsIn(int $cycleId): int
    {
        $seal = ReleasedStanding::forCycle($cycleId);
        if ($seal === null) return 0;
        try {
            $cats = DB::table('gates_nominees')->whereIn('id', array_keys(array_filter(
                $seal['rows'], static fn (array $r): bool => $r['rank'] === 1 && ($r['in_running'] ?? true)
            )))->distinct()->count('category_id');
        } catch (\Throwable) {
            return 0;
        }
        return (int) $cats;
    }

    /** @return list<string> the countries an edition's nominees came from */
    private static function countriesIn(int $cycleId): array
    {
        try {
            return array_values(array_unique(array_filter(array_map('strtoupper', DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)->whereNotNull('n.country_code')
                ->pluck('n.country_code')->all()))));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Every edition of one programme, newest first, the current one marked. */
    private static function timeline(int $programmeId, int $currentCycle): array
    {
        try {
            $rows = DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.programme_id', $programmeId)->where('p.is_active', 1)
                ->orderByDesc('c.year')->orderByDesc('c.id')
                ->get(['c.*', 'p.slug as programme_slug'])->all();
        } catch (\Throwable) {
            return [];
        }
        $vault = [];
        foreach (self::editions() as $e) if ($e['cycle_id']) $vault[$e['cycle_id']] = $e['url'];
        $out = [];
        foreach ($rows as $c) {
            $released = in_array((string) $c->status, PublicResults::RELEASED, true);
            $live = in_array((string) $c->status, ['voting', 'nominations', 'shortlisting', 'judging'], true);
            if (!$released && !$live) continue;
            $out[] = [
                'label'   => EditionName::label($c),
                'note'    => $released ? (string) $c->year
                           : ((string) $c->status === 'voting' ? Translator::t('Voting now')
                           : ((string) $c->status === 'nominations' ? Translator::t('Nominations open') : Translator::t('In progress'))),
                'url'     => $released ? ($vault[(int) $c->id] ?? PublicResults::editionUrl((string) $c->programme_slug, (int) $c->year))
                                       : PublicResults::editionUrl((string) $c->programme_slug, (int) $c->year),
                'current' => (int) $c->id === $currentCycle,
                'live'    => (string) $c->status === 'voting',
            ];
        }
        return $out;
    }

    /**
     * The seal's fingerprint and when it was made — the newest hash of the cycle's release
     * capture. A short, grouped form for reading aloud; the full hash is in the title.
     */
    private static function seal(int $cycleId): ?array
    {
        if (!SchemaHas::column('gates_vote_snapshots', 'capture_kind')) return null;
        try {
            $r = DB::table('gates_vote_snapshots')->where('cycle_id', $cycleId)
                ->where('capture_kind', SnapshotService::KIND_RELEASE)
                ->orderByDesc('id')->first(['hash', 'snapshot_at']);
        } catch (\Throwable) {
            return null;
        }
        if (!$r || !$r->hash) return null;
        $h = strtoupper(substr((string) $r->hash, 0, 8));
        return ['short' => substr($h, 0, 4) . '·' . substr($h, 4, 4), 'hash' => (string) $r->hash,
                'at' => (string) $r->snapshot_at];
    }

    /** One citation line, the vault's own record of where this edition is kept. */
    private static function cite(array $e): string
    {
        $base = \AfricaGates\Support\SiteUrl::base();
        return sprintf('%s%s (%s). Africa GATES Legacy Vault. %s%s',
            $e['award'], $e['edition'] !== '' ? ', ' . $e['edition'] : '',
            $e['year'] > 0 ? (string) $e['year'] : 'n.d.', $base, $e['url']);
    }
}
