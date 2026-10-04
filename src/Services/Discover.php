<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Like;
use AfricaGates\Support\NationsLive;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\ProgrammeHost;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * DISCOVER — what `/discover` shows, resolved once (Phase 4, design/DiscoverPage.dc.html).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * A PAGE THAT COMPOSES, NOT A SEARCH OF ITS OWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every row on the page comes from a reader that already existed and is already the one
 * answer to its question, so Discover and the rest of the site cannot disagree:
 *
 *  · the timeline ("Happening now", the Live tab) — {@see ActivityFeedService::timeline()},
 *    the index the search palette reads; `/activity` was that index's page and is a 301
 *    to the Live tab now;
 *  · "Open for nominations" — {@see SearchLanding::currentCycles()}, the palette's "Open
 *    now", with the phase COMPUTED from the cycle's windows ({@see CyclePolicy});
 *  · "Just decided" — {@see PublicResults::index()}, the announced results and only those;
 *  · "Upcoming ceremonies" — {@see SearchLanding::upcomingEvents()};
 *  · "Award hosts" — {@see ProgrammeHost}, the one resolver for who runs an award;
 *  · "Where" — {@see NationsLive::codes()}, the nations the footer says the platform is live
 *    in, so a filter cannot offer a country the footer does not count.
 *
 * The only query written here is "Most nominated this month", because nothing asked it
 * before; it walks the category chain to an ACTIVE programme like every public reader, so
 * the sandbox (an inactive programme, DemoSeeder) cannot reach it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THE DESIGN DRAWS AND THIS PLATFORM CANNOT SAY — NOT DRAWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The DC's facets include "Identity verified — checked against a government ID", "Vouched
 * for — by 3 or more verified people" and a "Field" taxonomy. None has a source here:
 * `verification_tier` is a tier, not a record of a government-ID check; nothing records a
 * vouch; awards and categories are free text with no field. A filter that matches nothing,
 * or matches on a guess, is the invented-statistic fault with a switch on it — so they are
 * absent and asked of the owner (docs/handoff/PHASE-4.md, "Discover", Blocked). Likewise the
 * Live tab's "Recognitions" chip: there are no recognitions on this platform yet (GAPS
 * §3.1), and the chip says exactly that rather than listing something else under the word.
 */
final class Discover
{
    /** Tab key => label, in the DC's order (Live second, after All — §8.23). */
    public const TABS = [
        'all'     => 'All',
        'live'    => 'Live',
        'people'  => 'People',
        'orgs'    => 'Organisations',
        'awards'  => 'Awards',
        'results' => 'Results',
        'events'  => 'Events',
    ];

    /**
     * The Live tab's kind chips: label, the timeline sources each asks, the phases of the
     * transitions ledger it takes (null = any), and the dot's token.
     *
     * `recognition` has no source — `null` — and is drawn so the page can SAY so.
     */
    public const KINDS = [
        'all'         => ['label' => 'Everything',   'sources' => [],                    'phases' => null,            'dot' => 'ink'],
        'result'      => ['label' => 'Results',      'sources' => ['result', 'phase'],   'phases' => ['results'],     'dot' => 'gold'],
        'nomination'  => ['label' => 'Nominations',  'sources' => ['nominee', 'phase'],  'phases' => ['nominations'], 'dot' => 'green'],
        'vote'        => ['label' => 'Voting',       'sources' => ['phase'],             'phases' => ['voting'],      'dot' => 'live'],
        'event'       => ['label' => 'Events',       'sources' => ['event'],             'phases' => null,            'dot' => 'info'],
        'story'       => ['label' => 'Stories',      'sources' => ['post', 'thread'],    'phases' => null,            'dot' => 'ink'],
        'recognition' => ['label' => 'Recognitions', 'sources' => null,                  'phases' => null,            'dot' => 'green-deep'],
    ];

