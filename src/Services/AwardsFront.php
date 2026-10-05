<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\ProgrammeHost;
use AfricaGates\Support\Slug;
use AfricaGates\Support\Translator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * THE AWARDS PAGES' FACTS — `/awards` and `/awards/{slug}` (Phase 5, AwardsPage.dc.html).
 *
 * Plain arrays out, so the templates draw and decide nothing. Every fact is read from the
 * rule that owns it: the phase from `CyclePolicy` (computed, never the stored status), the
 * steps and the one action from `AwardOverview`, the scoring split from `RuleEngine` for
 * this programme and edition, the edition's name from `EditionName`, published winners
 * from `PublicResults` (released and laid over with the seal), the terms from
 * `AwardTerms`. Nothing here is a typed figure (CLAUDE.md, the 157-points-out screen).
 *
 * ── THE PHASE A CARD SAYS, AND THE ONE IT IS FILTERED UNDER ─────────────────
 *
 * The DC's chips are All · Nominations · Voting · Judging · Results · Coming soon. The
 * computed phase has two more: `shortlisting` (nominations closed, voting not yet open) is
 * filed under Nominations and SAYS "Shortlisting" — a card claiming "nominations open"
 * would be false; `archived` is a finished edition and is filed under Results. `upcoming`
 * is Coming soon, and its card opens the coming-soon view.
 *
 * ── THE SANDBOX ─────────────────────────────────────────────────────────────
 *
 * Only `is_active = 1` programmes are read, which is the containment the demo relies on
 * (it lives in an inactive programme — CLAUDE.md, "The sandbox must never reach the
 * public"). Every lookup here walks programme → cycle → category, never an id a stranger
 * typed.
 */
final class AwardsFront
{
    /** Chip key → [label, Accent token of its dot]. The word always travels with the dot. */
    public const PHASES = [
        'nominations' => ['Nominations', 'gold-ink'],
        'voting'      => ['Voting',      'live'],
        'judging'     => ['Judging',     'info'],
        'results'     => ['Results',     'green'],
        'soon'        => ['Coming soon', 'gold'],
    ];

    /** Computed phase → the chip it is filed under. */
    private const FILED = [
        'upcoming' => 'soon', 'nominations' => 'nominations', 'shortlisting' => 'nominations',
        'voting' => 'voting', 'judging' => 'judging', 'results' => 'results', 'archived' => 'results',
        'late' => 'judging',
    ];

    /** Computed phase → what the card's badge says. */
    private const BADGE = [
        'upcoming' => 'Coming soon', 'nominations' => 'Nominations open', 'shortlisting' => 'Shortlisting',
        'voting' => 'Voting open', 'judging' => 'Judging', 'results' => 'Results out', 'archived' => 'Results out',
        'late' => 'Results delayed',
    ];

    /**
     * The phase a READER is owed, which is not always the computed one. A results date that
     * has passed computes as `results`, but a results date is a promise, not an announcement
     * (CLAUDE.md): until `CycleMaterialiser` has written `results`, the award is late, and a
     * card saying "Results out" over a page that has none is the fault PublicResults::delayed()
     * exists to prevent.
     */
    public static function phaseKey(object $cycle): string
    {
        $phase = CyclePolicy::phaseFor($cycle)->value;
        if (in_array($phase, ['results', 'archived'], true)
            && !in_array((string) ($cycle->status ?? ''), ['results', 'archived'], true)) {
            return 'late';
        }
        return $phase;
    }

    /**
     * The index: every active award as a card, filtered by chip and query.
     *
     * @param array{ph?:string,q?:string} $view
     * @return array{cards:list<array<string,mixed>>, counts:array<string,int>, total:int, view:array{ph:string,q:string}}
     */
    public static function index(array $view = []): array
    {
        $ph = (string) ($view['ph'] ?? 'all');
        if ($ph !== 'all' && !isset(self::PHASES[$ph])) $ph = 'all';
        $q  = mb_substr(trim((string) ($view['q'] ?? '')), 0, 80);

        $all = [];
        foreach (DB::table('gates_award_programmes')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get() as $p) {
            $all[] = self::card($p);
        }

        $counts = array_fill_keys(array_keys(self::PHASES), 0);
        foreach ($all as $c) $counts[$c['filed']]++;

        $needle = mb_strtolower($q);
        $cards = array_values(array_filter($all, static fn (array $c): bool =>
            ($ph === 'all' || $c['filed'] === $ph)
            && ($needle === '' || str_contains(mb_strtolower($c['title'] . ' ' . $c['host'] . ' ' . $c['where']), $needle))));

        return ['cards' => $cards, 'counts' => $counts, 'total' => count($all), 'view' => ['ph' => $ph, 'q' => $q]];
    }

    /** @return array<string,mixed> */
    private static function card(object $p): array
    {
        $cycle = BallotGuard::currentCycleForProgramme((int) $p->id);
        $phase = $cycle ? self::phaseKey($cycle) : 'upcoming';
        $host  = ProgrammeHost::forProgramme((int) $p->id);
        $filed = self::FILED[$phase] ?? 'soon';

        return [
            'id'      => (int) $p->id,
            'slug'    => (string) $p->slug,
            'url'     => '/awards/' . rawurlencode((string) $p->slug),
            'title'   => (string) $p->title,
            'cover'   => trim((string) ($p->cover_path ?? '')),
            'tone'    => self::coverTone((int) $p->id),
            'host'    => (string) ($host['name'] ?? ''),
            'where'   => self::where($p),
            'edition' => $cycle ? EditionName::label($cycle) : '',
            'cats'    => $cycle ? (int) DB::table('gates_award_categories')->where('cycle_id', $cycle->id)->count() : 0,
            'phase'   => $phase,
            'filed'   => $filed,
            'badge'   => self::BADGE[$phase] ?? 'Coming soon',
            'dot'     => self::PHASES[$filed][1],
            'next'    => $cycle ? self::nextLine($cycle, $phase) : '',
        ];
    }

    /**
     * The generated cover for an award with no picture (GAPS §8 Q8: "awards use the
     * programme's identity family"): the programme's own fill as the ground, white words,
     * the green rule. Every value a `var()` of a palette token, so nothing typed reaches CSS.
     */
    public static function coverTone(int $programmeId): string
    {
        $f = \AfricaGates\Support\Accent::forProgramme($programmeId)['fill'];
        return '--ph-top:var(--ag-' . $f . ');--ph-bottom:var(--ag-' . $f . ')';
    }

    /** "Continental", "Regional", "National" — the scope the operator chose, said as a place. */
    public static function where(object $p): string
    {
        return match ((string) ($p->scope ?? 'continental')) {
            'national' => 'National',
            'regional' => 'Regional',
            default    => 'Continental',
        };
    }

    /** The one date a card states: when the thing now happening ends, or when the next starts. */
    public static function nextLine(object $c, string $phase): string
    {
        $d = static fn ($v, string $f = 'j M'): string => $v ? DisplayTime::show((string) $v, $f) : '';
        $t = static fn (string $s, string $date = ''): string => Translator::t($s, ['%date%' => $date]);
        return match ($phase) {
            'upcoming'     => ($o = $d($c->nominations_open ?? $c->voting_open ?? null, 'j M Y')) !== '' ? $t('Opens %date%', $o) : $t('Dates to come'),
            'nominations'  => ($o = $d($c->nominations_close ?? null)) !== '' ? $t('Closes %date%', $o) : $t('Open now'),
            'shortlisting' => ($o = $d($c->voting_open ?? null)) !== '' ? $t('Voting from %date%', $o) : $t('Voting next'),
            'voting'       => ($o = $d($c->voting_close ?? null)) !== '' ? $t('Closes %date%', $o) : $t('Open now'),
            'judging'      => ($o = $d($c->results_date ?? null)) !== '' ? $t('Results %date%', $o) : $t('Results to be announced'),
            'late'         => ($o = $d($c->results_date ?? null)) !== '' ? $t('Results were due %date%', $o) : $t('Results delayed'),
            'results', 'archived' => ($o = $d($c->results_date ?? null)) !== '' ? $t('Decided %date%', $o) : $t('Decided'),
            default        => '',
        };
    }

    /**
     * One award: the programme, the edition being shown, and everything the three tabs draw.
     *
     * @return array<string,mixed>|null null for an unknown or inactive programme
     */
    public static function detail(string $slug, ?int $year = null): ?array
    {
        $p = DB::table('gates_award_programmes')->where('slug', $slug)->where('is_active', 1)->first();
        if (!$p) return null;
        $pid = (int) $p->id;

        $current = BallotGuard::currentCycleForProgramme($pid);
        $cycles  = DB::table('gates_award_cycles')->where('programme_id', $pid)
            ->orderByDesc('year')->orderByDesc('id')->get()->all();
        $cycle = $current;
        if ($year !== null) {
            foreach ($cycles as $c) { if ((int) $c->year === $year) { $cycle = $c; break; } }
        }

        $phase   = $cycle ? CyclePolicy::stateFor($cycle) : null;
        $phaseK  = $cycle ? self::phaseKey($cycle) : 'upcoming';
        // A late edition is still with the panel as far as anybody may be told: its steps
        // and its one action are judging's, and the delay is stated beside them.
        if ($phaseK === 'late' && $phase !== null) {
            $phase['phase'] = 'judging';
            $phase['detail'] = '';
        }
        $host    = ProgrammeHost::forProgramme($pid);
        $rules   = new RuleEngine();
        $weights = $rules->weights($pid, $cycle ? (int) $cycle->id : null);
        $quorum  = (int) ($rules->effective($pid, $cycle ? (int) $cycle->id : null)['min_judges_per_nominee']
                          ?? RuleEngine::DEFAULTS['min_judges_per_nominee']);
        $span    = DB::table('gates_award_cycles')->where('programme_id', $pid)
            ->selectRaw('MIN(year) AS first_year, COUNT(*) AS editions')->first();
        $terms   = AwardTerms::current($pid);
        $released = $cycle && in_array($phaseK, ['results', 'archived'], true)
            ? PublicResults::edition(PublicResults::editionSlug((string) $p->slug, (int) $cycle->year)) : null;

        $editions = array_map(static fn (object $c): array => [
            'year'  => (int) $c->year,
            'label' => EditionName::full($c),
            'on'    => $cycle && (int) $c->id === (int) $cycle->id,
        ], $cycles);

        return [
            'id'         => $pid,
            'slug'       => (string) $p->slug,
            'title'      => (string) $p->title,
            'subtitle'   => trim((string) ($p->subtitle ?? '')),
            'description'=> trim((string) ($p->description ?? '')),
            'cover'      => trim((string) ($p->cover_path ?? '')),
            'tone'       => self::coverTone($pid),
            'host'       => $host,
            'where'      => self::where($p),
            'cycle'      => $cycle ? (array) $cycle : null,
            'edition'    => $cycle ? EditionName::label($cycle) : '',
            'edition_full'=> $cycle ? EditionName::full($cycle) : '',
            'is_current' => $cycle && $current && (int) $cycle->id === (int) $current->id,
            'editions'   => $editions,
            'phase'      => $phase,
            'phase_key'  => $phaseK,
            'soon'       => $phaseK === 'upcoming',
            'badge'      => self::BADGE[$phaseK] ?? '',
            'badge_family'=> self::PHASES[self::FILED[$phaseK] ?? 'soon'][1],
            'timeline'   => $cycle ? AwardOverview::timeline((array) $cycle, $phase) : [],
            'action'     => self::action((string) $p->slug, $phase, $released),
            'facts'      => AwardOverview::facts($host, isset($span->first_year) ? (int) $span->first_year : null,
                                                 (int) ($span->editions ?? 0), $weights),
            'weights'    => ['community' => (int) round(($weights['community'] ?? 0) * 100),
                             'judge'     => (int) round(($weights['judge'] ?? 0) * 100)],
            'quorum'     => $quorum,
            'paid'       => PaidVoteService::enabled()
                ? PaidVoteCopy::sentence($pid, $cycle ? (int) $cycle->id : null) : '',
            'categories' => $cycle ? self::categories($cycle, $phaseK, $released) : [],
            'decided'    => $released !== null && ($released['awards'] ?? []) !== [],
            'results_url'=> $released['url'] ?? '',
            'sponsors'   => ProgrammeSponsor::forCycle($pid, $cycle ? (int) $cycle->id : null),
            'terms'      => $terms,
            'terms_log'  => $terms ? AwardTerms::history($pid) : [],
            'plan'       => $cycle ? self::plan($cycle) : [],
            'opens_at'   => ($o = $cycle ? self::opensAt($cycle) : null),
            // Seconds, computed HERE: the browser only decrements (VoteCountdownTest).
            'opens_in'   => $o ? max(0, Carbon::parse($o)->getTimestamp() - Carbon::now()->getTimestamp()) : 0,
            'waiting'    => AwardAlert::waiting($pid),
            'delay'      => ($phaseK === 'late' && $cycle) ? PublicResults::delayFor((int) $cycle->id) : null,
        ];
    }

    /** The action, pointed at THIS edition's result rather than the results index. */
    private static function action(string $slug, ?array $phase, ?array $released): ?array
    {
        $a = AwardOverview::action($slug, $phase);
        if ($a !== null && ($phase['phase'] ?? '') !== '' && in_array($phase['phase'], ['results', 'archived'], true)) {
            $a['href'] = $released['url'] ?? '/results';
        }
        return $a;
    }

    /**
     * The category list on the Overview tab: leaders while the race runs, winners once the
     * result is published, titles otherwise. A leader is named only once somebody has a
     * vote — a nominee on zero is not "leading" (AwardService::categoriesByHeat()).
     *
     * @return list<array<string,mixed>>
     */
    private static function categories(object $cycle, string $phase, ?array $released): array
    {
        $cats = DB::table('gates_award_categories')->where('cycle_id', (int) $cycle->id)->orderBy('sort_order')->orderBy('id')->get();
        if ($cats->isEmpty()) return [];

        $won = [];
        foreach (($released['awards'] ?? []) as $a) {
            $won[(int) ($a['category']->id ?? 0)] = $a;
        }

        $leaders = [];
        if (!$released) {
            $q = DB::table('gates_nominees')->whereIn('category_id', $cats->pluck('id')->all())
                ->whereIn('status', ['approved', 'winner', 'runner_up']);
            MergeService::notMerged($q);
            foreach ($q->orderByDesc('vote_count')->orderBy('id')->get(['id', 'name', 'photo_path', 'vote_count', 'category_id']) as $n) {
                $leaders[(int) $n->category_id] ??= $n;
            }
        }

        $programmeSlug = (string) DB::table('gates_award_programmes')->where('id', $cycle->programme_id)->value('slug');
        $out = [];
        foreach ($cats as $c) {
            $id = (int) $c->id;
            $row = ['id' => $id, 'title' => (string) $c->title, 'who' => '', 'photo' => '', 'kicker' => '', 'meta' => '', 'href' => '', 'won' => false];
            if (isset($won[$id])) {
                $w = $won[$id]['winner'] ?? null;
                $row = ['who' => (string) ($w['name'] ?? ''), 'photo' => (string) ($w['photo'] ?? ''),
                        'kicker' => 'Won by', 'meta' => 'Result', 'href' => (string) ($won[$id]['url'] ?? ''), 'won' => true] + $row;
            } elseif (($l = $leaders[$id] ?? null) && (int) $l->vote_count > 0
                      && in_array($phase, ['voting', 'judging', 'shortlisting'], true)) {
                $row = ['who' => (string) $l->name, 'photo' => (string) ($l->photo_path ?? ''),
                        'kicker' => 'Leading:', 'meta' => number_format((int) $l->vote_count) . ' votes',
                        'href' => '/vote/' . rawurlencode($programmeSlug) . '/' . Slug::idSegment((int) $l->id, (string) $l->name)] + $row;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** The coming-soon view's "What's planned": the edition's own dates, in order, and only those set. */
    private static function plan(object $c): array
    {
        $rows = [];
        foreach ([['nominations_open', 'Nominations open'], ['nominations_close', 'Nominations close'],
                  ['voting_open', 'Community voting opens'], ['voting_close', 'Voting closes, then judging'],
                  ['results_date', 'Results']] as [$col, $label]) {
            if (!empty($c->{$col})) {
                $rows[] = ['at' => (string) $c->{$col}, 'date' => DisplayTime::show((string) $c->{$col}, 'j M Y'), 'label' => $label];
            }
        }
        return $rows;
    }

    /** When the edition first opens to the public — the coming-soon countdown's target. */
    private static function opensAt(object $c): ?string
    {
        $at = $c->nominations_open ?? $c->voting_open ?? null;
        if (!$at) return null;
        return Carbon::parse((string) $at)->isFuture() ? (string) $at : null;
    }
}