    /**
     * What one timeline row is called, and its dot. The DC's own words (Result, Nomination,
     * Voting, Event, Story, Edition); `joined` is the one source the DC has no row for — a
     * registry profile — named in the words the activity page always used.
     */
    private const ROW = [
        'result'     => ['Result', 'gold'],
        'nomination' => ['Nomination', 'green'],
        'vote'       => ['Voting', 'live'],
        'event'      => ['Event', 'info'],
        'story'      => ['Story', 'ink'],
        'edition'    => ['Edition', 'gold-ink'],
        'joined'     => ['Joined', 'ink-2'],
    ];

    /** "Status" — which phase the Awards section lists. Default: open for nominations. */
    public const STATUS = [
        'open'    => 'Open for nominations',
        'voting'  => 'Voting now',
        'decided' => 'Decided',
    ];

    /** "Trust" — only the two with a record behind them (see the class docblock). */
    public const TRUST = [
        'won' => ['Recognised before', 'Has won or been shortlisted'],
        'ev'  => ['Reviewed evidence', 'At least one item reviewed by our team'],
    ];

    /** How many rows each section draws (the DC's own slices). */
    private const SHOW = ['awards' => 4, 'results' => 3, 'people' => 4, 'orgs' => 4, 'events' => 3];

    public function __construct(private readonly ActivityFeedService $feed = new ActivityFeedService()) {}

    /**
     * The request, read once and whitelisted. Everything arrives in a query string a
     * stranger can type, so an unknown value is DROPPED rather than refused: a filter
     * nobody can see narrowing a page to nothing reads as the page being broken.
     *
     * @param array<string,mixed> $p
     * @return array{tab:string,q:string,literal:bool,kind:string,page:int,where:string,
     *               status:string,trust:list<string>}
     */
    public static function state(array $p): array
    {
        $str = static fn (string $k): string => is_string($p[$k] ?? null) ? trim($p[$k]) : '';

        $tab    = array_key_exists($str('tab'), self::TABS) ? $str('tab') : 'all';
        $kind   = array_key_exists($str('kind'), self::KINDS) ? $str('kind') : 'all';
        $status = array_key_exists($str('status'), self::STATUS) ? $str('status') : '';
        $where  = strtoupper($str('where'));
        if ($where !== '' && !in_array($where, NationsLive::codes(), true)) $where = '';

        $trust = [];
        foreach ((array) ($p['trust'] ?? []) as $t) {
            if (is_string($t) && array_key_exists($t, self::TRUST) && !in_array($t, $trust, true)) $trust[] = $t;
        }

        return [
            'tab'     => $tab,
            'q'       => mb_substr($str('q'), 0, 120),
            'literal' => ($p['literal'] ?? '') !== '' && ($p['literal'] ?? '') !== '0',
            'kind'    => $kind,
            'page'    => max(1, min(ActivityFeedService::TIMELINE_DEPTH, (int) ($p['page'] ?? 1))),
            'where'   => $where,
            'status'  => $status,
            'trust'   => $trust,
        ];
    }

    /**
     * A Discover URL for this state with some keys changed. The one builder, so a chip,
     * a tab, a "remove filter" link and "Show older updates" cannot disagree about which
     * parameters survive a click — "filters persist on Back" (skill §5) is this function.
     *
     * @param array<string,mixed> $s
     * @param array<string,mixed> $with
     */
    public static function url(array $s, array $with = []): string
    {
        $s = array_merge($s, $with);
        $q = [];
        if (($s['tab'] ?? 'all') !== 'all') $q['tab'] = $s['tab'];
        if (($s['q'] ?? '') !== '')         $q['q'] = $s['q'];
        if (!empty($s['literal']) && ($s['q'] ?? '') !== '') $q['literal'] = '1';
        if (($s['kind'] ?? 'all') !== 'all') $q['kind'] = $s['kind'];
        if (($s['where'] ?? '') !== '')     $q['where'] = $s['where'];
        if (($s['status'] ?? '') !== '')    $q['status'] = $s['status'];
        if (!empty($s['trust']))            $q['trust'] = array_values($s['trust']);
        if ((int) ($s['page'] ?? 1) > 1)    $q['page'] = (int) $s['page'];

        $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        // `trust[0]=won` → `trust[]=won`: the indices carry nothing and make a URL
        // somebody shares longer and odder than it has to be.
        $qs = (string) preg_replace('/%5B\d+%5D=/', '%5B%5D=', $qs);

        return '/discover' . ($qs !== '' ? '?' . $qs : '');
    }

    /**
     * Everything the page draws.
     *
     * @param array<string,mixed> $s a {@see state()}
     * @return array<string,mixed>
     */
    public function page(array $s): array
    {
        $live     = $this->live($s);
        $sections = [
            'awards'  => $this->awards($s),
            'results' => $this->decided($s),
            'people'  => $this->people($s),
            'orgs'    => $this->hosts($s),
            'events'  => $this->events($s),
        ];

        // THE COUNT IS PER TAB, AND IT IS A COUNT OF WHAT THE TAB LISTS. All adds every
        // section's matched total; a section tab is that section; Live is the updates on
        // this page. Never a guess at "how much is on the platform".
        $counts = ['all' => array_sum(array_column($sections, 'total')), 'live' => count($live['rows'])];
        foreach ($sections as $k => $sec) $counts[$k] = $sec['total'];

        return [
            'live'     => $live,
            'sections' => $sections,
            'counts'   => $counts,
            'facets'   => $this->facets($s),
        ];
    }

    /** The count a tab would list under this state — for the facet panel's live button. */
    public function count(array $s): int
    {
        return $this->page($s)['counts'][$s['tab']] ?? 0;
    }

    // ── The timeline ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function live(array $s): array
    {
        $kind = self::KINDS[$s['kind']];
        // On the All tab the timeline is the compact "Happening now": the four newest of
        // EVERYTHING, whatever chip the Live tab was left on, because no chip is drawn there.
        $compact = $s['tab'] !== 'live';
        if ($compact) $kind = self::KINDS['all'];

        if ($kind['sources'] === null) {
            return ['rows' => [], 'more' => false, 'deepest' => false, 'page' => 1, 'sources' => 0,
                    'asked' => 0, 'understood' => null, 'unsourced' => true, 'interpreted' => false];
        }

        $t = $this->feed->timeline(
            $s['q'], !$s['literal'], $kind['sources'], $kind['phases'],
            $compact ? 1 : $s['page'],
            $compact ? 4 : ActivityFeedService::TIMELINE_PER,
        );

        return [
            'rows'        => array_map([self::class, 'row'], $t['items']),
            'more'        => !$compact && $t['more'],
            'deepest'     => !$compact && $t['deepest'],
            'page'        => $t['page'],
            'sources'     => $t['sources'],
            'asked'       => $t['asked'],
            'understood'  => $t['understood'] === null ? null : self::understood($t['understood']),
            'unsourced'   => false,
        ];
    }

    /**
     * One timeline entry as the DC draws it: kind dot + label · `<time datetime>` · title ·
     * detail. The item's own label (Winner, "Voting opened", Upcoming event) is kept in the
     * detail line, because it is the most specific thing the index knows about the row.
     *
     * @param array<string,mixed> $i an {@see ActivityFeedService} item
     * @return array<string,string>
     */
    public static function row(array $i): array
    {
        $k = match ((string) $i['kind']) {
            'result'         => 'result',
            'nominee'        => 'nomination',
            'event'          => 'event',
            'post', 'thread' => 'story',
            'profile'        => 'joined',
            'phase'          => match ((string) ($i['phase'] ?? '')) {
                'results'     => 'result',
                'nominations' => 'nomination',
                'voting'      => 'vote',
                default       => 'edition',
            },
            default          => 'edition',
        };

        $detail = (string) ($i['detail'] ?? '');
        if (in_array($i['kind'], ['result', 'phase', 'event'], true)) {
            $detail = trim(Translator::t((string) $i['label']) . ($detail !== '' ? ' · ' . $detail : ''));
        }

        return [
            'kind'   => $k,
            'label'  => Translator::t(self::ROW[$k][0]),
            'dot'    => self::ROW[$k][1],
            'title'  => (string) $i['title'],
            'detail' => $detail,
            'url'    => (string) $i['url'],
            'at'     => (string) $i['at'],
            'ago'    => (string) $i['at_label'],
        ];
    }

    /**
     * What a model read the query as, in the words the "Understood as" chips print.
     *
     * @param array<string,mixed> $u
     * @return list<string>
     */
    private static function understood(array $u): array
    {
        $chips = [];
        foreach ((array) ($u['kinds'] ?? []) as $k) {
            $chips[] = Translator::t(match ($k) {
                'result'  => 'Results',
                'nominee' => 'Nominations',
                'phase'   => 'Voting and phases',
                'event'   => 'Events',
                'post'    => 'Stories',
                'thread'  => 'Discussions',
                'profile' => 'People who joined',
                default   => 'Everything',
            });
        }
        if (!empty($u['country'])) $chips[] = NationsLive::name((string) $u['country']);
        if (!empty($u['days'])) {
            $d = (int) $u['days'];
            $chips[] = $d === 1 ? Translator::t('Today')
                : ($d === 7 ? Translator::t('Last 7 days') : Translator::t('Last %n% days', ['%n%' => (string) $d]));
        }

        return array_values(array_unique($chips));
    }

    // ── The sections ─────────────────────────────────────────────────────────────

    /** Case-insensitive "does this text contain the query", for the small in-PHP sets. */
    private static function has(string $q, string ...$hay): bool
    {
        if ($q === '') return true;
        foreach ($hay as $h) if (mb_stripos($h, $q) !== false) return true;
        return false;
    }

    /** "Open for nominations" — or, under the Status facet, "Voting now" / "Decided". */
    private function awards(array $s): array
    {
        $status = $s['status'] === '' ? 'open' : $s['status'];
        $now    = Carbon::now();
        $rows   = [];

        foreach (SearchLanding::currentCycles($now) as $c) {
            $phase = $c['phase'];
            $ok = match ($status) {
                'voting'  => $phase->isVotingOpen(),
                // DECIDED IS THE ANNOUNCEMENT, never the date (PublicResults::RELEASED):
                // a results date that has passed is a promise, and listing that award as
                // decided would announce it.
                'decided' => in_array($c['status'], PublicResults::RELEASED, true),
                default   => $phase->isNominationsOpen(),
            };
            if (!$ok) continue;

            $host = ProgrammeHost::forProgramme($c['programme_id']);
            if (!self::has($s['q'], $c['title'], (string) ($host['name'] ?? ''))) continue;

            $close = match ($status) {
                'voting'  => $c['voting_close'],
                'decided' => '',
                default   => $c['nominations_close'],
            };
            $days = null;
            if ($close !== '') {
                try {
                    $days = (int) ceil(max(0, Carbon::parse($close)->getTimestamp() - $now->getTimestamp()) / 86400);
                } catch (\Throwable) {
                    $days = null;
                }
            }

            $rows[] = [
                'title' => $c['title'],
                'line'  => trim(($host['name'] ?? 'Africa GATES') . ' · ' . $c['edition'], ' ·'),
                'cover' => $c['cover'],
                'url'   => '/awards/' . $c['slug'],
                'days'  => $days,
                // §6.1: urgency is `live-ink` WORDS, and only inside five days (the DC's own
                // threshold); the sentence says it, the colour only repeats it.
                'soon'  => $days !== null && $days <= 5,
            ];
        }

        return ['status' => $status, 'heading' => Translator::t(self::STATUS[$status]),
                'items' => array_slice($rows, 0, self::SHOW['awards']), 'total' => count($rows)];
    }

    /** "Just decided": winners of the newest announced awards. */
    private function decided(array $s): array
    {
        $all = $this->cached('discover:decided', 300, static function (): array {
            $out = [];
            foreach (PublicResults::index(3)['items'] as $c) {
                $w = $c['winner'] ?? null;
                if (!is_array($w) || ($w['name'] ?? '') === '') continue;
                $out[] = [
                    'name'  => (string) $w['name'],
                    'photo' => (string) ($w['photo'] ?? ''),
                    'what'  => trim((string) ($c['category']->title ?? '') . ' · '
                        . trim((string) $c['programme'] . ', ' . (string) $c['edition'], ', '), ' ·'),
                    'url'   => (string) $c['url'],
                ];
            }
            return $out;
        });

        $rows = array_values(array_filter($all, fn (array $r): bool => self::has($s['q'], $r['name'], $r['what'])));
        foreach ($rows as &$r) $r['initials'] = self::initials($r['name']);
        unset($r);

        return ['items' => array_slice($rows, 0, self::SHOW['results']), 'total' => count($rows)];
    }

    /**
     * "Most nominated this month": people entered into a live award since the first of
     * this month, ranked by how many categories they were entered into.
     *
     * A PERSON is a registry profile where the nominee is linked to one, otherwise the
     * nominee row — never a name, because two people share names and folding them is the
     * merge flow's job (with a human confirming), not a public list's. The COUNT is
     * categories, because that is what is recorded: a nomination is not linked to the
     * nominee it was attached to (no `nominee_id` on gates_nominations), so "212
     * nominations" — the DC's figure — would have to be reconstructed from names.
     */
    private function people(array $s): array
    {
        $since = Carbon::now()->startOfMonth()->toDateTimeString();
        try {
            $b = DemoSeeder::notSandbox(
                DB::table('gates_nominees as n')
                    ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                    ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                    ->where('p.is_active', 1)
                    ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                    ->whereNull('n.merged_into')
                    ->where('n.nominated_at', '>=', $since),
                'cy.programme_id');

            if ($s['q'] !== '') {
                $like = Like::contains($s['q']);
                $b->where(function ($w) use ($like) {
                    $w->whereRaw(Like::clause('n.name'), [$like])
                      ->orWhereRaw(Like::clause('n.organisation'), [$like])
                      ->orWhereRaw(Like::clause('c.title'), [$like]);
                });
            }
            if ($s['where'] !== '') $b->where('n.country_code', $s['where']);
            if (in_array('won', $s['trust'], true)) self::recognised($b);
            if (in_array('ev', $s['trust'], true)) self::reviewed($b);

            $rows = $b->orderByDesc('n.nominated_at')->orderByDesc('n.id')->limit(2000)
                ->get(['n.id', 'n.name', 'n.profile_id', 'n.country_code', 'n.organisation',
                       'n.photo_path', 'c.title as category'])->all();
        } catch (\Throwable) {
            $rows = [];
        }

        $people = [];
        foreach ($rows as $r) {
            $key = $r->profile_id ? 'p' . $r->profile_id : 'n' . $r->id;
            if (!isset($people[$key])) {
                $people[$key] = ['row' => $r, 'n' => 0, 'order' => count($people)];
            }
            $people[$key]['n']++;
        }
        // Most categories first; among equals, whoever was entered most recently.
        uasort($people, static fn (array $a, array $b): int => [$b['n'], $a['order']] <=> [$a['n'], $b['order']]);

        $out = [];
        foreach ($people as $p) {
            $r = $p['row'];
            $place = trim((string) ($r->organisation ?? '')) !== '' ? (string) $r->organisation : (string) $r->category;
            $nation = (string) ($r->country_code ?? '') !== '' ? NationsLive::name((string) $r->country_code) : '';
            $out[] = [
                'name'     => (string) $r->name,
                'initials' => self::initials((string) $r->name),
                'photo'    => (string) ($r->photo_path ?? ''),
                'meta'     => implode(' · ', array_filter([
                    $place, $nation,
                    $p['n'] === 1 ? Translator::t('nominated in 1 category')
                        : Translator::t('nominated in %n% categories', ['%n%' => (string) $p['n']]),
                ], static fn (string $v): bool => $v !== '')),
                'url'      => NomineeUrl::path((int) $r->id),
            ];
        }

        return ['items' => array_slice($out, 0, self::SHOW['people']), 'total' => count($out)];
    }

    /** Trust · "Recognised before": won, runner-up in an ANNOUNCED award, or on a published shortlist. */
    private static function recognised(object $b): void
    {
        $b->where(function ($w) {
            $w->whereExists(function ($x) {
                $x->select(DB::raw(1))->from('gates_nominees as n2')
                  ->join('gates_award_categories as c2', 'c2.id', '=', 'n2.category_id')
                  ->join('gates_award_cycles as y2', 'y2.id', '=', 'c2.cycle_id')
                  ->whereIn('y2.status', PublicResults::RELEASED)
                  ->whereIn('n2.status', ['winner', 'runner_up'])
                  ->where(function ($m) {
                      $m->whereColumn('n2.id', 'n.id')
                        ->orWhere(fn ($o) => $o->whereNotNull('n.profile_id')->whereColumn('n2.profile_id', 'n.profile_id'));
                  });
            });
            if (SchemaHas::table('gates_shortlist_entries') && SchemaHas::table('gates_shortlists')) {
                $w->orWhereExists(function ($x) {
                    $x->select(DB::raw(1))->from('gates_shortlist_entries as e')
                      ->join('gates_shortlists as sl', 'sl.id', '=', 'e.shortlist_id')
                      ->where('sl.status', 'published')
                      ->whereColumn('e.nominee_id', 'n.id');
                });
            }
        });
    }

    /** Trust · "Reviewed evidence": at least one dossier item a person on the team verified. */
    private static function reviewed(object $b): void
    {
        if (!SchemaHas::table('gates_nominee_evidence')) {
            // No dossier on this install means nobody's evidence was reviewed: the honest
            // answer to the filter is nobody, not everybody.
            $b->whereRaw('1 = 0');
            return;
        }
        $b->whereExists(function ($x) {
            $x->select(DB::raw(1))->from('gates_nominee_evidence as ev')
              ->whereColumn('ev.nominee_id', 'n.id')
              ->whereNotNull('ev.verified_at');
        });
    }

    /** "Award hosts": who runs the live awards, through ProgrammeHost. */
    private function hosts(array $s): array
    {
        $all = $this->cached('discover:hosts', 300, static function (): array {
            try {
                $progs = DB::table('gates_award_programmes as p')->where('p.is_active', 1)
                    ->orderBy('p.sort_order')->orderBy('p.id')->get(['p.id', 'p.slug', 'p.title', 'p.scope'])->all();
                $editions = DB::table('gates_award_cycles as c')
                    ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                    ->where('p.is_active', 1)
                    ->groupBy('c.programme_id')
                    ->pluck(DB::raw('COUNT(*) as n'), 'c.programme_id')->all();
            } catch (\Throwable) {
                return [];
            }

            $hosts = [];
            foreach ($progs as $p) {
                $h    = ProgrammeHost::forProgramme((int) $p->id);
                $name = $h['name'] ?? 'Africa GATES';
                $hosts[$name] ??= ['name' => $name, 'awards' => 0, 'editions' => 0, 'scopes' => [],
                                   'titles' => [], 'url' => '/awards/' . $p->slug];
                $hosts[$name]['awards']++;
                $hosts[$name]['editions'] += (int) ($editions[$p->id] ?? 0);
                $hosts[$name]['scopes'][(string) ($p->scope ?? '')] = true;
                $hosts[$name]['titles'][] = (string) $p->title;
                // One award: its own page. More than one: the awards index, where all of them are.
                if ($hosts[$name]['awards'] > 1) $hosts[$name]['url'] = '/awards';
            }

            return array_values($hosts);
        });

        $out = [];
        foreach ($all as $h) {
            if (!self::has($s['q'], $h['name'], ...$h['titles'])) continue;
            $scopes = array_keys(array_filter($h['scopes']));
            $out[] = [
                'name'    => $h['name'],
                'initial' => mb_substr($h['name'], 0, 1),
                'meta'    => implode(' · ', array_filter([
                    $h['awards'] === 1 ? Translator::t('1 award') : Translator::t('%n% awards', ['%n%' => (string) $h['awards']]),
                    $h['editions'] === 1 ? Translator::t('1 edition') : Translator::t('%n% editions', ['%n%' => (string) $h['editions']]),
                    // A scope is said only when every award shares it; a mixed host
                    // would otherwise be described by whichever award came first.
                    count($scopes) === 1 && $scopes[0] !== '' ? Translator::t(ucfirst($scopes[0])) : '',
                ])),
                'url'     => $h['url'],
            ];
        }

        return ['items' => array_slice($out, 0, self::SHOW['orgs']), 'total' => count($out)];
    }

    /** "Upcoming ceremonies": published events still to come, soonest first. */
    private function events(array $s): array
    {
        $rows = [];
        foreach (SearchLanding::upcomingEvents(null, 60) as $e) {
            if (!self::has($s['q'], $e['title'], $e['location'])) continue;
            try { $t = Carbon::parse($e['at']); } catch (\Throwable) { continue; }
            $rows[] = [
                'title' => $e['title'],
                // Sentence case: §18.4 forbids the DC's capitalised month ("DEC").
                'mon'   => Translator::t($t->format('M')),
                'day'   => $t->format('d'),
                'iso'   => $t->format('Y-m-d'),
                'meta'  => $e['location'],
                'url'   => '/events/' . $e['slug'],
            ];
        }

        return ['items' => array_slice($rows, 0, self::SHOW['events']), 'total' => count($rows)];
    }

    // ── Facets ───────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function facets(array $s): array
    {
        $where = [['code' => '', 'label' => Translator::t('All of Africa'), 'on' => $s['where'] === '']];
        foreach (NationsLive::codes() as $cc) {
            $where[] = ['code' => $cc, 'label' => NationsLive::name($cc), 'on' => $s['where'] === $cc];
        }

        $trust = [];
        foreach (self::TRUST as $k => [$label, $help]) {
            $trust[] = ['key' => $k, 'label' => Translator::t($label), 'help' => Translator::t($help),
                        'on' => in_array($k, $s['trust'], true)];
        }

        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[] = ['key' => $k, 'label' => Translator::t($label), 'on' => $s['status'] === $k];
        }

        // Applied filters, each with the URL that removes it (works with no script).
        $applied = [];
        if ($s['where'] !== '') {
            $applied[] = ['label' => NationsLive::name($s['where']), 'url' => self::url($s, ['where' => '', 'page' => 1])];
        }
        foreach ($s['trust'] as $k) {
            $applied[] = ['label' => Translator::t(self::TRUST[$k][0]),
                          'url' => self::url($s, ['trust' => array_values(array_diff($s['trust'], [$k])), 'page' => 1])];
        }
        if ($s['status'] !== '') {
            $applied[] = ['label' => Translator::t(self::STATUS[$s['status']]), 'url' => self::url($s, ['status' => '', 'page' => 1])];
        }

        return ['where' => $where, 'trust' => $trust, 'status' => $status, 'applied' => $applied,
                'clear' => self::url($s, ['where' => '', 'trust' => [], 'status' => '', 'page' => 1])];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private static function initials(string $name): string
    {
        $out = '';
        foreach (array_slice(preg_split('/\s+/u', trim($name)) ?: [], 0, 2) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $out;
    }

    /** A briefly cached read that never takes the page down with the cache. */
    private function cached(string $key, int $ttl, callable $read): array
    {
        try {
            $v = (new CacheService())->remember($key, $ttl, $read, ['registry', 'leaderboard']);
            return is_array($v) ? $v : [];
        } catch (\Throwable) {
            return $read();
        }
    }
}
